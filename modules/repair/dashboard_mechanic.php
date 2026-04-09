<?php
require_once __DIR__ . "/../../config/session.php";
require_once '../../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'mechanic') {
    header("Location: ../../auth/login.php");
    exit;
}

$full_name = $_SESSION['full_name'] ?? 'Mechanic';

$page_title  = 'Mechanic Dashboard';
$active_page = 'dashboard';
$base_path   = '../../';
$extra_css   = '<link rel="stylesheet" href="../../assets/css/dashboard.css?v=' . filemtime(__DIR__ . '/../../assets/css/dashboard.css') . '"/>';
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
        ['icon' => 'wrench',       'theme' => 'gold',  'label' => 'Active Repair Jobs',  'value' => 0, 'trend' => 'In progress'],
        ['icon' => 'check-circle', 'theme' => 'green', 'label' => 'Completed Today',      'value' => 0, 'trend' => 'Today'],
        ['icon' => 'receipt',      'theme' => 'blue',  'label' => 'Pending Quotations',   'value' => 0, 'trend' => 'Awaiting approval'],
    ];
    $theme_map = [
        'gold'  => ['accent' => 'linear-gradient(90deg,#D4A017,#E8D5A3)', 'icon_bg' => 'var(--gold-light)',  'icon_color' => 'var(--gold)'],
        'green' => ['accent' => 'linear-gradient(90deg,#2E7D52,#52B788)', 'icon_bg' => 'var(--success-bg)', 'icon_color' => 'var(--success)'],
        'blue'  => ['accent' => 'linear-gradient(90deg,#1A6B9A,#3498DB)', 'icon_bg' => 'var(--info-bg)',    'icon_color' => 'var(--info)'],
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

    <!-- QUICK LINKS -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;">

      <div class="card" style="margin-bottom:0;">
        <div class="card-header">
          <div class="card-icon"><?= icon('wrench', 16) ?></div>
          <div>
            <div class="card-title">Repair Jobs</div>
            <div class="card-sub">View and manage active repair orders</div>
          </div>
          <a href="repair_list.php" class="btn-sm-gold" style="margin-left:auto;"><?= icon('arrow-right', 14) ?> Go</a>
        </div>
        <div style="padding:1.25rem 1.5rem;">
          <div class="empty-state" style="padding:1rem 0;">
            <div class="empty-icon"><?= icon('wrench', 28) ?></div>
            <div class="empty-title" style="font-size:0.85rem;">No active repair jobs</div>
            <div class="empty-desc">Repair jobs will appear here once the module is set up.</div>
          </div>
        </div>
      </div>

      <div class="card" style="margin-bottom:0;">
        <div class="card-header">
          <div class="card-icon"><?= icon('receipt', 16) ?></div>
          <div>
            <div class="card-title">Quotations &amp; Receipts</div>
            <div class="card-sub">Generate and manage quotations</div>
          </div>
          <a href="quotation_list.php" class="btn-sm-gold" style="margin-left:auto;"><?= icon('arrow-right', 14) ?> Go</a>
        </div>
        <div style="padding:1.25rem 1.5rem;">
          <div class="empty-state" style="padding:1rem 0;">
            <div class="empty-icon"><?= icon('receipt', 28) ?></div>
            <div class="empty-title" style="font-size:0.85rem;">No quotations yet</div>
            <div class="empty-desc">Quotations will appear here once the module is set up.</div>
          </div>
        </div>
      </div>

    </div>

  </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
