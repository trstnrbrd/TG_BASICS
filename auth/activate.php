<?php
session_start();
require_once '../config/db.php';
require_once '../includes/icons.php';

$token   = trim($_GET['token'] ?? '');
$error   = '';
$success = false;
$user    = null;

if ($token === '') {
    header("Location: login.php");
    exit;
}

$stmt = $conn->prepare("SELECT user_id, full_name, email, is_active FROM users WHERE activation_token = ?");
$stmt->bind_param('s', $token);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if (!$user) {
    $error = 'This activation link is invalid or has already been used.';
} elseif ($user['is_active']) {
    $error = 'This account is already activated. Please log in.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user && !$user['is_active']) {
    $new_password = trim($_POST['new_password'] ?? '');
    $confirm_pw   = trim($_POST['confirm_password'] ?? '');

    if (strlen($new_password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($new_password !== $confirm_pw) {
        $error = 'Passwords do not match.';
    } else {
        $hashed = password_hash($new_password, PASSWORD_DEFAULT);
        $upd = $conn->prepare("UPDATE users SET password = ?, is_active = 1, activation_token = NULL WHERE user_id = ?");
        $upd->bind_param('si', $hashed, $user['user_id']);
        if ($upd->execute()) {
            $success = true;
        } else {
            $error = 'Something went wrong. Please try again.';
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
<title>Activate Account | TG-BASICS</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="../assets/css/activate.css"/>
</head>
<body>
<div class="wrap">

  <div class="brand">
    <div class="brand-logos">
      <div class="brand-logo-ring">
        <img src="../assets/img/tg_logo.png" alt="TG Customworks"/>
      </div>
      <div class="brand-logo-sep"></div>
      <div class="brand-logo-ring no-ring">
        <img src="../assets/img/LogoBasicCar.png" alt="Basic Car Insurance"/>
      </div>
    </div>
    <div class="brand-name">TG<span>-BASICS</span></div>
    <div class="brand-tag">Account Activation</div>
  </div>

  <div class="card">

    <div class="card-head">
      <?php if ($success): ?>
        <div class="card-head-icon success"><?= icon('check-circle', 22) ?></div>
        <div class="card-head-text">
          <div class="card-head-title">Account Activated</div>
          <div class="card-head-sub">You are ready to sign in to TG-BASICS</div>
        </div>
      <?php elseif ($error && !$user): ?>
        <div class="card-head-icon danger"><?= icon('exclamation-triangle', 22) ?></div>
        <div class="card-head-text">
          <div class="card-head-title">Invalid Link</div>
          <div class="card-head-sub">This activation link cannot be used</div>
        </div>
      <?php else: ?>
        <div class="card-head-icon default"><?= icon('lock-closed', 22) ?></div>
        <div class="card-head-text">
          <div class="card-head-title">Set Your Password</div>
          <div class="card-head-sub">TG Customworks &amp; Basic Car Insurance</div>
        </div>
      <?php endif; ?>
    </div>

    <div class="card-body">

      <?php if ($success): ?>
        <div class="success-icon-wrap"><?= icon('check-circle', 32) ?></div>
        <p style="text-align:center;font-size:0.88rem;color:var(--text-secondary);line-height:1.7;margin-bottom:1.5rem;">
          Your account has been activated successfully.<br/>
          <strong style="color:var(--text-primary);">Welcome to TG-BASICS.</strong>
        </p>
        <a href="login.php" class="btn-submit">
          <?= icon('lock-closed', 14) ?> Sign In to TG-BASICS
        </a>

      <?php elseif ($error && !$user): ?>
        <div class="alert alert-danger">
          <?= icon('exclamation-triangle', 14) ?> <?= htmlspecialchars($error) ?>
        </div>
        <a href="login.php" class="btn-ghost-link">
          <?= icon('arrow-left', 14) ?> Back to Login
        </a>

      <?php else: ?>
        <div class="steps">
          <div class="step-dot done"></div>
          <div class="step-dot active"></div>
          <div class="step-dot"></div>
        </div>

        <?php if ($error): ?>
        <div class="alert alert-danger">
          <?= icon('exclamation-triangle', 14) ?> <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <div class="welcome-msg">
          <div class="welcome-avatar"><?= strtoupper(substr($user['full_name'], 0, 1)) ?></div>
          <div class="welcome-text">
            Hi <strong><?= htmlspecialchars($user['full_name']) ?></strong>, your account has been created by the administrator. Set a password below to activate your access.
          </div>
        </div>

        <form method="POST" action="">
          <div class="field">
            <label class="field-label">New Password</label>
            <div class="field-input-wrap">
              <span class="field-icon"><?= icon('lock-closed', 14) ?></span>
              <input type="password" name="new_password" id="new_password" class="field-input" placeholder="Minimum 8 characters" required/>
            </div>
            <div class="pw-strength"><div class="pw-strength-bar" id="pw-bar"></div></div>
            <span class="field-hint" id="pw-hint">At least 8 characters.</span>
          </div>
          <div class="field">
            <label class="field-label">Confirm Password</label>
            <div class="field-input-wrap">
              <span class="field-icon"><?= icon('lock-closed', 14) ?></span>
              <input type="password" name="confirm_password" class="field-input" placeholder="Re-enter your password" required/>
            </div>
          </div>
          <button type="submit" class="btn-submit">
            <?= icon('check-circle', 14) ?> Activate My Account
          </button>
        </form>
      <?php endif; ?>

    </div>

    <div class="card-foot">
      <span>Need help? Contact <strong>Gerald Peterson Carpio</strong> &mdash; TG Customworks</span>
      <a href="login.php"><?= icon('arrow-left', 12) ?> Back to Login</a>
    </div>

  </div>

</div>

<script src="../assets/js/activate.js"></script>

</body>
</html>