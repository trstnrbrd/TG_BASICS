<?php
$page_title = $page_title ?? 'TG-BASICS';
$base_path  = $base_path  ?? '../';
$role_label = match($_SESSION['role'] ?? '') {
    'super_admin' => 'Owner',
    'admin'       => 'Admin',
    'mechanic'    => 'Mechanic',
    default       => 'User'
};

require_once __DIR__ . '/icons.php';

// Load user theme preference
$_user_theme = $_SESSION['theme'] ?? 'light';
if ($_user_theme === 'light' && isset($_SESSION['user_id'], $conn)) {
    $__t = $conn->prepare("SELECT theme FROM users WHERE user_id = ?");
    $__t->bind_param('i', $_SESSION['user_id']);
    $__t->execute();
    $__tr = $__t->get_result()->fetch_assoc();
    if ($__tr) {
        $_user_theme = $__tr['theme'] ?? 'light';
        $_SESSION['theme'] = $_user_theme;
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($_user_theme) ?>">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<meta name="format-detection" content="telephone=no, date=no, email=no, address=no"/>
<meta name="base_path" content="/TG-BASICS/"/>
<title><?= htmlspecialchars($page_title) ?> | TG-BASICS</title>
<link rel="icon" type="image/png" href="<?= $base_path ?>assets/img/tg_logo.png"/>
<link rel="apple-touch-icon" href="<?= $base_path ?>assets/img/tg_logo.png"/>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Big+Shoulders+Text:wght@700;800;900&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="<?= $base_path ?>assets/css/shared/app.css?v=<?= filemtime(__DIR__ . '/../assets/css/shared/app.css') ?>"/>
<link rel="stylesheet" href="<?= $base_path ?>assets/css/shared/mobile_tables.css?v=<?= filemtime(__DIR__ . '/../assets/css/shared/mobile_tables.css') ?>"/>
<?= $extra_css ?? '' ?>
</head>
<body class="<?= $_user_theme === 'light' ? 'light-mode' : '' ?>">
<div id="page-loader"></div>
<script>
(function(){
  var bar = document.getElementById('page-loader');
  var w = 0, tid;

  function advance() {
    if (w < 85) { w += (85 - w) * 0.18 + 1; bar.style.width = w + '%'; }
    tid = setTimeout(advance, 180);
  }
  function start() {
    clearTimeout(tid);
    bar.style.opacity = '1'; w = 0; bar.style.width = '0%';
    setTimeout(function(){ w = 20; bar.style.width = '20%'; advance(); }, 10);
  }
  function complete() {
    clearTimeout(tid);
    bar.style.width = '100%';
    setTimeout(function(){ bar.style.opacity = '0'; }, 220);
  }

  // Expose globally so any page can call window.TGLoader.start() / .done()
  window.TGLoader = { start: start, done: complete };

  // Initial page load
  advance();
  window.addEventListener('load', complete);

  // Link clicks (navigation)
  document.addEventListener('click', function(e){
    var a = e.target.closest('a[href]');
    if (!a) return;
    var href = a.getAttribute('href');
    if (!href || href.startsWith('#') || href.startsWith('javascript') || a.target === '_blank') return;
    if (e.defaultPrevented) return;
    start();
  });

  // Form submits
  document.addEventListener('submit', function(){
    start();
  });
})();
</script>

<div class="sidebar-overlay" id="sidebar-overlay"></div>

<?php
/* ── MOBILE BOTTOM NAV ── */
$_mob_role        = $_SESSION['role'] ?? '';
$_mob_active      = $active_page ?? '';
$_mob_is_admin    = in_array($_mob_role, ['admin', 'super_admin']);
$_mob_is_mechanic = $_mob_role === 'mechanic';

// Determine nav items per role
if ($_mob_is_admin) {
    $_mob_nav = [
        ['id' => 'dashboard', 'label' => 'Home',    'href' => $base_path . 'modules/admin/dashboard_admin.php',    'icon' => 'home'],
        ['id' => 'clients',   'label' => 'Clients', 'href' => $base_path . 'modules/clients/client_list.php',      'icon' => 'users'],
        ['id' => 'repair',    'label' => 'Repairs', 'href' => $base_path . 'modules/repair/repair_list.php',       'icon' => 'wrench'],
        ['id' => 'policy',    'label' => 'Policy',  'href' => $base_path . 'modules/renewal/renewal_list.php',     'icon' => 'shield'],
        ['id' => 'more',      'label' => 'More',    'href' => '#', 'icon' => 'more'],
    ];
    // Policy tab is active for renewal/insurance/claims/billing pages
    $_mob_policy_pages = ['renewal', 'insurance', 'claims', 'billing', 'quotations'];
    if (in_array($_mob_active, $_mob_policy_pages)) $_mob_active = 'policy';
    // More tab active for settings/admin pages
    $_mob_more_pages = ['settings', 'manage_users', 'activity_log', 'monthly_report'];
    if (in_array($_mob_active, $_mob_more_pages)) $_mob_active = 'more';
} else {
    $_mob_nav = [
        ['id' => 'dashboard', 'label' => 'Home',    'href' => $base_path . 'modules/repair/dashboard_mechanic.php', 'icon' => 'home'],
        ['id' => 'clients',   'label' => 'Clients', 'href' => $base_path . 'modules/clients/client_list.php',       'icon' => 'users'],
        ['id' => 'repair',    'label' => 'Repairs', 'href' => $base_path . 'modules/repair/repair_list.php',        'icon' => 'wrench'],
        ['id' => 'more',      'label' => 'More',    'href' => '#', 'icon' => 'more'],
    ];
}

function _mob_icon(string $name): string {
    return match($name) {
        'home'        => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
        'users'       => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        'wrench'      => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>',
        'shield'      => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
        'user-circle' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
        'more'        => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/></svg>',
        default       => '',
    };
}
?>
<nav class="mob-nav" id="mob-nav">
  <div class="mob-nav-inner">
    <?php foreach ($_mob_nav as $_n):
        $_active_class = ($_n['id'] === $_mob_active) ? ' active' : '';
        $_is_btn       = in_array($_n['id'], ['more', 'profile', 'policy']);
    ?>
    <?php if ($_is_btn): ?>
    <button type="button" class="mob-nav-item<?= $_active_class ?>" id="mob-nav-<?= $_n['id'] ?>-btn">
      <?= _mob_icon($_n['icon']) ?>
      <?php if ($_n['id'] === 'policy'): ?>
      <span id="mob-expiry-badge" class="mob-nav-badge"></span>
      <?php endif; ?>
      <span><?= $_n['label'] ?></span>
    </button>
    <?php else: ?>
    <a href="<?= htmlspecialchars($_n['href']) ?>" class="mob-nav-item<?= $_active_class ?>">
      <?= _mob_icon($_n['icon']) ?>
      <span><?= $_n['label'] ?></span>
    </a>
    <?php endif; ?>
    <?php endforeach; ?>
  </div>
</nav>

<!-- ── MOBILE MORE SHEET ── -->
<div class="mob-more-overlay" id="mob-more-overlay"></div>
<div class="mob-more-sheet" id="mob-more-sheet">
  <div class="mob-more-handle"></div>
  <div class="mob-more-header">
    <div class="mob-more-avatar" id="mob-more-avatar">
      <?php
      $_mob_full   = $_SESSION['full_name'] ?? 'User';
      $_mob_inits  = strtoupper(substr(implode('', array_map(fn($w) => $w[0] ?? '', array_filter(explode(' ', $_mob_full)))), 0, 2));
      echo htmlspecialchars($_mob_inits);
      ?>
    </div>
    <div>
      <div class="mob-more-name"><?= htmlspecialchars($_mob_full) ?></div>
      <div class="mob-more-role"><?= $role_label ?></div>
    </div>
  </div>
  <div class="mob-more-grid">
    <button type="button" class="mob-more-item" onclick="mobMoreClose();setTimeout(()=>window.openEditProfileModal&&window.openEditProfileModal(),200)">
      <div class="mob-more-item-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div>
      <span>Profile</span>
    </button>
    <a href="<?= $base_path ?>modules/admin/settings.php" class="mob-more-item" onclick="mobMoreClose()">
      <div class="mob-more-item-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg></div>
      <span>Settings</span>
    </a>
    <?php if ($_mob_is_admin): ?>
    <a href="<?= $base_path ?>modules/admin/monthly_report.php" class="mob-more-item" onclick="mobMoreClose()">
      <div class="mob-more-item-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg></div>
      <span>Reports</span>
    </a>
    <a href="<?= $base_path ?>modules/admin/manage_users.php" class="mob-more-item" onclick="mobMoreClose()">
      <div class="mob-more-item-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
      <span>Users</span>
    </a>
    <a href="<?= $base_path ?>modules/admin/activity_log.php" class="mob-more-item" onclick="mobMoreClose()">
      <div class="mob-more-item-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg></div>
      <span>Logs</span>
    </a>
    <?php endif; ?>
  </div>
  <div class="mob-more-divider"></div>
  <a href="<?= $base_path ?>auth/logout.php" class="mob-more-logout">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
    Logout
  </a>
</div>

<?php if ($_mob_is_admin): ?>
<!-- ── MOBILE POLICY SHEET (Eligibility, Renewal Tracking per company, Claims, Billing) ── -->
<div class="mob-more-overlay" id="mob-policy-overlay"></div>
<div class="mob-more-sheet" id="mob-policy-sheet">
  <div class="mob-more-handle"></div>
  <div class="mob-more-header">
    <div>
      <div class="mob-more-name">Policy &amp; Claims</div>
      <div class="mob-more-role">Insurance, renewals, and claims</div>
    </div>
  </div>
  <div class="mob-more-grid">
    <a href="<?= $base_path ?>modules/insurance/eligibility_check.php" class="mob-more-item" onclick="mobPolicyClose()">
      <div class="mob-more-item-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/></svg></div>
      <span>Eligibility &amp; Policy</span>
    </a>
    <a href="<?= $base_path ?>modules/renewal/renewal_list.php?company=PhilBritish" class="mob-more-item" onclick="mobPolicyClose()">
      <div class="mob-more-item-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/></svg></div>
      <span>PhilBritish</span>
    </a>
    <a href="<?= $base_path ?>modules/renewal/renewal_list.php?company=<?= urlencode('Alpha Insurance & Surety Company Inc.') ?>" class="mob-more-item" onclick="mobPolicyClose()">
      <div class="mob-more-item-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/></svg></div>
      <span>Alpha Insurance</span>
    </a>
    <a href="<?= $base_path ?>modules/claims/claims_list.php" class="mob-more-item" onclick="mobPolicyClose()">
      <div class="mob-more-item-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2"/></svg></div>
      <span>Claims List</span>
    </a>
    <a href="<?= $base_path ?>modules/billing/billing_list.php" class="mob-more-item" onclick="mobPolicyClose()">
      <div class="mob-more-item-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></div>
      <span>Billing</span>
    </a>
  </div>
</div>
<?php endif; ?>

<script src="<?= $base_path ?>assets/js/shared/layout.js?v=<?= filemtime(__DIR__ . '/../assets/js/shared/layout.js') ?>"></script>
