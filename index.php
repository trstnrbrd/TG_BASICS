<?php
function icon(string $name, int $size = 18, string $class = ''): string {
    $s = $size;
    $c = $class ? " class=\"{$class}\"" : '';
    $icons = [
      'users'           => '<path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/>',
      'shield-check'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/>',
      'clock'           => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/>',
      'clipboard-list'  => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z"/>',
      'wrench'          => '<path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75a4.5 4.5 0 01-4.884 4.484c-1.076-.091-2.264.071-2.95.904l-7.152 8.684a2.548 2.548 0 11-3.586-3.586l8.684-7.152c.833-.686.995-1.874.904-2.95a4.5 4.5 0 016.336-4.486l-3.276 3.276a3.004 3.004 0 002.25 2.25l3.276-3.276c.256.565.398 1.192.398 1.852z"/>',
      'receipt'         => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 14.25l6-6m4.5-3.493V21.75l-3.75-1.5-3.75 1.5-3.75-1.5-3.75 1.5V4.757c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0111.186 0c1.1.128 1.907 1.077 1.907 2.185zM9.75 9h.008v.008H9.75V9zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm4.125 4.5h.008v.008h-.008V13.5zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z"/>',
      'lock-closed'     => '<path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/>',
      'document'        => '<path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/>',
      'building-office' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21"/>',
      'map-pin'         => '<path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/>',
      'cpu-chip'        => '<path stroke-linecap="round" stroke-linejoin="round" d="M8.25 3v1.5M4.5 8.25H3m18 0h-1.5M4.5 12H3m18 0h-1.5m-15 3.75H3m18 0h-1.5M8.25 19.5V21M12 3v1.5m0 15V21m3.75-18v1.5m0 15V21m-9-1.5h10.5a2.25 2.25 0 002.25-2.25V6.75a2.25 2.25 0 00-2.25-2.25H6.75A2.25 2.25 0 004.5 6.75v10.5a2.25 2.25 0 002.25 2.25zm.75-12h9v9h-9v-9z"/>',
      'academic-cap'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.41 60.46 60.46 0 00-.491-6.347m-15.482 0a50.57 50.57 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.697 50.697 0 0112 13.489a50.702 50.702 0 017.74-3.342M6.75 15a.75.75 0 100-1.5.75.75 0 000 1.5zm0 0v-3.675A55.378 55.378 0 0112 8.443m-7.007 11.55A5.981 5.981 0 006.75 15.75v-1.5"/>',
      'calendar'        => '<path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/>',
      'briefcase'       => '<path stroke-linecap="round" stroke-linejoin="round" d="M20.25 14.15v4.25c0 1.094-.787 2.036-1.872 2.18-2.087.277-4.216.42-6.378.42s-4.291-.143-6.378-.42c-1.085-.144-1.872-1.086-1.872-2.18v-4.25m16.5 0a2.18 2.18 0 00.75-1.661V8.706c0-1.081-.768-2.015-1.837-2.175a48.114 48.114 0 00-3.413-.387m4.5 8.006c-.194.165-.42.295-.673.38A23.978 23.978 0 0112 15.75c-2.648 0-5.195-.429-7.577-1.22a2.016 2.016 0 01-.673-.38m0 0A2.18 2.18 0 013 12.489V8.706c0-1.081.768-2.015 1.837-2.175a48.111 48.111 0 013.413-.387m7.5 0V5.25A2.25 2.25 0 0013.5 3h-3a2.25 2.25 0 00-2.25 2.25v.894m7.5 0a48.667 48.667 0 00-7.5 0M12 12.75h.008v.008H12v-.008z"/>',
      'chevron-down'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/>',
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
<title>TG-BASICS | Brokerage and Auto Shop Integrated Client System</title>
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
    --shadow: 0 1px 3px rgba(0,0,0,0.08), 0 1px 2px rgba(0,0,0,0.04);
    --shadow-md: 0 4px 16px rgba(0,0,0,0.08), 0 2px 6px rgba(0,0,0,0.04);
    --shadow-lg: 0 12px 40px rgba(0,0,0,0.10), 0 4px 12px rgba(0,0,0,0.06);
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
    overflow-x: hidden;
  }

  /* ── TOPNAV ── */
  .topnav {
    position: fixed;
    top: 0; left: 0; right: 0;
    z-index: 100;
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.9rem 3rem;
    background: rgba(244,241,236,0.92);
    backdrop-filter: blur(14px);
    border-bottom: 1px solid var(--border);
    box-shadow: var(--shadow);
  }

  .nav-brand {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    text-decoration: none;
  }

  .nav-logo-img {
    width: 34px; height: 34px;
    border-radius: 50%;
    object-fit: cover;
    border: 1.5px solid var(--gold-bright);
  }

  .nav-brand-name {
    font-size: 1rem;
    font-weight: 800;
    color: var(--text-primary);
    letter-spacing: -0.2px;
  }

  .nav-brand-name span { color: var(--gold-bright); }

  .nav-tagline {
    font-size: 0.6rem;
    color: var(--text-muted);
    letter-spacing: 1.5px;
    text-transform: uppercase;
    display: block;
    line-height: 1;
    margin-top: 1px;
  }

  .nav-right { display: flex; align-items: center; gap: 0.75rem; }

  .nav-label {
    font-size: 0.72rem;
    color: var(--text-muted);
    font-weight: 500;
    letter-spacing: 0.3px;
  }

  .btn-login-nav {
    background: var(--sidebar-bg);
    color: var(--gold-bright);
    padding: 0.5rem 1.25rem;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 700;
    font-size: 0.78rem;
    letter-spacing: 0.3px;
    transition: background 0.15s, box-shadow 0.15s;
    box-shadow: 0 2px 8px rgba(28,26,23,0.2);
  }

  .btn-login-nav:hover {
    background: #2A2724;
    box-shadow: 0 4px 14px rgba(28,26,23,0.3);
  }

  /* ── HERO ── */
  .hero {
    min-height: 100vh;
    display: flex;
    align-items: center;
    padding: 8rem 3rem 5rem;
    position: relative;
    overflow: hidden;
  }

  .hero-bg-pattern {
    position: absolute;
    inset: 0;
    pointer-events: none;
    background-image:
      radial-gradient(circle at 70% 30%, rgba(212,160,23,0.07) 0%, transparent 55%),
      radial-gradient(circle at 20% 80%, rgba(212,160,23,0.04) 0%, transparent 45%);
  }

  .hero-grid {
    position: absolute;
    inset: 0;
    pointer-events: none;
    background-image:
      linear-gradient(var(--border) 1px, transparent 1px),
      linear-gradient(90deg, var(--border) 1px, transparent 1px);
    background-size: 48px 48px;
    opacity: 0.45;
    mask-image: radial-gradient(ellipse 80% 80% at 50% 50%, black 40%, transparent 100%);
  }

  .hero-inner {
    max-width: 1200px;
    margin: 0 auto;
    width: 100%;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 5rem;
    align-items: center;
    position: relative;
    z-index: 2;
  }

  .hero-left { display: flex; flex-direction: column; }

  .hero-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    background: var(--gold-light);
    border: 1px solid var(--gold-muted);
    padding: 0.3rem 0.85rem;
    border-radius: 100px;
    font-size: 0.68rem;
    font-weight: 700;
    color: var(--gold);
    letter-spacing: 1.5px;
    text-transform: uppercase;
    margin-bottom: 1.5rem;
    width: fit-content;
    opacity: 0;
    animation: fadeUp 0.6s ease 0.1s forwards;
  }

  .hero-eyebrow-dot {
    width: 6px; height: 6px;
    background: var(--gold-bright);
    border-radius: 50%;
  }

  .hero-title {
    font-size: clamp(2.6rem, 5vw, 4rem);
    font-weight: 800;
    line-height: 1.05;
    color: var(--text-primary);
    letter-spacing: -1.5px;
    margin-bottom: 1.25rem;
    opacity: 0;
    animation: fadeUp 0.6s ease 0.2s forwards;
  }

  .hero-title .accent {
    color: var(--gold-bright);
    position: relative;
  }

  .hero-title .accent::after {
    content: '';
    position: absolute;
    bottom: 2px; left: 0; right: 0;
    height: 3px;
    background: linear-gradient(90deg, var(--gold-bright), var(--gold-muted));
    border-radius: 2px;
    opacity: 0.5;
  }

  .hero-sub {
    font-size: 0.95rem;
    line-height: 1.8;
    color: var(--text-secondary);
    max-width: 460px;
    margin-bottom: 2.5rem;
    opacity: 0;
    animation: fadeUp 0.6s ease 0.3s forwards;
  }

  .hero-actions {
    display: flex;
    gap: 0.75rem;
    align-items: center;
    flex-wrap: wrap;
    opacity: 0;
    animation: fadeUp 0.6s ease 0.4s forwards;
  }

  .btn-primary-hero {
    background: var(--sidebar-bg);
    color: var(--gold-bright);
    padding: 0.8rem 2rem;
    border-radius: 10px;
    text-decoration: none;
    font-weight: 700;
    font-size: 0.85rem;
    letter-spacing: 0.3px;
    transition: background 0.15s, box-shadow 0.15s, transform 0.15s;
    box-shadow: 0 4px 16px rgba(28,26,23,0.25);
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
  }

  .btn-primary-hero:hover {
    background: #2A2724;
    box-shadow: 0 8px 24px rgba(28,26,23,0.35);
    transform: translateY(-2px);
  }

  .btn-secondary-hero {
    background: var(--bg-3);
    color: var(--text-secondary);
    padding: 0.8rem 1.5rem;
    border-radius: 10px;
    text-decoration: none;
    font-weight: 600;
    font-size: 0.85rem;
    border: 1px solid var(--border);
    transition: all 0.15s;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
  }

  .btn-secondary-hero:hover {
    border-color: var(--gold-muted);
    color: var(--gold);
    background: var(--gold-pale);
  }

  /* HERO RIGHT - VISUAL CARD STACK */
  .hero-right {
    position: relative;
    opacity: 0;
    animation: fadeUp 0.7s ease 0.5s forwards;
  }

  .hero-card-main {
    background: var(--bg-3);
    border: 1px solid var(--border);
    border-radius: 16px;
    box-shadow: var(--shadow-lg);
    overflow: hidden;
    position: relative;
    z-index: 2;
  }

  .hero-card-topbar {
    background: var(--sidebar-bg);
    padding: 0.9rem 1.25rem;
    display: flex;
    align-items: center;
    gap: 0.65rem;
  }

  .hc-logo-img {
    width: 28px; height: 28px;
    border-radius: 50%;
    object-fit: cover;
    border: 1px solid var(--gold-bright);
  }

  .hc-brand {
    font-size: 0.82rem;
    font-weight: 700;
    color: #fff;
    letter-spacing: 0.2px;
  }

  .hc-brand span { color: var(--gold-bright); }

  .hc-dot {
    width: 7px; height: 7px;
    border-radius: 50%;
    background: #2ECC71;
    margin-left: auto;
    box-shadow: 0 0 6px rgba(46,204,113,0.6);
  }

  .hero-card-body { padding: 1.25rem; }

  .hc-stat-row {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 0.75rem;
    margin-bottom: 1.1rem;
  }

  .hc-stat {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 0.8rem 0.75rem;
    text-align: center;
  }

  .hc-stat-num {
    font-size: 1.4rem;
    font-weight: 800;
    color: var(--text-primary);
    line-height: 1;
    letter-spacing: -0.5px;
  }

  .hc-stat-label {
    font-size: 0.6rem;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.8px;
    margin-top: 0.2rem;
    font-weight: 600;
  }

  .hc-policy-row {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
  }

  .hc-policy-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 0.6rem 0.9rem;
  }

  .hc-policy-left {
    display: flex;
    align-items: center;
    gap: 0.6rem;
  }

  .hc-policy-plate {
    background: var(--sidebar-bg);
    color: var(--gold-bright);
    padding: 0.15rem 0.5rem;
    border-radius: 4px;
    font-size: 0.65rem;
    font-weight: 800;
    letter-spacing: 1.5px;
  }

  .hc-policy-name {
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--text-primary);
  }

  .hc-policy-sub {
    font-size: 0.65rem;
    color: var(--text-muted);
  }

  .hc-badge {
    font-size: 0.62rem;
    font-weight: 700;
    padding: 0.2rem 0.55rem;
    border-radius: 100px;
    letter-spacing: 0.5px;
  }

  .hc-badge.green { background: #E8F8EE; color: #2E7D52; }
  .hc-badge.yellow { background: #FFF8E6; color: #B8860B; }
  .hc-badge.red { background: #FDF2F2; color: #C0392B; }

  /* Floating accent card */
  .hero-card-float {
    position: absolute;
    bottom: -1.5rem;
    right: -1.5rem;
    background: var(--bg-3);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 0.9rem 1.1rem;
    box-shadow: var(--shadow-md);
    display: flex;
    align-items: center;
    gap: 0.75rem;
    z-index: 3;
    min-width: 190px;
  }

  .float-icon {
    width: 38px; height: 38px;
    background: var(--gold-light);
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.1rem;
    flex-shrink: 0;
  }

  .float-label { font-size: 0.7rem; color: var(--text-muted); font-weight: 500; }
  .float-val { font-size: 1rem; font-weight: 800; color: var(--text-primary); line-height: 1.2; }

  /* ── ABOUT STRIP ── */
  .about-strip {
    background: var(--sidebar-bg);
    padding: 1rem 3rem;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 3rem;
    flex-wrap: wrap;
    border-top: 1px solid rgba(255,255,255,0.04);
    border-bottom: 1px solid rgba(255,255,255,0.04);
  }

  .strip-item {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    font-size: 0.75rem;
    color: rgba(200,192,176,0.65);
    font-weight: 500;
    letter-spacing: 0.3px;
  }

  .strip-item-icon { font-size: 0.85rem; }

  .strip-divider {
    width: 1px; height: 18px;
    background: rgba(255,255,255,0.08);
  }

  /* ── MODULES SECTION ── */
  .modules-section {
    padding: 5rem 3rem;
    max-width: 1200px;
    margin: 0 auto;
  }

  .section-label {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.65rem;
    font-weight: 700;
    color: var(--gold);
    letter-spacing: 2.5px;
    text-transform: uppercase;
    margin-bottom: 0.75rem;
  }

  .section-label::before {
    content: '';
    display: block;
    width: 16px; height: 2px;
    background: var(--gold-bright);
    border-radius: 1px;
  }

  .section-title {
    font-size: clamp(1.8rem, 3vw, 2.5rem);
    font-weight: 800;
    color: var(--text-primary);
    letter-spacing: -0.8px;
    line-height: 1.15;
    margin-bottom: 0.75rem;
  }

  .section-title span { color: var(--gold-bright); }

  .section-desc {
    font-size: 0.9rem;
    color: var(--text-secondary);
    line-height: 1.75;
    max-width: 520px;
    margin-bottom: 3rem;
  }

  /* MODULE GRID */
  .modules-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1px;
    background: var(--border);
    border: 1px solid var(--border);
    border-radius: 12px;
    overflow: hidden;
    box-shadow: var(--shadow-md);
  }

  .module-card {
    background: var(--bg-3);
    padding: 1.75rem 1.5rem;
    position: relative;
    transition: background 0.2s;
    overflow: hidden;
  }

  .module-card:hover { background: var(--gold-pale); }

  .module-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 2px;
    background: linear-gradient(90deg, var(--gold-bright), transparent);
    opacity: 0;
    transition: opacity 0.25s;
  }

  .module-card:hover::before { opacity: 1; }

  .module-num {
    font-size: 2.5rem;
    font-weight: 800;
    color: var(--border);
    line-height: 1;
    position: absolute;
    top: 1rem; right: 1.25rem;
    letter-spacing: -1px;
    transition: color 0.25s;
  }

  .module-card:hover .module-num { color: var(--gold-muted); }

  .module-icon {
    width: 40px; height: 40px;
    background: var(--gold-light);
    border: 1px solid var(--gold-muted);
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.1rem;
    margin-bottom: 1rem;
    transition: background 0.2s, border-color 0.2s;
  }

  .module-card:hover .module-icon {
    background: var(--gold-muted);
    border-color: var(--gold);
  }

  .module-name {
    font-size: 0.88rem;
    font-weight: 700;
    color: var(--text-primary);
    margin-bottom: 0.5rem;
    line-height: 1.35;
  }

  .module-desc {
    font-size: 0.76rem;
    line-height: 1.7;
    color: var(--text-muted);
  }

  .module-tag {
    display: inline-block;
    margin-top: 1rem;
    font-size: 0.62rem;
    letter-spacing: 1.2px;
    text-transform: uppercase;
    color: var(--gold);
    background: var(--gold-light);
    padding: 0.2rem 0.55rem;
    border-radius: 4px;
    font-weight: 700;
  }

  /* ── ROLES SECTION ── */
  .roles-section {
    background: var(--sidebar-bg);
    padding: 5rem 3rem;
  }

  .roles-inner {
    max-width: 1200px;
    margin: 0 auto;
  }

  .roles-section .section-label { color: var(--gold-bright); }
  .roles-section .section-label::before { background: var(--gold-bright); }
  .roles-section .section-title { color: #fff; }
  .roles-section .section-desc { color: rgba(200,192,176,0.65); }

  .roles-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1rem;
  }

  .role-card {
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 12px;
    padding: 1.75rem;
    transition: background 0.2s, border-color 0.2s;
  }

  .role-card:hover {
    background: rgba(212,160,23,0.06);
    border-color: rgba(212,160,23,0.2);
  }

  .role-header {
    display: flex;
    align-items: center;
    gap: 0.85rem;
    margin-bottom: 1.25rem;
  }

  .role-avatar {
    width: 44px; height: 44px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--gold-bright), var(--gold));
    border: 1.5px solid var(--gold-bright);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.2rem;
    flex-shrink: 0;
  }

  .role-title {
    font-size: 0.95rem;
    font-weight: 700;
    color: var(--gold-bright);
    line-height: 1.2;
  }

  .role-subtitle {
    font-size: 0.7rem;
    color: rgba(200,192,176,0.45);
    margin-top: 0.1rem;
    letter-spacing: 0.3px;
  }

  .role-list {
    list-style: none;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
  }

  .role-list li {
    font-size: 0.78rem;
    color: rgba(200,192,176,0.7);
    padding-left: 1.1rem;
    position: relative;
    line-height: 1.5;
  }

  .role-list li::before {
    content: '';
    position: absolute;
    left: 0; top: 0.55em;
    width: 5px; height: 1px;
    background: var(--gold-bright);
  }

  /* ── FOOTER ── */
  footer {
    background: #141210;
    padding: 2.5rem 3rem;
    border-top: 1px solid rgba(255,255,255,0.04);
  }

  .footer-inner {
    max-width: 1200px;
    margin: 0 auto;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 3rem;
    flex-wrap: wrap;
    margin-bottom: 2rem;
  }

  .footer-brand-name {
    font-size: 1rem;
    font-weight: 800;
    color: #fff;
    letter-spacing: -0.2px;
    margin-bottom: 0.4rem;
    display: flex;
    align-items: center;
    gap: 0.6rem;
  }

  .footer-brand-name span { color: var(--gold-bright); }

  .footer-logo-img {
    width: 28px; height: 28px;
    border-radius: 50%;
    object-fit: cover;
    border: 1px solid var(--gold-bright);
  }

  .footer-brand-desc {
    font-size: 0.73rem;
    color: rgba(200,192,176,0.35);
    line-height: 1.7;
    max-width: 260px;
  }

  .footer-col h4 {
    font-size: 0.62rem;
    letter-spacing: 2px;
    text-transform: uppercase;
    color: var(--gold-bright);
    margin-bottom: 0.75rem;
    font-weight: 700;
  }

  .footer-col p {
    font-size: 0.73rem;
    color: rgba(200,192,176,0.4);
    line-height: 1.8;
  }

  .footer-bottom {
    max-width: 1200px;
    margin: 0 auto;
    padding-top: 1.5rem;
    border-top: 1px solid rgba(255,255,255,0.05);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.5rem;
  }

  .footer-bottom p {
    font-size: 0.67rem;
    color: rgba(200,192,176,0.22);
    letter-spacing: 0.3px;
  }

  .footer-bottom span { color: rgba(212,160,23,0.5); }

  /* ── ANIMATIONS ── */
  @keyframes fadeUp {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
  }

  /* ── SCROLLBAR ── */
  ::-webkit-scrollbar { width: 4px; }
  ::-webkit-scrollbar-track { background: var(--bg); }
  ::-webkit-scrollbar-thumb { background: var(--gold-muted); border-radius: 2px; }

  /* ── RESPONSIVE ── */
  @media (max-width: 960px) {
    .hero-inner { grid-template-columns: 1fr; gap: 3rem; }
    .hero-right { display: none; }
    .modules-grid { grid-template-columns: 1fr 1fr; }
    .roles-grid { grid-template-columns: 1fr; }
    .topnav { padding: 0.9rem 1.5rem; }
    .hero { padding: 7rem 1.5rem 4rem; }
    .modules-section { padding: 4rem 1.5rem; }
    .roles-section { padding: 4rem 1.5rem; }
    footer { padding: 2.5rem 1.5rem; }
    .about-strip { padding: 1rem 1.5rem; gap: 1.5rem; }
  }

  @media (max-width: 600px) {
    .modules-grid { grid-template-columns: 1fr; }
    .hero-title { font-size: 2.2rem; letter-spacing: -1px; }
    .strip-divider { display: none; }
  }
</style>
</head>
<body>

<!-- TOPNAV -->
<nav class="topnav">
  <a href="index.php" class="nav-brand">
    <img src="assets/img/tg_logo.png" alt="TG" class="nav-logo-img"/>
    <div>
      <div class="nav-brand-name">TG<span>-BASICS</span></div>
      <span class="nav-tagline">Management System</span>
    </div>
  </a>
  <div class="nav-right">
    <span class="nav-label">Internal system &mdash; authorized users only</span>
    <a href="login.php" class="btn-login-nav"><?= icon('lock-closed', 14) ?> Sign In</a>
  </div>
</nav>

<!-- HERO -->
<section class="hero">
  <div class="hero-bg-pattern"></div>
  <div class="hero-grid"></div>

  <div class="hero-inner">
    <div class="hero-left">
      <div class="hero-eyebrow">
        <div class="hero-eyebrow-dot"></div>
        Pandi, Bulacan &mdash; Internal Platform
      </div>
      <h1 class="hero-title">
        One system.<br>
        Every record.<br>
        <span id="typewriter-root"></span>
      </h1>
      <p class="hero-sub">
        TG-BASICS centralizes client records, insurance policies, repair workflows, and billing for TG Customworks and Basic Car Insurance Services into a single platform.
      </p>
      <div class="hero-actions">
        <a href="login.php" class="btn-primary-hero">
          <?= icon('lock-closed', 14) ?> Sign In to TG-BASICS
        </a>
        <a href="#modules" class="btn-secondary-hero">
          View Modules <?= icon('chevron-down', 14) ?>
        </a>
      </div>
    </div>

    <!-- VISUAL CARD -->
    <div class="hero-right">
      <div class="hero-card-main">
        <div class="hero-card-topbar">
          <img src="assets/img/tg_logo.png" alt="TG" class="hc-logo-img"/>
          <span class="hc-brand">TG<span>-BASICS</span></span>
          <div class="hc-dot"></div>
        </div>
        <div class="hero-card-body">
          <div class="hc-stat-row" id="hero-stat-root">
            <!-- React animated counters mount here -->
          </div>
          <div class="hc-policy-row">
            <div class="hc-policy-item">
              <div class="hc-policy-left">
                <div class="hc-policy-plate">NCH 7952</div>
                <div>
                  <div class="hc-policy-name">Ofelia P. Ape</div>
                  <div class="hc-policy-sub">Expires Jun 18, 2026</div>
                </div>
              </div>
              <span class="hc-badge green">Stable</span>
            </div>
            <div class="hc-policy-item">
              <div class="hc-policy-left">
                <div class="hc-policy-plate">ABX 4421</div>
                <div>
                  <div class="hc-policy-name">Ramon T. Santos</div>
                  <div class="hc-policy-sub">Expires in 22 days</div>
                </div>
              </div>
              <span class="hc-badge yellow">Expiring</span>
            </div>
            <div class="hc-policy-item">
              <div class="hc-policy-left">
                <div class="hc-policy-plate">PDZ 8812</div>
                <div>
                  <div class="hc-policy-name">Maria C. Reyes</div>
                  <div class="hc-policy-sub">Expires in 5 days</div>
                </div>
              </div>
              <span class="hc-badge red">Urgent</span>
            </div>
          </div>
        </div>
      </div>
      <div class="hero-card-float">
        <div class="float-icon"><?= icon('document', 18) ?></div>
        <div>
          <div class="float-label">Claims In Progress</div>
          <div class="float-val">3 Active</div>
        </div>
      </div>
    </div>

  </div>
</section>

<!-- ABOUT STRIP -->
<div class="about-strip">
  <div class="strip-item"><span class="strip-item-icon"><?= icon('building-office', 14) ?></span> TG Customworks &amp; Basic Car Insurance</div>
  <div class="strip-divider"></div>
  <div class="strip-item"><span class="strip-item-icon"><?= icon('map-pin', 14) ?></span> 49 Villa Tierra St., San Roque, Pandi, Bulacan</div>
  <div class="strip-divider"></div>
  <div class="strip-item"><span class="strip-item-icon"><?= icon('cpu-chip', 14) ?></span> PHP + MySQL</div>
  <div class="strip-divider"></div>
  <div class="strip-item"><span class="strip-item-icon"><?= icon('academic-cap', 14) ?></span> STI College Sta. Maria Capstone</div>
  <div class="strip-divider"></div>
  <div class="strip-item"><span class="strip-item-icon"><?= icon('calendar', 14) ?></span> Operating Since 2016</div>
</div>

<!-- MODULES -->
<section id="modules" class="modules-section">
  <div class="modules-inner">
    <div class="section-label">System Modules</div>
    <h2 class="section-title">Six modules.<br><span>One platform.</span></h2>
    <p class="section-desc">Every feature built around the actual workflow of the business, from the first inspection to the final e-receipt.</p>

    <div class="modules-grid">
      <div class="module-card">
        <div class="module-num">01</div>
        <div class="module-icon"><?= icon('users', 18) ?></div>
        <div class="module-name">Client and Vehicle Records</div>
        <div class="module-desc">Centralized client profiles and vehicle details in one searchable database. Find any record by name, plate number, or policy number instantly.</div>
        <div class="module-tag">Records</div>
      </div>

      <div class="module-card">
        <div class="module-num">02</div>
        <div class="module-icon"><?= icon('shield-check', 18) ?></div>
        <div class="module-name">Insurance Eligibility and Policy Processing</div>
        <div class="module-desc">Automatic 10-year eligibility check for PhilBritish coverage. Encode full policy details including premium, participation fee, and coverage type.</div>
        <div class="module-tag">Insurance</div>
      </div>

      <div class="module-card">
        <div class="module-num">03</div>
        <div class="module-icon"><?= icon('clock', 18) ?></div>
        <div class="module-name">Policy Status and Renewal Tracker</div>
        <div class="module-desc">Color-coded expiry dashboard. Green for stable, Yellow for expiring within 30 days, Red for urgent within 7 days. Full payment balance tracking.</div>
        <div class="module-tag">Renewal</div>
      </div>

      <div class="module-card">
        <div class="module-num">04</div>
        <div class="module-icon"><?= icon('clipboard-list', 18) ?></div>
        <div class="module-name">Claims Document Tracker</div>
        <div class="module-desc">Log every claim and track document completeness including OR/CR, driver's license, and damage photos. Admin manually updates status from collection to resolution.</div>
        <div class="module-tag">Claims</div>
      </div>

      <div class="module-card">
        <div class="module-num">05</div>
        <div class="module-icon"><?= icon('wrench', 18) ?></div>
        <div class="module-name">Repair Job Management</div>
        <div class="module-desc">Mechanic submits digital inspection checklist on arrival. Admin monitors job stages from Inspection through Repair, Paint, Curing, and Final Release.</div>
        <div class="module-tag">Repair Shop</div>
      </div>

      <div class="module-card">
        <div class="module-num">06</div>
        <div class="module-icon"><?= icon('receipt', 18) ?></div>
        <div class="module-name">Quotation and E-Receipt Generator</div>
        <div class="module-desc">Build quotations from the digital service catalog. Once payment is confirmed, the system converts the quotation directly into a formatted e-receipt. No double encoding.</div>
        <div class="module-tag">Billing</div>
      </div>
    </div>
  </div>
</section>

<!-- ROLES -->
<section class="roles-section">
  <div class="roles-inner">
    <div class="section-label">Access Levels</div>
    <h2 class="section-title">The right access<br><span>for every role.</span></h2>
    <p class="section-desc">Each user is redirected to their own dashboard after login. No self-registration. Accounts are created by the administrator.</p>

    <div class="roles-grid">
      <div class="role-card">
        <div class="role-header">
          <div class="role-avatar"><?= icon('briefcase', 20) ?></div>
          <div>
            <div class="role-title">Admin</div>
    
          </div>
        </div>
        <ul class="role-list">
          <li>Full access to all six modules</li>
          <li>Encode and manage client and vehicle records</li>
          <li>Process insurance policies and track renewals</li>
          <li>Monitor claims and document completeness</li>
          <li>Prepare quotations and confirm billing</li>
          <li>Oversee repair job stages and expiry dashboards</li>
        </ul>
      </div>

      <div class="role-card">
        <div class="role-header">
          <div class="role-avatar"><?= icon('wrench', 20) ?></div>
          <div>
            <div class="role-title">Mechanic</div>
            
          </div>
        </div>
        <ul class="role-list">
          <li>Access to repair job panel only</li>
          <li>Fill out digital vehicle inspection checklist on arrival</li>
          <li>Update repair job stages as work progresses</li>
          <li>Cannot access client records, insurance, or billing</li>
        </ul>
      </div>
    </div>
  </div>
</section>

<!-- FOOTER -->
<footer>
  <div class="footer-inner">
    <div>
      <div class="footer-brand-name">
        <img src="assets/img/tg_logo.png" alt="TG" class="footer-logo-img"/>
        TG<span>-BASICS</span>
      </div>
      <p class="footer-brand-desc">Brokerage and Auto Shop Integrated Client System. Built exclusively for TG Customworks and Basic Car Insurance Services.</p>
    </div>
    <div class="footer-col">
      <h4>Business Address</h4>
      <p>49 Villa Tierra St., San Roque<br/>Pandi, Bulacan, Philippines<br/>Gerald Peterson V. Carpio, Prop.</p>
    </div>
    <div class="footer-col">
      <h4>System Info</h4>
      <p>Built with PHP + MySQL<br/>Web-based internal system<br/>STI College Sta. Maria Capstone</p>
    </div>
  </div>
  <div class="footer-bottom">
    <p>TG-BASICS &mdash; <span>TG Customworks and Basic Car Insurance Services</span></p>
    <p>Internal use only. Unauthorized access is prohibited.</p>
  </div>
</footer>

<!-- React CDN -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/react/18.2.0/umd/react.production.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/react-dom/18.2.0/umd/react-dom.production.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/babel-standalone/7.23.2/babel.min.js"></script>

<script type="text/babel">
  const { useState, useEffect, useRef } = React;

  // ── ANIMATED COUNTER ──
  function AnimatedCounter({ target, suffix = '', duration = 1400 }) {
    const [count, setCount] = useState(0);
    const ref = useRef(null);
    const started = useRef(false);

    useEffect(() => {
      const observer = new IntersectionObserver(([entry]) => {
        if (entry.isIntersecting && !started.current) {
          started.current = true;
          let start = 0;
          const step = Math.ceil(target / (duration / 20));
          const timer = setInterval(() => {
            start += step;
            if (start >= target) {
              setCount(target);
              clearInterval(timer);
            } else {
              setCount(start);
            }
          }, 20);
        }
      }, { threshold: 0.5 });

      if (ref.current) observer.observe(ref.current);
      return () => observer.disconnect();
    }, [target]);

    return <span ref={ref}>{count}{suffix}</span>;
  }

  // ── HERO CARD STATS - animated on load ──
  function HeroStats() {
    const stats = [
      { num: 48, label: 'Clients',   suffix: '' },
      { num: 31, label: 'Policies',  suffix: '' },
      { num: 7,  label: 'In Repair', suffix: '' },
    ];

    return (
      <>
        {stats.map((s, i) => (
          <div key={i} className="hc-stat">
            <div className="hc-stat-num">
              <AnimatedCounter target={s.num} duration={1000 + i * 200} />
            </div>
            <div className="hc-stat-label">{s.label}</div>
          </div>
        ))}
      </>
    );
  }

  // ── TYPEWRITER for hero title accent ──
  function Typewriter({ words, speed = 80, pause = 1800 }) {
    const [display, setDisplay] = useState('');
    const [wordIdx, setWordIdx] = useState(0);
    const [charIdx, setCharIdx] = useState(0);
    const [deleting, setDeleting] = useState(false);

    useEffect(() => {
      const current = words[wordIdx];

      const timeout = setTimeout(() => {
        if (!deleting) {
          setDisplay(current.slice(0, charIdx + 1));
          if (charIdx + 1 === current.length) {
            setTimeout(() => setDeleting(true), pause);
          } else {
            setCharIdx(c => c + 1);
          }
        } else {
          setDisplay(current.slice(0, charIdx - 1));
          if (charIdx - 1 === 0) {
            setDeleting(false);
            setWordIdx(w => (w + 1) % words.length);
            setCharIdx(0);
          } else {
            setCharIdx(c => c - 1);
          }
        }
      }, deleting ? speed / 2 : speed);

      return () => clearTimeout(timeout);
    }, [charIdx, deleting, wordIdx]);

    return (
      <span style={{ color: 'var(--gold-bright)', position: 'relative' }}>
        {display}
        <span style={{
          borderRight: '2px solid var(--gold-bright)',
          marginLeft: '2px',
          animation: 'blink 0.7s step-end infinite'
        }}></span>
      </span>
    );
  }

  // ── SCROLL REVEAL wrapper ──
  function RevealOnScroll({ children, delay = 0 }) {
    const ref = useRef(null);
    const [visible, setVisible] = useState(false);

    useEffect(() => {
      const observer = new IntersectionObserver(([entry]) => {
        if (entry.isIntersecting) {
          setTimeout(() => setVisible(true), delay);
          observer.disconnect();
        }
      }, { threshold: 0.15 });

      if (ref.current) observer.observe(ref.current);
      return () => observer.disconnect();
    }, []);

    return (
      <div
        ref={ref}
        style={{
          opacity: visible ? 1 : 0,
          transform: visible ? 'translateY(0)' : 'translateY(24px)',
          transition: `opacity 0.6s ease, transform 0.6s ease`,
        }}
      >
        {children}
      </div>
    );
  }

  // ── MOUNT HERO CARD STATS ──
  const heroStatRoot = document.getElementById('hero-stat-root');
  if (heroStatRoot) {
    ReactDOM.createRoot(heroStatRoot).render(<HeroStats />);
  }

  // ── MOUNT TYPEWRITER in hero title ──
  const twRoot = document.getElementById('typewriter-root');
  if (twRoot) {
    ReactDOM.createRoot(twRoot).render(
      <Typewriter words={['Always ready.', 'Always accurate.', 'Always organized.']} />
    );
  }

 

</script>

<style>
  @keyframes blink {
    0%, 100% { opacity: 1; }
    50% { opacity: 0; }
  }
</style>

</body>
</html>