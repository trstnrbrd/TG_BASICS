<?php
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/data_access.php';
require_once __DIR__ . '/../../includes/service_types.php';
require_once __DIR__ . '/../../config/settings.php';
require_once __DIR__ . '/../../includes/icons.php';

$token = isset($_GET['token']) ? trim($_GET['token']) : '';

// this page is opened with a secret QR code link, so keep it out of search engines
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Referrer-Policy: no-referrer');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

function pub_not_found(): never {
    http_response_code(404);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Profile Not Found — TG-BASICS</title>
<link rel="icon" type="image/png" href="<?= brand_logo('tg', '../../') ?>">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:"Plus Jakarta Sans","Segoe UI",sans-serif;background:linear-gradient(160deg,#1c1a17 0%,#252118 50%,#1a1710 100%);min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:2rem;}
.err-wrap{display:flex;flex-direction:column;align-items:center;text-align:center;gap:1.25rem;max-width:400px;}
.err-logos{display:flex;align-items:center;gap:0.75rem;margin-bottom:0.5rem;}
.err-logos img{height:48px;width:auto;object-fit:contain;}
.err-icon{width:72px;height:72px;border-radius:50%;background:rgba(192,57,43,0.12);border:2px solid rgba(192,57,43,0.25);display:flex;align-items:center;justify-content:center;color:#e74c3c;}
.err-code{font-size:0.6rem;font-weight:800;letter-spacing:2.5px;text-transform:uppercase;color:rgba(212,160,23,0.5);}
.err-title{font-size:1.4rem;font-weight:800;color:#fff;letter-spacing:-0.3px;}
.err-msg{font-size:0.78rem;color:rgba(200,192,176,0.5);line-height:1.6;}
.err-divider{width:40px;height:2px;background:linear-gradient(90deg,#d4a017,#b8860b);border-radius:2px;}
.err-hint{font-size:0.68rem;color:rgba(200,192,176,0.35);}
</style>
</head>
<body>
<div class="err-wrap">
  <div class="err-logos">
    <img src="<?= brand_logo('tg', '../../') ?>" alt="TG Customworks">
    <img src="<?= brand_logo('basic', '../../') ?>" alt="Basic Car Insurance">
  </div>
  <div class="err-code">404 — Not Found</div>
  <div class="err-icon">
    <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
  </div>
  <div class="err-title">Profile Not Found</div>
  <div class="err-divider"></div>
  <div class="err-msg">This QR code is no longer valid or the client profile has been removed. The link may have expired or never existed.</div>
  <div class="err-hint">If you believe this is an error, please contact our office directly.</div>
</div>
</body>
</html>
    <?php
    exit;
}

// ── Privacy: only show part of the client's contact details, not the full value ──

/** 09485283482 -> 0948•••••82 */
function pub_mask_phone(string $phone): string
{
    $d = preg_replace('/\D/', '', $phone);
    if (strlen($d) < 7) return str_repeat('•', 4);
    return substr($d, 0, 4) . str_repeat('•', strlen($d) - 6) . substr($d, -2);
}

/** kerby@gmail.com -> ke•••@gmail.com */
function pub_mask_email(string $email): string
{
    $at = strrpos($email, '@');
    if ($at === false || $at === 0) return '•••';
    $name = substr($email, 0, $at);
    return mb_substr($name, 0, min(2, max(1, mb_strlen($name) - 1))) . '•••' . substr($email, $at);
}

/** "Pulong Buhangin, Sta.Maria, Bulacan, 3022" -> "•••, Sta.Maria, Bulacan" */
function pub_mask_address(string $address): string
{
    $parts = array_values(array_filter(array_map('trim', explode(',', $address)), fn($p) => $p !== '' && !ctype_digit($p)));
    if (count($parts) < 2) return '•••';
    $keep = count($parts) >= 3 ? 2 : 1;   // town and province only
    return '•••, ' . implode(', ', array_slice($parts, -$keep));
}

if ($token === '' || strlen($token) !== 64 || !ctype_xdigit($token)) {
    pub_not_found();
}

$client = public_client_by_token($conn, $token);

if (!$client) {
    pub_not_found();
}

$client_id = (int)$client['client_id'];

// Vehicles
$vehicles = client_vehicles($conn, $client_id);

// Active policies
$policies = public_client_policies($conn, $client_id);

// Repair jobs
$repair_jobs = public_client_repair_jobs($conn, $client_id, 10);

// Progress photos the shop chose to show ("Show to client"), and only while their job is still open. The files are
// sent by photo.php, which checks all of that again; uploads/repair_jobs itself stays closed to direct links.
require_once __DIR__ . '/../../config/access.php';
$photos_by_job = [];
foreach (public_client_shown_photos($conn, $client_id) as $ph) {
    if (repair_job_is_open($ph['status'])) $photos_by_job[(int)$ph['job_id']][] = $ph;
}

// Live updates: a fingerprint of everything this page shows. When the shop saves something (a Pusher signal, or
// the 30-second poll when Pusher is unreachable), the page asks ?state=1 and reloads only if ITS fingerprint
// changed, so one client's page never reloads because of another client's repair. Signals carry no client data.
$live_state = md5(json_encode([$client['full_name'], $client['contact_number'], $client['email'], $client['address'],
    $vehicles, $policies, $repair_jobs, $photos_by_job]));
if (isset($_GET['state'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['state' => $live_state]);
    exit;
}
require_once __DIR__ . '/../../includes/realtime.php';
$live_cfg = realtime_client_config();   // public values only (key, cluster); empty = polling only

$svc_labels = service_type_labels($conn);   // service_types table (includes/service_types.php)

$company_name    = getSetting($conn, 'company_name',    'TG Customworks & Basic Car Insurance');
$company_address = getSetting($conn, 'company_address', '49 Villa Tierra St., San Roque, Pandi, Bulacan');
$company_phone   = getSetting($conn, 'company_phone',   '09171453448');
$company_email   = getSetting($conn, 'company_email',   'tgcustomworksbulacan@gmail.com');
$fb_tg           = getSetting($conn, 'fb_tg',           'https://www.facebook.com/TGCustomworks');
$fb_basiccar     = getSetting($conn, 'fb_basiccar',     'https://www.facebook.com/BasicCarInsurance');
$words    = array_filter(explode(' ', $client['full_name']));
$initials = strtoupper(substr(implode('', array_map(fn($w) => $w[0], $words)), 0, 2));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<meta name="robots" content="noindex, nofollow, noarchive">
<meta name="referrer" content="no-referrer">
<title><?= htmlspecialchars($client['full_name']) ?> — <?= htmlspecialchars($company_name) ?></title>
<link rel="icon" type="image/png" href="<?= brand_logo('tg', '../../') ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Big+Shoulders+Text:wght@800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../../assets/css/public/client.css?v=<?= filemtime(__DIR__ . '/../../assets/css/public/client.css') ?>"/>
<style>
@keyframes loaderSpin { to { transform: rotate(360deg); } }
@keyframes loaderPulse { 0%,100% { opacity:1; transform:scale(1); } 50% { opacity:0.7; transform:scale(0.95); } }
</style>
</head>
<body>

<!-- PAGE LOADER -->
<div id="pub-loader" style="position:fixed;inset:0;z-index:9999;background:linear-gradient(160deg,#1c1a17 0%,#252118 50%,#1a1710 100%);display:flex;align-items:center;justify-content:center;">
  <div style="display:flex;flex-direction:column;align-items:center;gap:1.25rem;">
    <img src="<?= brand_logo('tg', '../../') ?>" alt="TG" style="width:64px;height:64px;object-fit:contain;animation:loaderPulse 1.2s ease-in-out infinite;">
    <div style="width:28px;height:28px;border:2.5px solid rgba(212,160,23,0.2);border-top-color:#d4a017;border-radius:50%;animation:loaderSpin 0.75s linear infinite;"></div>
  </div>
</div>

<!-- TOP NAV -->
<nav class="pub-nav">
  <div class="pub-nav-logos">
    <img src="<?= brand_logo('tg', '../../') ?>" alt="TG Customworks" class="pub-nav-img">
    <img src="<?= brand_logo('basic', '../../') ?>" alt="Basic Car Insurance" class="pub-nav-img">
  </div>
  <div class="pub-nav-brand">
    <div class="pub-nav-brand-name">TG<span>-BASICS</span></div>
    <div class="pub-nav-brand-sub"><?= htmlspecialchars($company_name) ?></div>
  </div>
</nav>
<div class="pub-gold-bar"></div>

<div class="pub-wrap">
  <div class="pub-container">

    <div class="pub-card">

      <!-- ID TOP: label -->
      <div class="pub-id-top">
        <div class="pub-id-label">CLIENT DIGITAL ID</div>
      </div>

      <!-- AVATAR + NAME -->
      <div class="pub-banner">
        <div class="pub-avatar"><?= htmlspecialchars($initials) ?></div>
        <div class="pub-banner-name"><?= htmlspecialchars($client['full_name']) ?></div>
        <div class="pub-verified-badge">
          <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
          Verified Client
        </div>
      </div>

      <!-- ID FIELDS TOGGLE -->
      <button class="pub-id-toggle" id="idFieldsToggle" onclick="toggleIdFields()" aria-expanded="false">
        <span>Personal Information</span>
        <svg class="pub-id-toggle-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
      </button>

      <!-- ID FIELDS -->
      <div class="pub-id-fields" id="idFields" hidden>
        <div class="pub-id-field">
          <div class="pub-id-field-label">Contact Number</div>
          <div class="pub-id-field-value"><?= htmlspecialchars(!empty($client['contact_number']) ? pub_mask_phone($client['contact_number']) : '—') ?></div>
        </div>
        <?php if (!empty($client['email'])): ?>
        <div class="pub-id-field">
          <div class="pub-id-field-label">Email Address</div>
          <div class="pub-id-field-value"><?= htmlspecialchars(pub_mask_email($client['email'])) ?></div>
        </div>
        <?php endif; ?>
        <?php if (!empty($client['address'])): ?>
        <div class="pub-id-field pub-id-field-full">
          <div class="pub-id-field-label">Address</div>
          <div class="pub-id-field-value"><?= htmlspecialchars(pub_mask_address($client['address'])) ?></div>
        </div>
        <?php endif; ?>
        <div class="pub-id-field pub-id-field-full" style="padding-top:0.55rem;padding-bottom:0.55rem;">
          <div style="font-size:0.68rem;color:#9C9286;display:flex;align-items:center;gap:0.35rem;">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            Partly hidden to protect your privacy. To update your details, please contact our office.
          </div>
        </div>
      </div>

      <!-- 2-COL GRID: Vehicles + Policies -->
      <div class="pub-grid">

        <!-- VEHICLES -->
        <div class="pub-section pub-grid-col">
          <div class="pub-section-hdr">
            <div class="pub-section-icon">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
            </div>
            <span class="pub-section-title">Vehicles</span>
            <span class="pub-section-count"><?= count($vehicles) ?></span>
          </div>
          <div class="pub-section-body">
            <?php if ($vehicles): ?>
            <div class="veh-list">
              <?php foreach ($vehicles as $v): ?>
              <div class="veh-chip">
                <span class="veh-plate"><?= htmlspecialchars($v['plate_number']) ?></span>
                <div>
                  <div class="veh-name"><?= htmlspecialchars($v['make'] . ' ' . $v['model']) ?></div>
                  <div class="veh-meta">
                    <?= htmlspecialchars($v['year_model'] ?: 'Unknown year') ?>
                    <?php if ($v['color']): ?> &middot; <?= htmlspecialchars($v['color']) ?><?php endif; ?>
                  </div>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="pub-empty">None on record.</div>
            <?php endif; ?>
          </div>
        </div>

        <!-- POLICIES -->
        <div class="pub-section pub-grid-col pub-grid-col-right">
          <div class="pub-section-hdr">
            <div class="pub-section-icon">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            </div>
            <span class="pub-section-title">Policies</span>
            <span class="pub-section-count"><?= count($policies) ?></span>
          </div>
          <div class="pub-section-body">
            <?php if ($policies): ?>
            <div class="pol-list">
              <?php foreach ($policies as $p):
                $days = (int)$p['days_left'];
                if ($p['policy_end'] < date('Y-m-d')) {
                    $pbadge = '<span class="badge badge-gray">Expired</span>';
                } elseif ($days <= 7) {
                    $pbadge = '<span class="badge badge-red">Urgent</span>';
                } elseif ($days <= 30) {
                    $pbadge = '<span class="badge badge-yellow">Expiring</span>';
                } else {
                    $pbadge = '<span class="badge badge-green">Active</span>';
                }
              ?>
              <div class="pol-item">
                <div class="pol-item-top">
                  <span class="pol-num"><?= htmlspecialchars($p['policy_number']) ?></span>
                  <?= $pbadge ?>
                </div>
                <span class="veh-plate" style="font-size:0.68rem;padding:0.12rem 0.45rem;letter-spacing:2px;"><?= htmlspecialchars($p['plate_number']) ?></span>
                <div class="pol-item-meta"><?= htmlspecialchars($p['coverage_type']) ?> &middot; Exp <?= date('M d, Y', strtotime($p['policy_end'])) ?></div>
              </div>
              <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="pub-empty">None on record.</div>
            <?php endif; ?>
          </div>
        </div>

      </div><!-- /.pub-grid -->

      <!-- REPAIR JOBS (full width) -->
      <div class="pub-section">
        <div class="pub-section-hdr">
          <div class="pub-section-icon">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
          </div>
          <span class="pub-section-title">Repair Jobs</span>
          <span class="pub-section-count">Recent history</span>
        </div>
        <div class="pub-section-body">
          <?php if ($repair_jobs): ?>
          <div class="job-list">
            <?php foreach ($repair_jobs as $rj):
              $rst    = repair_status_badge($rj['status']);   // statuses table
              $pct    = $rst['progress'];
              $slabel = $rst['public_label'];
              $scolor = $rst['public_color'];
              $svclbl = $svc_labels[$rj['service_type']] ?? $rj['service_type'];
            ?>
            <div class="job-item">
              <div class="job-top">
                <div>
                  <div class="job-num"><?= htmlspecialchars($rj['job_number']) ?></div>
                  <div class="job-veh">
                    <span class="veh-plate" style="font-size:0.68rem;padding:0.12rem 0.45rem;letter-spacing:2px;"><?= htmlspecialchars($rj['plate_number']) ?></span>
                    <?= htmlspecialchars($rj['make'] . ' ' . $rj['model']) ?>
                  </div>
                  <div class="job-svc"><?= htmlspecialchars($svclbl) ?></div>
                </div>
              </div>
              <div class="job-date">
                Repair: <?= date('M d, Y', strtotime($rj['repair_date'])) ?>
                <?php if ($rj['release_date']): ?>
                &nbsp;&middot;&nbsp;Est. Release: <?= date('M d, Y', strtotime($rj['release_date'])) ?>
                <?php endif; ?>
              </div>
              <div class="prog-row">
                <div class="prog-track">
                  <div class="prog-bar" style="width:<?= $pct ?>%;background:<?= $scolor ?>;"></div>
                </div>
                <span class="prog-label" style="color:<?= $scolor ?>;"><?= htmlspecialchars($slabel) ?></span>
                <span class="prog-pct"><?= $pct ?>%</span>
              </div>
              <?php if (!empty($photos_by_job[(int)$rj['job_id']])): ?>
              <div class="job-photos">
                <div class="job-photos-label">Progress photos from the shop</div>
                <div class="job-photos-grid">
                  <?php foreach ($photos_by_job[(int)$rj['job_id']] as $ph):
                    $ph_url = 'photo.php?token=' . urlencode($token) . '&id=' . (int)$ph['image_id'];
                    $ph_cap = (string)($ph['caption'] ?? ''); ?>
                  <a class="job-photo" href="<?= htmlspecialchars($ph_url) ?>" target="_blank" rel="noopener">
                    <img src="<?= htmlspecialchars($ph_url) ?>" alt="<?= htmlspecialchars($ph_cap !== '' ? $ph_cap : 'Repair progress photo') ?>" loading="lazy"/>
                    <span class="job-photo-meta"><?php if ($ph_cap !== ''): ?><strong><?= htmlspecialchars($ph_cap) ?></strong><?php endif; ?><?= date('M d, Y', strtotime($ph['uploaded_at'])) ?></span>
                  </a>
                  <?php endforeach; ?>
                </div>
              </div>
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
          </div>
          <?php else: ?>
          <div class="pub-empty">No repair jobs on record.</div>
          <?php endif; ?>
        </div>
      </div>

    </div><!-- /.pub-card -->

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
        <a href="tel:<?= htmlspecialchars($company_phone) ?>" class="pub-footer-link">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 13a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 2.18h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.71 2.81a2 2 0 0 1-.45 2.11L7.91 9.91a16 16 0 0 0 6.18 6.18l.96-.96a2 2 0 0 1 2.11-.45c.91.35 1.85.58 2.81.71A2 2 0 0 1 22 16.92z"/></svg>
          <?= htmlspecialchars($company_phone) ?>
        </a>
        <a href="mailto:<?= htmlspecialchars($company_email) ?>" class="pub-footer-link">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
          <?= htmlspecialchars($company_email) ?>
        </a>
      </div>
      <div class="pub-footer-copy">
        &copy; <?= date('Y') ?> <strong><?= htmlspecialchars($company_name) ?></strong> &middot; Read-only client profile
        <br/><a href="privacy_notice.php" style="color:rgba(200,192,176,0.5);">Privacy Notice</a> &middot; <a href="terms.php" style="color:rgba(200,192,176,0.5);">Terms of Use</a>
      </div>
    </div>

  </div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/ScrollTrigger.min.js"></script>
<script>
function toggleIdFields() {
  var fields = document.getElementById('idFields');
  var btn = document.getElementById('idFieldsToggle');
  var open = fields.hasAttribute('hidden');
  if (open) {
    fields.removeAttribute('hidden');
    btn.setAttribute('aria-expanded', 'true');
    btn.classList.add('pub-id-toggle--open');
    gsap.from(fields.querySelectorAll('.pub-id-field'), {
      y: 12, opacity: 0, stagger: 0.06, duration: 0.3, ease: 'power2.out'
    });
  } else {
    fields.setAttribute('hidden', '');
    btn.setAttribute('aria-expanded', 'false');
    btn.classList.remove('pub-id-toggle--open');
  }
}

gsap.registerPlugin(ScrollTrigger);

window.addEventListener('DOMContentLoaded', function () {
  var loader = document.getElementById('pub-loader');

  // A reload made by a live update: no intro animation, back to where the client was
  var liveY = null;
  try { liveY = sessionStorage.getItem('pub_live_reload'); sessionStorage.removeItem('pub_live_reload'); } catch (e) {}
  if (liveY !== null) {
    loader.style.display = 'none';
    gsap.set('.pub-nav, .pub-gold-bar, .pub-card, .pub-footer', { autoAlpha: 1, y: 0 });
    window.scrollTo(0, parseInt(liveY, 10) || 0);
    return;
  }

  // Hide page content — target specific elements, NOT their parents
  gsap.set('.pub-nav, .pub-gold-bar, .pub-card, .pub-footer', { autoAlpha: 0 });

  // Fade out loader, then animate content in
  gsap.to(loader, {
    autoAlpha: 0, duration: 0.4, delay: 0.65, ease: 'power2.out',
    onComplete: function () {
      loader.style.display = 'none';

      var tl = gsap.timeline({ defaults: { ease: 'power3.out' } });

      tl.to('.pub-nav',      { autoAlpha: 1, y: 0, duration: 0.35 })
        .to('.pub-gold-bar', { autoAlpha: 1, duration: 0.2 }, '-=0.1')
        .to('.pub-card',     { autoAlpha: 1, y: 0, duration: 0.5 }, '<0.1')
        .from('.pub-id-top', { y: -10, autoAlpha: 0, duration: 0.3 }, '-=0.3')
        .from('.pub-avatar', { scale: 0.5, autoAlpha: 0, duration: 0.5, ease: 'back.out(1.7)' }, '-=0.2')
        .from('.pub-banner-name',    { y: 14, autoAlpha: 0, duration: 0.35 }, '-=0.25')
        .from('.pub-verified-badge', { scale: 0.8, autoAlpha: 0, duration: 0.3, ease: 'back.out(1.5)' }, '-=0.1')
        .from('.pub-id-toggle',      { y: 10, autoAlpha: 0, duration: 0.3 }, '-=0.15')
        .from('.pub-grid-col',       { y: 24, autoAlpha: 0, stagger: 0.12, duration: 0.4 }, '-=0.1')
        .to('.pub-footer',           { autoAlpha: 1, duration: 0.3 }, '-=0.1');

      gsap.from('.pub-section:not(.pub-grid-col)', {
        scrollTrigger: { trigger: '.pub-section:not(.pub-grid-col)', start: 'top 88%' },
        y: 28, autoAlpha: 0, duration: 0.45, ease: 'power2.out'
      });
    }
  });
});
</script>
<?php if ($live_cfg): ?>
<script src="https://js.pusher.com/8.4.0/pusher.min.js"></script>
<?php endif; ?>
<script>
// Live updates on the client's page: when the shop saves something, check whether THIS page changed and reload if so
(function () {
  var cfg = <?= json_encode($live_cfg + ['pollMs' => 30000]) ?>;
  var mine = <?= json_encode($live_state) ?>;
  var url = <?= json_encode('client.php?token=' . $token . '&state=1') ?>;
  var busy = false, again = false, pollTimer = null;
  function check() {
    if (document.hidden) return;
    if (busy) { again = true; return; }
    busy = true;
    fetch(url, { cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) {
        if (j && j.state && j.state !== mine) {
          try { sessionStorage.setItem('pub_live_reload', String(window.scrollY)); } catch (e) {}
          location.reload();
        }
      })
      .catch(function () {})
      .then(function () { busy = false; if (again) { again = false; check(); } });
  }
  function polling(on) {
    if (on && !pollTimer) pollTimer = setInterval(check, cfg.pollMs);
    if (!on && pollTimer) { clearInterval(pollTimer); pollTimer = null; }
  }
  document.addEventListener('visibilitychange', function () { if (!document.hidden) check(); });
  window.TG_PUB_check = check;   // for tests
  if (!cfg.key || !window.Pusher) return polling(true);
  var pusher = new Pusher(cfg.key, { cluster: cfg.cluster });
  pusher.subscribe('tg-live').bind('changed', function (d) {
    if (!d || !d.topic || ['clients', 'repairs', 'policies', 'quotations'].indexOf(d.topic) !== -1) check();
  });
  pusher.connection.bind('state_change', function (s) { polling(s.current !== 'connected'); });
  window.TG_PUB_connected = function () { return pusher.connection.state === 'connected'; };
})();
</script>
</body>
</html>
