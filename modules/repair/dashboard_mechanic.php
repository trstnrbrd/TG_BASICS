<?php
require_once __DIR__ . "/../../config/session.php";
require_once '../../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'mechanic') {
    header("Location: ../../auth/login.php");
    exit;
}

$full_name = $_SESSION['full_name'] ?? 'Mechanic';

// ── STATS ──
$active_jobs   = (int)$conn->query("SELECT COUNT(*) as c FROM repair_jobs WHERE status = 'in_progress'")->fetch_assoc()['c'];
$completed_today = (int)$conn->query("SELECT COUNT(*) as c FROM repair_jobs WHERE status = 'completed' AND DATE(updated_at) = CURDATE()")->fetch_assoc()['c'];
$pending_jobs  = (int)$conn->query("SELECT COUNT(*) as c FROM repair_jobs WHERE status = 'pending'")->fetch_assoc()['c'];

// ── RECENT REPAIR JOBS (latest 5 active/pending) ──
$jobs = $conn->query("
    SELECT j.job_id, j.job_number, j.service_type, j.status, j.repair_date, j.release_date,
           c.full_name, v.plate_number, v.make, v.model
    FROM repair_jobs j
    INNER JOIN clients  c ON j.client_id  = c.client_id
    INNER JOIN vehicles v ON j.vehicle_id = v.vehicle_id
    WHERE j.status IN ('pending','in_progress','for_pickup')
    ORDER BY j.created_at DESC
    LIMIT 5
");

$service_labels = [
    'repair_panel'   => 'Per Panel Repair',
    'repair_full'    => 'Full Body Repair',
    'paint_panel'    => 'Per Panel Paint',
    'paint_full'     => 'Full Body Paint',
    'washover_basic' => 'Basic Wash Over',
    'washover_full'  => 'Fully Wash Over',
    'custom'         => 'Custom / Mixed',
];

$status_badges = [
    'pending'     => ['Pending',     'badge-yellow'],
    'in_progress' => ['In Progress', 'badge-blue'],
    'for_pickup'  => ['For Pickup',  'badge-gold'],
    'completed'   => ['Completed',   'badge-green'],
    'cancelled'   => ['Cancelled',   'badge-gray'],
];

$page_title  = 'Mechanic Dashboard';
$active_page = 'dashboard';
$base_path   = '../../';
$extra_css   = '<link rel="stylesheet" href="../../assets/css/dashboard.css?v=' . filemtime(__DIR__ . '/../../assets/css/dashboard.css') . '"/>
<link rel="stylesheet" href="../../assets/css/mechanic/repair_list.css?v=' . filemtime(__DIR__ . '/../../assets/css/mechanic/repair_list.css') . '"/>';
require_once '../../includes/header.php';
require_once '../../includes/navbar.php';
?>

<div class="main">

<?php
$topbar_title      = 'Mechanic Dashboard';
$topbar_breadcrumb = ['Repair Shop', 'Dashboard'];
$topbar_show_clock = true;
require_once '../../includes/topbar.php';
?>

  <div class="content">

    <!-- STAT CARDS -->
    <?php
    $mech_stats = [
        ['icon' => 'wrench',       'theme' => 'gold',  'label' => 'Active Repair Jobs', 'value' => $active_jobs,     'trend' => 'In progress'],
        ['icon' => 'check-circle', 'theme' => 'green', 'label' => 'Completed Today',     'value' => $completed_today, 'trend' => 'Today'],
        ['icon' => 'clock',        'theme' => 'amber', 'label' => 'Pending Jobs',        'value' => $pending_jobs,    'trend' => 'Awaiting start'],
    ];
    $theme_map = [
        'gold'  => ['accent' => 'linear-gradient(90deg,#D4A017,#E8D5A3)', 'icon_bg' => 'var(--gold-light)',  'icon_color' => 'var(--gold)'],
        'green' => ['accent' => 'linear-gradient(90deg,#2E7D52,#52B788)', 'icon_bg' => 'var(--success-bg)', 'icon_color' => 'var(--success)'],
        'amber' => ['accent' => 'linear-gradient(90deg,#B8860B,#D4A017)', 'icon_bg' => 'var(--warning-bg)', 'icon_color' => 'var(--warning)'],
    ];
    ?>
    <div class="dash-stats">
      <?php foreach ($mech_stats as $s):
          $t = $theme_map[$s['theme']]; ?>
      <div class="dash-stat">
        <div class="dash-stat-accent" style="background:<?= $t['accent'] ?>;"></div>
        <div class="dash-stat-top">
          <div class="dash-stat-icon" style="background:<?= $t['icon_bg'] ?>;color:<?= $t['icon_color'] ?>;">
            <?= icon($s['icon'], 18) ?>
          </div>
          <span class="dash-stat-badge" style="background:var(--bg);color:var(--text-muted);border:1px solid var(--border);">
            <?= $s['trend'] ?>
          </span>
        </div>
        <div class="dash-stat-value"><?= $s['value'] ?></div>
        <div class="dash-stat-label"><?= $s['label'] ?></div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- REPAIR JOBS + QUICK ACTIONS -->
    <div style="display:grid;grid-template-columns:2fr 1fr;gap:1.25rem;">

      <!-- ACTIVE REPAIR JOBS -->
      <div class="card" style="margin-bottom:0;">
        <div class="card-header" style="justify-content:space-between;">
          <div style="display:flex;align-items:center;gap:0.75rem;">
            <div class="card-icon"><?= icon('wrench', 16) ?></div>
            <div>
              <div class="card-title">Active Repair Jobs</div>
              <div class="card-sub">Pending &amp; in-progress orders</div>
            </div>
          </div>
          <a href="repair_list.php" class="btn-sm-gold"><?= icon('arrow-right', 13) ?> View All</a>
        </div>

        <?php if ($jobs->num_rows > 0): ?>
        <table class="tg-table">
          <thead>
            <tr>
              <th>Job #</th>
              <th>Client</th>
              <th>Vehicle</th>
              <th>Service</th>
              <th>Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php while ($j = $jobs->fetch_assoc()):
              $sb  = $status_badges[$j['status']] ?? ['Unknown','badge-gray'];
              $svc = $service_labels[$j['service_type']] ?? $j['service_type'];
            ?>
            <tr>
              <td><span class="job-num"><?= htmlspecialchars($j['job_number']) ?></span></td>
              <td><div class="cell-primary"><?= htmlspecialchars($j['full_name']) ?></div></td>
              <td>
                <div class="cell-primary"><?= htmlspecialchars($j['plate_number']) ?></div>
                <div class="cell-sub"><?= htmlspecialchars($j['make'] . ' ' . $j['model']) ?></div>
              </td>
              <td><span class="cell-service"><?= htmlspecialchars($svc) ?></span></td>
              <td><span class="badge <?= $sb[1] ?>"><?= $sb[0] ?></span></td>
              <td>
                <a href="view_repair.php?id=<?= $j['job_id'] ?>" class="btn-sm-gold" style="padding:0.3rem 0.6rem;">
                  <?= icon('eye', 13) ?>
                </a>
              </td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
        <?php else: ?>
        <div class="empty-state" style="padding:2rem 0;">
          <div class="empty-icon"><?= icon('wrench', 28) ?></div>
          <div class="empty-title">No active jobs</div>
          <div class="empty-desc">All caught up! No pending or in-progress repair jobs.</div>
          <a href="add_repair.php" class="btn-primary" style="margin-top:0.75rem;display:inline-flex;align-items:center;gap:0.4rem;">
            <?= icon('plus', 14) ?> New Repair Job
          </a>
        </div>
        <?php endif; ?>
      </div>

      <!-- QUICK ACTIONS -->
      <div class="card" style="margin-bottom:0;">
        <div class="card-header">
          <div class="card-icon"><?= icon('arrow-right', 16) ?></div>
          <div>
            <div class="card-title">Quick Actions</div>
            <div class="card-sub">Common tasks</div>
          </div>
        </div>
        <div style="display:flex;flex-direction:column;gap:0.5rem;padding:1rem;">
          <?php
          $actions = [
            ['add_repair.php',                             'plus',             'New Repair Job',       'Log a new repair order',           '#2E7D52', 'rgba(46,125,82,0.12)'],
            ['repair_list.php',                            'wrench',           'All Repair Jobs',      'View and manage all jobs',         '#1A6B9A', 'rgba(26,107,154,0.12)'],
            ['../quotations/quotation_list.php',           'receipt',          'Quotations',           'Generate and manage quotations',   '#B8860B', 'rgba(184,134,11,0.12)'],
            ['../clients/client_list.php',                 'users',            'Client Records',       'Browse client profiles',           '#7B3FA0', 'rgba(123,63,160,0.12)'],
          ];
          foreach ($actions as $a): ?>
          <a href="<?= $a[0] ?>" class="quick-action">
            <div class="quick-action-icon" style="background:<?= $a[5] ?>;color:<?= $a[4] ?>;"><?= icon($a[1], 16) ?></div>
            <div>
              <div class="quick-action-label"><?= $a[2] ?></div>
              <div class="quick-action-hint"><?= $a[3] ?></div>
            </div>
          </a>
          <?php endforeach; ?>
        </div>
      </div>

    </div>

  </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
