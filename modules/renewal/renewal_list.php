<?php
require_once __DIR__ . "/../../config/session.php";
require_once '../../config/db.php';
require_once '../../config/validators.php';
require_once '../../config/settings.php';

$urg_days = (int)getSetting($conn, 'renewal_urgent_days', '7');
$exp_days = (int)getSetting($conn, 'renewal_expiring_days', '30');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header("Location: ../../auth/login.php");
    exit;
}

// ── FILTERS ──
$filter       = san_enum($_GET['filter'] ?? 'all', ['all', 'urgent', 'expiring', 'stable', 'expired', 'renewed']);
$search       = validate_search(san_str($_GET['search'] ?? '', MAX_SEARCH));
$show_renewed = isset($_GET['show_renewed']) && $_GET['show_renewed'] === '1';

// ── BUILD QUERY ──
$where_clauses = [];
$params        = [];
$types         = '';

// Hide renewed policies by default (unless explicitly filtering for them)
// Hide renewed from 'all' by default unless show_renewed=1; date-based filters always include all statuses
if ($filter === 'all' && !$show_renewed) {
    $where_clauses[] = "p.is_renewed = 0";
}

if ($search !== '') {
    $like = "%$search%";
    $where_clauses[] = "(c.full_name LIKE ? OR v.plate_number LIKE ? OR p.policy_number LIKE ?)";
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types   .= 'sss';
}

switch ($filter) {
    case 'urgent':
        $where_clauses[] = "p.policy_end >= CURDATE() AND DATEDIFF(p.policy_end, CURDATE()) <= $urg_days";
        break;
    case 'expiring':
        $exp_start = $urg_days + 1;
        $where_clauses[] = "p.policy_end >= CURDATE() AND DATEDIFF(p.policy_end, CURDATE()) BETWEEN $exp_start AND $exp_days";
        break;
    case 'stable':
        $where_clauses[] = "p.policy_end >= CURDATE() AND DATEDIFF(p.policy_end, CURDATE()) > $exp_days";
        break;
    case 'expired':
        $where_clauses[] = "p.policy_end < CURDATE()";
        break;
    case 'renewed':
        $where_clauses[] = "p.is_renewed = 1";
        break;
}

$where_sql = count($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

$sql = "
    SELECT
        p.policy_id, p.policy_number, p.coverage_type,
        p.policy_start, p.policy_end, p.is_renewed,
        p.total_premium, p.amount_paid, p.balance,
        p.payment_status,
        DATEDIFF(p.policy_end, CURDATE()) AS days_left,
        c.client_id, c.full_name, c.contact_number,
        v.plate_number, v.make, v.model, v.year_model
    FROM insurance_policies p
    INNER JOIN clients c ON p.client_id = c.client_id
    INNER JOIN vehicles v ON p.vehicle_id = v.vehicle_id
    $where_sql
    ORDER BY p.policy_end ASC
";

$stmt = $conn->prepare($sql);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$policies = $stmt->get_result();

// ── SUMMARY COUNTS ──
$exp_start_count = $urg_days + 1;
$counts = $conn->query("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN policy_end >= CURDATE() AND DATEDIFF(policy_end, CURDATE()) <= $urg_days THEN 1 ELSE 0 END) AS urgent,
        SUM(CASE WHEN policy_end >= CURDATE() AND DATEDIFF(policy_end, CURDATE()) BETWEEN $exp_start_count AND $exp_days THEN 1 ELSE 0 END) AS expiring,
        SUM(CASE WHEN policy_end >= CURDATE() AND DATEDIFF(policy_end, CURDATE()) > $exp_days THEN 1 ELSE 0 END) AS stable,
        SUM(CASE WHEN policy_end < CURDATE() THEN 1 ELSE 0 END) AS expired,
        SUM(CASE WHEN is_renewed = 1 THEN 1 ELSE 0 END) AS renewed
    FROM insurance_policies
")->fetch_assoc();

$page_title  = 'Renewal Tracking';
$active_page = 'renewal';
$base_path   = '../../';
require_once '../../includes/header.php';
require_once '../../includes/navbar.php';
?>

<link rel="stylesheet" href="../../assets/css/shared/clients.css"/>

<div class="main">

<?php
$topbar_title      = 'Policy Status and Renewal Tracking';
$topbar_breadcrumb = ['Insurance', 'Renewal Tracking'];
require_once '../../includes/topbar.php';
?>

  <div class="content">

    <!-- SUMMARY CARDS -->
    <div style="display:grid;grid-template-columns:repeat(6,1fr);gap:1rem;margin-bottom:1.5rem;">
      <?php
      $summary = [
        ['all',      'Total Policies',    $counts['total'],    'badge-gold',  'document'],
        ['urgent',   'Urgent (' . $urg_days . 'd)',  $counts['urgent'],   'badge-red',   'exclamation-triangle'],
        ['expiring', 'Expiring (' . $exp_days . 'd)',$counts['expiring'],'badge-yellow','clock'],
        ['stable',   'Stable',            $counts['stable'],   'badge-green', 'check-circle'],
        ['expired',  'Expired',           $counts['expired'],  'badge-gray',  'x-mark'],
        ['renewed',  'Renewed',           $counts['renewed'],  'badge-info',  'arrow-path'],
      ];
      foreach ($summary as [$key, $label, $count, $badge, $ico]):
        $active_card = ($filter === $key) ? 'border-color:var(--gold-bright);background:var(--gold-pale);' : '';
      ?>
      <a href="?filter=<?= $key ?><?= $search ? '&search='.urlencode($search) : '' ?><?= in_array($key, ['all','renewed']) ? '&show_renewed=1' : '' ?>"
         style="text-decoration:none;">
        <div class="card" style="margin-bottom:0;padding:1rem 1.25rem;display:flex;align-items:center;gap:0.75rem;transition:all 0.15s;<?= $active_card ?>">
          <div class="card-icon" style="width:38px;height:38px;border-radius:9px;flex-shrink:0;">
            <?= icon($ico, 16) ?>
          </div>
          <div>
            <div style="font-size:1.5rem;font-weight:800;color:var(--text-primary);line-height:1;letter-spacing:-0.5px;"><?= (int)$count ?></div>
            <div style="font-size:0.62rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.5px;margin-top:0.15rem;"><?= $label ?></div>
          </div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>

    <!-- TOOLBAR -->
    <form method="GET" action="" style="margin-bottom:1rem;">
      <div style="display:flex;gap:0.6rem;align-items:center;flex-wrap:wrap;">
        <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>"/>
        <div style="position:relative;flex:1;min-width:200px;max-width:420px;">
          <span style="position:absolute;left:0.85rem;top:50%;transform:translateY(-50%);color:var(--text-muted);pointer-events:none;"><?= icon('magnifying-glass', 14) ?></span>
          <input type="text" name="search" class="filter-input"
            placeholder="Search by client, plate number, or policy number..."
            value="<?= htmlspecialchars($search) ?>"
            style="padding-left:2.4rem;width:100%;"/>
        </div>
        <button type="submit" class="btn-primary"><?= icon('magnifying-glass', 14) ?> Search</button>
        <?php if ($search): ?>
        <a href="?filter=<?= $filter ?>" class="btn-ghost"><?= icon('x-mark', 14) ?> Clear</a>
        <?php endif; ?>
      </div>
    </form>

    <!-- TABLE -->
    <div class="card" style="margin-bottom:0;">
      <div class="card-header">
        <div class="card-icon"><?= icon('clock', 16) ?></div>
        <div>
          <div class="card-title">
            <?php
            $titles = [
              'all'      => 'All Policies',
              'urgent'   => 'Urgent — Expiring Within ' . $urg_days . ' Days',
              'expiring' => 'Expiring Within ' . $exp_days . ' Days',
              'stable'   => 'Stable Policies',
              'expired'  => 'Expired Policies',
              'renewed'  => 'Renewed Policies',
            ];
            echo $titles[$filter] ?? 'All Policies';
            ?>
          </div>
          <div class="card-sub"><?= $policies->num_rows ?> record<?= $policies->num_rows !== 1 ? 's' : '' ?></div>
        </div>
      </div>

      <?php if ($policies->num_rows > 0): ?>
      <div class="tg-table-wrap">
        <table class="tg-table">
          <thead>
            <tr>
              <th style="text-align:center;">Client</th>
              <th style="text-align:center;">Plate</th>
              <th style="text-align:center;">Policy Number</th>
              <th style="text-align:center;">Coverage</th>
              <th style="text-align:center;">Expiry Date</th>
              <th style="text-align:center;">Status</th>
              <th style="text-align:right;">Balance</th>
              <th style="text-align:center;">Payment</th>
              <th style="text-align:center;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php while ($row = $policies->fetch_assoc()):
              $days = (int)$row['days_left'];

              if ($row['policy_end'] < date('Y-m-d')) {
                $status_badge = '<span class="badge badge-gray">Expired</span>';
                $row_style    = '';
              } elseif ($days <= $urg_days) {
                $status_badge = '<span class="badge badge-red">' . icon('exclamation-triangle', 10) . ' Urgent &mdash; ' . $days . 'd left</span>';
                $row_style    = 'background:rgba(192,57,43,0.03);';
              } elseif ($days <= $exp_days) {
                $status_badge = '<span class="badge badge-yellow">' . icon('clock', 10) . ' Expiring &mdash; ' . $days . 'd left</span>';
                $row_style    = 'background:rgba(184,134,11,0.03);';
              } else {
                $status_badge = '<span class="badge badge-green">' . icon('check-circle', 10) . ' Stable</span>';
                $row_style    = '';
              }
              // Append renewed indicator without overriding the urgency status
              if ($row['is_renewed']) {
                $status_badge .= ' <span class="badge badge-info">' . icon('arrow-path', 10) . ' Renewed</span>';
              }

              $pay_badge = match($row['payment_status']) {
                'Paid'    => '<span class="badge badge-green">Paid</span>',
                'Partial' => '<span class="badge badge-yellow">Partial</span>',
                'Overdue' => '<span class="badge badge-orange">Overdue</span>',
                default   => '<span class="badge badge-red">Unpaid</span>',
              };
            ?>
            <tr style="<?= $row_style ?>">
              <td style="text-align:center;">
                <div style="font-weight:700;color:var(--text-primary);font-size:0.82rem;"><?= htmlspecialchars($row['full_name']) ?></div>
                <div style="font-size:0.7rem;color:var(--text-muted);"><?= htmlspecialchars($row['contact_number']) ?></div>
              </td>
              <td style="text-align:center;"><span class="badge-dark"><?= htmlspecialchars($row['plate_number']) ?></span></td>
              <td style="font-size:0.75rem;color:var(--text-secondary);font-weight:600;text-align:center;"><?= htmlspecialchars($row['policy_number']) ?></td>
              <td style="font-size:0.75rem;color:var(--text-muted);text-align:center;"><?= htmlspecialchars($row['coverage_type']) ?></td>
              <td style="text-align:center;">
                <div style="font-size:0.82rem;font-weight:700;color:var(--text-primary);"><?= date('M d, Y', strtotime($row['policy_end'])) ?></div>
                <div style="font-size:0.68rem;color:var(--text-muted);"><?= date('M d, Y', strtotime($row['policy_start'])) ?> &mdash; start</div>
              </td>
              <td style="text-align:center;"><?= $status_badge ?></td>
              <td style="text-align:right;">
                <?php if ($row['balance'] > 0): ?>
                  <span style="color:var(--warning);font-weight:700;font-size:0.82rem;">PHP <?= number_format($row['balance'], 2) ?></span>
                <?php else: ?>
                  <span style="color:var(--success);font-weight:700;font-size:0.82rem;"><?= icon('check', 12) ?> Cleared</span>
                <?php endif; ?>
              </td>
              <td style="text-align:center;"><?= $pay_badge ?></td>
              <td style="text-align:center;">
                <a href="view_policy.php?id=<?= $row['policy_id'] ?>" class="btn-sm-gold" title="View" style="padding:0.35rem 0.55rem;">
                  <?= icon('eye', 14) ?>
                </a>
              </td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>

      <?php else: ?>
      <div class="empty-state">
        <div class="empty-icon"><?= icon('clock', 28) ?></div>
        <div class="empty-title">No policies found</div>
        <div class="empty-desc">
          <?= $search ? 'No results for your search.' : 'No policies in this category yet.' ?>
        </div>
      </div>
      <?php endif; ?>
    </div>

  </div>
</div>

<?php
$footer_scripts = '';
if (!empty($_GET['success'])) {
    $footer_scripts = 'Swal.fire({ toast:true, position:"top-end", icon:"success", title:' . json_encode($_GET['success']) . ', showConfirmButton:false, timer:3000, timerProgressBar:true });';
} elseif (!empty($_GET['msg'])) {
    $footer_scripts = 'Swal.fire({ toast:true, position:"top-end", icon:"info", title:' . json_encode($_GET['msg']) . ', showConfirmButton:false, timer:3000, timerProgressBar:true });';
}
require_once '../../includes/footer.php';
?>