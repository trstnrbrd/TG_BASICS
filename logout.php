<?php
session_start();
require_once 'config/db.php';
require_once 'includes/icons.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_logout'])) {
    session_unset();
    session_destroy();
    header("Location: index.php");
    exit;
}

// Grab name before we potentially destroy session
$full_name = $_SESSION['full_name'] ?? 'User';
$role      = $_SESSION['role'] ?? 'admin';
$first     = explode(' ', $full_name)[0];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Logging Out | TG-BASICS</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
<style>
  :root {
    --gold: #B8860B;
    --gold-bright: #D4A017;
    --gold-light: #F5E6C0;
    --gold-muted: #E8D5A3;
    --bg: #F4F1EC;
    --bg-3: #FFFFFF;
    --sidebar-bg: #1C1A17;
    --text-primary: #1A1814;
    --text-secondary: #5C5648;
    --text-muted: #9C9286;
    --border: #E2D9CC;
    --shadow-lg: 0 12px 40px rgba(0,0,0,0.12), 0 4px 12px rgba(0,0,0,0.06);
    --danger: #C0392B;
    --danger-bg: #FDF2F2;
    --danger-border: rgba(192,57,43,0.2);
  }

  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    background: var(--bg);
    font-family: 'Plus Jakarta Sans', sans-serif;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    overflow: hidden;
  }

  body::before {
    content: '';
    position: fixed;
    inset: 0;
    background-image:
      linear-gradient(var(--border) 1px, transparent 1px),
      linear-gradient(90deg, var(--border) 1px, transparent 1px);
    background-size: 40px 40px;
    opacity: 0.4;
    pointer-events: none;
    mask-image: radial-gradient(ellipse 70% 70% at 50% 50%, black 20%, transparent 100%);
  }

  .card {
    background: var(--bg-3);
    border: 1px solid var(--border);
    border-radius: 18px;
    box-shadow: var(--shadow-lg);
    overflow: hidden;
    width: 100%;
    max-width: 400px;
    margin: 1.5rem;
    position: relative;
    z-index: 2;
    animation: popIn 0.4s cubic-bezier(0.34, 1.56, 0.64, 1) both;
  }

  @keyframes popIn {
    from { opacity: 0; transform: scale(0.92) translateY(12px); }
    to   { opacity: 1; transform: scale(1) translateY(0); }
  }

  .card-top {
    background: var(--sidebar-bg);
    padding: 2rem 2rem 1.75rem;
    text-align: center;
    position: relative;
    overflow: hidden;
  }

  .card-top::after {
    content: '';
    position: absolute;
    bottom: 0; left: 0; right: 0;
    height: 2px;
    background: linear-gradient(90deg, transparent, var(--gold-bright), transparent);
  }

  .logout-icon-wrap {
    width: 64px; height: 64px;
    border-radius: 50%;
    background: rgba(192,57,43,0.12);
    border: 1.5px solid rgba(192,57,43,0.25);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.6rem;
    margin: 0 auto 1rem;
  }

  .card-top-title {
    font-size: 1.1rem;
    font-weight: 800;
    color: #fff;
    margin-bottom: 0.25rem;
    letter-spacing: -0.2px;
  }

  .card-top-sub {
    font-size: 0.75rem;
    color: rgba(200,192,176,0.5);
  }

  .card-top-sub span { color: var(--gold-bright); font-weight: 600; }

  .card-body {
    padding: 1.75rem 2rem;
  }

  .confirm-text {
    font-size: 0.85rem;
    color: var(--text-secondary);
    line-height: 1.7;
    text-align: center;
    margin-bottom: 1.5rem;
  }

  .confirm-text strong { color: var(--text-primary); font-weight: 700; }

  .btn-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.75rem;
  }

  .btn-cancel {
    background: var(--bg);
    color: var(--text-secondary);
    border: 1px solid var(--border);
    padding: 0.8rem;
    border-radius: 10px;
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 0.82rem;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    text-align: center;
    transition: all 0.15s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.4rem;
  }

  .btn-cancel:hover {
    border-color: var(--gold-muted);
    color: var(--gold);
    background: #FDF8EE;
  }

  .btn-confirm {
    background: var(--danger);
    color: #fff;
    border: none;
    padding: 0.8rem;
    border-radius: 10px;
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 0.82rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.15s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.4rem;
    box-shadow: 0 4px 12px rgba(192,57,43,0.25);
  }

  .btn-confirm:hover {
    background: #A93226;
    box-shadow: 0 6px 16px rgba(192,57,43,0.35);
    transform: translateY(-1px);
  }

  .card-footer {
    padding: 0.9rem 2rem;
    border-top: 1px solid var(--border);
    background: #FAFAF8;
    text-align: center;
    font-size: 0.68rem;
    color: var(--text-muted);
  }
</style>
</head>
<body>

<div class="card">
  <div class="card-top">
    <div class="logout-icon-wrap"><?= icon('lock-closed', 28) ?></div>
    <div class="card-top-title">Sign Out</div>
    <div class="card-top-sub">
      Logged in as <span><?= htmlspecialchars($first) ?></span>
    </div>
  </div>

  <div class="card-body">
    <p class="confirm-text">
      Are you sure you want to sign out of <strong>TG-BASICS</strong>?
      Your session will be ended and you will need to sign in again to continue.
    </p>

    <div class="btn-row">
      <?php
        $back = ($role === 'mechanic')
          ? 'modules/repair/dashboard_mechanic.php'
          : 'modules/dashboard_admin.php';
      ?>
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

  <div class="card-footer">
    TG-BASICS &mdash; Internal Use Only
  </div>
</div>

</body>
</html>