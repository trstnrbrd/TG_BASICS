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
    --shadow: 0 1px 3px rgba(0,0,0,0.08), 0 1px 2px rgba(0,0,0,0.04);
    --shadow-md: 0 4px 16px rgba(0,0,0,0.08), 0 2px 6px rgba(0,0,0,0.04);
    --shadow-lg: 0 12px 40px rgba(0,0,0,0.12), 0 4px 12px rgba(0,0,0,0.06);
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
    --sidebar-width: 232px;
  }

  [data-theme="dark"] {
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
    background: var(--bg); border: 1px solid var(--border);
    padding: 0.35rem 0.75rem 0.35rem 0.45rem;
    border-radius: 100px; font-size: 0.75rem;
    color: var(--text-secondary); font-weight: 500; white-space: nowrap;
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
    display: inline-flex; align-items: center; gap: 0.4rem;
    color: var(--text-muted); text-decoration: none;
    font-size: 0.78rem; font-weight: 500; margin-bottom: 1.5rem; transition: color 0.15s;
  }
  .back-link:hover { color: var(--gold); }

  /* ── PAGE HEADER ── */
  .page-header {
    background: var(--sidebar-bg); border-radius: 12px;
    padding: 1.5rem 1.75rem; margin-bottom: 1.75rem;
    position: relative; overflow: hidden;
  }
  .page-header::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; background: linear-gradient(90deg, var(--gold-bright), var(--gold-muted), transparent); }
  .page-header-title { font-size: 1.1rem; font-weight: 800; color: #fff; margin-bottom: 0.2rem; }
  .page-header-sub   { font-size: 0.75rem; color: rgba(200,192,176,0.5); }

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
  .btn-primary { background: var(--sidebar-bg); color: var(--gold-bright); border: none; padding: 0.7rem 1.5rem; border-radius: 9px; font-family: 'Plus Jakarta Sans', sans-serif; font-size: 0.83rem; font-weight: 700; cursor: pointer; text-decoration: none; transition: all 0.15s; display: inline-flex; align-items: center; gap: 0.5rem; box-shadow: 0 4px 12px rgba(28,26,23,0.2); white-space: nowrap; }
  .btn-primary:hover { background: #2A2724; box-shadow: 0 6px 16px rgba(28,26,23,0.3); }

  .btn-gold { background: var(--gold); color: #fff; border: none; padding: 0.7rem 1.5rem; border-radius: 9px; font-family: 'Plus Jakarta Sans', sans-serif; font-size: 0.83rem; font-weight: 700; cursor: pointer; text-decoration: none; transition: all 0.15s; display: inline-flex; align-items: center; gap: 0.5rem; box-shadow: 0 4px 12px rgba(184,134,11,0.25); white-space: nowrap; }
  .btn-gold:hover { background: var(--gold-bright); }

  .btn-ghost { background: var(--bg-3); color: var(--text-secondary); border: 1px solid var(--border); padding: 0.7rem 1.25rem; border-radius: 9px; font-family: 'Plus Jakarta Sans', sans-serif; font-size: 0.83rem; font-weight: 500; text-decoration: none; transition: all 0.15s; display: inline-flex; align-items: center; gap: 0.5rem; cursor: pointer; white-space: nowrap; }
  .btn-ghost:hover { border-color: var(--gold-muted); color: var(--gold); }

  .btn-danger { background: var(--danger); color: #fff; border: none; padding: 0.7rem 1.5rem; border-radius: 9px; font-family: 'Plus Jakarta Sans', sans-serif; font-size: 0.83rem; font-weight: 700; cursor: pointer; text-decoration: none; transition: all 0.15s; display: inline-flex; align-items: center; gap: 0.5rem; box-shadow: 0 4px 12px rgba(192,57,43,0.25); white-space: nowrap; }
  .btn-danger:hover { background: #A93226; }

  .btn-sm-gold { background: var(--gold); color: #fff; border: none; padding: 0.35rem 0.85rem; border-radius: 7px; font-size: 0.75rem; font-weight: 700; text-decoration: none; transition: all 0.15s; white-space: nowrap; display: inline-flex; align-items: center; gap: 0.35rem; font-family: 'Plus Jakarta Sans', sans-serif; cursor: pointer; }
  .btn-sm-gold:hover { background: var(--gold-bright); color: #fff; }

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
  .tg-table { width: 100%; border-collapse: collapse; }
  .tg-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
  .tg-table thead tr { background: var(--bg-2); border-bottom: 1px solid var(--border); }
  .tg-table thead th { padding: 0.65rem 1rem; text-align: left; font-size: 0.62rem; letter-spacing: 1.2px; text-transform: uppercase; color: var(--text-muted); font-weight: 700; white-space: nowrap; }
  .tg-table tbody tr { border-bottom: 1px solid var(--border); transition: background 0.12s; }
  .tg-table tbody tr:last-child { border-bottom: none; }
  .tg-table tbody tr:hover { background: var(--gold-pale); }
  .tg-table tbody td { padding: 0.8rem 1rem; font-size: 0.8rem; color: var(--text-secondary); vertical-align: middle; }

  /* ── BADGES ── */
  .plate-chip { display: inline-flex; background: var(--sidebar-bg); color: var(--gold-bright); padding: 0.2rem 0.6rem; border-radius: 4px; font-size: 0.72rem; font-weight: 800; letter-spacing: 1.5px; }
  .badge-dark {
  display: inline-block;background: #FFFFFF;color: #1a7a1a;font-family: 'Big Shoulders Text', 'Courier New', monospace;font-weight: 800;font-size: 0.85rem;padding: 0.25rem 0.75rem;border-radius: 3px;letter-spacing: 3px;border: 1.5px solid #1a7a1a;text-transform: uppercase;
}
  .badge { display: inline-flex; align-items: center; padding: 0.2rem 0.65rem; border-radius: 100px; font-size: 0.67rem; font-weight: 700; letter-spacing: 0.3px; white-space: nowrap; }
  .badge-green  { background: var(--success-bg); color: var(--success); }
  .badge-yellow { background: var(--warning-bg); color: var(--warning); }
  .badge-red    { background: var(--danger-bg);  color: var(--danger); }
  .badge-gold   { background: var(--gold-light); color: var(--gold); }
  .badge-gray   { background: var(--bg-2); color: var(--text-muted); border: 1px solid var(--border); }

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
    .topbar { padding: 0.9rem 1.5rem; }
    .content { padding: 1.5rem; }
  }

  /* ── RESPONSIVE: MOBILE ── */
  @media (max-width: 768px) {
    .main { margin-left: 0; }
    .hamburger { display: flex; }
    .topbar { padding: 0.75rem 1rem; padding-left: 3.25rem; }
    .topbar-breadcrumb { display: none; }
    .user-chip-label { display: none; }
    .content { padding: 1rem; }
    .form-grid, .form-grid-3 { grid-template-columns: 1fr; }
    .span-2, .span-3 { grid-column: span 1; }
    .page-header { padding: 1.1rem 1.25rem; }
    .page-header-title { font-size: 0.95rem; }
    .card-header { padding: 0.85rem 1rem; flex-direction: column; align-items: flex-start; }
    .card-body { padding: 1rem; }
    .form-actions { padding: 0.9rem 1rem; }
    .form-actions .btn-ghost,
    .form-actions .btn-primary { flex: 1; justify-content: center; }
    .tg-table thead th,
    .tg-table tbody td { padding: 0.6rem 0.75rem; font-size: 0.72rem; }

    /* Force inline grids to stack on mobile */
    .content [style*="grid-template-columns: repeat(3"],
    .content [style*="grid-template-columns: repeat(4"],
    .content [style*="grid-template-columns: repeat(5"],
    .content [style*="grid-template-columns:repeat(3"],
    .content [style*="grid-template-columns:repeat(4"],
    .content [style*="grid-template-columns:repeat(5"] {
      grid-template-columns: 1fr !important;
    }
    .content [style*="grid-template-columns: 1fr 1fr"],
    .content [style*="grid-template-columns:1fr 1fr"] {
      grid-template-columns: 1fr !important;
    }

    /* Filter toolbars: stack vertically */
    .content form[method] > div[style*="display:flex"],
    .content form[method] > div[style*="display: flex"] {
      flex-direction: column;
    }
    .content form[method] [style*="min-width:200px"],
    .content form[method] [style*="min-width: 200px"] {
      min-width: 0 !important;
      max-width: none !important;
      width: 100%;
    }

    /* Table action buttons: stack */
    .tg-table td [style*="display:flex"][style*="gap"] {
      flex-wrap: wrap;
    }

    /* Buttons full width on mobile */
    .btn-primary, .btn-gold, .btn-ghost, .btn-danger {
      font-size: 0.78rem;
      padding: 0.65rem 1rem;
    }
  }

  /* ── RESPONSIVE: SMALL MOBILE ── */
  @media (max-width: 480px) {
    .content { padding: 0.75rem; }
    .topbar { padding: 0.65rem 0.75rem; padding-left: 3rem; }
    .page-header { padding: 1rem; border-radius: 10px; }
    .user-chip { padding: 0.3rem 0.5rem 0.3rem 0.35rem; font-size: 0; }
    .user-chip .user-avatar { font-size: 0.62rem; }
    .user-chip-chevron { display: none; }

    /* Smaller text for tight screens */
    .tg-table thead th { font-size: 0.55rem; padding: 0.5rem 0.5rem; }
    .tg-table tbody td { font-size: 0.7rem; padding: 0.55rem 0.5rem; }
    .card-title { font-size: 0.82rem; }
    .page-header-title { font-size: 0.88rem; }
    .page-header-sub { font-size: 0.65rem; }
  }
</style>
<?= $extra_css ?? '' ?>
</head>
<body>

<div class="sidebar-overlay" id="sidebar-overlay"></div>

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

  document.addEventListener('DOMContentLoaded', function () {
    const overlay = document.getElementById('sidebar-overlay');
    if (overlay) overlay.addEventListener('click', toggleSidebar);
  });
</script>