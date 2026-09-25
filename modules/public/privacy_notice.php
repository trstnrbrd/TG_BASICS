<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/settings.php';

$company_name    = getSetting($conn, 'company_name',    'TG Customworks & Basic Car Insurance');
$company_address = getSetting($conn, 'company_address', '49 Villa Tierra St., San Roque, Pandi, Bulacan');
$fb_tg           = getSetting($conn, 'fb_tg',           'https://www.facebook.com/TGCustomworks');
$fb_basiccar     = getSetting($conn, 'fb_basiccar',     'https://www.facebook.com/BasicCarInsurance');
$company_phone   = getSetting($conn, 'company_phone',   '09171453448');
$company_email   = getSetting($conn, 'company_email',   'tgcustomworksbulacan@gmail.com');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Privacy Notice — <?= htmlspecialchars($company_name) ?></title>
<link rel="icon" type="image/png" href="../../assets/img/tg_logo.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../../assets/css/public/client.css?v=<?= filemtime(__DIR__ . '/../../assets/css/public/client.css') ?>"/>
<style>
.priv-container { max-width: 700px !important; }
.priv-section { padding: 0 0 1.5rem; }
.priv-section:last-child { padding-bottom: 0; }
.priv-eyebrow { font-size: 0.68rem; letter-spacing: 1.5px; text-transform: uppercase; color: #b8860b; font-weight: 700; margin-bottom: 0.5rem; }
.priv-body { font-size: 0.84rem; line-height: 1.8; color: #4a4440; }
.priv-body ul { margin-top: 0.5rem; margin-left: 1.25rem; display: flex; flex-direction: column; gap: 0.3rem; }
.priv-body strong { color: #1a1814; }
</style>
</head>
<body>

<!-- TOP NAV -->
<nav class="pub-nav">
  <a href="javascript:void(0)" onclick="history.back()" title="Back" style="display:flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:8px;background:rgba(255,255,255,0.06);color:rgba(255,255,255,0.65);text-decoration:none;flex-shrink:0;">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
  </a>
  <div class="pub-nav-logos">
    <img src="../../assets/img/tg_logo.png" alt="TG Customworks" class="pub-nav-img">
    <img src="../../assets/img/LogoBasicCar.png" alt="Basic Car Insurance" class="pub-nav-img">
  </div>
  <div class="pub-nav-brand">
    <div class="pub-nav-brand-name">TG<span>-BASICS</span></div>
    <div class="pub-nav-brand-sub"><?= htmlspecialchars($company_name) ?></div>
  </div>
</nav>
<div class="pub-gold-bar"></div>

<div class="pub-wrap">
  <div class="pub-container priv-container">

    <div class="pub-card">
      <div style="padding:1.75rem 1.75rem 0.5rem;">
        <div class="priv-eyebrow">Data Privacy</div>
        <div style="font-size:1.25rem;font-weight:800;color:#1a1814;margin-bottom:0.3rem;">Privacy Notice</div>
        <div style="font-size:0.75rem;color:#9c9286;">In accordance with Republic Act No. 10173 — Data Privacy Act of 2012</div>
      </div>

      <div style="padding:1.5rem 1.75rem 1.75rem;">

        <div style="background:#f4f1ec;border:1px solid #e2d9cc;border-radius:10px;padding:0.9rem 1.1rem;font-size:0.75rem;color:#9c9286;margin-bottom:1.5rem;">
          <strong style="color:#1a1814;">Effective Date:</strong> April 2026 &nbsp;&bull;&nbsp;
          <strong style="color:#1a1814;">Operator:</strong> TG Customworks &amp; Basic Car Insurance
        </div>

        <div class="priv-section">
          <div class="priv-eyebrow">1. Purpose of Data Collection</div>
          <p class="priv-body">TG Customworks &amp; Basic Car Insurance collects and processes personal information solely for the purpose of managing client insurance policies, vehicle records, claims processing, and repair job coordination.</p>
        </div>

        <div class="priv-section">
          <div class="priv-eyebrow">2. Data Collected</div>
          <p class="priv-body">We collect and store the following personal data:</p>
          <ul class="priv-body">
            <li>Full name, contact number, and email address</li>
            <li>Home or billing address</li>
            <li>Vehicle information (plate number, make, model, year, chassis and engine numbers)</li>
            <li>Insurance policy details (policy number, coverage type, premium, payment status)</li>
            <li>Claims documentation and incident details</li>
          </ul>
        </div>

        <div class="priv-section">
          <div class="priv-eyebrow">3. Legal Basis &amp; Consent</div>
          <p class="priv-body">Data processing is carried out in compliance with <strong>Republic Act No. 10173</strong>, the <strong>Data Privacy Act of 2012</strong>. Personal data is collected only after the client signs a printed Data Privacy Consent Form at our office, authorizing its collection and processing for the purposes stated above and for the performance of the client's insurance policy or repair service agreement.</p>
        </div>

        <div class="priv-section">
          <div class="priv-eyebrow">4. Data Retention</div>
          <p class="priv-body">Personal data is retained for as long as the client relationship is active and for a minimum of <strong>five (5) years</strong> after the last transaction, in accordance with applicable insurance regulations and the Data Privacy Act.</p>
        </div>

        <div class="priv-section">
          <div class="priv-eyebrow">5. Access and Security</div>
          <p class="priv-body">Access to personal data is strictly role-based and limited to authorized personnel. All passwords are encrypted, and system activity is logged through an audit trail to ensure accountability.</p>
        </div>

        <div class="priv-section">
          <div class="priv-eyebrow">6. Your Rights</div>
          <p class="priv-body">Under RA 10173, you have the right to:</p>
          <ul class="priv-body">
            <li><strong>Be informed</strong> — know what data is collected and how it is used</li>
            <li><strong>Access</strong> — request a copy of your personal data held by us</li>
            <li><strong>Rectification</strong> — request correction of inaccurate or outdated information</li>
            <li><strong>Erasure</strong> — request deletion of data when no longer necessary, subject to legal retention requirements</li>
            <li><strong>Object</strong> — object to the processing of your personal data in certain circumstances</li>
          </ul>
        </div>

        <div class="priv-section">
          <div class="priv-eyebrow">7. Data Sharing</div>
          <p class="priv-body">Your personal data is <strong>not shared, sold, or disclosed</strong> to third parties except when required by law, or when necessary for the processing of your insurance policy or claim with the insuring company you are enrolled under — either PhilBritish Insurance Corporation or Alpha Insurance &amp; Surety Company Inc.</p>
        </div>

        <div class="priv-section">
          <div class="priv-eyebrow">8. Contact Us</div>
          <p class="priv-body">For privacy-related concerns, requests, or inquiries, please contact us directly at <strong><?= htmlspecialchars($company_email) ?></strong> or <strong><?= htmlspecialchars($company_phone) ?></strong>, or visit our office at <?= htmlspecialchars($company_address) ?>.</p>
        </div>

      </div>
    </div>

    <div class="pub-footer">
      <p class="pub-footer-tagline">Have questions or concerns? We're here to help.</p>
      <div class="pub-footer-links">
        <a href="<?= htmlspecialchars($fb_tg) ?>" target="_blank" rel="noopener" class="pub-footer-link">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>
          TG Customworks
        </a>
        <a href="<?= htmlspecialchars($fb_basiccar) ?>" target="_blank" rel="noopener" class="pub-footer-link">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>
          Basic Car Insurance
        </a>
      </div>
      <div class="pub-footer-copy">&copy; <?= date('Y') ?> <strong><?= htmlspecialchars($company_name) ?></strong></div>
    </div>

  </div>
</div>

</body>
</html>
