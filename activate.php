<?php
session_start();
require_once 'config/db.php';
require_once 'includes/icons.php';

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
<title>Activate Account | TG-BASICS</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
<style>
  :root {
    --gold: #B8860B;
    --gold-bright: #D4A017;
    --gold-light: #F5E6C0;
    --gold-muted: #E8D5A3;
    --gold-pale: #FDF8EE;
    --bg: #F4F1EC;
    --bg-2: #FAFAF8;
    --bg-3: #FFFFFF;
    --sidebar-bg: #1C1A17;
    --text-primary: #1A1814;
    --text-secondary: #5C5648;
    --text-muted: #9C9286;
    --border: #E2D9CC;
    --border-focus: #D4A017;
    --shadow-md: 0 4px 16px rgba(0,0,0,0.08), 0 2px 6px rgba(0,0,0,0.04);
    --shadow-lg: 0 12px 40px rgba(0,0,0,0.12), 0 4px 12px rgba(0,0,0,0.06);
    --danger: #C0392B;
    --danger-bg: #FDF2F2;
    --danger-border: rgba(192,57,43,0.2);
    --success: #2E7D52;
    --success-bg: #F0FAF4;
    --success-border: rgba(46,125,82,0.2);
  }

  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    background: var(--bg);
    color: var(--text-primary);
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 14px;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    overflow: hidden;
  }

  body::before {
    content: '';
    position: fixed; inset: 0;
    background-image:
      linear-gradient(var(--border) 1px, transparent 1px),
      linear-gradient(90deg, var(--border) 1px, transparent 1px);
    background-size: 40px 40px;
    opacity: 0.45;
    pointer-events: none;
    mask-image: radial-gradient(ellipse 80% 80% at 50% 50%, black 30%, transparent 100%);
  }

  body::after {
    content: '';
    position: fixed; inset: 0;
    background:
      radial-gradient(circle at 30% 20%, rgba(212,160,23,0.06) 0%, transparent 50%),
      radial-gradient(circle at 75% 80%, rgba(212,160,23,0.04) 0%, transparent 45%);
    pointer-events: none;
  }

  .wrap {
    width: 100%;
    max-width: 440px;
    padding: 1.5rem;
    position: relative;
    z-index: 2;
  }

  /* BRAND */
  .brand {
    display: flex;
    flex-direction: column;
    align-items: center;
    margin-bottom: 1.75rem;
    animation: fadeDown 0.5s ease both;
  }

  .brand-logo-ring {
    width: 72px; height: 72px;
    border-radius: 50%;
    border: 2px solid var(--gold-bright);
    overflow: hidden;
    margin-bottom: 1rem;
    box-shadow: 0 0 0 6px var(--gold-light), var(--shadow-md);
  }

  .brand-logo-ring img { width: 100%; height: 100%; object-fit: cover; }

  .brand-name {
    font-size: 1.4rem;
    font-weight: 800;
    color: var(--text-primary);
    letter-spacing: -0.3px;
    margin-bottom: 0.2rem;
  }

  .brand-name span { color: var(--gold-bright); }

  .brand-tag {
    font-size: 0.65rem;
    color: var(--text-muted);
    letter-spacing: 2px;
    text-transform: uppercase;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 0.5rem;
  }

  .brand-tag::before,
  .brand-tag::after {
    content: '';
    display: block;
    width: 20px; height: 1px;
    background: var(--gold-muted);
  }

  /* CARD */
  .card {
    background: var(--bg-3);
    border: 1px solid var(--border);
    border-radius: 16px;
    box-shadow: var(--shadow-lg);
    overflow: hidden;
    animation: fadeUp 0.5s ease 0.1s both;
  }

  /* CARD HEAD */
  .card-head {
    background: var(--sidebar-bg);
    padding: 1.5rem 1.75rem;
    position: relative;
    overflow: hidden;
    display: flex;
    align-items: center;
    gap: 1rem;
  }

  .card-head::after {
    content: '';
    position: absolute;
    bottom: 0; left: 0; right: 0; height: 2px;
    background: linear-gradient(90deg, var(--gold-bright), var(--gold-muted), transparent);
  }

  .card-head::before {
    content: 'TG';
    position: absolute;
    right: 1.5rem; top: 50%;
    transform: translateY(-50%);
    font-size: 4rem;
    font-weight: 800;
    color: rgba(212,160,23,0.06);
    letter-spacing: -2px;
    pointer-events: none;
  }

  .card-head-icon {
    width: 44px; height: 44px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
    position: relative; z-index: 1;
  }

  .card-head-icon.success { background: rgba(46,125,82,0.15);  color: #52B788; }
  .card-head-icon.danger  { background: rgba(192,57,43,0.15);   color: #E74C3C; }
  .card-head-icon.default { background: rgba(212,160,23,0.12);  color: var(--gold-bright); }

  .card-head-text { position: relative; z-index: 1; }
  .card-head-title { font-size: 1rem; font-weight: 700; color: #fff; margin-bottom: 0.15rem; }
  .card-head-sub   { font-size: 0.72rem; color: rgba(200,192,176,0.45); }

  /* CARD BODY */
  .card-body { padding: 1.75rem; }

  /* STEPS */
  .steps {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    margin-bottom: 1.5rem;
  }

  .step-dot {
    width: 8px; height: 8px;
    border-radius: 50%;
    background: var(--border);
    transition: all 0.2s;
  }

  .step-dot.active { background: var(--gold-bright); width: 20px; border-radius: 4px; }
  .step-dot.done   { background: var(--success); }

  /* WELCOME */
  .welcome-msg {
    background: var(--gold-pale);
    border: 1px solid var(--gold-muted);
    border-radius: 10px;
    padding: 1rem 1.1rem;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
  }

  .welcome-avatar {
    width: 36px; height: 36px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--gold-bright), var(--gold));
    display: flex; align-items: center; justify-content: center;
    font-size: 0.75rem;
    font-weight: 800;
    color: #fff;
    flex-shrink: 0;
  }

  .welcome-text { font-size: 0.82rem; color: var(--text-secondary); line-height: 1.6; }
  .welcome-text strong { color: var(--text-primary); font-weight: 700; }

  /* FIELDS */
  .field { display: flex; flex-direction: column; gap: 0.4rem; margin-bottom: 1rem; }
  .field-label { font-size: 0.72rem; font-weight: 600; color: var(--text-secondary); }
  .field-input-wrap { position: relative; }
  .field-icon { position: absolute; left: 0.85rem; top: 50%; transform: translateY(-50%); color: var(--text-muted); pointer-events: none; display: flex; align-items: center; }

  .field-input {
    width: 100%;
    background: var(--bg);
    border: 1px solid var(--border);
    color: var(--text-primary);
    padding: 0.7rem 0.9rem 0.7rem 2.5rem;
    border-radius: 9px;
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 0.85rem;
    outline: none;
    transition: border-color 0.15s, box-shadow 0.15s, background 0.15s;
  }

  .field-input:focus { border-color: var(--border-focus); box-shadow: 0 0 0 3px rgba(212,160,23,0.1); background: var(--bg-3); }
  .field-input::placeholder { color: var(--text-muted); font-size: 0.82rem; }
  .field-hint { font-size: 0.67rem; color: var(--text-muted); }

  .pw-strength { height: 3px; border-radius: 2px; background: var(--border); margin-top: 0.4rem; overflow: hidden; }
  .pw-strength-bar { height: 100%; border-radius: 2px; transition: width 0.3s ease, background 0.3s ease; width: 0%; }

  /* BUTTONS */
  .btn-submit {
    width: 100%;
    background: var(--sidebar-bg);
    color: var(--gold-bright);
    border: none;
    padding: 0.875rem;
    border-radius: 9px;
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 0.85rem;
    font-weight: 700;
    cursor: pointer;
    transition: background 0.15s, box-shadow 0.15s, transform 0.15s;
    box-shadow: 0 4px 14px rgba(28,26,23,0.25);
    margin-top: 0.5rem;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    text-decoration: none;
  }

  .btn-submit:hover { background: #2A2724; box-shadow: 0 6px 20px rgba(28,26,23,0.35); transform: translateY(-1px); }

  .btn-ghost-link {
    width: 100%;
    background: var(--bg);
    color: var(--text-secondary);
    border: 1px solid var(--border);
    padding: 0.75rem;
    border-radius: 9px;
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 0.83rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.15s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    text-decoration: none;
    margin-top: 0.6rem;
  }

  .btn-ghost-link:hover { border-color: var(--gold-muted); color: var(--gold); background: var(--gold-pale); }

  /* ALERTS */
  .alert { padding: 0.85rem 1rem; border-radius: 9px; font-size: 0.8rem; font-weight: 500; margin-bottom: 1.25rem; display: flex; align-items: flex-start; gap: 0.6rem; line-height: 1.5; }
  .alert-danger  { background: var(--danger-bg);  border: 1px solid var(--danger-border);  color: var(--danger); }
  .alert-success { background: var(--success-bg); border: 1px solid var(--success-border); color: var(--success); }

  /* SUCCESS STATE */
  .success-icon-wrap {
    width: 64px; height: 64px;
    border-radius: 50%;
    background: var(--success-bg);
    border: 2px solid var(--success-border);
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 1.25rem;
    color: var(--success);
  }

  /* CARD FOOT */
  .card-foot {
    text-align: center;
    font-size: 0.7rem;
    color: var(--text-muted);
    padding: 1rem 1.75rem 1.25rem;
    border-top: 1px solid var(--border);
    background: var(--bg-2);
    line-height: 1.7;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.35rem;
  }

  .card-foot a {
    color: var(--gold);
    text-decoration: none;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    font-size: 0.72rem;
    transition: color 0.15s;
  }

  .card-foot a:hover { color: var(--gold-bright); }

  @keyframes fadeUp   { from { opacity: 0; transform: translateY(16px); }  to { opacity: 1; transform: translateY(0); } }
  @keyframes fadeDown { from { opacity: 0; transform: translateY(-12px); } to { opacity: 1; transform: translateY(0); } }

  ::-webkit-scrollbar { width: 4px; }
  ::-webkit-scrollbar-track { background: var(--bg); }
  ::-webkit-scrollbar-thumb { background: var(--gold-muted); border-radius: 2px; }
</style>
</head>
<body>
<div class="wrap">

  <!-- BRAND -->
  <div class="brand">
    <div class="brand-logo-ring">
      <img src="assets/img/tg_logo.png" alt="TG Customworks"/>
    </div>
    <div class="brand-name">TG<span>-BASICS</span></div>
    <div class="brand-tag">Account Activation</div>
  </div>

  <!-- CARD -->
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
          <div class="card-head-sub">TG Customworks &amp; Basic Car Insurance Services</div>
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

<script>
  const pwInput = document.getElementById('new_password');
  const pwBar   = document.getElementById('pw-bar');
  const pwHint  = document.getElementById('pw-hint');

  if (pwInput) {
    pwInput.addEventListener('input', function () {
      const val = this.value;
      let strength = 0;
      if (val.length >= 8)             strength++;
      if (val.length >= 12)            strength++;
      if (/[A-Z]/.test(val))           strength++;
      if (/[0-9]/.test(val))           strength++;
      if (/[^A-Za-z0-9]/.test(val))    strength++;

      const levels = [
        { w: '0%',   bg: 'transparent', label: 'At least 8 characters.' },
        { w: '25%',  bg: '#E74C3C',     label: 'Weak' },
        { w: '50%',  bg: '#E67E22',     label: 'Fair' },
        { w: '75%',  bg: '#F1C40F',     label: 'Good' },
        { w: '100%', bg: '#2ECC71',     label: 'Strong' },
      ];

      const level = val.length === 0 ? 0 : Math.min(strength, 4);
      pwBar.style.width      = levels[level].w;
      pwBar.style.background = levels[level].bg;
      pwHint.textContent     = val.length === 0 ? 'At least 8 characters.' : levels[level].label;
    });
  }
</script>

</body>
</html>