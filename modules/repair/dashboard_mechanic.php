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
require_once '../../includes/header.php';
require_once '../../includes/navbar.php';
?>

<div class="main">

<?php
$topbar_title      = 'Mechanic Dashboard';
$topbar_breadcrumb = ['Repair Shop', 'Dashboard'];
require_once '../../includes/topbar.php';
?>

  <div class="content">

    <div class="page-header">
      <div class="page-header-title"><?= icon('wrench', 18) ?> Welcome, <?= htmlspecialchars($full_name) ?></div>
      <div class="page-header-sub">Overview of repair shop activity</div>
    </div>

    <!-- STAT CARDS -->
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1.25rem;margin-bottom:1.5rem;">

      <!-- Active Repair Jobs -->
      <div class="card" style="margin-bottom:0;padding:1.25rem 1.5rem;display:flex;align-items:center;gap:1rem;">
        <div class="card-icon" style="width:44px;height:44px;border-radius:12px;flex-shrink:0;">
          <?= icon('wrench', 20) ?>
        </div>
        <div>
          <div style="font-size:2rem;font-weight:800;color:var(--text-primary);line-height:1;letter-spacing:-1px;">0</div>
          <div style="font-size:0.7rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.5px;margin-top:0.2rem;">Active Repair Jobs</div>
        </div>
      </div>

      <!-- Completed Today -->
      <div class="card" style="margin-bottom:0;padding:1.25rem 1.5rem;display:flex;align-items:center;gap:1rem;">
        <div class="card-icon" style="width:44px;height:44px;border-radius:12px;flex-shrink:0;">
          <?= icon('check-circle', 20) ?>
        </div>
        <div>
          <div style="font-size:2rem;font-weight:800;color:var(--text-primary);line-height:1;letter-spacing:-1px;">0</div>
          <div style="font-size:0.7rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.5px;margin-top:0.2rem;">Completed Today</div>
        </div>
      </div>

      <!-- Pending Quotations -->
      <div class="card" style="margin-bottom:0;padding:1.25rem 1.5rem;display:flex;align-items:center;gap:1rem;">
        <div class="card-icon" style="width:44px;height:44px;border-radius:12px;flex-shrink:0;">
          <?= icon('receipt', 20) ?>
        </div>
        <div>
          <div style="font-size:2rem;font-weight:800;color:var(--text-primary);line-height:1;letter-spacing:-1px;">0</div>
          <div style="font-size:0.7rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.5px;margin-top:0.2rem;">Pending Quotations</div>
        </div>
      </div>

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
