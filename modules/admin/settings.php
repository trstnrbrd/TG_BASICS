<?php
require_once __DIR__ . '/../../config/session.php';
require_once '../../config/db.php';
require_once '../../config/validators.php';
require_once '../../config/settings.php';
require_once '../../config/mailer.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin', 'mechanic'])) {
    header("Location: ../../auth/login.php");
    exit;
}

$user_id  = $_SESSION['user_id'];
$role     = $_SESSION['role'];
$is_super = $role === 'super_admin';

// ═══════════════════════════════════════════════════
// AJAX SAVE HANDLERS
// ═══════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['section'])) {
    header('Content-Type: application/json');

    $section    = san_str($_POST['section'] ?? '', 30);
    $admin_only = ['system_settings'];

    if (in_array($section, $admin_only) && !$is_super) {
        echo json_encode(['ok' => false, 'error' => 'Unauthorized.']);
        exit;
    }

    switch ($section) {

        // ── AVATAR UPLOAD ──
        case 'avatar_upload':
            $upload_dir = __DIR__ . '/../../uploads/avatars/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

            if (empty($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['ok' => false, 'error' => 'No file uploaded or upload error.']);
                exit;
            }

            $file = $_FILES['avatar'];
            $allowed = ['image/jpeg', 'image/png', 'image/webp'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (!in_array($mime, $allowed)) {
                echo json_encode(['ok' => false, 'error' => 'Only JPG, PNG, and WebP images are allowed.']);
                exit;
            }
            if ($file['size'] > 2 * 1024 * 1024) {
                echo json_encode(['ok' => false, 'error' => 'Image must be under 2 MB.']);
                exit;
            }

            // Delete old photo if exists
            $old_stmt = $conn->prepare("SELECT profile_photo FROM users WHERE user_id = ?");
            $old_stmt->bind_param('i', $user_id);
            $old_stmt->execute();
            $old_photo = $old_stmt->get_result()->fetch_assoc()['profile_photo'] ?? '';
            if ($old_photo && file_exists($upload_dir . $old_photo)) {
                unlink($upload_dir . $old_photo);
            }

            $ext = match($mime) { 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp' };
            $filename = 'avatar_' . $user_id . '_' . time() . '.' . $ext;
            move_uploaded_file($file['tmp_name'], $upload_dir . $filename);

            $upd = $conn->prepare("UPDATE users SET profile_photo = ? WHERE user_id = ?");
            $upd->bind_param('si', $filename, $user_id);
            $upd->execute();

            echo json_encode(['ok' => true, 'message' => 'Profile photo updated.', 'photo' => $filename]);
            break;

        // ── REMOVE AVATAR ──
        case 'avatar_remove':
            $upload_dir = __DIR__ . '/../../uploads/avatars/';
            $old_stmt = $conn->prepare("SELECT profile_photo FROM users WHERE user_id = ?");
            $old_stmt->bind_param('i', $user_id);
            $old_stmt->execute();
            $old_photo = $old_stmt->get_result()->fetch_assoc()['profile_photo'] ?? '';
            if ($old_photo && file_exists($upload_dir . $old_photo)) {
                unlink($upload_dir . $old_photo);
            }

            $upd = $conn->prepare("UPDATE users SET profile_photo = NULL WHERE user_id = ?");
            $upd->bind_param('i', $user_id);
            $upd->execute();

            echo json_encode(['ok' => true, 'message' => 'Profile photo removed.']);
            break;

        // ── MY ACCOUNT ──
        case 'account':
            $name   = san_str($_POST['full_name'] ?? '', MAX_NAME);
            $email  = san_str($_POST['email'] ?? '', MAX_EMAIL);
            $cur_pw = san_str($_POST['current_password'] ?? '', MAX_PASSWORD);
            $new_pw = san_str($_POST['new_password'] ?? '', MAX_PASSWORD);
            $cfm_pw = san_str($_POST['confirm_password'] ?? '', MAX_PASSWORD);

            if ($name === '') {
                echo json_encode(['ok' => false, 'error' => 'Full name is required.']);
                exit;
            }
            if (!validate_name($name)) {
                echo json_encode(['ok' => false, 'error' => 'Full name contains invalid characters.']);
                exit;
            }

            // Get current user data
            $cur_stmt = $conn->prepare("SELECT email, full_name FROM users WHERE user_id = ?");
            $cur_stmt->bind_param('i', $user_id);
            $cur_stmt->execute();
            $cur_data = $cur_stmt->get_result()->fetch_assoc();
            $old_email = $cur_data['email'] ?? '';

            // Email uniqueness check (against both users and pending verifications)
            if ($email !== '' && $email !== $old_email) {
                $dup = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
                $dup->bind_param('si', $email, $user_id);
                $dup->execute();
                if ($dup->get_result()->num_rows > 0) {
                    echo json_encode(['ok' => false, 'error' => 'Email is already used by another account.']);
                    exit;
                }

                // Send verification email instead of updating directly
                $token = bin2hex(random_bytes(32));

                // Invalidate previous pending verifications
                $inv = $conn->prepare("UPDATE email_verifications SET used = 1 WHERE user_id = ? AND used = 0");
                $inv->bind_param('i', $user_id);
                $inv->execute();

                // Use MySQL NOW() to avoid PHP/MySQL timezone mismatch
                $ins = $conn->prepare("INSERT INTO email_verifications (user_id, new_email, token, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))");
                $ins->bind_param('iss', $user_id, $email, $token);
                $ins->execute();

                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'];
                $verifyLink = $protocol . '://' . $host . '/TG-BASICS/auth/verify_email.php?token=' . $token;

                sendEmailVerificationEmail($email, $name, $verifyLink);

                // Don't update email directly — update name only
                $upd = $conn->prepare("UPDATE users SET full_name = ? WHERE user_id = ?");
                $upd->bind_param('si', $name, $user_id);
                $upd->execute();
                $_SESSION['full_name'] = $name;

                $log  = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'PROFILE_UPDATED', ?)");
                $desc = $name . ' updated their profile. Email verification sent to ' . $email . '.';
                $log->bind_param('is', $user_id, $desc);
                $log->execute();

                $msg = 'Profile updated. A verification email has been sent to ' . htmlspecialchars($email) . '.';
            } else {
                // No email change — update profile normally
                $upd = $conn->prepare("UPDATE users SET full_name = ?, email = ? WHERE user_id = ?");
                $upd->bind_param('ssi', $name, $email, $user_id);
                $upd->execute();
                $_SESSION['full_name'] = $name;

                $log  = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'PROFILE_UPDATED', ?)");
                $desc = $name . ' updated their profile.';
                $log->bind_param('is', $user_id, $desc);
                $log->execute();

                $msg = 'Profile updated successfully.';
            }

            // Password change (only if any password field is filled)
            if ($new_pw !== '' || $cur_pw !== '' || $cfm_pw !== '') {
                if ($cur_pw === '') {
                    echo json_encode(['ok' => false, 'error' => 'Current password is required to change password.']);
                    exit;
                }
                if (!validate_password($new_pw)) {
                    echo json_encode(['ok' => false, 'error' => 'Password must be 8–128 characters and include an uppercase letter, a number, and a special character.']);
                    exit;
                }
                if ($new_pw !== $cfm_pw) {
                    echo json_encode(['ok' => false, 'error' => 'Passwords do not match.']);
                    exit;
                }

                $pw_stmt = $conn->prepare("SELECT password FROM users WHERE user_id = ?");
                $pw_stmt->bind_param('i', $user_id);
                $pw_stmt->execute();
                $pw_row = $pw_stmt->get_result()->fetch_assoc();

                if (!password_verify($cur_pw, $pw_row['password'])) {
                    echo json_encode(['ok' => false, 'error' => 'Current password is incorrect.']);
                    exit;
                }

                $hashed = password_hash($new_pw, PASSWORD_DEFAULT);
                $pw_upd = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
                $pw_upd->bind_param('si', $hashed, $user_id);
                $pw_upd->execute();

                $log  = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'PASSWORD_CHANGED', ?)");
                $desc = $name . ' changed their password.';
                $log->bind_param('is', $user_id, $desc);
                $log->execute();

                // Send password change notification email
                $notify_email = $email ?: $old_email;
                if ($notify_email) {
                    sendPasswordChangeNotification($notify_email, $name);
                }

                $msg = 'Profile and password updated.';
            }

            echo json_encode(['ok' => true, 'message' => $msg]);
            break;

        // ── TOGGLE 2FA ──
        case '2fa_toggle':
            $enabled = (int)($_POST['enabled'] ?? 0);

            // If enabling 2FA, user must have an email
            if ($enabled) {
                $em_chk = $conn->prepare("SELECT email FROM users WHERE user_id = ?");
                $em_chk->bind_param('i', $user_id);
                $em_chk->execute();
                $em_row = $em_chk->get_result()->fetch_assoc();
                if (empty($em_row['email'])) {
                    echo json_encode(['ok' => false, 'error' => 'You must have an email address set before enabling Two-Factor Authentication.']);
                    exit;
                }
            }

            $upd = $conn->prepare("UPDATE users SET two_factor_enabled = ? WHERE user_id = ?");
            $upd->bind_param('ii', $enabled, $user_id);
            $upd->execute();

            $log  = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'SECURITY_UPDATED', ?)");
            $desc = $_SESSION['full_name'] . ($enabled ? ' enabled' : ' disabled') . ' two-factor authentication.';
            $log->bind_param('is', $user_id, $desc);
            $log->execute();

            echo json_encode(['ok' => true, 'message' => '2FA ' . ($enabled ? 'enabled' : 'disabled') . ' successfully.']);
            break;

        // ── DESIGN PREFERENCES ──
        case 'design_prefs':
            $theme = in_array($_POST['theme'] ?? '', ['light', 'dark']) ? $_POST['theme'] : 'light';

            $upd = $conn->prepare("UPDATE users SET theme = ? WHERE user_id = ?");
            $upd->bind_param('si', $theme, $user_id);
            $upd->execute();

            $_SESSION['theme'] = $theme;

            echo json_encode(['ok' => true, 'message' => 'Design preferences saved.']);
            break;

        // ── SYSTEM SETTINGS (Owner only — all in one save) ──
        case 'system_settings':
            // Company
            foreach (['company_name', 'company_address', 'company_contact', 'company_email'] as $k) {
                setSetting($conn, $k, trim($_POST[$k] ?? ''));
            }
            // Email (simplified — no host/port/encryption)
            foreach (['smtp_username', 'smtp_password', 'smtp_sender_name', 'smtp_sender_email', 'claim_notify_email'] as $k) {
                setSetting($conn, $k, trim($_POST[$k] ?? ''));
            }
            // Insurance
            foreach (['eligibility_max_age', 'renewal_urgent_days', 'renewal_expiring_days'] as $k) {
                setSetting($conn, $k, (string)max(1, (int)($_POST[$k] ?? 0)));
            }
            // Security
            foreach (['max_login_attempts', 'lockout_duration', 'activation_link_expiry', 'reset_link_expiry'] as $k) {
                setSetting($conn, $k, (string)max(1, (int)($_POST[$k] ?? 0)));
            }
            // System
            foreach (['timezone', 'date_format'] as $k) {
                setSetting($conn, $k, trim($_POST[$k] ?? ''));
            }

            $log  = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'SETTINGS_UPDATED', ?)");
            $desc = $_SESSION['full_name'] . ' updated system settings.';
            $log->bind_param('is', $user_id, $desc);
            $log->execute();

            echo json_encode(['ok' => true, 'message' => 'All settings saved successfully.']);
            break;

        default:
            echo json_encode(['ok' => false, 'error' => 'Unknown section.']);
    }
    exit;
}

// ═══════════════════════════════════════════════════
// PAGE DATA
// ═══════════════════════════════════════════════════
$full_name = $_SESSION['full_name'];
$initials  = substr(implode('', array_map(fn($w) => strtoupper($w[0]), explode(' ', $full_name))), 0, 2);

// Current user record (include 2FA status, photo, theme)
$u_stmt = $conn->prepare("SELECT full_name, username, email, two_factor_enabled, profile_photo, theme FROM users WHERE user_id = ?");
$u_stmt->bind_param('i', $user_id);
$u_stmt->execute();
$current_user = $u_stmt->get_result()->fetch_assoc();

// Check for pending email verification
$pend_stmt = $conn->prepare("SELECT new_email FROM email_verifications WHERE user_id = ? AND used = 0 AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1");
$pend_stmt->bind_param('i', $user_id);
$pend_stmt->execute();
$pending_email = $pend_stmt->get_result()->fetch_assoc();

// All system settings
$settings = getAllSettings($conn);

$page_title  = 'Settings';
$active_page = 'settings';
$base_path   = '../../';
$extra_css    = '<link rel="stylesheet" href="' . $base_path . 'assets/css/settings.css?v=' . filemtime(__DIR__ . '/../../assets/css/settings.css') . '"/>';
require_once '../../includes/header.php';
require_once '../../includes/navbar.php';
?>

<div class="main">

<?php
$topbar_title      = 'Settings';
$topbar_breadcrumb = ['System', 'Settings'];
require_once '../../includes/topbar.php';
?>

  <div class="content">

    <div class="page-header">
      <div class="page-header-title"><?= icon('cog', 18) ?> System Settings</div>
      <div class="page-header-sub">Configure your profile and system preferences for TG-BASICS.</div>
    </div>

    <?php
    $has_photo   = !empty($current_user['profile_photo']);
    $photo_url   = $has_photo ? $base_path . 'uploads/avatars/' . htmlspecialchars($current_user['profile_photo']) : '';
    $user_theme  = $current_user['theme'] ?? 'light';
    ?>

    <!-- ═══ HORIZONTAL TABS ═══ -->
    <div class="settings-tabs-bar">
      <button class="settings-tab-btn active" data-tab="account">
        <?= icon('user', 16) ?> My Account
      </button>
      <button class="settings-tab-btn" data-tab="design">
        <?= icon('swatch', 16) ?> Design Preferences
      </button>
      <?php if ($is_super): ?>
      <button class="settings-tab-btn" data-tab="system_settings">
        <?= icon('cog', 16) ?> System Settings
        <span class="tab-owner">Owner</span>
      </button>
      <?php endif; ?>
    </div>

    <!-- ═══ PANELS ═══ -->

    <!-- ── MY ACCOUNT ── -->
    <div class="settings-panel active" id="panel-account">

      <!-- Profile Photo -->
      <div class="card" style="margin-bottom:1.5rem;">
        <div class="card-header">
          <div class="card-icon"><?= icon('camera', 16) ?></div>
          <div>
            <div class="card-title">Profile Photo</div>
            <div class="card-sub">Upload a photo to personalize your account</div>
          </div>
        </div>
        <div class="card-body">
          <div class="avatar-upload-area">
            <div class="avatar-preview" id="avatar-preview">
              <?php if ($has_photo): ?>
              <img src="<?= $photo_url ?>" alt="Profile" id="avatar-img"/>
              <?php else: ?>
              <span class="avatar-initials" id="avatar-initials"><?= htmlspecialchars($initials) ?></span>
              <?php endif; ?>
            </div>
            <div class="avatar-actions">
              <div class="avatar-actions-title"><?= htmlspecialchars($current_user['full_name']) ?></div>
              <div class="avatar-actions-hint">JPG, PNG, or WebP. Max 2 MB.</div>
              <div class="avatar-buttons">
                <label class="btn-secondary btn-sm" id="avatar-upload-label">
                  <?= icon('camera', 14) ?> Upload Photo
                  <input type="file" accept="image/jpeg,image/png,image/webp" id="avatar-file-input" hidden/>
                </label>
                <?php if ($has_photo): ?>
                <button type="button" class="btn-outline-danger btn-sm" id="avatar-remove-btn">
                  <?= icon('x-circle', 14) ?> Remove
                </button>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      </div>

      <form class="settings-form" data-section="account">
        <input type="hidden" name="section" value="account"/>

        <div class="card" style="margin-bottom:1.5rem;">
          <div class="card-header">
            <div class="card-icon"><?= icon('user', 16) ?></div>
            <div>
              <div class="card-title">Profile Information</div>
              <div class="card-sub">Update your display name and email address</div>
            </div>
          </div>
          <div class="card-body">
            <div class="form-grid">
              <div class="field">
                <label class="field-label">Full Name <span class="req">*</span></label>
                <input type="text" name="full_name" class="field-input"
                  value="<?= htmlspecialchars($current_user['full_name']) ?>" required/>
              </div>
              <div class="field">
                <label class="field-label">Email Address</label>
                <input type="email" name="email" class="field-input"
                  value="<?= htmlspecialchars($current_user['email'] ?? '') ?>"
                  placeholder="your@email.com"/>
                <?php if ($pending_email): ?>
                <div class="pending-email-badge">
                  <?= icon('clock', 12) ?>
                  Pending verification: <?= htmlspecialchars($pending_email['new_email']) ?>
                </div>
                <?php endif; ?>
              </div>
            </div>
            <div class="info-box" style="margin-top:1rem;">
              <?= icon('information-circle', 14) ?>
              <span>Your username <strong>@<?= htmlspecialchars($current_user['username']) ?></strong> cannot be changed.
              <?php if (!$pending_email): ?>
              Changing your email will require verification.
              <?php endif; ?>
              </span>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="card-header">
            <div class="card-icon"><?= icon('lock-closed', 16) ?></div>
            <div>
              <div class="card-title">Change Password</div>
              <div class="card-sub">Leave blank to keep your current password</div>
            </div>
          </div>
          <div class="card-body">
            <div class="form-grid-3">
              <div class="field">
                <label class="field-label">Current Password</label>
                <input type="password" name="current_password" class="field-input"
                  placeholder="Enter current password"/>
              </div>
              <div class="field">
                <label class="field-label">New Password</label>
                <input type="password" name="new_password" class="field-input"
                  placeholder="Minimum 8 characters"/>
              </div>
              <div class="field">
                <label class="field-label">Confirm Password</label>
                <input type="password" name="confirm_password" class="field-input"
                  placeholder="Re-enter new password"/>
              </div>
            </div>
          </div>
        </div>

        <!-- 2FA Card -->
        <div class="card" style="margin-top:1.5rem;">
          <div class="card-header">
            <div class="card-icon"><?= icon('shield-check', 16) ?></div>
            <div>
              <div class="card-title">Two-Factor Authentication</div>
              <div class="card-sub">Add an extra layer of security to your account</div>
            </div>
          </div>
          <div class="toggle-row">
            <div class="toggle-info">
              <div class="toggle-info-title">Email-Based Verification</div>
              <div class="toggle-info-desc">
                When enabled, a 6-digit code will be sent to your email each time you log in. You must enter this code to complete sign-in.
              </div>
              <div class="toggle-status <?= $current_user['two_factor_enabled'] ? 'on' : 'off' ?>" id="tfa-status">
                <?= icon($current_user['two_factor_enabled'] ? 'check-circle' : 'x-circle', 12) ?>
                <span><?= $current_user['two_factor_enabled'] ? 'Enabled' : 'Disabled' ?></span>
              </div>
            </div>
            <label class="toggle-switch">
              <input type="checkbox" id="tfa-toggle"
                <?= $current_user['two_factor_enabled'] ? 'checked' : '' ?>
                <?= empty($current_user['email']) ? 'disabled title="Set an email address first"' : '' ?>/>
              <span class="toggle-slider"></span>
            </label>
          </div>
          <?php if (empty($current_user['email'])): ?>
          <div style="padding:0 1.75rem 1.25rem;">
            <div class="info-box">
              <?= icon('information-circle', 14) ?>
              <span>You need to set an email address before enabling Two-Factor Authentication.</span>
            </div>
          </div>
          <?php endif; ?>
        </div>

        <!-- Bottom save button -->
        <div class="panel-save-bar">
          <button type="submit" class="btn-primary"><?= icon('check', 14) ?> Save Changes</button>
        </div>
      </form>
    </div>

    <!-- ── DESIGN PREFERENCES ── -->
    <div class="settings-panel" id="panel-design">
      <div class="card">
        <div class="card-header">
          <div class="card-icon"><?= icon('swatch', 16) ?></div>
          <div>
            <div class="card-title">Theme</div>
            <div class="card-sub">Choose your preferred color scheme</div>
          </div>
        </div>
        <div class="card-body">
          <div class="theme-picker">

            <label class="theme-option <?= $user_theme === 'light' ? 'active' : '' ?>">
              <input type="radio" name="theme" value="light" <?= $user_theme === 'light' ? 'checked' : '' ?> hidden/>
              <div class="theme-preview theme-preview-light">
                <div class="tp-sidebar"></div>
                <div class="tp-main">
                  <div class="tp-topbar"></div>
                  <div class="tp-content">
                    <div class="tp-card"></div>
                    <div class="tp-card short"></div>
                  </div>
                </div>
              </div>
              <div class="theme-label">
                <span class="theme-radio"></span>
                <span class="theme-name">Light</span>
              </div>
            </label>

            <label class="theme-option <?= $user_theme === 'dark' ? 'active' : '' ?>">
              <input type="radio" name="theme" value="dark" <?= $user_theme === 'dark' ? 'checked' : '' ?> hidden/>
              <div class="theme-preview theme-preview-dark">
                <div class="tp-sidebar"></div>
                <div class="tp-main">
                  <div class="tp-topbar"></div>
                  <div class="tp-content">
                    <div class="tp-card"></div>
                    <div class="tp-card short"></div>
                  </div>
                </div>
              </div>
              <div class="theme-label">
                <span class="theme-radio"></span>
                <span class="theme-name">Dark</span>
              </div>
            </label>

          </div>
        </div>
      </div>

      <div class="panel-save-bar">
        <button type="button" class="btn-primary" id="save-design-btn"><?= icon('check', 14) ?> Save Changes</button>
      </div>
    </div>

    <?php if ($is_super): ?>

    <!-- ── SYSTEM SETTINGS (Owner) ── -->
    <div class="settings-panel" id="panel-system_settings">
      <form class="settings-form" data-section="system_settings">
        <input type="hidden" name="section" value="system_settings"/>

        <!-- Company Profile -->
        <div class="card" style="margin-bottom:1.5rem;">
          <div class="card-header">
            <div class="card-icon"><?= icon('building-office', 16) ?></div>
            <div>
              <div class="card-title">Company Profile</div>
              <div class="card-sub">Business details shown in emails and system headers</div>
            </div>
          </div>
          <div class="card-body">
            <div class="form-grid">
              <div class="field span-2">
                <label class="field-label">Business Name</label>
                <input type="text" name="company_name" class="field-input"
                  value="<?= htmlspecialchars($settings['company_name']) ?>"/>
              </div>
              <div class="field span-2">
                <label class="field-label">Address</label>
                <input type="text" name="company_address" class="field-input"
                  value="<?= htmlspecialchars($settings['company_address']) ?>"/>
              </div>
              <div class="field">
                <label class="field-label">Contact Number</label>
                <input type="text" name="company_contact" class="field-input"
                  value="<?= htmlspecialchars($settings['company_contact']) ?>"
                  placeholder="e.g. 0917-xxx-xxxx"/>
              </div>
              <div class="field">
                <label class="field-label">Email Address</label>
                <input type="email" name="company_email" class="field-input"
                  value="<?= htmlspecialchars($settings['company_email']) ?>"
                  placeholder="e.g. info@tgbasics.com"/>
              </div>
            </div>
          </div>
        </div>

        <!-- Email Configuration (simplified) -->
        <div class="card" style="margin-bottom:1.5rem;">
          <div class="card-header">
            <div class="card-icon"><?= icon('envelope', 16) ?></div>
            <div>
              <div class="card-title">Email Configuration</div>
              <div class="card-sub">Configure outgoing email credentials and sender identity</div>
            </div>
          </div>
          <div class="card-body">
            <div class="field-section">Email Credentials</div>
            <div class="form-grid">
              <div class="field">
                <label class="field-label">Email Username</label>
                <input type="text" name="smtp_username" class="field-input"
                  value="<?= htmlspecialchars($settings['smtp_username']) ?>"
                  placeholder="your-email@gmail.com"/>
                <span class="field-hint">The email account used to send system emails (e.g. Gmail address).</span>
              </div>
              <div class="field">
                <label class="field-label">Email App Password</label>
                <input type="password" name="smtp_password" class="field-input"
                  value="<?= htmlspecialchars($settings['smtp_password']) ?>"/>
                <span class="field-hint">App-specific password from your email provider.</span>
              </div>
            </div>
            <div class="field-section">Sender Identity</div>
            <div class="form-grid">
              <div class="field">
                <label class="field-label">Sender Name</label>
                <input type="text" name="smtp_sender_name" class="field-input"
                  value="<?= htmlspecialchars($settings['smtp_sender_name']) ?>"/>
                <span class="field-hint">Name shown in the "From" field of outgoing emails.</span>
              </div>
              <div class="field">
                <label class="field-label">Sender Email</label>
                <input type="email" name="smtp_sender_email" class="field-input"
                  value="<?= htmlspecialchars($settings['smtp_sender_email']) ?>"/>
                <span class="field-hint">Email address shown in the "From" field.</span>
              </div>
              <div class="field">
                <label class="field-label">Claims Notify Email</label>
                <input type="email" name="claim_notify_email" class="field-input"
                  value="<?= htmlspecialchars($settings['claim_notify_email'] ?? '') ?>"/>
                <span class="field-hint">Email where claim requirements updates are sent (admin / Jean Paolo).</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Insurance Settings -->
        <div class="card" style="margin-bottom:1.5rem;">
          <div class="card-header">
            <div class="card-icon"><?= icon('shield-check', 16) ?></div>
            <div>
              <div class="card-title">Insurance Settings</div>
              <div class="card-sub">Configure eligibility rules and renewal thresholds</div>
            </div>
          </div>
          <div class="card-body">
            <div class="form-grid-3">
              <div class="field">
                <label class="field-label">Max Vehicle Age (years)</label>
                <input type="number" name="eligibility_max_age" class="field-input"
                  value="<?= htmlspecialchars($settings['eligibility_max_age']) ?>" min="1" max="50"/>
                <span class="field-hint">Vehicles older than this are not eligible.</span>
              </div>
              <div class="field">
                <label class="field-label">Urgent Threshold (days)</label>
                <input type="number" name="renewal_urgent_days" class="field-input"
                  value="<?= htmlspecialchars($settings['renewal_urgent_days']) ?>" min="1" max="365"/>
                <span class="field-hint">Policies within this range marked "Urgent".</span>
              </div>
              <div class="field">
                <label class="field-label">Expiring Threshold (days)</label>
                <input type="number" name="renewal_expiring_days" class="field-input"
                  value="<?= htmlspecialchars($settings['renewal_expiring_days']) ?>" min="1" max="365"/>
                <span class="field-hint">Policies within this range marked "Expiring".</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Security Settings -->
        <div class="card" style="margin-bottom:1.5rem;">
          <div class="card-header">
            <div class="card-icon"><?= icon('lock-closed', 16) ?></div>
            <div>
              <div class="card-title">Security Settings</div>
              <div class="card-sub">Configure login security and link expiration</div>
            </div>
          </div>
          <div class="card-body">
            <div class="field-section">Login Protection</div>
            <div class="form-grid">
              <div class="field">
                <label class="field-label">Max Login Attempts</label>
                <input type="number" name="max_login_attempts" class="field-input"
                  value="<?= htmlspecialchars($settings['max_login_attempts']) ?>" min="1" max="20"/>
                <span class="field-hint">Account locks after this many failed attempts.</span>
              </div>
              <div class="field">
                <label class="field-label">Lockout Duration (minutes)</label>
                <input type="number" name="lockout_duration" class="field-input"
                  value="<?= htmlspecialchars($settings['lockout_duration']) ?>" min="1" max="120"/>
                <span class="field-hint">How long the account stays locked.</span>
              </div>
            </div>
            <div class="field-section">Link Expiration</div>
            <div class="form-grid">
              <div class="field">
                <label class="field-label">Activation Link Expiry (hours)</label>
                <input type="number" name="activation_link_expiry" class="field-input"
                  value="<?= htmlspecialchars($settings['activation_link_expiry']) ?>" min="1" max="168"/>
                <span class="field-hint">How long account activation links remain valid.</span>
              </div>
              <div class="field">
                <label class="field-label">Reset Link Expiry (hours)</label>
                <input type="number" name="reset_link_expiry" class="field-input"
                  value="<?= htmlspecialchars($settings['reset_link_expiry']) ?>" min="1" max="24"/>
                <span class="field-hint">How long password reset links remain valid.</span>
              </div>
            </div>
          </div>
        </div>

        <!-- System Preferences -->
        <div class="card">
          <div class="card-header">
            <div class="card-icon"><?= icon('cog', 16) ?></div>
            <div>
              <div class="card-title">System Preferences</div>
              <div class="card-sub">Timezone and display format settings</div>
            </div>
          </div>
          <div class="card-body">
            <div class="form-grid">
              <div class="field">
                <label class="field-label">Timezone</label>
                <select name="timezone" class="field-select">
                  <?php
                  $timezones = [
                      'Asia/Manila', 'Asia/Singapore', 'Asia/Tokyo', 'Asia/Hong_Kong',
                      'Asia/Shanghai', 'Asia/Kolkata', 'Australia/Sydney',
                      'UTC', 'US/Eastern', 'US/Pacific', 'Europe/London',
                  ];
                  foreach ($timezones as $tz): ?>
                  <option value="<?= $tz ?>" <?= $settings['timezone'] === $tz ? 'selected' : '' ?>><?= $tz ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field">
                <label class="field-label">Date Format</label>
                <select name="date_format" class="field-select">
                  <?php
                  $formats = [
                      'M d, Y'  => 'Mar 26, 2026',
                      'F j, Y'  => 'March 26, 2026',
                      'd M Y'   => '26 Mar 2026',
                      'm/d/Y'   => '03/26/2026',
                      'd/m/Y'   => '26/03/2026',
                      'Y-m-d'   => '2026-03-26',
                  ];
                  foreach ($formats as $fmt => $example): ?>
                  <option value="<?= $fmt ?>" <?= $settings['date_format'] === $fmt ? 'selected' : '' ?>><?= $example ?> (<?= $fmt ?>)</option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          </div>
        </div>

        <!-- Bottom save button -->
        <div class="panel-save-bar">
          <button type="submit" class="btn-primary"><?= icon('check', 14) ?> Save All Settings</button>
        </div>
      </form>
    </div>

    <?php endif; ?>

  </div>
</div>

<?php
$footer_scripts = ''; // JS moved to assets/js/shared/settings.js
/* REMOVED HEREDOC START
// ── Tab switching ──
document.querySelectorAll('.settings-tab-btn').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.settings-tab-btn').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.settings-panel').forEach(p => p.classList.remove('active'));
        tab.classList.add('active');
        const panel = document.getElementById('panel-' + tab.dataset.tab);
        if (panel) panel.classList.add('active');
    });
});

// ── AJAX form save ──
document.querySelectorAll('.settings-form').forEach(form => {
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = form.querySelector('button[type="submit"]');
        const originalHTML = btn.innerHTML;
        btn.disabled = true;
        btn.style.opacity = '0.6';
        btn.textContent = 'Saving...';

        try {
            const res = await fetch('settings.php', {
                method: 'POST',
                body: new FormData(form)
            });
            const data = await res.json();
            if (data.ok) {
                Swal.fire({ toast:true, position:'top-end', icon:'success', title:'Saved!', text:data.message, showConfirmButton:false, timer:3000, timerProgressBar:true });
                if (form.querySelector('[name="section"]').value === 'account') {
                    form.querySelectorAll('input[type="password"]').forEach(p => p.value = '');
                }
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: data.error, confirmButtonColor: '#B8860B' });
            }
        } catch (err) {
            Swal.fire({ icon: 'error', title: 'Error', text: 'Something went wrong. Please try again.', confirmButtonColor: '#B8860B' });
        }

        btn.disabled = false;
        btn.style.opacity = '';
        btn.innerHTML = originalHTML;
    });
});

// ── Avatar Upload ──
const avatarInput = document.getElementById('avatar-file-input');
if (avatarInput) {
    avatarInput.addEventListener('change', async function() {
        if (!this.files.length) return;
        const fd = new FormData();
        fd.append('section', 'avatar_upload');
        fd.append('avatar', this.files[0]);

        try {
            const res = await fetch('settings.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.ok) {
                Swal.fire({ toast:true, position:'top-end', icon:'success', title:'Saved!', text:data.message, showConfirmButton:false, timer:3000, timerProgressBar:true });
                setTimeout(() => location.reload(), 600);
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: data.error, confirmButtonColor: '#B8860B' });
            }
        } catch (err) {
            Swal.fire({ icon: 'error', title: 'Error', text: 'Upload failed. Please try again.', confirmButtonColor: '#B8860B' });
        }
        this.value = '';
    });
}

// ── Avatar Remove ──
const avatarRemoveBtn = document.getElementById('avatar-remove-btn');
if (avatarRemoveBtn) {
    avatarRemoveBtn.addEventListener('click', async function() {
        const fd = new FormData();
        fd.append('section', 'avatar_remove');

        try {
            const res = await fetch('settings.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.ok) {
                Swal.fire({ toast:true, position:'top-end', icon:'success', title:'Saved!', text:data.message, showConfirmButton:false, timer:3000, timerProgressBar:true });
                setTimeout(() => location.reload(), 600);
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: data.error, confirmButtonColor: '#B8860B' });
            }
        } catch (err) {
            Swal.fire({ icon: 'error', title: 'Error', text: 'Something went wrong.', confirmButtonColor: '#B8860B' });
        }
    });
}

// ── Theme Picker ──
document.querySelectorAll('.theme-option').forEach(opt => {
    opt.addEventListener('click', () => {
        document.querySelectorAll('.theme-option').forEach(o => o.classList.remove('active'));
        opt.classList.add('active');
        opt.querySelector('input[type="radio"]').checked = true;
    });
});

// ── Save Design Preferences ──
const saveDesignBtn = document.getElementById('save-design-btn');
if (saveDesignBtn) {
    saveDesignBtn.addEventListener('click', async function() {
        const theme = document.querySelector('input[name="theme"]:checked')?.value || 'light';
        const originalHTML = this.innerHTML;
        this.disabled = true;
        this.style.opacity = '0.6';
        this.textContent = 'Saving...';

        const fd = new FormData();
        fd.append('section', 'design_prefs');
        fd.append('theme', theme);

        try {
            const res = await fetch('settings.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.ok) {
                Swal.fire({ toast:true, position:'top-end', icon:'success', title:'Saved!', text:data.message, showConfirmButton:false, timer:3000, timerProgressBar:true });
                document.documentElement.setAttribute('data-theme', theme);
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: data.error, confirmButtonColor: '#B8860B' });
            }
        } catch (err) {
            Swal.fire({ icon: 'error', title: 'Error', text: 'Something went wrong.', confirmButtonColor: '#B8860B' });
        }

        this.disabled = false;
        this.style.opacity = '';
        this.innerHTML = originalHTML;
    });
}

// ── 2FA Toggle ──
const tfaToggle = document.getElementById('tfa-toggle');
if (tfaToggle) {
    tfaToggle.addEventListener('change', async function() {
        const enabled = this.checked ? 1 : 0;
        const fd = new FormData();
        fd.append('section', '2fa_toggle');
        fd.append('enabled', enabled);

        try {
            const res = await fetch('settings.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.ok) {
                Swal.fire({ toast:true, position:'top-end', icon:'success', title:'Saved!', text:data.message, showConfirmButton:false, timer:3000, timerProgressBar:true });
                const status = document.getElementById('tfa-status');
                if (status) {
                    status.className = 'toggle-status ' + (enabled ? 'on' : 'off');
                    status.innerHTML = (enabled
                        ? '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg><span>Enabled</span>'
                        : '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg><span>Disabled</span>'
                    );
                }
            } else {
                this.checked = !this.checked;
                Swal.fire({ icon: 'error', title: 'Error', text: data.error, confirmButtonColor: '#B8860B' });
            }
        } catch (err) {
            this.checked = !this.checked;
            Swal.fire({ icon: 'error', title: 'Error', text: 'Something went wrong.', confirmButtonColor: '#B8860B' });
        }
    });
}
REMOVED HEREDOC END */

echo '<script src="../../assets/js/shared/settings.js"></script>';

require_once '../../includes/footer.php';
?>
