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

    // CSRF check for all POST handlers
    $submitted_token = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $submitted_token)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid or missing CSRF token.']);
        exit;
    }

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
            $first  = san_str($_POST['first_name'] ?? '', MAX_NAME);
            $last   = san_str($_POST['last_name']  ?? '', MAX_NAME);
            $name   = trim($first . ' ' . $last);
            // Hidden accounts keep the masked label in session/audit trail even after a
            // profile name edit — only their real first_name/last_name columns change.
            $display_name = !empty($_SESSION['is_hidden']) ? 'System Administrator' : $name;
            $email  = san_str($_POST['email'] ?? '', MAX_EMAIL);
            $cur_pw = san_str($_POST['current_password'] ?? '', MAX_PASSWORD);
            $new_pw = san_str($_POST['new_password'] ?? '', MAX_PASSWORD);
            $cfm_pw = san_str($_POST['confirm_password'] ?? '', MAX_PASSWORD);

            if ($first === '') {
                echo json_encode(['ok' => false, 'error' => 'First name is required.']);
                exit;
            }
            if ($last === '') {
                echo json_encode(['ok' => false, 'error' => 'Last name is required.']);
                exit;
            }
            if (!validate_name($first) || !validate_name($last)) {
                echo json_encode(['ok' => false, 'error' => 'Name contains invalid characters.']);
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
                $upd = $conn->prepare("UPDATE users SET first_name = ?, last_name = ? WHERE user_id = ?");
                $upd->bind_param('ssi', $first, $last, $user_id);
                $upd->execute();
                $_SESSION['full_name'] = $display_name;

                $log  = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'PROFILE_UPDATED', ?)");
                $desc = $display_name . ' updated their profile. Email verification sent to ' . $email . '.';
                $log->bind_param('is', $user_id, $desc);
                $log->execute();

                $msg = 'Profile updated. A verification email has been sent to ' . htmlspecialchars($email) . '.';
            } else {
                // No email change — update profile normally
                $upd = $conn->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ? WHERE user_id = ?");
                $upd->bind_param('sssi', $first, $last, $email, $user_id);
                $upd->execute();
                $_SESSION['full_name'] = $display_name;

                $log  = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'PROFILE_UPDATED', ?)");
                $desc = $display_name . ' updated their profile.';
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

        // ── CHANGE USERNAME ──
        case 'username':
            $new_username = san_str($_POST['new_username'] ?? '', 50);
            $cur_pw       = san_str($_POST['current_password'] ?? '', MAX_PASSWORD);

            if ($new_username === '') {
                echo json_encode(['ok' => false, 'error' => 'Username cannot be empty.']);
                exit;
            }
            if (!preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $new_username)) {
                echo json_encode(['ok' => false, 'error' => 'Username may only contain letters, numbers, dots, underscores, and hyphens (3–50 characters).']);
                exit;
            }
            if ($cur_pw === '') {
                echo json_encode(['ok' => false, 'error' => 'Current password is required to change your username.']);
                exit;
            }

            // Verify password + check cooldown
            $pw_stmt = $conn->prepare("SELECT password, username, username_changed_at FROM users WHERE user_id = ?");
            $pw_stmt->bind_param('i', $user_id);
            $pw_stmt->execute();
            $pw_row = $pw_stmt->get_result()->fetch_assoc();

            if ($pw_row['username_changed_at']) {
                $days_since = (int)floor((time() - strtotime($pw_row['username_changed_at'])) / 86400);
                $days_left  = 60 - $days_since;
                if ($days_left > 0) {
                    echo json_encode(['ok' => false, 'error' => 'You can only change your username once every 60 days. ' . $days_left . ' day' . ($days_left !== 1 ? 's' : '') . ' remaining.']);
                    exit;
                }
            }

            if (!password_verify($cur_pw, $pw_row['password'])) {
                echo json_encode(['ok' => false, 'error' => 'Current password is incorrect.']);
                exit;
            }
            if ($new_username === $pw_row['username']) {
                echo json_encode(['ok' => false, 'error' => 'New username is the same as your current one.']);
                exit;
            }

            // Check uniqueness
            $dup = $conn->prepare("SELECT user_id FROM users WHERE username = ? AND user_id != ?");
            $dup->bind_param('si', $new_username, $user_id);
            $dup->execute();
            if ($dup->get_result()->num_rows > 0) {
                echo json_encode(['ok' => false, 'error' => 'That username is already taken.']);
                exit;
            }

            $now = date('Y-m-d H:i:s');
            $upd = $conn->prepare("UPDATE users SET username = ?, username_changed_at = ? WHERE user_id = ?");
            $upd->bind_param('ssi', $new_username, $now, $user_id);
            $upd->execute();

            $_SESSION['username'] = $new_username;

            $log  = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'PROFILE_UPDATED', ?)");
            $desc = ($_SESSION['full_name'] ?? '') . ' changed their username to @' . $new_username . '.';
            $log->bind_param('is', $user_id, $desc);
            $log->execute();

            echo json_encode(['ok' => true, 'message' => 'Username updated to @' . $new_username . '.', 'new_username' => $new_username]);
            exit;

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

        // ── TOTP GENERATE ──
        case 'totp_generate':
            require_once '../../config/totp.php';
            $secret = totp_generate_secret();
            $_SESSION['totp_pending_secret'] = $secret;
            $label  = $_SESSION['username'] ?? 'user';
            echo json_encode(['ok' => true, 'secret' => $secret, 'uri' => totp_qr_uri($secret, $label)]);
            exit;

        // ── TOTP CONFIRM ──
        case 'totp_confirm':
            require_once '../../config/totp.php';
            $code     = preg_replace('/\D/', '', $_POST['code'] ?? '');
            $password = $_POST['password'] ?? '';
            $secret   = $_SESSION['totp_pending_secret'] ?? '';
            if (!$secret) { echo json_encode(['ok' => false, 'error' => 'No pending setup. Please start again.']); exit; }
            if (!totp_verify($secret, $code)) { echo json_encode(['ok' => false, 'error' => 'Incorrect code. Make sure your authenticator app time is in sync.']); exit; }
            // Verify password
            $pw_row = $conn->prepare("SELECT password FROM users WHERE user_id = ?");
            $pw_row->bind_param('i', $user_id); $pw_row->execute();
            $pw_hash = $pw_row->get_result()->fetch_assoc()['password'] ?? '';
            if (!password_verify($password, $pw_hash)) { echo json_encode(['ok' => false, 'error' => 'Incorrect password.']); exit; }
            $plain_codes  = totp_generate_recovery_codes(8);
            $hashed_codes = array_map(fn($c) => password_hash($c, PASSWORD_DEFAULT), $plain_codes);
            $encoded = json_encode($hashed_codes);
            $u = $conn->prepare("UPDATE users SET totp_secret = ?, totp_enabled = 1, totp_recovery_codes = ?, two_factor_enabled = 0 WHERE user_id = ?");
            $u->bind_param('ssi', $secret, $encoded, $user_id); $u->execute();
            unset($_SESSION['totp_pending_secret']);
            $log  = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'TOTP_ENABLED', ?)");
            $desc = ($_SESSION['full_name'] ?? '') . ' enabled authenticator app 2FA.';
            $log->bind_param('is', $user_id, $desc); $log->execute();
            echo json_encode(['ok' => true, 'recovery_codes' => $plain_codes]);
            exit;

        // ── TOTP DISABLE ──
        case 'totp_disable':
            $password = $_POST['password'] ?? '';
            $s = $conn->prepare("SELECT password FROM users WHERE user_id = ?");
            $s->bind_param('i', $user_id); $s->execute();
            $row = $s->get_result()->fetch_assoc();
            if (!$row || !password_verify($password, $row['password'])) { echo json_encode(['ok' => false, 'error' => 'Incorrect password.']); exit; }
            $u = $conn->prepare("UPDATE users SET totp_secret = NULL, totp_enabled = 0, totp_recovery_codes = NULL WHERE user_id = ?");
            $u->bind_param('i', $user_id); $u->execute();
            $log  = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'TOTP_DISABLED', ?)");
            $desc = ($_SESSION['full_name'] ?? '') . ' disabled authenticator app 2FA.';
            $log->bind_param('is', $user_id, $desc); $log->execute();
            echo json_encode(['ok' => true]);
            exit;

        // ── TOTP REGEN RECOVERY ──
        case 'totp_regen_recovery':
            require_once '../../config/totp.php';
            $password = $_POST['password'] ?? '';
            $s = $conn->prepare("SELECT password FROM users WHERE user_id = ?");
            $s->bind_param('i', $user_id); $s->execute();
            $row = $s->get_result()->fetch_assoc();
            if (!$row || !password_verify($password, $row['password'])) { echo json_encode(['ok' => false, 'error' => 'Incorrect password.']); exit; }
            $plain_codes  = totp_generate_recovery_codes(8);
            $hashed_codes = array_map(fn($c) => password_hash($c, PASSWORD_DEFAULT), $plain_codes);
            $encoded = json_encode($hashed_codes);
            $u = $conn->prepare("UPDATE users SET totp_recovery_codes = ? WHERE user_id = ?");
            $u->bind_param('si', $encoded, $user_id); $u->execute();
            echo json_encode(['ok' => true, 'recovery_codes' => $plain_codes]);
            exit;

        // ── DESIGN PREFERENCES ──
        case 'design_prefs':
            $theme = in_array($_POST['theme'] ?? '', ['light', 'dark', 'warm', 'high-contrast', 'high-contrast-dark']) ? $_POST['theme'] : 'light';

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

        // ── TRANSACTION PIN ──
        case 'pin_set':
            $new_pin  = $_POST['new_pin']     ?? '';
            $cfm_pin  = $_POST['confirm_pin'] ?? '';
            $cur_pw   = san_str($_POST['current_password'] ?? '', MAX_PASSWORD);

            if (!preg_match('/^\d{4,6}$/', $new_pin)) {
                echo json_encode(['ok' => false, 'error' => 'PIN must be 4 to 6 digits.']);
                exit;
            }
            if ($new_pin !== $cfm_pin) {
                echo json_encode(['ok' => false, 'error' => 'PINs do not match.']);
                exit;
            }
            if ($cur_pw === '') {
                echo json_encode(['ok' => false, 'error' => 'Current password is required.']);
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
            $hashed_pin = password_hash($new_pin, PASSWORD_DEFAULT);
            $upd = $conn->prepare("UPDATE users SET transaction_pin = ? WHERE user_id = ?");
            $upd->bind_param('si', $hashed_pin, $user_id);
            $upd->execute();
            $log  = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'SECURITY_UPDATED', ?)");
            $desc = ($_SESSION['full_name'] ?? '') . ' set a transaction PIN.';
            $log->bind_param('is', $user_id, $desc);
            $log->execute();
            echo json_encode(['ok' => true, 'message' => 'Transaction PIN set successfully.']);
            exit;

        case 'pin_remove':
            $cur_pw = san_str($_POST['current_password'] ?? '', MAX_PASSWORD);
            if ($cur_pw === '') {
                echo json_encode(['ok' => false, 'error' => 'Current password is required.']);
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
            $upd = $conn->prepare("UPDATE users SET transaction_pin = NULL WHERE user_id = ?");
            $upd->bind_param('i', $user_id);
            $upd->execute();
            echo json_encode(['ok' => true, 'message' => 'Transaction PIN removed.']);
            exit;

        case 'pin_verify':
            $pin = $_POST['pin'] ?? '';
            $pin_stmt = $conn->prepare("SELECT transaction_pin FROM users WHERE user_id = ?");
            $pin_stmt->bind_param('i', $user_id);
            $pin_stmt->execute();
            $pin_row = $pin_stmt->get_result()->fetch_assoc();
            if (!$pin_row['transaction_pin']) {
                echo json_encode(['ok' => true, 'no_pin' => true]);
                exit;
            }
            if (password_verify($pin, $pin_row['transaction_pin'])) {
                echo json_encode(['ok' => true]);
            } else {
                echo json_encode(['ok' => false, 'error' => 'Incorrect PIN.']);
            }
            exit;

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
$u_stmt = $conn->prepare("SELECT first_name, last_name, full_name, username, username_changed_at, email, two_factor_enabled, totp_enabled, profile_photo, theme, transaction_pin FROM users WHERE user_id = ?");
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
    <script>window._csrf = <?= json_encode(csrf_token()) ?>;</script>


    <?php
    $has_photo   = !empty($current_user['profile_photo']);
    $photo_url   = $has_photo ? $base_path . 'uploads/avatars/' . htmlspecialchars($current_user['profile_photo']) : '';
    $user_theme  = $current_user['theme'] ?? 'light';
    ?>

    <!-- ═══ HORIZONTAL TABS ═══ -->
    <div class="settings-tabs-bar">
      <button class="settings-tab-btn active" data-tab="account">
        <?= icon('user', 16) ?>
        <span class="tab-label">My Account</span>
      </button>
      <button class="settings-tab-btn" data-tab="design">
        <?= icon('swatch', 16) ?>
        <span class="tab-label">Design Preferences</span>
      </button>
      <?php if ($is_super): ?>
      <button class="settings-tab-btn" data-tab="system_settings">
        <?= icon('cog', 16) ?>
        <span class="tab-label">System Settings</span>
        <span class="tab-owner">Owner</span>
      </button>
      <?php endif; ?>
    </div>

    <!-- ═══ PANELS ═══ -->

    <!-- ── MY ACCOUNT ── -->
    <div class="settings-panel active" id="panel-account">

      <form class="settings-form" data-section="account">
        <input type="hidden" name="section" value="account"/>
        <?= csrf_field() ?>

        <div class="card" style="margin-bottom:1.5rem;">
          <div class="card-header">
            <div class="card-icon"><?= icon('user', 16) ?></div>
            <div>
              <div class="card-title">Profile Information</div>
              <div class="card-sub">Update your photo, display name, and email address</div>
            </div>
          </div>
          <div class="card-body">
            <div class="avatar-upload-area" style="margin-bottom:1.25rem;">
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
            <div style="height:1px;background:var(--border);margin-bottom:1.25rem;"></div>
            <div class="form-grid">
              <div class="field">
                <label class="field-label">First Name <span class="req">*</span></label>
                <input type="text" name="first_name" class="field-input"
                  value="<?= htmlspecialchars($current_user['first_name']) ?>" required/>
              </div>
              <div class="field">
                <label class="field-label">Last Name <span class="req">*</span></label>
                <input type="text" name="last_name" class="field-input"
                  value="<?= htmlspecialchars($current_user['last_name']) ?>" required/>
              </div>
              <div class="field span-2">
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
            <?php if (!$pending_email): ?>
            <div class="info-box" style="margin-top:1rem;">
              <?= icon('information-circle', 14) ?>
              <span>Changing your email will require verification.</span>
            </div>
            <?php endif; ?>
            <div style="display:flex;justify-content:flex-end;margin-top:1.25rem;">
              <button type="button" class="btn-primary js-settings-save"><?= icon('check', 14) ?> Save Profile</button>
            </div>
          </div>
        </div>

        <?php
        $username_changed_at = $current_user['username_changed_at'] ?? null;
        $cooldown_days_left  = 0;
        $next_change_date    = '';
        if ($username_changed_at) {
            $days_since         = (int)floor((time() - strtotime($username_changed_at)) / 86400);
            $cooldown_days_left = max(0, 60 - $days_since);
            $next_change_date   = date('M d, Y', strtotime($username_changed_at . ' +60 days'));
        }
        ?>
        <div class="card" style="margin-top:1.5rem;">
          <div class="card-header">
            <div class="card-icon"><?= icon('at-symbol', 16) ?></div>
            <div>
              <div class="card-title">Change Username</div>
              <div class="card-sub">Current: <strong>@<?= htmlspecialchars($current_user['username']) ?></strong></div>
            </div>
          </div>
          <div class="card-body">
            <?php if ($cooldown_days_left > 0): ?>
            <div class="info-box">
              <?= icon('clock', 14) ?>
              <span>You can change your username again on <strong><?= $next_change_date ?></strong> (<?= $cooldown_days_left ?> day<?= $cooldown_days_left !== 1 ? 's' : '' ?> remaining). Usernames can only be changed once every 60 days.</span>
            </div>
            <?php else: ?>
            <div class="form-grid">
              <div class="field">
                <label class="field-label">New Username <span class="req">*</span></label>
                <input type="text" id="new_username" name="new_username" class="field-input"
                  placeholder="Letters, numbers, dots, underscores, hyphens"/>
                <span class="field-hint">3–50 characters. You can change this once every 60 days.</span>
              </div>
              <div class="field">
                <label class="field-label">Current Password <span class="req">*</span></label>
                <input type="password" id="username_cur_pw" name="username_cur_pw" class="field-input"
                  placeholder="Confirm your identity"/>
              </div>
            </div>
            <div style="display:flex;justify-content:flex-end;margin-top:1rem;">
              <button type="button" class="btn-primary" id="save-username-btn"><?= icon('check', 14) ?> Update Username</button>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="card" style="margin-top:1.5rem;">
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
            <div style="display:flex;justify-content:flex-end;margin-top:1.25rem;">
              <button type="button" class="btn-primary" id="save-password-btn"><?= icon('check', 14) ?> Save Password</button>
            </div>
          </div>
        </div>

      </form>

      <!-- ── SECURITY SECTION ── -->
      <div style="margin-bottom:0.5rem;">
        <div style="font-size:0.7rem;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--text-muted);padding:0 0.25rem;">Security</div>
        <div style="height:1px;background:var(--border);margin-top:0.5rem;"></div>
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
                <?= (!empty($current_user['totp_enabled']) || empty($current_user['email'])) ? 'disabled' : '' ?>
                <?php if (!empty($current_user['totp_enabled'])): ?>
                  title="Authenticator 2FA is active — disable it first to switch to email 2FA"
                <?php elseif (empty($current_user['email'])): ?>
                  title="Set an email address first"
                <?php endif; ?>/>
              <span class="toggle-slider"></span>
            </label>
          </div>
          <?php if (empty($current_user['email']) && empty($current_user['totp_enabled'])): ?>
          <div style="padding:0 1.75rem 1.25rem;">
            <div class="info-box">
              <?= icon('information-circle', 14) ?>
              <span>You need to set an email address before enabling email-based 2FA.</span>
            </div>
          </div>
          <?php endif; ?>
        </div>

        <!-- Authenticator App 2FA Card -->
        <div class="card" style="margin-top:1.5rem;">
          <div class="card-header">
            <div class="card-icon"><?= icon('device-phone-mobile', 16) ?></div>
            <div>
              <div class="card-title">Authenticator App (TOTP)</div>
              <div class="card-sub">Google Authenticator, Authy, Microsoft Authenticator</div>
            </div>
            <div style="margin-left:auto;">
              <span class="badge <?= $current_user['totp_enabled'] ? 'badge-green' : 'badge-gray' ?>" id="totp-status-badge">
                <?= $current_user['totp_enabled'] ? icon('check-circle',11).' Enabled' : 'Disabled' ?>
              </span>
            </div>
          </div>
          <div class="card-body">
            <?php if (!$current_user['totp_enabled']): ?>
            <div id="totp-setup-intro">
              <div class="info-box" style="margin-bottom:<?= $current_user['two_factor_enabled'] ? '0.75rem' : '0' ?>;">
                <?= icon('information-circle', 14) ?>
                <span>When enabled, you will be asked for a 6-digit code from your authenticator app on every login. This is stronger than email-based 2FA.</span>
              </div>
              <?php if ($current_user['two_factor_enabled']): ?>
              <div class="info-box" style="background:var(--warning-bg,#fffbeb);border-color:var(--warning-border,#fcd34d);color:var(--warning,#92400e);margin-bottom:0;">
                <?= icon('exclamation-triangle', 14) ?>
                <span>Email 2FA is currently active. Setting up the authenticator app will automatically take over — email verification will no longer be required.</span>
              </div>
              <?php endif; ?>
            </div>
            <div id="totp-setup-flow" style="display:none;">
              <div style="display:flex;flex-direction:column;align-items:center;gap:1.25rem;padding:0.25rem 0 0.5rem;">

                <!-- Step 1 -->
                <div style="width:100%;max-width:420px;">
                  <div style="font-size:0.7rem;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--text-muted);margin-bottom:0.75rem;text-align:center;">Step 1 — Scan this QR code</div>
                  <div style="display:flex;flex-direction:column;align-items:center;gap:0.75rem;">
                    <div id="totp-qr" style="background:#fff;padding:10px;border-radius:12px;border:1px solid var(--border);"></div>
                    <div style="font-size:0.72rem;color:var(--text-muted);text-align:center;">Open your authenticator app, tap <strong>+</strong>, then scan the QR code.</div>
                    <div style="width:100%;">
                      <div style="font-size:0.68rem;color:var(--text-muted);font-weight:600;text-align:center;margin-bottom:0.35rem;">Or enter this key manually</div>
                      <div id="totp-secret-display" style="font-family:monospace;font-size:0.8rem;font-weight:700;background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:0.5rem 0.75rem;letter-spacing:2px;word-break:break-all;text-align:center;"></div>
                    </div>
                  </div>
                </div>

                <!-- Divider -->
                <div style="width:100%;max-width:420px;border-top:1px solid var(--border);"></div>

                <!-- Step 2 -->
                <div style="width:100%;max-width:420px;">
                  <div style="font-size:0.7rem;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--text-muted);margin-bottom:0.75rem;text-align:center;">Step 2 — Enter the 6-digit code</div>
                  <div id="totp-confirm-alert"></div>
                  <div style="display:flex;gap:0.5rem;justify-content:center;" id="totp-digit-boxes">
                    <?php for($i=0;$i<6;$i++): ?>
                    <input type="text" inputmode="numeric" maxlength="1" autocomplete="off"
                      class="totp-digit"
                      style="width:48px;height:56px;text-align:center;font-size:1.5rem;font-weight:700;font-family:monospace;border:1.5px solid var(--border);border-radius:10px;background:var(--bg);color:var(--text);outline:none;transition:border-color 0.15s;"/>
                    <?php endfor; ?>
                  </div>
                  <input type="hidden" id="totp-code-input"/>
                  <div style="display:flex;gap:0.6rem;margin-top:0.75rem;justify-content:center;">
                    <button type="button" onclick="totpConfirm()" class="btn-primary" style="font-size:0.82rem;"><?= icon('check-circle', 13) ?> Verify &amp; Activate</button>
                    <button type="button" onclick="totpCancelSetup()" class="btn-ghost" style="font-size:0.82rem;">Cancel</button>
                  </div>
                </div>

              </div>
            </div>
            <?php else: ?>
            <div class="info-box" style="background:var(--success-bg);border-color:var(--success-border);color:var(--success);">
              <?= icon('shield-check', 14) ?>
              <span>Authenticator 2FA is active. You will be prompted for a code on every login.</span>
            </div>
            <?php endif; ?>
          </div>
          <div class="form-actions">
            <?php if (!$current_user['totp_enabled']): ?>
            <button type="button" onclick="totpStartSetup()" class="btn-primary" id="totp-setup-btn"><?= icon('shield-check', 14) ?> Set Up Authenticator</button>
            <?php else: ?>
            <button type="button" onclick="totpRegenRecovery()" class="btn-ghost"><?= icon('arrow-path', 14) ?> Regenerate Recovery Codes</button>
            <button type="button" onclick="totpDisable()" class="btn-danger"><?= icon('x-mark', 14) ?> Disable Authenticator</button>
            <?php endif; ?>
          </div>
        </div>

        <!-- Recovery Codes Modal -->
        <div id="recovery-modal" style="display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,0.6);backdrop-filter:blur(2px);align-items:center;justify-content:center;padding:1rem;">
          <div style="background:var(--bg-3);border:1px solid var(--border);border-radius:16px;width:100%;max-width:460px;box-shadow:var(--shadow-lg);">
            <div style="display:flex;align-items:center;justify-content:space-between;padding:1rem 1.25rem;border-bottom:1px solid var(--border);">
              <div style="font-weight:700;font-size:0.88rem;"><?= icon('key', 14) ?> &nbsp;Save Your Recovery Codes</div>
            </div>
            <div style="padding:1.25rem;">
              <div class="alert alert-warning" style="margin-bottom:1rem;"><?= icon('exclamation-triangle', 13) ?> <span>Save these now — each code can only be used once and you cannot view them again.</span></div>
              <div id="recovery-codes-list" style="display:grid;grid-template-columns:1fr 1fr;gap:0.4rem;font-family:monospace;font-size:0.85rem;margin-bottom:1rem;"></div>
              <div style="display:flex;gap:0.6rem;flex-wrap:wrap;">
                <button type="button" onclick="downloadRecoveryCodes()" class="btn-primary" style="font-size:0.8rem;"><?= icon('arrow-down-tray', 13) ?> Download</button>
                <button type="button" onclick="closeRecoveryModal()" class="btn-ghost" style="font-size:0.8rem;">Done</button>
              </div>
            </div>
          </div>
        </div>

        <!-- Transaction PIN Card -->
        <?php $has_pin = !empty($current_user['transaction_pin']); ?>
        <div class="card" style="margin-top:1.5rem;">
          <div class="card-header">
            <div class="card-icon"><?= icon('key', 16) ?></div>
            <div>
              <div class="card-title">Transaction PIN</div>
              <div class="card-sub">Required when deleting sensitive records</div>
            </div>
            <div style="margin-left:auto;">
              <span class="badge <?= $has_pin ? 'badge-green' : 'badge-gray' ?>" id="pin-status-badge">
                <?= $has_pin ? 'PIN Set' : 'No PIN' ?>
              </span>
            </div>
          </div>
          <div class="card-body">
            <div id="pin-info-box" class="info-box" style="margin-bottom:1rem;<?= $has_pin ? 'background:var(--success-bg);border-color:var(--success-border);color:var(--success);' : '' ?>">
              <?= $has_pin ? icon('check-circle', 14) : icon('information-circle', 14) ?>
              <span id="pin-info-text"><?= $has_pin
                ? 'Transaction PIN is active. You will be prompted to enter your PIN before deleting sensitive records.'
                : 'No PIN set. Deletes will proceed without a PIN prompt. Set a PIN to add an extra layer of protection.'
              ?></span>
            </div>
            <div style="display:flex;gap:0.5rem;justify-content:flex-end;">
              <?php if ($has_pin): ?>
              <button type="button" class="btn-ghost" id="btn-pin-remove" style="color:var(--danger);border-color:var(--danger);"><?= icon('trash', 14) ?> Remove PIN</button>
              <?php endif; ?>
              <button type="button" class="btn-primary" id="btn-pin-set"><?= icon('key', 14) ?> <?= $has_pin ? 'Change PIN' : 'Set PIN' ?></button>
            </div>
          </div>
        </div>

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
          <?php
          $themes = [
            'light'            => ['name' => 'Light',             'bg' => '#F4F1EC', 'bg2' => '#FAFAF8', 'sidebar' => '#1C1A17', 'accent' => '#D4A017', 'card' => '#FFFFFF'],
            'dark'             => ['name' => 'Dark',              'bg' => '#141210', 'bg2' => '#1C1A17', 'sidebar' => '#0F0E0D', 'accent' => '#D4A017', 'card' => '#242220'],
            'warm'             => ['name' => 'Warm',              'bg' => '#FAF5EE', 'bg2' => '#F3EBE0', 'sidebar' => '#2C1F12', 'accent' => '#A8865A', 'card' => '#FFFFFF'],
            'high-contrast'    => ['name' => 'High Contrast',     'bg' => '#FFFFFF', 'bg2' => '#F0F0F0', 'sidebar' => '#000000', 'accent' => '#000000', 'card' => '#FFFFFF'],
            'high-contrast-dark' => ['name' => 'High Contrast Dark', 'bg' => '#000000', 'bg2' => '#111111', 'sidebar' => '#000000', 'accent' => '#FFFFFF', 'card' => '#111111'],
          ];
          ?>
          <div class="theme-picker">
            <?php foreach ($themes as $value => $t): ?>
            <label class="theme-option <?= $user_theme === $value ? 'active' : '' ?>">
              <input type="radio" name="theme" value="<?= $value ?>" <?= $user_theme === $value ? 'checked' : '' ?> hidden/>
              <div class="theme-preview" style="background:<?= $t['bg'] ?>;border:1px solid <?= $t['bg2'] ?>;">
                <div class="tp-sidebar" style="background:<?= $t['sidebar'] ?>;border-right:2px solid <?= $t['accent'] ?>33;"></div>
                <div class="tp-main" style="background:<?= $t['bg'] ?>;">
                  <div class="tp-topbar" style="background:<?= $t['bg2'] ?>;"></div>
                  <div class="tp-content">
                    <div class="tp-card" style="background:<?= $t['card'] ?>;border:1px solid <?= $t['bg2'] ?>;"></div>
                    <div class="tp-card short" style="background:<?= $t['card'] ?>;border:1px solid <?= $t['bg2'] ?>;border-top:2px solid <?= $t['accent'] ?>;"></div>
                  </div>
                </div>
              </div>
              <div class="theme-label">
                <span class="theme-radio"></span>
                <span class="theme-name"><?= $t['name'] ?></span>
              </div>
            </label>
            <?php endforeach; ?>
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
        <?= csrf_field() ?>

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
                  placeholder="09XXXXXXXXX or 044-XXX-XXXX"/>
              </div>
              <div class="field">
                <label class="field-label">Email Address</label>
                <input type="email" name="company_email" class="field-input"
                  value="<?= htmlspecialchars($settings['company_email']) ?>"
                  placeholder="name@email.com"/>
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
          <button type="button" class="btn-primary js-settings-save"><?= icon('check', 14) ?> Save All Settings</button>
        </div>
      </form>
    </div>

    <?php endif; ?>

  </div>
</div>

<?php
$footer_scripts = ''; // JS moved to assets/js/shared/settings.js
$footer_extra_scripts = '<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>'
    . '<script src="../../assets/js/shared/settings.js"></script>';
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

// ── Theme Picker — live preview on click ──
document.querySelectorAll('.theme-option').forEach(opt => {
    opt.addEventListener('click', () => {
        document.querySelectorAll('.theme-option').forEach(o => o.classList.remove('active'));
        opt.classList.add('active');
        const radio = opt.querySelector('input[type="radio"]');
        radio.checked = true;
        document.documentElement.setAttribute('data-theme', radio.value);
        document.body.classList.toggle('light-mode', radio.value === 'light');
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
                document.body.classList.toggle('light-mode', theme === 'light');
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

?>

<!-- ── TRANSACTION PIN MODAL ── -->
<div class="modal-overlay" id="pin-modal" onclick="if(event.target===this)closePinModal()">
  <div class="modal-box" style="max-width:400px;">
    <div class="modal-header">
      <div class="modal-title"><?= icon('key', 16) ?> <span id="pin-modal-title">Set Transaction PIN</span></div>
      <button class="modal-close" onclick="closePinModal()"><?= icon('x-mark', 14) ?></button>
    </div>
    <div style="padding:1.25rem;display:flex;flex-direction:column;gap:1rem;">
      <div class="field">
        <label class="field-label">New PIN <span class="req">*</span></label>
        <input type="password" id="modal-pin-new" class="field-input" inputmode="numeric" maxlength="6" placeholder="4–6 digits" autocomplete="new-password"/>
      </div>
      <div class="field">
        <label class="field-label">Confirm PIN <span class="req">*</span></label>
        <input type="password" id="modal-pin-confirm" class="field-input" inputmode="numeric" maxlength="6" placeholder="Re-enter PIN" autocomplete="new-password"/>
      </div>
      <div class="field">
        <label class="field-label">Current Password <span class="req">*</span></label>
        <input type="password" id="modal-pin-curpw" class="field-input" placeholder="Confirm your identity" autocomplete="current-password"/>
      </div>
      <div id="pin-modal-error" style="display:none;color:var(--danger);font-size:0.8rem;background:rgba(192,57,43,0.08);border:1px solid rgba(192,57,43,0.2);border-radius:8px;padding:0.6rem 0.8rem;"></div>
    </div>
    <div style="padding:1rem 1.25rem;border-top:1px solid var(--border);display:flex;gap:0.5rem;justify-content:flex-end;">
      <button type="button" class="btn-ghost" onclick="closePinModal()">Cancel</button>
      <button type="button" class="btn-primary" id="btn-pin-modal-save"><?= icon('key', 14) ?> <span id="pin-modal-btn-label">Set PIN</span></button>
    </div>
  </div>
</div>

<style>
#pin-modal .modal-box { overflow: hidden; }
</style>

<script>
function openPinModal() {
  document.getElementById('pin-modal').classList.add('open');
  document.getElementById('modal-pin-new').value = '';
  document.getElementById('modal-pin-confirm').value = '';
  document.getElementById('modal-pin-curpw').value = '';
  document.getElementById('pin-modal-error').style.display = 'none';
  setTimeout(() => document.getElementById('modal-pin-new').focus(), 80);
}
function closePinModal() {
  document.getElementById('pin-modal').classList.remove('open');
}
document.getElementById('btn-pin-set').addEventListener('click', openPinModal);

document.getElementById('btn-pin-modal-save').addEventListener('click', async function() {
  const newPin  = document.getElementById('modal-pin-new').value.trim();
  const cfmPin  = document.getElementById('modal-pin-confirm').value.trim();
  const curPw   = document.getElementById('modal-pin-curpw').value;
  const errBox  = document.getElementById('pin-modal-error');

  const showErr = (msg) => { errBox.textContent = msg; errBox.style.display = 'block'; };
  errBox.style.display = 'none';

  if (!newPin)              { showErr('Enter a new PIN.'); return; }
  if (!/^\d{4,6}$/.test(newPin)) { showErr('PIN must be 4 to 6 digits only.'); return; }
  if (newPin !== cfmPin)    { showErr('PINs do not match.'); return; }
  if (!curPw)               { showErr('Enter your current password.'); return; }

  const orig = this.innerHTML; this.disabled = true; this.textContent = 'Saving...';

  const fd = new FormData();
  fd.append('section', 'pin_set');
  fd.append('csrf_token', window._csrf || '');
  fd.append('new_pin', newPin);
  fd.append('confirm_pin', cfmPin);
  fd.append('current_password', curPw);

  let data = null;
  try { const res = await fetch('settings.php', { method:'POST', body:fd }); data = await res.json(); } catch(e) {}
  this.disabled = false; this.innerHTML = orig;

  if (!data) { showErr('Something went wrong. Please try again.'); return; }
  if (data.ok) {
    closePinModal();
    // Update the UI without reload
    const badge = document.getElementById('pin-status-badge');
    const infoBox = document.getElementById('pin-info-box');
    const infoText = document.getElementById('pin-info-text');
    if (badge)   { badge.className = 'badge badge-green'; badge.textContent = 'PIN Set'; }
    if (infoBox) { infoBox.style.cssText = 'margin-bottom:1rem;background:var(--success-bg);border-color:var(--success-border);color:var(--success);'; }
    if (infoText) infoText.textContent = 'Transaction PIN is active. You will be prompted to enter your PIN before deleting sensitive records.';
    document.getElementById('btn-pin-set').innerHTML = '<?= icon('key', 14) ?> Change PIN';
    if (!document.getElementById('btn-pin-remove')) {
      const removeBtn = document.createElement('button');
      removeBtn.type = 'button';
      removeBtn.className = 'btn-ghost';
      removeBtn.id = 'btn-pin-remove';
      removeBtn.style.cssText = 'color:var(--danger);border-color:var(--danger);';
      removeBtn.innerHTML = '<?= icon('trash', 14) ?> Remove PIN';
      document.getElementById('btn-pin-set').parentElement.insertBefore(removeBtn, document.getElementById('btn-pin-set'));
      removeBtn.addEventListener('click', handlePinRemove);
    }
    Swal.fire({ toast:true, position:'top-end', icon:'success', title:'PIN updated!', showConfirmButton:false, timer:2500, timerProgressBar:true });
  } else {
    showErr(data.error || 'Failed to save PIN.');
  }
});

async function handlePinRemove() {
  const curPw = document.getElementById('modal-pin-curpw').value;
  const confirmed = await Swal.fire({
    title: 'Remove Transaction PIN?',
    text: 'Deletes will no longer require a PIN.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#C0392B',
    cancelButtonColor: '#6B7280',
    confirmButtonText: 'Yes, remove',
    cancelButtonText: 'Cancel'
  });
  if (!confirmed.isConfirmed) return;

  const pw = await Swal.fire({
    title: 'Confirm Password',
    html: '<input id="swal-pw-input" type="password" class="swal2-input" placeholder="Current password" autocomplete="current-password"/>',
    confirmButtonText: 'Remove PIN',
    confirmButtonColor: '#C0392B',
    showCancelButton: true,
    cancelButtonColor: '#6B7280',
    didOpen: () => document.getElementById('swal-pw-input').focus(),
    preConfirm: () => {
      const v = document.getElementById('swal-pw-input').value;
      if (!v) { Swal.showValidationMessage('Password is required.'); return false; }
      return v;
    }
  });
  if (!pw.isConfirmed) return;

  const fd = new FormData();
  fd.append('section', 'pin_remove');
  fd.append('csrf_token', window._csrf || '');
  fd.append('current_password', pw.value);

  let data = null;
  try { const res = await fetch('settings.php', { method:'POST', body:fd }); data = await res.json(); } catch(e) {}

  if (data?.ok) {
    const badge = document.getElementById('pin-status-badge');
    const infoBox = document.getElementById('pin-info-box');
    const infoText = document.getElementById('pin-info-text');
    if (badge)   { badge.className = 'badge badge-gray'; badge.textContent = 'No PIN'; }
    if (infoBox) { infoBox.style.cssText = 'margin-bottom:1rem;'; }
    if (infoText) infoText.textContent = 'No PIN set. Deletes will proceed without a PIN prompt. Set a PIN to add an extra layer of protection.';
    document.getElementById('btn-pin-set').innerHTML = '<?= icon('key', 14) ?> Set PIN';
    const removeBtn = document.getElementById('btn-pin-remove');
    if (removeBtn) removeBtn.remove();
    Swal.fire({ toast:true, position:'top-end', icon:'success', title:'PIN removed.', showConfirmButton:false, timer:2500, timerProgressBar:true });
  } else {
    Swal.fire({ icon:'error', title:'Error', text: data?.error || 'Something went wrong.', confirmButtonColor:'#B8860B' });
  }
}

const removePinBtn = document.getElementById('btn-pin-remove');
if (removePinBtn) removePinBtn.addEventListener('click', handlePinRemove);
</script>

<?php
require_once '../../includes/footer.php';
?>

<script>
// ── TOTP digit boxes ──
(function() {
    const boxes = document.querySelectorAll('.totp-digit');
    const hidden = document.getElementById('totp-code-input');
    if (!boxes.length || !hidden) return;

    function syncHidden() {
        hidden.value = Array.from(boxes).map(b => b.value).join('');
    }

    boxes.forEach((box, i) => {
        box.addEventListener('focus', () => box.style.borderColor = 'var(--gold-bright, #B8860B)');
        box.addEventListener('blur',  () => box.style.borderColor = 'var(--border)');

        box.addEventListener('input', () => {
            box.value = box.value.replace(/\D/g, '').slice(-1);
            syncHidden();
            if (box.value && i < boxes.length - 1) boxes[i + 1].focus();
        });

        box.addEventListener('keydown', (e) => {
            if (e.key === 'Backspace' && !box.value && i > 0) boxes[i - 1].focus();
        });

        box.addEventListener('paste', (e) => {
            e.preventDefault();
            const digits = (e.clipboardData.getData('text') || '').replace(/\D/g, '').slice(0, 6);
            digits.split('').forEach((d, j) => { if (boxes[j]) boxes[j].value = d; });
            syncHidden();
            const next = boxes[Math.min(digits.length, boxes.length - 1)];
            if (next) next.focus();
        });
    });
})();

// ── TOTP (Authenticator App) — must be after footer.php so SweetAlert2 is loaded ──
let _recoveryCodes = [];

function totpPost(data) {
    const fd = new FormData();
    fd.append('csrf_token', window._csrf || '');
    Object.entries(data).forEach(([k,v]) => fd.append(k,v));
    return fetch('settings.php', { method:'POST', body:fd }).then(r=>r.json());
}

window.totpStartSetup = async function() {
    const res = await totpPost({ section:'totp_generate' });
    if (!res.ok) { Swal.fire({ icon:'error', title:'Error', text:res.error, confirmButtonColor:'#B8860B' }); return; }
    document.getElementById('totp-setup-intro').style.display = 'none';
    document.getElementById('totp-setup-flow').style.display  = 'block';
    const btn = document.getElementById('totp-setup-btn');
    if (btn) btn.style.display = 'none';
    document.getElementById('totp-secret-display').textContent = res.secret;
    const qrEl = document.getElementById('totp-qr');
    qrEl.innerHTML = '';
    new QRCode(qrEl, { text: res.uri, width: 140, height: 140, correctLevel: QRCode.CorrectLevel.M });
    const firstBox = document.querySelector('.totp-digit');
    if (firstBox) firstBox.focus();
};

window.totpCancelSetup = function() {
    document.getElementById('totp-setup-intro').style.display = 'block';
    document.getElementById('totp-setup-flow').style.display  = 'none';
    const btn = document.getElementById('totp-setup-btn');
    if (btn) btn.style.display = '';
    document.querySelectorAll('.totp-digit').forEach(b => b.value = '');
    document.getElementById('totp-code-input').value = '';
    document.getElementById('totp-confirm-alert').innerHTML = '';
};

window.totpConfirm = async function() {
    const code = document.getElementById('totp-code-input').value.trim();
    if (!code || code.length < 6) {
        document.getElementById('totp-confirm-alert').innerHTML =
            '<div class="alert alert-danger" style="margin-bottom:0.75rem;">Please enter the 6-digit code from your app.</div>';
        return;
    }
    // Ask for password to confirm identity before activating
    const pwResult = await Swal.fire({
        title: 'Confirm your password',
        text: 'Enter your account password to activate Authenticator 2FA.',
        input: 'password', inputPlaceholder: 'Your password',
        confirmButtonText: 'Activate', confirmButtonColor: '#B8860B',
        showCancelButton: true, cancelButtonColor: '#6B7280',
    });
    if (!pwResult.isConfirmed) return;
    const res = await totpPost({ section:'totp_confirm', code, password: pwResult.value });
    if (!res.ok) {
        document.getElementById('totp-confirm-alert').innerHTML =
            '<div class="alert alert-danger" style="margin-bottom:0.75rem;">' + res.error + '</div>';
        return;
    }
    _recoveryCodes = res.recovery_codes;
    showRecoveryModal(_recoveryCodes);
};

window.totpDisable = async function() {
    const result = await Swal.fire({
        title:'Disable Authenticator App', text:'Enter your password to confirm.',
        input:'password', inputPlaceholder:'Your password',
        confirmButtonText:'Disable', confirmButtonColor:'#C0392B',
        showCancelButton:true, cancelButtonColor:'#6B7280',
    });
    if (!result.isConfirmed) return;
    const res = await totpPost({ section:'totp_disable', password:result.value });
    if (res.ok) {
        Swal.fire({ icon:'success', title:'2FA Disabled', confirmButtonColor:'#B8860B' }).then(() => location.reload());
    } else {
        Swal.fire({ icon:'error', title:'Error', text:res.error, confirmButtonColor:'#B8860B' });
    }
};

window.totpRegenRecovery = async function() {
    const result = await Swal.fire({
        title:'Regenerate Recovery Codes',
        text:'This will invalidate your existing recovery codes. Enter your password to confirm.',
        input:'password', inputPlaceholder:'Your password',
        confirmButtonText:'Regenerate', confirmButtonColor:'#1C1A17',
        showCancelButton:true, cancelButtonColor:'#6B7280',
    });
    if (!result.isConfirmed) return;
    const res = await totpPost({ section:'totp_regen_recovery', password:result.value });
    if (res.ok) {
        _recoveryCodes = res.recovery_codes;
        showRecoveryModal(_recoveryCodes);
    } else {
        Swal.fire({ icon:'error', title:'Error', text:res.error, confirmButtonColor:'#B8860B' });
    }
};

function showRecoveryModal(codes) {
    const list = document.getElementById('recovery-codes-list');
    if (!list) return;
    list.innerHTML = codes.map(function(c) {
        return '<div style="background:var(--bg);border:1px solid var(--border);border-radius:6px;padding:0.3rem 0.55rem;text-align:center;font-weight:700;font-size:0.8rem;">' + c + '</div>';
    }).join('');
    document.getElementById('recovery-modal').style.display = 'flex';
}

window.closeRecoveryModal = function() {
    document.getElementById('recovery-modal').style.display = 'none';
    if (_recoveryCodes.length) location.reload();
};

window.downloadRecoveryCodes = function() {
    var text = "TG-BASICS Recovery Codes\nGenerated: " + new Date().toLocaleString() +
               "\nKeep these safe — each code can only be used once.\n\n" + _recoveryCodes.join("\n");
    var a = document.createElement('a');
    a.href = 'data:text/plain;charset=utf-8,' + encodeURIComponent(text);
    a.download = 'tg-basics-recovery-codes.txt';
    a.click();
};
</script>
