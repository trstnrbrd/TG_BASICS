<?php
require_once __DIR__ . "/../../config/session.php";
require_once '../../config/db.php';
require_once '../../config/validators.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin', 'mechanic'])) {
    header("Location: ../../auth/login.php");
    exit;
}

// AJAX autocomplete
if (isset($_GET['ajax_ac']) && isset($_GET['q'])) {
    header('Content-Type: application/json');
    $q    = '%' . san_str($_GET['q'], 100) . '%';
    $stmt = $conn->prepare("
        SELECT c.client_id, c.full_name, c.contact_number,
               v.plate_number, v.make, v.model
        FROM clients c
        LEFT JOIN vehicles v ON c.client_id = v.client_id
        WHERE c.deleted_at IS NULL
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
    $del_id = (int)$_POST['delete_client_id'];
    $cstmt  = $conn->prepare("SELECT full_name FROM clients WHERE client_id = ? AND deleted_at IS NULL");
    $cstmt->bind_param('i', $del_id);
    $cstmt->execute();
    $cdata = $cstmt->get_result()->fetch_assoc();
    if ($cdata) {
        // Check for linked records before soft-deleting
        $chk = $conn->prepare("
            SELECT
              (SELECT COUNT(*) FROM claims       WHERE client_id = ?) AS claims_count,
              (SELECT COUNT(*) FROM vehicles     WHERE client_id = ?) AS vehicles_count,
              (SELECT COUNT(*) FROM insurance_policies WHERE client_id = ?) AS policies_count,
              (SELECT COUNT(*) FROM repair_jobs  WHERE client_id = ?) AS repairs_count
        ");
        $chk->bind_param('iiii', $del_id, $del_id, $del_id, $del_id);
        $chk->execute();
        $counts = $chk->get_result()->fetch_assoc();
        $total_linked = ($counts['claims_count'] ?? 0) + ($counts['vehicles_count'] ?? 0)
                      + ($counts['policies_count'] ?? 0) + ($counts['repairs_count'] ?? 0);

        if ($total_linked > 0) {
            $parts = [];
            if ($counts['vehicles_count'])  $parts[] = $counts['vehicles_count']  . ' vehicle(s)';
            if ($counts['policies_count'])  $parts[] = $counts['policies_count']  . ' policy(s)';
            if ($counts['repairs_count'])   $parts[] = $counts['repairs_count']   . ' repair job(s)';
            if ($counts['claims_count'])    $parts[] = $counts['claims_count']    . ' claim(s)';
            $linked_msg = implode(', ', $parts);
            header("Location: client_list.php?error=" . urlencode('"' . $cdata['full_name'] . '" cannot be deleted — they still have ' . $linked_msg . ' on record.'));
            exit;
        }

        $dstmt = $conn->prepare("UPDATE clients SET deleted_at = NOW() WHERE client_id = ?");
        $dstmt->bind_param('i', $del_id);
        $dstmt->execute();
        $log  = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'CLIENT_DELETED', ?)");
        $desc = ($_SESSION['full_name'] ?? 'Unknown') . ' deleted client "' . $cdata['full_name'] . '".';
        $log->bind_param('is', $_SESSION['user_id'], $desc);
        $log->execute();
        header("Location: client_list.php?success=" . urlencode('"' . $cdata['full_name'] . '" has been deleted.'));
        exit;
    }
}

$full_name = $_SESSION['full_name'];
$initials  = substr(implode('', array_map(fn($w) => strtoupper($w[0]), explode(' ', $full_name))), 0, 2);

$search      = validate_search(san_str($_GET['search'] ?? '', MAX_SEARCH));
$filter_by   = san_enum($_GET['filter_by'] ?? 'all', ['all', 'name', 'plate', 'contact', 'email']);
$sort_by     = $_GET['sort'] ?? 'newest';
$filter_type = san_enum($_GET['client_type'] ?? 'all', ['all', 'insurance', 'walkin']);
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

$type_having = '';
if ($filter_type === 'insurance') {
    $type_having = 'HAVING has_policy = 1';
} elseif ($filter_type === 'walkin') {
    $type_having = 'HAVING has_policy = 0';
}

// Merge soft-delete filter into WHERE
$soft_del_cond = "c.deleted_at IS NULL";
$where_with_sd = $where === ''
    ? "WHERE $soft_del_cond"
    : $where . " AND $soft_del_cond";

$sql = "
    SELECT c.client_id, c.full_name, c.contact_number, c.email,
           COUNT(DISTINCT v.vehicle_id) AS vehicle_count, c.created_at,
           COUNT(DISTINCT ip.policy_id) > 0 AS has_policy
    FROM clients c
    LEFT JOIN vehicles v            ON c.client_id = v.client_id
    LEFT JOIN insurance_policies ip ON c.client_id = ip.client_id
    $where_with_sd
    GROUP BY c.client_id
    $type_having
    ORDER BY $order
";

$stmt = $conn->prepare($sql);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$rows = $result->fetch_all(MYSQLI_ASSOC);

$total_clients  = $conn->query("SELECT COUNT(*) as c FROM clients WHERE deleted_at IS NULL")->fetch_assoc()['c'];
$total_vehicles = $conn->query("SELECT COUNT(*) as c FROM vehicles")->fetch_assoc()['c'];
$recent         = $conn->query("SELECT COUNT(*) as c FROM clients WHERE deleted_at IS NULL AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetch_assoc()['c'];

$page_title  = 'Client Records';
$active_page = 'clients';
$base_path   = '../../';
require_once '../../includes/header.php';
require_once '../../includes/navbar.php';
?>

<link rel="stylesheet" href="../../assets/css/shared/clients.css?v=<?= filemtime(__DIR__ . '/../../assets/css/shared/clients.css') ?>"/>
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
    width: 28px !important; height: 28px !important;
    border-radius: 7px !important;
  }
  .client-stats-grid .card-icon svg { width: 13px !important; height: 13px !important; }
  .client-stats-grid [style*="1.6rem"] { font-size: 1.2rem !important; }
  .client-stats-grid [style*="0.7rem"] { font-size: 0.58rem !important; }

  /* Toolbar — keep filters in a 3-col row, search full width */
  .client-toolbar > div {
    display: grid !important;
    grid-template-columns: 1fr 1fr 1fr !important;
    gap: 0.5rem !important;
  }
  .client-toolbar > div > div:first-child { grid-column: 1 / -1; }  /* search full width */
  .client-toolbar > div > button[type="submit"],
  .client-toolbar > div > a[href*="add_client"] { grid-column: span 1; justify-content: center; }
  .client-toolbar > div > a[href*="client_list"] { grid-column: span 1; justify-content: center; }
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
        Swal.fire({ toast:true, position:'top-end', icon:'success', title:<?= json_encode($_GET['success']) ?>, showConfirmButton:false, timer:3000, timerProgressBar:true });
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
    <div class="client-stats-grid" style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:1.5rem;">
      <?php
      $stats = [
        [icon('user', 16), $total_clients,  'Total Clients'],
        [icon('vehicle', 16), $total_vehicles, 'Total Vehicles'],
        [icon('calendar', 16), $recent,         'Added This Month'],
      ];
      foreach ($stats as $s): ?>
      <div class="card" style="margin-bottom:0;display:flex;align-items:center;gap:0.9rem;padding:1.1rem 1.25rem;">
        <div class="card-icon" style="width:42px;height:42px;border-radius:10px;flex-shrink:0;"><?= $s[0] ?></div>
        <div>
          <div style="font-size:1.6rem;font-weight:800;color:var(--text-primary);line-height:1;letter-spacing:-0.5px;"><?= $s[1] ?></div>
          <div style="font-size:0.7rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.5px;margin-top:0.15rem;"><?= $s[2] ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- TOOLBAR -->
    <form method="GET" action="" class="client-toolbar" style="margin-bottom:1rem;">
      <div style="display:flex;gap:0.6rem;align-items:center;flex-wrap:wrap;">

        <!-- SEARCH INPUT with autocomplete -->
        <div style="position:relative;flex:1;min-width:200px;max-width:400px;">
          <span style="position:absolute;left:0.85rem;top:50%;transform:translateY(-50%);color:var(--text-muted);pointer-events:none;z-index:1;"><?= icon('magnifying-glass', 14) ?></span>
          <input type="text" name="search" id="client-search-input"
            placeholder="Search clients..."
            value="<?= htmlspecialchars($search) ?>"
            class="filter-input" style="padding-left:2.4rem;width:100%;"
            autocomplete="off"/>
          <div id="client-ac-dropdown" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:200;background:var(--bg-3);border:1px solid var(--gold-bright);border-radius:9px;box-shadow:var(--shadow-md);max-height:220px;overflow-y:auto;margin-top:2px;"></div>
        </div>

        <!-- FILTER BY -->
        <select name="filter_by" class="filter-input" style="min-width:140px;">
          <option value="all"     <?= $filter_by === 'all' ? 'selected' : '' ?>>All Fields</option>
          <option value="name"    <?= $filter_by === 'name' ? 'selected' : '' ?>>Name</option>
          <option value="plate"   <?= $filter_by === 'plate' ? 'selected' : '' ?>>Plate Number</option>
          <option value="contact" <?= $filter_by === 'contact' ? 'selected' : '' ?>>Contact</option>
          <option value="email"   <?= $filter_by === 'email' ? 'selected' : '' ?>>Email</option>
        </select>

        <!-- CLIENT TYPE -->
        <select name="client_type" class="filter-input" style="min-width:150px;">
          <option value="all"       <?= $filter_type === 'all'       ? 'selected' : '' ?>>All Types</option>
          <option value="insurance" <?= $filter_type === 'insurance' ? 'selected' : '' ?>>Insurance</option>
          <option value="walkin"    <?= $filter_type === 'walkin'    ? 'selected' : '' ?>>Walk-in</option>
        </select>

        <!-- SORT BY -->
        <select name="sort" class="filter-input" style="min-width:150px;">
          <option value="newest"   <?= $sort_by === 'newest' ? 'selected' : '' ?>>Newest First</option>
          <option value="oldest"   <?= $sort_by === 'oldest' ? 'selected' : '' ?>>Oldest First</option>
          <option value="name_asc" <?= $sort_by === 'name_asc' ? 'selected' : '' ?>>Name A–Z</option>
          <option value="name_desc"<?= $sort_by === 'name_desc' ? 'selected' : '' ?>>Name Z–A</option>
          <option value="vehicles" <?= $sort_by === 'vehicles' ? 'selected' : '' ?>>Most Vehicles</option>
        </select>

        <!-- BUTTONS -->
        <button type="submit" class="btn-primary"><?= icon('magnifying-glass', 14) ?> Search</button>
        <?php if ($search || $filter_by !== 'all' || $sort_by !== 'newest' || $filter_type !== 'all'): ?>
        <a href="client_list.php" class="btn-ghost"><?= icon('x-mark', 14) ?> Clear</a>
        <?php endif; ?>
        <?php if ($_SESSION['role'] !== 'mechanic'): ?>
        <a href="add_client.php" class="btn-primary"><?= icon('plus', 14) ?> Add Client</a>
        <?php endif; ?>
      </div>
    </form>

    <!-- TABLE -->
    <div class="card" style="margin-bottom:0;">
      <div class="card-header">
        <div class="card-icon"><?= icon('users', 16) ?></div>
        <div>
          <div class="card-title"><?= $search ? 'Search Results' : 'All Clients' ?></div>
          <div class="card-sub"><?= count($rows) ?> record<?= count($rows) !== 1 ? 's' : '' ?></div>
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
              <?= icon('phone', 11) ?> <?= htmlspecialchars($row['contact_number']) ?>
              &nbsp;·&nbsp;
              <?= $row['vehicle_count'] ?> vehicle<?= $row['vehicle_count'] != 1 ? 's' : '' ?>
            </div>
          </div>
          <div class="cl-card-right">
            <?= $badge ?>
            <?php if ($_SESSION['role'] !== 'mechanic'): ?>
            <form method="POST" action="" style="display:inline;" onclick="event.preventDefault();event.stopPropagation();">
              <?= csrf_field() ?>
              <input type="hidden" name="delete_client_id" value="<?= $row['client_id'] ?>"/>
              <button type="button" class="btn-sm-danger js-delete-client cl-card-del" data-name="<?= htmlspecialchars($row['full_name'], ENT_QUOTES) ?>"><?= icon('trash', 13) ?></button>
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
                $sort_qs = http_build_query(array_merge($_GET, ['sort' => $next_sort]));
                ?>
                <a href="?<?= $sort_qs ?>" style="display:inline-flex;align-items:center;gap:0.35rem;color:inherit;text-decoration:none;">
                  Client <?= $sort_icon ?>
                </a>
              </th>
              <th>Contact</th>
              <th>Type</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $row):
              $cid = 'client-expand-' . $row['client_id'];
            ?>
            <tr class="tg-expandable-row" data-expand="<?= $cid ?>" style="cursor:pointer;">
              <td style="text-align:left;">
                <div style="display:flex;align-items:center;gap:0.6rem;">
                  <svg class="row-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="flex-shrink:0;opacity:0.35;transition:transform 0.2s;"><polyline points="9 18 15 12 9 6"/></svg>
                  <div>
                    <div style="font-weight:700;color:var(--text-primary);font-size:0.85rem;"><?= htmlspecialchars($row['full_name']) ?></div>
                  </div>
                </div>
              </td>
              <td style="font-size:0.82rem;"><?= htmlspecialchars($row['contact_number']) ?></td>
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
                  <?php if ($_SESSION['role'] !== 'mechanic'): ?>
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
              <td colspan="4" style="padding:0;">
                <div class="tg-expand-body">
                  <div class="tg-expand-grid">
                    <div class="tg-expand-item">
                      <span class="tg-expand-label">Date Added</span>
                      <span class="tg-expand-value"><?= date('M d, Y', strtotime($row['created_at'])) ?></span>
                    </div>
                    <div class="tg-expand-item">
                      <span class="tg-expand-label">Vehicles</span>
                      <span class="tg-expand-value"><span class="badge badge-gold"><?= $row['vehicle_count'] ?> vehicle<?= $row['vehicle_count'] != 1 ? 's' : '' ?></span></span>
                    </div>
                    <div class="tg-expand-item">
                      <span class="tg-expand-label">Email</span>
                      <span class="tg-expand-value"><?= htmlspecialchars($row['email'] ?? '—') ?></span>
                    </div>
                  </div>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
      <div class="empty-state">
        <div class="empty-icon-wrap"><?= icon('users', 26) ?></div>
        <div class="empty-title"><?= $search ? 'No results found' : 'No clients yet' ?></div>
        <div class="empty-desc"><?= $search ? 'Try a different name, plate number, or contact.' : 'Start by adding your first client record.' ?></div>
      </div>
      <?php endif; ?>
    </div>

  </div>
</div>

<script src="../../assets/js/shared/client_list.js?v=<?= filemtime(__DIR__.'/../../assets/js/shared/client_list.js') ?>"></script>
<script>
(function() {
  var input    = document.getElementById('client-search-input');
  var dropdown = document.getElementById('client-ac-dropdown');
  if (!input || !dropdown) return;

  var timer = null;

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
                [c.plate_number, c.make, c.model].filter(Boolean).join(' · ') + '</span>'
              : '';
            return '<div class="cl-ac-item" data-id="' + c.client_id + '"' +
              ' style="padding:0.6rem 1rem;cursor:pointer;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;"' +
              ' onmouseover="this.style.background=\'var(--gold-pale)\'" onmouseout="this.style.background=\'\'">' +
              '<div><span style="font-size:0.82rem;font-weight:600;color:var(--text-primary);">' + c.full_name + '</span>' + vehicle + '</div>' +
              '<span style="font-size:0.7rem;color:var(--text-muted);flex-shrink:0;margin-left:0.5rem;">' + (c.contact_number || '') + '</span>' +
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
</script>

<?php require_once '../../includes/footer.php'; ?>