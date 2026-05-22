<?php
$active_page = $active_page ?? '';
$base_path   = $base_path   ?? '../';
$role        = $_SESSION['role'] ?? '';

$active_group = match($active_page) {
    'clients'                      => 'clients',
    'insurance', 'renewal'         => 'renewals',
    'claims', 'billing'            => 'claims',
    'repair', 'quotations'         => 'repairs',
    'manage_users', 'activity_log' => 'admin',
    default                        => ''
};

$dash_url = $role === 'mechanic'
    ? $base_path . 'modules/repair/dashboard_mechanic.php'
    : $base_path . 'modules/admin/dashboard_admin.php';
?>
<link rel="stylesheet" href="<?= $base_path ?>assets/css/sidebar.css"/>

<aside class="sidebar" id="tg-sidebar">

  <a href="<?= $dash_url ?>" class="sidebar-logo" title="Go to Dashboard">
    <div class="sidebar-logo-imgs">
      <img src="<?= $base_path ?>assets/img/tg_logo.png" alt="TG" class="sidebar-logo-img"/>
      <div class="sidebar-logo-sep"></div>
      <img src="<?= $base_path ?>assets/img/LogoBasicCar.png" alt="BASICS" class="sidebar-logo-img no-ring"/>
    </div>
    <div class="sidebar-logo-name">TG<span>-BASICS</span></div>
  </a>

  <nav class="sidebar-nav">

    <?php
    $chevron = '<svg class="nav-item-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="9 18 15 12 9 6"/></svg>';
    ?>

    <!-- Client Records (no flyout — single page) -->
    <div class="nav-item-wrap">
      <a href="<?= $base_path ?>modules/clients/client_list.php"
         class="nav-item <?= $active_group === 'clients' ? 'active' : '' ?>">
        <?= icon('users', 16) ?> Client Records
      </a>
    </div>

    <?php if ($role !== 'mechanic'): ?>

    <!-- Policy -->
    <div class="nav-item-wrap">
      <a href="<?= $base_path ?>modules/renewal/renewal_list.php"
         class="nav-item <?= $active_group === 'renewals' ? 'active' : '' ?>">
        <?= icon('shield-check', 16) ?> Policy
        <span class="nav-badge" id="expiry-badge" style="display:none;"></span>
        <?= $chevron ?>
      </a>
      <div class="nav-flyout">
        <a href="<?= $base_path ?>modules/renewal/renewal_list.php"
           class="nav-flyout-item <?= $active_page === 'renewal' ? 'active' : '' ?>">
          <?= icon('clock', 13) ?> Renewal Tracking
        </a>
        <a href="<?= $base_path ?>modules/insurance/eligibility_check.php"
           class="nav-flyout-item <?= $active_page === 'insurance' ? 'active' : '' ?>">
          <?= icon('shield-check', 13) ?> Eligibility &amp; Policy
        </a>
      </div>
    </div>

    <!-- Claims -->
    <div class="nav-item-wrap">
      <a href="<?= $base_path ?>modules/claims/claims_list.php"
         class="nav-item <?= $active_group === 'claims' ? 'active' : '' ?>">
        <?= icon('clipboard-list', 16) ?> Claims
        <?= $chevron ?>
      </a>
      <div class="nav-flyout">
        <a href="<?= $base_path ?>modules/claims/claims_list.php"
           class="nav-flyout-item <?= $active_page === 'claims' ? 'active' : '' ?>">
          <?= icon('clipboard-list', 13) ?> Claims List
        </a>
        <a href="<?= $base_path ?>modules/billing/billing_list.php"
           class="nav-flyout-item <?= $active_page === 'billing' ? 'active' : '' ?>">
          <?= icon('document-text', 13) ?> Billing
        </a>
      </div>
    </div>

    <?php endif; ?>

    <!-- Repairs -->
    <div class="nav-item-wrap">
      <a href="<?= $base_path ?>modules/repair/repair_list.php"
         class="nav-item <?= $active_group === 'repairs' ? 'active' : '' ?>">
        <?= icon('wrench', 16) ?> Repairs
        <?= $chevron ?>
      </a>
      <div class="nav-flyout">
        <a href="<?= $base_path ?>modules/repair/repair_list.php"
           class="nav-flyout-item <?= $active_page === 'repair' ? 'active' : '' ?>">
          <?= icon('wrench', 13) ?> Repair Jobs
        </a>
        <a href="<?= $base_path ?>modules/quotations/quotation_list.php"
           class="nav-flyout-item <?= $active_page === 'quotations' ? 'active' : '' ?>">
          <?= icon('receipt', 13) ?> Quotations &amp; Receipts
        </a>
      </div>
    </div>

    <?php if ($role === 'super_admin'): ?>
    <!-- User Management -->
    <div class="nav-item-wrap">
      <a href="<?= $base_path ?>modules/admin/manage_users.php"
         class="nav-item <?= $active_group === 'admin' ? 'active' : '' ?>">
        <?= icon('user-cog', 16) ?> User Management
        <?= $chevron ?>
      </a>
      <div class="nav-flyout">
        <a href="<?= $base_path ?>modules/admin/manage_users.php"
           class="nav-flyout-item <?= $active_page === 'manage_users' ? 'active' : '' ?>">
          <?= icon('users', 13) ?> Manage Users
        </a>
        <a href="<?= $base_path ?>modules/admin/activity_log.php"
           class="nav-flyout-item <?= $active_page === 'activity_log' ? 'active' : '' ?>">
          <?= icon('clipboard-list', 13) ?> Activity Log
        </a>
      </div>
    </div>
    <?php endif; ?>

  </nav>

</aside>
