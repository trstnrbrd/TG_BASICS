<?php
require_once __DIR__ . "/../../config/session.php";
require_once '../../config/db.php';
require_once '../../config/validators.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin', 'mechanic'])) {
    header("Location: ../../auth/login.php");
    exit;
}

$page_title  = 'Quotations & Receipts';
$active_page = 'quotations';
$base_path   = '../../';
require_once '../../includes/header.php';
require_once '../../includes/navbar.php';
?>

<div class="main">

<?php
$topbar_title      = 'Quotations & Receipts';
$topbar_breadcrumb = ['Repair Shop', 'Quotations & Receipts'];
$topbar_show_clock = true;
require_once '../../includes/topbar.php';
?>

  <div class="content">


    <!-- STAT CARDS -->
    <?php
    $stats = [
        ['icon' => 'receipt',      'theme' => 'gold',  'label' => 'Total Quotations', 'value' => 0, 'trend' => 'All time'],
        ['icon' => 'clock',        'theme' => 'yellow','label' => 'Pending Approval',  'value' => 0, 'trend' => 'Awaiting client'],
        ['icon' => 'check-circle', 'theme' => 'green', 'label' => 'Converted to Receipt','value' => 0, 'trend' => 'Paid'],
        ['icon' => 'x-circle',     'theme' => 'red',   'label' => 'Cancelled',         'value' => 0, 'trend' => 'All time'],
    ];
    $theme_map = [
        'gold'   => ['accent' => 'linear-gradient(90deg,#D4A017,#E8D5A3)', 'icon_bg' => 'var(--gold-light)',  'icon_color' => 'var(--gold)'],
        'green'  => ['accent' => 'linear-gradient(90deg,#2E7D52,#52B788)', 'icon_bg' => 'var(--success-bg)', 'icon_color' => 'var(--success)'],
        'yellow' => ['accent' => 'linear-gradient(90deg,#9A6B00,#D4A017)', 'icon_bg' => 'var(--warning-bg)', 'icon_color' => 'var(--warning)'],
        'red'    => ['accent' => 'linear-gradient(90deg,#8B1A1A,#C0392B)', 'icon_bg' => 'var(--danger-bg)',  'icon_color' => 'var(--danger)'],
    ];
    ?>
    <div class="dash-stats" style="grid-template-columns:repeat(4,1fr);">
      <?php foreach ($stats as $s):
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

    <!-- HOW IT WORKS -->
    <div class="card" style="margin-bottom:1.25rem;">
      <div class="card-header">
        <div class="card-icon"><?= icon('information-circle', 16) ?></div>
        <div>
          <div class="card-title">Quotation Workflow</div>
          <div class="card-sub">How quotations and receipts work in TG-BASICS</div>
        </div>
      </div>
      <div style="padding:1.25rem;display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;">
        <?php
        $steps = [
            ['icon' => 'wrench',       'num' => '1', 'title' => 'Create Repair Job',    'desc' => 'Log the vehicle intake with the condition checklist.'],
            ['icon' => 'receipt',      'num' => '2', 'title' => 'Generate Quotation',   'desc' => 'Select services and parts. System computes the total.'],
            ['icon' => 'check-circle', 'num' => '3', 'title' => 'Client Approval',      'desc' => 'Mark quotation as approved once client agrees.'],
            ['icon' => 'document-text','num' => '4', 'title' => 'Convert to E-Receipt', 'desc' => 'On payment, one click converts to official e-receipt. No double encoding.'],
        ];
        foreach ($steps as $step): ?>
        <div style="display:flex;flex-direction:column;align-items:flex-start;gap:0.5rem;">
          <div style="display:flex;align-items:center;gap:0.6rem;margin-bottom:0.25rem;">
            <div style="width:26px;height:26px;border-radius:50%;background:var(--gold-pale);border:1.5px solid var(--gold-muted);display:flex;align-items:center;justify-content:center;font-size:0.72rem;font-weight:800;color:var(--gold);flex-shrink:0;">
              <?= $step['num'] ?>
            </div>
            <div style="color:var(--gold);flex-shrink:0;"><?= icon($step['icon'], 16) ?></div>
          </div>
          <div style="font-size:0.82rem;font-weight:700;color:var(--text-primary);"><?= $step['title'] ?></div>
          <div style="font-size:0.75rem;color:var(--text-muted);line-height:1.5;"><?= $step['desc'] ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- QUOTATIONS TABLE -->
    <div class="card">
      <div class="card-header" style="justify-content:space-between;">
        <div style="display:flex;align-items:center;gap:0.75rem;">
          <div class="card-icon"><?= icon('receipt', 16) ?></div>
          <div>
            <div class="card-title">All Quotations</div>
            <div class="card-sub">Generated from repair jobs</div>
          </div>
        </div>
        <a href="../repair/repair_list.php" class="btn-sm-gold">
          <?= icon('wrench', 12) ?> View Repair Jobs
        </a>
      </div>

      <div class="empty-state">
        <div class="empty-icon"><?= icon('receipt', 32) ?></div>
        <div class="empty-title">No quotations yet</div>
        <div class="empty-desc">Quotations will appear here once repair jobs are created and services are selected.</div>
        <a href="../repair/repair_list.php" class="btn-primary" style="margin-top:1rem;display:inline-flex;align-items:center;gap:0.4rem;">
          <?= icon('wrench', 14) ?> Go to Repair Jobs
        </a>
      </div>
    </div>

  </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
