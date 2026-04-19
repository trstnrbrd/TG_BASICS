<?php
require_once __DIR__ . '/../../config/session.php';
require_once '../../config/db.php';
require_once '../../config/validators.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: ../../auth/login.php");
    exit;
}

// ── FILTERS ──
$filter_user   = san_int($_GET['user'] ?? 0, 0);
$filter_action = san_str($_GET['action'] ?? '', 60);
$filter_search = validate_search(san_str($_GET['q'] ?? '', MAX_SEARCH));
$filter_from   = san_str($_GET['from'] ?? '', 10);
$filter_to     = san_str($_GET['to'] ?? '', 10);
// Sanitize dates — clear if invalid format
if ($filter_from !== '' && !validate_date($filter_from)) $filter_from = '';
if ($filter_to   !== '' && !validate_date($filter_to))   $filter_to   = '';
$page          = max(1, (int)($_GET['page'] ?? 1));
$per_page      = 50;
$offset        = ($page - 1) * $per_page;

// Build query
// Display is always limited to the last 7 days; use Export CSV for older records
$where   = ["a.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"];
$params  = [];
$types   = '';

if ($filter_user > 0) {
    $where[]  = 'a.user_id = ?';
    $params[] = $filter_user;
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

$where_clause = 'WHERE ' . implode(' AND ', $where);

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

// ── CSV EXPORT ──
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    // Always export from 1st of current month to today
    $exp_month_start = date('Y-m-01');
    $exp_today       = date('Y-m-d');

    // Build export-specific where — keep user/action/search filters, override date range
    $exp_where  = ['a.created_at >= ?', 'a.created_at <= ?'];
    $exp_params = [$exp_month_start . ' 00:00:00', $exp_today . ' 23:59:59'];
    $exp_types  = 'ss';

    if ($filter_user > 0) {
        $exp_where[]  = 'a.user_id = ?';
        $exp_params[] = $filter_user;
        $exp_types   .= 'i';
    }
    if ($filter_action !== '') {
        $exp_where[]  = 'a.action = ?';
        $exp_params[] = $filter_action;
        $exp_types   .= 's';
    }
    if ($filter_search !== '') {
        $exp_where[]  = 'a.description LIKE ?';
        $exp_params[] = '%' . $filter_search . '%';
        $exp_types   .= 's';
    }

    $exp_where_sql = 'WHERE ' . implode(' AND ', $exp_where);

    $exp_sql  = "SELECT a.log_id, u.full_name, a.action, a.description, a.created_at
                 FROM audit_logs a
                 LEFT JOIN users u ON a.user_id = u.user_id
                 $exp_where_sql
                 ORDER BY a.created_at ASC";
    $exp_stmt = $conn->prepare($exp_sql);
    $exp_stmt->bind_param($exp_types, ...$exp_params);
    $exp_stmt->execute();
    $exp_rows = $exp_stmt->get_result();

    $filter_labels = ['Period: ' . date('M 1, Y') . ' — ' . date('M d, Y')];
    if ($filter_user > 0)      $filter_labels[] = 'User ID: ' . $filter_user;
    if ($filter_action !== '') $filter_labels[] = 'Action: ' . $filter_action;
    if ($filter_search !== '') $filter_labels[] = 'Search: "' . $filter_search . '"';
    $filters_text = implode('   |   ', $filter_labels);

    $filename = 'TG-BASICS_Activity_Log_' . date('Y-m-d') . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    // UTF-8 BOM
    echo "\xEF\xBB\xBF";
    ?>
<html xmlns:o="urn:schemas-microsoft-com:office:office"
      xmlns:x="urn:schemas-microsoft-com:office:excel"
      xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta charset="UTF-8"/>
<!--[if gte mso 9]><xml>
<x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>
<x:Name>Activity Log</x:Name>
<x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions>
</x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook>
</xml><![endif]-->
<style>
  body { font-family: Calibri, Arial, sans-serif; font-size: 11pt; }
  table { border-collapse: collapse; width: 100%; }
  .title  { font-size:16pt; font-weight:bold; color:#7B5A00; background:#FFF8E7; padding:12px 10px 4px; border-bottom:3px solid #B8860B; }
  .sub    { font-size:11pt; color:#555; background:#FFF8E7; padding:2px 10px 10px; border-bottom:2px solid #e0c97a; }
  .meta   { font-size:9.5pt; color:#333; background:#fffdf5; padding:6px 10px; }
  .th     { background:#B8860B; color:#fff; font-weight:bold; font-size:10pt; padding:8px 10px; border:1px solid #9a6f00; white-space:nowrap; }
  .td     { border:1px solid #ddd; padding:6px 10px; font-size:10pt; vertical-align:top; }
  .td-num { border:1px solid #ddd; padding:6px 10px; font-size:10pt; text-align:right; color:#888; }
  .td-act { border:1px solid #ddd; padding:6px 10px; font-size:10pt; font-weight:bold; white-space:nowrap; }
  .td-dt  { border:1px solid #ddd; padding:6px 10px; font-size:10pt; white-space:nowrap; }
  .footer { font-size:9pt; color:#aaa; padding:6px 10px; border-top:1px solid #ddd; background:#fafafa; }
</style>
</head>
<body>
<table>
  <tr><td colspan="6" class="title">TG-BASICS Insurance Management System</td></tr>
  <tr><td colspan="6" class="sub">System Activity Log Report</td></tr>
  <tr><td colspan="6" class="meta">
    <b>Generated By:</b> <?= htmlspecialchars($_SESSION['full_name'] ?? 'Unknown') ?> &nbsp;&nbsp;
    <b>Generated On:</b> <?= date('F d, Y  h:i A') ?> &nbsp;&nbsp;
    <b>Period:</b> <?= htmlspecialchars($filters_text) ?>
  </td></tr>
  <tr><td colspan="6" style="padding:6px;background:#fff;"></td></tr>
  <tr>
    <td class="th" width="40">#</td>
    <td class="th" width="160">User</td>
    <td class="th" width="180">Action</td>
    <td class="th" width="420">Description</td>
    <td class="th" width="110">Date</td>
    <td class="th" width="90">Time</td>
  </tr>
    <?php
    $action_colors = [
        'LOGIN'            => '#e8f5e9', 'LOGOUT'           => '#f0f0f0',
        'ACCOUNT_CREATED'  => '#fff8e1', 'ACCOUNT_DELETED'  => '#ffebee',
        'PASSWORD_RESET'   => '#fff8e1', 'CLIENT_ADDED'     => '#e8f5e9',
        'CLIENT_UPDATED'   => '#fff8e1', 'CLIENT_DELETED'   => '#ffebee',
        'VEHICLE_ADDED'    => '#e8f5e9', 'VEHICLE_UPDATED'  => '#fff8e1',
        'POLICY_CREATED'   => '#fff8e1', 'POLICY_RENEWED'   => '#e8f5e9',
        'POLICY_DELETED'   => '#ffebee', 'CLAIM_ADDED'      => '#fff8e1',
        'CLAIM_UPDATED'    => '#fff8e1', 'CLAIM_DELETED'    => '#ffebee',
        'REPAIR_CREATED'   => '#fff8e1', 'REPAIR_UPDATED'   => '#fff8e1',
        'BILLING_UPDATED'  => '#fff8e1', 'BILLING_DELETED'  => '#ffebee',
        'PAYMENT_RECORDED' => '#e8f5e9',
    ];
    $n = 1; $stripe = false;
    while ($r = $exp_rows->fetch_assoc()):
        $ts  = strtotime($r['created_at']);
        $bg  = $action_colors[$r['action']] ?? ($stripe ? '#f9f9f9' : '#ffffff');
        $stripe = !$stripe;
    ?>
  <tr style="background:<?= $bg ?>;">
    <td class="td-num"><?= $n++ ?></td>
    <td class="td"><?= htmlspecialchars($r['full_name'] ?? 'Deleted User') ?></td>
    <td class="td-act"><?= htmlspecialchars($r['action']) ?></td>
    <td class="td"><?= htmlspecialchars($r['description']) ?></td>
    <td class="td-dt"><?= date('M d, Y', $ts) ?></td>
    <td class="td-dt"><?= date('h:i A', $ts) ?></td>
  </tr>
    <?php endwhile; ?>
  <tr><td colspan="6" style="padding:8px;background:#fff;"></td></tr>
  <tr><td colspan="6" class="footer">&mdash; End of Report &mdash; &nbsp; TG-BASICS &copy; <?= date('Y') ?></td></tr>
</table>
</body>
</html>
    <?php
    exit;
}

// For filter dropdowns
$all_users   = $conn->query("SELECT user_id, full_name FROM users ORDER BY full_name ASC");
$all_actions = $conn->query("SELECT DISTINCT action FROM audit_logs ORDER BY action ASC");

$page_title  = 'Activity Log';
$active_page = 'activity_log';
$base_path   = '../../';
require_once '../../includes/header.php';
require_once '../../includes/navbar.php';
?>

<div class="main">
<?php
$topbar_title      = 'Activity Log';
$topbar_breadcrumb = ['Administration', 'Activity Log'];
require_once '../../includes/topbar.php';
?>

  <div class="content">


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
        &middot; <span style="color:var(--gold);">Showing last 7 days only.</span> Use Export CSV for older records.
        <?php if ($total_pages > 1): ?>
          &middot; Page <?= $page ?> of <?= $total_pages ?>
        <?php endif; ?>
      </span>
      <?php if ($total > 0): ?>
      <?php
        $export_qs = $_GET;
        unset($export_qs['page']);
        $export_qs['export'] = 'csv';
      ?>
      <a href="?<?= http_build_query($export_qs) ?>" class="btn-sm-gold" title="Export to Excel">
        <?= icon('floppy-disk', 14) ?> Export Excel
      </a>
      <?php endif; ?>
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

<?php require_once '../../includes/footer.php'; ?>
