<?php
/**
 * includes/topbar.php
 * Required variables before including:
 *   $topbar_title      - string, e.g. "Dashboard"
 *   $topbar_breadcrumb - array, e.g. [['label' => 'Records', 'url' => ''], ['label' => 'Clients', 'url' => '']]
 *   $base_path         - string, path back to root e.g. '../' or '../../'
 *   $role_label        - auto-available from header.php
 */
$topbar_show_clock = true; // show on all pages
$full_name_display = $_SESSION['full_name'] ?? 'User';
$initials_display  = substr(implode('', array_map(fn($w) => strtoupper($w[0]), explode(' ', $full_name_display))), 0, 2);

// Get profile photo for dropdown
$_profile_photo_url = '';
$_first_name_display = '';
$_last_name_display  = '';
if (isset($_SESSION['user_id'], $conn)) {
    $_pq = $conn->prepare("SELECT profile_photo, first_name, last_name FROM users WHERE user_id = ?");
    $_pq->bind_param('i', $_SESSION['user_id']);
    $_pq->execute();
    $_pr = $_pq->get_result()->fetch_assoc();
    if (!empty($_pr['profile_photo'])) {
        $_profile_photo_url = $base_path . 'uploads/avatars/' . $_pr['profile_photo'];
    }
    $_first_name_display = $_pr['first_name'] ?? '';
    $_last_name_display  = $_pr['last_name']  ?? '';
}
?>
<div class="mob-topbar" id="mob-topbar">
  <div class="mob-topbar-title"><?= htmlspecialchars($topbar_title ?? '') ?></div>
  <div class="mob-topbar-avatar" onclick="window.openEditProfileModal && window.openEditProfileModal()" id="mob-topbar-avatar">
    <?php if (!empty($_profile_photo_url)): ?>
    <img src="<?= htmlspecialchars($_profile_photo_url) ?>" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%;"/>
    <?php else: ?>
    <?= $initials_display ?>
    <?php endif; ?>
  </div>
</div>

<div class="topbar">
  <div class="topbar-left">
    <button class="hamburger" id="hamburger-btn" onclick="toggleSidebar()" aria-label="Menu">
      <span></span><span></span><span></span>
    </button>
    <div class="topbar-titles">
      <div class="topbar-title"><?= htmlspecialchars($topbar_title ?? '') ?></div>
      <div class="topbar-breadcrumb">
        TG-BASICS
        <?php foreach ($topbar_breadcrumb ?? [] as $crumb): ?>
          <span>/</span> <?= htmlspecialchars($crumb) ?>
        <?php endforeach; ?>
      </div>
      <?php if (!empty($topbar_show_clock)): ?>
      <div style="display:flex;align-items:center;gap:0.4rem;margin-top:0.1rem;line-height:1;">
        <span id="topbar-time" style="font-size:0.65rem;font-weight:700;color:var(--text-primary);"></span>
        <span style="color:var(--border);font-size:0.6rem;">|</span>
        <span id="topbar-date" style="font-size:0.65rem;color:var(--text-muted);font-weight:500;"></span>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <div class="topbar-right">
    <!-- GLOBAL SEARCH -->
    <div class="gs-wrap" id="gs-wrap">
      <div class="gs-input-row">
        <svg class="gs-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="text" id="gs-input" class="gs-input" placeholder="Search anything…" autocomplete="off" spellcheck="false"/>
        <kbd class="gs-kbd" id="gs-kbd">/</kbd>
      </div>
      <div class="gs-dropdown" id="gs-dropdown" style="display:none;"></div>
    </div>
    <div id="user-dropdown-root"
      data-name="<?= htmlspecialchars($full_name_display) ?>"
      data-firstname="<?= htmlspecialchars($_first_name_display) ?>"
      data-lastname="<?= htmlspecialchars($_last_name_display) ?>"
      data-initials="<?= htmlspecialchars($initials_display) ?>"
      data-role="<?= $role_label ?>"
      data-username="<?= htmlspecialchars($_SESSION['username'] ?? '') ?>"
      data-base="<?= $base_path ?>"
      data-photo="<?= htmlspecialchars($_profile_photo_url) ?>">
    </div>
  </div>
</div>