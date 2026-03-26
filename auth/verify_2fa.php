<?php
session_start();
require_once '../config/db.php';
require_once '../config/settings.php';
require_once '../config/mailer.php';
require_once '../includes/icons.php';

// Must have a pending 2FA session
if (!isset($_SESSION['2fa_user_id'])) {
    header("Location: login.php");
    exit;
}

$pending_uid  = $_SESSION['2fa_user_id'];
$pending_name = $_SESSION['2fa_full_name'];
$pending_role = $_SESSION['2fa_role'];
$pending_user = $_SESSION['2fa_username'];
$error = '';

// Handle cancel
if (isset($_GET['action']) && $_GET['action'] === 'cancel') {
    unset($_SESSION['2fa_user_id'], $_SESSION['2fa_full_name'], $_SESSION['2fa_role'], $_SESSION['2fa_username'], $_SESSION['2fa_email']);
    header("Location: login.php");
    exit;
}

// Handle resend
if (isset($_GET['action']) && $_GET['action'] === 'resend') {
    // Get user email
    $em_stmt = $conn->prepare("SELECT email FROM users WHERE user_id = ?");
    $em_stmt->bind_param('i', $pending_uid);
    $em_stmt->execute();
    $em_row = $em_stmt->get_result()->fetch_assoc();

    if ($em_row && $em_row['email']) {
        // Invalidate old codes
        $inv = $conn->prepare("UPDATE two_factor_codes SET used = 1 WHERE user_id = ? AND used = 0");
        $inv->bind_param('i', $pending_uid);
        $inv->execute();

        // Generate new code
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));
        $ins = $conn->prepare("INSERT INTO two_factor_codes (user_id, code, expires_at) VALUES (?, ?, ?)");
        $ins->bind_param('iss', $pending_uid, $code, $expires);
        $ins->execute();

        send2FACodeEmail($em_row['email'], $pending_name, $code);
    }

    header("Location: verify_2fa.php?resent=1");
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim($_POST['code'] ?? '');

    if (strlen($code) !== 6 || !ctype_digit($code)) {
        $error = 'Please enter a valid 6-digit code.';
    } else {
        $stmt = $conn->prepare("
            SELECT id FROM two_factor_codes
            WHERE user_id = ? AND code = ? AND used = 0 AND expires_at > NOW()
            LIMIT 1
        ");
        $stmt->bind_param('is', $pending_uid, $code);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if ($row) {
            // Mark code as used
            $mark = $conn->prepare("UPDATE two_factor_codes SET used = 1 WHERE id = ?");
            $mark->bind_param('i', $row['id']);
            $mark->execute();

            // Log the login
            $log  = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'LOGIN', ?)");
            $desc = $pending_name . ' logged in (2FA verified).';
            $log->bind_param('is', $pending_uid, $desc);
            $log->execute();

            // Set full session
            $_SESSION['user_id']   = $pending_uid;
            $_SESSION['username']  = $pending_user;
            $_SESSION['role']      = $pending_role;
            $_SESSION['full_name'] = $pending_name;

            // Clean up 2FA session vars
            unset($_SESSION['2fa_user_id'], $_SESSION['2fa_full_name'], $_SESSION['2fa_role'], $_SESSION['2fa_username'], $_SESSION['2fa_email']);

            if ($pending_role === 'mechanic') {
                header("Location: ../modules/repair/dashboard_mechanic.php");
            } else {
                header("Location: ../modules/dashboard_admin.php");
            }
            exit;
        } else {
            $error = 'Invalid or expired code. Please try again or request a new one.';
        }
    }
}

$resent = isset($_GET['resent']);
$masked_email = $_SESSION['2fa_email'] ?? '';
if ($masked_email) {
    $parts = explode('@', $masked_email);
    $name_part = $parts[0];
    $masked_email = substr($name_part, 0, 2) . str_repeat('*', max(0, strlen($name_part) - 2)) . '@' . $parts[1];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Two-Factor Verification | TG-BASICS</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="../assets/css/activate.css"/>
<style>
  .code-inputs {
      display: flex;
      gap: 0.5rem;
      justify-content: center;
      margin-bottom: 1.5rem;
  }
  .code-inputs input {
      width: 48px;
      height: 56px;
      text-align: center;
      font-size: 1.4rem;
      font-weight: 800;
      font-family: 'Plus Jakarta Sans', monospace;
      border: 2px solid var(--border);
      border-radius: 10px;
      background: var(--bg);
      color: var(--text-primary);
      outline: none;
      transition: border-color 0.15s, box-shadow 0.15s;
  }
  .code-inputs input:focus {
      border-color: var(--gold-bright);
      box-shadow: 0 0 0 3px rgba(212, 160, 23, 0.15);
      background: var(--bg-3);
  }
  .resend-row {
      text-align: center;
      font-size: 0.78rem;
      color: var(--text-muted);
      margin-top: 1rem;
  }
  .resend-row a {
      color: var(--gold);
      text-decoration: none;
      font-weight: 600;
  }
  .resend-row a:hover { color: var(--gold-bright); }
  .resent-badge {
      display: inline-flex;
      align-items: center;
      gap: 0.3rem;
      background: var(--success-bg);
      color: var(--success);
      font-size: 0.72rem;
      font-weight: 600;
      padding: 0.3rem 0.7rem;
      border-radius: 6px;
      margin-bottom: 1rem;
  }
</style>
</head>
<body>

<div class="wrap">
  <div class="brand">
    <div class="brand-logo-ring">
      <img src="../assets/img/tg_logo.png" alt="TG Logo"/>
    </div>
    <div class="brand-name">TG<span>-BASICS</span></div>
    <div class="brand-tag">Two-Factor Authentication</div>
  </div>

  <div class="card">
    <div class="card-head">
      <div class="card-head-icon default">
        <?= icon('shield-check', 22) ?>
      </div>
      <div class="card-head-text">
        <div class="card-head-title">Verify Your Identity</div>
        <div class="card-head-sub">Enter the 6-digit code sent to your email</div>
      </div>
    </div>

    <div class="card-body">

      <?php if ($resent): ?>
      <div style="text-align:center;">
        <div class="resent-badge"><?= icon('check-circle', 12) ?> A new code has been sent!</div>
      </div>
      <?php endif; ?>

      <?php if ($error): ?>
      <div class="alert alert-danger">
        <?= icon('exclamation-triangle', 14) ?>
        <span><?= htmlspecialchars($error) ?></span>
      </div>
      <?php endif; ?>

      <div class="welcome-msg">
        <div class="welcome-avatar"><?= substr($pending_name, 0, 1) ?></div>
        <div class="welcome-text">
          Hi <strong><?= htmlspecialchars($pending_name) ?></strong>, we sent a verification code to
          <strong><?= htmlspecialchars($masked_email) ?></strong>. Enter it below to continue.
        </div>
      </div>

      <form method="POST" action="" id="tfa-form">
        <input type="hidden" name="code" id="code-hidden"/>

        <div class="code-inputs" id="code-inputs">
          <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" autocomplete="off"/>
          <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" autocomplete="off"/>
          <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" autocomplete="off"/>
          <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" autocomplete="off"/>
          <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" autocomplete="off"/>
          <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" autocomplete="off"/>
        </div>

        <button type="submit" class="btn-submit">
          <?= icon('shield-check', 14) ?> Verify & Sign In
        </button>

        <div class="resend-row">
          Didn't receive the code? <a href="verify_2fa.php?action=resend">Resend Code</a>
        </div>
      </form>
    </div>

    <div class="card-foot">
      <span>Code expires in 10 minutes</span>
      <a href="verify_2fa.php?action=cancel"><?= icon('arrow-left', 12) ?> Back to Login</a>
    </div>
  </div>
</div>

<script>
(function() {
    const inputs = document.querySelectorAll('#code-inputs input');
    const hidden = document.getElementById('code-hidden');
    const form   = document.getElementById('tfa-form');

    function updateHidden() {
        hidden.value = Array.from(inputs).map(i => i.value).join('');
    }

    inputs.forEach((input, idx) => {
        input.addEventListener('input', (e) => {
            const val = e.target.value.replace(/\D/g, '');
            e.target.value = val;
            if (val && idx < inputs.length - 1) {
                inputs[idx + 1].focus();
            }
            updateHidden();
            // Auto-submit when all filled
            if (hidden.value.length === 6) {
                form.submit();
            }
        });

        input.addEventListener('keydown', (e) => {
            if (e.key === 'Backspace' && !input.value && idx > 0) {
                inputs[idx - 1].focus();
            }
        });

        input.addEventListener('paste', (e) => {
            e.preventDefault();
            const paste = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '').slice(0, 6);
            paste.split('').forEach((ch, i) => {
                if (inputs[i]) inputs[i].value = ch;
            });
            updateHidden();
            if (paste.length === 6) {
                inputs[5].focus();
                form.submit();
            } else if (inputs[paste.length]) {
                inputs[paste.length].focus();
            }
        });
    });

    // Auto-focus first input
    inputs[0].focus();
})();
</script>

</body>
</html>
