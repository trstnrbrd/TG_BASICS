<?php
session_start();
require_once 'config/db.php';
require_once 'includes/icons.php';

if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'mechanic') {
        header("Location: modules/repair/dashboard_mechanic.php");
    } else {
        header("Location: modules/dashboard_admin.php");
    }
    exit;
}

$error   = '';
$lockout = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Please fill in all fields.';
    } else {
        $lock_check = $conn->prepare("SELECT failed_attempts, locked_until FROM users WHERE username = ?");
        $lock_check->bind_param('s', $username);
        $lock_check->execute();
        $lock_result = $lock_check->get_result()->fetch_assoc();

        if ($lock_result && $lock_result['locked_until'] && strtotime($lock_result['locked_until']) > time()) {
            $lockout = true;
            $error   = 'Account is locked due to too many failed attempts. Please try again later.';
        } else {
            $stmt = $conn->prepare("SELECT user_id, username, password, role, full_name, is_active FROM users WHERE username = ?");
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();

            if ($user && password_verify($password, $user['password'])) {
                if (!$user['is_active']) {
                    $error = 'Your account is not yet activated. Please check your email for the activation link.';
                } else {
                    $reset = $conn->prepare("UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE username = ?");
                    $reset->bind_param('s', $username);
                    $reset->execute();

                    $log  = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'LOGIN', ?)");
                    $desc = $user['full_name'] . ' logged in.';
                    $log->bind_param('is', $user['user_id'], $desc);
                    $log->execute();

                    $_SESSION['user_id']   = $user['user_id'];
                    $_SESSION['username']  = $user['username'];
                    $_SESSION['role']      = $user['role'];
                    $_SESSION['full_name'] = $user['full_name'];

                    if ($user['role'] === 'mechanic') {
                        header("Location: modules/repair/dashboard_mechanic.php");
                    } else {
                        header("Location: modules/dashboard_admin.php");
                    }
                    exit;
                }
            } else {
                if ($lock_result) {
                    $new_attempts = ($lock_result['failed_attempts'] ?? 0) + 1;
                    if ($new_attempts >= 5) {
                        $locked_until = date('Y-m-d H:i:s', strtotime('+15 minutes'));
                        $upd = $conn->prepare("UPDATE users SET failed_attempts = ?, locked_until = ? WHERE username = ?");
                        $upd->bind_param('iss', $new_attempts, $locked_until, $username);
                        $upd->execute();
                        $lockout = true;
                        $error   = 'Account locked after 5 failed attempts. Try again in 15 minutes.';
                    } else {
                        $remaining = 5 - $new_attempts;
                        $upd = $conn->prepare("UPDATE users SET failed_attempts = ? WHERE username = ?");
                        $upd->bind_param('is', $new_attempts, $username);
                        $upd->execute();
                        $error = 'Invalid username or password. ' . $remaining . ' attempt' . ($remaining !== 1 ? 's' : '') . ' remaining.';
                    }
                } else {
                    $error = 'Invalid username or password.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Sign In | TG-BASICS</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
<style>
  :root {
    --gold: #B8860B; --gold-bright: #D4A017; --gold-light: #F5E6C0; --gold-muted: #E8D5A3;
    --bg: #F4F1EC; --bg-2: #FAFAF8; --bg-3: #FFFFFF; --sidebar-bg: #1C1A17;
    --text-primary: #1A1814; --text-secondary: #5C5648; --text-muted: #9C9286;
    --border: #E2D9CC; --border-focus: #D4A017;
    --shadow-md: 0 4px 16px rgba(0,0,0,0.08), 0 2px 6px rgba(0,0,0,0.04);
    --shadow-lg: 0 12px 40px rgba(0,0,0,0.12), 0 4px 12px rgba(0,0,0,0.06);
    --danger: #C0392B; --danger-bg: #FDF2F2; --danger-border: rgba(192,57,43,0.2);
  }
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    background: var(--bg); color: var(--text-primary);
    font-family: 'Plus Jakarta Sans', sans-serif; font-weight: 400;
    min-height: 100vh; font-size: 14px;
    display: flex; align-items: center; justify-content: center;
    position: relative; overflow: hidden;
  }
  body::before {
    content: ''; position: fixed; inset: 0;
    background-image: linear-gradient(var(--border) 1px, transparent 1px), linear-gradient(90deg, var(--border) 1px, transparent 1px);
    background-size: 40px 40px; opacity: 0.45; pointer-events: none;
    mask-image: radial-gradient(ellipse 80% 80% at 50% 50%, black 30%, transparent 100%);
  }
  body::after {
    content: ''; position: fixed; inset: 0;
    background: radial-gradient(circle at 30% 20%, rgba(212,160,23,0.06) 0%, transparent 50%), radial-gradient(circle at 75% 80%, rgba(212,160,23,0.04) 0%, transparent 45%);
    pointer-events: none;
  }
  .login-wrapper { width: 100%; max-width: 420px; padding: 1.5rem; position: relative; z-index: 2; }
  .login-brand { display: flex; flex-direction: column; align-items: center; margin-bottom: 2rem; animation: fadeDown 0.5s ease both; }
  .brand-logo-wrap { width: 64px; height: 64px; border-radius: 50%; border: 2px solid var(--gold-bright); overflow: hidden; margin-bottom: 0.9rem; box-shadow: 0 0 0 6px var(--gold-light), var(--shadow-md); }
  .brand-logo-wrap img { width: 100%; height: 100%; object-fit: cover; }
  .brand-name { font-size: 1.35rem; font-weight: 800; color: var(--text-primary); letter-spacing: -0.3px; margin-bottom: 0.2rem; }
  .brand-name span { color: var(--gold-bright); }
  .brand-tagline { font-size: 0.68rem; color: var(--text-muted); letter-spacing: 1.5px; text-transform: uppercase; font-weight: 500; }
  .login-card { background: var(--bg-3); border: 1px solid var(--border); border-radius: 16px; box-shadow: var(--shadow-lg); overflow: hidden; animation: fadeUp 0.5s ease 0.1s both; }
  .login-card-header { background: var(--sidebar-bg); padding: 1.25rem 1.75rem; position: relative; overflow: hidden; }
  .login-card-header::after { content: ''; position: absolute; bottom: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, var(--gold-bright), var(--gold-muted), transparent); }
  .login-card-header-title { font-size: 1rem; font-weight: 700; color: #fff; margin-bottom: 0.15rem; }
  .login-card-header-sub { font-size: 0.72rem; color: rgba(200,192,176,0.5); }
  .login-card-body { padding: 1.75rem; }
  .field { display: flex; flex-direction: column; gap: 0.4rem; margin-bottom: 1.1rem; }
  .field-label { font-size: 0.72rem; font-weight: 600; color: var(--text-secondary); }
  .field-input-wrap { position: relative; }
  .field-icon { position: absolute; left: 0.85rem; top: 50%; transform: translateY(-50%); font-size: 0.85rem; color: var(--text-muted); pointer-events: none; z-index: 1; }
  .field-input { width: 100%; background: var(--bg); border: 1px solid var(--border); color: var(--text-primary); padding: 0.7rem 0.9rem 0.7rem 2.4rem; border-radius: 9px; font-family: 'Plus Jakarta Sans', sans-serif; font-size: 0.85rem; outline: none; transition: border-color 0.15s, box-shadow 0.15s, background 0.15s; }
  .field-input:focus { border-color: var(--border-focus); box-shadow: 0 0 0 3px rgba(212,160,23,0.1); background: var(--bg-3); }
  .field-input::placeholder { color: var(--text-muted); font-size: 0.82rem; }
  .pw-toggle { position: absolute; right: 0.85rem; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: var(--text-muted); font-size: 0.85rem; padding: 0.2rem; transition: color 0.15s; z-index: 1; }
  .pw-toggle:hover { color: var(--gold); }
  .alert-error { background: var(--danger-bg); border: 1px solid var(--danger-border); color: var(--danger); padding: 0.75rem 1rem; border-radius: 9px; font-size: 0.78rem; font-weight: 500; margin-bottom: 1.25rem; display: flex; align-items: flex-start; gap: 0.6rem; line-height: 1.5; }
  .btn-submit { width: 100%; background: var(--sidebar-bg); color: var(--gold-bright); border: none; padding: 0.85rem; border-radius: 9px; font-family: 'Plus Jakarta Sans', sans-serif; font-size: 0.85rem; font-weight: 700; cursor: pointer; transition: background 0.15s, box-shadow 0.15s, transform 0.15s; box-shadow: 0 4px 14px rgba(28,26,23,0.25); display: flex; align-items: center; justify-content: center; gap: 0.5rem; margin-top: 0.5rem; }
  .btn-submit:hover:not(:disabled) { background: #2A2724; box-shadow: 0 6px 20px rgba(28,26,23,0.35); transform: translateY(-1px); }
  .btn-submit:disabled { opacity: 0.7; cursor: not-allowed; transform: none; }
  .spinner { width: 16px; height: 16px; border: 2px solid rgba(212,160,23,0.3); border-top-color: var(--gold-bright); border-radius: 50%; animation: spin 0.7s linear infinite; flex-shrink: 0; }
  .login-note { text-align: center; font-size: 0.7rem; color: var(--text-muted); line-height: 1.6; padding: 1rem 1.75rem 1.5rem; border-top: 1px solid var(--border); background: var(--bg-2); }
  .login-note a { color: var(--gold); text-decoration: none; font-weight: 600; }
  #toast-root { position: fixed; top: 1.5rem; left: 50%; transform: translateX(-50%); z-index: 9999; pointer-events: none; display: flex; flex-direction: column; align-items: center; gap: 0.5rem; }
  @keyframes fadeUp   { from { opacity: 0; transform: translateY(16px); }  to { opacity: 1; transform: translateY(0); } }
  @keyframes fadeDown { from { opacity: 0; transform: translateY(-12px); } to { opacity: 1; transform: translateY(0); } }
  @keyframes spin     { to { transform: rotate(360deg); } }
  @keyframes toastIn  { from { opacity: 0; transform: translateY(-10px) scale(0.96); } to { opacity: 1; transform: translateY(0) scale(1); } }
  @keyframes toastOut { from { opacity: 1; } to { opacity: 0; transform: translateY(-8px) scale(0.96); } }
  ::-webkit-scrollbar { width: 4px; }
  ::-webkit-scrollbar-track { background: var(--bg); }
  ::-webkit-scrollbar-thumb { background: var(--gold-muted); border-radius: 2px; }
</style>
</head>
<body>
<div id="toast-root"></div>
<div class="login-wrapper">
  <div class="login-brand">
    <div class="brand-logo-wrap"><img src="assets/img/tg_logo.png" alt="TG Logo"/></div>
    <div class="brand-name">TG<span>-BASICS</span></div>
    <div class="brand-tagline">Brokerage and Auto Shop Integrated Client System</div>
  </div>
  <div class="login-card">
    <div class="login-card-header">
      <div class="login-card-header-title">Welcome back</div>
      <div class="login-card-header-sub">Sign in to continue to TG-BASICS</div>
    </div>
    <div class="login-card-body">
      <?php if ($error && !$lockout): ?>
      <div class="alert-error"><span>&#9888;</span><span><?= htmlspecialchars($error) ?></span></div>
      <?php endif; ?>
      <form method="POST" action="" id="login-form" novalidate>
        <div class="field">
          <label class="field-label">Username</label>
          <div class="field-input-wrap">
            <span class="field-icon"><?= icon('user', 14) ?></span>
            <input type="text" name="username" id="username" class="field-input" placeholder="Enter your username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" autocomplete="username" required/>
          </div>
        </div>
        <div class="field">
          <label class="field-label">Password</label>
          <div class="field-input-wrap">
            <span class="field-icon"><?= icon('lock-closed', 14) ?></span>
            <input type="password" name="password" id="password" class="field-input" placeholder="Enter your password" autocomplete="current-password" required/>
            <button type="button" class="pw-toggle" id="pw-toggle">&#128065;</button>
          </div>
        </div>
        <div id="submit-root"></div>
      </form>
    </div>
    <div class="login-note">
      No self-registration. Contact your administrator for account access.<br/>
      <a href="index.php">&#8592; Back to Home</a>
    </div>
  </div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/react/18.2.0/umd/react.production.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/react-dom/18.2.0/umd/react-dom.production.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/babel-standalone/7.23.2/babel.min.js"></script>
<script type="text/babel">
  const { useState, useEffect } = React;
  function Toast({ message, type, onDone }) {
    const [leaving, setLeaving] = useState(false);
    useEffect(() => {
      const t1 = setTimeout(() => setLeaving(true), 3500);
      const t2 = setTimeout(() => onDone(), 4000);
      return () => { clearTimeout(t1); clearTimeout(t2); };
    }, []);
    return (
      <div style={{ display:'flex', alignItems:'center', gap:'0.6rem', background: type==='lockout'?'#FDF2F2':'#1C1A17', border: type==='lockout'?'1px solid rgba(192,57,43,0.25)':'1px solid rgba(212,160,23,0.25)', color: type==='lockout'?'#C0392B':'#D4A017', padding:'0.75rem 1.25rem', borderRadius:'10px', fontSize:'0.8rem', fontWeight:'600', fontFamily:"'Plus Jakarta Sans',sans-serif", boxShadow:'0 8px 24px rgba(0,0,0,0.15)', pointerEvents:'auto', animation: leaving?'toastOut 0.4s ease forwards':'toastIn 0.35s ease forwards', maxWidth:'360px', lineHeight:'1.4' }}>
        <span style={{fontSize:'1rem',flexShrink:0}}>{type==='lockout'?'🔒':'⚠️'}</span>
        <span>{message}</span>
      </div>
    );
  }
  function ToastManager({ initialToast }) {
    const [toasts, setToasts] = useState(initialToast ? [{ id: Date.now(), ...initialToast }] : []);
    const remove = (id) => setToasts(prev => prev.filter(t => t.id !== id));
    return <>{toasts.map(t => <Toast key={t.id} message={t.message} type={t.type} onDone={() => remove(t.id)} />)}</>;
  }
  function SubmitButton() {
    const [loading, setLoading] = useState(false);
    const handleClick = () => {
      const username = document.getElementById('username').value.trim();
      const password = document.getElementById('password').value.trim();
      if (!username || !password) return;
      setLoading(true);
      setTimeout(() => document.getElementById('login-form').submit(), 400);
    };
    return (
      <button type="button" className="btn-submit" onClick={handleClick} disabled={loading}>
        {loading ? <><div className="spinner"></div>Signing in...</> : <><?= icon('lock-closed', 14) ?> Sign In to TG-BASICS</>}
      </button>
    );
  }
  ReactDOM.createRoot(document.getElementById('submit-root')).render(<SubmitButton />);
  <?php if ($lockout): ?>
  ReactDOM.createRoot(document.getElementById('toast-root')).render(
    <ToastManager initialToast={{ message: <?= json_encode($error) ?>, type: 'lockout' }} />
  );
  <?php endif; ?>
</script>
<script>
  document.getElementById('pw-toggle').addEventListener('click', function () {
    const pw = document.getElementById('password');
    pw.type = pw.type === 'password' ? 'text' : 'password';
    this.textContent = pw.type === 'password' ? '👁' : '🙈';
  });
</script>
</body>
</html>