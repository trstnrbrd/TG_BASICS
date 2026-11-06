<?php
require_once __DIR__ . '/includes/branding.php';
require_once 'includes/icons.php';
require_once __DIR__ . '/config/app_url.php';
require_once __DIR__ . '/config/version.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/settings.php';
require_once __DIR__ . '/includes/lookups.php';
$testimonials = load_testimonials($conn);
require_once __DIR__ . '/includes/insurance_companies.php';
// partner insurers for the page text, already escaped (Settings > Insurance Companies)
$ins_tag      = htmlspecialchars(insurance_companies_phrase($conn, 'short_name', '&'));    // PhilBritish & Alpha Insurance
$ins_and      = htmlspecialchars(insurance_companies_phrase($conn, 'short_name', 'and'));
$ins_legal_or = htmlspecialchars(insurance_companies_phrase($conn, 'billing_name', 'or'));
$ins_legal    = htmlspecialchars(insurance_companies_phrase($conn, 'billing_name', 'and'));
$ins_first    = htmlspecialchars(insurance_companies_active($conn)[0]['billing_name'] ?? '');
// [label, website or ''] — the website is insurance_companies.website (phpMyAdmin); no address, no link
$insurers     = array_map(fn($c) => [trim((string)($c['short_name'] ?? '')) !== '' ? $c['short_name'] : $c['name'], insurance_company_website($conn, $c['name'])],
                          insurance_companies_active($conn));
$company      = company_info($conn);   // Settings > Company Info
$phone_digits = preg_replace('/\D+/', '', $company['contact']);
require_once __DIR__ . '/config/validators.php';
// the shop on Google Maps (Settings > Company Info); without one, directions search the address instead
$maps_url     = web_url_clean($company['maps_url'] ?? '');
$directions   = $maps_url !== '' ? $maps_url : 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($company['address'] . ', Philippines');
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);

const LANDING_SINCE = 2016;
$years = max(1, (int)date('Y') - LANDING_SINCE);

// Reviews summary for the hero, from the testimonials table (nothing shown when there are none)
$review_count = count($testimonials);
$review_avg   = $review_count ? round(array_sum(array_column($testimonials, 'rating')) / $review_count, 1) : 0;

// Hero photos (optional): a repaired car shown by default, the damaged one painted under the pointer.
// Drop both files in assets/img/landing/ and the effect turns on; without them the hero keeps its plain background.
$hero_after  = 'assets/img/landing/hero_after.jpg';
$hero_before = 'assets/img/landing/hero_before.jpg';
$hero_photos = is_file(__DIR__ . '/' . $hero_after) && is_file(__DIR__ . '/' . $hero_before);
$hero_v      = fn($p) => $p . '?v=' . filemtime(__DIR__ . '/' . $p);

$_desc = 'TG-BASICS is the management system of TG Customworks and Basic Car Insurance in Pandi, Bulacan — '
       . 'clients, policies, claims, renewals and repair jobs in one place.';

$nav = [
    ['services', 'Services'], ['modules', 'Modules'], ['roles', 'Access'],
    ['security', 'Security'], ['testimonials', 'Reviews'], ['contact', 'Contact'],
];
if (!$testimonials) $nav = array_values(array_filter($nav, fn($n) => $n[0] !== 'testimonials'));

$services = [
    'Basic Car Insurance' => [
        ['New Policy & Eligibility Check', "Vehicle eligibility checked against the shared 10-year coverage window for {$ins_and} before any application moves forward."],
        ['Premium Computation & Issuance', 'Coverage, participation fee, and total premium computed and explained before you sign anything.'],
        ['Claims Assistance', 'Document collection, submission to head office, and status updates handled from filing to resolution.'],
        ['Renewal & Installment Plans', 'Renewal reminders sent ahead of expiry, with 3, 4, or 6-month installment options on premium payments.'],
    ],
    'TG Customworks' => [
        ['Inspection & Free Quotation', 'Every vehicle goes through a full external condition checklist before a no-obligation repair quotation is issued.'],
        ['Dent Repair & Paint', 'Per-panel dent repair and paint, from a single panel touch-up to a full wash over, with color-matched finishing.'],
        ['Preventive Maintenance Service', "Oil change, filter replacement, and brake cleaning scheduled around your vehicle's actual mileage."],
        ['Real-Time Job Tracking', 'From inspection to final release, repair status is visible to admin and mechanic at every stage.'],
    ],
];

$modules = [
    ['Records', 'Client and Vehicle Records', 'Centralized client profiles and vehicle details in one searchable database. Find any record by name, plate number, or policy number instantly.', ['Clients', 'Vehicles', 'Search']],
    ['Insurance', 'Insurance Eligibility and Policy Processing', "Automatic 10-year eligibility check for {$ins_and} coverage, based on year model. Encode full policy details including premium, commission, and coverage type.", ['Eligibility', 'Policies']],
    ['Renewal', 'Policy Status and Renewal Tracker', 'Color-coded expiry dashboard. Green for stable, yellow for expiring within 30 days, red for urgent within 7 days. Full payment balance tracking.', ['Renewals', 'Payments']],
    ['Claims', 'Claims Document Tracker', 'Log every claim and track document completeness — policy, OR/CR, driver’s license, and damage photos. Admin manually updates status from collection to resolution.', ['Claims', 'Documents']],
    ['Repair Shop', 'Repair Job Management', 'Mechanic submits a digital inspection checklist on arrival. Admin monitors job stages from Pending through In Progress, For Pickup, and Completed.', ['Inspection', 'Job Status']],
    ['Billing', 'Quotation and E-Receipt Generator', 'Build quotations from the digital service catalog. Once payment is confirmed, the system converts the quotation directly into a formatted e-receipt — no double encoding.', ['Quotations', 'E-Receipts']],
];

// A miniature of each module's real screen (same cards, badges, plates and tables as the app), with sample data
// only: never a real client. One look per card.
$viz_keys = ['records', 'insurance', 'renewal', 'claims', 'repair', 'billing'];
$vhead = fn(string $ic, string $title, string $sub, string $right = '') =>
    '<div class="v-head"><span class="v-head-icon">' . icon($ic, 12) . '</span><span class="v-head-text"><b>' . $title . '</b><small>' . $sub . '</small></span>' . $right . '</div>';
$vbadge = fn(string $tone, string $label, string $ic = '') => '<span class="v-badge v-badge--' . $tone . '">' . ($ic !== '' ? icon($ic, 9) : '') . $label . '</span>';
$vplate = fn(string $p) => '<span class="v-plate">' . $p . '</span>';
$eye = '<span class="v-eye">' . icon('eye', 10) . '</span>';
$ins_tabs = array_slice(array_column($insurers, 0), 0, 2);
$viz = [
    'records' => $vhead('users', 'My Clients', '3 records')
        . '<div class="v-table"><div class="v-tr v-th v-cols-rec"><span>Client ID</span><span>Client</span><span>Type</span><span></span></div>'
        . implode('', array_map(fn($r) => '<div class="v-tr v-cols-rec"><span class="v-id">' . $r[0] . '</span><b>' . $r[1] . '</b><span>' . $r[2] . '</span>' . $eye . '</div>', [
            ['CL-0001', 'MIGUEL DELA CRUZ', $vbadge('green', 'Insurance', 'shield-check')],
            ['CL-0002', 'ANDREA SANTOS', $vbadge('gold', 'Walk-in', 'wrench')],
            ['CL-0003', 'JOSE VILLANUEVA', $vbadge('green', 'Insurance', 'shield-check')],
        ])) . '</div>',
    'insurance' => '<div class="v-verdict"><span class="v-verdict-icon">' . icon('check', 12) . '</span><span><b>Eligible for Coverage</b><small>Toyota Vios (NAB 4821) can be insured.</small></span></div>'
        . '<div class="v-grid3"><span><small>Plate number</small><b>NAB 4821</b></span><span><small>Year model</small><b>2019</b></span><span><small>Vehicle age</small><b>7 years old</b></span></div>'
        . '<div class="v-rule">' . icon('document-text', 10) . '<span><b>Eligibility Rule:</b> 10 years old or newer.</span></div>',
    'renewal' => ($ins_tabs ? '<div class="v-tabs">' . implode('', array_map(fn($n, $k) => '<span class="' . ($k === 0 ? 'is-on' : '') . '">' . icon('shield-check', 9) . $e($n) . '</span>', $ins_tabs, array_keys($ins_tabs))) . '</div>' : '')
        . '<div class="v-table"><div class="v-tr v-th v-cols-ren"><span>Plate</span><span>Expiry</span><span>Status</span><span>Payment</span></div>'
        . implode('', array_map(fn($r) => '<div class="v-tr v-cols-ren">' . $vplate($r[0]) . '<span>' . $r[1] . '</span><span>' . $r[2] . '</span><span>' . $r[3] . '</span></div>', [
            ['AAB 1234', 'Dec 10, 2026', $vbadge('green', 'Stable'), $vbadge('green', 'Paid')],
            ['BCD 5678', 'Nov 30, 2026', $vbadge('yellow', 'Expiring'), $vbadge('yellow', 'Partial')],
            ['EFG 9012', 'Nov 10, 2026', $vbadge('red', 'Urgent'), $vbadge('red', 'Unpaid')],
        ])) . '</div>',
    'claims' => '<div class="v-flow"><span class="is-on">Compiling Requirements</span><i>&rsaquo;</i><span>Sent to Admin</span><i>&rsaquo;</i><span>Sent to Head Office</span></div>'
        . $vhead('document-text', 'Insurance Claim Requirements', '3/4 documents received', '<span class="v-left">1 remaining</span>')
        . implode('', array_map(fn($r) => '<div class="v-doc' . ($r[2] ? ' is-done' : '') . '"><span class="v-box">' . icon('check', 9) . '</span><span><b>' . $r[0] . '</b><small>' . $r[1] . '</small></span></div>', [
            ['Policy', 'Copy of the insurance policy', 1], ['OR &mdash; Official Receipt', 'LTO receipt of the vehicle', 1], ['Damage photos', 'Close-up of every damaged part', 0],
        ])),
    'repair' => '<div class="v-top">' . $vbadge('gold', 'For Pickup') . '<span class="v-btn">' . icon('arrow-right', 9) . 'Update Status</span></div>'
        . $vhead('clipboard-list', 'Unit Inspection Checklist', 'Damage assessment at intake')
        . '<div class="v-areas"><span class="v-area v-area--major"><b>Hood</b><small>Major Damage</small></span><span class="v-area v-area--minor"><b>Right Front Door</b><small>Minor Scratch</small></span></div>'
        . '<div class="v-chips"><span>Front Bumper</span><span>Trunk</span><span>Windshield</span><span>Left Mirror</span></div>',
    'billing' => $vhead('clipboard-list', 'Line Items', '2 items', $vbadge('green', 'Paid'))
        . '<div class="v-table"><div class="v-tr v-th v-cols-bill"><span>Description</span><span>Subtotal</span></div>'
        . '<div class="v-tr v-cols-bill"><b>Major Damage Repair &mdash; Hood</b><span class="v-mono">PHP 3,500.00</span></div>'
        . '<div class="v-tr v-cols-bill"><b>Minor Scratch Repair &mdash; Door</b><span class="v-mono">PHP 3,500.00</span></div></div>'
        . '<div class="v-totals"><b>TOTAL</b><b class="v-mono">PHP 7,000.00</b></div>',
];

// [permission, super admin, admin, mechanic]
$access = [
    ['Client & Vehicle Records', 1, 1, 0], ['Insurance & Renewals', 1, 1, 0], ['Claims Management', 1, 1, 0],
    ['Repair Jobs', 1, 1, 1], ['Billing', 1, 1, 0], ['User & Account Management', 1, 0, 0], ['Audit Logs & System Settings', 1, 0, 0],
];

$security = [
    ['Two-Factor Authentication', 'Email-based 2FA codes plus TOTP authenticator app support for every login with 2FA enabled.'],
    ['Account Lockout Protection', 'Automatic lockout after configurable failed attempts with IP-based rate limiting to block brute-force attacks.'],
    ['Full Audit Trail', 'Every login, data change, and system action logged with timestamps and user identity for full accountability.'],
    ['Role-Based Access Control', 'Three distinct roles with strict permission boundaries. No cross-role data access or unauthorized module entry.'],
];

$stats = [[count($modules), '', 'System modules'], [3, '', 'User roles'], [count($security), '', 'Security layers'], [$years, '+', 'Years in operation']];

$socials = [
    ['TG Customworks', 'https://www.facebook.com/TGCustomWorks'],
    ['Basic Car Insurance', 'https://www.facebook.com/basiccarinsurance'],
];

$arrow = fn(string $dir = 'right') => $dir === 'up'
    ? '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 17 17 7M8 7h9v9" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>'
    : '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
$fb = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>';
$star = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2.5l2.9 5.88 6.49.94-4.7 4.58 1.11 6.46L12 17.9l-5.8 3.05 1.1-6.46-4.69-4.58 6.49-.94L12 2.5z"/></svg>';
// a pill button: dark / light / outline, optionally with a round arrow badge
$pill = function (string $label, string $variant, array $attrs = [], ?string $arrow_dir = null) use ($arrow): string {
    $tag = isset($attrs['href']) ? 'a' : 'button';
    if ($tag === 'button' && !isset($attrs['type'])) $attrs['type'] = 'button';
    $a = '';
    foreach ($attrs as $k => $v) $a .= ' ' . $k . '="' . htmlspecialchars($v, ENT_QUOTES) . '"';
    $badge = $arrow_dir ? '<span class="lp-pill-badge lp-arrow-' . $arrow_dir . '">' . $arrow($arrow_dir) . '</span>' : '';
    return "<$tag class=\"lp-pill lp-pill--$variant" . ($arrow_dir ? ' has-arrow' : '') . "\"$a><span>" . $label . "</span>$badge</$tag>";
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<meta name="format-detection" content="telephone=no, date=no, email=no, address=no"/>
<title>TG-BASICS | Brokerage and Auto Shop Integrated Control System</title>
<meta name="description" content="<?= $e($_desc) ?>"/>
<!-- Link preview when the address is shared on Messenger / Facebook. og:image must be an absolute URL. -->
<meta property="og:type" content="website"/>
<meta property="og:site_name" content="TG-BASICS"/>
<meta property="og:title" content="TG-BASICS — TG Customworks &amp; Basic Car Insurance"/>
<meta property="og:description" content="<?= $e($_desc) ?>"/>
<meta property="og:url" content="<?= $e(app_url('')) ?>"/>
<meta property="og:image" content="<?= $e(app_url('assets/img/pwa/icon-512.png')) ?>"/>
<meta property="og:image:width" content="512"/>
<meta property="og:image:height" content="512"/>
<meta property="og:image:alt" content="TG Customworks logo"/>
<meta name="twitter:card" content="summary"/>
<link rel="icon" type="image/png" href="<?= brand_logo('tg') ?>"/>
<link rel="apple-touch-icon" href="assets/img/pwa/apple-touch-icon.png"/>
<link rel="manifest" href="manifest.webmanifest"/>
<meta name="theme-color" content="#1A1814"/>
<script>
// Theme before the page is drawn (no flash): the visitor's saved choice, else light.
// The intro plays once per browser session, so staff opening the page again go straight in.
(function () {
  var d = document.documentElement, t = 'light', seen = false;
  try { t = localStorage.getItem('tg-theme') || 'light'; seen = sessionStorage.getItem('tg-intro') === '1'; } catch (e) {}
  d.setAttribute('data-theme', t === 'dark' ? 'dark' : 'light');
  if (seen || (window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches)) d.classList.add('lp-no-intro');
})();
</script>
<script src="assets/js/shared/pwa.js?v=<?= filemtime(__DIR__ . '/assets/js/shared/pwa.js') ?>" defer></script>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="assets/css/index.css?v=<?= filemtime(__DIR__ . '/assets/css/index.css') ?>"/>
</head>
<body>

<a href="#main" class="lp-skip">Skip to content</a>

<!-- INTRO -->
<div class="lp-loader" id="lp-loader" aria-hidden="true">
  <div class="lp-loader-center">
    <div class="lp-loader-brand">
      <img src="<?= brand_logo('tg') ?>" alt=""/>
      <img src="<?= brand_logo('basic') ?>" alt=""/>
      <span>TG<b>-BASICS</b></span>
    </div>
    <p>Your Journey, Our Care.</p>
  </div>
  <div class="lp-loader-progress">
    <div class="lp-loader-track"><div class="lp-loader-fill" id="lp-loader-fill"></div></div>
    <div class="lp-loader-row"><span>Loading</span><span class="lp-tnum" id="lp-loader-num">000</span></div>
  </div>
</div>

<!-- HEADER: a floating glass bar that stays at the top while scrolling -->
<header class="lp-header lp-enter" style="--d:150ms">
  <div class="lp-topnav" id="lp-topnav">
    <a href="#home" class="lp-brand" data-scroll>
      <span class="lp-brand-logos">
        <img src="<?= brand_logo('tg') ?>" alt="TG Customworks" class="lp-logo-tg"/>
        <span class="lp-logo-sep" aria-hidden="true"></span>
        <img src="<?= brand_logo('basic') ?>" alt="Basic Car Insurance" class="lp-logo-basic"/>
      </span>
      <span class="lp-brand-text">
        <span class="lp-brand-name">TG<b>-BASICS</b></span>
        <span class="lp-brand-tag">Management System</span>
      </span>
    </a>
    <nav class="lp-nav" aria-label="Sections">
      <ul>
        <?php foreach ($nav as [$id, $label]): ?>
        <li><a href="#<?= $id ?>" <?= $id === 'contact' ? 'data-contact' : 'data-scroll' ?>><?= $label ?></a></li>
        <?php endforeach; ?>
      </ul>
    </nav>
    <div class="lp-header-right">
      <span class="lp-nav-label">Internal system &mdash; authorized users only</span>
      <button type="button" class="lp-theme-btn" id="theme-toggle" aria-label="Switch light / dark">
        <svg class="lp-moon" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <svg class="lp-sun" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4.5" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 2v2M12 20v2M4.2 4.2l1.4 1.4M18.4 18.4l1.4 1.4M2 12h2M20 12h2M4.2 19.8l1.4-1.4M18.4 5.6l1.4-1.4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
      </button>
      <a href="auth/login.php" class="lp-signin"><?= icon('lock-closed', 13) ?><span>Sign In</span></a>
      <button type="button" class="lp-theme-btn lp-menu-btn" id="lp-menu-open" aria-haspopup="dialog" aria-controls="lp-menu" aria-label="Menu">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
      </button>
    </div>
  </div>
</header>

<main id="main">

<!-- HERO -->
<section id="home" class="lp-hero">
  <div class="lp-hero-media" aria-hidden="true">
    <?php if ($hero_photos): ?>
      <img src="<?= $hero_v($hero_after) ?>" alt="" class="lp-hero-img" fetchpriority="high"/>
      <canvas class="lp-hero-canvas" id="lp-reveal" data-src="<?= $hero_v($hero_before) ?>"></canvas>
    <?php else: ?>
      <div class="lp-hero-plain"></div>
    <?php endif; ?>
  </div>
  <div class="lp-hero-vignette" aria-hidden="true"></div>
  <div class="lp-watermark lp-hero-mark lp-enter" style="--d:300ms" aria-hidden="true">TG-BASICS</div>

  <div class="lp-shell lp-hero-grid">
    <div class="lp-hero-left">
      <div class="lp-eyebrow lp-enter" style="--d:200ms"><span class="lp-dot"></span>Pandi, Bulacan &mdash; Since <?= LANDING_SINCE ?></div>
      <h1 class="lp-h1" data-lines data-delay="250" data-stagger="120">
        <span>One system.</span>
        <span>Every record.</span>
        <span class="lp-accent-text">Always <span id="lp-type">ready.</span><span class="lp-type-cursor" aria-hidden="true"></span></span>
      </h1>
      <p class="lp-hero-sub lp-enter" style="--d:550ms">TG-BASICS centralizes client records, insurance policies, repair workflows, and billing for TG Customworks and Basic Car Insurance into a single platform.</p>
      <?php if ($review_count): ?>
      <div class="lp-rating lp-enter" style="--d:650ms">
        <span class="lp-stars" aria-hidden="true"><?= str_repeat($star, 5) ?></span>
        <span><b><?= number_format($review_avg, 1) ?></b> from <?= $review_count ?> client review<?= $review_count === 1 ? '' : 's' ?></span>
      </div>
      <?php endif; ?>
      <div class="lp-cta-row lp-enter" style="--d:750ms">
        <?= $pill('Sign In', 'dark', ['href' => 'auth/login.php'], 'right') ?>
        <?= $pill('See Services', 'outline', ['href' => '#services', 'data-scroll' => '']) ?>
      </div>
    </div>

    <div class="lp-hero-right">
      <div class="lp-hero-card lp-enter lp-enter--pop" style="--d:400ms" id="lp-hero-card" role="group" aria-roledescription="carousel" aria-label="What TG-BASICS handles">
        <div class="lp-hero-card-tile">
          <img src="<?= brand_logo('basic') ?>" alt="" data-logo="basic" class="lp-tile-logo lp-tile-logo--basic is-active"/>
          <img src="<?= brand_logo('tg') ?>" alt="" data-logo="tg" class="lp-tile-logo"/>
        </div>
        <div class="lp-hero-card-panel">
          <div class="lp-hero-card-slot" aria-live="polite">
            <div class="lp-hero-card-item is-active" data-logo="basic"><span>Basic Car Insurance</span><b>Policies, renewals, claims.</b></div>
            <div class="lp-hero-card-item" data-logo="tg"><span>TG Customworks</span><b>Repairs tracked to release.</b></div>
            <div class="lp-hero-card-item" data-logo="tg"><span>Billing</span><b>Quotation to e-receipt.</b></div>
          </div>
          <div class="lp-hero-card-foot">
            <div class="lp-hero-dots"><i class="is-active"></i><i></i><i></i></div>
            <div class="lp-hero-card-nav">
              <button type="button" data-step="-1" aria-label="Previous"><?= $arrow() ?></button>
              <button type="button" data-step="1" aria-label="Next"><?= $arrow() ?></button>
            </div>
          </div>
        </div>
      </div>
      <?php if ($insurers): ?>
      <div class="lp-partners lp-enter" style="--d:550ms">
        <div class="lp-partners-label"><?= $ins_tag ?>-Accredited Broker</div>
        <ul>
          <?php foreach ($insurers as [$name, $site]):
            $dot = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="1.6"/><circle cx="12" cy="12" r="3.2" fill="currentColor"/></svg>'; ?>
          <li><?php if ($site !== ''): ?><a href="<?= $e($site) ?>" target="_blank" rel="noopener noreferrer" class="lp-partner-link" title="<?= $e($name) ?> website (opens in a new tab)"><?= $dot . $e($name) ?><span class="lp-partner-ext" aria-hidden="true"><?= $arrow('up') ?></span></a><?php else: ?><?= $dot . $e($name) ?><?php endif; ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="lp-shell lp-hero-bar lp-enter lp-enter--fade" style="--d:900ms">
    <span>Operating since <?= LANDING_SINCE ?></span>
    <span class="lp-hide-sm">Insurance brokerage &amp; auto repair</span>
    <span>Scroll to explore <span aria-hidden="true">&darr;</span></span>
  </div>
</section>

<!-- ABOUT -->
<section class="lp-section lp-about">
  <div class="lp-shell lp-about-grid">
    <div class="lp-about-left">
      <div class="lp-eyebrow">The Shop</div>
      <div class="lp-shop-card lp-reveal">
        <div class="lp-shop-row">
          <span class="lp-shop-icon"><?= icon('map-pin', 18) ?></span>
          <div><small>Visit us</small><b><?= $e($company['address']) ?>, Philippines</b></div>
        </div>
        <div class="lp-shop-row">
          <span class="lp-shop-icon"><?= icon('phone', 18) ?></span>
          <div><small>Call or text</small><a href="tel:<?= $e($phone_digits) ?>"><b><?= $e($company['contact']) ?></b></a></div>
        </div>
        <div class="lp-shop-row">
          <span class="lp-shop-icon"><?= icon('clock', 18) ?></span>
          <div><small>Serving clients</small><b>Since <?= LANDING_SINCE ?> &middot; <?= $years ?>+ years</b></div>
        </div>
        <a class="lp-shop-directions" href="<?= $e($directions) ?>" target="_blank" rel="noopener noreferrer">
          <?= $maps_url !== '' ? 'Open in Google Maps' : 'Get directions' ?> <span aria-hidden="true"><?= $arrow('up') ?></span>
        </a>
      </div>
    </div>
    <div class="lp-about-right">
      <h2 class="lp-statement" data-words>Two businesses under one roof &mdash; <span class="lp-muted">insurance brokerage and auto repair, handled by the same team, with every record in one system.</span></h2>
      <div class="lp-about-foot lp-reveal" style="--d:200ms">
        <div>
          <div class="lp-small-label">Find us on Facebook</div>
          <div class="lp-socials">
            <?php foreach ($socials as $i => [$label, $url]): ?>
            <a href="<?= $e($url) ?>" target="_blank" rel="noopener noreferrer" class="lp-social<?= $i === 0 ? ' is-accent' : '' ?>"><span class="lp-social-icon"><?= $fb ?></span><?= $e($label) ?></a>
            <?php endforeach; ?>
          </div>
        </div>
        <?= $pill('Contact Us', 'outline', ['data-contact' => ''], 'right') ?>
      </div>
    </div>
  </div>
</section>

<!-- HOW IT WORKS -->
<section class="lp-steps-section" aria-labelledby="lp-steps-title">
  <div class="lp-shell">
    <div class="lp-steps-head">
      <div class="lp-eyebrow lp-reveal">How it works</div>
      <h2 id="lp-steps-title" class="lp-steps-title lp-reveal">From your first visit to the final receipt.</h2>
    </div>
    <ol class="lp-steps">
      <?php foreach ([
          ['user-plus', 'Register', 'Client and vehicle details recorded into the central database.'],
          ['shield-check', 'Insure', 'Policy filed. OR/CR renewal and insurer premium computed.'],
          ['wrench', 'Repair', 'Repair job opened, assigned to a mechanic, and tracked to completion.'],
          ['banknotes', 'Settle', 'Billing finalized, claims resolved, and records updated.'],
      ] as $i => [$ic, $title, $desc]): ?>
      <li class="lp-step lp-reveal" style="--d:<?= $i * 110 ?>ms">
        <div class="lp-step-top">
          <span class="lp-step-icon"><?= icon($ic, 20) ?></span>
          <span class="lp-step-num"><?= sprintf('%02d', $i + 1) ?></span>
        </div>
        <h3><?= $title ?></h3>
        <p><?= $desc ?></p>
      </li>
      <?php endforeach; ?>
    </ol>
  </div>
</section>

<!-- SERVICES -->
<section id="services" class="lp-section">
  <div class="lp-shell">
    <div class="lp-eyebrow lp-reveal">Services Offered</div>
    <h2 class="lp-h2 lp-h2--gap" data-lines data-delay="120"><span>Two businesses.</span><span class="lp-accent-text">One roof.</span></h2>
    <div class="lp-services">
      <?php foreach ($services as $biz => $rows): ?>
      <div class="lp-service-group">
        <div class="lp-service-head lp-reveal">
          <img src="<?= brand_logo($biz === 'TG Customworks' ? 'tg' : 'basic') ?>" alt="" class="<?= $biz === 'TG Customworks' ? 'is-tg' : 'is-basic' ?>"/>
          <div>
            <h3><?= $biz ?></h3>
            <span><?= $biz === 'TG Customworks' ? 'Auto Repair &amp; Paint Shop' : $ins_tag . '-Accredited Broker' ?></span>
          </div>
        </div>
        <ul class="lp-rows">
          <?php foreach ($rows as $i => [$title, $desc]): ?>
          <li class="lp-reveal" style="--d:<?= $i * 80 ?>ms">
            <div class="lp-row">
              <span class="lp-row-num"><?= sprintf('%02d', $i + 1) ?></span>
              <div class="lp-row-text"><h4><?= $e($title) ?></h4><p><?= $desc ?></p></div>
              <span class="lp-row-badge"><?= $arrow('up') ?></span>
            </div>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- MODULES -->
<section id="modules" class="lp-section lp-section--tight-top">
  <div class="lp-shell">
    <div class="lp-center">
      <div class="lp-eyebrow lp-eyebrow--boxed lp-reveal">System Modules</div>
      <h2 class="lp-h2 lp-h2--center" data-lines data-delay="120"><span>Six modules. <span class="lp-accent-text">One platform.</span></span></h2>
      <p class="lp-lead lp-reveal">Every feature built around the actual workflow of the business, from the first inspection to the final e-receipt.</p>
    </div>
    <ul class="lp-cards">
      <?php foreach ($modules as $i => [$tag, $title, $desc, $chips]): ?>
      <li class="lp-reveal lp-reveal--far" style="--d:<?= ($i % 3) * 90 ?>ms">
        <article class="lp-card lp-card--<?= $viz_keys[$i] ?>">
          <div class="lp-card-meta"><span><?= sprintf('%02d', $i + 1) ?> &mdash; <?= $e($tag) ?></span><span class="lp-card-dot" aria-hidden="true"></span></div>
          <div class="lp-viz" aria-hidden="true"><?= $viz[$viz_keys[$i]] ?></div>
          <div class="lp-card-body">
            <h3><?= $e($title) ?></h3>
            <p><?= $desc ?></p>
            <div class="lp-tags"><?php foreach ($chips as $c): ?><span><?= $e($c) ?></span><?php endforeach; ?></div>
          </div>
        </article>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>

<!-- ACCESS -->
<section id="roles" class="lp-section lp-section--tight-top">
  <div class="lp-shell lp-split">
    <div>
      <div class="lp-eyebrow lp-reveal">Access Levels</div>
      <h2 class="lp-h2" data-lines data-delay="120"><span>The right access</span><span class="lp-accent-text">for every role.</span></h2>
      <p class="lp-lead lp-lead--left lp-reveal">Each user is redirected to their own dashboard after login. No self-registration. Accounts are created by the administrator.</p>
    </div>
    <div class="lp-matrix lp-reveal" role="table" aria-label="What each role can open">
      <div class="lp-matrix-row lp-matrix-head" role="row">
        <span role="columnheader">Module</span><span role="columnheader">Super Admin</span><span role="columnheader">Admin</span><span role="columnheader">Mechanic</span>
      </div>
      <?php foreach ($access as [$perm, $sa, $ad, $me]): ?>
      <div class="lp-matrix-row" role="row">
        <span role="rowheader"><?= $e($perm) ?></span>
        <?php foreach ([$sa, $ad, $me] as $on): ?>
        <span role="cell"><?= $on ? '<i class="lp-yes" aria-label="Yes">' . icon('check', 12) . '</i>' : '<i class="lp-no" aria-label="No"></i>' ?></span>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- SECURITY -->
<section id="security" class="lp-section lp-section--tight-top">
  <div class="lp-shell">
    <div class="lp-eyebrow lp-reveal">Security &amp; Technology</div>
    <h2 class="lp-h2 lp-h2--gap" data-lines data-delay="120"><span>Built secure.</span><span class="lp-accent-text">Built to last.</span></h2>
    <ul class="lp-rows lp-rows--big">
      <?php foreach ($security as $i => [$title, $desc]): ?>
      <li class="lp-reveal" style="--d:<?= $i * 80 ?>ms">
        <div class="lp-row">
          <span class="lp-row-num"><?= sprintf('%02d', $i + 1) ?></span>
          <h3 class="lp-row-title"><?= $e($title) ?></h3>
          <p class="lp-row-desc"><?= $e($desc) ?></p>
          <span class="lp-row-badge"><?= $arrow('up') ?></span>
        </div>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>

<!-- STATS -->
<section class="lp-stats-section">
  <div class="lp-shell">
    <div class="lp-stats lp-reveal lp-reveal--panel">
      <div class="lp-eyebrow lp-eyebrow--light">By the numbers</div>
      <h2 class="lp-h2 lp-h2--panel" data-lines data-delay="120"><span>&ldquo;Your Journey, Our Care.&rdquo;</span></h2>
      <ul class="lp-stats-grid">
        <?php foreach ($stats as $i => [$num, $suffix, $label]): ?>
        <li class="lp-reveal" style="--d:<?= $i * 90 ?>ms">
          <div class="lp-stat-num"><span class="lp-tnum" data-count="<?= (int)$num ?>"><?= (int)$num ?></span><?= $suffix ?></div>
          <div class="lp-stat-label"><?= $label ?></div>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
</section>

<?php if ($testimonials): ?>
<!-- TESTIMONIALS -->
<section id="testimonials" class="lp-section lp-section--tight-top">
  <div class="lp-shell">
    <div class="lp-testi-head">
      <div>
        <div class="lp-eyebrow lp-reveal">Client Testimonials</div>
        <h2 class="lp-h2" data-lines data-delay="120"><span>What our clients</span><span class="lp-accent-text">are saying.</span></h2>
        <p class="lp-lead lp-lead--left lp-reveal">Feedback shared by TG Customworks and Basic Car Insurance clients, lightly rephrased for clarity.</p>
        <?php if ($maps_url !== ''): ?>
        <a class="lp-reviews-link lp-reveal" href="<?= $e($maps_url) ?>" target="_blank" rel="noopener noreferrer">
          <span class="lp-stars" aria-hidden="true"><?= str_repeat($star, 5) ?></span>
          <span>See all reviews on Google</span>
          <span class="lp-reviews-ext" aria-hidden="true"><?= $arrow('up') ?></span>
        </a>
        <?php endif; ?>
      </div>
      <div class="carousel-nav lp-reveal">
        <button type="button" class="carousel-arrow" id="testimonial-prev" aria-label="Previous testimonial"><?= icon('chevron-left', 16) ?></button>
        <button type="button" class="carousel-arrow" id="testimonial-next" aria-label="Next testimonial"><?= icon('chevron-right', 16) ?></button>
      </div>
    </div>
    <div class="testimonial-carousel" id="testimonial-carousel">
      <?php foreach ($testimonials as $t): ?>
      <div class="testimonial-card">
        <div class="testimonial-stars">
          <?php for ($i = 1; $i <= 5; $i++): ?>
            <svg class="rating-star<?= $i <= $t['rating'] ? ' is-filled' : '' ?>" width="15" height="15" viewBox="0 0 20 20"><path d="M10 1.5l2.472 5.007 5.528.803-4 3.899.944 5.507L10 14.14l-4.944 2.576.944-5.507-4-3.899 5.528-.803z"/></svg>
          <?php endfor; ?>
        </div>
        <p class="testimonial-quote">&ldquo;<?= htmlspecialchars($t['quote']) ?>&rdquo;</p>
        <div class="testimonial-author">
          <div class="testimonial-avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr(trim($t['name']), 0, 1))) ?></div>
          <div>
            <div class="testimonial-name"><?= htmlspecialchars($t['name']) ?></div>
            <div class="testimonial-meta"><?= htmlspecialchars($t['meta']) ?></div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

</main>

<!-- FOOTER -->
<footer id="contact" class="lp-footer">
  <div class="lp-shell lp-footer-inner">
    <div class="lp-footer-cta">
      <h2 class="lp-h2 lp-h2--footer" data-lines data-stagger="100"><span>Everything you need.</span><span class="lp-accent-text">All in one place.</span></h2>
      <div class="lp-cta-row">
        <?= $pill('Sign In', 'light', ['href' => 'auth/login.php'], 'up') ?>
        <?= $pill('Contact the shop', 'ghost', ['data-contact' => '']) ?>
      </div>
    </div>
    <div class="lp-footer-cols">
      <div class="lp-footer-brand">
        <div class="lp-brand lp-brand--footer">
          <span class="lp-brand-logos">
            <img src="<?= brand_logo('tg') ?>" alt="TG Customworks" class="lp-logo-tg"/>
            <span class="lp-logo-sep" aria-hidden="true"></span>
            <img src="<?= brand_logo('basic') ?>" alt="Basic Car Insurance" class="lp-logo-basic"/>
          </span>
          <span class="lp-brand-text">
            <span class="lp-brand-name">TG<b>-BASICS</b></span>
            <span class="lp-brand-tag">Management System</span>
          </span>
        </div>
        <p>Brokerage and Auto Shop Integrated Central System. Built exclusively for TG Customworks and Basic Car Insurance.</p>
      </div>
      <div>
        <h4>Location</h4>
        <ul>
          <li><?= $e($company['address']) ?></li>
          <li>Philippines</li>
          <li>Gerald Peterson V. Carpio, Prop.</li>
          <li>Operating since <?= LANDING_SINCE ?></li>
        </ul>
      </div>
      <div>
        <h4>Contact Us</h4>
        <ul>
          <li><a class="lp-link" href="tel:<?= $e($phone_digits) ?>"><span><?= $e($company['contact']) ?></span></a></li>
          <li><a class="lp-link lp-break" href="mailto:<?= $e($company['email']) ?>"><span><?= $e($company['email']) ?></span></a></li>
          <?php foreach ($socials as [$label, $url]): ?>
          <li><a class="lp-link" href="<?= $e($url) ?>" target="_blank" rel="noopener noreferrer"><span><?= $e($label) ?> on Facebook</span></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div>
        <h4>Legal</h4>
        <ul>
          <li><a href="#" class="lp-link footer-legal-link" data-tab="privacy"><span>Privacy Notice</span></a></li>
          <li><a href="#" class="lp-link footer-legal-link" data-tab="terms"><span>Terms &amp; Conditions</span></a></li>
          <li><a href="#" class="lp-link footer-legal-link" data-tab="disclaimer"><span>Disclaimer</span></a></li>
        </ul>
      </div>
    </div>
    <div class="lp-footer-legal">
      <p>&copy; <?= date('Y') ?> TG Customworks &amp; Basic Car Insurance. All rights reserved. &middot; Internal use only.</p>
      <p>TG-BASICS v<?= APP_VERSION ?> &mdash; Capstone Project, STI College Sta. Maria</p>
    </div>
  </div>
  <div class="lp-watermark lp-footer-mark" aria-hidden="true">TG-BASICS</div>
</footer>

<!-- MENU OVERLAY -->
<div class="lp-menu" id="lp-menu" role="dialog" aria-modal="true" aria-label="Menu" hidden>
  <div class="lp-shell lp-menu-top">
    <span class="lp-brand-name">TG<b>-BASICS</b></span>
    <button type="button" class="lp-menu-close" data-close><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4l16 16M20 4 4 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>Close</button>
  </div>
  <nav class="lp-shell lp-menu-nav" aria-label="Sections">
    <ul>
      <?php foreach ($nav as $i => [$id, $label]): ?>
      <li style="--i:<?= $i ?>"><a href="#<?= $id ?>" <?= $id === 'contact' ? 'data-contact' : 'data-scroll' ?>><small><?= sprintf('%02d', $i + 1) ?></small><span><?= $label ?></span></a></li>
      <?php endforeach; ?>
      <li style="--i:<?= count($nav) ?>"><a href="auth/login.php"><small><?= sprintf('%02d', count($nav) + 1) ?></small><span>Sign In</span></a></li>
    </ul>
  </nav>
  <div class="lp-shell lp-menu-bottom">
    <span>Pandi time &mdash; <span data-clock="time"></span></span>
    <button type="button" data-contact>Contact the shop &rarr;</button>
  </div>
</div>

<!-- CONTACT -->
<div class="lp-modal" id="lp-contact" role="dialog" aria-modal="true" aria-labelledby="lp-contact-title" hidden>
  <div class="lp-modal-panel">
    <button type="button" class="lp-modal-x" data-close aria-label="Close"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4l16 16M20 4 4 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></button>
    <div class="lp-eyebrow"><span class="lp-dot lp-dot--accent"></span>Contact the shop</div>
    <h2 id="lp-contact-title" class="lp-modal-title">Talk to us about your car.</h2>
    <p class="lp-modal-sub">Insurance, renewals, claims or repairs: call, email or message us. We are at <?= $e($company['address']) ?>.</p>
    <div class="lp-contact-list">
      <a href="tel:<?= $e($phone_digits) ?>" class="lp-contact-item"><span class="lp-contact-icon"><?= icon('phone', 18) ?></span><span><small>Call or text</small><b><?= $e($company['contact']) ?></b></span><span class="lp-contact-go"><?= $arrow('up') ?></span></a>
      <a href="mailto:<?= $e($company['email']) ?>" class="lp-contact-item"><span class="lp-contact-icon"><?= icon('envelope', 18) ?></span><span><small>Email</small><b class="lp-break"><?= $e($company['email']) ?></b></span><span class="lp-contact-go"><?= $arrow('up') ?></span></a>
      <?php foreach ($socials as [$label, $url]): ?>
      <a href="<?= $e($url) ?>" target="_blank" rel="noopener noreferrer" class="lp-contact-item"><span class="lp-contact-icon"><?= $fb ?></span><span><small>Facebook</small><b><?= $e($label) ?></b></span><span class="lp-contact-go"><?= $arrow('up') ?></span></a>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/landing_legal.php'; ?>

<script src="assets/js/index.js?v=<?= filemtime(__DIR__ . '/assets/js/index.js') ?>"></script>
</body>
</html>
