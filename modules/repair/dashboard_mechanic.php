<?php
require_once __DIR__ . "/../../config/session.php";
require_once '../../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../auth/login.php");
    exit;
}

$page_title  = 'Mechanic Dashboard';
$active_page = 'dashboard';
$base_path   = '../../';
require_once '../../includes/header.php';
require_once '../../includes/navbar.php';
?>

<div class="main">
  <?php require_once '../../includes/topbar.php'; ?>
  <div class="content">

    <div class="page-header">
      <div class="page-header-title">Mechanic Dashboard</div>
      <div class="page-header-sub">Overview of assigned repair jobs</div>
    </div>

    <div class="card">
      <div class="card-body">
        <div class="empty-state">
          <div class="empty-icon"><?= icon('wrench', 40) ?></div>
          <div class="empty-title">Test</div>
          <div class="empty-desc"></div>
        </div>
      </div>
    </div>

  </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
