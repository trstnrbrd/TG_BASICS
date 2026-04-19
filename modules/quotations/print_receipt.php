<?php
require_once __DIR__ . "/../../config/session.php";
require_once '../../config/db.php';
require_once '../../config/validators.php';
require_once '../../config/settings.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header("Location: ../../auth/login.php");
    exit;
}

$qt_id = san_int($_GET['id'] ?? 0, 1);
if (!$qt_id) { header("Location: quotation_list.php"); exit; }

$stmt = $conn->prepare("
    SELECT q.quotation_number, q.total, q.discount, q.subtotal, q.notes AS qt_notes,
           j.job_number, j.service_type, j.repair_date,
           c.full_name, c.contact_number, c.email, c.address,
           v.plate_number, v.make, v.model, v.year_model, v.color,
           r.receipt_number, r.amount_paid, r.balance, r.payment_method,
           r.payment_status, r.issued_at, r.notes AS receipt_notes
    FROM quotations q
    INNER JOIN repair_jobs j ON q.job_id = j.job_id
    INNER JOIN clients     c ON j.client_id  = c.client_id
    INNER JOIN vehicles    v ON j.vehicle_id = v.vehicle_id
    INNER JOIN receipts    r ON r.quotation_id = q.quotation_id
    WHERE q.quotation_id = ?
");
$stmt->bind_param('i', $qt_id);
$stmt->execute();
$d = $stmt->get_result()->fetch_assoc();
if (!$d) { header("Location: quotation_list.php"); exit; }

$items_stmt = $conn->prepare("SELECT * FROM quotation_items WHERE quotation_id = ? ORDER BY sort_order ASC");
$items_stmt->bind_param('i', $qt_id);
$items_stmt->execute();
$items = $items_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$company_name    = getSetting($conn, 'company_name',    'TG Customworks & Basic Car Insurance');
$company_address = getSetting($conn, 'company_address', '49 Villa Tierra St., San Roque, Pandi, Bulacan');

$service_labels = [
    'repair_panel'   => 'Per Panel Repair',  'repair_full'    => 'Full Body Repair',
    'paint_panel'    => 'Per Panel Paint',   'paint_full'     => 'Full Body Paint',
    'washover_basic' => 'Basic Wash Over',   'washover_full'  => 'Fully Wash Over',
    'custom'         => 'Custom / Mixed',
];
$pay_methods = ['cash' => 'Cash', 'e_wallet' => 'E-Wallet', 'bank_transfer' => 'Bank Transfer'];
$pay_status_labels = ['unpaid' => 'Unpaid', 'partial' => 'Partial', 'paid' => 'Paid'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>Receipt <?= htmlspecialchars($d['receipt_number']) ?> | TG-BASICS</title>
<link rel="icon" type="image/png" href="../../assets/img/tg_logo.png"/>
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
    width: 100%; max-width: 720px;
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 1.25rem; flex-wrap: wrap; gap: 0.5rem;
  }
  .print-bar a { font-size: 0.82rem; color: #6B5E4C; text-decoration: none; display: flex; align-items: center; gap: 0.35rem; }
  .print-bar a:hover { color: #B8860B; }
  .print-btn {
    background: #1C1A17; color: #D4A017; border: none; border-radius: 10px;
    padding: 0.55rem 1.4rem; font-size: 0.82rem; font-weight: 700;
    cursor: pointer; display: flex; align-items: center; gap: 0.4rem;
    font-family: inherit; transition: opacity 0.15s;
  }
  .print-btn:hover { opacity: 0.85; }

  /* ── RECEIPT PAPER ── */
  .receipt {
    width: 100%; max-width: 720px;
    background: #fff;
    border: 1px solid #E2D9CC;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 4px 24px rgba(0,0,0,0.08);
  }

  /* Header */
  .receipt-head {
    background: #1C1A17;
    padding: 1.75rem 2rem;
    display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;
  }
  .receipt-brand { display: flex; align-items: center; gap: 1rem; }
  .receipt-brand-name { font-size: 1.15rem; font-weight: 800; color: #fff; }
  .receipt-brand-name span { color: #D4A017; }
  .receipt-brand-addr { font-size: 0.68rem; color: rgba(200,192,176,0.6); margin-top: 0.2rem; }
  .receipt-title-block { text-align: right; }
  .receipt-title-block .label { font-size: 0.65rem; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; color: #D4A017; }
  .receipt-title-block .num { font-size: 1.25rem; font-weight: 800; color: #fff; font-family: monospace; margin-top: 0.15rem; }
  .receipt-title-block .date { font-size: 0.72rem; color: rgba(200,192,176,0.6); margin-top: 0.15rem; }

  /* Gold accent bar */
  .gold-bar { height: 3px; background: linear-gradient(90deg,#D4A017,#B8860B,#E2D9CC); }

  /* Info grid */
  .info-section { padding: 1.5rem 2rem; border-bottom: 1px solid #F0EDE8; }
  .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
  .info-block label { font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #9C9286; display: block; margin-bottom: 0.2rem; }
  .info-block .val { font-size: 0.85rem; font-weight: 600; color: #1A1814; }
  .info-block .val-muted { font-size: 0.78rem; color: #6B5E4C; margin-top: 0.1rem; }

  /* Items table */
  .items-section { padding: 0 2rem 0; }
  .items-table { width: 100%; border-collapse: collapse; margin: 1.25rem 0; }
  .items-table thead tr { border-bottom: 2px solid #E2D9CC; }
  .items-table th {
    font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px;
    color: #9C9286; padding: 0.5rem 0.6rem; text-align: left;
  }
  .items-table th.r { text-align: right; }
  .items-table td { padding: 0.7rem 0.6rem; border-bottom: 1px solid #F4F1EC; font-size: 0.83rem; vertical-align: top; }
  .items-table td.r { text-align: right; font-family: monospace; }
  .items-table tbody tr:last-child td { border-bottom: none; }
  .item-desc { font-weight: 600; color: #1A1814; }
  .item-area { font-size: 0.72rem; color: #9C9286; margin-top: 0.15rem; }
  .item-num  { color: #9C9286; font-size: 0.75rem; }

  /* Totals */
  .totals-section {
    margin: 0 2rem;
    border-top: 2px solid #E2D9CC;
    padding: 1rem 0;
    display: flex; flex-direction: column; align-items: flex-end; gap: 0.35rem;
  }
  .total-row { display: flex; gap: 2rem; font-size: 0.82rem; color: #6B5E4C; }
  .total-row .tl { min-width: 110px; text-align: right; }
  .total-row .tv { min-width: 110px; text-align: right; font-family: monospace; font-weight: 600; }
  .total-row.grand { font-size: 1rem; font-weight: 800; color: #1A1814; padding-top: 0.5rem; border-top: 1px solid #E2D9CC; margin-top: 0.25rem; }

  /* Payment summary */
  .pay-section {
    margin: 1rem 2rem;
    background: #FFFBF0; border: 1px solid #F0E0B8; border-radius: 12px;
    padding: 1rem 1.25rem;
    display: grid; grid-template-columns: repeat(3,1fr); gap: 0.75rem;
  }
  .pay-block label { font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #9C9286; display: block; margin-bottom: 0.2rem; }
  .pay-block .pval { font-size: 0.9rem; font-weight: 800; color: #1A1814; font-family: monospace; }
  .pay-block .pval.green { color: #2E7D52; }
  .pay-block .pval.warn  { color: #B8860B; }
  .pay-block .pval.red   { color: #C0392B; }

  /* Notes */
  .notes-section { padding: 0.75rem 2rem 1rem; }
  .notes-label { font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #9C9286; margin-bottom: 0.35rem; }
  .notes-val { font-size: 0.8rem; color: #5C5648; line-height: 1.6; }

  /* Footer */
  .receipt-foot {
    background: #FAFAF8; border-top: 1px solid #E2D9CC;
    padding: 1.25rem 2rem;
    display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem;
  }
  .receipt-foot .note { font-size: 0.72rem; color: #9C9286; line-height: 1.6; }
  .receipt-foot .stamp {
    font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px;
    color: #D4A017; border: 1.5px solid #D4A017; border-radius: 6px;
    padding: 0.25rem 0.65rem;
  }

  /* ── PRINT STYLES ── */
  @media print {
    body { background: #fff; padding: 0; }
    .print-bar { display: none; }
    .receipt { box-shadow: none; border: none; border-radius: 0; max-width: 100%; }
    @page { margin: 1cm; size: A4; }
  }

  /* ── MOBILE ── */
  @media (max-width: 540px) {
    .receipt-head { flex-direction: column; }
    .receipt-title-block { text-align: left; }
    .info-grid { grid-template-columns: 1fr; }
    .pay-section { grid-template-columns: 1fr 1fr; }
    .items-table th:nth-child(3),
    .items-table td:nth-child(3) { display: none; }
  }
</style>
</head>
<body>

<div class="print-bar">
  <a href="view_quotation.php?id=<?= $qt_id ?>">
    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
    Back
  </a>
  <button class="print-btn" onclick="window.print()">
    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0110.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0l.229 2.523a1.125 1.125 0 01-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0021 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 00-1.913-.247M6.34 18H5.25A2.25 2.25 0 013 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 011.913-.247m10.5 0a48.536 48.536 0 00-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5zm-3 0h.008v.008H15V10.5z"/></svg>
    Print / Save PDF
  </button>
</div>

<div class="receipt">

  <!-- HEADER -->
  <div class="receipt-head">
    <div class="receipt-brand">
      <div style="display:flex;align-items:center;gap:0.5rem;flex-shrink:0;">
        <img src="../../assets/img/tg_logo.png" alt="TG Customworks" style="width:38px;height:38px;border-radius:8px;object-fit:cover;border:1.5px solid rgba(212,160,23,0.4);"/>
        <div style="width:1px;height:28px;background:rgba(212,160,23,0.25);"></div>
        <img src="../../assets/img/LogoBasicCar.png" alt="Basic Car Insurance" style="height:34px;width:auto;object-fit:contain;"/>
      </div>
      <div>
        <div class="receipt-brand-name">TG<span>-BASICS</span></div>
        <div class="receipt-brand-addr"><?= htmlspecialchars($company_address) ?></div>
      </div>
    </div>
    <div class="receipt-title-block">
      <div class="label">Official Receipt</div>
      <div class="num"><?= htmlspecialchars($d['receipt_number']) ?></div>
      <div class="date"><?= date('F d, Y', strtotime($d['issued_at'])) ?></div>
    </div>
  </div>
  <div class="gold-bar"></div>

  <!-- CLIENT + JOB INFO -->
  <div class="info-section">
    <div class="info-grid">
      <div class="info-block">
        <label>Billed To</label>
        <div class="val"><?= htmlspecialchars($d['full_name']) ?></div>
        <div class="val-muted"><?= htmlspecialchars($d['contact_number'] ?: '—') ?></div>
        <?php if ($d['email']): ?>
        <div class="val-muted"><?= htmlspecialchars($d['email']) ?></div>
        <?php endif; ?>
      </div>
      <div class="info-block">
        <label>Vehicle</label>
        <div class="val"><?= htmlspecialchars(trim($d['year_model'] . ' ' . $d['make'] . ' ' . $d['model'])) ?></div>
        <div class="val-muted">Plate: <?= htmlspecialchars($d['plate_number']) ?><?= $d['color'] ? ' &nbsp;·&nbsp; ' . htmlspecialchars($d['color']) : '' ?></div>
      </div>
      <div class="info-block">
        <label>Job Reference</label>
        <div class="val"><?= htmlspecialchars($d['job_number']) ?></div>
        <div class="val-muted"><?= htmlspecialchars($service_labels[$d['service_type']] ?? $d['service_type']) ?></div>
      </div>
      <div class="info-block">
        <label>Quotation #</label>
        <div class="val"><?= htmlspecialchars($d['quotation_number']) ?></div>
        <div class="val-muted">Repair Date: <?= date('F d, Y', strtotime($d['repair_date'])) ?></div>
      </div>
    </div>
  </div>

  <!-- LINE ITEMS -->
  <div class="items-section">
    <table class="items-table">
      <thead>
        <tr>
          <th style="width:28px;">#</th>
          <th>Description</th>
          <th>Area</th>
          <th class="r" style="width:60px;">Qty</th>
          <th class="r" style="width:110px;">Unit Price</th>
          <th class="r" style="width:110px;">Amount</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($items as $i => $it): ?>
        <tr>
          <td class="item-num"><?= $i + 1 ?></td>
          <td>
            <div class="item-desc"><?= htmlspecialchars($it['description']) ?></div>
          </td>
          <td><span class="item-area"><?= $it['area'] ? htmlspecialchars($it['area']) : '—' ?></span></td>
          <td class="r"><?= number_format($it['qty'], 2) ?></td>
          <td class="r">PHP <?= number_format($it['unit_price'], 2) ?></td>
          <td class="r">PHP <?= number_format($it['subtotal'], 2) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- TOTALS -->
  <div class="totals-section">
    <div class="total-row"><span class="tl">Subtotal</span><span class="tv">PHP <?= number_format($d['subtotal'], 2) ?></span></div>
    <?php if ($d['discount'] > 0): ?>
    <div class="total-row"><span class="tl">Discount</span><span class="tv" style="color:#2E7D52;">— PHP <?= number_format($d['discount'], 2) ?></span></div>
    <?php endif; ?>
    <div class="total-row grand"><span class="tl">TOTAL</span><span class="tv">PHP <?= number_format($d['total'], 2) ?></span></div>
  </div>

  <!-- PAYMENT SUMMARY -->
  <div class="pay-section">
    <div class="pay-block">
      <label>Amount Paid</label>
      <div class="pval green">PHP <?= number_format($d['amount_paid'], 2) ?></div>
    </div>
    <div class="pay-block">
      <label>Balance</label>
      <div class="pval <?= $d['balance'] > 0 ? 'warn' : 'green' ?>">PHP <?= number_format($d['balance'], 2) ?></div>
    </div>
    <div class="pay-block">
      <label>Payment Method</label>
      <div class="pval"><?= htmlspecialchars($pay_methods[$d['payment_method']] ?? $d['payment_method']) ?></div>
    </div>
  </div>

  <?php if ($d['receipt_notes'] || $d['qt_notes']): ?>
  <!-- NOTES -->
  <div class="notes-section">
    <div class="notes-label">Notes</div>
    <div class="notes-val"><?= htmlspecialchars($d['receipt_notes'] ?: $d['qt_notes']) ?></div>
  </div>
  <?php endif; ?>

  <!-- FOOTER -->
  <div class="receipt-foot">
    <div class="note">
      This is an official receipt issued by <strong><?= htmlspecialchars($company_name) ?></strong>.<br>
      Generated on <?= date('F d, Y \a\t h:i A') ?> via TG-BASICS.
    </div>
    <?php
    $stamp_map   = ['paid' => 'PAID', 'partial' => 'PARTIAL', 'unpaid' => 'UNPAID'];
    $stamp_color = ['paid' => '#2E7D52', 'partial' => '#B8860B', 'unpaid' => '#C0392B'];
    $ps = $d['payment_status'];
    ?>
    <div class="stamp" style="border-color:<?= $stamp_color[$ps] ?? '#D4A017' ?>;color:<?= $stamp_color[$ps] ?? '#D4A017' ?>;">
      <?= $stamp_map[$ps] ?? 'ISSUED' ?>
    </div>
  </div>

</div>

</body>
</html>
