<?php
$page_title = $page_title ?? 'TG-BASICS';
$base_path  = $base_path  ?? '../';
$role_label = match($_SESSION['role'] ?? '') {
    'super_admin' => 'Owner',
    'admin'       => 'Admin',
    'mechanic'    => 'Mechanic',
    default       => 'User'
};

/**
 * icon($name, $size, $class)
 * Returns an inline SVG Heroicon (outline style)
 */
function icon(string $name, int $size = 18, string $class = ''): string {
    $s = $size;
    $c = $class ? " class=\"{$class}\"" : '';
    $icons = [

      'dashboard' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z"/>',

      'users' => '<path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/>',

      'user' => '<path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/>',

      'vehicle' => '<path stroke-linecap="round" stroke-linejoin="round" d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.177v-.958c0-.568-.422-1.048-.987-1.106a48.554 48.554 0 00-10.026 0 1.106 1.106 0 00-.987 1.106v7.635m12-6.677v6.677m0 4.5v-4.5m0 0h-12"/>',

      'shield-check' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/>',

      'clock' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/>',

      'document' => '<path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/>',

      'wrench' => '<path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75a4.5 4.5 0 01-4.884 4.484c-1.076-.091-2.264.071-2.95.904l-7.152 8.684a2.548 2.548 0 11-3.586-3.586l8.684-7.152c.833-.686.995-1.874.904-2.95a4.5 4.5 0 016.336-4.486l-3.276 3.276a3.004 3.004 0 002.25 2.25l3.276-3.276c.256.565.398 1.192.398 1.852z"/>',

      'receipt' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 14.25l6-6m4.5-3.493V21.75l-3.75-1.5-3.75 1.5-3.75-1.5-3.75 1.5V4.757c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0111.186 0c1.1.128 1.907 1.077 1.907 2.185zM9.75 9h.008v.008H9.75V9zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm4.125 4.5h.008v.008h-.008V13.5zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z"/>',

      'cog' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>',

      'lock-closed' => '<path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/>',

      'magnifying-glass' => '<path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 15.803 7.5 7.5 0 0015.803 15.803z"/>',

      'plus' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>',

      'pencil' => '<path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10"/>',

      'trash' => '<path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/>',

      'check-circle' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>',

      'exclamation-triangle' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/>',

      'information-circle' => '<path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/>',

      'arrow-left' => '<path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18"/>',

      'calendar' => '<path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/>',

      'envelope' => '<path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/>',

      'eye' => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>',

      'x-mark' => '<path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>',

      'check' => '<path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>',

      'floppy-disk' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/>',

      'arrow-right' => '<path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/>',

      'bars-3' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/>',

      'clipboard-list' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z"/>',
    ];

    $path = $icons[$name] ?? '<path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z"/>';

    return "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"{$s}\" height=\"{$s}\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.75\"{$c}>{$path}</svg>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title><?= htmlspecialchars($page_title) ?> | TG-BASICS</title>
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

  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  html { scroll-behavior: smooth; }

  body {
    background: var(--bg);
    color: var(--text-primary);
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-weight: 400;
    min-height: 100vh;
    font-size: 14px;
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
  }
  .user-avatar {
    width: 24px; height: 24px; border-radius: 50%;
    background: linear-gradient(135deg, var(--gold-bright), var(--gold));
    display: flex; align-items: center; justify-content: center;
    font-size: 0.62rem; font-weight: 800; color: #fff; flex-shrink: 0;
  }

  .btn-logout {
    background: transparent; color: var(--text-muted);
    border: 1px solid var(--border); padding: 0.35rem 0.85rem;
    border-radius: 8px; font-size: 0.75rem;
    font-family: 'Plus Jakarta Sans', sans-serif; font-weight: 500;
    cursor: pointer; text-decoration: none; transition: all 0.15s;
    display: inline-flex; align-items: center; gap: 0.35rem; white-space: nowrap;
  }
  .btn-logout:hover { border-color: var(--danger); color: var(--danger); background: var(--danger-bg); }

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
  .card-icon { width: 34px; height: 34px; background: var(--gold-light); border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 0.95rem; flex-shrink: 0; }
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

  .btn-sm-gold { background: var(--gold-light); color: var(--gold); border: 1px solid var(--gold-muted); padding: 0.35rem 0.85rem; border-radius: 7px; font-size: 0.75rem; font-weight: 700; text-decoration: none; transition: all 0.15s; white-space: nowrap; display: inline-flex; align-items: center; gap: 0.35rem; font-family: 'Plus Jakarta Sans', sans-serif; cursor: pointer; }
  .btn-sm-gold:hover { background: var(--gold-muted); color: var(--gold); }

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
  .badge-dark { display: inline-flex; background: var(--sidebar-bg); color: var(--gold-bright); padding: 0.2rem 0.6rem; border-radius: 4px; font-size: 0.72rem; font-weight: 800; letter-spacing: 1.5px; }
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
    .topbar { padding: 0.75rem 1rem; }
    .topbar-breadcrumb { display: none; }
    .user-chip-label { display: none; }
    .content { padding: 1rem; }
    .form-grid, .form-grid-3 { grid-template-columns: 1fr; }
    .span-2, .span-3 { grid-column: span 1; }
    .page-header { padding: 1.1rem 1.25rem; }
    .page-header-title { font-size: 0.95rem; }
    .card-header { padding: 0.85rem 1rem; }
    .card-body { padding: 1rem; }
    .form-actions { padding: 0.9rem 1rem; }
    .form-actions .btn-ghost,
    .form-actions .btn-primary { flex: 1; justify-content: center; }
    .tg-table thead th,
    .tg-table tbody td { padding: 0.6rem 0.75rem; }
  }

  /* ── RESPONSIVE: SMALL MOBILE ── */
  @media (max-width: 480px) {
    .content { padding: 0.75rem; }
    .topbar { padding: 0.65rem 0.75rem; }
    .page-header { padding: 1rem; border-radius: 10px; }
    .user-chip { padding: 0.3rem 0.5rem 0.3rem 0.35rem; font-size: 0; }
    .user-chip .user-avatar { font-size: 0.62rem; }
    .btn-logout { padding: 0.35rem 0.6rem; font-size: 0; }
    .btn-logout::before { content: '🔒'; font-size: 0.85rem; }
  }
</style>

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