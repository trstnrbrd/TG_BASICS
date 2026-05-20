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
<style>
  /* ── SIDEBAR ── */
  .sidebar {
    position: fixed; top: 0; left: 0; bottom: 0;
    width: var(--sidebar-width);
    background: var(--sidebar-bg);
    display: flex; flex-direction: column;
    z-index: 50;
    transition: transform 0.25s ease;
    overflow: visible;
  }

  /* ── LOGO ── */
  .sidebar-logo {
    display: flex; align-items: center; gap: 0.55rem;
    padding: 1.1rem 1.1rem 1rem;
    text-decoration: none;
    border-bottom: 1px solid rgba(255,255,255,0.06);
    flex-shrink: 0;
  }
  .sidebar-logo-imgs { display: flex; align-items: center; gap: 0.35rem; }
  .sidebar-logo-img  { width: 28px; height: 28px; border-radius: 50%; object-fit: cover; border: 1.5px solid var(--gold-bright); flex-shrink: 0; }
  .sidebar-logo-img.no-ring { border: none; border-radius: 0; width: auto; height: 22px; object-fit: contain; }
  .sidebar-logo-sep  { width: 1px; height: 18px; background: rgba(212,160,23,0.3); }
  .sidebar-logo-name { font-size: 0.88rem; font-weight: 800; color: #fff; line-height: 1; }
  .sidebar-logo-name span { color: var(--gold-bright); }

  /* ── NAV LIST ── */
  .sidebar-nav {
    flex: 1;
    padding: 0.75rem 0;
    overflow: visible;
  }

  /* ── NAV ITEM WRAPPER (for flyout) ── */
  .nav-item-wrap {
    position: relative;
  }

  /* ── NAV ITEM ── */
  .nav-item {
    display: flex; align-items: center; gap: 0.65rem;
    padding: 0.7rem 1.1rem;
    color: var(--sidebar-text);
    text-decoration: none; font-size: 0.82rem; font-weight: 500;
    transition: all 0.12s; border-left: 3px solid transparent;
    white-space: nowrap; cursor: pointer;
    position: relative;
  }
  .nav-item:hover {
    background: rgba(255,255,255,0.05);
    color: #fff;
    border-left-color: rgba(212,160,23,0.4);
  }
  .nav-item.active {
    background: rgba(212,160,23,0.12);
    color: var(--sidebar-active);
    border-left-color: var(--sidebar-active);
    font-weight: 600;
  }
  .nav-item svg { flex-shrink: 0; opacity: 0.7; }
  .nav-item.active svg, .nav-item:hover svg { opacity: 1; }

  /* chevron */
  .nav-item-chevron {
    margin-left: auto;
    opacity: 0.35;
    transition: opacity 0.15s;
    flex-shrink: 0;
  }
  .nav-item-wrap:hover .nav-item-chevron { opacity: 0.8; }

  /* ── FLYOUT ── */
  .nav-flyout {
    display: none;
    position: absolute;
    top: 0; left: 100%;
    min-width: 200px;
    background: var(--bg-3);
    border: 1px solid var(--border);
    border-radius: 0 10px 10px 10px;
    box-shadow: var(--shadow-lg);
    padding: 0.35rem 0;
    z-index: 200;
    animation: flyout-in 0.15s ease;
  }
  @keyframes flyout-in {
    from { opacity: 0; transform: translateX(-6px); }
    to   { opacity: 1; transform: translateX(0); }
  }
  .nav-item-wrap:hover .nav-flyout { display: block; }

  .nav-flyout-item {
    display: flex; align-items: center; gap: 0.55rem;
    padding: 0.6rem 1rem;
    font-size: 0.8rem; font-weight: 500;
    color: var(--text-secondary);
    text-decoration: none;
    transition: background 0.1s, color 0.1s;
    white-space: nowrap;
  }
  .nav-flyout-item:hover { background: var(--gold-pale); color: var(--gold); }
  .nav-flyout-item.active { color: var(--gold); font-weight: 700; background: var(--gold-pale); }
  .nav-flyout-item svg { flex-shrink: 0; opacity: 0.65; }
  .nav-flyout-item.active svg, .nav-flyout-item:hover svg { opacity: 1; }

  /* ── EXPIRY BADGE ── */
  .nav-badge {
    background: rgba(192,57,43,0.18); color: #E74C3C;
    font-size: 0.58rem; font-weight: 700; padding: 0.1rem 0.4rem;
    border-radius: 100px; letter-spacing: 0.3px; margin-left: auto;
    animation: pulse-badge 2s ease infinite;
  }
  @keyframes pulse-badge { 0%,100% { opacity:1; } 50% { opacity:0.6; } }

  /* ── HIGH CONTRAST overrides ── */
  [data-theme="high-contrast"] .sidebar { background: #fff; border-right: 1px solid #000; }
  [data-theme="high-contrast"] .sidebar-logo { border-bottom-color: rgba(0,0,0,0.1); }
  [data-theme="high-contrast"] .sidebar-logo-name { color: #000; }
  [data-theme="high-contrast"] .sidebar-logo-name span { color: #000; }
  [data-theme="high-contrast"] .sidebar-logo-img { border-color: #000; }
  [data-theme="high-contrast"] .sidebar-logo-sep { background: rgba(0,0,0,0.2); }
  [data-theme="high-contrast"] .nav-item { color: #333; }
  [data-theme="high-contrast"] .nav-item:hover { background: rgba(0,0,0,0.05); color: #000; border-left-color: #000; }
  [data-theme="high-contrast"] .nav-item.active { background: rgba(0,0,0,0.08); color: #000; border-left-color: #000; }
  [data-theme="high-contrast"] .nav-flyout { background: #fff; border-color: #000; }
  [data-theme="high-contrast"] .nav-flyout-item { color: #333; }
  [data-theme="high-contrast"] .nav-flyout-item:hover { background: #f0f0f0; color: #000; }
  [data-theme="high-contrast"] .nav-flyout-item.active { background: #e8e8e8; color: #000; }

  /* ── MOBILE ── */
  @media (max-width: 768px) {
    .sidebar { transform: translateX(-100%); overflow: hidden; }
    .sidebar.open { transform: translateX(0); box-shadow: 4px 0 24px rgba(0,0,0,0.4); overflow-y: auto; }
    /* On mobile show flyout items inline instead */
    .nav-flyout { display: none !important; }
    .nav-item-chevron { display: none; }
  }
</style>

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

    <!-- Renewals -->
    <div class="nav-item-wrap">
      <a href="<?= $base_path ?>modules/renewal/renewal_list.php"
         class="nav-item <?= $active_group === 'renewals' ? 'active' : '' ?>">
        <?= icon('clock', 16) ?> Renewals
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
