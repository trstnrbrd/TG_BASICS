<?php
session_start();
require_once '../config/db.php';
require_once '../config/settings.php';
require_once '../config/mailer.php';
require_once '../includes/icons.php';

if (isset($_SESSION['user_id'])) {
    header("Location: ../modules/dashboard_admin.php");
    exit;
}

$sent  = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $stmt = $conn->prepare("SELECT user_id, full_name, email FROM users WHERE email = ? AND is_active = 1");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if ($user) {
            // Invalidate any existing unused tokens for this user
            $inv = $conn->prepare("UPDATE password_resets SET used = 1 WHERE user_id = ? AND used = 0");
            $inv->bind_param('i', $user['user_id']);
            $inv->execute();

            // Generate a new token
            $reset_expiry_hours = (int)getSetting($conn, 'reset_link_expiry', '1');
            $token      = bin2hex(random_bytes(32));
            $expires_at = date('Y-m-d H:i:s', strtotime('+' . $reset_expiry_hours . ' hours'));

            $ins = $conn->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)");
            $ins->bind_param('iss', $user['user_id'], $token, $expires_at);
            $ins->execute();

            $reset_link = 'http://localhost/tg-basics/auth/reset_password.php?token=' . $token;
            sendPasswordResetEmail($user['email'], $user['full_name'], $reset_link);
        }

        // Always show success — do not reveal whether the email exists
        $sent = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Forgot Password | TG-BASICS</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="../assets/css/activate.css"/>
</head>
<body>
<div class="wrap">

  <div class="brand">
    <div class="brand-logo-ring">
      <img src="../assets/img/tg_logo.png" alt="TG Customworks"/>
    </div>
    <div class="brand-name">TG<span>-BASICS</span></div>
    <div class="brand-tag">Password Recovery</div>
  </div>

  <div class="card">

    <div class="card-head">
      <?php if ($sent): ?>
        <div class="card-head-icon success"><?= icon('check-circle', 22) ?></div>
        <div class="card-head-text">
          <div class="card-head-title">Check Your Email</div>
          <div class="card-head-sub">A reset link has been sent if the account exists</div>
        </div>
      <?php else: ?>
        <div class="card-head-icon default"><?= icon('lock-closed', 22) ?></div>
        <div class="card-head-text">
          <div class="card-head-title">Forgot Password</div>
          <div class="card-head-sub">Enter your email to receive a reset link</div>
        </div>
      <?php endif; ?>
    </div>

    <div class="card-body">

      <?php if ($sent): ?>

        <div class="success-icon-wrap"><?= icon('envelope', 32) ?></div>
        <p style="text-align:center;font-size:0.88rem;color:var(--text-secondary);line-height:1.7;margin-bottom:1.5rem;">
          If that email is registered and active, a password reset link has been sent.<br/>
          <strong style="color:var(--text-primary);">Check your inbox and spam folder.</strong>
        </p>
        <div style="background:var(--gold-pale);border:1px solid var(--gold-muted);border-radius:10px;padding:0.85rem 1rem;font-size:0.78rem;color:var(--text-secondary);line-height:1.6;margin-bottom:1.25rem;display:flex;gap:0.6rem;align-items:flex-start;">
          <?= icon('information-circle', 14) ?>
          <?php $re = (int)getSetting($conn, 'reset_link_expiry', '1'); ?>
          <span>The link expires in <strong><?= $re ?> hour<?= $re != 1 ? 's' : '' ?></strong>. If it does not arrive, check your spam folder or try again.</span>
        </div>
        <a href="login.php" class="btn-submit">
          <?= icon('arrow-left', 14) ?> Back to Sign In
        </a>

      <?php else: ?>

        <?php if ($error): ?>
        <div class="alert alert-danger">
          <?= icon('exclamation-triangle', 14) ?> <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <p style="font-size:0.84rem;color:var(--text-secondary);line-height:1.7;margin-bottom:1.5rem;">
          Enter the email address associated with your account. We will send you a link to reset your password.
        </p>

        <form method="POST" action="">
          <div class="field">
            <label class="field-label">Email Address</label>
            <div class="field-input-wrap">
              <span class="field-icon"><?= icon('envelope', 14) ?></span>
              <input type="email" name="email" class="field-input"
                placeholder="Enter your registered email"
                value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                autocomplete="email" required autofocus/>
            </div>
          </div>
          <button type="submit" class="btn-submit">
            <?= icon('paper-airplane', 14) ?> Send Reset Link
          </button>
        </form>

        <a href="login.php" class="btn-ghost-link" style="margin-top:0.75rem;">
          <?= icon('arrow-left', 14) ?> Back to Sign In
        </a>

      <?php endif; ?>

    </div>

    <div class="card-foot">
      <span>Need help? Contact <strong>Gerald Peterson Carpio</strong> &mdash; TG Customworks</span>
    </div>

  </div>

</div>
</body>
</html>
