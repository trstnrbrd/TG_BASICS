<?php require_once 'includes/icons.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<meta name="format-detection" content="telephone=no, date=no, email=no, address=no"/>
<title>TG-BASICS | Brokerage and Auto Shop Integrated Client System</title>
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

</body>
</html>