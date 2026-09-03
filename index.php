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
  <div class="nav-links">
    <a href="#services" class="nav-link">Services</a>
    <a href="#modules" class="nav-link">Modules</a>
    <a href="#roles" class="nav-link">Access</a>
    <a href="#security" class="nav-link">Security</a>
    <a href="#contact" class="nav-link">Contact</a>
  </div>
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

          <!-- Section label -->
          <div class="hc-section-label">
            <?= icon('clock', 11) ?> Renewal Tracking
          </div>

          <div class="hc-policy-row">
            <div class="hc-policy-item">
              <div class="hc-policy-left">
                <div class="hc-policy-plate">AAB 1234</div>
                <div>
                  <div class="hc-policy-name">Miguel R. Dela Cruz</div>
                  <div class="hc-policy-sub">Expires Dec 10, 2026</div>
                </div>
              </div>
              <span class="hc-badge green">Stable</span>
            </div>
            <div class="hc-policy-item">
              <div class="hc-policy-left">
                <div class="hc-policy-plate">BCD 5678</div>
                <div>
                  <div class="hc-policy-name">Andrea L. Santos</div>
                  <div class="hc-policy-sub">Expires in 24 days</div>
                </div>
              </div>
              <span class="hc-badge yellow">Expiring</span>
            </div>
            <div class="hc-policy-item">
              <div class="hc-policy-left">
                <div class="hc-policy-plate">EFG 9012</div>
                <div>
                  <div class="hc-policy-name">Jose P. Villanueva</div>
                  <div class="hc-policy-sub">Expires in 4 days</div>
                </div>
              </div>
              <span class="hc-badge red">Urgent</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Float cards -->
      <div class="hero-card-float">
        <div class="float-drag-handle"></div>
        <div class="float-icon"><?= icon('document', 18) ?></div>
        <div>
          <div class="float-label">Claims In Progress</div>
          <div class="float-val">3 Active</div>
        </div>
      </div>
      <div class="hero-card-float hero-card-float--2">
        <div class="float-drag-handle"></div>
        <div class="float-icon" style="background:rgba(34,197,94,0.12);border-color:rgba(34,197,94,0.2);color:#22c55e;"><?= icon('check-circle', 18) ?></div>
        <div>
          <div class="float-label">Policies Renewed</div>
          <div class="float-val">This Month</div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- SOCIAL PROOF / TRUST STRIP -->
<section class="proof-section">
  <div class="trust-inner js-reveal">
    <div class="section-label" style="justify-content:center;">Trusted</div>
    <h3 class="trust-heading">&ldquo;Your Journey, Our Care.&rdquo;</h3>
    <p class="trust-sub">PhilBritish-Accredited Broker &middot; Est. 2016</p>

    <div class="trust-rule">
      <span class="trust-rule-line"></span>
      <span class="trust-rule-plus">+</span>
      <span class="trust-rule-line"></span>
    </div>

    <div class="proof-stats">
      <div class="proof-stat">
        <div class="proof-stat-num" id="stat-clients-root"></div>
        <div class="proof-stat-label">System Modules</div>
      </div>
      <div class="proof-stat-sep"></div>
      <div class="proof-stat">
        <div class="proof-stat-num" id="stat-policies-root"></div>
        <div class="proof-stat-label">User Roles</div>
      </div>
      <div class="proof-stat-sep"></div>
      <div class="proof-stat">
        <div class="proof-stat-num" id="stat-modules-root"></div>
        <div class="proof-stat-label">Security Features</div>
      </div>
      <div class="proof-stat-sep"></div>
      <div class="proof-stat">
        <div class="proof-stat-num" id="stat-years-root"></div>
        <div class="proof-stat-label">Years in Operation</div>
      </div>
    </div>

    <div class="trust-rule">
      <span class="trust-rule-line"></span>
      <span class="trust-rule-plus">+</span>
      <span class="trust-rule-line"></span>
    </div>
  </div>
</section>

<!-- SERVICES OFFERED -->
<section id="services" class="page-section">
  <div class="section-container js-reveal-container">
    <div class="section-head js-reveal">
      <div class="section-label">Services Offered</div>
      <h2 class="section-title">Two businesses.<br><span>One roof.</span></h2>
      <p class="section-desc">TG Customworks and Basic Car Insurance operate side by side in Pandi, Bulacan &mdash; insurance brokerage and auto repair, both handled by the same team.</p>
    </div>

    <div class="services-grid">
      <!-- INSURANCE PILLAR -->
      <div class="service-pillar service-pillar--insurance">
        <div class="service-pillar-head">
          <img src="assets/img/LogoBasicCar.png" alt="Basic Car Insurance" class="service-pillar-logo"/>
          <div>
            <div class="service-pillar-name">Basic Car Insurance</div>
            <div class="service-pillar-tag">PhilBritish-Accredited Broker</div>
          </div>
        </div>
        <div class="service-item">
          <div class="service-item-icon"><?= icon('shield-check', 18) ?></div>
          <div>
            <div class="service-item-name">New Policy &amp; Eligibility Check</div>
            <div class="service-item-desc">Vehicle eligibility checked against PhilBritish's 10-year coverage window before any application moves forward.</div>
          </div>
        </div>
        <div class="service-item">
          <div class="service-item-icon"><?= icon('banknotes', 18) ?></div>
          <div>
            <div class="service-item-name">Premium Computation &amp; Issuance</div>
            <div class="service-item-desc">Coverage, participation fee, and total premium computed and explained before you sign anything.</div>
          </div>
        </div>
        <div class="service-item">
          <div class="service-item-icon"><?= icon('clipboard-list', 18) ?></div>
          <div>
            <div class="service-item-name">Claims Assistance</div>
            <div class="service-item-desc">Document collection, submission to head office, and status updates handled from filing to resolution.</div>
          </div>
        </div>
        <div class="service-item">
          <div class="service-item-icon"><?= icon('calendar', 18) ?></div>
          <div>
            <div class="service-item-name">Renewal &amp; Installment Plans</div>
            <div class="service-item-desc">Renewal reminders sent ahead of expiry, with 3, 4, or 6-month installment options on premium payments.</div>
          </div>
        </div>
      </div>

      <!-- REPAIR PILLAR -->
      <div class="service-pillar service-pillar--repair">
        <div class="service-pillar-head">
          <img src="assets/img/tg_logo.png" alt="TG Customworks" class="service-pillar-logo"/>
          <div>
            <div class="service-pillar-name">TG Customworks</div>
            <div class="service-pillar-tag">Auto Repair &amp; Paint Shop</div>
          </div>
        </div>
        <div class="service-item">
          <div class="service-item-icon"><?= icon('vehicle', 18) ?></div>
          <div>
            <div class="service-item-name">Inspection &amp; Free Quotation</div>
            <div class="service-item-desc">Every vehicle goes through a full external condition checklist before a no-obligation repair quotation is issued.</div>
          </div>
        </div>
        <div class="service-item">
          <div class="service-item-icon"><?= icon('swatch', 18) ?></div>
          <div>
            <div class="service-item-name">Dent Repair &amp; Paint</div>
            <div class="service-item-desc">Per-panel dent repair and paint, from a single panel touch-up to a full wash over, with color-matched finishing.</div>
          </div>
        </div>
        <div class="service-item">
          <div class="service-item-icon"><?= icon('wrench', 18) ?></div>
          <div>
            <div class="service-item-name">Preventive Maintenance Service</div>
            <div class="service-item-desc">Oil change, filter replacement, and brake cleaning scheduled around your vehicle's actual mileage.</div>
          </div>
        </div>
        <div class="service-item">
          <div class="service-item-icon"><?= icon('clock', 18) ?></div>
          <div>
            <div class="service-item-name">Real-Time Job Tracking</div>
            <div class="service-item-desc">From inspection to final release, repair status is visible to admin and mechanic at every stage.</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- BRAND PANEL -->
<section class="brand-panel js-reveal">
  <div class="bp-inner">
    <div class="bp-flow">

      <!-- REGISTER -->
      <div class="bp-step">
        <div class="bp-step-inner">
          <div class="bp-step-front">
            <div class="bp-step-num">01</div>
            <div class="bp-step-flip-hint"><?= icon('arrow-path', 12) ?></div>
            <div class="bp-step-icon"><?= icon('user-plus', 26) ?></div>
            <div class="bp-step-title">Register</div>
            <div class="bp-step-text">Client and vehicle details recorded into the central database.</div>
          </div>
          <div class="bp-step-back">
            <div class="bp-mock-screen">
              <div class="bm-topbar">
                <div class="bm-breadcrumb">Records <span class="bm-sep">›</span> <strong>Add Client</strong></div>
                <div class="bm-topbar-right"><div class="bm-avatar">AJ</div></div>
              </div>
              <div class="bm-content">
                <div class="bm-back-link">← Back to Client Records</div>
                <div class="bm-card">
                  <div class="bm-card-header">
                    <div class="bm-card-icon"><?= icon('user', 12) ?></div>
                    <div>
                      <div class="bm-card-title">Client Information</div>
                      <div class="bm-card-sub">Fields marked <span class="bm-req">*</span> are required</div>
                    </div>
                    <div class="bm-card-btn"><?= icon('camera', 10) ?> Scan Document</div>
                  </div>
                  <div class="bm-card-body">
                    <div class="bm-section">Personal Details</div>
                    <div class="bm-form-grid">
                      <div class="bm-field"><div class="bm-label">Full Name <span class="bm-req">*</span></div><div class="bm-input bm-input-filled">DELA CRUZ, JUAN M.</div></div>
                      <div class="bm-field"><div class="bm-label">Contact Number <span class="bm-req">*</span></div><div class="bm-input bm-input-filled">09171234567</div></div>
                      <div class="bm-field"><div class="bm-label">Email Address</div><div class="bm-input">jdelacruz@email.com</div></div>
                      <div class="bm-field"><div class="bm-label">Address <span class="bm-req">*</span></div><div class="bm-input bm-input-filled">San Roque, Pandi, Bulacan</div></div>
                    </div>
                    <div class="bm-section">Vehicle Details</div>
                    <div class="bm-form-grid-3">
                      <div class="bm-field"><div class="bm-label">Plate Number <span class="bm-req">*</span></div><div class="bm-input bm-input-filled">ABC 1234</div></div>
                      <div class="bm-field"><div class="bm-label">Make <span class="bm-req">*</span></div><div class="bm-input bm-input-filled">Toyota</div></div>
                      <div class="bm-field"><div class="bm-label">Model <span class="bm-req">*</span></div><div class="bm-input bm-input-filled">Vios</div></div>
                      <div class="bm-field"><div class="bm-label">Year Model <span class="bm-req">*</span></div><div class="bm-input bm-input-filled">2019</div></div>
                      <div class="bm-field bm-span2"><div class="bm-label">Color</div><div class="bm-input">Pearl White</div></div>
                    </div>
                  </div>
                  <div class="bm-footer">
                    <div class="bm-btn-ghost">Cancel</div>
                    <div class="bm-btn-primary"><?= icon('user-plus', 10) ?> Save Client Record</div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- INSURE -->
      <div class="bp-step">
        <div class="bp-step-inner">
          <div class="bp-step-front">
            <div class="bp-step-num">02</div>
            <div class="bp-step-flip-hint"><?= icon('arrow-path', 12) ?></div>
            <div class="bp-step-icon"><?= icon('document', 26) ?></div>
            <div class="bp-step-title">Insure</div>
            <div class="bp-step-text">Policy filed. OR/CR renewal and PhilBritish premium computed.</div>
          </div>
          <div class="bp-step-back">
            <div class="bp-mock-screen">
              <div class="bm-topbar">
                <div class="bm-breadcrumb">Insurance <span class="bm-sep">›</span> <strong>Add Policy</strong></div>
                <div class="bm-topbar-right"><div class="bm-avatar">AJ</div></div>
              </div>
              <div class="bm-content">
                <div class="bm-back-link">← Back to Renewal List</div>
                <div class="bm-vehicle-bar">
                  <span class="bm-vehicle-plate">ABC 1234</span>
                  <span class="bm-vehicle-info">Toyota Vios 2019 · DELA CRUZ, JUAN M.</span>
                </div>
                <div class="bm-card">
                  <div class="bm-card-header">
                    <div class="bm-card-icon"><?= icon('document', 12) ?></div>
                    <div>
                      <div class="bm-card-title">Policy Details</div>
                      <div class="bm-card-sub">PhilBritish Insurance Corporation</div>
                    </div>
                  </div>
                  <div class="bm-card-body">
                    <div class="bm-form-grid">
                      <div class="bm-field"><div class="bm-label">Policy Number <span class="bm-req">*</span></div><div class="bm-input bm-input-filled">PB-2026-08491</div></div>
                      <div class="bm-field"><div class="bm-label">Coverage Type <span class="bm-req">*</span></div><div class="bm-input bm-input-filled">Comprehensive</div></div>
                      <div class="bm-field"><div class="bm-label">Sum Insured <span class="bm-req">*</span></div><div class="bm-input bm-input-filled">₱600,000.00</div></div>
                      <div class="bm-field"><div class="bm-label">Basic Premium <span class="bm-req">*</span></div><div class="bm-input bm-input-filled">₱8,450.00</div></div>
                      <div class="bm-field"><div class="bm-label">Inception Date <span class="bm-req">*</span></div><div class="bm-input bm-input-filled">Jul 14, 2026</div></div>
                      <div class="bm-field"><div class="bm-label">Expiry Date <span class="bm-req">*</span></div><div class="bm-input bm-input-filled">Jul 14, 2027</div></div>
                    </div>
                  </div>
                  <div class="bm-footer">
                    <div class="bm-btn-ghost">Cancel</div>
                    <div class="bm-btn-primary"><?= icon('document', 10) ?> File Policy</div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- REPAIR -->
      <div class="bp-step">
        <div class="bp-step-inner">
          <div class="bp-step-front">
            <div class="bp-step-num">03</div>
            <div class="bp-step-flip-hint"><?= icon('arrow-path', 12) ?></div>
            <div class="bp-step-icon"><?= icon('wrench', 26) ?></div>
            <div class="bp-step-title">Repair</div>
            <div class="bp-step-text">Repair job opened, assigned to mechanic, and tracked to completion.</div>
          </div>
          <div class="bp-step-back">
            <div class="bp-mock-screen">
              <div class="bm-topbar">
                <div class="bm-breadcrumb">Repair <span class="bm-sep">›</span> <strong>Add Repair Job</strong></div>
                <div class="bm-topbar-right"><div class="bm-avatar">AJ</div></div>
              </div>
              <div class="bm-content">
                <div class="bm-back-link">← Back to Repair List</div>
                <div class="bm-search-bar">
                  <span class="bm-search-icon"><?= icon('magnifying-glass', 10) ?></span>
                  DELA CRUZ, JUAN M.
                  <span class="bm-search-clear">×</span>
                </div>
                <div class="bm-card">
                  <div class="bm-card-header">
                    <div class="bm-card-icon"><?= icon('wrench', 12) ?></div>
                    <div>
                      <div class="bm-card-title">Repair Job Details</div>
                      <div class="bm-card-sub">RJ-20260714-0001</div>
                    </div>
                  </div>
                  <div class="bm-card-body">
                    <div class="bm-form-grid">
                      <div class="bm-field"><div class="bm-label">Client <span class="bm-req">*</span></div><div class="bm-input bm-input-filled">DELA CRUZ, JUAN M.</div></div>
                      <div class="bm-field"><div class="bm-label">Vehicle / Plate <span class="bm-req">*</span></div><div class="bm-input bm-input-filled">ABC 1234 – Vios 2019</div></div>
                      <div class="bm-field"><div class="bm-label">Service Type <span class="bm-req">*</span></div><div class="bm-input bm-input-filled">Repair (Full)</div></div>
                      <div class="bm-field"><div class="bm-label">Repair Date <span class="bm-req">*</span></div><div class="bm-input bm-input-filled">Jul 14, 2026</div></div>
                      <div class="bm-field"><div class="bm-label">Release Date</div><div class="bm-input">Jul 17, 2026</div></div>
                      <div class="bm-field"><div class="bm-label">Additional Damages</div><div class="bm-input">Front bumper scratch</div></div>
                    </div>
                  </div>
                  <div class="bm-footer">
                    <div class="bm-btn-ghost">Cancel</div>
                    <div class="bm-btn-primary"><?= icon('wrench', 10) ?> Open Repair Job</div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- SETTLE -->
      <div class="bp-step">
        <div class="bp-step-inner">
          <div class="bp-step-front">
            <div class="bp-step-num">04</div>
            <div class="bp-step-flip-hint"><?= icon('arrow-path', 12) ?></div>
            <div class="bp-step-icon"><?= icon('receipt', 26) ?></div>
            <div class="bp-step-title">Settle</div>
            <div class="bp-step-text">Billing finalized, claims resolved, and records updated.</div>
          </div>
          <div class="bp-step-back">
            <div class="bp-mock-screen">
              <div class="bm-topbar">
                <div class="bm-breadcrumb">Insurance <span class="bm-sep">›</span> Billing <span class="bm-sep">›</span> <strong>New</strong></div>
                <div class="bm-topbar-right"><div class="bm-avatar">AJ</div></div>
              </div>
              <div class="bm-content">
                <div class="bm-two-col">
                  <div>
                    <div class="bm-card">
                      <div class="bm-card-header">
                        <div class="bm-card-icon"><?= icon('clipboard-list', 12) ?></div>
                        <div>
                          <div class="bm-card-title">Linked Claim</div>
                          <div class="bm-card-sub">Select approved claim</div>
                        </div>
                      </div>
                      <div class="bm-card-body">
                        <div class="bm-claim-sel">#12 — DELA CRUZ, JUAN M. (ABC 1234 · PB-2026-08491)</div>
                      </div>
                    </div>
                    <div class="bm-card">
                      <div class="bm-card-header">
                        <div class="bm-card-icon"><?= icon('document-text', 12) ?></div>
                        <div>
                          <div class="bm-card-title">Billing Details</div>
                          <div class="bm-card-sub">Insurance company &amp; dates</div>
                        </div>
                      </div>
                      <div class="bm-card-body">
                        <div class="bm-field bm-field-solo"><div class="bm-label">Billed To <span class="bm-req">*</span></div><div class="bm-input bm-input-filled">PhilBritish Insurance Corp.</div></div>
                        <div class="bm-form-grid">
                          <div class="bm-field"><div class="bm-label">Incident Date</div><div class="bm-input bm-input-filled">Jul 10, 2026</div></div>
                          <div class="bm-field"><div class="bm-label">Repair Date</div><div class="bm-input bm-input-filled">Jul 14, 2026</div></div>
                        </div>
                      </div>
                    </div>
                  </div>
                  <div>
                    <div class="bm-card">
                      <div class="bm-card-header">
                        <div class="bm-card-icon"><?= icon('receipt', 12) ?></div>
                        <div><div class="bm-card-title">Cost Summary</div></div>
                      </div>
                      <div class="bm-card-body">
                        <div class="bm-field bm-field-solo"><div class="bm-label">Parts Cost</div><div class="bm-input bm-input-filled">₱3,200.00</div></div>
                        <div class="bm-field bm-field-solo"><div class="bm-label">Labor Cost</div><div class="bm-input bm-input-filled">₱1,500.00</div></div>
                        <div class="bm-field bm-field-solo"><div class="bm-label">Other Cost</div><div class="bm-input bm-input-filled">₱350.00</div></div>
                        <div class="bm-field bm-field-solo"><div class="bm-label">Deductible</div><div class="bm-input bm-input-filled">₱500.00</div></div>
                        <div class="bm-total-row"><span>Total</span><span>₱4,550.00</span></div>
                      </div>
                      <div class="bm-footer">
                        <div class="bm-btn-primary"><?= icon('receipt', 10) ?> Save Billing</div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

    </div>
  </div>
</section>

<!-- MODULES -->
<section id="modules" class="page-section page-section--open">
  <div class="section-open-inner js-reveal-container">
    <div class="section-head js-reveal">
      <div class="section-label">System Modules</div>
      <h2 class="section-title">Six modules.<br><span>One platform.</span></h2>
      <p class="section-desc">Every feature built around the actual workflow of the business, from the first inspection to the final e-receipt.</p>
    </div>

    <div class="feat-rows">
      <div class="feat-rail"></div>

      <!-- 01 — Client and Vehicle Records -->
      <div class="feat-row" data-accent="1">
        <div class="feat-visual">
          <div class="feat-card">
            <div class="feat-card-topbar">
              <span class="feat-card-dot"></span><span class="feat-card-dot"></span><span class="feat-card-dot"></span>
              <span class="feat-card-title">Client Records</span>
            </div>
            <div class="feat-list">
              <div class="feat-list-row">
                <div class="feat-list-avatar">MD</div>
                <div class="feat-list-info">
                  <div class="feat-list-name">Miguel Dela Cruz</div>
                  <div class="feat-list-sub">945 RJCW &middot; 2021 Honda Civic</div>
                </div>
              </div>
              <div class="feat-list-row">
                <div class="feat-list-avatar">AS</div>
                <div class="feat-list-info">
                  <div class="feat-list-name">Andrea Santos</div>
                  <div class="feat-list-sub">BCD 5678 &middot; 2019 Toyota Vios</div>
                </div>
              </div>
              <div class="feat-list-row">
                <div class="feat-list-avatar">JV</div>
                <div class="feat-list-info">
                  <div class="feat-list-name">Jose Villanueva</div>
                  <div class="feat-list-sub">EFG 9012 &middot; 2020 Ford Ranger</div>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="feat-node"><span class="feat-node-dot"></span></div>
        <div class="feat-text">
          <div class="feat-num-row"><span class="feat-num">01</span><span class="feat-tag">Records</span></div>
          <h3 class="feat-heading">Client and Vehicle Records</h3>
          <p class="feat-desc">Centralized client profiles and vehicle details in one searchable database. Find any record by name, plate number, or policy number instantly.</p>
        </div>
      </div>

      <!-- 02 — Insurance Eligibility and Policy Processing -->
      <div class="feat-row feat-row--reverse" data-accent="2">
        <div class="feat-text">
          <div class="feat-num-row"><span class="feat-num">02</span><span class="feat-tag">Insurance</span></div>
          <h3 class="feat-heading">Insurance Eligibility and Policy Processing</h3>
          <p class="feat-desc">Automatic 10-year eligibility check for PhilBritish coverage, based on year model. Encode full policy details including premium, commission, and coverage type.</p>
        </div>
        <div class="feat-node"><span class="feat-node-dot"></span></div>
        <div class="feat-visual">
          <div class="feat-card">
            <div class="feat-card-topbar">
              <span class="feat-card-dot"></span><span class="feat-card-dot"></span><span class="feat-card-dot"></span>
              <span class="feat-card-title">Eligibility Check</span>
            </div>
            <div class="feat-elig-body">
              <div class="feat-elig-row"><span>Plate Number</span><strong>945 RJCW</strong></div>
              <div class="feat-elig-row"><span>Year Model</span><strong>2021</strong></div>
              <div class="feat-elig-row"><span>Vehicle Age</span><strong>5 years</strong></div>
              <div class="feat-elig-result eligible"><?= icon('check-circle', 16) ?> Eligible for PhilBritish Coverage</div>
            </div>
          </div>
        </div>
      </div>

      <!-- 03 — Policy Status and Renewal Tracker -->
      <div class="feat-row" data-accent="3">
        <div class="feat-visual">
          <div class="feat-card">
            <div class="feat-card-topbar">
              <span class="feat-card-dot"></span><span class="feat-card-dot"></span><span class="feat-card-dot"></span>
              <span class="feat-card-title">Renewal Tracker</span>
            </div>
            <div class="feat-policy-list">
              <div class="feat-policy-row">
                <div><div class="feat-policy-plate">AAB 1234</div><div class="feat-policy-sub">Expires Dec 10, 2026</div></div>
                <span class="feat-badge green">Stable</span>
              </div>
              <div class="feat-policy-row">
                <div><div class="feat-policy-plate">BCD 5678</div><div class="feat-policy-sub">Expires in 24 days</div></div>
                <span class="feat-badge yellow">Expiring</span>
              </div>
              <div class="feat-policy-row">
                <div><div class="feat-policy-plate">EFG 9012</div><div class="feat-policy-sub">Expires in 4 days</div></div>
                <span class="feat-badge red">Urgent</span>
              </div>
            </div>
          </div>
        </div>
        <div class="feat-node"><span class="feat-node-dot"></span></div>
        <div class="feat-text">
          <div class="feat-num-row"><span class="feat-num">03</span><span class="feat-tag">Renewal</span></div>
          <h3 class="feat-heading">Policy Status and Renewal Tracker</h3>
          <p class="feat-desc">Color-coded expiry dashboard. Green for stable, yellow for expiring within 30 days, red for urgent within 7 days. Full payment balance tracking.</p>
        </div>
      </div>

      <!-- 04 — Claims Document Tracker -->
      <div class="feat-row feat-row--reverse" data-accent="4">
        <div class="feat-text">
          <div class="feat-num-row"><span class="feat-num">04</span><span class="feat-tag">Claims</span></div>
          <h3 class="feat-heading">Claims Document Tracker</h3>
          <p class="feat-desc">Log every claim and track document completeness &mdash; policy, OR/CR, driver&rsquo;s license, and damage photos. Admin manually updates status from collection to resolution.</p>
        </div>
        <div class="feat-node"><span class="feat-node-dot"></span></div>
        <div class="feat-visual">
          <div class="feat-card">
            <div class="feat-card-topbar">
              <span class="feat-card-dot"></span><span class="feat-card-dot"></span><span class="feat-card-dot"></span>
              <span class="feat-card-title">Document Checklist</span>
            </div>
            <div class="feat-checklist">
              <div class="feat-check-item checked"><span class="feat-check-box"><?= icon('check', 12) ?></span>Insurance Policy</div>
              <div class="feat-check-item checked"><span class="feat-check-box"><?= icon('check', 12) ?></span>OR / CR</div>
              <div class="feat-check-item checked"><span class="feat-check-box"><?= icon('check', 12) ?></span>Driver&rsquo;s License</div>
              <div class="feat-check-item"><span class="feat-check-box"></span>Damage Photos</div>
            </div>
            <div class="feat-check-progress"><div class="feat-check-bar" style="width:75%"></div></div>
            <div class="feat-check-label">3 of 4 documents received</div>
          </div>
        </div>
      </div>

      <!-- 05 — Repair Job Management -->
      <div class="feat-row" data-accent="5">
        <div class="feat-visual">
          <div class="feat-card">
            <div class="feat-card-topbar">
              <span class="feat-card-dot"></span><span class="feat-card-dot"></span><span class="feat-card-dot"></span>
              <span class="feat-card-title">Repair Jobs</span>
            </div>
            <div class="feat-policy-list">
              <div class="feat-policy-row">
                <div><div class="feat-policy-plate">RJ-0231</div><div class="feat-policy-sub">2021 Honda Civic</div></div>
                <span class="feat-badge blue">In Progress</span>
              </div>
              <div class="feat-policy-row">
                <div><div class="feat-policy-plate">RJ-0232</div><div class="feat-policy-sub">2019 Toyota Vios</div></div>
                <span class="feat-badge gold">For Pickup</span>
              </div>
              <div class="feat-policy-row">
                <div><div class="feat-policy-plate">RJ-0233</div><div class="feat-policy-sub">2020 Ford Ranger</div></div>
                <span class="feat-badge yellow">Pending</span>
              </div>
            </div>
          </div>
        </div>
        <div class="feat-node"><span class="feat-node-dot"></span></div>
        <div class="feat-text">
          <div class="feat-num-row"><span class="feat-num">05</span><span class="feat-tag">Repair Shop</span></div>
          <h3 class="feat-heading">Repair Job Management</h3>
          <p class="feat-desc">Mechanic submits a digital inspection checklist on arrival. Admin monitors job stages from Pending through In Progress, For Pickup, and Completed.</p>
        </div>
      </div>

      <!-- 06 — Quotation and E-Receipt Generator -->
      <div class="feat-row feat-row--reverse" data-accent="6">
        <div class="feat-text">
          <div class="feat-num-row"><span class="feat-num">06</span><span class="feat-tag">Billing</span></div>
          <h3 class="feat-heading">Quotation and E-Receipt Generator</h3>
          <p class="feat-desc">Build quotations from the digital service catalog. Once payment is confirmed, the system converts the quotation directly into a formatted e-receipt &mdash; no double encoding.</p>
        </div>
        <div class="feat-node"><span class="feat-node-dot"></span></div>
        <div class="feat-visual">
          <div class="feat-card">
            <div class="feat-card-topbar">
              <span class="feat-card-dot"></span><span class="feat-card-dot"></span><span class="feat-card-dot"></span>
              <span class="feat-card-title">Quotation Q-20260902-0001</span>
            </div>
            <div class="feat-receipt-body">
              <div class="feat-receipt-row"><span>Minor Scratch Repair &mdash; Front Door</span><strong>PHP 3,500.00</strong></div>
              <div class="feat-receipt-row"><span>Paint</span><strong>PHP 2,000.00</strong></div>
              <div class="feat-receipt-total"><span>Total</span><strong>PHP 5,500.00</strong></div>
            </div>
            <div class="feat-receipt-stamp">PAID</div>
          </div>
        </div>
      </div>

    </div>
  </div>
</section>
<!-- ACCESS LEVELS -->
<section id="roles" class="page-section">
  <div class="section-container js-reveal-container">
    <div class="section-head js-reveal">
      <div class="section-label">Access Levels</div>
      <h2 class="section-title">The right access<br><span>for every role.</span></h2>
      <p class="section-desc">Each user is redirected to their own dashboard after login. No self-registration. Accounts are created by the administrator.</p>
    </div>
    <div class="roles-matrix js-reveal-item">
      <div class="rm-header">
        <div class="rm-perm-col">Module / Permission</div>
        <div class="rm-role-col">
          <div class="rm-role-badge"><?= icon('shield-check', 15) ?></div>
          <div class="rm-role-name">Super Admin</div>
        </div>
        <div class="rm-role-col">
          <div class="rm-role-badge"><?= icon('users', 15) ?></div>
          <div class="rm-role-name">Admin</div>
        </div>
        <div class="rm-role-col">
          <div class="rm-role-badge"><?= icon('wrench', 15) ?></div>
          <div class="rm-role-name">Mechanic</div>
        </div>
      </div>
      <div class="rm-row">
        <div class="rm-perm">Client &amp; Vehicle Records</div>
        <div class="rm-cell"><span class="rm-dot rm-full"><?= icon('check', 12) ?></span></div>
        <div class="rm-cell"><span class="rm-dot rm-full"><?= icon('check', 12) ?></span></div>
        <div class="rm-cell"><span class="rm-dot rm-none"></span></div>
      </div>
      <div class="rm-row">
        <div class="rm-perm">Insurance &amp; Renewals</div>
        <div class="rm-cell"><span class="rm-dot rm-full"><?= icon('check', 12) ?></span></div>
        <div class="rm-cell"><span class="rm-dot rm-full"><?= icon('check', 12) ?></span></div>
        <div class="rm-cell"><span class="rm-dot rm-none"></span></div>
      </div>
      <div class="rm-row">
        <div class="rm-perm">Claims Management</div>
        <div class="rm-cell"><span class="rm-dot rm-full"><?= icon('check', 12) ?></span></div>
        <div class="rm-cell"><span class="rm-dot rm-full"><?= icon('check', 12) ?></span></div>
        <div class="rm-cell"><span class="rm-dot rm-none"></span></div>
      </div>
      <div class="rm-row">
        <div class="rm-perm">Repair Jobs</div>
        <div class="rm-cell"><span class="rm-dot rm-full"><?= icon('check', 12) ?></span></div>
        <div class="rm-cell"><span class="rm-dot rm-full"><?= icon('check', 12) ?></span></div>
        <div class="rm-cell"><span class="rm-dot rm-full"><?= icon('check', 12) ?></span></div>
      </div>
      <div class="rm-row">
        <div class="rm-perm">Billing</div>
        <div class="rm-cell"><span class="rm-dot rm-full"><?= icon('check', 12) ?></span></div>
        <div class="rm-cell"><span class="rm-dot rm-full"><?= icon('check', 12) ?></span></div>
        <div class="rm-cell"><span class="rm-dot rm-none"></span></div>
      </div>
      <div class="rm-row">
        <div class="rm-perm">User &amp; Account Management</div>
        <div class="rm-cell"><span class="rm-dot rm-full"><?= icon('check', 12) ?></span></div>
        <div class="rm-cell"><span class="rm-dot rm-none"></span></div>
        <div class="rm-cell"><span class="rm-dot rm-none"></span></div>
      </div>
      <div class="rm-row">
        <div class="rm-perm">Audit Logs &amp; System Settings</div>
        <div class="rm-cell"><span class="rm-dot rm-full"><?= icon('check', 12) ?></span></div>
        <div class="rm-cell"><span class="rm-dot rm-none"></span></div>
        <div class="rm-cell"><span class="rm-dot rm-none"></span></div>
      </div>
    </div>
  </div>
</section>

<!-- SECURITY & TECHNOLOGY -->
<section id="security" class="page-section">
  <div class="section-container js-reveal-container">
    <div class="section-head js-reveal">
      <div class="section-label">Security &amp; Technology</div>
      <h2 class="section-title">Built secure.<br><span>Built to last.</span></h2>
      <p class="section-desc">Enterprise-grade security features protecting every transaction and record in the system.</p>
    </div>
    <div class="sec-cards-grid">
      <div class="sec-card" data-accent="1">
        <div class="sec-card-top">
          <div class="sec-card-icon"><?= icon('lock-closed', 20) ?></div>
          <span class="sec-card-num">01</span>
        </div>
        <div class="sec-card-name">Two-Factor Authentication</div>
        <p class="sec-card-desc">Email-based 2FA codes plus TOTP authenticator app support for every login with 2FA enabled.</p>
      </div>
      <div class="sec-card" data-accent="2">
        <div class="sec-card-top">
          <div class="sec-card-icon"><?= icon('shield-check', 20) ?></div>
          <span class="sec-card-num">02</span>
        </div>
        <div class="sec-card-name">Account Lockout Protection</div>
        <p class="sec-card-desc">Automatic lockout after configurable failed attempts with IP-based rate limiting to block brute-force attacks.</p>
      </div>
      <div class="sec-card" data-accent="3">
        <div class="sec-card-top">
          <div class="sec-card-icon"><?= icon('clipboard-list', 20) ?></div>
          <span class="sec-card-num">03</span>
        </div>
        <div class="sec-card-name">Full Audit Trail</div>
        <p class="sec-card-desc">Every login, data change, and system action logged with timestamps and user identity for full accountability.</p>
      </div>
      <div class="sec-card" data-accent="4">
        <div class="sec-card-top">
          <div class="sec-card-icon"><?= icon('cog', 20) ?></div>
          <span class="sec-card-num">04</span>
        </div>
        <div class="sec-card-name">Role-Based Access Control</div>
        <p class="sec-card-desc">Three distinct roles with strict permission boundaries. No cross-role data access or unauthorized module entry.</p>
      </div>
    </div>
  </div>
</section>
<!-- CLIENT TESTIMONIALS -->
<section id="testimonials" class="page-section page-section--open">
  <div class="section-open-inner js-reveal-container">
    <div class="section-head js-reveal">
      <div class="section-label">Client Testimonials</div>
      <h2 class="section-title">What our clients<br><span>are saying.</span></h2>
      <p class="section-desc">Feedback shared by TG Customworks and Basic Car Insurance clients, lightly rephrased for clarity.</p>
    </div>

    <?php
    // 'year' is the review's actual posting year — the "X years ago" label is computed
    // below so it stays correct without manual edits as time passes.
    $testimonials = [
      ['name' => 'Jjpot G.',         'source' => 'Facebook Review', 'year' => 2020, 'rating' => 5, 'quote' => 'Sobrang linis at maayos ang trabaho — makikita mo talaga ang kalidad sa sasakyan ko.'],
      ['name' => 'John Jack D.',     'source' => 'Facebook Review', 'year' => 2020, 'rating' => 5, 'quote' => 'Napakagaling ng kalidad ng trabaho, mabilis pa at pulido ang resulta. Mabait din po ang may-ari, si Mr. Carpio, sa mga kliyente.'],
      ['name' => 'Joseph Albert O.', 'source' => 'Google Review',   'year' => 2021, 'rating' => 5, 'quote' => 'The service was perfect, and the owner, Mr. Carpio, was very accommodating to his clients.'],
      ['name' => 'John Michael A.',  'source' => 'Google Review',   'year' => 2021, 'rating' => 5, 'quote' => 'Their work is solid.'],
      ['name' => 'Ana Katrina V.',   'source' => 'Google Review',   'year' => 2024, 'rating' => 5, 'quote' => 'Maayos ang serbisyo nila.'],
    ];
    $current_year = (int)date('Y');
    foreach ($testimonials as &$t) {
        $diff = max(0, $current_year - $t['year']);
        $t['meta'] = $t['source'] . ' · ' . ($diff === 0 ? 'This year' : ($diff === 1 ? '1 year ago' : $diff . ' years ago'));
    }
    unset($t);
    ?>

    <div class="testimonial-carousel-wrap">
      <div class="testimonial-carousel" id="testimonial-carousel">
        <?php foreach ($testimonials as $t): ?>
        <div class="testimonial-card<?= !empty($t['placeholder']) ? ' testimonial-card--placeholder' : '' ?>">
          <?php if (!empty($t['placeholder'])): ?>
          <div class="testimonial-placeholder-badge">Placeholder</div>
          <?php endif; ?>
          <div class="testimonial-stars">
            <?php for ($i = 1; $i <= 5; $i++): ?>
              <svg class="rating-star<?= $i <= $t['rating'] ? ' is-filled' : '' ?>" width="15" height="15" viewBox="0 0 20 20"><path d="M10 1.5l2.472 5.007 5.528.803-4 3.899.944 5.507L10 14.14l-4.944 2.576.944-5.507-4-3.899 5.528-.803z"/></svg>
            <?php endfor; ?>
          </div>
          <p class="testimonial-quote">&ldquo;<?= htmlspecialchars($t['quote']) ?>&rdquo;</p>
          <div class="testimonial-author">
            <div class="testimonial-avatar"><?= strtoupper(substr(trim($t['name'], '[]'), 0, 1)) ?></div>
            <div>
              <div class="testimonial-name"><?= htmlspecialchars($t['name']) ?></div>
              <div class="testimonial-meta"><?= htmlspecialchars($t['meta']) ?></div>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="carousel-nav">
        <button type="button" class="carousel-arrow" id="testimonial-prev" aria-label="Previous testimonial"><?= icon('chevron-left', 16) ?></button>
        <button type="button" class="carousel-arrow" id="testimonial-next" aria-label="Next testimonial"><?= icon('chevron-right', 16) ?></button>
      </div>
    </div>
  </div>
</section>

<!-- CTA -->
<section id="contact" class="cta-section js-reveal">
  <div class="cta-inner">
    <div class="cta-bg-glow"></div>
    <div class="cta-content">
      <div class="section-label" style="justify-content:center;">Ready to get started?</div>
      <h2 class="cta-title">Everything you need.<br><span>All in one place.</span></h2>
      <p class="cta-desc">TG-BASICS is built exclusively for the team. Sign in to access client records, policies, claims, repair jobs, and billing — all from a single dashboard.</p>
      <div class="cta-actions">
        <a href="auth/login.php" class="btn-primary-hero">
          <?= icon('lock-closed', 14) ?> Sign In to TG-BASICS
        </a>
      </div>
    </div>
  </div>
</section>

<!-- FOOTER -->
<footer>
  <div class="footer-inner">

    <!-- Brand col -->
    <div class="footer-brand-col">
      <div class="footer-brand-name">
        <img src="assets/img/tg_logo.png" alt="TG Customworks" class="footer-logo-img"/>
        <div class="footer-logo-sep"></div>
        <img src="assets/img/LogoBasicCar.png" alt="Basic Car Insurance" class="footer-logo-img no-ring"/>
        TG<span>-BASICS</span>
      </div>
      <p class="footer-brand-desc">Brokerage and Auto Shop Integrated Central System. Built exclusively for TG Customworks and Basic Car Insurance.</p>
      <div class="footer-socials">
        <a href="https://www.facebook.com/TGCustomWorks" target="_blank" rel="noopener noreferrer" class="footer-social-icon" title="TG Customworks Facebook">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>
          TG Customworks
        </a>
        <a href="https://www.facebook.com/basiccarinsurance" target="_blank" rel="noopener noreferrer" class="footer-social-icon" title="Basic Car Insurance Facebook">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>
          Basic Car Insurance
        </a>
      </div>
    </div>

    <!-- Location col -->
    <div class="footer-col">
      <h4>Location</h4>
      <nav class="footer-nav">
        <span>49 Villa Tierra St., San Roque</span>
        <span>Pandi, Bulacan, Philippines</span>
        <span>Gerald Peterson V. Carpio, Prop.</span>
        <span>Operating Since 2016</span>
      </nav>
    </div>

    <!-- System col -->
    <div class="footer-col">
      <h4>System</h4>
      <nav class="footer-nav">
        <span>Built with PHP + MySQL</span>
        <span>Web-based Internal System</span>
        <span>STI College Sta. Maria</span>
        <span>Capstone Project 2026</span>
      </nav>
    </div>

    <!-- Legal col -->
    <div class="footer-col">
      <h4>Legal</h4>
      <nav class="footer-nav">
        <a href="#" class="footer-legal-link" data-tab="privacy">Privacy Notice</a>
        <a href="#" class="footer-legal-link" data-tab="terms">Terms &amp; Conditions</a>
        <a href="#" class="footer-legal-link" data-tab="disclaimer">Disclaimer</a>
      </nav>
    </div>

    <!-- Contact col -->
    <div class="footer-col">
      <h4>Contact Us</h4>
      <nav class="footer-nav">
        <a href="tel:09171453448" class="footer-nav-link">0917 145 3448</a>
        <a href="mailto:tgcustomworksbulacan@gmail.com" class="footer-nav-link">tgcustomworksbulacan<br/>@gmail.com</a>
      </nav>
    </div>

  </div>
  <div class="footer-bottom">
    <p>TG-BASICS &mdash; <span>TG Customworks and Basic Car Insurance</span></p>
    <p>&copy; <?= date('Y') ?> TG Customworks &amp; Basic Car Insurance. All rights reserved. &nbsp;&middot;&nbsp; Internal use only.</p>
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
    <div style="display:flex;align-items:center;justify-content:space-between;padding:1.1rem 1.75rem;border-bottom:1px solid rgba(255,255,255,0.07);background:rgba(212,160,23,0.06);flex-shrink:0;">
      <div style="display:flex;align-items:center;gap:0.85rem;">
        <div style="width:36px;height:36px;background:linear-gradient(135deg,#D4A017,#B8860B);border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0;box-shadow:0 4px 12px rgba(184,134,11,0.35);">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        </div>
        <div>
          <div style="font-size:0.95rem;font-weight:800;color:#E8E2D8;letter-spacing:-0.3px;">Legal Documents</div>
          <div style="font-size:0.67rem;color:#7A7268;margin-top:0.1rem;">TG Customworks &amp; Basic Car Insurance &mdash; TG-BASICS</div>
        </div>
      </div>
      <button id="close-privacy-modal" style="background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.08);color:#7A7268;cursor:pointer;padding:0.4rem;border-radius:8px;transition:all 0.15s;line-height:1;display:flex;" onmouseover="this.style.background='rgba(255,255,255,0.1)';this.style.color='#E8E2D8'" onmouseout="this.style.background='rgba(255,255,255,0.05)';this.style.color='#7A7268'">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
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
    <div id="privacy-modal-body" style="padding:1.5rem 1.75rem;display:flex;flex-direction:column;gap:1.1rem;font-size:0.82rem;line-height:1.8;color:#B8B0A4;overflow-y:auto;">
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
    <div style="padding:1rem 1.75rem;border-top:1px solid rgba(255,255,255,0.07);display:flex;justify-content:space-between;align-items:center;background:rgba(0,0,0,0.25);flex-shrink:0;gap:1rem;flex-wrap:wrap;">
      <span style="font-size:0.7rem;color:#7A7268;line-height:1.5;">By accessing TG-BASICS, you acknowledge and agree to our<br/>Privacy Notice, Terms &amp; Conditions, and Disclaimer.</span>
      <button id="close-privacy-modal-btn" style="background:linear-gradient(135deg,#D4A017,#B8860B);color:#fff;border:none;padding:0.6rem 1.5rem;border-radius:9px;font-size:0.8rem;font-weight:700;cursor:pointer;font-family:inherit;transition:all 0.15s;box-shadow:0 4px 14px rgba(184,134,11,0.35);white-space:nowrap;" onmouseover="this.style.boxShadow='0 6px 20px rgba(184,134,11,0.5)'" onmouseout="this.style.boxShadow='0 4px 14px rgba(184,134,11,0.35)'">
        I Understand &amp; Continue
      </button>
    </div>
  </div>
</div>

<script src="assets/js/index.js?v=<?= filemtime('assets/js/index.js') ?>"></script>

<script>
(function() {
  var floats = document.querySelectorAll('.hero-card-float');
  var card   = document.querySelector('.hero-card-main');
  if (!card) return;

  var cursorGrab    = "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24'%3E%3Cpath d='M9 3v8M12 2v8M15 4v6M18 6v5c1.1 0 2 .9 2 2v3a5 5 0 01-5 5h-4a5 5 0 01-5-5v-5c0-1.1.9-2 2-2h.5V3c0-1.1.9-2 2-2s1.5.9 1.5 2' stroke='%23000' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round' fill='white' paint-order='stroke'/%3E%3C/svg%3E\") 9 2, grab";
  var cursorGrabbing = "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24'%3E%3Cpath d='M8 8h8a2 2 0 012 2v4a5 5 0 01-5 5H9a5 5 0 01-5-5v-4a2 2 0 012-2h2V5a1 1 0 012 0v3z' stroke='%23000' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round' fill='white' paint-order='stroke'/%3E%3C/svg%3E\") 9 8, grabbing";

  floats.forEach(function(el) {
    el.style.cursor = cursorGrab;
    el.style.userSelect = 'none';
    el.style.transition = 'box-shadow 0.2s, transform 0.15s';

    var dragging = false, startX, startY, origX, origY;

    el.addEventListener('mousedown', function(e) {
      e.preventDefault();
      dragging = true;
      el.style.cursor = cursorGrabbing;
      el.style.transform = 'scale(1.05)';
      el.style.boxShadow = '0 12px 40px rgba(0,0,0,0.5)';
      el.style.zIndex = '10';
      el.style.transition = 'box-shadow 0.2s, transform 0.15s';

      var rect  = el.getBoundingClientRect();
      var cRect = card.getBoundingClientRect();
      startX = e.clientX;
      startY = e.clientY;
      origX  = rect.left - cRect.left;
      origY  = rect.top  - cRect.top;

      // switch to absolute positioning within card
      el.style.position = 'absolute';
      el.style.left  = origX + 'px';
      el.style.top   = origY + 'px';
      el.style.bottom = 'auto';
      el.style.right  = 'auto';
    });

    document.addEventListener('mousemove', function(e) {
      if (!dragging) return;
      var cRect  = card.getBoundingClientRect();
      var elRect = el.getBoundingClientRect();
      var dx = e.clientX - startX;
      var dy = e.clientY - startY;

      var newX = Math.min(Math.max(origX + dx, -elRect.width / 2), cRect.width  - elRect.width / 2);
      var newY = Math.min(Math.max(origY + dy, -elRect.height / 2), cRect.height - elRect.height / 2);

      el.style.left = newX + 'px';
      el.style.top  = newY + 'px';
    });

    document.addEventListener('mouseup', function() {
      if (!dragging) return;
      dragging = false;
      el.style.cursor = cursorGrab;
      el.style.transform = 'scale(1)';
      el.style.boxShadow = '0 8px 32px rgba(0,0,0,0.4)';
      el.style.zIndex = '3';
    });
  });
})();

</script>

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

  tabBtns.forEach(function(btn) {
    btn.addEventListener('click', function() { switchTab(this.dataset.tab); });
  });

  document.querySelectorAll('.footer-legal-link').forEach(function(link) {
    link.addEventListener('click', function(e) { e.preventDefault(); openModal(this.dataset.tab); });
  });

  if (closeBtn)  closeBtn.addEventListener('click', closeModal);
  if (closeBtn2) closeBtn2.addEventListener('click', closeModal);
  modal.addEventListener('click', function(e) { if (e.target === modal) closeModal(); });
  document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeModal(); });

  var seen = false;
  try { seen = !!localStorage.getItem(STORAGE_KEY); } catch(e) {}
  if (!seen) setTimeout(function() { openModal('privacy'); }, 800);
})();
</script>

</body>
</html>
