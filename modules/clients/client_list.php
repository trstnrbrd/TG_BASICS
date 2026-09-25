<?php
require_once __DIR__ . "/../../config/session.php";
require_once '../../config/db.php';
require_once '../../config/validators.php';
require_once '../../config/access.php';
require_once '../../includes/agent_filter.php';
require_once '../../includes/pagination.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin', 'mechanic'])) {
    header("Location: ../../auth/login.php");
    exit;
}

// AJAX autocomplete
if (isset($_GET['ajax_ac']) && isset($_GET['q'])) {
    header('Content-Type: application/json');
    $q = '%' . san_str($_GET['q'], 100) . '%';

    // Every admin sees every client; mechanics only ever see walk-in clients (config/access.php)
    $ac_scope_sql = '';
    if ($_SESSION['role'] === 'mechanic') $ac_scope_sql = "AND NOT EXISTS (SELECT 1 FROM insurance_policies ip WHERE ip.client_id = c.client_id)";

    $stmt = $conn->prepare("
        SELECT c.client_id, c.full_name, c.contact_number,
               v.plate_number, v.make, v.model
        FROM clients c
        LEFT JOIN vehicles v ON c.client_id = v.client_id
        WHERE c.deleted_at IS NULL $ac_scope_sql
          AND (c.full_name LIKE ? OR c.contact_number LIKE ? OR v.plate_number LIKE ? OR v.make LIKE ? OR v.model LIKE ?)
        GROUP BY c.client_id, v.vehicle_id
        ORDER BY c.full_name ASC
        LIMIT 8
    ");
    $stmt->bind_param('sssss', $q, $q, $q, $q, $q);
    $stmt->execute();
    echo json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    exit;
}

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_client_id'])) {
    csrf_verify();
    // Only the Owner, the admin who encoded the client, or its insurance agent may delete it
    // (mechanics never can — the UI hides the button from everyone else).
    $del_id = (int)$_POST['delete_client_id'];
    $cstmt  = $conn->prepare("SELECT full_name FROM clients WHERE client_id = ? AND deleted_at IS NULL");
    $cstmt->bind_param('i', $del_id);
    $cstmt->execute();
    $cdata = $cstmt->get_result()->fetch_assoc();
    if ($cdata && !client_editable($conn, $del_id)) {
        http_response_code(403);
        exit('Not allowed.');
    }
    if ($cdata) {
        // Come back to the same agent view the delete was made from (the form posts to the current URL)
        $back_agent = isset($_GET['agent']) && is_scalar($_GET['agent']) ? 'agent=' . urlencode((string)$_GET['agent']) . '&' : '';

        // Block deletion only if active policies, repair jobs, or claims exist
        // Vehicles alone are NOT a blocker — they are removed on client deletion
        $linked_msg = client_delete_blockers($conn, $del_id);
        if ($linked_msg !== '') {
            header("Location: client_list.php?" . $back_agent . "error=" . urlencode('"' . $cdata['full_name'] . '" cannot be deleted — they still have ' . $linked_msg . ' on record.'));
            exit;
        }

        // Remove vehicles first (no blocking records remain)
        $conn->query("DELETE FROM vehicles WHERE client_id = " . $del_id);

        $dstmt = $conn->prepare("UPDATE clients SET deleted_at = NOW() WHERE client_id = ?");
        $dstmt->bind_param('i', $del_id);
        $dstmt->execute();
        $log  = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'CLIENT_DELETED', ?)");
        $desc = ($_SESSION['full_name'] ?? 'Unknown') . ' deleted client "' . $cdata['full_name'] . '".';
        $log->bind_param('is', $_SESSION['user_id'], $desc);
        $log->execute();
        header("Location: client_list.php?" . $back_agent . "success=" . urlencode('"' . $cdata['full_name'] . '" has been deleted.'));
        exit;
    }
}

$full_name = $_SESSION['full_name'];
$initials  = substr(implode('', array_map(fn($w) => strtoupper($w[0]), explode(' ', $full_name))), 0, 2);

$search      = validate_search(san_str($_GET['search'] ?? '', MAX_SEARCH));
$filter_by   = san_enum($_GET['filter_by'] ?? 'all', ['all', 'name', 'plate', 'contact', 'email']);
$sort_by     = $_GET['sort'] ?? 'newest';
$is_mechanic = $_SESSION['role'] === 'mechanic';
// Mechanics only ever see walk-in clients — force this regardless of any ?client_type= in the URL
$filter_type = $is_mechanic ? 'walkin' : san_enum($_GET['client_type'] ?? 'all', ['all', 'insurance', 'walkin']);
$where       = '';
$params      = [];
$types       = '';

if ($search !== '') {
    $like = "%$search%";
    switch ($filter_by) {
        case 'name':
            $where  = "WHERE c.full_name LIKE ?";
            $params = [$like];
            $types  = 's';
            break;
        case 'plate':
            $where  = "WHERE v.plate_number LIKE ?";
            $params = [$like];
            $types  = 's';
            break;
        case 'contact':
            $where  = "WHERE c.contact_number LIKE ?";
            $params = [$like];
            $types  = 's';
            break;
        case 'email':
            $where  = "WHERE c.email LIKE ?";
            $params = [$like];
            $types  = 's';
            break;
        default:
            $where  = "WHERE (c.full_name LIKE ? OR v.plate_number LIKE ? OR c.contact_number LIKE ? OR c.email LIKE ?)";
            $params = [$like, $like, $like, $like];
            $types  = 'ssss';
    }
}

$order = match ($sort_by) {
    'oldest'   => 'c.created_at ASC',
    'name_asc' => 'c.full_name ASC',
    'name_desc'=> 'c.full_name DESC',
    'vehicles' => 'vehicle_count DESC',
    default    => 'c.created_at DESC',
};

// Insurance-agent filter (includes/agent_filter.php): an agent opens the list on THEIR OWN clients and can
// switch to another agent, "Unassigned" or everyone. Mechanics have no agent filter.
$me_id         = (int)$_SESSION['user_id'];
$af            = agent_filter_state($conn, !$is_mechanic);
$agent_filter  = $af['value'];
$agent_default = $af['default'];
$agent_names   = $af['names'];
$agent_cond    = fn(string $col) => agent_filter_sql($af, $col);

$type_having = '';
if ($filter_type === 'insurance') {
    $type_having = 'HAVING has_policy = 1';
} elseif ($filter_type === 'walkin') {
    $type_having = 'HAVING has_policy = 0';
}

// Every admin sees every client (owner's rule, 2026-09-24); who may change a client is decided per row
// below. The soft-delete filter is the only scope here — mechanics are limited to walk-ins via $filter_type.
$where_with_sd = ($where === ''
    ? "WHERE c.deleted_at IS NULL"
    : $where . " AND c.deleted_at IS NULL") . $agent_cond('c.agent_id');

// Insurance agent (whose client it is) and who added the record, both masked if the hidden account
$sql = "
    SELECT c.client_id, c.full_name, c.contact_number, c.email, c.created_by, c.agent_id,
           COUNT(DISTINCT v.vehicle_id) AS vehicle_count, c.created_at,
           COUNT(DISTINCT ip.policy_id) > 0 AS has_policy,
           CASE WHEN u.is_hidden = 1 THEN 'Developer' ELSE u.full_name END AS added_by_name,
           CASE WHEN ag.is_hidden = 1 THEN 'Developer' ELSE ag.full_name END AS agent_name,
           CASE WHEN ag.is_hidden = 1 THEN NULL ELSE ag.profile_photo END AS agent_photo
    FROM clients c
    LEFT JOIN vehicles v            ON c.client_id = v.client_id
    LEFT JOIN insurance_policies ip ON c.client_id = ip.client_id
    LEFT JOIN users u               ON c.created_by = u.user_id
    LEFT JOIN users ag              ON c.agent_id = ag.user_id
    $where_with_sd
    GROUP BY c.client_id
    $type_having
    ORDER BY $order
";

// One page at a time (includes/pagination.php) — the total is counted from this same query
[$result, $pg] = paginate_query($conn, $sql, $types, $params);
$rows = $result->fetch_all(MYSQLI_ASSOC);

// Mechanics only ever see walk-in clients — exclude anyone with an insurance policy on record
// The stat cards follow the agent filter too, so they always describe the clients being listed
$walkin_cond_bare = ($is_mechanic ? "AND NOT EXISTS (SELECT 1 FROM insurance_policies ip WHERE ip.client_id = clients.client_id)" : '') . $agent_cond('clients.agent_id');
$walkin_cond_c    = ($is_mechanic ? "AND NOT EXISTS (SELECT 1 FROM insurance_policies ip WHERE ip.client_id = c.client_id)" : '') . $agent_cond('c.agent_id');
$total_clients   = $conn->query("SELECT COUNT(*) as c FROM clients WHERE deleted_at IS NULL $walkin_cond_bare")->fetch_assoc()['c'];
$total_vehicles  = $conn->query("SELECT COUNT(*) as c FROM vehicles v INNER JOIN clients c ON v.client_id = c.client_id WHERE c.deleted_at IS NULL $walkin_cond_c")->fetch_assoc()['c'];
$recent          = $conn->query("SELECT COUNT(*) as c FROM clients WHERE deleted_at IS NULL AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) $walkin_cond_bare")->fetch_assoc()['c'];

// Card title for the current view
$list_title = match (true) {
    $search !== ''                 => 'Search Results',
    $agent_filter === 'all'        => 'All Clients',
    $agent_filter === 'none'       => 'Unassigned Clients',
    $agent_filter === (string)$me_id => 'My Clients',
    default                        => 'Clients of ' . $agent_names[(int)$agent_filter],
};

// Per row: may the signed-in user change / delete this client? Same rule as client_editable().
$row_can_edit = function (array $row) use ($me_id): bool {
    if ($_SESSION['role'] === 'super_admin') return true;
    if ($_SESSION['role'] !== 'admin') return false;
    return (int)$row['created_by'] === $me_id || (int)$row['agent_id'] === $me_id;
};

$page_title  = 'Client Records';
$active_page = 'clients';
$base_path   = '../../';
require_once '../../includes/header.php';
require_once '../../includes/navbar.php';
?>

<link rel="stylesheet" href="../../assets/css/shared/clients.css?v=<?= filemtime(__DIR__ . '/../../assets/css/shared/clients.css') ?>"/>
<link rel="stylesheet" href="../../assets/css/shared/agent_filter.css?v=<?= filemtime(__DIR__ . '/../../assets/css/shared/agent_filter.css') ?>"/>
<style>
@media (max-width: 768px) {
  /* Stats — keep 3-col, compact */
  .client-stats-grid {
    grid-template-columns: repeat(3, 1fr) !important;
    gap: 0.5rem !important;
    margin-bottom: 0.85rem !important;
  }
  .client-stats-grid .card {
    padding: 0.6rem 0.75rem !important;
    gap: 0.5rem !important;
    flex-direction: column !important;
    align-items: flex-start !important;
  }
  .client-stats-grid .card-icon {
    width: 26px !important; height: 26px !important;
    border-radius: 6px !important;
  }
  .client-stats-grid .card-icon svg { width: 12px !important; height: 12px !important; }
  .client-stats-grid .stat-value { font-size: 1rem !important; }
  .client-stats-grid .stat-label { font-size: 0.56rem !important; }

  /* Toolbar — keep filters in a 3-col row, search full width */
  .client-toolbar > div {
    display: grid !important;
    grid-template-columns: 1fr 1fr 1fr !important;
    gap: 0.5rem !important;
  }
  .client-toolbar > div > div:first-child { grid-column: 1 / -1; }  /* search full width */
  .client-toolbar > div > .cl-agent-wrap { grid-column: 1 / -1; }   /* agent filter full width */
  .client-toolbar > div > button[type="submit"],
  .client-toolbar > div > a[href*="add_client"] { grid-column: span 1; justify-content: center; }
  .client-toolbar > div > a[href*="client_list"] { grid-column: span 1; justify-content: center; }
}

/* Row details open on hover, not click — no dead zone between the two <tr>s
   since .tg-table uses border-collapse, so the adjacent-sibling hover chain
   holds as the cursor moves from one row straight into the other. */
.client-list-table .tg-expand-row { display: none; }
.client-list-table .tg-expandable-row:hover + .tg-expand-row,
.client-list-table .tg-expand-row:hover {
  display: table-row !important;
}
.client-list-table .tg-expandable-row:hover .row-chevron {
  transform: rotate(90deg);
  opacity: 0.7 !important;
}
</style>

<div class="main">

  <?php
$topbar_title      = 'Client Records';
$topbar_breadcrumb = ['Records', 'Clients'];
require_once '../../includes/topbar.php';
?>

  <div class="content">

    <?php if (isset($_GET['success'])): ?>
    <script>
      document.addEventListener('DOMContentLoaded', function() {
        Swal.fire({ toast:true, position:'top-end', icon:'success', titleText:<?= json_encode($_GET['success']) ?>, showConfirmButton:false, timer:3000, timerProgressBar:true });
      });
    </script>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
    <script>
      document.addEventListener('DOMContentLoaded', function() {
        Swal.fire({
          icon: 'error',
          title: 'Error',
          text: <?= json_encode($_GET['error']) ?>,
          confirmButtonColor: '#B8860B'
        });
      });
    </script>
    <?php endif; ?>

    <!-- STATS -->
    <div class="client-stats-grid" style="display:grid;grid-template-columns:repeat(3,1fr);gap:0.75rem;margin-bottom:1.25rem;">
      <?php
      $stats = [
        [icon('user', 14), $total_clients,  'Total Clients'],
        [icon('vehicle', 14), $total_vehicles, 'Total Vehicles'],
        [icon('calendar', 14), $recent,         'Added This Month'],
      ];
      foreach ($stats as $s): ?>
      <div class="card" style="margin-bottom:0;display:flex;align-items:center;gap:0.65rem;padding:0.75rem 1rem;">
        <div class="card-icon" style="width:32px;height:32px;border-radius:8px;flex-shrink:0;"><?= $s[0] ?></div>
        <div>
          <div class="stat-value" style="font-size:1.2rem;font-weight:800;color:var(--text-primary);line-height:1;letter-spacing:-0.5px;"><?= $s[1] ?></div>
          <div class="stat-label" style="font-size:0.64rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.5px;margin-top:0.1rem;"><?= $s[2] ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- TOOLBAR -->
    <form method="GET" action="" class="client-toolbar" style="margin-bottom:1rem;">
      <div style="display:flex;gap:0.6rem;align-items:center;flex-wrap:wrap;">

        <!-- SEARCH INPUT with autocomplete -->
        <div style="position:relative;flex:1;min-width:150px;max-width:400px;">
          <span style="position:absolute;left:0.85rem;top:50%;transform:translateY(-50%);color:var(--text-muted);pointer-events:none;z-index:1;"><?= icon('magnifying-glass', 14) ?></span>
          <input type="text" name="search" id="client-search-input"
            placeholder="Search clients..."
            value="<?= htmlspecialchars($search) ?>"
            class="filter-input" style="padding-left:2.4rem;width:100%;"
            autocomplete="off"/>
          <div id="client-ac-dropdown" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:200;background:var(--bg-3);border:1px solid var(--gold-bright);border-radius:9px;box-shadow:var(--shadow-md);max-height:220px;overflow-y:auto;margin-top:2px;"></div>
        </div>

        <!-- INSURANCE AGENT (opens on the signed-in agent's own clients; changing it applies right away) -->
        <?php render_agent_filter($af, $base_path); ?>

        <!-- FILTER BY -->
        <select name="filter_by" class="filter-input" style="min-width:120px;">
          <option value="all"     <?= $filter_by === 'all' ? 'selected' : '' ?>>All Fields</option>
          <option value="name"    <?= $filter_by === 'name' ? 'selected' : '' ?>>Name</option>
          <option value="plate"   <?= $filter_by === 'plate' ? 'selected' : '' ?>>Plate Number</option>
          <option value="contact" <?= $filter_by === 'contact' ? 'selected' : '' ?>>Contact</option>
          <option value="email"   <?= $filter_by === 'email' ? 'selected' : '' ?>>Email</option>
        </select>

        <!-- CLIENT TYPE (mechanics are locked to Walk-in, so there's nothing to switch) -->
        <?php if (!$is_mechanic): ?>
        <select name="client_type" class="filter-input" style="min-width:125px;">
          <option value="all"       <?= $filter_type === 'all'       ? 'selected' : '' ?>>All Types</option>
          <option value="insurance" <?= $filter_type === 'insurance' ? 'selected' : '' ?>>Insurance</option>
          <option value="walkin"    <?= $filter_type === 'walkin'    ? 'selected' : '' ?>>Walk-in</option>
        </select>
        <?php endif; ?>

        <!-- SORT BY -->
        <select name="sort" class="filter-input" style="min-width:135px;">
          <option value="newest"   <?= $sort_by === 'newest' ? 'selected' : '' ?>>Newest First</option>
          <option value="oldest"   <?= $sort_by === 'oldest' ? 'selected' : '' ?>>Oldest First</option>
          <option value="name_asc" <?= $sort_by === 'name_asc' ? 'selected' : '' ?>>Name A–Z</option>
          <option value="name_desc"<?= $sort_by === 'name_desc' ? 'selected' : '' ?>>Name Z–A</option>
          <option value="vehicles" <?= $sort_by === 'vehicles' ? 'selected' : '' ?>>Most Vehicles</option>
        </select>

        <!-- BUTTONS -->
        <button type="submit" class="btn-primary"><?= icon('magnifying-glass', 14) ?> Search</button>
        <?php if ($search || $filter_by !== 'all' || $sort_by !== 'newest' || $filter_type !== 'all' || $agent_filter !== $agent_default): ?>
        <a href="client_list.php" class="btn-ghost"><?= icon('x-mark', 14) ?> Clear</a>
        <?php endif; ?>
        <?php if ($_SESSION['role'] !== 'mechanic'): ?>
        <a href="import_clients.php" class="btn-ghost" title="Add many clients at once from a CSV file"><?= icon('arrow-up-tray', 14) ?> Import</a>
        <a href="add_client.php" class="btn-primary"><?= icon('plus', 14) ?> Add Client</a>
        <?php endif; ?>
      </div>
    </form>

    <!-- TABLE -->
    <div class="card" style="margin-bottom:0;">
      <div class="card-header">
        <div class="card-icon"><?= icon('users', 16) ?></div>
        <div>
          <div class="card-title"><?= htmlspecialchars($list_title) ?></div>
          <div class="card-sub"><?= paginate_summary($pg) ?></div>
        </div>
      </div>

      <?php if (count($rows) > 0): ?>

      <!-- ── MOBILE CARD LIST (hidden on desktop) ── -->
      <div class="cl-mobile-list">
        <?php foreach ($rows as $row):
          $badge = $row['has_policy']
            ? '<span class="badge badge-green" style="display:inline-flex;align-items:center;gap:0.25rem;">' . icon('shield-check', 11) . ' Insurance</span>'
            : '<span class="badge badge-gold" style="display:inline-flex;align-items:center;gap:0.25rem;">' . icon('wrench', 11) . ' Walk-in</span>';
        ?>
        <a href="view_client.php?id=<?= $row['client_id'] ?>" class="cl-card">
          <div class="cl-card-body">
            <div class="cl-card-name"><?= htmlspecialchars($row['full_name']) ?></div>
            <div class="cl-card-meta">
              <?php if (!empty($row['contact_number'])): ?><?= icon('phone', 11) ?> <?= htmlspecialchars($row['contact_number']) ?>
              &nbsp;·&nbsp;
              <?php endif; ?><?= $row['vehicle_count'] ?> vehicle<?= $row['vehicle_count'] != 1 ? 's' : '' ?>
            </div>
            <?php if (!$is_mechanic):
              $m_initials = !empty($row['agent_name']) ? substr(implode('', array_map(fn($w) => strtoupper($w[0] ?? ''), explode(' ', trim($row['agent_name'])))), 0, 2) : '';
            ?>
            <div class="cl-card-meta">
              <?php if (!empty($row['agent_name'])): ?>
              <div style="width:16px;height:16px;border-radius:50%;background:linear-gradient(135deg,var(--gold-bright),var(--gold));display:flex;align-items:center;justify-content:center;font-size:0.5rem;font-weight:800;color:#fff;flex-shrink:0;overflow:hidden;">
                <?php if (!empty($row['agent_photo'])): ?>
                  <img src="<?= $base_path ?>uploads/avatars/<?= htmlspecialchars($row['agent_photo']) ?>" alt="" loading="lazy" style="width:100%;height:100%;object-fit:cover;"/>
                <?php else: ?>
                  <?= htmlspecialchars($m_initials) ?>
                <?php endif; ?>
              </div>
              Agent: <?= htmlspecialchars($row['agent_name']) ?>
              <?php else: ?>
              Agent: <em>Unassigned</em>
              <?php endif; ?>
            </div>
            <?php endif; ?>
          </div>
          <div class="cl-card-right">
            <?= $badge ?>
            <?php if ($row_can_edit($row)): ?>
            <form method="POST" action="" style="display:inline;" onclick="event.preventDefault();event.stopPropagation();">
              <?= csrf_field() ?>
              <input type="hidden" name="delete_client_id" value="<?= $row['client_id'] ?>"/>
              <button type="button" class="btn-sm-danger js-delete-client cl-card-del" data-name="<?= htmlspecialchars($row['full_name'], ENT_QUOTES) ?>" aria-label="Delete <?= htmlspecialchars($row['full_name'], ENT_QUOTES) ?>"><?= icon('trash', 13) ?></button>
            </form>
            <?php endif; ?>
          </div>
        </a>
        <?php endforeach; ?>
      </div>

      <!-- ── DESKTOP TABLE (hidden on mobile) ── -->
      <div class="cl-desktop-table tg-table-wrap">
        <table class="tg-table client-list-table">
          <thead>
            <tr>
              <th style="text-align:left;padding-left:2.5rem;">
                <?php
                $next_sort  = $sort_by === 'name_asc' ? 'name_desc' : 'name_asc';
                $sort_icon  = $sort_by === 'name_asc'
                  ? '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 19V5M5 12l7-7 7 7"/></svg>'
                  : ($sort_by === 'name_desc'
                    ? '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 5v14M5 12l7 7 7-7"/></svg>'
                    : '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="opacity:0.35"><path d="M8 9l4-4 4 4M8 15l4 4 4-4"/></svg>');
                $sort_qs = http_build_query(array_merge(array_diff_key($_GET, ['page' => 1, 'success' => 1, 'error' => 1]), ['sort' => $next_sort]));   // re-sorting starts at page 1
                ?>
                <a href="?<?= $sort_qs ?>" style="display:inline-flex;align-items:center;gap:0.35rem;color:inherit;text-decoration:none;">
                  Client <?= $sort_icon ?>
                </a>
              </th>
              <th>Contact</th>
              <?php if (!$is_mechanic): ?>
              <th>Insurance Agent</th>
              <?php endif; ?>
              <th>Type</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $row):
              $cid = 'client-expand-' . $row['client_id'];
              $agent_initials = '';
              if (!empty($row['agent_name'])) {
                  $agent_initials = substr(implode('', array_map(fn($w) => strtoupper($w[0] ?? ''), explode(' ', trim($row['agent_name'])))), 0, 2);
              }
            ?>
            <tr class="tg-expandable-row" data-expand="<?= $cid ?>" tabindex="0" style="cursor:pointer;">
              <td style="text-align:left;">
                <div style="display:flex;align-items:center;gap:0.6rem;">
                  <svg class="row-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="flex-shrink:0;opacity:0.35;transition:transform 0.2s;"><polyline points="9 18 15 12 9 6"/></svg>
                  <div>
                    <div style="font-weight:700;color:var(--text-primary);font-size:0.85rem;"><?= htmlspecialchars($row['full_name']) ?></div>
                  </div>
                </div>
              </td>
              <td style="font-size:0.82rem;"><?= htmlspecialchars($row['contact_number'] ?: '—') ?></td>
              <?php if (!$is_mechanic): ?>
              <td style="font-size:0.78rem;color:var(--text-secondary);">
                <?php if (!empty($row['agent_name'])): ?>
                <div style="display:inline-flex;align-items:center;gap:0.45rem;">
                  <div style="width:22px;height:22px;border-radius:50%;background:linear-gradient(135deg,var(--gold-bright),var(--gold));display:flex;align-items:center;justify-content:center;font-size:0.56rem;font-weight:800;color:#fff;flex-shrink:0;overflow:hidden;">
                    <?php if (!empty($row['agent_photo'])): ?>
                      <img src="<?= $base_path ?>uploads/avatars/<?= htmlspecialchars($row['agent_photo']) ?>" alt="" loading="lazy" style="width:100%;height:100%;object-fit:cover;"/>
                    <?php else: ?>
                      <?= htmlspecialchars($agent_initials) ?>
                    <?php endif; ?>
                  </div>
                  <span><?= htmlspecialchars($row['agent_name']) ?></span>
                </div>
                <?php else: ?>
                  <span style="color:var(--text-muted);font-style:italic;">Unassigned</span>
                <?php endif; ?>
              </td>
              <?php endif; ?>
              <td>
                <?php if ($row['has_policy']): ?>
                  <span class="badge badge-green" style="display:inline-flex;align-items:center;gap:0.25rem;"><?= icon('shield-check', 11) ?> Insurance</span>
                <?php else: ?>
                  <span class="badge badge-gold" style="display:inline-flex;align-items:center;gap:0.25rem;"><?= icon('wrench', 11) ?> Walk-in</span>
                <?php endif; ?>
              </td>
              <td>
                <div style="display:inline-flex;gap:0.4rem;align-items:center;" onclick="event.stopPropagation()">
                  <a href="view_client.php?id=<?= $row['client_id'] ?>" class="btn-sm-gold" title="View" style="padding:0.35rem 0.55rem;"><?= icon('eye', 14) ?></a>
                  <?php if ($row_can_edit($row)): ?>
                  <form method="POST" action="" style="display:inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="delete_client_id" value="<?= $row['client_id'] ?>"/>
                    <button type="button" class="btn-sm-danger js-delete-client" title="Delete" data-name="<?= htmlspecialchars($row['full_name'], ENT_QUOTES) ?>"><?= icon('trash', 14) ?></button>
                  </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <tr class="tg-expand-row" id="<?= $cid ?>" style="display:none;">
              <td colspan="<?= $is_mechanic ? 4 : 5 ?>" style="padding:0;">
                <div class="tg-expand-body">
                  <div class="tg-expand-grid">
                    <div class="tg-expand-item">
                      <span class="tg-expand-label">Date Added</span>
                      <span class="tg-expand-value"><?= date('M d, Y', strtotime($row['created_at'])) ?></span>
                    </div>
                    <?php if (!$is_mechanic): ?>
                    <div class="tg-expand-item">
                      <span class="tg-expand-label">Added By</span>
                      <span class="tg-expand-value"><?= htmlspecialchars($row['added_by_name'] ?? '—') ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="tg-expand-item">
                      <span class="tg-expand-label">Email</span>
                      <span class="tg-expand-value"><?= htmlspecialchars($row['email'] ?: '—') ?></span>
                    </div>
                    <div class="tg-expand-item">
                      <span class="tg-expand-label">Vehicles</span>
                      <span class="tg-expand-value"><span class="badge badge-gold"><?= $row['vehicle_count'] ?> vehicle<?= $row['vehicle_count'] != 1 ? 's' : '' ?></span></span>
                    </div>
                  </div>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php render_pagination($pg); ?>
      <?php else: ?>
      <div class="empty-state">
        <div class="empty-icon-wrap"><?= icon('users', 26) ?></div>
        <?php if ($agent_filter !== 'all'): ?>
        <!-- Filtered to one agent — the client may simply belong to someone else -->
        <div class="empty-title"><?= $search ? 'No results for this agent' : 'No clients for this agent yet' ?></div>
        <div class="empty-desc">Only the clients of <?= $agent_filter === 'none' ? 'no agent (Unassigned)' : htmlspecialchars($agent_filter === (string)$me_id ? 'you' : $agent_names[(int)$agent_filter]) ?> are shown.</div>
        <a href="?<?= htmlspecialchars(http_build_query(array_merge(array_diff_key($_GET, ['success' => 1, 'error' => 1]), ['agent' => 'all']))) ?>" class="btn-primary" style="margin-top:0.9rem;"><?= icon('users', 14) ?> Show All Agents</a>
        <?php else: ?>
        <div class="empty-title"><?= $search ? 'No results found' : 'No clients yet' ?></div>
        <div class="empty-desc"><?= $search ? 'Try a different name, plate number, or contact.' : 'Start by adding your first client record.' ?></div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

  </div>
</div>

<script src="../../assets/js/shared/client_list.js?v=<?= filemtime(__DIR__.'/../../assets/js/shared/client_list.js') ?>"></script>
<script src="../../assets/js/shared/agent_filter.js?v=<?= filemtime(__DIR__.'/../../assets/js/shared/agent_filter.js') ?>"></script>
<script>
(function() {
  var input    = document.getElementById('client-search-input');
  var dropdown = document.getElementById('client-ac-dropdown');
  if (!input || !dropdown) return;

  var timer = null;
  // Client data (make/model are free text) goes into innerHTML below — escape it so it can only ever be text
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (ch) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]; }); };

  input.addEventListener('input', function() {
    var q = this.value.trim();
    clearTimeout(timer);
    if (q.length < 1) { dropdown.style.display = 'none'; return; }

    timer = setTimeout(function() {
      fetch('client_list.php?ajax_ac=1&q=' + encodeURIComponent(q))
        .then(function(r) { return r.json(); })
        .then(function(data) {
          if (!data.length) { dropdown.style.display = 'none'; return; }
          dropdown.innerHTML = data.map(function(c) {
            var vehicle = (c.plate_number || c.make || c.model)
              ? '<span style="font-size:0.68rem;color:var(--gold-bright);margin-top:0.1rem;display:block;">' +
                esc([c.plate_number, c.make, c.model].filter(Boolean).join(' · ')) + '</span>'
              : '';
            return '<div class="cl-ac-item" data-id="' + esc(c.client_id) + '"' +
              ' style="padding:0.6rem 1rem;cursor:pointer;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;"' +
              ' onmouseover="this.style.background=\'var(--gold-pale)\'" onmouseout="this.style.background=\'\'">' +
              '<div><span style="font-size:0.82rem;font-weight:600;color:var(--text-primary);">' + esc(c.full_name) + '</span>' + vehicle + '</div>' +
              '<span style="font-size:0.7rem;color:var(--text-muted);flex-shrink:0;margin-left:0.5rem;">' + esc(c.contact_number) + '</span>' +
              '</div>';
          }).join('');
          dropdown.style.display = 'block';

          dropdown.querySelectorAll('.cl-ac-item').forEach(function(el) {
            el.addEventListener('mousedown', function(e) {
              e.preventDefault();
              window.location.href = 'view_client.php?id=' + this.dataset.id;
            });
          });
        });
    }, 200);
  });

  input.addEventListener('blur', function() {
    setTimeout(function() { dropdown.style.display = 'none'; }, 150);
  });

  input.addEventListener('focus', function() {
    if (this.value.trim().length >= 1) this.dispatchEvent(new Event('input'));
  });
})();

// Row details now open on hover (CSS-driven) instead of click — stop clicks
// on this table from reaching the global click-to-toggle handler in footer.php
// so it can't fight with the hover state or leave a row stuck open/closed.
(function() {
  var clTable = document.querySelector('.client-list-table');
  if (clTable) clTable.addEventListener('click', function(e) { e.stopPropagation(); });
})();
</script>

<?php require_once '../../includes/footer.php'; ?>