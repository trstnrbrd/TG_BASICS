<?php
// Printable Unit Inspection of one repair job: the car with the marked parts, every area's condition, the
// remarks and the two sign-off signatures. Same paper layout as the printed Billing Statement
// (modules/quotations/print_receipt.php): Print, or Download PDF built in the browser.
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . '/../../includes/data_access.php';
require_once '../../config/db.php';
require_once __DIR__ . '/../../includes/lookups.php';
require_once '../../config/validators.php';
require_once '../../config/settings.php';
require_once '../../config/uploads.php';
require_once __DIR__ . '/../../includes/statuses.php';
require_once __DIR__ . '/../../includes/repair_inspection.php';

require_role(['admin', 'super_admin', 'mechanic']);   // the same people who open View Repair Job

$job_id = san_int($_GET['id'] ?? 0, 1);
if (!$job_id) { header("Location: repair_list.php"); exit; }

$job = repair_job_find($conn, $job_id);
if (!$job) { header("Location: repair_list.php"); exit; }

$checklist  = repair_checklist($conn, $job_id);
$signatures = repair_job_signatures($conn, $job_id);
$layout     = repair_inspection_layout();
$cond       = repair_inspection_conditions($checklist);
$damaged    = count(array_filter($cond, fn($c) => $c !== 'none'));

$company        = company_info($conn);   // Settings > Company Info (config/settings.php)
$service_labels = service_type_labels($conn);
$svc_lbl        = $service_labels[$job['service_type']] ?? $job['service_type'];
$sb             = repair_status_badge($job['status']);
$vehicle        = trim($job['year_model'] . ' ' . $job['make'] . ' ' . $job['model']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>Vehicle Inspection <?= htmlspecialchars($job['job_number']) ?> | TG-BASICS</title>
<link rel="icon" type="image/png" href="<?= brand_logo('tg', '../../') ?>"/>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    font-family: 'Plus Jakarta Sans', 'Segoe UI', Arial, sans-serif;
    background: #f0ede8;
    color: #1A1814;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 2rem 1rem;
  }

  /* ── PRINT TOOLBAR ── */
  .print-bar {
    width: 100%; max-width: 760px;
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 1.25rem; flex-wrap: wrap; gap: 0.6rem;
  }
  .print-bar a { font-size: 0.82rem; color: #6B5E4C; text-decoration: none; display: flex; align-items: center; gap: 0.35rem; }
  .print-bar a:hover { color: #B8860B; }
  .print-bar .print-btn:first-of-type { margin-left: auto; }
  .print-btn {
    background: #1C1A17; color: #D4A017; border: none; border-radius: 10px;
    padding: 0.55rem 1.4rem; font-size: 0.82rem; font-weight: 700;
    cursor: pointer; display: flex; align-items: center; gap: 0.4rem;
    font-family: inherit; transition: opacity 0.15s;
  }
  .print-btn:hover { opacity: 0.85; }
  .pdf-btn { background: #D4A017; color: #1A1814; }
  .pdf-btn:disabled { opacity: 0.6; cursor: wait; }

  /* ── PAPER ── */
  .sheet {
    width: 100%; max-width: 760px;
    background: #fff;
    border: 1px solid #E2D9CC;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 4px 24px rgba(0,0,0,0.08);
  }
  .sheet.pdf-mode { box-shadow: none; border-radius: 0; }

  /* Header */
  .sheet-head {
    background: #1C1A17;
    padding: 1.5rem 2rem;
    display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;
  }
  .sheet-brand { display: flex; align-items: center; gap: 1rem; }
  .sheet-brand-name { font-size: 1.15rem; font-weight: 800; color: #fff; }
  .sheet-brand-name span { color: #D4A017; }
  .sheet-brand-addr { font-size: 0.68rem; color: rgba(200,192,176,0.6); margin-top: 0.2rem; }
  .sheet-title { text-align: right; }
  .sheet-title .label { font-size: 0.65rem; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; color: #D4A017; }
  .sheet-title .num { font-size: 1.2rem; font-weight: 800; color: #fff; font-family: monospace; margin-top: 0.15rem; }
  .sheet-title .date { font-size: 0.72rem; color: rgba(200,192,176,0.6); margin-top: 0.15rem; }
  .gold-bar { height: 3px; background: linear-gradient(90deg,#D4A017,#B8860B,#E2D9CC); }

  /* Info grid */
  .info-section { padding: 1.25rem 2rem; border-bottom: 1px solid #F0EDE8; }
  .info-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; }
  .info-block label { font-size: 0.62rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #9C9286; display: block; margin-bottom: 0.2rem; }
  .info-block .val { font-size: 0.82rem; font-weight: 700; color: #1A1814; }
  .info-block .val-muted { font-size: 0.74rem; color: #6B5E4C; margin-top: 0.1rem; }

  /* Section titles */
  .sec { padding: 1.1rem 2rem 0; }
  .sec-title {
    display: flex; align-items: center; justify-content: space-between; gap: 0.75rem;
    font-size: 0.68rem; font-weight: 800; text-transform: uppercase; letter-spacing: 1.2px; color: #1A1814;
    padding-bottom: 0.5rem; border-bottom: 2px solid #E2D9CC; margin-bottom: 0.85rem;
  }
  .sec-title .sum { font-size: 0.68rem; font-weight: 700; letter-spacing: 0.3px; text-transform: none; padding: 0.15rem 0.6rem; border-radius: 99px; }
  .sum.ok  { color: #2E7D52; background: #EDF7F1; }
  .sum.bad { color: #C0392B; background: #FBEDEB; }

  /* Car + table */
  .insp { display: grid; grid-template-columns: 190px 1fr; gap: 1.25rem; align-items: start; }
  .insp-car { border: 1px solid #ECE6DC; border-radius: 12px; padding: 0.5rem 0.35rem 0.6rem; background: #FBFAF7; }
  .insp-car svg { display: block; width: 100%; height: auto; overflow: visible; }
  .legend { display: flex; flex-wrap: wrap; justify-content: center; gap: 0.25rem 0.6rem; margin-top: 0.4rem; }
  .legend span { display: inline-flex; align-items: center; gap: 0.3rem; font-size: 0.6rem; color: #6B5E4C; }
  .legend i { width: 0.65rem; height: 0.65rem; border-radius: 3px; border: 1.3px solid #8A8072; background: #fff; display: inline-block; }
  .legend i.minor { background: #F6DC92; border-color: #B8860B; }
  .legend i.major { background: #EFA79C; border-color: #C0392B; }

  .areas { width: 100%; border-collapse: collapse; font-size: 0.74rem; }
  .areas th {
    font-size: 0.58rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.7px; color: #9C9286;
    padding: 0.3rem 0.4rem; text-align: left; border-bottom: 1.5px solid #E2D9CC;
  }
  .areas th.c { text-align: center; width: 58px; }
  .areas td { padding: 0.3rem 0.4rem; border-bottom: 1px solid #F4F1EC; vertical-align: middle; }
  .areas td.c { text-align: center; }
  .areas tr.grp td {
    font-size: 0.58rem; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; color: #6B5E4C;
    background: #FAF8F4; padding: 0.28rem 0.4rem; border-bottom: 1px solid #ECE6DC;
  }
  .areas .n {
    display: inline-flex; align-items: center; justify-content: center;
    width: 1.15rem; height: 1.15rem; border-radius: 50%; font-size: 0.6rem; font-weight: 700;
    border: 1px solid #8A8072; color: #4A4540; background: #fff;
  }
  .areas tr.minor .n { background: #B8860B; border-color: #B8860B; color: #fff; }
  .areas tr.major .n { background: #C0392B; border-color: #C0392B; color: #fff; }
  .areas tr.minor .name { color: #8A6508; font-weight: 700; }
  .areas tr.major .name { color: #C0392B; font-weight: 700; }
  .box {
    display: inline-flex; align-items: center; justify-content: center;
    width: 0.85rem; height: 0.85rem; border: 1.3px solid #B5AC9E; border-radius: 3px;
    font-size: 0.62rem; font-weight: 800; line-height: 1; color: #fff;
  }
  .box.on.none  { background: #6B7280; border-color: #6B7280; }
  .box.on.minor { background: #B8860B; border-color: #B8860B; }
  .box.on.major { background: #C0392B; border-color: #C0392B; }
  .areas .note { font-size: 0.68rem; color: #5C5648; }

  /* Remarks */
  .remarks { font-size: 0.78rem; color: #3D3830; line-height: 1.6; white-space: pre-wrap; border: 1px solid #ECE6DC; border-radius: 10px; padding: 0.6rem 0.85rem; min-height: 2.6rem; }
  .remarks.empty { color: #9C9286; }

  /* Sign-off */
  .sign { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; padding-bottom: 1.25rem; }
  .sign-box .role { font-size: 0.62rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #9C9286; }
  .sign-box .ink { height: 64px; display: flex; align-items: flex-end; justify-content: center; border-bottom: 1.5px solid #1A1814; margin-top: 0.3rem; }
  .sign-box .ink img { max-height: 60px; max-width: 100%; object-fit: contain; }
  .sign-box .who { font-size: 0.8rem; font-weight: 700; color: #1A1814; margin-top: 0.3rem; min-height: 1.1rem; }
  .sign-box .when { font-size: 0.66rem; color: #6B5E4C; }
  .sign-box .attest { font-size: 0.62rem; color: #9C9286; margin-top: 0.2rem; line-height: 1.45; }

  /* Footer */
  .sheet-foot {
    background: #FAFAF8; border-top: 1px solid #E2D9CC;
    padding: 1rem 2rem;
    display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem;
  }
  .sheet-foot .note { font-size: 0.7rem; color: #9C9286; line-height: 1.6; }
  .sheet-foot .stamp {
    font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px;
    border: 1.5px solid; border-radius: 6px; padding: 0.25rem 0.65rem;
  }

  /* ── PRINT ── */
  @media print {
    body { background: #fff; padding: 1cm; margin: 0; }
    .print-bar { display: none; }
    .sheet { box-shadow: none; border: none; border-radius: 0; max-width: 100%; }
    .sheet-head, .gold-bar, .box.on, .areas tr.grp td, .sum, .n { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .areas tr, .sign { break-inside: avoid; }
    @page { margin: 0; size: A4 portrait; }
    /* tighter on paper, so a full checklist with both signatures fits one A4 page */
    .sheet-head { padding: 1rem 1.5rem; }
    .info-section { padding: 0.85rem 1.5rem; }
    .sec { padding: 0.75rem 1.5rem 0; }
    .sec-title { margin-bottom: 0.6rem; padding-bottom: 0.4rem; }
    .insp { grid-template-columns: 170px 1fr; }
    .areas td { padding: 0.17rem 0.4rem; }
    .areas tr.grp td { padding: 0.2rem 0.4rem; }
    .remarks { min-height: 0; padding: 0.45rem 0.75rem; }
    .sign { padding-bottom: 0.85rem; }
    .sign-box .ink { height: 48px; }
    .sign-box .ink img { max-height: 46px; }
    .sheet-foot { padding: 0.7rem 1.5rem; }
  }

  /* ── MOBILE ── */
  @media (max-width: 640px) {
    .sheet-head { flex-direction: column; align-items: flex-start; }
    .sheet-title { text-align: left; }
    .info-grid { grid-template-columns: 1fr 1fr; }
    .info-section, .sec, .sheet-foot { padding-left: 1rem; padding-right: 1rem; }
    .insp { grid-template-columns: 1fr; }
    .insp-car { max-width: 220px; margin: 0 auto; width: 100%; }
    .areas th.c { width: 40px; }
    .sign { grid-template-columns: 1fr; }
  }
</style>
</head>
<body>

<div class="print-bar">
  <a href="view_repair.php?id=<?= $job_id ?>">
    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
    Back
  </a>
  <button class="print-btn" onclick="window.print()">
    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0110.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0l.229 2.523a1.125 1.125 0 01-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0021 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 00-1.913-.247M6.34 18H5.25A2.25 2.25 0 013 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 011.913-.247m10.5 0a48.536 48.536 0 00-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5zm-3 0h.008v.008H15V10.5z"/></svg>
    Print
  </button>
  <button class="print-btn pdf-btn" id="pdf-btn" type="button" onclick="downloadInspectionPdf()">
    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
    <span>Download PDF</span>
  </button>
</div>

<div class="sheet">

  <!-- HEADER -->
  <div class="sheet-head">
    <div class="sheet-brand">
      <div style="display:flex;align-items:center;gap:0.5rem;flex-shrink:0;">
        <img src="<?= brand_logo('tg', '../../') ?>" alt="TG Customworks" style="width:38px;height:38px;border-radius:8px;object-fit:cover;border:1.5px solid rgba(212,160,23,0.4);"/>
        <div style="width:1px;height:28px;background:rgba(212,160,23,0.25);"></div>
        <img src="<?= brand_logo('basic', '../../') ?>" alt="Basic Car Insurance" style="height:34px;width:auto;object-fit:contain;"/>
      </div>
      <div>
        <div class="sheet-brand-name">TG<span>-BASICS</span></div>
        <div class="sheet-brand-addr"><?= htmlspecialchars($company['address']) ?></div>
      </div>
    </div>
    <div class="sheet-title">
      <div class="label">Vehicle Inspection</div>
      <div class="num"><?= htmlspecialchars($job['job_number']) ?></div>
      <div class="date"><?= date('F d, Y', strtotime($job['repair_date'])) ?></div>
    </div>
  </div>
  <div class="gold-bar"></div>

  <!-- CLIENT + VEHICLE + JOB -->
  <div class="info-section">
    <div class="info-grid">
      <div class="info-block">
        <label>Client</label>
        <div class="val"><?= htmlspecialchars($job['full_name']) ?></div>
        <div class="val-muted"><?= htmlspecialchars($job['contact_number'] ?: '—') ?></div>
      </div>
      <div class="info-block">
        <label>Vehicle</label>
        <div class="val"><?= htmlspecialchars($vehicle) ?></div>
        <div class="val-muted">Plate: <?= htmlspecialchars($job['plate_number']) ?><?= $job['color'] ? ' · ' . htmlspecialchars($job['color']) : '' ?></div>
      </div>
      <div class="info-block">
        <label>Service</label>
        <div class="val"><?= htmlspecialchars($svc_lbl) ?></div>
        <div class="val-muted">Status: <?= htmlspecialchars($sb['label']) ?></div>
      </div>
      <div class="info-block">
        <label>Dates</label>
        <div class="val">In: <?= date('M d, Y', strtotime($job['repair_date'])) ?></div>
        <div class="val-muted">Est. release: <?= $job['release_date'] ? date('M d, Y', strtotime($job['release_date'])) : '—' ?></div>
      </div>
    </div>
  </div>

  <!-- UNIT INSPECTION -->
  <div class="sec">
    <div class="sec-title">
      <span>Unit Inspection Checklist</span>
      <?php if ($damaged): ?>
      <span class="sum bad"><?= $damaged ?> <?= $damaged === 1 ? 'area' : 'areas' ?> damaged</span>
      <?php else: ?>
      <span class="sum ok">No damage recorded</span>
      <?php endif; ?>
    </div>
    <div class="insp">
      <div class="insp-car">
        <?= repair_car_svg($cond) ?>
        <div class="legend">
          <span><i></i><?= htmlspecialchars(REPAIR_DAMAGE_LEVELS['none']['label']) ?></span>
          <span><i class="minor"></i><?= htmlspecialchars(REPAIR_DAMAGE_LEVELS['minor']['label']) ?></span>
          <span><i class="major"></i><?= htmlspecialchars(REPAIR_DAMAGE_LEVELS['major']['label']) ?></span>
        </div>
      </div>

      <table class="areas">
        <thead>
          <tr>
            <th style="width:26px;">#</th>
            <th>Area</th>
            <?php foreach (REPAIR_DAMAGE_LEVELS as $d): ?>
            <th class="c"><?= htmlspecialchars($d['label']) ?></th>
            <?php endforeach; ?>
            <th>Notes</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($layout['sectors'] as $sector): ?>
          <tr class="grp"><td colspan="<?= 3 + count(REPAIR_DAMAGE_LEVELS) ?>"><?= htmlspecialchars($sector['label']) ?></td></tr>
          <?php foreach ($sector['areas'] as $key): $c = $cond[$key] ?? 'none'; ?>
          <tr class="<?= $c ?>" data-key="<?= $key ?>">
            <td><span class="n"><?= $layout['num'][$key] ?></span></td>
            <td class="name"><?= htmlspecialchars(REPAIR_CHECKLIST_AREAS[$key]) ?></td>
            <?php foreach (REPAIR_DAMAGE_LEVELS as $lvl => $_): ?>
            <td class="c"><span class="box <?= $lvl ?><?= $lvl === $c ? ' on' : '' ?>"><?= $lvl === $c ? '&#10003;' : '' ?></span></td>
            <?php endforeach; ?>
            <td class="note"><?= htmlspecialchars(trim((string)($checklist[$key]['notes'] ?? ''))) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- REMARKS -->
  <div class="sec">
    <div class="sec-title"><span>Additional Damages / Remarks</span></div>
    <?php $remarks = trim((string)($job['additional_damages'] ?? '')); ?>
    <div class="remarks<?= $remarks === '' ? ' empty' : '' ?>"><?= $remarks !== '' ? htmlspecialchars($remarks) : 'None.' ?></div>
  </div>

  <!-- SIGN-OFF: the saved signatures, or blank lines to sign on paper -->
  <div class="sec">
    <div class="sec-title"><span>Inspection Sign-off</span></div>
    <div class="sign">
      <?php foreach ([
          'client' => ['Client', 'I confirm the condition of my vehicle as recorded above.'],
          'staff'  => ['Shop Representative', 'I inspected this vehicle and recorded its condition above.'],
      ] as $side => [$role_lbl, $attest]): $sig = $signatures[$side] ?? null; ?>
      <div class="sign-box" data-side="<?= $side ?>">
        <div class="role"><?= $role_lbl ?></div>
        <div class="ink">
          <?php if ($sig): ?><img src="<?= htmlspecialchars(upload_url('repair_jobs', $sig['file_name'], $job_id)) ?>" alt="<?= $role_lbl ?> signature"/><?php endif; ?>
        </div>
        <div class="who"><?= $sig ? htmlspecialchars($sig['signer_name']) : ($side === 'client' ? htmlspecialchars($job['full_name']) : '') ?></div>
        <div class="when"><?= $sig ? 'Signed ' . date('M d, Y · g:i A', strtotime($sig['signed_at'])) : 'Signature over printed name / Date' ?></div>
        <div class="attest"><?= $attest ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- FOOTER -->
  <div class="sheet-foot">
    <div class="note">
      Vehicle condition recorded at intake by <strong><?= htmlspecialchars($company['name']) ?></strong>.<br>
      Generated on <?= date('F d, Y \a\t h:i A') ?> via TG-BASICS.
    </div>
    <div class="stamp" style="border-color:<?= htmlspecialchars($sb['color'] ?: '#D4A017') ?>;color:<?= htmlspecialchars($sb['color'] ?: '#D4A017') ?>;">
      <?= htmlspecialchars(strtoupper($sb['label'])) ?>
    </div>
  </div>

</div>

<script>
// A real PDF file (not the browser's print dialog), built in the browser from the sheet above.
// html2pdf.js is ~900 KB, so it is only fetched the first time the button is pressed.
const PDF_LIB = 'https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js';
function loadPdfLib() {
  if (window.html2pdf) return Promise.resolve();
  return new Promise((resolve, reject) => {
    const s = document.createElement('script');
    s.src = PDF_LIB;
    s.onload = resolve;
    s.onerror = () => reject(new Error('load'));
    document.head.appendChild(s);
  });
}
async function downloadInspectionPdf() {
  const btn = document.getElementById('pdf-btn');
  const label = btn.querySelector('span');
  btn.disabled = true;
  label.textContent = 'Preparing…';
  try {
    await loadPdfLib();
    const el = document.querySelector('.sheet');
    el.classList.add('pdf-mode');
    await html2pdf().set({
      margin: [8, 8, 8, 8],
      filename: <?= json_encode('Vehicle-Inspection-' . preg_replace('/[^A-Za-z0-9_-]/', '-', $job['job_number']) . '.pdf') ?>,
      image: { type: 'jpeg', quality: 0.96 },
      html2canvas: { scale: 2, useCORS: true, backgroundColor: '#ffffff' },
      jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
      pagebreak: { mode: ['avoid-all', 'css'] },
    }).from(el).save();
    el.classList.remove('pdf-mode');
  } catch (e) {
    alert('The PDF could not be created. Check your internet connection, or use Print instead.');
  }
  btn.disabled = false;
  label.textContent = 'Download PDF';
}
</script>
</body>
</html>
