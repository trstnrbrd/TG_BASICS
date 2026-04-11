<?php require_once 'includes/icons.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<meta name="format-detection" content="telephone=no, date=no, email=no, address=no"/>
<title>TG-BASICS | Brokerage and Auto Shop Integrated Control System</title>
<link rel="icon" type="image/png" href="assets/img/tg_logo.png"/>
<link rel="apple-touch-icon" href="assets/img/tg_logo.png"/>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="assets/css/index.css?v=<?= filemtime('assets/css/index.css') ?>"/>
</head>
<body>

<!-- TOPNAV -->
<nav class="topnav">
  <a href="index.php" class="nav-brand">
    <div class="nav-logos">
      <img src="assets/img/tg_logo.png" alt="TG Customworks" class="nav-logo-img"/>
      <div class="nav-logo-sep"></div>
      <img src="assets/img/LogoBasicCar.png" alt="Basic Car Insurance" class="nav-logo-img no-ring"/>
    </div>
    <div>
      <div class="nav-brand-name">TG<span>-BASICS</span></div>
      <span class="nav-tagline">Management System</span>
    </div>
  </a>
  <div class="nav-right">
    <span class="nav-label">Internal system &mdash; authorized users only</span>
    <button class="theme-toggle" id="theme-toggle" title="Toggle light/dark mode" aria-label="Toggle theme">
      <span id="toggle-moon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg></span>
      <span id="toggle-sun" style="display:none"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg></span>
    </button>
    <a href="auth/login.php" class="btn-login-nav"><?= icon('lock-closed', 14) ?> Sign In</a>
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
        TG-BASICS centralizes client records, insurance policies, repair workflows, and billing for TG Customworks and Basic Car Insurance into a single platform.
      </p>
      <div class="hero-actions">
        <a href="auth/login.php" class="btn-primary-hero">
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
          <img src="assets/img/tg_logo.png" alt="TG Customworks" class="hc-logo-img"/>
          <div class="hc-logo-sep"></div>
          <img src="assets/img/LogoBasicCar.png" alt="Basic Car Insurance" class="hc-logo-img no-ring"/>
          <span class="hc-brand">TG<span>-BASICS</span></span>
          <div class="hc-dot"></div>
        </div>
        <div class="hero-card-body">
          <div class="hc-stat-row" id="hero-stat-root"></div>
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
  <div class="strip-inner">
    <div class="strip-tagline">&ldquo;Your Journey, Our Care&rdquo;</div>
    <div class="strip-rule">
      <span class="strip-rule-line"></span>
      <span class="strip-rule-diamond">&#9670;</span>
      <span class="strip-rule-line"></span>
    </div>
    <div class="strip-details">
      <span class="strip-detail-item">
        <span class="strip-item-icon"><?= icon('building-office', 13) ?></span>
        49 Villa Tierra St., San Roque, Pandi, Bulacan
      </span>
      <span class="strip-dot">&middot;</span>
      <span class="strip-detail-item">
        <span class="strip-item-icon"><?= icon('calendar', 13) ?></span>
        Operating Since 2016
      </span>
    </div>
  </div>
</div>

<!-- MODULES -->
<section id="modules" class="modules-section">
  <div class="modules-inner">
    <div class="section-label">System Modules</div>
    <h2 class="section-title">Six modules.<br><span>One platform.</span></h2>
    <p class="section-desc">Every feature built around the actual workflow of the business, from the first inspection to the final e-receipt.</p>

    <div class="modules-grid">
      <div class="module-card" data-mod="1">
        <div class="module-num">01</div>
        <div class="module-icon"><?= icon('users', 20) ?></div>
        <div class="module-name">Client and Vehicle Records</div>
        <div class="module-desc">Centralized client profiles and vehicle details in one searchable database. Find any record by name, plate number, or policy number instantly.</div>
        <div class="module-tag">Records</div>
      </div>
      <div class="module-card" data-mod="2">
        <div class="module-num">02</div>
        <div class="module-icon"><?= icon('shield-check', 20) ?></div>
        <div class="module-name">Insurance Eligibility and Policy Processing</div>
        <div class="module-desc">Automatic 10-year eligibility check for PhilBritish coverage. Encode full policy details including premium, participation fee, and coverage type.</div>
        <div class="module-tag">Insurance</div>
      </div>
      <div class="module-card" data-mod="3">
        <div class="module-num">03</div>
        <div class="module-icon"><?= icon('clock', 20) ?></div>
        <div class="module-name">Policy Status and Renewal Tracker</div>
        <div class="module-desc">Color-coded expiry dashboard. Green for stable, Yellow for expiring within 30 days, Red for urgent within 7 days. Full payment balance tracking.</div>
        <div class="module-tag">Renewal</div>
      </div>
      <div class="module-card" data-mod="4">
        <div class="module-num">04</div>
        <div class="module-icon"><?= icon('clipboard-list', 20) ?></div>
        <div class="module-name">Claims Document Tracker</div>
        <div class="module-desc">Log every claim and track document completeness including OR/CR, driver's license, and damage photos. Admin manually updates status from collection to resolution.</div>
        <div class="module-tag">Claims</div>
      </div>
      <div class="module-card" data-mod="5">
        <div class="module-num">05</div>
        <div class="module-icon"><?= icon('wrench', 20) ?></div>
        <div class="module-name">Repair Job Management</div>
        <div class="module-desc">Mechanic submits digital inspection checklist on arrival. Admin monitors job stages from Inspection through Repair, Paint, Curing, and Final Release.</div>
        <div class="module-tag">Repair Shop</div>
      </div>
      <div class="module-card" data-mod="6">
        <div class="module-num">06</div>
        <div class="module-icon"><?= icon('receipt', 20) ?></div>
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
    <div class="roles-grid roles-grid-3">
      <div class="role-card">
        <div class="role-header">
          <div class="role-avatar"><?= icon('shield-check', 20) ?></div>
          <div>
            <div class="role-title">Super Admin</div>
          </div>
        </div>
        <ul class="role-list">
          <li>Full access to all modules plus administration</li>
          <li>Create and manage user accounts</li>
          <li>View system activity logs and audit trail</li>
          <li>Configure system settings and SMTP</li>
          <li>Toggle two-factor authentication</li>
        </ul>
      </div>
      <div class="role-card">
        <div class="role-header">
          <div class="role-avatar"><?= icon('users', 20) ?></div>
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

<!-- SECURITY & TECH -->
<section class="tech-section">
  <div class="tech-inner">
    <div class="section-label">Security &amp; Technology</div>
    <h2 class="section-title">Built secure.<br><span>Built to last.</span></h2>
    <p class="section-desc">Enterprise-grade security features protecting every transaction and record in the system.</p>
    <div class="tech-grid">
      <div class="tech-card">
        <div class="tech-card-icon"><?= icon('lock-closed', 20) ?></div>
        <div class="tech-card-name">Two-Factor Authentication</div>
        <div class="tech-card-desc">Email-based 2FA verification codes on every login for accounts with 2FA enabled.</div>
      </div>
      <div class="tech-card">
        <div class="tech-card-icon"><?= icon('shield-check', 20) ?></div>
        <div class="tech-card-name">Account Lockout Protection</div>
        <div class="tech-card-desc">Automatic lockout after multiple failed login attempts to prevent brute-force attacks.</div>
      </div>
      <div class="tech-card">
        <div class="tech-card-icon"><?= icon('clipboard-list', 20) ?></div>
        <div class="tech-card-name">Full Audit Trail</div>
        <div class="tech-card-desc">Every login, record change, and system action is logged with timestamps and user details.</div>
      </div>
      <div class="tech-card">
        <div class="tech-card-icon"><?= icon('cog', 20) ?></div>
        <div class="tech-card-name">Role-Based Access</div>
        <div class="tech-card-desc">Three distinct user roles with strict permission boundaries. No unauthorized module access.</div>
      </div>
    </div>
  </div>
</section>

<!-- FOOTER -->
<footer>
  <div class="footer-inner">
    <div>
      <div class="footer-brand-name">
        <img src="assets/img/tg_logo.png" alt="TG Customworks" class="footer-logo-img"/>
        <div class="footer-logo-sep"></div>
        <img src="assets/img/LogoBasicCar.png" alt="Basic Car Insurance" class="footer-logo-img no-ring"/>
        TG<span>-BASICS</span>
      </div>
      <p class="footer-brand-desc">Brokerage and Auto Shop Integrated Control System. Built exclusively for TG Customworks and Basic Car Insurance.</p>
    </div>
    <div class="footer-col">
      <h4>Business Address</h4>
      <p>49 Villa Tierra St., San Roque<br/>Pandi, Bulacan, Philippines<br/>Gerald Peterson V. Carpio, Prop.</p>
    </div>
    <div class="footer-col">
      <h4>System Info</h4>
      <p>Built with PHP + MySQL<br/>Web-based internal system<br/>STI College Sta. Maria Capstone</p>
    </div>
    <div class="footer-col">
      <h4>Legal</h4>
      <div style="display:flex;flex-direction:column;gap:0.5rem;">
        <a href="#" class="footer-legal-link" data-tab="privacy">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          Privacy Notice
        </a>
        <a href="#" class="footer-legal-link" data-tab="terms">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
          Terms &amp; Conditions
        </a>
        <a href="#" class="footer-legal-link" data-tab="disclaimer">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          Disclaimer
        </a>
      </div>
    </div>
    <div class="footer-col">
      <h4>Contact Us</h4>
      <p>
        <a href="tel:09171453448" class="footer-contact-link">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;">
            <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.15 12 19.79 19.79 0 0 1 1.07 3.38 2 2 0 0 1 3.05 1h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.09 8.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 21 16.92z"/>
          </svg>
          0917 145 3448
        </a>
      </p>
      <p>
        <a href="mailto:tgcustomworksbulacan@gmail.com" class="footer-contact-link">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;">
            <rect x="2" y="4" width="20" height="16" rx="2"/>
            <path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>
          </svg>
          tgcustomworksbulacan@gmail.com
        </a>
      </p>
      <div style="display:flex;flex-direction:column;gap:0.45rem;margin-top:0.25rem;">
        <a href="https://www.facebook.com/TGCustomWorks" target="_blank" rel="noopener noreferrer" class="footer-fb-link">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" style="flex-shrink:0;">
            <path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/>
          </svg>
          TG Customworks
        </a>
        <a href="https://www.facebook.com/basiccarinsurance" target="_blank" rel="noopener noreferrer" class="footer-fb-link">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" style="flex-shrink:0;">
            <path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/>
          </svg>
          Basic Car Insurance
        </a>
      </div>
    </div>
  </div>
  <div class="footer-bottom">
    <p>TG-BASICS &mdash; <span>TG Customworks and Basic Car Insurance</span></p>
    <p>&copy; <?= date('Y') ?> TG Customworks &amp; Basic Car Insurance. All rights reserved. &nbsp;&middot;&nbsp; Internal use only. Unauthorized access is prohibited.</p>
  </div>
</footer>

<!-- LEGAL MODAL (Privacy + Terms tabs) -->
<style>
  #privacy-modal { display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0);backdrop-filter:blur(0px);align-items:center;justify-content:center;padding:1.5rem;transition:background 0.3s,backdrop-filter 0.3s; }
  #privacy-modal.show { display:flex;background:rgba(0,0,0,0.65);backdrop-filter:blur(6px); }
  #privacy-modal-box { background:#1C1A17;border:1px solid rgba(212,160,23,0.25);border-radius:18px;overflow:hidden;box-shadow:0 32px 80px rgba(0,0,0,0.6),0 0 0 1px rgba(212,160,23,0.05);width:100%;max-width:680px;max-height:90vh;display:flex;flex-direction:column;transform:translateY(32px) scale(0.97);opacity:0;transition:transform 0.35s cubic-bezier(0.34,1.56,0.64,1),opacity 0.3s ease; }
  #privacy-modal.show #privacy-modal-box { transform:translateY(0) scale(1);opacity:1; }
  #privacy-modal-body::-webkit-scrollbar { width:3px; }
  #privacy-modal-body::-webkit-scrollbar-thumb { background:rgba(212,160,23,0.3);border-radius:2px; }
  .legal-tab-btn { flex:1;padding:0.65rem 1rem;background:none;border:none;font-family:inherit;font-size:0.78rem;font-weight:700;color:#7A7268;cursor:pointer;border-bottom:2px solid transparent;transition:all 0.15s;letter-spacing:0.2px; }
  .legal-tab-btn.active { color:#D4A017;border-bottom-color:#D4A017; }
  .legal-tab-btn:hover:not(.active) { color:#B8B0A4; }
  .footer-legal-link { display:inline-flex;align-items:center;gap:0.4rem;font-size:0.72rem;color:rgba(200,192,176,0.5);text-decoration:none;transition:color 0.15s;font-family:inherit; }
  .footer-legal-link:hover { color:#D4A017; }
</style>

<div id="privacy-modal">
  <div id="privacy-modal-box">

    <!-- Header -->
    <div style="display:flex;align-items:center;justify-content:space-between;padding:1.1rem 1.75rem;border-bottom:1px solid rgba(255,255,255,0.07);background:rgba(212,160,23,0.06);flex-shrink:0;">
      <div style="display:flex;align-items:center;gap:0.85rem;">
        <div style="width:36px;height:36px;background:linear-gradient(135deg,#D4A017,#B8860B);border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0;box-shadow:0 4px 12px rgba(184,134,11,0.35);">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
          </svg>
        </div>
        <div>
          <div style="font-size:0.95rem;font-weight:800;color:#E8E2D8;letter-spacing:-0.3px;">Legal Documents</div>
          <div style="font-size:0.67rem;color:#7A7268;margin-top:0.1rem;">TG Customworks &amp; Basic Car Insurance &mdash; TG-BASICS</div>
        </div>
      </div>
      <button id="close-privacy-modal" style="background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.08);color:#7A7268;cursor:pointer;padding:0.4rem;border-radius:8px;transition:all 0.15s;line-height:1;display:flex;" onmouseover="this.style.background='rgba(255,255,255,0.1)';this.style.color='#E8E2D8'" onmouseout="this.style.background='rgba(255,255,255,0.05)';this.style.color='#7A7268'">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>
    </div>

    <!-- Tabs -->
    <div style="display:flex;border-bottom:1px solid rgba(255,255,255,0.07);background:rgba(0,0,0,0.2);flex-shrink:0;">
      <button class="legal-tab-btn active" data-tab="privacy">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;margin-right:0.35rem;"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        Privacy Notice
      </button>
      <button class="legal-tab-btn" data-tab="terms">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;margin-right:0.35rem;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        Terms &amp; Conditions
      </button>
      <button class="legal-tab-btn" data-tab="disclaimer">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;margin-right:0.35rem;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        Disclaimer
      </button>
    </div>

    <!-- Body -->
    <div id="privacy-modal-body" style="padding:1.5rem 1.75rem;display:flex;flex-direction:column;gap:1.1rem;font-size:0.82rem;line-height:1.8;color:#B8B0A4;overflow-y:auto;">

      <!-- PRIVACY TAB -->
      <div id="tab-privacy">
        <div style="background:rgba(212,160,23,0.07);border:1px solid rgba(212,160,23,0.15);border-radius:9px;padding:0.85rem 1.1rem;font-size:0.74rem;color:#9C9286;margin-bottom:1.1rem;">
          <strong style="color:#E8E2D8;">Effective Date:</strong> April 2026 &nbsp;&bull;&nbsp;
          <strong style="color:#E8E2D8;">Legal Basis:</strong> RA 10173 — Data Privacy Act of 2012
        </div>
        <?php
        $privacy_sections = [
          ['Purpose', 'TG-BASICS collects and processes personal information solely for managing client insurance policies, vehicle records, claims processing, and repair job coordination — exclusively for the internal business operations of TG Customworks &amp; Basic Car Insurance.'],
          ['Data Collected', '<ul style="margin-top:0.4rem;margin-left:1.25rem;display:flex;flex-direction:column;gap:0.25rem;"><li>Full name, contact number, and email address</li><li>Home or billing address</li><li>Vehicle information (plate number, make, model, chassis and engine numbers)</li><li>Insurance policy details and payment status</li><li>Claims documentation and incident details</li><li>System user credentials (hashed) and audit logs</li></ul>'],
          ['Legal Basis', 'Processing is carried out in compliance with <strong style="color:#E8E2D8;">Republic Act No. 10173 (Data Privacy Act of 2012)</strong>. Collection is based on the legitimate interests of the business and the performance of an insurance policy agreement with the data subject.'],
          ['Data Retention', 'Personal data is retained for a minimum of <strong style="color:#E8E2D8;">five (5) years</strong> after the last transaction, in compliance with insurance regulations and RA 10173.'],
          ['Security', 'Access is strictly role-based (Super Admin, Admin, Mechanic). Passwords are hashed. All data operations are logged through a full system audit trail.'],
          ['Your Rights', '<ul style="margin-top:0.4rem;margin-left:1.25rem;display:flex;flex-direction:column;gap:0.25rem;"><li><strong style="color:#E8E2D8;">Be Informed</strong> — know what data is collected and how it is used</li><li><strong style="color:#E8E2D8;">Access</strong> — request a copy of your personal data</li><li><strong style="color:#E8E2D8;">Rectification</strong> — request correction of inaccurate information</li><li><strong style="color:#E8E2D8;">Erasure</strong> — request deletion subject to legal retention requirements</li></ul>'],
          ['Data Sharing', 'Personal data is <strong style="color:#E8E2D8;">not shared, sold, or disclosed</strong> to third parties except as required by law or when necessary for claims processing with PhilBritish Insurance Corporation.'],
        ];
        foreach ($privacy_sections as $i => $sec): ?>
        <div style="margin-bottom:1.1rem;">
          <div style="font-size:0.63rem;letter-spacing:1.5px;text-transform:uppercase;color:#D4A017;font-weight:700;margin-bottom:0.35rem;"><?= ($i+1) ?>. <?= $sec[0] ?></div>
          <p><?= $sec[1] ?></p>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- TERMS TAB -->
      <div id="tab-terms" style="display:none;">
        <div style="background:rgba(212,160,23,0.07);border:1px solid rgba(212,160,23,0.15);border-radius:9px;padding:0.85rem 1.1rem;font-size:0.74rem;color:#9C9286;margin-bottom:1.1rem;">
          <strong style="color:#E8E2D8;">Effective Date:</strong> April 2026 &nbsp;&bull;&nbsp;
          <strong style="color:#E8E2D8;">Applies To:</strong> All authorized users of TG-BASICS
        </div>
        <?php
        $terms_sections = [
          ['Authorized Access Only', 'TG-BASICS is a private, internal system exclusively for authorized personnel of TG Customworks &amp; Basic Car Insurance. Unauthorized access, use, or attempt to access this system is strictly prohibited and may be subject to legal action.'],
          ['User Responsibilities', 'Each user is responsible for maintaining the confidentiality of their login credentials. You must not share your account with anyone. You are fully accountable for all actions performed under your account.'],
          ['Role-Based Usage', '<ul style="margin-top:0.4rem;margin-left:1.25rem;display:flex;flex-direction:column;gap:0.25rem;"><li><strong style="color:#E8E2D8;">Super Admin</strong> — Full system access; manages users and system settings</li><li><strong style="color:#E8E2D8;">Admin</strong> — Manages clients, policies, claims, and renewals</li><li><strong style="color:#E8E2D8;">Mechanic</strong> — Limited to repair jobs and quotations only</li></ul>'],
          ['Data Accuracy', 'Users are responsible for ensuring the accuracy and completeness of all data entered into the system. Inputting false, misleading, or unauthorized information is a violation of these terms.'],
          ['Confidentiality', 'All client records, policy details, claims information, and financial data accessed through TG-BASICS are strictly confidential. Users must not disclose, copy, or transmit any system data to unauthorized parties.'],
          ['Audit &amp; Accountability', 'All user actions within TG-BASICS are recorded in an audit log. This includes logins, data entries, updates, and deletions. Users consent to this monitoring as a condition of system use.'],
          ['Prohibited Actions', '<ul style="margin-top:0.4rem;margin-left:1.25rem;display:flex;flex-direction:column;gap:0.25rem;"><li>Unauthorized deletion or modification of records</li><li>Sharing login credentials with others</li><li>Attempting to bypass role-based access controls</li><li>Using the system for personal or non-business purposes</li></ul>'],
          ['Termination of Access', 'The Super Admin reserves the right to suspend or permanently revoke system access for any user who violates these Terms &amp; Conditions without prior notice.'],
        ];
        foreach ($terms_sections as $i => $sec): ?>
        <div style="margin-bottom:1.1rem;">
          <div style="font-size:0.63rem;letter-spacing:1.5px;text-transform:uppercase;color:#D4A017;font-weight:700;margin-bottom:0.35rem;"><?= ($i+1) ?>. <?= $sec[0] ?></div>
          <p><?= $sec[1] ?></p>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- DISCLAIMER TAB -->
      <div id="tab-disclaimer" style="display:none;">
        <div style="background:rgba(212,160,23,0.07);border:1px solid rgba(212,160,23,0.15);border-radius:9px;padding:0.85rem 1.1rem;font-size:0.74rem;color:#9C9286;margin-bottom:1.1rem;">
          <strong style="color:#E8E2D8;">Effective Date:</strong> April 2026 &nbsp;&bull;&nbsp;
          <strong style="color:#E8E2D8;">Scope:</strong> All users and stakeholders of TG-BASICS
        </div>
        <?php
        $disclaimer_sections = [
          ['General Disclaimer', 'TG-BASICS is developed as a capstone project for academic purposes at STI College Sta. Maria. While every effort has been made to ensure the accuracy, reliability, and completeness of the system, <strong style="color:#E8E2D8;">TG Customworks &amp; Basic Car Insurance makes no warranties</strong>, expressed or implied, regarding the system\'s performance, fitness for a particular purpose, or freedom from errors.'],
          ['System Accuracy', 'The information displayed within TG-BASICS — including policy records, premium computations, claim statuses, and renewal dates — is dependent on the accuracy of data entered by authorized users. <strong style="color:#E8E2D8;">The system operators are not liable for errors arising from incorrect data entry, system misconfiguration, or user misuse.</strong>'],
          ['Business Decisions', 'TG-BASICS is a management tool intended to assist — not replace — professional judgment. <strong style="color:#E8E2D8;">No business decision should be made solely based on system output</strong> without proper verification. TG Customworks &amp; Basic Car Insurance assumes no liability for losses or damages arising from decisions made based on system-generated information.'],
          ['System Availability', 'TG-BASICS is hosted on a local server environment. Uptime, data persistence, and system availability are not guaranteed. <strong style="color:#E8E2D8;">The operators are not responsible for data loss, system downtime, or service interruptions</strong> caused by hardware failure, software errors, power outages, or other unforeseen circumstances.'],
          ['Insurance Liability', 'TG-BASICS facilitates the encoding and tracking of insurance policies under PhilBritish Insurance Corporation. <strong style="color:#E8E2D8;">The system does not constitute an official insurance contract.</strong> All insurance coverage and claims are governed by the actual policy documents issued by the insuring company. Discrepancies between system records and official policy documents shall defer to the official policy documents.'],
          ['Limitation of Liability', 'To the maximum extent permitted by applicable law, TG Customworks &amp; Basic Car Insurance, its staff, and the system developers <strong style="color:#E8E2D8;">shall not be held liable</strong> for any direct, indirect, incidental, or consequential damages arising from the use of or inability to use TG-BASICS, even if advised of the possibility of such damages.'],
          ['Academic Context', 'This system was developed in partial fulfillment of the requirements for the Bachelor of Science in Information Technology at <strong style="color:#E8E2D8;">STI College Sta. Maria</strong>. The academic institution bears no responsibility for the deployment, operation, or outcomes of the system in a business environment.'],
        ];
        foreach ($disclaimer_sections as $i => $sec): ?>
        <div style="margin-bottom:1.1rem;">
          <div style="font-size:0.63rem;letter-spacing:1.5px;text-transform:uppercase;color:#D4A017;font-weight:700;margin-bottom:0.35rem;"><?= ($i+1) ?>. <?= $sec[0] ?></div>
          <p><?= $sec[1] ?></p>
        </div>
        <?php endforeach; ?>
      </div>

    </div>

    <!-- Footer -->
    <div style="padding:1rem 1.75rem;border-top:1px solid rgba(255,255,255,0.07);display:flex;justify-content:space-between;align-items:center;background:rgba(0,0,0,0.25);flex-shrink:0;gap:1rem;flex-wrap:wrap;">
      <span style="font-size:0.7rem;color:#7A7268;line-height:1.5;">By accessing TG-BASICS, you acknowledge and agree to our<br/>Privacy Notice, Terms &amp; Conditions, and Disclaimer.</span>
      <button id="close-privacy-modal-btn" style="background:linear-gradient(135deg,#D4A017,#B8860B);color:#fff;border:none;padding:0.6rem 1.5rem;border-radius:9px;font-size:0.8rem;font-weight:700;cursor:pointer;font-family:inherit;transition:all 0.15s;box-shadow:0 4px 14px rgba(184,134,11,0.35);white-space:nowrap;" onmouseover="this.style.boxShadow='0 6px 20px rgba(184,134,11,0.5)'" onmouseout="this.style.boxShadow='0 4px 14px rgba(184,134,11,0.35)'">
        I Understand &amp; Continue
      </button>
    </div>

  </div>
</div>

<!-- React CDN -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/react/18.2.0/umd/react.production.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/react-dom/18.2.0/umd/react-dom.production.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/babel-standalone/7.23.2/babel.min.js"></script>
<script type="text/babel" src="assets/js/index.js"></script>

<script>
(function () {
  var btn  = document.getElementById('theme-toggle');
  var moon = document.getElementById('toggle-moon');
  var sun  = document.getElementById('toggle-sun');

  function applyTheme(mode) {
    if (mode === 'light') {
      document.body.classList.add('light-mode');
      moon.style.display = 'none';
      sun.style.display  = '';
    } else {
      document.body.classList.remove('light-mode');
      moon.style.display = '';
      sun.style.display  = 'none';
    }
    try { localStorage.setItem('tg-theme', mode); } catch(e) {}
  }

  // Apply saved or default (dark) on load
  var saved = 'dark';
  try { saved = localStorage.getItem('tg-theme') || 'dark'; } catch(e) {}
  applyTheme(saved);

  btn.addEventListener('click', function () {
    var isDark = !document.body.classList.contains('light-mode');
    applyTheme(isDark ? 'light' : 'dark');
  });
})();
</script>

<script>
(function() {
  var modal      = document.getElementById('privacy-modal');
  var closeBtn   = document.getElementById('close-privacy-modal');
  var closeBtn2  = document.getElementById('close-privacy-modal-btn');
  var tabBtns    = document.querySelectorAll('.legal-tab-btn');
  var STORAGE_KEY = 'tg-privacy-seen';

  function switchTab(tab) {
    document.getElementById('tab-privacy').style.display    = tab === 'privacy'    ? '' : 'none';
    document.getElementById('tab-terms').style.display      = tab === 'terms'      ? '' : 'none';
    document.getElementById('tab-disclaimer').style.display = tab === 'disclaimer' ? '' : 'none';
    tabBtns.forEach(function(b) { b.classList.toggle('active', b.dataset.tab === tab); });
    document.getElementById('privacy-modal-body').scrollTop = 0;
  }

  function openModal(tab) {
    switchTab(tab || 'privacy');
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    requestAnimationFrame(function() {
      requestAnimationFrame(function() { modal.classList.add('show'); });
    });
  }

  function closeModal() {
    modal.classList.remove('show');
    document.body.style.overflow = '';
    try { localStorage.setItem(STORAGE_KEY, '1'); } catch(e) {}
    setTimeout(function() { modal.style.display = 'none'; }, 320);
  }

  // Tab buttons
  tabBtns.forEach(function(btn) {
    btn.addEventListener('click', function() { switchTab(this.dataset.tab); });
  });

  // Footer legal links
  document.querySelectorAll('.footer-legal-link').forEach(function(link) {
    link.addEventListener('click', function(e) { e.preventDefault(); openModal(this.dataset.tab); });
  });

  if (closeBtn)  closeBtn.addEventListener('click', closeModal);
  if (closeBtn2) closeBtn2.addEventListener('click', closeModal);
  modal.addEventListener('click', function(e) { if (e.target === modal) closeModal(); });
  document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeModal(); });

  // Auto-show on first visit
  var seen = false;
  try { seen = !!localStorage.getItem(STORAGE_KEY); } catch(e) {}
  if (!seen) setTimeout(function() { openModal('privacy'); }, 800);
})();
</script>

</body>
</html>