<?php
/**
 * includes/topbar.php
 * Required variables before including:
 *   $topbar_title      - string, e.g. "Dashboard"
 *   $topbar_breadcrumb - array, e.g. [['label' => 'Records', 'url' => ''], ['label' => 'Clients', 'url' => '']]
 *   $base_path         - string, path back to root e.g. '../' or '../../'
 *   $role_label        - auto-available from header.php
 */
$full_name_display = $_SESSION['full_name'] ?? 'User';
$initials_display  = substr(implode('', array_map(fn($w) => strtoupper($w[0]), explode(' ', $full_name_display))), 0, 2);
?>
<div class="topbar">
  <div class="topbar-left">
    <div class="topbar-titles">
      <div class="topbar-title"><?= htmlspecialchars($topbar_title ?? '') ?></div>
      <div class="topbar-breadcrumb">
        TG-BASICS
        <?php foreach ($topbar_breadcrumb ?? [] as $crumb): ?>
          <span>/</span> <?= htmlspecialchars($crumb) ?>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <div class="topbar-right">
    <div id="user-dropdown-root"
      data-name="<?= htmlspecialchars($full_name_display) ?>"
      data-initials="<?= htmlspecialchars($initials_display) ?>"
      data-role="<?= $role_label ?>"
      data-username="<?= htmlspecialchars($_SESSION['username'] ?? '') ?>"
      data-base="<?= $base_path ?>">
    </div>
  </div>
</div>