<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: ../auth/login.php");
    exit;
}

// ── FILTERS ──
$filter_user   = trim($_GET['user'] ?? '');
$filter_action = trim($_GET['action'] ?? '');
$filter_search = trim($_GET['q'] ?? '');
$filter_from   = trim($_GET['from'] ?? '');
$filter_to     = trim($_GET['to'] ?? '');
$page          = max(1, (int)($_GET['page'] ?? 1));
$per_page      = 25;
$offset        = ($page - 1) * $per_page;

// Build query
$where   = [];
$params  = [];
$types   = '';

if ($filter_user !== '') {
    $where[]  = 'a.user_id = ?';
    $params[] = (int)$filter_user;
    $types   .= 'i';
}
if ($filter_action !== '') {
    $where[]  = 'a.action = ?';
    $params[] = $filter_action;
    $types   .= 's';
}
if ($filter_search !== '') {
    $where[]  = 'a.description LIKE ?';
    $params[] = '%' . $filter_search . '%';
    $types   .= 's';
}
if ($filter_from !== '') {
    $where[]  = 'a.created_at >= ?';
    $params[] = $filter_from . ' 00:00:00';
    $types   .= 's';
}
if ($filter_to !== '') {
    $where[]  = 'a.created_at <= ?';
    $params[] = $filter_to . ' 23:59:59';
    $types   .= 's';
}

$where_clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Count total
$count_sql  = "SELECT COUNT(*) AS total FROM audit_logs a $where_clause";
$count_stmt = $conn->prepare($count_sql);
if ($types) $count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$total       = $count_stmt->get_result()->fetch_assoc()['total'];
$total_pages = max(1, ceil($total / $per_page));

// Fetch logs
$sql  = "SELECT a.log_id, a.user_id, a.action, a.description, a.created_at, u.full_name
         FROM audit_logs a
         LEFT JOIN users u ON a.user_id = u.user_id
         $where_clause
         ORDER BY a.created_at DESC
         LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
$bind_types  = $types . 'ii';
$bind_params = array_merge($params, [$per_page, $offset]);
$stmt->bind_param($bind_types, ...$bind_params);
$stmt->execute();
$logs = $stmt->get_result();

// For filter dropdowns
$all_users   = $conn->query("SELECT user_id, full_name FROM users ORDER BY full_name ASC");
$all_actions = $conn->query("SELECT DISTINCT action FROM audit_logs ORDER BY action ASC");

$page_title  = 'Activity Log';
$active_page = 'activity_log';
$base_path   = '../';
require_once '../includes/header.php';
require_once '../includes/navbar.php';
?>

<div class="main">
<?php
$topbar_title      = 'Activity Log';
$topbar_breadcrumb = ['Administration', 'Activity Log'];
require_once '../includes/topbar.php';
?>

  <div class="content">

    <div class="page-header">
      <div class="page-header-title"><?= icon('clipboard-list', 18) ?> Activity Log</div>
      <div class="page-header-sub">Monitor all system activity. See who did what and when.</div>
    </div>

    <!-- FILTERS -->
    <div class="card" style="margin-bottom:1.25rem;">
      <form method="GET" action="" style="padding:1rem 1.25rem;display:flex;align-items:flex-end;gap:0.75rem;flex-wrap:wrap;">

        <div class="field" style="min-width:160px;flex:1;">
          <label class="field-label">User</label>
          <select name="user" class="field-select">
            <option value="">All Users</option>
            <?php while ($u = $all_users->fetch_assoc()): ?>
            <option value="<?= $u['user_id'] ?>" <?= $filter_user == $u['user_id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($u['full_name']) ?>
            </option>
            <?php endwhile; ?>
          </select>
        </div>

        <div class="field" style="min-width:150px;flex:1;">
          <label class="field-label">Action</label>
          <select name="action" class="field-select">
            <option value="">All Actions</option>
            <?php while ($a = $all_actions->fetch_assoc()): ?>
            <option value="<?= htmlspecialchars($a['action']) ?>" <?= $filter_action === $a['action'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($a['action']) ?>
            </option>
            <?php endwhile; ?>
          </select>
        </div>

        <div class="field" style="min-width:120px;flex:0.8;">
          <label class="field-label">From</label>
          <input type="date" name="from" class="field-input" value="<?= htmlspecialchars($filter_from) ?>"/>
        </div>

        <div class="field" style="min-width:120px;flex:0.8;">
          <label class="field-label">To</label>
          <input type="date" name="to" class="field-input" value="<?= htmlspecialchars($filter_to) ?>"/>
        </div>

        <div class="field" style="min-width:180px;flex:1.5;">
          <label class="field-label">Search</label>
          <input type="text" name="q" class="field-input"
            placeholder="Search description..."
            value="<?= htmlspecialchars($filter_search) ?>"/>
        </div>

        <div style="display:flex;gap:0.5rem;padding-bottom:0.05rem;">
          <button type="submit" class="btn-primary" style="padding:0.7rem 1.1rem;">
            <?= icon('search', 14) ?> Filter
          </button>
          <?php if ($filter_user || $filter_action || $filter_search || $filter_from || $filter_to): ?>
          <a href="activity_log.php" class="btn-ghost" style="padding:0.7rem 1.1rem;">
            <?= icon('x-mark', 14) ?> Clear
          </a>
          <?php endif; ?>
        </div>

      </form>
    </div>

    <!-- RESULTS SUMMARY -->
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.75rem;padding:0 0.25rem;">
      <span style="font-size:0.75rem;color:var(--text-muted);font-weight:500;">
        <?= number_format($total) ?> event<?= $total !== 1 ? 's' : '' ?> found
        <?php if ($total_pages > 1): ?>
          &middot; Page <?= $page ?> of <?= $total_pages ?>
        <?php endif; ?>
      </span>
    </div>

    <!-- LOG TABLE -->
    <div class="card">
      <?php if ($logs->num_rows > 0): ?>
      <div class="tg-table-wrap">
        <table class="tg-table">
          <thead>
            <tr>
              <th style="width:40px;">#</th>
              <th>User</th>
              <th>Action</th>
              <th>Description</th>
              <th>Date &amp; Time</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $row_num = $offset;
            while ($log = $logs->fetch_assoc()):
              $row_num++;
              $action_badges = [
                'LOGIN'            => ['badge-green',  'arrow-right-on-rectangle'],
                'LOGOUT'           => ['badge-gray',   'arrow-left-on-rectangle'],
                'ACCOUNT_CREATED'  => ['badge-gold',   'user-plus'],
                'ACCOUNT_DELETED'  => ['badge-red',    'trash'],
                'PASSWORD_RESET'   => ['badge-yellow', 'lock-closed'],
                'CLIENT_ADDED'     => ['badge-green',  'user-plus'],
                'CLIENT_UPDATED'   => ['badge-yellow', 'pencil'],
                'VEHICLE_ADDED'    => ['badge-green',  'plus'],
                'VEHICLE_UPDATED'  => ['badge-yellow', 'pencil'],
                'POLICY_CREATED'   => ['badge-gold',   'shield-check'],
                'POLICY_SAVED'     => ['badge-green',  'shield-check'],
                'CLAIM_ADDED'      => ['badge-gold',   'clipboard-list'],
                'CLAIM_UPDATED'    => ['badge-yellow', 'clipboard-list'],
                'REPAIR_CREATED'   => ['badge-gold',   'wrench'],
                'REPAIR_UPDATED'   => ['badge-yellow', 'wrench'],
              ];
              $ab   = $action_badges[$log['action']][0] ?? 'badge-gray';
              $aico = $action_badges[$log['action']][1] ?? 'clipboard-list';

              // Time formatting
              $ts       = strtotime($log['created_at']);
              $is_today = date('Y-m-d', $ts) === date('Y-m-d');
              $is_yesterday = date('Y-m-d', $ts) === date('Y-m-d', strtotime('-1 day'));

              if ($is_today) {
                  $date_label = 'Today, ' . date('h:i A', $ts);
              } elseif ($is_yesterday) {
                  $date_label = 'Yesterday, ' . date('h:i A', $ts);
              } else {
                  $date_label = date('M d, Y h:i A', $ts);
              }
            ?>
            <tr>
              <td style="font-size:0.7rem;color:var(--text-muted);font-weight:600;"><?= $row_num ?></td>
              <td>
                <div style="display:flex;align-items:center;gap:0.5rem;">
                  <div style="width:26px;height:26px;border-radius:50%;background:<?= $log['full_name'] ? 'linear-gradient(135deg,var(--gold-bright),var(--gold))' : 'var(--border)' ?>;display:flex;align-items:center;justify-content:center;font-size:0.58rem;font-weight:800;color:#fff;flex-shrink:0;">
                    <?= $log['full_name'] ? strtoupper(substr($log['full_name'], 0, 1)) : '?' ?>
                  </div>
                  <span style="font-weight:600;color:<?= $log['full_name'] ? 'var(--text-primary)' : 'var(--text-muted)' ?>;font-size:0.8rem;font-style:<?= $log['full_name'] ? 'normal' : 'italic' ?>;">
                    <?= $log['full_name'] ? htmlspecialchars($log['full_name']) : 'Deleted User' ?>
                  </span>
                </div>
              </td>
              <td>
                <span class="badge <?= $ab ?>" style="gap:0.3rem;">
                  <?= icon($aico, 11) ?> <?= htmlspecialchars($log['action']) ?>
                </span>
              </td>
              <td style="font-size:0.78rem;color:var(--text-secondary);max-width:340px;">
                <?= htmlspecialchars($log['description']) ?>
              </td>
              <td style="font-size:0.72rem;color:var(--text-muted);white-space:nowrap;">
                <?= $date_label ?>
              </td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>

      <?php if ($total_pages > 1): ?>
      <!-- PAGINATION -->
      <?php
        // Build current query string without 'page'
        $qs = $_GET;
        unset($qs['page']);
        $qs_string = http_build_query($qs);
        $qs_prefix = $qs_string ? $qs_string . '&' : '';
      ?>
      <div style="padding:1rem 1.25rem;border-top:1px solid var(--border);display:flex;justify-content:center;gap:0.35rem;flex-wrap:wrap;">
        <?php if ($page > 1): ?>
        <a href="?<?= $qs_prefix ?>page=<?= $page - 1 ?>" class="btn-ghost" style="padding:0.4rem 0.7rem;font-size:0.75rem;">
          <?= icon('chevron-left', 12) ?> Prev
        </a>
        <?php endif; ?>

        <?php
          $start = max(1, $page - 2);
          $end   = min($total_pages, $page + 2);
          if ($start > 1):
        ?>
          <a href="?<?= $qs_prefix ?>page=1" class="btn-ghost" style="padding:0.4rem 0.6rem;font-size:0.75rem;">1</a>
          <?php if ($start > 2): ?><span style="color:var(--text-muted);padding:0.4rem 0.2rem;font-size:0.75rem;">&hellip;</span><?php endif; ?>
        <?php endif; ?>

        <?php for ($i = $start; $i <= $end; $i++): ?>
        <a href="?<?= $qs_prefix ?>page=<?= $i ?>"
           class="<?= $i === $page ? 'btn-primary' : 'btn-ghost' ?>"
           style="padding:0.4rem 0.6rem;font-size:0.75rem;min-width:32px;justify-content:center;">
          <?= $i ?>
        </a>
        <?php endfor; ?>

        <?php if ($end < $total_pages): ?>
          <?php if ($end < $total_pages - 1): ?><span style="color:var(--text-muted);padding:0.4rem 0.2rem;font-size:0.75rem;">&hellip;</span><?php endif; ?>
          <a href="?<?= $qs_prefix ?>page=<?= $total_pages ?>" class="btn-ghost" style="padding:0.4rem 0.6rem;font-size:0.75rem;"><?= $total_pages ?></a>
        <?php endif; ?>

        <?php if ($page < $total_pages): ?>
        <a href="?<?= $qs_prefix ?>page=<?= $page + 1 ?>" class="btn-ghost" style="padding:0.4rem 0.7rem;font-size:0.75rem;">
          Next <?= icon('chevron-right', 12) ?>
        </a>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php else: ?>
      <div class="empty-state">
        <div class="empty-icon"><?= icon('clipboard-list', 28) ?></div>
        <div class="empty-title">No activity found</div>
        <div class="empty-desc">
          <?php if ($filter_user || $filter_action || $filter_search || $filter_from || $filter_to): ?>
            No events match your filters. Try adjusting your criteria.
          <?php else: ?>
            System events will appear here as users interact with TG-BASICS.
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>

  </div>
</div>

<?php require_once '../includes/footer.php'; ?>
