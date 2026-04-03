<?php
require_once __DIR__ . "/../../config/session.php";
require_once '../../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../auth/login.php");
    exit;
}

$page_title  = 'Claims Tracking';
$active_page = 'claims';
$base_path   = '../../';
require_once '../../includes/header.php';
require_once '../../includes/navbar.php';
?>

<div class="main">
  <?php require_once '../../includes/topbar.php'; ?>
  <div class="content">

    <div class="page-header">
      <div class="page-header-title">Claims Tracking</div>
      <div class="page-header-sub">Manage and track insurance claims</div>
    </div>

    <div class="card">
      <div class="card-body">
        <div class="empty-state">
          <div class="empty-icon"><?= icon('clipboard-list', 40) ?></div>
          <div class="empty-title"></div>
          <div class="empty-desc">Test</div>
        </div>
      </div>
    </div>

  </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
