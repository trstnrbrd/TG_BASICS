<?php
session_start();
require_once 'config/db.php';
require_once 'includes/icons.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_logout'])) {
    if (isset($_SESSION['user_id'])) {
        $log  = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'LOGOUT', ?)");
        $desc = ($_SESSION['full_name'] ?? 'Unknown') . ' logged out.';
        $uid  = $_SESSION['user_id'];
        $log->bind_param('is', $uid, $desc);
        $log->execute();
    }
    session_unset();
    session_destroy();
    header("Location: index.php");
    exit;
}

$full_name = $_SESSION['full_name'] ?? 'User';
$role      = $_SESSION['role'] ?? 'admin';
$first     = explode(' ', $full_name)[0];
$back      = ($role === 'mechanic') ? 'modules/repair/dashboard_mechanic.php' : 'modules/dashboard_admin.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Logging Out | TG-BASICS</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="assets/css/logout.css"/>
</head>
<body>

<div class="card">
  <div class="card-top">
    <div class="logout-icon-wrap"><?= icon('lock-closed', 28) ?></div>
    <div class="card-top-title">Sign Out</div>
    <div class="card-top-sub">Logged in as <span><?= htmlspecialchars($first) ?></span></div>
  </div>

  <div class="card-body">
    <p class="confirm-text">
      Are you sure you want to sign out of <strong>TG-BASICS</strong>?
      Your session will be ended and you will need to sign in again to continue.
    </p>
    <div class="btn-row">
      <a href="<?= $back ?>" class="btn-cancel">
        <?= icon('arrow-left', 14) ?> Stay
      </a>
      <form method="POST" action="logout.php" style="display:contents;">
        <button type="submit" name="confirm_logout" class="btn-confirm">
          <?= icon('lock-closed', 14) ?> Yes, Sign Out
        </button>
      </form>
    </div>
  </div>

  <div class="card-footer">TG-BASICS &mdash; Internal Use Only</div>
</div>

</body>
</html>