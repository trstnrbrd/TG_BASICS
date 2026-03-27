<?php
session_start();
require_once '../config/db.php';
require_once '../config/settings.php';
require_once '../config/mailer.php';
require_once '../includes/icons.php';

if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'mechanic') {
        header("Location: ../modules/repair/dashboard_mechanic.php");
    } else {
        header("Location: ../modules/dashboard_admin.php");
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
                        header("Location: ../modules/dashboard_admin.php");
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
<title>Sign In | TG-BASICS</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="../assets/css/login.css"/>
</head>
<body>

<!-- Toast root - data attributes pass PHP state to JS -->
<div id="toast-root"
  data-lockout="<?= $lockout ? '1' : '0' ?>"
  data-message="<?= htmlspecialchars($error) ?>">
</div>

<div class="login-wrapper">

  <div class="login-brand">
    <div class="brand-logo-wrap">
      <img src="../assets/img/tg_logo.png" alt="TG Logo"/>
    </div>
    <div class="brand-name">TG<span>-BASICS</span></div>
    <div class="brand-tagline">Brokerage and Auto Shop Integrated Client System</div>
  </div>

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
            <button type="button" class="pw-toggle" id="pw-toggle">&#128065;</button>
          </div>
        </div>

        <div id="submit-root"></div>
      </form>
    </div>

    <div class="login-note">
      No self-registration. Contact your administrator for account access.<br/>
      <a href="forgot_password.php">Forgot your password?</a>
      &nbsp;&middot;&nbsp;
      <a href="../index.php"><?= icon('arrow-left', 12) ?> Back to Home</a>
    </div>
  </div>

</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/react/18.2.0/umd/react.production.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/react-dom/18.2.0/umd/react-dom.production.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/babel-standalone/7.23.2/babel.min.js"></script>
<script type="text/babel" src="../assets/js/login.js"></script>

<script>
  document.getElementById('pw-toggle').addEventListener('click', function () {
    const pw = document.getElementById('password');
    pw.type = pw.type === 'password' ? 'text' : 'password';
    this.textContent = pw.type === 'password' ? '👁' : '🙈';
  });
</script>

</body>
</html>