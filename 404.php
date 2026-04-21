<?php
http_response_code(404);
require_once __DIR__ . '/includes/icons.php';

// Determine back link
$base = '/TG-BASICS/';
require_once __DIR__ . '/config/session.php';
$logged_in = isset($_SESSION['user_id']);
$role = $_SESSION['role'] ?? '';
if ($logged_in) {
    if ($role === 'mechanic') {
        $back_href = $base . 'modules/repair/dashboard_mechanic.php';
    } else {
        $back_href = $base . 'modules/admin/dashboard_admin.php';
    }
    $back_label = 'Back to Dashboard';
} else {
    $back_href  = $base . 'auth/login.php';
    $back_label = 'Go to Login';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>404 — Page Not Found | TG-BASICS</title>
<link rel="icon" type="image/png" href="<?= $base ?>assets/img/tg_logo.png"/>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="<?= $base ?>assets/css/404.css?v=<?= filemtime(__DIR__.'/assets/css/404.css') ?>"/>
</head>
<body>

<a href="<?= $base ?>" class="brand">
  <img src="<?= $base ?>assets/img/tg_logo.png" alt="TG"/>
  <div class="brand-sep"></div>
  <img src="<?= $base ?>assets/img/LogoBasicCar.png" alt="Basic Car Insurance" style="width:28px;height:28px;border-radius:6px;"/>
  <div class="brand-name">TG<span>-BASICS</span></div>
</a>

<div class="error-code">404</div>

<div class="error-icon">
  <?= icon('exclamation-triangle', 28) ?>
</div>

<h1 class="error-title">Page Not Found</h1>
<p class="error-sub">The page you're looking for doesn't exist or may have been moved. Check the URL or go back to where you came from.</p>

<div class="actions">
  <a href="<?= htmlspecialchars($back_href) ?>" class="btn-primary">
    <?= icon('arrow-left', 14) ?> <?= htmlspecialchars($back_label) ?>
  </a>
  <a href="<?= $base ?>" class="btn-ghost">
    <?= icon('arrow-left', 14) ?> Landing Page
  </a>
</div>

<div class="footer-note">TG-BASICS &mdash; TG Customworks &amp; Basic Car Insurance</div>

</body>
</html>
