<?php
$active_page = $active_page ?? '';
$base_path   = $base_path   ?? '../';
$role        = $_SESSION['role'] ?? '';
?>

<!-- HAMBURGER BUTTON - visible on mobile only -->
<button class="hamburger" id="hamburger-btn" onclick="toggleSidebar()" aria-label="Toggle menu">
  <span></span><span></span><span></span>
</button>

<aside class="sidebar" id="tg-sidebar">
  <div class="sidebar-logo">
    <div class="logo-row">
      <img src="<?= $base_path ?>assets/img/tg_logo.png" alt="TG" class="logo-img"/>
      <div>
        <div class="logo-name">TG<span>-BASICS</span></div>
        <div class="logo-tagline">Management System</div>
      </div>
    </div>
  </div>

  <nav class="sidebar-nav">

    <div class="nav-group-label">Main</div>
    <a href="<?= $base_path ?>modules/dashboard_admin.php"
       class="nav-item <?= $active_page === 'dashboard' ? 'active' : '' ?>">
      <span class="nav-icon"><?= icon('dashboard', 16) ?></span> Dashboard
    </a>

    <div class="nav-group-label">Records</div>
    <a href="<?= $base_path ?>modules/clients/client_list.php"
       class="nav-item <?= $active_page === 'clients' ? 'active' : '' ?>">
      <span class="nav-icon"><?= icon('users', 16) ?></span> Client Records
    </a>

    <div class="nav-group-label">Insurance</div>
    <a href="<?= $base_path ?>modules/insurance/eligibility_check.php"
       class="nav-item <?= $active_page === 'insurance' ? 'active' : '' ?>">
      <span class="nav-icon"><?= icon('shield-check', 16) ?></span> Eligibility &amp; Policy
    </a>
    <a href="<?= $base_path ?>modules/renewal/renewal_list.php"
       class="nav-item <?= $active_page === 'renewal' ? 'active' : '' ?>">
      <span class="nav-icon"><?= icon('clock', 16) ?></span> Renewal Tracking
      <span class="nav-badge" id="expiry-badge" style="display:none;"></span>
    </a>
    <a href="<?= $base_path ?>modules/claims/claims_list.php"
       class="nav-item <?= $active_page === 'claims' ? 'active' : '' ?>">
      <span class="nav-icon"><?= icon('clipboard-list', 16) ?></span> Claims Tracking
    </a>

    <div class="nav-group-label">Repair Shop</div>
    <a href="<?= $base_path ?>modules/repair/repair_list.php"
       class="nav-item <?= $active_page === 'repair' ? 'active' : '' ?>">
      <span class="nav-icon"><?= icon('wrench', 16) ?></span> Repair Jobs
    </a>
    <a href="<?= $base_path ?>modules/repair/quotation_list.php"
       class="nav-item <?= $active_page === 'quotations' ? 'active' : '' ?>">
      <span class="nav-icon"><?= icon('receipt', 16) ?></span> Quotations &amp; Receipts
    </a>

    <?php if ($role === 'super_admin'): ?>
    <div class="nav-group-label">Administration</div>
    <a href="<?= $base_path ?>modules/manage_users.php"
       class="nav-item <?= $active_page === 'manage_users' ? 'active' : '' ?>">
      <span class="nav-icon"><?= icon('users', 16) ?></span> Manage Users
    </a>
    <?php endif; ?>

    <div class="nav-group-label">System</div>
    <a href="<?= $base_path ?>modules/settings.php"
       class="nav-item <?= $active_page === 'settings' ? 'active' : '' ?>">
      <span class="nav-icon"><?= icon('cog', 16) ?></span> Settings
    </a>
    <a href="<?= $base_path ?>auth/logout.php" class="nav-item" id="logout-nav-btn">
      <span class="nav-icon"><?= icon('lock-closed', 16) ?></span> Logout
    </a>

  </nav>

  <div class="sidebar-footer">TG-BASICS &mdash; Internal Use Only</div>
</aside>

<style>
  .sidebar {
    position: fixed; top: 0; left: 0;
    width: 232px; height: 100vh;
    background: var(--sidebar-bg);
    display: flex; flex-direction: column;
    z-index: 50; overflow-y: auto;
  }

  .sidebar-logo { padding: 1.5rem 1.25rem 1.2rem; border-bottom: 1px solid rgba(255,255,255,0.06); }
  .logo-row { display: flex; align-items: center; gap: 0.6rem; margin-bottom: 0.25rem; }
  .logo-img { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; border: 1.5px solid var(--gold-bright); flex-shrink: 0; }
  .logo-name { font-size: 1rem; font-weight: 800; color: #fff; }
  .logo-name span { color: var(--gold-bright); }
  .logo-tagline { font-size: 0.62rem; color: rgba(200,192,176,0.45); letter-spacing: 1.2px; text-transform: uppercase; }

  .sidebar-nav { padding: 1rem 0; flex: 1; }

  .nav-group-label {
    font-size: 0.58rem; letter-spacing: 2px; text-transform: uppercase;
    color: rgba(200,192,176,0.35); padding: 0.9rem 1.25rem 0.35rem; font-weight: 600;
  }

  .nav-item {
    display: flex; align-items: center; gap: 0.6rem;
    padding: 0.55rem 1.25rem; color: var(--sidebar-text);
    text-decoration: none; font-size: 0.82rem; font-weight: 400;
    transition: all 0.15s; border-left: 2px solid transparent; margin: 0.05rem 0;
  }

  .nav-item:hover { background: rgba(255,255,255,0.05); color: #fff; border-left-color: rgba(212,160,23,0.3); }
  .nav-item.active { background: rgba(212,160,23,0.12); color: var(--sidebar-active); border-left-color: var(--sidebar-active); font-weight: 600; }
  .nav-icon { width: 18px; text-align: center; font-size: 0.9rem; flex-shrink: 0; }

  .nav-badge {
    margin-left: auto; background: rgba(192,57,43,0.18); color: #E74C3C;
    font-size: 0.6rem; font-weight: 700; padding: 0.1rem 0.45rem;
    border-radius: 100px; letter-spacing: 0.3px;
    animation: pulse-badge 2s ease infinite;
  }

  @keyframes pulse-badge { 0%, 100% { opacity: 1; } 50% { opacity: 0.6; } }

  .sidebar-footer { padding: 0.9rem 1.25rem; border-top: 1px solid rgba(255,255,255,0.06); font-size: 0.65rem; color: rgba(200,192,176,0.25); }

  /* Hamburger - fixed position on mobile, hidden on desktop */
  .hamburger {
    display: none;
    position: fixed;
    top: 0.7rem;
    left: 0.75rem;
    z-index: 51;
    flex-direction: column;
    justify-content: center;
    gap: 4px;
    width: 36px; height: 36px;
    background: var(--bg-3);
    border: 1px solid var(--border);
    border-radius: 8px;
    cursor: pointer;
    padding: 8px;
    box-shadow: var(--shadow-md);
    transition: all 0.15s;
  }

  .hamburger:hover { border-color: var(--gold-muted); background: var(--gold-pale); }
  .hamburger span { display: block; width: 100%; height: 2px; background: var(--text-secondary); border-radius: 2px; transition: all 0.2s ease; }
  .hamburger.open span:nth-child(1) { transform: translateY(6px) rotate(45deg); }
  .hamburger.open span:nth-child(2) { opacity: 0; transform: scaleX(0); }
  .hamburger.open span:nth-child(3) { transform: translateY(-6px) rotate(-45deg); }

  @media (max-width: 768px) {
    .hamburger { display: flex; }
    .sidebar {
      transform: translateX(-100%);
      transition: transform 0.25s ease;
      z-index: 50;
    }
    .sidebar.open { transform: translateX(0); }
  }
</style>