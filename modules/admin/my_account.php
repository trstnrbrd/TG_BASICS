<?php
require_once __DIR__ . '/../../config/session.php';
require_once '../../config/db.php';
require_once '../../config/validators.php';
require_once '../../config/totp.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../auth/login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// ── AJAX handlers ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    $submitted = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $submitted)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token.']); exit;
    }

    $action = $_POST['action'];

    // ── Change Password ──
    if ($action === 'change_password') {
        $cur  = $_POST['current_password'] ?? '';
        $new  = $_POST['new_password']     ?? '';
        $conf = $_POST['confirm_password'] ?? '';

        $s = $conn->prepare("SELECT password FROM users WHERE user_id = ?");
        $s->bind_param('i', $user_id); $s->execute();
        $row = $s->get_result()->fetch_assoc();

        if (!$row || !password_verify($cur, $row['password'])) {
            echo json_encode(['ok' => false, 'error' => 'Current password is incorrect.']); exit;
        }
        if (strlen($new) < 8) {
            echo json_encode(['ok' => false, 'error' => 'New password must be at least 8 characters.']); exit;
        }
        if ($new !== $conf) {
            echo json_encode(['ok' => false, 'error' => 'Passwords do not match.']); exit;
        }

        $hash = password_hash($new, PASSWORD_DEFAULT);
        $u = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
        $u->bind_param('si', $hash, $user_id); $u->execute();

        $log = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'PASSWORD_CHANGED', ?)");
        $desc = ($_SESSION['full_name'] ?? '') . ' changed their password.';
        $log->bind_param('is', $user_id, $desc); $log->execute();

        echo json_encode(['ok' => true]); exit;
    }

    // ── Change PIN ──
    if ($action === 'change_pin') {
        $pin  = $_POST['pin']          ?? '';
        $conf = $_POST['pin_confirm']  ?? '';

        if ($pin === '') {
            // Clear PIN
            $u = $conn->prepare("UPDATE users SET transaction_pin = NULL WHERE user_id = ?");
            $u->bind_param('i', $user_id); $u->execute();
            echo json_encode(['ok' => true, 'msg' => 'Transaction PIN removed.']); exit;
        }
        if (!ctype_digit($pin) || strlen($pin) < 4 || strlen($pin) > 6) {
            echo json_encode(['ok' => false, 'error' => 'PIN must be 4–6 digits.']); exit;
        }
        if ($pin !== $conf) {
            echo json_encode(['ok' => false, 'error' => 'PINs do not match.']); exit;
        }
        $hash = password_hash($pin, PASSWORD_DEFAULT);
        $u = $conn->prepare("UPDATE users SET transaction_pin = ? WHERE user_id = ?");
        $u->bind_param('si', $hash, $user_id); $u->execute();
        echo json_encode(['ok' => true, 'msg' => 'Transaction PIN updated.']); exit;
    }

    // ── Generate TOTP secret (step 1 of setup) ──
    if ($action === 'totp_generate') {
        $secret = totp_generate_secret();
        $_SESSION['totp_pending_secret'] = $secret;
        $label  = $_SESSION['username'] ?? 'user';
        echo json_encode(['ok' => true, 'secret' => $secret, 'uri' => totp_qr_uri($secret, $label)]); exit;
    }

    // ── Confirm TOTP setup (step 2 — verify code then save) ──
    if ($action === 'totp_confirm') {
        $code   = preg_replace('/\D/', '', $_POST['code'] ?? '');
        $secret = $_SESSION['totp_pending_secret'] ?? '';

        if (!$secret) {
            echo json_encode(['ok' => false, 'error' => 'No pending setup. Please start again.']); exit;
        }
        if (!totp_verify($secret, $code)) {
            echo json_encode(['ok' => false, 'error' => 'Incorrect code. Make sure your authenticator app time is in sync.']); exit;
        }

        // Generate recovery codes
        $plain_codes  = totp_generate_recovery_codes(8);
        $hashed_codes = array_map(fn($c) => password_hash($c, PASSWORD_DEFAULT), $plain_codes);

        $u = $conn->prepare("UPDATE users SET totp_secret = ?, totp_enabled = 1, totp_recovery_codes = ? WHERE user_id = ?");
        $encoded = json_encode($hashed_codes);
        $u->bind_param('ssi', $secret, $encoded, $user_id); $u->execute();
        unset($_SESSION['totp_pending_secret']);

        $log = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'TOTP_ENABLED', ?)");
        $desc = ($_SESSION['full_name'] ?? '') . ' enabled authenticator app 2FA.';
        $log->bind_param('is', $user_id, $desc); $log->execute();

        echo json_encode(['ok' => true, 'recovery_codes' => $plain_codes]); exit;
    }

    // ── Disable TOTP ──
    if ($action === 'totp_disable') {
        $password = $_POST['password'] ?? '';
        $s = $conn->prepare("SELECT password FROM users WHERE user_id = ?");
        $s->bind_param('i', $user_id); $s->execute();
        $row = $s->get_result()->fetch_assoc();

        if (!$row || !password_verify($password, $row['password'])) {
            echo json_encode(['ok' => false, 'error' => 'Incorrect password.']); exit;
        }

        $u = $conn->prepare("UPDATE users SET totp_secret = NULL, totp_enabled = 0, totp_recovery_codes = NULL WHERE user_id = ?");
        $u->bind_param('i', $user_id); $u->execute();

        $log = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'TOTP_DISABLED', ?)");
        $desc = ($_SESSION['full_name'] ?? '') . ' disabled authenticator app 2FA.';
        $log->bind_param('is', $user_id, $desc); $log->execute();

        echo json_encode(['ok' => true]); exit;
    }

    // ── Regenerate recovery codes ──
    if ($action === 'totp_regen_recovery') {
        $password = $_POST['password'] ?? '';
        $s = $conn->prepare("SELECT password FROM users WHERE user_id = ?");
        $s->bind_param('i', $user_id); $s->execute();
        $row = $s->get_result()->fetch_assoc();

        if (!$row || !password_verify($password, $row['password'])) {
            echo json_encode(['ok' => false, 'error' => 'Incorrect password.']); exit;
        }

        $plain_codes  = totp_generate_recovery_codes(8);
        $hashed_codes = array_map(fn($c) => password_hash($c, PASSWORD_DEFAULT), $plain_codes);
        $encoded = json_encode($hashed_codes);

        $u = $conn->prepare("UPDATE users SET totp_recovery_codes = ? WHERE user_id = ?");
        $u->bind_param('si', $encoded, $user_id); $u->execute();

        echo json_encode(['ok' => true, 'recovery_codes' => $plain_codes]); exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action.']); exit;
}

// ── Load user data ──
$stmt = $conn->prepare("SELECT full_name, username, email, role, totp_enabled, transaction_pin FROM users WHERE user_id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

$page_title  = 'My Account';
$active_page = 'my_account';
$base_path   = '../../';
require_once '../../includes/header.php';
require_once '../../includes/navbar.php';
?>

<div class="main">

<?php
$topbar_title      = 'My Account';
$topbar_breadcrumb = ['System', 'My Account'];
require_once '../../includes/topbar.php';
?>

<div class="content" style="max-width:720px;">

  <a href="../../modules/admin/dashboard_admin.php" class="back-link"><?= icon('arrow-left', 14) ?> Back to Dashboard</a>

  <!-- Change Password -->
  <div class="card">
    <div class="card-header">
      <div class="card-icon"><?= icon('lock-closed', 16) ?></div>
      <div>
        <div class="card-title">Change Password</div>
        <div class="card-sub">Update your login password</div>
      </div>
    </div>
    <div class="card-body">
      <div id="pw-alert"></div>
      <div style="display:flex;flex-direction:column;gap:0.9rem;">
        <div class="field">
          <label class="field-label">Current Password <span class="req">*</span></label>
          <input type="password" id="cur-pw" class="field-input" autocomplete="current-password"/>
        </div>
        <div class="field">
          <label class="field-label">New Password <span class="req">*</span></label>
          <input type="password" id="new-pw" class="field-input" autocomplete="new-password"/>
          <div class="field-hint">Minimum 8 characters.</div>
        </div>
        <div class="field">
          <label class="field-label">Confirm New Password <span class="req">*</span></label>
          <input type="password" id="conf-pw" class="field-input" autocomplete="new-password"/>
        </div>
      </div>
    </div>
    <div class="form-actions">
      <button type="button" onclick="savePassword()" class="btn-primary"><?= icon('lock-closed', 14) ?> Update Password</button>
    </div>
  </div>

  <!-- Transaction PIN -->
  <div class="card">
    <div class="card-header">
      <div class="card-icon"><?= icon('key', 16) ?></div>
      <div>
        <div class="card-title">Transaction PIN</div>
        <div class="card-sub">Required to confirm sensitive actions (deletions, receipt removal)</div>
      </div>
      <?php if ($user['transaction_pin']): ?>
      <span class="badge badge-green" style="margin-left:auto;"><?= icon('check-circle', 11) ?> PIN Set</span>
      <?php else: ?>
      <span class="badge badge-gray" style="margin-left:auto;">Not Set</span>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <div id="pin-alert"></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
        <div class="field">
          <label class="field-label">New PIN <span class="req">*</span></label>
          <input type="password" id="pin-val" class="field-input" inputmode="numeric" maxlength="6" placeholder="4–6 digits"/>
        </div>
        <div class="field">
          <label class="field-label">Confirm PIN <span class="req">*</span></label>
          <input type="password" id="pin-conf" class="field-input" inputmode="numeric" maxlength="6" placeholder="Repeat PIN"/>
        </div>
      </div>
      <div style="margin-top:0.6rem;font-size:0.72rem;color:var(--text-muted);">Leave both fields empty to remove your PIN.</div>
    </div>
    <div class="form-actions">
      <button type="button" onclick="savePin()" class="btn-primary"><?= icon('key', 14) ?> Save PIN</button>
    </div>
  </div>

  <!-- Authenticator App 2FA -->
  <div class="card">
    <div class="card-header">
      <div class="card-icon"><?= icon('shield-check', 16) ?></div>
      <div>
        <div class="card-title">Two-Factor Authentication</div>
        <div class="card-sub">Authenticator app (Google Authenticator, Authy, etc.)</div>
      </div>
      <?php if ($user['totp_enabled']): ?>
      <span class="badge badge-green" style="margin-left:auto;"><?= icon('check-circle', 11) ?> Enabled</span>
      <?php else: ?>
      <span class="badge badge-gray" style="margin-left:auto;">Disabled</span>
      <?php endif; ?>
    </div>
    <div class="card-body">

      <?php if (!$user['totp_enabled']): ?>
      <!-- SETUP FLOW -->
      <div id="totp-setup-intro">
        <div class="alert alert-info" style="margin-bottom:1rem;">
          <?= icon('information-circle', 14) ?>
          <span>When enabled, you will be asked for a 6-digit code from your authenticator app every time you log in.</span>
        </div>
        <button type="button" onclick="totpStartSetup()" class="btn-gold"><?= icon('shield-check', 14) ?> Enable Authenticator 2FA</button>
      </div>

      <div id="totp-setup-flow" style="display:none;">
        <div style="font-weight:700;font-size:0.88rem;color:var(--text-primary);margin-bottom:0.75rem;">Step 1 — Scan this QR code</div>
        <div style="display:flex;gap:2rem;align-items:flex-start;flex-wrap:wrap;margin-bottom:1.25rem;">
          <div id="totp-qr" style="background:#fff;padding:12px;border-radius:10px;border:1px solid var(--border);flex-shrink:0;"></div>
          <div style="flex:1;min-width:200px;">
            <div style="font-size:0.78rem;color:var(--text-muted);margin-bottom:0.75rem;">Open your authenticator app, tap <strong>+</strong> or <strong>Add account</strong>, then scan the QR code.</div>
            <div style="font-size:0.72rem;color:var(--text-muted);margin-bottom:0.4rem;font-weight:600;">OR enter this key manually:</div>
            <div id="totp-secret-display" style="font-family:monospace;font-size:0.85rem;font-weight:700;color:var(--text-primary);background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:0.5rem 0.75rem;letter-spacing:2px;word-break:break-all;"></div>
          </div>
        </div>
        <div style="font-weight:700;font-size:0.88rem;color:var(--text-primary);margin-bottom:0.6rem;">Step 2 — Enter the code to confirm</div>
        <div id="totp-confirm-alert"></div>
        <div style="display:flex;gap:0.75rem;align-items:flex-end;flex-wrap:wrap;">
          <div class="field" style="flex:1;min-width:160px;">
            <label class="field-label">6-digit code from app</label>
            <input type="text" id="totp-code-input" class="field-input" inputmode="numeric" maxlength="6" placeholder="000000" autocomplete="one-time-code" style="letter-spacing:0.3rem;font-size:1.1rem;text-align:center;"/>
          </div>
          <button type="button" onclick="totpConfirm()" class="btn-primary" style="margin-bottom:0;"><?= icon('check-circle', 14) ?> Verify & Activate</button>
          <button type="button" onclick="totpCancelSetup()" class="btn-ghost">Cancel</button>
        </div>
      </div>

      <?php else: ?>
      <!-- ALREADY ENABLED -->
      <div class="alert alert-success" style="margin-bottom:1.25rem;">
        <?= icon('shield-check', 14) ?>
        <span>Authenticator 2FA is active. You will be prompted for a code on every login.</span>
      </div>

      <div style="display:flex;gap:0.75rem;flex-wrap:wrap;">
        <button type="button" onclick="totpRegenRecovery()" class="btn-ghost"><?= icon('arrow-path', 14) ?> Regenerate Recovery Codes</button>
        <button type="button" onclick="totpDisable()" class="btn-danger"><?= icon('x-mark', 14) ?> Disable 2FA</button>
      </div>
      <?php endif; ?>

    </div>
  </div>

  <!-- Recovery Codes Modal -->
  <div id="recovery-modal" style="display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,0.6);backdrop-filter:blur(2px);align-items:center;justify-content:center;padding:1rem;">
    <div style="background:var(--bg-3);border:1px solid var(--border);border-radius:16px;width:100%;max-width:480px;box-shadow:var(--shadow-lg);">
      <div style="display:flex;align-items:center;justify-content:space-between;padding:1rem 1.25rem;border-bottom:1px solid var(--border);">
        <div style="font-weight:700;font-size:0.9rem;color:var(--text-primary);"><?= icon('key', 15) ?> &nbsp;Recovery Codes</div>
      </div>
      <div style="padding:1.25rem;">
        <div class="alert alert-warning" style="margin-bottom:1rem;">
          <?= icon('exclamation-triangle', 13) ?>
          <span>Save these codes now. Each code can only be used once. Store them somewhere safe — you cannot view them again.</span>
        </div>
        <div id="recovery-codes-list" style="display:grid;grid-template-columns:1fr 1fr;gap:0.4rem;font-family:monospace;font-size:0.88rem;margin-bottom:1rem;"></div>
        <div style="display:flex;gap:0.6rem;flex-wrap:wrap;">
          <button type="button" onclick="downloadRecoveryCodes()" class="btn-primary"><?= icon('arrow-down-tray', 14) ?> Download</button>
          <button type="button" onclick="closeRecoveryModal()" class="btn-ghost">Done</button>
        </div>
      </div>
    </div>
  </div>

</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script>
(function() {
  const csrf = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;
  let _recoveryCodes = [];
  let _qrInstance    = null;

  function post(data) {
    data.csrf_token = csrf;
    const fd = new FormData();
    Object.entries(data).forEach(([k,v]) => fd.append(k,v));
    return fetch('my_account.php', { method:'POST', body:fd }).then(r=>r.json());
  }

  function showAlert(elId, type, msg) {
    const el = document.getElementById(elId);
    if (!el) return;
    el.innerHTML = `<div class="alert alert-${type}" style="margin-bottom:0.75rem;">${msg}</div>`;
  }

  // ── Password ──
  window.savePassword = async function() {
    const cur  = document.getElementById('cur-pw').value;
    const nw   = document.getElementById('new-pw').value;
    const conf = document.getElementById('conf-pw').value;
    const res  = await post({ action:'change_password', current_password:cur, new_password:nw, confirm_password:conf });
    if (res.ok) {
      showAlert('pw-alert','success','Password updated successfully.');
      document.getElementById('cur-pw').value = '';
      document.getElementById('new-pw').value = '';
      document.getElementById('conf-pw').value = '';
    } else {
      showAlert('pw-alert','danger', res.error || 'Failed to update password.');
    }
  };

  // ── PIN ──
  window.savePin = async function() {
    const pin  = document.getElementById('pin-val').value;
    const conf = document.getElementById('pin-conf').value;
    const res  = await post({ action:'change_pin', pin, pin_confirm:conf });
    if (res.ok) {
      showAlert('pin-alert','success', res.msg || 'PIN updated.');
      document.getElementById('pin-val').value  = '';
      document.getElementById('pin-conf').value = '';
    } else {
      showAlert('pin-alert','danger', res.error || 'Failed to update PIN.');
    }
  };

  // ── TOTP Setup ──
  window.totpStartSetup = async function() {
    const res = await post({ action:'totp_generate' });
    if (!res.ok) { alert(res.error); return; }

    document.getElementById('totp-setup-intro').style.display = 'none';
    document.getElementById('totp-setup-flow').style.display  = 'block';
    document.getElementById('totp-secret-display').textContent = res.secret;

    // Generate QR code
    const qrEl = document.getElementById('totp-qr');
    qrEl.innerHTML = '';
    _qrInstance = new QRCode(qrEl, { text: res.uri, width: 160, height: 160, correctLevel: QRCode.CorrectLevel.M });
    document.getElementById('totp-code-input').focus();
  };

  window.totpCancelSetup = function() {
    document.getElementById('totp-setup-intro').style.display = 'block';
    document.getElementById('totp-setup-flow').style.display  = 'none';
    document.getElementById('totp-code-input').value = '';
    document.getElementById('totp-confirm-alert').innerHTML = '';
  };

  window.totpConfirm = async function() {
    const code = document.getElementById('totp-code-input').value.trim();
    const res  = await post({ action:'totp_confirm', code });
    if (!res.ok) {
      showAlert('totp-confirm-alert','danger', res.error || 'Verification failed.');
      return;
    }
    _recoveryCodes = res.recovery_codes;
    showRecoveryModal(_recoveryCodes);
    setTimeout(() => location.reload(), 500);
  };

  window.totpDisable = async function() {
    const result = await Swal.fire({
      title: 'Disable 2FA',
      text: 'Enter your password to confirm.',
      input: 'password',
      inputPlaceholder: 'Your password',
      confirmButtonText: 'Disable',
      confirmButtonColor: '#C0392B',
      showCancelButton: true,
      cancelButtonColor: '#6B7280',
    });
    if (!result.isConfirmed) return;
    const res = await post({ action:'totp_disable', password: result.value });
    if (res.ok) {
      Swal.fire({ icon:'success', title:'2FA Disabled', confirmButtonColor:'#B8860B' }).then(() => location.reload());
    } else {
      Swal.fire({ icon:'error', title:'Error', text: res.error, confirmButtonColor:'#B8860B' });
    }
  };

  window.totpRegenRecovery = async function() {
    const result = await Swal.fire({
      title: 'Regenerate Recovery Codes',
      text: 'This will invalidate your existing recovery codes. Enter your password to confirm.',
      input: 'password',
      inputPlaceholder: 'Your password',
      confirmButtonText: 'Regenerate',
      confirmButtonColor: '#1C1A17',
      showCancelButton: true,
      cancelButtonColor: '#6B7280',
    });
    if (!result.isConfirmed) return;
    const res = await post({ action:'totp_regen_recovery', password: result.value });
    if (res.ok) {
      _recoveryCodes = res.recovery_codes;
      showRecoveryModal(_recoveryCodes);
    } else {
      Swal.fire({ icon:'error', title:'Error', text: res.error, confirmButtonColor:'#B8860B' });
    }
  };

  // ── Recovery codes modal ──
  function showRecoveryModal(codes) {
    const list = document.getElementById('recovery-codes-list');
    list.innerHTML = codes.map(c =>
      `<div style="background:var(--bg);border:1px solid var(--border);border-radius:6px;padding:0.35rem 0.6rem;text-align:center;font-weight:700;">${c}</div>`
    ).join('');
    document.getElementById('recovery-modal').style.display = 'flex';
  }

  window.closeRecoveryModal = function() {
    document.getElementById('recovery-modal').style.display = 'none';
  };

  window.downloadRecoveryCodes = function() {
    const text = "TG-BASICS Recovery Codes\n" +
                 "Generated: " + new Date().toLocaleString() + "\n" +
                 "Keep these safe — each code can only be used once.\n\n" +
                 _recoveryCodes.join("\n");
    const a = document.createElement('a');
    a.href = 'data:text/plain;charset=utf-8,' + encodeURIComponent(text);
    a.download = 'tg-basics-recovery-codes.txt';
    a.click();
  };
})();
</script>

<?php require_once '../../includes/footer.php'; ?>
