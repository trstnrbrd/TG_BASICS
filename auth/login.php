<?php
require_once __DIR__ . "/../config/session.php";
require_once '../config/db.php';
require_once '../config/settings.php';
require_once '../config/mailer.php';
require_once '../includes/icons.php';

if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'mechanic') {
        header("Location: ../modules/repair/dashboard_mechanic.php");
    } else {
        header("Location: ../modules/admin/dashboard_admin.php");
    }
    exit;
}

$error   = '';
$lockout = false;
$_max_attempts = (int)getSetting($conn, 'max_login_attempts', '5');
$_lockout_mins = (int)getSetting($conn, 'lockout_duration', '15');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Please fill in all fields.';
    } else {
        $lock_check = $conn->prepare("SELECT failed_attempts, locked_until FROM users WHERE username = ?");
        $lock_check->bind_param('s', $username);
        $lock_check->execute();
        $lock_result = $lock_check->get_result()->fetch_assoc();

        if ($lock_result && $lock_result['locked_until'] && strtotime($lock_result['locked_until']) > time()) {
            $lockout = true;
            $error   = 'Account is locked due to too many failed attempts. Please try again later.';
        } else {
            $stmt = $conn->prepare("SELECT user_id, username, password, role, full_name, is_active, two_factor_enabled, email FROM users WHERE username = ?");
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();

            if ($user && password_verify($password, $user['password'])) {
                if (!$user['is_active']) {
                    $error = 'Your account is not yet activated. Please check your email for the activation link.';
                } else {
                    $reset = $conn->prepare("UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE username = ?");
                    $reset->bind_param('s', $username);
                    $reset->execute();

                    // Check if 2FA is enabled
                    if ($user['two_factor_enabled'] && !empty($user['email'])) {
                        // Generate 6-digit code
                        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

                        // Invalidate old codes
                        $inv = $conn->prepare("UPDATE two_factor_codes SET used = 1 WHERE user_id = ? AND used = 0");
                        $inv->bind_param('i', $user['user_id']);
                        $inv->execute();

                        // Insert new code (use MySQL NOW() to avoid PHP/MySQL timezone mismatch)
                        $ins = $conn->prepare("INSERT INTO two_factor_codes (user_id, code, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))");
                        $ins->bind_param('is', $user['user_id'], $code);
                        $ins->execute();

                        // Send the code via email
                        send2FACodeEmail($user['email'], $user['full_name'], $code);

                        // Store pending 2FA session
                        $_SESSION['2fa_user_id']   = $user['user_id'];
                        $_SESSION['2fa_username']  = $user['username'];
                        $_SESSION['2fa_role']      = $user['role'];
                        $_SESSION['2fa_full_name'] = $user['full_name'];
                        $_SESSION['2fa_email']     = $user['email'];

                        header("Location: verify_2fa.php");
                        exit;
                    }

                    // No 2FA — normal login
                    $log  = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'LOGIN', ?)");
                    $desc = $user['full_name'] . ' logged in.';
                    $log->bind_param('is', $user['user_id'], $desc);
                    $log->execute();

                    $_SESSION['user_id']   = $user['user_id'];
                    $_SESSION['username']  = $user['username'];
                    $_SESSION['role']      = $user['role'];
                    $_SESSION['full_name'] = $user['full_name'];

                    if ($user['role'] === 'mechanic') {
                        header("Location: ../modules/repair/dashboard_mechanic.php");
                    } else {
                        header("Location: ../modules/admin/dashboard_admin.php");
                    }
                    exit;
                }
            } else {
                if ($lock_result) {
                    $new_attempts = ($lock_result['failed_attempts'] ?? 0) + 1;
                    if ($new_attempts >= $_max_attempts) {
                        $locked_until = date('Y-m-d H:i:s', strtotime('+' . $_lockout_mins . ' minutes'));
                        $upd = $conn->prepare("UPDATE users SET failed_attempts = ?, locked_until = ? WHERE username = ?");
                        $upd->bind_param('iss', $new_attempts, $locked_until, $username);
                        $upd->execute();
                        $lockout = true;
                        $error   = 'Account locked after ' . $_max_attempts . ' failed attempts. Try again in ' . $_lockout_mins . ' minutes.';
                    } else {
                        $remaining = $_max_attempts - $new_attempts;
                        $upd = $conn->prepare("UPDATE users SET failed_attempts = ? WHERE username = ?");
                        $upd->bind_param('is', $new_attempts, $username);
                        $upd->execute();
                        $error = 'Invalid username or password. ' . $remaining . ' attempt' . ($remaining !== 1 ? 's' : '') . ' remaining.';
                    }
                } else {
                    $error = 'Invalid username or password.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<meta name="format-detection" content="telephone=no, date=no, email=no, address=no"/>
<title>Sign In | TG-BASICS</title>
<link rel="icon" type="image/png" href="../assets/img/tg_logo.png"/>
<link rel="apple-touch-icon" href="../assets/img/tg_logo.png"/>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="../assets/css/auth/login.css"/>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>

<!-- Toast root - data attributes pass PHP state to JS -->
<div id="toast-root"
  data-lockout="<?= $lockout ? '1' : '0' ?>"
  data-message="<?= htmlspecialchars($error) ?>"
  data-icon-lockout="<?= htmlspecialchars(icon('lock-closed', 14)) ?>"
  data-icon-warning="<?= htmlspecialchars(icon('exclamation-triangle', 14)) ?>">
</div>

<div class="login-wrapper">

  <a href="../index.php" style="text-decoration:none;color:inherit;">
  <div class="login-brand">
    <div class="brand-logos">
      <div class="brand-logo-wrap">
        <img src="../assets/img/tg_logo.png" alt="TG Customworks"/>
      </div>
      <div class="brand-logo-sep"></div>
      <div class="brand-logo-wrap no-ring">
        <img src="../assets/img/LogoBasicCar.png" alt="Basic Car Insurance"/>
      </div>
    </div>
    <div class="brand-name">TG<span>-BASICS</span></div>
    <div class="brand-tagline">Brokerage and Auto Shop Integrated Client System</div>
  </div>
  </a>

  <div class="login-card">
    <div class="login-card-header">
      <div class="login-card-header-title">Welcome back</div>
      <div class="login-card-header-sub">Sign in to continue to TG-BASICS</div>
    </div>

    <div class="login-card-body">

      <?php if ($error && !$lockout): ?>
      <div class="alert-error">
        <?= icon('exclamation-triangle', 14) ?>
        <span><?= htmlspecialchars($error) ?></span>
      </div>
      <?php endif; ?>

      <form method="POST" action="" id="login-form" novalidate>
        <div class="field">
          <label class="field-label">Username</label>
          <div class="field-input-wrap">
            <span class="field-icon"><?= icon('user', 14) ?></span>
            <input type="text" name="username" id="username" class="field-input"
              placeholder="Enter your username"
              value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
              autocomplete="username" required/>
          </div>
        </div>

        <div class="field">
          <label class="field-label">Password</label>
          <div class="field-input-wrap">
            <span class="field-icon"><?= icon('lock-closed', 14) ?></span>
            <input type="password" name="password" id="password" class="field-input"
              placeholder="Enter your password"
              autocomplete="current-password" required/>
            <button type="button" class="pw-toggle" id="pw-toggle" aria-label="Toggle password visibility">
              <svg id="pw-icon-show" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
              <svg id="pw-icon-hide" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="16" height="16" style="display:none;"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88"/></svg>
            </button>
          </div>
        </div>

        <div id="submit-root"></div>
      </form>
    </div>

    <div class="login-note">
      No self-registration. Contact your administrator for account access.<br/>
      <a href="forgot_password.php">Forgot your password?</a>
    </div>
  </div>

</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/react/18.2.0/umd/react.production.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/react-dom/18.2.0/umd/react-dom.production.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/babel-standalone/7.23.2/babel.min.js"></script>
<script type="text/babel" src="../assets/js/auth/login.react.js"></script>

<script src="../assets/js/auth/login.js"></script>

</body>
</html>