<?php
require_once __DIR__ . "/../config/session.php";
require_once '../config/db.php';
require_once '../config/totp.php';
require_once '../config/rate_limit.php';
require_once '../includes/icons.php';

// Must have a pending TOTP session
if (!isset($_SESSION['totp_user_id'])) {
    header("Location: login.php");
    exit;
}

$pending_uid  = $_SESSION['totp_user_id'];
$pending_name = $_SESSION['totp_full_name'];
$pending_role = $_SESSION['totp_role'];
$pending_user = $_SESSION['totp_username'];
$error = '';

if (isset($_GET['action']) && $_GET['action'] === 'cancel') {
    unset($_SESSION['totp_user_id'], $_SESSION['totp_full_name'], $_SESSION['totp_role'], $_SESSION['totp_username']);
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rate_limit_check($conn, 'verify_totp');
    $raw    = trim($_POST['code'] ?? '');
    $is_recovery = strlen($raw) > 6; // recovery codes are longer

    if ($is_recovery) {
        // Check against recovery codes
        $s = $conn->prepare("SELECT totp_recovery_codes FROM users WHERE user_id = ?");
        $s->bind_param('i', $pending_uid); $s->execute();
        $row = $s->get_result()->fetch_assoc();
        $codes = json_decode($row['totp_recovery_codes'] ?? '[]', true);
        $matched_idx = null;
        foreach ($codes as $i => $hashed) {
            if (password_verify($raw, $hashed)) { $matched_idx = $i; break; }
        }
        if ($matched_idx === null) {
            $error = 'Invalid recovery code.';
            rate_limit_record($conn, 'verify_totp');
        } else {
            // Remove used code
            array_splice($codes, $matched_idx, 1);
            $encoded = json_encode($codes);
            $u = $conn->prepare("UPDATE users SET totp_recovery_codes = ? WHERE user_id = ?");
            $u->bind_param('si', $encoded, $pending_uid); $u->execute();
            goto login_success;
        }
    } else {
        // TOTP code
        $s = $conn->prepare("SELECT totp_secret FROM users WHERE user_id = ?");
        $s->bind_param('i', $pending_uid); $s->execute();
        $row = $s->get_result()->fetch_assoc();

        if (!$row || !totp_verify($row['totp_secret'], $raw)) {
            $error = 'Invalid code. Make sure your authenticator app is in sync.';
            rate_limit_record($conn, 'verify_totp');
        } else {
            goto login_success;
        }
    }

    goto render;

    login_success:
    rate_limit_clear($conn, 'verify_totp');
    session_regenerate_id(true);

    $log  = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'LOGIN', ?)");
    $desc = $pending_name . ' logged in (Authenticator 2FA verified).';
    $log->bind_param('is', $pending_uid, $desc); $log->execute();

    $la = $conn->prepare("UPDATE users SET last_active = NOW() WHERE user_id = ?");
    $la->bind_param('i', $pending_uid); $la->execute();

    $_SESSION['user_id']   = $pending_uid;
    $_SESSION['username']  = $pending_user;
    $_SESSION['role']      = $pending_role;
    $_SESSION['full_name'] = $pending_name;
    unset($_SESSION['totp_user_id'], $_SESSION['totp_full_name'], $_SESSION['totp_role'], $_SESSION['totp_username']);

    if ($pending_role === 'mechanic') {
        header("Location: ../modules/repair/dashboard_mechanic.php");
    } else {
        header("Location: ../modules/admin/dashboard_admin.php");
    }
    exit;
}

render:
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Authenticator Verification | TG-BASICS</title>
<link rel="icon" type="image/png" href="../assets/img/tg_logo.png"/>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="../assets/css/auth/activate.css?v=<?= filemtime(__DIR__.'/../assets/css/auth/activate.css') ?>"/>
<style>
  .code-inputs { display:flex; gap:0.5rem; justify-content:center; margin-bottom:1.25rem; }
  .code-inputs input {
    width:48px; height:56px; text-align:center; font-size:1.4rem; font-weight:800;
    font-family:'Plus Jakarta Sans',monospace; border:2px solid var(--border);
    border-radius:10px; background:var(--bg); color:var(--text-primary); outline:none;
    transition:border-color 0.15s, box-shadow 0.15s;
  }
  .code-inputs input:focus { border-color:var(--gold-bright); box-shadow:0 0 0 3px rgba(212,160,23,0.15); background:var(--bg-3); }
  .recovery-toggle { text-align:center; font-size:0.78rem; color:var(--text-muted); margin-top:0.75rem; }
  .recovery-toggle a { color:var(--gold); text-decoration:none; font-weight:600; cursor:pointer; }
  .recovery-toggle a:hover { color:var(--gold-bright); }
</style>
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
    <div class="brand-tag">Authenticator Verification</div>
  </div>

  <div class="card">
    <div class="card-head">
      <div class="card-head-icon default"><?= icon('shield-check', 22) ?></div>
      <div class="card-head-text">
        <div class="card-head-title">Authenticator Code</div>
        <div class="card-head-sub" id="form-subtitle">Enter the 6-digit code from your authenticator app</div>
      </div>
    </div>

    <div class="card-body">
      <?php if ($error): ?>
      <div class="alert alert-danger"><?= icon('exclamation-triangle', 14) ?> <span><?= htmlspecialchars($error) ?></span></div>
      <?php endif; ?>

      <div class="welcome-msg">
        <div class="welcome-avatar"><?= substr($pending_name, 0, 1) ?></div>
        <div class="welcome-text">Hi <strong><?= htmlspecialchars($pending_name) ?></strong>, open your authenticator app and enter the code shown for TG-BASICS.</div>
      </div>

      <!-- TOTP form -->
      <form method="POST" action="" id="totp-form">
        <input type="hidden" name="code" id="code-hidden"/>
        <div class="code-inputs" id="code-inputs">
          <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" autocomplete="off"/>
          <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" autocomplete="off"/>
          <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" autocomplete="off"/>
          <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" autocomplete="off"/>
          <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" autocomplete="off"/>
          <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" autocomplete="off"/>
        </div>
        <button type="submit" class="btn-submit"><?= icon('shield-check', 14) ?> Verify & Sign In</button>
      </form>

      <!-- Recovery code form (hidden by default) -->
      <form method="POST" action="" id="recovery-form" style="display:none;">
        <div class="field" style="margin-bottom:1rem;">
          <label class="field-label">Recovery Code</label>
          <input type="text" name="code" class="field-input" placeholder="XXXXXXXX-XXXXXXXX" autocomplete="off" style="font-family:monospace;letter-spacing:1px;"/>
        </div>
        <button type="submit" class="btn-submit"><?= icon('key', 14) ?> Use Recovery Code</button>
      </form>

      <div class="recovery-toggle">
        <a id="recovery-toggle-link" onclick="toggleRecovery()">Use a recovery code instead</a>
      </div>
    </div>

    <div class="card-foot">
      <span>Code refreshes every 30 seconds</span>
      <a href="verify_totp.php?action=cancel"><?= icon('arrow-left', 12) ?> Back to Login</a>
    </div>
  </div>
</div>

<script>
(function() {
  const inputs  = document.querySelectorAll('#code-inputs input');
  const hidden  = document.getElementById('code-hidden');
  const form    = document.getElementById('totp-form');
  let recovery  = false;

  function updateHidden() {
    hidden.value = Array.from(inputs).map(i => i.value).join('');
  }

  inputs.forEach((input, idx) => {
    input.addEventListener('input', e => {
      e.target.value = e.target.value.replace(/\D/g, '');
      if (e.target.value && idx < inputs.length - 1) inputs[idx+1].focus();
      updateHidden();
      if (hidden.value.length === 6) form.submit();
    });
    input.addEventListener('keydown', e => {
      if (e.key === 'Backspace' && !input.value && idx > 0) inputs[idx-1].focus();
    });
    input.addEventListener('paste', e => {
      e.preventDefault();
      const paste = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g,'').slice(0,6);
      paste.split('').forEach((ch, i) => { if (inputs[i]) inputs[i].value = ch; });
      updateHidden();
      if (paste.length === 6) { inputs[5].focus(); form.submit(); }
      else if (inputs[paste.length]) inputs[paste.length].focus();
    });
  });
  inputs[0].focus();

  window.toggleRecovery = function() {
    recovery = !recovery;
    document.getElementById('totp-form').style.display      = recovery ? 'none' : 'block';
    document.getElementById('recovery-form').style.display  = recovery ? 'block' : 'none';
    document.getElementById('form-subtitle').textContent    = recovery
      ? 'Enter one of your 8-character recovery codes'
      : 'Enter the 6-digit code from your authenticator app';
    document.getElementById('recovery-toggle-link').textContent = recovery
      ? 'Use authenticator app instead'
      : 'Use a recovery code instead';
    if (!recovery) inputs[0].focus();
    else document.querySelector('#recovery-form input[name=code]').focus();
  };
})();
</script>
</body>
</html>
