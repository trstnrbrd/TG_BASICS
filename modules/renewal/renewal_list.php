<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header("Location: ../../login.php");
    exit;
}

// ── FILTERS ──
$filter = trim($_GET['filter'] ?? 'all');   // all | urgent | expiring | stable | expired
$search = trim($_GET['search'] ?? '');

// ── BUILD QUERY ──
$where_clauses = [];
$params        = [];
$types         = '';

if ($search !== '') {
    $like = "%$search%";
    $where_clauses[] = "(c.full_name LIKE ? OR v.plate_number LIKE ? OR p.policy_number LIKE ?)";
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types   .= 'sss';
}

switch ($filter) {
    case 'urgent':
        $where_clauses[] = "p.policy_end >= CURDATE() AND DATEDIFF(p.policy_end, CURDATE()) <= 7";
        break;
    case 'expiring':
        $where_clauses[] = "p.policy_end >= CURDATE() AND DATEDIFF(p.policy_end, CURDATE()) BETWEEN 8 AND 30";
        break;
    case 'stable':
        $where_clauses[] = "p.policy_end >= CURDATE() AND DATEDIFF(p.policy_end, CURDATE()) > 30";
        break;
    case 'expired':
        $where_clauses[] = "p.policy_end < CURDATE()";
        break;
}

$where_sql = count($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

$sql = "
    SELECT
        p.policy_id, p.policy_number, p.coverage_type,
        p.policy_start, p.policy_end,
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
$counts = $conn->query("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN policy_end >= CURDATE() AND DATEDIFF(policy_end, CURDATE()) <= 7  THEN 1 ELSE 0 END) AS urgent,
        SUM(CASE WHEN policy_end >= CURDATE() AND DATEDIFF(policy_end, CURDATE()) BETWEEN 8 AND 30 THEN 1 ELSE 0 END) AS expiring,
        SUM(CASE WHEN policy_end >= CURDATE() AND DATEDIFF(policy_end, CURDATE()) > 30 THEN 1 ELSE 0 END) AS stable,
        SUM(CASE WHEN policy_end < CURDATE() THEN 1 ELSE 0 END) AS expired
    FROM insurance_policies
")->fetch_assoc();

$page_title  = 'Renewal Tracking';
$active_page = 'renewal';
$base_path   = '../../';
require_once '../../includes/header.php';
require_once '../../includes/navbar.php';
?>

<div class="main">

<?php
$topbar_title      = 'Policy Status and Renewal Tracking';
$topbar_breadcrumb = ['Insurance', 'Renewal Tracking'];
require_once '../../includes/topbar.php';
?>

  <div class="content">

    <!-- SUMMARY CARDS -->
    <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:1rem;margin-bottom:1.5rem;">
      <?php
      $summary = [
        ['all',      'Total Policies',    $counts['total'],    'badge-gold',  'document'],
        ['urgent',   'Urgent (≤7 days)',  $counts['urgent'],   'badge-red',   'exclamation-triangle'],
        ['expiring', 'Expiring (≤30 days)',$counts['expiring'],'badge-yellow','clock'],
        ['stable',   'Stable',            $counts['stable'],   'badge-green', 'check-circle'],
        ['expired',  'Expired',           $counts['expired'],  'badge-gray',  'x-mark'],
      ];
      foreach ($summary as [$key, $label, $count, $badge, $ico]):
        $active_card = ($filter === $key) ? 'border-color:var(--gold-bright);background:var(--gold-pale);' : '';
      ?>
      <a href="?filter=<?= $key ?><?= $search ? '&search='.urlencode($search) : '' ?>"
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
    <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;margin-bottom:1rem;flex-wrap:wrap;">
      <form method="GET" action="" style="flex:1;min-width:200px;max-width:420px;">
        <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>"/>
        <div style="position:relative;">
          <span style="position:absolute;left:0.85rem;top:50%;transform:translateY(-50%);color:var(--text-muted);pointer-events:none;"><?= icon('magnifying-glass', 14) ?></span>
          <input
            type="text" name="search"
            placeholder="Search by client, plate number, or policy number..."
            value="<?= htmlspecialchars($search) ?>"
            style="width:100%;background:var(--bg-3);border:1px solid var(--border);color:var(--text-primary);padding:0.6rem 0.9rem 0.6rem 2.4rem;border-radius:9px;font-family:'Plus Jakarta Sans',sans-serif;font-size:0.82rem;outline:none;transition:border-color 0.15s,box-shadow 0.15s;"
            onfocus="this.style.borderColor='var(--gold-bright)';this.style.boxShadow='0 0 0 3px rgba(212,160,23,0.1)'"
            onblur="this.style.borderColor='var(--border)';this.style.boxShadow='none'"
          />
        </div>
      </form>
      <?php if ($search): ?>
      <a href="?filter=<?= $filter ?>" class="btn-ghost"><?= icon('x-mark', 14) ?> Clear</a>
      <?php endif; ?>
    </div>

    <!-- TABLE -->
    <div class="card" style="margin-bottom:0;">
      <div class="card-header">
        <div class="card-icon"><?= icon('clock', 16) ?></div>
        <div>
          <div class="card-title">
            <?php
            $titles = [
              'all'      => 'All Policies',
              'urgent'   => 'Urgent - Expiring Within 7 Days',
              'expiring' => 'Expiring Within 30 Days',
              'stable'   => 'Stable Policies',
              'expired'  => 'Expired Policies',
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
              <th>Client</th>
              <th>Plate</th>
              <th>Policy Number</th>
              <th>Coverage</th>
              <th>Expiry Date</th>
              <th>Status</th>
              <th>Balance</th>
              <th>Payment</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php while ($row = $policies->fetch_assoc()):
              $days = (int)$row['days_left'];

              if ($row['policy_end'] < date('Y-m-d')) {
                $status_badge = '<span class="badge badge-gray">Expired</span>';
                $row_style    = '';
              } elseif ($days <= 7) {
                $status_badge = '<span class="badge badge-red">' . icon('exclamation-triangle', 10) . ' Urgent &mdash; ' . $days . 'd left</span>';
                $row_style    = 'background:rgba(192,57,43,0.03);';
              } elseif ($days <= 30) {
                $status_badge = '<span class="badge badge-yellow">' . icon('clock', 10) . ' Expiring &mdash; ' . $days . 'd left</span>';
                $row_style    = 'background:rgba(184,134,11,0.03);';
              } else {
                $status_badge = '<span class="badge badge-green">' . icon('check-circle', 10) . ' Stable</span>';
                $row_style    = '';
              }

              $pay_badge = match($row['payment_status']) {
                'Paid'    => '<span class="badge badge-green">Paid</span>',
                'Partial' => '<span class="badge badge-yellow">Partial</span>',
                default   => '<span class="badge badge-red">Unpaid</span>',
              };
            ?>
            <tr style="<?= $row_style ?>">
              <td>
                <div style="font-weight:700;color:var(--text-primary);font-size:0.82rem;"><?= htmlspecialchars($row['full_name']) ?></div>
                <div style="font-size:0.7rem;color:var(--text-muted);"><?= htmlspecialchars($row['contact_number']) ?></div>
              </td>
              <td><span class="badge-dark"><?= htmlspecialchars($row['plate_number']) ?></span></td>
              <td style="font-size:0.75rem;color:var(--text-secondary);font-weight:600;"><?= htmlspecialchars($row['policy_number']) ?></td>
              <td style="font-size:0.75rem;color:var(--text-muted);"><?= htmlspecialchars($row['coverage_type']) ?></td>
              <td>
                <div style="font-size:0.82rem;font-weight:700;color:var(--text-primary);"><?= date('M d, Y', strtotime($row['policy_end'])) ?></div>
                <div style="font-size:0.68rem;color:var(--text-muted);"><?= date('M d, Y', strtotime($row['policy_start'])) ?> &mdash; start</div>
              </td>
              <td><?= $status_badge ?></td>
              <td>
                <?php if ($row['balance'] > 0): ?>
                  <span style="color:var(--warning);font-weight:700;font-size:0.82rem;">PHP <?= number_format($row['balance'], 2) ?></span>
                <?php else: ?>
                  <span style="color:var(--success);font-weight:700;font-size:0.82rem;"><?= icon('check', 12) ?> Cleared</span>
                <?php endif; ?>
              </td>
              <td><?= $pay_badge ?></td>
              <td>
                <a href="view_policy.php?id=<?= $row['policy_id'] ?>" class="btn-sm-gold">
                  <?= icon('eye', 12) ?> View
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

<?php require_once '../../includes/footer.php'; ?>