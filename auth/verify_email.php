<?php
session_start();
require_once '../config/db.php';
require_once '../config/settings.php';
require_once '../includes/icons.php';

$status  = 'invalid';
$message = '';
$token   = $_GET['token'] ?? '';

if ($token !== '') {
    $stmt = $conn->prepare("
        SELECT v.id, v.user_id, v.new_email, u.full_name
        FROM email_verifications v
        JOIN users u ON u.user_id = v.user_id
        WHERE v.token = ? AND v.used = 0 AND v.expires_at > NOW()
        LIMIT 1
    ");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if ($row) {
        // Check email isn't already taken by another user
        $dup = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
        $dup->bind_param('si', $row['new_email'], $row['user_id']);
        $dup->execute();

        if ($dup->get_result()->num_rows > 0) {
            $status  = 'error';
            $message = 'This email address is now used by another account. Please try a different email.';
        } else {
            // Update user's email
            $upd = $conn->prepare("UPDATE users SET email = ? WHERE user_id = ?");
            $upd->bind_param('si', $row['new_email'], $row['user_id']);
            $upd->execute();

            // Mark token as used
            $mark = $conn->prepare("UPDATE email_verifications SET used = 1 WHERE id = ?");
            $mark->bind_param('i', $row['id']);
            $mark->execute();

            // Audit log
            $log  = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'EMAIL_VERIFIED', ?)");
            $desc = $row['full_name'] . ' verified new email address: ' . $row['new_email'];
            $log->bind_param('is', $row['user_id'], $desc);
            $log->execute();

            $status  = 'success';
            $message = 'Your email address has been updated to <strong>' . htmlspecialchars($row['new_email']) . '</strong>.';
        }
    } else {
        $status  = 'expired';
        $message = 'This verification link is invalid or has expired. Please request a new email change from Settings.';
    }
} else {
    $message = 'No verification token provided.';
}

$icon_class = $status === 'success' ? 'success' : ($status === 'error' ? 'danger' : 'default');
$heading    = $status === 'success' ? 'Email Verified' : ($status === 'error' ? 'Verification Failed' : 'Link Expired');
$sub        = $status === 'success' ? 'Your email has been updated successfully' : 'Unable to verify your email';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<meta name="format-detection" content="telephone=no, date=no, email=no, address=no"/>
<title>Verify Email | TG-BASICS</title>
<link rel="icon" type="image/png" href="../assets/img/tg_logo.png"/>
<link rel="apple-touch-icon" href="../assets/img/tg_logo.png"/>
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
    <div class="brand-tag">Email Verification</div>
  </div>

  <div class="card">
    <div class="card-head">
      <div class="card-head-icon <?= $icon_class ?>">
        <?= $status === 'success' ? icon('check-circle', 22) : icon('exclamation-triangle', 22) ?>
      </div>
      <div class="card-head-text">
        <div class="card-head-title"><?= $heading ?></div>
        <div class="card-head-sub"><?= $sub ?></div>
      </div>
    </div>

    <div class="card-body" style="text-align:center;">
      <?php if ($status === 'success'): ?>
      <div class="success-icon-wrap">
        <?= icon('check-circle', 28) ?>
      </div>
      <p style="font-size:0.9rem;color:var(--text-secondary);line-height:1.7;margin-bottom:1.5rem;">
        <?= $message ?>
      </p>
      <?php else: ?>
      <div style="background:var(--danger-bg);border:1px solid var(--danger-border);border-radius:10px;padding:1rem 1.25rem;margin-bottom:1.5rem;text-align:left;">
        <p style="font-size:0.85rem;color:var(--danger);line-height:1.6;margin:0;">
          <?= icon('exclamation-triangle', 14) ?> <?= $message ?>
        </p>
      </div>
      <?php endif; ?>

      <?php if (isset($_SESSION['user_id'])): ?>
      <a href="../modules/settings.php" class="btn-submit">
        <?= icon('cog', 14) ?> Go to Settings
      </a>
      <?php else: ?>
      <a href="login.php" class="btn-submit">
        <?= icon('arrow-right', 14) ?> Go to Login
      </a>
      <?php endif; ?>
    </div>
  </div>
</div>

</body>
</html>
