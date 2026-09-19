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
<title>Terms of Use — <?= htmlspecialchars($company_name) ?></title>
<link rel="icon" type="image/png" href="../../assets/img/tg_logo.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../../assets/css/public/client.css"/>
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
        <div class="priv-eyebrow">Terms of Use</div>
        <div style="font-size:1.25rem;font-weight:800;color:#1a1814;margin-bottom:0.3rem;">Client Digital Profile — Terms of Use</div>
        <div style="font-size:0.75rem;color:#9c9286;">Applies to the read-only profile accessed through your Digital ID / QR code</div>
      </div>

      <div style="padding:1.5rem 1.75rem 1.75rem;">

        <div class="priv-section">
          <div class="priv-eyebrow">1. What This Page Is</div>
          <p class="priv-body">This is a read-only digital profile provided by <?= htmlspecialchars($company_name) ?> so you can view your own vehicle, insurance policy, and repair job records at a glance. It does not let you edit any information — changes to your records can only be made by our staff.</p>
        </div>

        <div class="priv-section">
          <div class="priv-eyebrow">2. Accessing Your Profile</div>
          <p class="priv-body">Your profile is accessed through a unique QR code / link issued to you. Please treat this link as private — anyone with the link can view the information shown on this page. If you believe your link has been shared without your consent, please contact us immediately so we can issue you a new one.</p>
        </div>

        <div class="priv-section">
          <div class="priv-eyebrow">3. Accuracy of Information</div>
          <p class="priv-body">While we take reasonable care to keep your records accurate and up to date, this page is provided for your convenience only and does not replace your original insurance policy documents, official receipts, or the Certificate of Registration issued for your vehicle. In case of any discrepancy, the physical/original documents on file with us and with your insuring company shall prevail.</p>
        </div>

        <div class="priv-section">
          <div class="priv-eyebrow">4. No Warranty</div>
          <p class="priv-body">This service is provided "as is." We do our best to keep it available and accurate, but we do not guarantee uninterrupted access, and we are not liable for any decision made solely based on information shown on this page without verifying it with our office.</p>
        </div>

        <div class="priv-section">
          <div class="priv-eyebrow">5. Data Privacy</div>
          <p class="priv-body">Information shown on this page is handled in accordance with our <a href="privacy_notice.php" style="color:#b8860b;font-weight:600;">Privacy Notice</a>, issued under Republic Act No. 10173 (Data Privacy Act of 2012).</p>
        </div>

        <div class="priv-section">
          <div class="priv-eyebrow">6. Contact Us</div>
          <p class="priv-body">For questions, corrections, or concerns about your profile, please contact us at <strong><?= htmlspecialchars($company_email) ?></strong> or <strong><?= htmlspecialchars($company_phone) ?></strong>, or visit our office at <?= htmlspecialchars($company_address) ?>.</p>
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
