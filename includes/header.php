<?php
$page_title = $page_title ?? 'TG-BASICS';
$base_path  = $base_path  ?? '../';
$role_label = match($_SESSION['role'] ?? '') {
    'super_admin' => 'Owner',
    'admin'       => 'Admin',
    'mechanic'    => 'Mechanic',
    default       => 'User'
};

require_once __DIR__ . '/icons.php';

// Load user theme preference
$_user_theme = $_SESSION['theme'] ?? 'light';
if ($_user_theme === 'light' && isset($_SESSION['user_id'], $conn)) {
    $__t = $conn->prepare("SELECT theme FROM users WHERE user_id = ?");
    $__t->bind_param('i', $_SESSION['user_id']);
    $__t->execute();
    $__tr = $__t->get_result()->fetch_assoc();
    if ($__tr) {
        $_user_theme = $__tr['theme'] ?? 'light';
        $_SESSION['theme'] = $_user_theme;
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($_user_theme) ?>">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<meta name="format-detection" content="telephone=no, date=no, email=no, address=no"/>
<meta name="base_path" content="/TG-BASICS/"/>
<title><?= htmlspecialchars($page_title) ?> | TG-BASICS</title>
<link rel="icon" type="image/png" href="<?= $base_path ?>assets/img/tg_logo.png"/>
<link rel="apple-touch-icon" href="<?= $base_path ?>assets/img/tg_logo.png"/>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Big+Shoulders+Text:wght@700;800;900&display=swap" rel="stylesheet"/>
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
    --sidebar-text: #C8C0B0;
    --sidebar-active: #D4A017;
    --text-primary: #1A1814;
    --text-secondary: #5C5648;
    --text-muted: #9C9286;
    --border: #E2D9CC;
    --border-focus: #D4A017;
    --shadow: 0 2px 8px rgba(0,0,0,0.08), 0 1px 3px rgba(0,0,0,0.05);
    --shadow-md: 0 6px 20px rgba(0,0,0,0.10), 0 2px 6px rgba(0,0,0,0.06);
    --shadow-lg: 0 12px 40px rgba(0,0,0,0.14), 0 4px 12px rgba(0,0,0,0.08);
    --success: #2E7D52;
    --success-bg: #F0FAF4;
    --success-border: rgba(46,125,82,0.2);
    --warning: #B8860B;
    --warning-bg: #FFFBF0;
    --warning-border: rgba(184,134,11,0.25);
    --danger: #C0392B;
    --danger-bg: #FDF2F2;
    --danger-border: rgba(192,57,43,0.2);
    --info: #1A6B9A;
    --info-bg: #EFF6FB;
    --info-border: rgba(26,107,154,0.2);
    --btn-bg: #1C1A17;
    --btn-text: #D4A017;
    --sidebar-width: 232px;
  }

  [data-theme="dark"] {
    --gold: #C8A010;
    --btn-bg: #0F0E0D;
    --btn-text: #D4A017;
    --bg: #141210;
    --bg-2: #1C1A17;
    --bg-3: #242220;
    --sidebar-bg: #0F0E0D;
    --sidebar-text: #9C9286;
    --text-primary: #E8E2D8;
    --text-secondary: #B8B0A4;
    --text-muted: #7A7268;
    --border: #2A2724;
    --shadow: 0 1px 3px rgba(0,0,0,0.25), 0 1px 2px rgba(0,0,0,0.15);
    --shadow-md: 0 4px 16px rgba(0,0,0,0.3), 0 2px 6px rgba(0,0,0,0.2);
    --shadow-lg: 0 12px 40px rgba(0,0,0,0.4), 0 4px 12px rgba(0,0,0,0.25);
    --success-bg: rgba(46,125,82,0.12);
    --success-border: rgba(46,125,82,0.3);
    --warning-bg: rgba(184,134,11,0.12);
    --warning-border: rgba(184,134,11,0.3);
    --danger-bg: rgba(192,57,43,0.12);
    --danger-border: rgba(192,57,43,0.3);
    --info-bg: rgba(26,107,154,0.12);
    --info-border: rgba(26,107,154,0.3);
    --gold-pale: rgba(184,134,11,0.08);
    --gold-light: rgba(184,134,11,0.18);
    --gold-muted: rgba(212,160,23,0.35);
  }

  /* ── WARM — soft cream ── */
  [data-theme="warm"] {
    --btn-bg: #7C5C3A;
    --btn-text: #FDF6EE;
    --gold: #9A7B52;
    --gold-bright: #A8865A;
    --gold-light: #F0E6D6;
    --gold-muted: #C9A87A;
    --gold-pale: #FAF3E8;
    --bg: #FAF5EE;
    --bg-2: #F3EBE0;
    --bg-3: #FFFFFF;
    --sidebar-bg: #2C1F12;
    --sidebar-text: #C4A882;
    --sidebar-active: #C9A87A;
    --text-primary: #1E140A;
    --text-secondary: #5C4430;
    --text-muted: #9A7B5A;
    --border: #E4D5C0;
    --border-focus: #A8865A;
    --shadow: 0 2px 8px rgba(60,35,10,0.07), 0 1px 3px rgba(60,35,10,0.04);
    --shadow-md: 0 6px 20px rgba(60,35,10,0.09), 0 2px 6px rgba(60,35,10,0.05);
    --shadow-lg: 0 12px 40px rgba(60,35,10,0.12), 0 4px 12px rgba(60,35,10,0.07);
    --success: #2E7D52;
    --success-bg: #F0FAF4;
    --success-border: rgba(46,125,82,0.2);
    --warning: #9A6B10;
    --warning-bg: #FDF8EC;
    --warning-border: rgba(154,107,16,0.25);
    --danger: #C0392B;
    --danger-bg: #FDF2F2;
    --danger-border: rgba(192,57,43,0.2);
    --info: #1A6B9A;
    --info-bg: #EFF6FB;
    --info-border: rgba(26,107,154,0.2);
  }

  /* Force icons visible in HC White */
  [data-theme="high-contrast"] .quick-action-icon,
  [data-theme="high-contrast"] .dash-stat-icon {
    background: rgba(0,0,0,0.08) !important;
    color: #000000 !important;
  }
  [data-theme="high-contrast"] .quick-action:hover .quick-action-icon {
    background: rgba(0,0,0,0.14) !important;
  }

  /* ── HIGH CONTRAST DARK — pure black ── */
  [data-theme="high-contrast-dark"] {
    --btn-bg: #FFFFFF;
    --btn-text: #000000;
    --gold: #D4A017;
    --gold-bright: #E8B824;
    --gold-light: #3A2E00;
    --gold-muted: #8B6914;
    --gold-pale: rgba(212,160,23,0.1);
    --bg: #000000;
    --bg-2: #111111;
    --bg-3: #1A1A1A;
    --sidebar-bg: #000000;
    --sidebar-text: #AAAAAA;
    --sidebar-active: #E8B824;
    --text-primary: #FFFFFF;
    --text-secondary: #CCCCCC;
    --text-muted: #888888;
    --border: #333333;
    --border-focus: #FFFFFF;
    --shadow: 0 1px 4px rgba(0,0,0,0.6);
    --shadow-md: 0 4px 16px rgba(0,0,0,0.7);
    --shadow-lg: 0 12px 40px rgba(0,0,0,0.8);
    --success: #00FF88;
    --success-bg: rgba(0,255,136,0.1);
    --success-border: rgba(0,255,136,0.35);
    --warning: #FFD700;
    --warning-bg: rgba(255,215,0,0.1);
    --warning-border: rgba(255,215,0,0.35);
    --danger: #FF5555;
    --danger-bg: rgba(255,85,85,0.1);
    --danger-border: rgba(255,85,85,0.35);
    --info: #55AAFF;
    --info-bg: rgba(85,170,255,0.1);
    --info-border: rgba(85,170,255,0.35);
  }

  /* ── HIGH CONTRAST — pure white ── */
  [data-theme="high-contrast"] {
    --btn-bg: #000000;
    --btn-text: #FFFFFF;
    --gold: #000000;
    --gold-bright: #000000;
    --gold-light: #E0E0E0;
    --gold-muted: #888888;
    --gold-pale: #F5F5F5;
    --bg: #FFFFFF;
    --bg-2: #F0F0F0;
    --bg-3: #FFFFFF;
    --sidebar-bg: #FFFFFF;
    --sidebar-text: #222222;
    --sidebar-active: #000000;
    --text-primary: #000000;
    --text-secondary: #222222;
    --text-muted: #555555;
    --border: #BBBBBB;
    --border-focus: #000000;
    --shadow: 0 1px 4px rgba(0,0,0,0.15);
    --shadow-md: 0 4px 16px rgba(0,0,0,0.18);
    --shadow-lg: 0 12px 40px rgba(0,0,0,0.22);
    --success: #1A6B3A;
    --success-bg: #F0FFF4;
    --success-border: rgba(26,107,58,0.3);
    --warning: #7A4800;
    --warning-bg: #FFFBF0;
    --warning-border: rgba(122,72,0,0.3);
    --danger: #AA1111;
    --danger-bg: #FFF0F0;
    --danger-border: rgba(170,17,17,0.3);
    --info: #0A4F8A;
    --info-bg: #F0F7FF;
    --info-border: rgba(10,79,138,0.3);
  }

  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  html { scroll-behavior: smooth; }

  /* Prevent mobile browsers from auto-linking phone numbers, dates, etc. */
  a[href^="tel"], a[href^="sms"], a[x-apple-data-detectors] {
    color: inherit !important;
    text-decoration: none !important;
    pointer-events: none;
  }

  body {
    background: var(--bg);
    color: var(--text-primary);
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-weight: 400;
    min-height: 100vh;
    font-size: 14px;
    -webkit-text-size-adjust: 100%;
  }

  /* ── LAYOUT ── */
  .main { margin-left: var(--sidebar-width); min-height: 100vh; }

  /* ── OVERLAY ── */
  .sidebar-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.5);
    z-index: 49;
    opacity: 0;
    transition: opacity 0.25s ease;
  }
  .sidebar-overlay.active { display: block; opacity: 1; }

  /* ── TOPBAR ── */
  .topbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.9rem 2rem;
    background: var(--bg-3);
    border-bottom: 1px solid var(--border);
    box-shadow: var(--shadow);
    position: sticky;
    top: 0;
    z-index: 40;
    gap: 1rem;
  }

  .topbar-left { display: flex; align-items: center; gap: 0.75rem; min-width: 0; }
  .topbar-titles { display: flex; flex-direction: column; gap: 0.1rem; min-width: 0; }
  .topbar-title { font-size: 1.05rem; font-weight: 800; color: var(--text-primary); letter-spacing: -0.3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .topbar-breadcrumb { font-size: 0.7rem; color: var(--text-muted); white-space: nowrap; }
  .topbar-breadcrumb span { color: var(--gold); font-weight: 600; }
  .topbar-right { display: flex; align-items: center; gap: 0.75rem; flex-shrink: 0; }

  /* ── HAMBURGER ── */
  .hamburger {
    display: none;
    flex-direction: column;
    justify-content: center;
    gap: 4px;
    width: 36px; height: 36px;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 8px;
    cursor: pointer;
    padding: 8px;
    flex-shrink: 0;
    transition: all 0.15s;
  }
  .hamburger:hover { border-color: var(--gold-muted); background: var(--gold-pale); }
  .hamburger span { display: block; width: 100%; height: 2px; background: var(--text-secondary); border-radius: 2px; transition: all 0.2s ease; }
  .hamburger.open span:nth-child(1) { transform: translateY(6px) rotate(45deg); }
  .hamburger.open span:nth-child(2) { opacity: 0; transform: scaleX(0); }
  .hamburger.open span:nth-child(3) { transform: translateY(-6px) rotate(-45deg); }

  /* ── USER CHIP ── */
  .user-chip {
    display: flex; align-items: center; gap: 0.5rem;
    background: var(--bg-3); border: 1px solid var(--border);
    padding: 0.35rem 0.75rem 0.35rem 0.45rem;
    border-radius: 100px; font-size: 0.75rem;
    color: var(--text-primary); font-weight: 500; white-space: nowrap;
    cursor: pointer; transition: all 0.15s; user-select: none;
  }
  .user-chip:hover { border-color: var(--gold-muted); background: var(--gold-pale); }
  .user-avatar {
    width: 24px; height: 24px; border-radius: 50%;
    background: linear-gradient(135deg, var(--gold-bright), var(--gold));
    display: flex; align-items: center; justify-content: center;
    font-size: 0.62rem; font-weight: 800; color: #fff; flex-shrink: 0;
  }
  .user-chip-chevron {
    transition: transform 0.2s ease; color: var(--text-muted);
    display: flex; align-items: center; margin-left: 0.1rem;
  }
  .user-chip-chevron.open { transform: rotate(180deg); }

  /* ── USER DROPDOWN ── */
  .user-dropdown-wrap { position: relative; }
  .user-dropdown {
    position: absolute; top: calc(100% + 6px); right: 0;
    width: 260px; background: var(--bg-3);
    border: 1px solid var(--border); border-radius: 12px;
    box-shadow: var(--shadow-lg); z-index: 100; overflow: hidden;
  }
  .user-dropdown-header {
    padding: 1rem 1.1rem; border-bottom: 1px solid var(--border);
    display: flex; align-items: center; gap: 0.75rem; background: var(--bg-2);
  }
  .user-dropdown-avatar {
    width: 36px; height: 36px; border-radius: 50%;
    background: linear-gradient(135deg, var(--gold-bright), var(--gold));
    display: flex; align-items: center; justify-content: center;
    font-size: 0.75rem; font-weight: 800; color: #fff; flex-shrink: 0;
  }
  .user-dropdown-name { font-size: 0.82rem; font-weight: 700; color: var(--text-primary); line-height: 1.3; }
  .user-dropdown-meta { font-size: 0.68rem; color: var(--text-muted); }
  .user-dropdown-menu { padding: 0.4rem; }
  .user-dropdown-item {
    display: flex; align-items: center; gap: 0.6rem;
    padding: 0.6rem 0.75rem; border-radius: 8px;
    font-size: 0.78rem; font-weight: 500; color: var(--text-secondary);
    text-decoration: none; transition: all 0.12s; cursor: pointer;
    border: none; background: none; width: 100%;
    font-family: 'Plus Jakarta Sans', sans-serif;
  }
  .user-dropdown-item:hover { background: var(--gold-pale); color: var(--gold); }
  .user-dropdown-item svg { flex-shrink: 0; }

  @keyframes dropdownIn {
    from { opacity: 0; transform: translateY(-8px) scale(0.97); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
  }
  @keyframes dropdownOut {
    from { opacity: 1; transform: translateY(0) scale(1); }
    to   { opacity: 0; transform: translateY(-8px) scale(0.97); }
  }

  /* ── CONTENT ── */
  .content { padding: 2rem; }

  .back-link {
    display: inline-flex; align-items: center; justify-content: center;
    width: 36px; height: 36px;
    background: var(--bg-3); border: 1px solid var(--border);
    border-radius: 10px; color: var(--text-secondary);
    text-decoration: none; margin-bottom: 1.25rem;
    box-shadow: var(--shadow); transition: background 0.15s, border-color 0.15s, color 0.15s, box-shadow 0.15s;
    flex-shrink: 0; font-size: 0;
  }
  .back-link svg { flex-shrink: 0; font-size: initial; }
  .back-link:hover {
    background: var(--gold-pale); border-color: var(--gold-muted);
    color: var(--gold); box-shadow: var(--shadow-md);
  }

  /* ── PAGE HEADER ── */
  .page-header {
    display: flex; align-items: center; flex-wrap: wrap; gap: 0.75rem;
    margin-bottom: 1.5rem;
  }
  .page-header::before { display: none; }
  .page-header-title { font-size: 1.05rem; font-weight: 800; color: var(--text-primary); display: flex; align-items: center; gap: 0.5rem; }
  .page-header-sub   { font-size: 0.75rem; color: var(--text-muted); margin-top: 0.2rem; }

  /* ── INFO BOX ── */
  .info-box {
    background: var(--info-bg); border: 1px solid var(--info-border);
    border-radius: 9px; padding: 0.9rem 1.1rem;
    font-size: 0.78rem; color: var(--info); line-height: 1.6;
    display: flex; align-items: flex-start; gap: 0.6rem;
  }

  /* ── CARDS ── */
  .card { background: var(--bg-3); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; box-shadow: var(--shadow); margin-bottom: 1.25rem; }
  .card-header { padding: 1rem 1.25rem; border-bottom: 1px solid var(--border); background: var(--bg-2); display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }
  .card-icon { width: 34px; height: 34px; background: var(--gold); border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 0.95rem; flex-shrink: 0; color: #fff; }
  .card-title { font-size: 0.88rem; font-weight: 700; color: var(--text-primary); }
  .card-sub   { font-size: 0.7rem; color: var(--text-muted); margin-top: 0.1rem; }
  .card-body  { padding: 1.5rem; }

  /* ── BUTTONS ── */
  .btn-primary { background: var(--btn-bg); color: var(--btn-text); border: none; padding: 0.7rem 1.5rem; border-radius: 9px; font-family: 'Plus Jakarta Sans', sans-serif; font-size: 0.83rem; font-weight: 700; cursor: pointer; text-decoration: none; transition: all 0.15s; display: inline-flex; align-items: center; gap: 0.5rem; box-shadow: 0 4px 12px rgba(28,26,23,0.2); white-space: nowrap; }
  .btn-primary:hover { filter: brightness(1.15); box-shadow: 0 6px 16px rgba(28,26,23,0.3); }

  .btn-gold { background: var(--gold); color: #fff; border: none; padding: 0.7rem 1.5rem; border-radius: 9px; font-family: 'Plus Jakarta Sans', sans-serif; font-size: 0.83rem; font-weight: 700; cursor: pointer; text-decoration: none; transition: all 0.15s; display: inline-flex; align-items: center; gap: 0.5rem; box-shadow: 0 4px 12px rgba(184,134,11,0.25); white-space: nowrap; }
  .btn-gold:hover { background: var(--gold-bright); }

  .btn-ghost { background: var(--bg-3); color: var(--text-secondary); border: 1px solid var(--border); padding: 0.7rem 1.25rem; border-radius: 9px; font-family: 'Plus Jakarta Sans', sans-serif; font-size: 0.83rem; font-weight: 500; text-decoration: none; transition: all 0.15s; display: inline-flex; align-items: center; gap: 0.5rem; cursor: pointer; white-space: nowrap; }
  .btn-ghost:hover { border-color: var(--gold-muted); color: var(--gold); }

  .btn-danger { background: var(--danger); color: #fff; border: none; padding: 0.7rem 1.5rem; border-radius: 9px; font-family: 'Plus Jakarta Sans', sans-serif; font-size: 0.83rem; font-weight: 700; cursor: pointer; text-decoration: none; transition: all 0.15s; display: inline-flex; align-items: center; gap: 0.5rem; box-shadow: 0 4px 12px rgba(192,57,43,0.25); white-space: nowrap; }
  .btn-danger:hover { background: #A93226; }

  .btn-sm-gold { background: var(--gold); color: #fff; border: none; padding: 0.35rem 0.85rem; border-radius: 7px; font-size: 0.75rem; font-weight: 700; text-decoration: none; transition: all 0.15s; white-space: nowrap; display: inline-flex; align-items: center; gap: 0.35rem; font-family: 'Plus Jakarta Sans', sans-serif; cursor: pointer; }
  .btn-sm-gold:hover { background: var(--gold-bright); color: #fff; }
  .btn-sm-danger { background: var(--danger); color: #fff; border: none; padding: 0.35rem 0.55rem; border-radius: 7px; font-size: 0.75rem; font-weight: 700; text-decoration: none; transition: all 0.15s; white-space: nowrap; display: inline-flex; align-items: center; gap: 0.35rem; font-family: 'Plus Jakarta Sans', sans-serif; cursor: pointer; }
  .btn-sm-danger:hover { background: #a93226; color: #fff; }

  /* ── FORM FIELDS ── */
  .field { display: flex; flex-direction: column; gap: 0.4rem; }
  .field-label { font-size: 0.72rem; font-weight: 600; color: var(--text-secondary); }
  .field-label .req, .req { color: var(--gold-bright); margin-left: 2px; }

  .field-input, .field-select, .field-textarea {
    width: 100%; background: var(--bg); border: 1px solid var(--border);
    color: var(--text-primary); padding: 0.7rem 0.9rem; border-radius: 9px;
    font-family: 'Plus Jakarta Sans', sans-serif; font-size: 0.85rem; outline: none;
    transition: border-color 0.15s, box-shadow 0.15s, background 0.15s;
  }
  .field-input:focus, .field-select:focus, .field-textarea:focus { border-color: var(--border-focus); box-shadow: 0 0 0 3px rgba(212,160,23,0.1); background: var(--bg-3); }
  .field-input::placeholder, .field-textarea::placeholder { color: var(--text-muted); font-size: 0.82rem; }
  .field-input.has-error, .field-select.has-error { border-color: var(--danger); background: var(--danger-bg); }
  .field-select { cursor: pointer; appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%239C9286' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 0.9rem center; background-color: var(--bg); padding-right: 2.5rem; }
  .field-textarea { resize: vertical; min-height: 90px; }
  .field-hint { font-size: 0.67rem; color: var(--text-muted); line-height: 1.4; }

  .form-grid   { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem 1.25rem; }
  .form-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem 1.25rem; }
  .span-2 { grid-column: span 2; }
  .span-3 { grid-column: span 3; }

  /* Filter toolbar inputs */
  .filter-input {
    background: var(--bg-3); border: 1px solid var(--border); color: var(--text-primary);
    padding: 0.6rem 0.9rem; border-radius: 9px; font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 0.82rem; outline: none; transition: border-color 0.15s, box-shadow 0.15s;
    box-shadow: var(--shadow);
  }
  .filter-input:focus { border-color: var(--gold-bright); box-shadow: 0 0 0 3px rgba(212,160,23,0.1); }
  .filter-input::placeholder { color: var(--text-muted); }
  select.filter-input {
    cursor: pointer; appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%239C9286' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 0.9rem center;
    background-color: var(--bg-3); padding-right: 2.5rem;
  }

  .form-actions { display: flex; justify-content: flex-end; gap: 0.6rem; padding: 1.1rem 1.5rem; background: var(--bg-2); border-top: 1px solid var(--border); flex-wrap: wrap; }

  /* ── ALERTS ── */
  .alert { padding: 0.8rem 1rem; border-radius: 9px; font-size: 0.8rem; margin-bottom: 1.25rem; display: flex; align-items: flex-start; gap: 0.6rem; font-weight: 500; line-height: 1.5; }
  .alert-success { background: var(--success-bg); border: 1px solid var(--success-border); color: var(--success); }
  .alert-danger  { background: var(--danger-bg);  border: 1px solid var(--danger-border);  color: var(--danger); }
  .alert-warning { background: var(--warning-bg); border: 1px solid var(--warning-border); color: var(--warning); }
  .alert-info    { background: var(--info-bg);    border: 1px solid var(--info-border);    color: var(--info); }

  /* ── TABLE ── */
  .tg-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; position: relative; }
  .tg-table { width: 100%; border-collapse: collapse; }
  .tg-table thead tr { background: var(--bg-2); border-bottom: 1px solid var(--border); }
  .tg-table thead th { padding: 0.65rem 1rem; text-align: center; font-size: 0.62rem; letter-spacing: 1.2px; text-transform: uppercase; color: var(--text-muted); font-weight: 700; white-space: nowrap; }
  .tg-table tbody tr { border-bottom: 1px solid var(--border); transition: background 0.12s; }
  .tg-table tbody tr:last-child { border-bottom: none; }
  .tg-table tbody tr:hover { background: var(--gold-pale); }
  .tg-table tbody td { padding: 0.8rem 1rem; font-size: 0.8rem; color: var(--text-secondary); vertical-align: middle; text-align: center; }

  @media (max-width: 768px) {
    .tg-table-wrap,
    .card > .tg-table,
    .card > div > .tg-table,
    .tg-table {
      display: block;
      width: 100%;
      overflow-x: auto;
      overflow-y: auto;
      max-height: 60vh;
      -webkit-overflow-scrolling: touch;
    }
    .tg-table thead th:last-child,
    .tg-table tbody td:last-child {
      position: sticky;
      right: 0;
      background: var(--bg-3);
      z-index: 2;
      box-shadow: -3px 0 6px rgba(0,0,0,0.08);
    }
    .tg-table thead th { position: sticky; top: 0; z-index: 3; background: var(--bg-2); }
    .tg-table thead th:last-child { z-index: 4; }
    .tg-table tbody tr:hover td:last-child { background: var(--gold-pale); }
  }

  /* ── BADGES ── */
  .plate-chip { display: inline-flex; background: var(--btn-bg); color: var(--btn-text); padding: 0.2rem 0.6rem; border-radius: 4px; font-size: 0.72rem; font-weight: 800; letter-spacing: 1.5px; }
  .badge-dark {
  display: inline-block;background: #FFFFFF;color: #1a7a1a;font-family: 'Big Shoulders Text', 'Courier New', monospace;font-weight: 800;font-size: 0.85rem;padding: 0.25rem 0.75rem;border-radius: 3px;letter-spacing: 3px;border: 1.5px solid #1a7a1a;text-transform: uppercase;
}
  .badge { display: inline-flex; align-items: center; padding: 0.2rem 0.65rem; border-radius: 100px; font-size: 0.67rem; font-weight: 700; letter-spacing: 0.3px; white-space: nowrap; }
  .badge-green  { background: var(--success-bg); color: var(--success); }
  .badge-yellow { background: var(--warning-bg); color: var(--warning); }
  .badge-red    { background: var(--danger-bg);  color: var(--danger); }
  .badge-orange { background: rgba(230,126,34,0.15); color: #e67e22; border: 1px solid rgba(230,126,34,0.3); }
  .badge-gold   { background: var(--gold-light); color: var(--gold); }
  .badge-gray   { background: var(--bg-2); color: var(--text-muted); border: 1px solid var(--border); }
  .badge-blue   { background: var(--info-bg, rgba(59,130,246,0.1)); color: var(--info, #3b82f6); }
  .badge-info   { background: var(--info-bg, rgba(59,130,246,0.1)); color: var(--info, #3b82f6); }

  /* ── EMPTY STATE ── */
  .empty-state { padding: 3rem 2rem; text-align: center; }
  .empty-icon  { font-size: 2rem; opacity: 0.3; margin-bottom: 0.6rem; }
  .empty-title { font-size: 0.9rem; font-weight: 600; color: var(--text-secondary); margin-bottom: 0.3rem; }
  .empty-desc  { font-size: 0.75rem; color: var(--text-muted); margin-bottom: 1.25rem; }

  /* ── FIELD SECTION DIVIDER ── */
  .field-section { font-size: 0.62rem; letter-spacing: 2px; text-transform: uppercase; color: var(--gold); font-weight: 700; margin: 1.25rem 0 1rem; display: flex; align-items: center; gap: 0.75rem; }
  .field-section::after { content: ''; flex: 1; height: 1px; background: var(--border); }

  /* ── SCROLLBAR ── */
  ::-webkit-scrollbar { width: 4px; }
  ::-webkit-scrollbar-track { background: var(--bg); }
  ::-webkit-scrollbar-thumb { background: var(--gold-muted); border-radius: 2px; }

  /* ── RESPONSIVE: TABLET ── */
  @media (max-width: 1024px) {
    .topbar  { padding: 0.9rem 1.5rem; }
    .content { padding: 1.5rem; }
  }

  /* ── MOBILE APP SHELL ── */

  /* Bottom nav bar */
  .mob-nav {
    display: none;
    position: fixed;
    bottom: 0; left: 0; right: 0;
    height: 62px;
    background: var(--bg-3);
    border-top: 1px solid var(--border);
    z-index: 200;
    box-shadow: 0 -4px 20px rgba(0,0,0,0.08);
  }
  .mob-nav-inner {
    display: flex;
    height: 100%;
    align-items: stretch;
    width: 100%;
  }
  .mob-nav-item {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 3px;
    text-decoration: none;
    color: var(--text-muted);
    font-size: 0.55rem;
    font-weight: 600;
    letter-spacing: 0.3px;
    text-transform: uppercase;
    transition: color 0.15s;
    padding: 0.4rem 0.2rem;
    position: relative;
    -webkit-tap-highlight-color: transparent;
    background: none;
    border: none;
    cursor: pointer;
    font-family: 'Plus Jakarta Sans', sans-serif;
  }
  .mob-nav-item svg { flex-shrink: 0; transition: transform 0.15s; }
  .mob-nav-item:active svg { transform: scale(0.88); }
  .mob-nav-item.active { color: var(--gold); }
  .mob-nav-item.active svg { stroke: var(--gold); }
  .mob-nav-item.active::before {
    content: '';
    position: absolute;
    top: 0; left: 20%; right: 20%;
    height: 2.5px;
    background: var(--gold);
    border-radius: 0 0 4px 4px;
  }
  .mob-nav-badge {
    position: absolute;
    top: 6px; right: calc(50% - 14px);
    background: var(--danger);
    color: #fff;
    font-size: 0.5rem;
    font-weight: 800;
    min-width: 14px;
    height: 14px;
    border-radius: 7px;
    padding: 0 3px;
    display: none;
    align-items: center;
    justify-content: center;
    line-height: 1;
  }

  /* Mobile topbar (replaces desktop topbar) */
  .mob-topbar {
    display: none;
    position: sticky;
    top: 0;
    z-index: 40;
    background: var(--bg-3);
    border-bottom: 1px solid var(--border);
    padding: 0 1rem;
    height: 54px;
    align-items: center;
    justify-content: space-between;
    box-shadow: 0 1px 8px rgba(0,0,0,0.06);
  }
  .mob-topbar-title {
    font-size: 0.95rem;
    font-weight: 800;
    color: var(--text-primary);
    letter-spacing: -0.2px;
  }
  .mob-topbar-avatar {
    width: 34px; height: 34px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--gold-bright), var(--gold));
    display: flex; align-items: center; justify-content: center;
    font-size: 0.65rem; font-weight: 800; color: #fff;
    overflow: hidden;
    border: 2px solid var(--gold-muted);
    flex-shrink: 0;
    cursor: pointer;
    -webkit-tap-highlight-color: transparent;
  }

  /* More sheet */
  .mob-more-overlay {
    display: none;
    position: fixed; inset: 0;
    background: rgba(0,0,0,0.45);
    z-index: 300;
    backdrop-filter: blur(2px);
  }
  .mob-more-overlay.open { display: block; }
  .mob-more-sheet {
    position: fixed;
    bottom: 0; left: 0; right: 0;
    background: var(--bg-3);
    border-radius: 20px 20px 0 0;
    border-top: 1px solid var(--border);
    z-index: 301;
    padding: 0 0 env(safe-area-inset-bottom, 0);
    transform: translateY(100%);
    transition: transform 0.28s cubic-bezier(0.32,0.72,0,1);
    box-shadow: 0 -8px 32px rgba(0,0,0,0.15);
  }
  .mob-more-sheet.open { transform: translateY(0); }
  .mob-more-handle {
    width: 36px; height: 4px;
    background: var(--border);
    border-radius: 2px;
    margin: 0.75rem auto 0;
  }
  .mob-more-header {
    display: flex; align-items: center; gap: 0.75rem;
    padding: 0.9rem 1.25rem 0.75rem;
    border-bottom: 1px solid var(--border);
  }
  .mob-more-avatar {
    width: 42px; height: 42px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--gold-bright), var(--gold));
    display: flex; align-items: center; justify-content: center;
    font-size: 0.8rem; font-weight: 800; color: #fff;
    overflow: hidden; flex-shrink: 0;
    border: 2px solid var(--gold-muted);
  }
  .mob-more-name { font-size: 0.88rem; font-weight: 700; color: var(--text-primary); line-height: 1.3; }
  .mob-more-role { font-size: 0.68rem; color: var(--text-muted); }
  .mob-more-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 0.5rem;
    padding: 1rem;
  }
  .mob-more-item {
    display: flex; flex-direction: column; align-items: center;
    gap: 0.45rem; padding: 0.85rem 0.5rem;
    border-radius: 12px;
    background: var(--bg-2);
    border: 1px solid var(--border);
    text-decoration: none; color: var(--text-secondary);
    font-size: 0.6rem; font-weight: 600; letter-spacing: 0.2px;
    text-align: center; text-transform: uppercase;
    -webkit-tap-highlight-color: transparent;
    transition: background 0.12s, border-color 0.12s, color 0.12s;
    cursor: pointer; background-color: var(--bg-2);
    font-family: 'Plus Jakarta Sans', sans-serif;
    border: 1px solid var(--border);
  }
  .mob-more-item:active, .mob-more-item:hover {
    background: var(--gold-pale);
    border-color: var(--gold-muted);
    color: var(--gold);
  }
  .mob-more-item-icon {
    width: 40px; height: 40px;
    border-radius: 10px;
    background: var(--bg-3);
    border: 1px solid var(--border);
    display: flex; align-items: center; justify-content: center;
    color: var(--gold);
    flex-shrink: 0;
  }
  .mob-more-divider {
    height: 1px; background: var(--border);
    margin: 0 1rem;
  }
  .mob-more-logout {
    display: flex; align-items: center; gap: 0.75rem;
    padding: 0.9rem 1.25rem;
    text-decoration: none; color: var(--danger);
    font-size: 0.82rem; font-weight: 600;
    -webkit-tap-highlight-color: transparent;
  }

  /* ── RESPONSIVE: MOBILE ── */
  @media (max-width: 768px) {
    .main      { margin-left: 0; }
    .hamburger { display: none; }
    .topbar    { display: none !important; }
    .sidebar   { display: none !important; }
    .sidebar-overlay { display: none !important; }
    .mob-nav     { display: flex; }
    .mob-topbar  { display: flex; }
    .content { padding: 1rem; padding-bottom: 80px; }

    /* Forms */
    .form-grid, .form-grid-3 { grid-template-columns: 1fr; }
    .span-2, .span-3 { grid-column: span 1; }
    .form-actions { padding: 0.9rem 1rem; flex-direction: column; }
    .form-actions .btn-ghost,
    .form-actions .btn-primary { width: 100%; justify-content: center; }

    /* Cards */
    .card-header { padding: 0.85rem 1rem; flex-wrap: wrap; gap: 0.5rem; }
    .card-body   { padding: 1rem; }
    .card-header > *:last-child { margin-left: 0 !important; }

    /* Tables — horizontal scroll */
    .tg-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; border-radius: 0 0 12px 12px; }
    .tg-table { min-width: 540px; }
    .tg-table thead th,
    .tg-table tbody td { padding: 0.6rem 0.75rem; font-size: 0.72rem; }

    /* Stack ALL inline grids */
    .content [style*="grid-template-columns"] { grid-template-columns: 1fr !important; }

    /* Stack inline flex rows (filter bars, button rows) */
    .content > div[style*="display:flex"][style*="gap"],
    .content > div[style*="display: flex"][style*="gap"] { flex-wrap: wrap; }

    /* Filter inputs full width */
    .filter-input { width: 100% !important; min-width: 0 !important; max-width: none !important; }

    /* Buttons */
    .btn-primary, .btn-gold, .btn-ghost, .btn-danger { font-size: 0.78rem; padding: 0.65rem 1rem; }

    /* Stat card rows */
    .qt-stats { grid-template-columns: 1fr 1fr !important; }

    /* Action button groups in table cells */
    .tg-table td div[style*="display:flex"] { flex-wrap: wrap; justify-content: center; }

    /* Page-level back+header rows */
    .content > div[style*="justify-content:space-between"] { flex-direction: column; align-items: flex-start !important; gap: 0.75rem; }
    .content > div[style*="justify-content:space-between"] > div { width: 100%; }

    /* Two-col detail grids inside cards (e.g. view_repair top grid) */
    .content .card + .card,
    .content > div[style*="display:grid"] { gap: 0.9rem !important; }
  }

  /* ── RESPONSIVE: SMALL MOBILE ── */
  @media (max-width: 480px) {
    .content { padding: 0.75rem; }
    .topbar  { padding: 0.65rem 0.75rem; padding-left: 3rem; }
    .user-chip { padding: 0.3rem 0.5rem 0.3rem 0.35rem; font-size: 0; }
    .user-chip .user-avatar  { font-size: 0.62rem; }
    .user-chip-chevron       { display: none; }

    .tg-table { min-width: 480px; }
    .tg-table thead th { font-size: 0.55rem; padding: 0.5rem; }
    .tg-table tbody td { font-size: 0.68rem; padding: 0.5rem; }

    .card-title        { font-size: 0.82rem; }
    .card-header       { flex-direction: column; align-items: flex-start; }
    .card-header .btn-primary,
    .card-header .btn-sm-gold { width: 100%; justify-content: center; }

    .qt-stats { grid-template-columns: 1fr !important; }

    /* Stat numbers smaller */
    .qt-stat-value { font-size: 1.4rem !important; }

    /* Filter search bar full width */
    .content form > div,
    .content > form > div { flex-direction: column; }
    .content form .btn-primary,
    .content form .btn-ghost { width: 100%; justify-content: center; }

    /* Badge wrap */
    .badge { font-size: 0.62rem; padding: 0.15rem 0.5rem; }
  }
  /* ── ROW EXPAND ── */
  .tg-expandable-row:hover { background: var(--gold-pale); }
  .tg-expandable-row.expanded { background: var(--gold-pale); }
  .tg-expandable-row.expanded .row-chevron { transform: rotate(90deg); opacity: 0.7 !important; }
  .tg-expand-row td { background: var(--bg-2); }
  .tg-expand-body {
    padding: 0.85rem 1.25rem 0.85rem 2.9rem;
    border-top: 1px solid var(--border);
    border-bottom: 1px solid var(--border);
    animation: expandIn 0.18s ease;
  }
  @keyframes expandIn {
    from { opacity: 0; transform: translateY(-4px); }
    to   { opacity: 1; transform: translateY(0); }
  }
  .tg-expand-grid {
    display: flex; flex-wrap: wrap; gap: 1.25rem 2.5rem;
  }
  .tg-expand-item { display: flex; flex-direction: column; gap: 0.2rem; }
  .tg-expand-label { font-size: 0.62rem; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; color: var(--text-muted); }
  .tg-expand-value { font-size: 0.8rem; color: var(--text-primary); font-weight: 500; }

  /* Page loading cursor */
  html.tg-loading, html.tg-loading * { cursor: wait !important; }

  /* ── GLOBAL SEARCH ── */
  .gs-wrap { position: relative; }
  .gs-input-row {
    display: flex; align-items: center; gap: 0.5rem;
    background: var(--bg); border: 1px solid var(--border);
    border-radius: 9px; padding: 0.52rem 0.9rem;
    transition: border-color 0.15s, box-shadow 0.15s;
    width: 300px;
  }
  .gs-wrap.focused .gs-input-row {
    border-color: var(--gold-bright);
    box-shadow: 0 0 0 3px rgba(212,160,23,0.12);
    background: var(--bg-3);
  }
  .gs-icon { color: var(--text-muted); flex-shrink: 0; }
  .gs-input {
    flex: 1; background: none; border: none; outline: none;
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 0.875rem; color: var(--text-primary);
    min-width: 0;
  }
  .gs-input::placeholder { color: var(--text-muted); }
  .gs-kbd {
    font-size: 0.6rem; font-family: 'Plus Jakarta Sans', sans-serif;
    font-weight: 700; color: var(--text-muted);
    background: var(--bg-2); border: 1px solid var(--border);
    border-radius: 4px; padding: 0.1rem 0.35rem;
    flex-shrink: 0; transition: opacity 0.15s;
  }
  .gs-wrap.focused .gs-kbd { opacity: 0; pointer-events: none; }
  .gs-dropdown {
    position: absolute; top: calc(100% + 6px); left: 0; right: 0;
    background: var(--bg-3); border: 1px solid var(--border);
    border-radius: 12px; box-shadow: var(--shadow-lg);
    z-index: 200; overflow: hidden; min-width: 340px;
  }
  .gs-group-label {
    font-size: 0.58rem; letter-spacing: 1.5px; text-transform: uppercase;
    font-weight: 700; color: var(--text-muted);
    padding: 0.6rem 0.85rem 0.3rem;
  }
  .gs-item {
    display: flex; align-items: center; gap: 0.7rem;
    padding: 0.55rem 0.85rem; cursor: pointer;
    text-decoration: none; transition: background 0.1s;
    border-radius: 0;
  }
  .gs-item:hover, .gs-item.active { background: var(--gold-pale); }
  .gs-item-icon {
    width: 28px; height: 28px; border-radius: 7px;
    background: var(--bg-2); border: 1px solid var(--border);
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; color: var(--gold);
  }
  .gs-item-label {
    font-size: 0.8rem; font-weight: 600; color: var(--text-primary);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  }
  .gs-item-sub {
    font-size: 0.67rem; color: var(--text-muted);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  }
  .gs-empty {
    padding: 1.25rem 0.85rem; text-align: center;
    font-size: 0.78rem; color: var(--text-muted);
  }
  .gs-footer {
    padding: 0.45rem 0.85rem; border-top: 1px solid var(--border);
    background: var(--bg-2); font-size: 0.63rem; color: var(--text-muted);
    display: flex; gap: 0.75rem;
  }
  .gs-footer kbd {
    font-family: 'Plus Jakarta Sans', sans-serif;
    background: var(--bg); border: 1px solid var(--border);
    border-radius: 3px; padding: 0.05rem 0.3rem; font-size: 0.6rem;
  }
  @media (max-width: 768px) {
    .gs-wrap { display: none; }
  }
</style>
<link rel="stylesheet" href="<?= $base_path ?>assets/css/shared/mobile_tables.css?v=<?= filemtime(__DIR__ . '/../assets/css/shared/mobile_tables.css') ?>"/>
<?= $extra_css ?? '' ?>
</head>
<body>

<div class="sidebar-overlay" id="sidebar-overlay"></div>

<?php
/* ── MOBILE BOTTOM NAV ── */
$_mob_role        = $_SESSION['role'] ?? '';
$_mob_active      = $active_page ?? '';
$_mob_is_admin    = in_array($_mob_role, ['admin', 'super_admin']);
$_mob_is_mechanic = $_mob_role === 'mechanic';

// Determine nav items per role
if ($_mob_is_admin) {
    $_mob_nav = [
        ['id' => 'dashboard', 'label' => 'Home',    'href' => $base_path . 'modules/admin/dashboard_admin.php',    'icon' => 'home'],
        ['id' => 'clients',   'label' => 'Clients', 'href' => $base_path . 'modules/clients/client_list.php',      'icon' => 'users'],
        ['id' => 'repair',    'label' => 'Repairs', 'href' => $base_path . 'modules/repair/repair_list.php',       'icon' => 'wrench'],
        ['id' => 'policy',    'label' => 'Policy',  'href' => $base_path . 'modules/renewal/renewal_list.php',     'icon' => 'shield'],
        ['id' => 'more',      'label' => 'More',    'href' => '#', 'icon' => 'more'],
    ];
    // Policy tab is active for renewal/insurance/claims/billing pages
    $_mob_policy_pages = ['renewal', 'insurance', 'claims', 'billing', 'quotations'];
    if (in_array($_mob_active, $_mob_policy_pages)) $_mob_active = 'policy';
    // More tab active for settings/admin pages
    $_mob_more_pages = ['settings', 'manage_users', 'activity_log', 'monthly_report', 'my_account'];
    if (in_array($_mob_active, $_mob_more_pages)) $_mob_active = 'more';
} else {
    $_mob_nav = [
        ['id' => 'dashboard', 'label' => 'Home',    'href' => $base_path . 'modules/repair/dashboard_mechanic.php', 'icon' => 'home'],
        ['id' => 'clients',   'label' => 'Clients', 'href' => $base_path . 'modules/clients/client_list.php',       'icon' => 'users'],
        ['id' => 'repair',    'label' => 'Repairs', 'href' => $base_path . 'modules/repair/repair_list.php',        'icon' => 'wrench'],
        ['id' => 'more',      'label' => 'More',    'href' => '#', 'icon' => 'more'],
    ];
}

function _mob_icon(string $name): string {
    return match($name) {
        'home'        => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
        'users'       => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        'wrench'      => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>',
        'shield'      => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
        'user-circle' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
        'more'        => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/></svg>',
        default       => '',
    };
}
?>
<nav class="mob-nav" id="mob-nav">
  <div class="mob-nav-inner">
    <?php foreach ($_mob_nav as $_n):
        $_active_class = ($_n['id'] === $_mob_active) ? ' active' : '';
        $_is_btn       = in_array($_n['id'], ['more', 'profile']);
    ?>
    <?php if ($_is_btn): ?>
    <button type="button" class="mob-nav-item<?= $_active_class ?>" id="mob-nav-<?= $_n['id'] ?>-btn">
      <?= _mob_icon($_n['icon']) ?>
      <span><?= $_n['label'] ?></span>
    </button>
    <?php else: ?>
    <a href="<?= htmlspecialchars($_n['href']) ?>" class="mob-nav-item<?= $_active_class ?>">
      <?= _mob_icon($_n['icon']) ?>
      <?php if ($_n['id'] === 'policy'): ?>
      <span id="mob-expiry-badge" class="mob-nav-badge"></span>
      <?php endif; ?>
      <span><?= $_n['label'] ?></span>
    </a>
    <?php endif; ?>
    <?php endforeach; ?>
  </div>
</nav>

<!-- ── MOBILE MORE SHEET ── -->
<div class="mob-more-overlay" id="mob-more-overlay"></div>
<div class="mob-more-sheet" id="mob-more-sheet">
  <div class="mob-more-handle"></div>
  <div class="mob-more-header">
    <div class="mob-more-avatar" id="mob-more-avatar">
      <?php
      $_mob_full   = $_SESSION['full_name'] ?? 'User';
      $_mob_inits  = strtoupper(substr(implode('', array_map(fn($w) => $w[0] ?? '', array_filter(explode(' ', $_mob_full)))), 0, 2));
      echo htmlspecialchars($_mob_inits);
      ?>
    </div>
    <div>
      <div class="mob-more-name"><?= htmlspecialchars($_mob_full) ?></div>
      <div class="mob-more-role"><?= $role_label ?></div>
    </div>
  </div>
  <div class="mob-more-grid">
    <button type="button" class="mob-more-item" onclick="mobMoreClose();setTimeout(()=>window.openEditProfileModal&&window.openEditProfileModal(),200)">
      <div class="mob-more-item-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div>
      <span>Profile</span>
    </button>
    <a href="<?= $base_path ?>modules/admin/settings.php" class="mob-more-item" onclick="mobMoreClose()">
      <div class="mob-more-item-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg></div>
      <span>Settings</span>
    </a>
    <?php if ($_mob_is_admin): ?>
    <a href="<?= $base_path ?>modules/admin/monthly_report.php" class="mob-more-item" onclick="mobMoreClose()">
      <div class="mob-more-item-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg></div>
      <span>Reports</span>
    </a>
    <a href="<?= $base_path ?>modules/admin/manage_users.php" class="mob-more-item" onclick="mobMoreClose()">
      <div class="mob-more-item-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
      <span>Users</span>
    </a>
    <a href="<?= $base_path ?>modules/admin/activity_log.php" class="mob-more-item" onclick="mobMoreClose()">
      <div class="mob-more-item-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg></div>
      <span>Logs</span>
    </a>
    <?php endif; ?>
  </div>
  <div class="mob-more-divider"></div>
  <a href="<?= $base_path ?>auth/logout.php" class="mob-more-logout">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
    Logout
  </a>
</div>

<script>
  function toggleSidebar() {
    const sidebar   = document.getElementById('tg-sidebar');
    const overlay   = document.getElementById('sidebar-overlay');
    const hamburger = document.getElementById('hamburger-btn');
    if (!sidebar) return;
    const isOpen = sidebar.classList.toggle('open');
    overlay.classList.toggle('active', isOpen);
    if (hamburger) hamburger.classList.toggle('open', isOpen);
  }

  // ── Mobile More Sheet ──
  function mobMoreOpen() {
    document.getElementById('mob-more-overlay').classList.add('open');
    document.getElementById('mob-more-sheet').classList.add('open');
  }
  function mobMoreClose() {
    document.getElementById('mob-more-overlay').classList.remove('open');
    document.getElementById('mob-more-sheet').classList.remove('open');
  }
  window.mobMoreOpen  = mobMoreOpen;
  window.mobMoreClose = mobMoreClose;

  document.addEventListener('DOMContentLoaded', function () {
    const overlay = document.getElementById('sidebar-overlay');
    if (overlay) overlay.addEventListener('click', toggleSidebar);

    // More button
    const moreBtn = document.getElementById('mob-nav-more-btn');
    if (moreBtn) moreBtn.addEventListener('click', mobMoreOpen);

    // Profile button (mechanic)
    const profileBtn = document.getElementById('mob-nav-profile-btn');
    if (profileBtn) profileBtn.addEventListener('click', function() {
      window.openEditProfileModal && window.openEditProfileModal();
    });

    // Overlay click closes sheet
    const moreOverlay = document.getElementById('mob-more-overlay');
    if (moreOverlay) moreOverlay.addEventListener('click', mobMoreClose);

    // Mobile: tap nav-item with chevron to toggle accordion flyout
    document.querySelectorAll('.nav-item-wrap').forEach(function(wrap) {
      const item = wrap.querySelector('.nav-item');
      const flyout = wrap.querySelector('.nav-flyout');
      if (!item || !flyout) return;
      item.addEventListener('click', function(e) {
        if (window.innerWidth > 768) return; // desktop uses hover
        e.preventDefault();
        wrap.classList.toggle('mob-open');
      });
    });
  });

  // ── Global Transaction PIN verifier ──
  // Usage: const ok = await requirePin(); if (!ok) return;
  window.requirePin = async function() {
    const base = document.querySelector('meta[name="base_path"]')?.content || '/TG-BASICS/';
    const endpoint = base + 'ajax/verify_pin.php';

    // Check if user has a PIN (send empty pin = just checking existence)
    let chk;
    try { chk = await fetch(endpoint, { method:'POST', body: new FormData() }).then(r=>r.json()); } catch(e) { chk = null; }
    if (!chk || chk?.no_pin) return true; // no PIN set or fetch failed — skip prompt

    // Show PIN prompt
    const result = await Swal.fire({
      title: 'Enter Transaction PIN',
      html: '<input id="swal-pin-input" type="password" inputmode="numeric" maxlength="6" class="swal2-input" placeholder="Enter your PIN" autocomplete="off" style="letter-spacing:0.4rem;font-size:1.2rem;text-align:center;"/>',
      confirmButtonText: 'Confirm',
      confirmButtonColor: '#1C1A17',
      cancelButtonText: 'Cancel',
      showCancelButton: true,
      cancelButtonColor: '#6B7280',
      didOpen: () => document.getElementById('swal-pin-input').focus(),
      preConfirm: () => {
        const pin = document.getElementById('swal-pin-input').value.trim();
        if (!pin) { Swal.showValidationMessage('PIN is required.'); return false; }
        return pin;
      }
    });
    if (!result.isConfirmed) return false;

    const vfd = new FormData();
    vfd.append('pin', result.value);
    let vres;
    try { vres = await fetch(endpoint, { method:'POST', body:vfd }).then(r=>r.json()); } catch(e) { return false; }
    if (vres?.ok) return true;
    Swal.fire({ icon:'error', title:'Incorrect PIN', text: vres?.error || 'Wrong PIN.', confirmButtonColor:'#B8860B' });
    return false;
  };

  // Loading cursor — show on navigation, hide when page is fully ready
  (function () {
    document.documentElement.classList.add('tg-loading');
    window.addEventListener('load', function () {
      document.documentElement.classList.remove('tg-loading');
    });
    // Also trigger on link/form navigation
    document.addEventListener('click', function (e) {
      const a = e.target.closest('a[href]');
      if (a && !a.target && !a.href.startsWith('#') && !a.href.startsWith('javascript')) {
        if (a.closest('.user-dropdown')) return;
        document.documentElement.classList.add('tg-loading');
      }
    });
    document.addEventListener('submit', function (e) {
      if (!e.defaultPrevented) document.documentElement.classList.add('tg-loading');
    });
  })();
</script>