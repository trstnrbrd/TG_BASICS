<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header("Location: ../auth/login.php");
    exit;
}

$full_name = $_SESSION['full_name'];
$initials  = substr(implode('', array_map(fn($w) => strtoupper($w[0]), explode(' ', $full_name))), 0, 2);

$page_title  = 'Settings';
$active_page = 'settings';
$base_path   = '../';
require_once '../includes/header.php';
require_once '../includes/navbar.php';
?>

<div class="main">
<?php
$topbar_title      = 'Settings';
$topbar_breadcrumb = ['System', 'Settings'];
require_once '../includes/topbar.php';
?>
 

  <div class="content">
    <div class="page-header">
      <div class="page-header-title"><?= icon('cog', 18) ?> System Settings</div>
      <div class="page-header-sub">Configure system preferences for TG-BASICS.</div>
    </div>

    <div class="empty-state" style="background:var(--bg-3);border:1px solid var(--border);border-radius:12px;">
      <div class="empty-icon"><?= icon('cog', 28) ?></div>
      <div class="empty-title">Settings coming soon</div>
      <div class="empty-desc">This section is under development and will be available in a future update.</div>
    </div>
  </div>
</div>

<?php require_once '../includes/footer.php'; ?>