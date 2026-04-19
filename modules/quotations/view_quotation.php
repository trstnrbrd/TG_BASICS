<?php
require_once __DIR__ . "/../../config/session.php";
require_once '../../config/db.php';
require_once '../../config/validators.php';
require_once '../../config/mailer.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin', 'mechanic'])) {
    header("Location: ../../auth/login.php");
    exit;
}
$is_admin = in_array($_SESSION['role'], ['admin', 'super_admin']);

$qt_id = san_int($_GET['id'] ?? 0, 1);
if (!$qt_id) { header("Location: quotation_list.php"); exit; }

// ── FETCH QUOTATION ──
$stmt = $conn->prepare("
    SELECT q.*,
           j.job_number, j.service_type, j.repair_date, j.status AS job_status,
           c.client_id, c.full_name, c.contact_number, c.email, c.address,
           v.plate_number, v.make, v.model, v.year_model, v.color,
           r.receipt_id, r.receipt_number, r.amount_paid, r.balance AS receipt_balance,
           r.payment_method, r.payment_status, r.issued_at
    FROM quotations q
    INNER JOIN repair_jobs j ON q.job_id = j.job_id
    INNER JOIN clients     c ON j.client_id  = c.client_id
    INNER JOIN vehicles    v ON j.vehicle_id = v.vehicle_id
    LEFT  JOIN receipts    r ON r.quotation_id = q.quotation_id
    WHERE q.quotation_id = ?
");
$stmt->bind_param('i', $qt_id);
$stmt->execute();
$qt = $stmt->get_result()->fetch_assoc();
if (!$qt) { header("Location: quotation_list.php"); exit; }

// ── FETCH LINE ITEMS ──
$items_stmt = $conn->prepare("SELECT * FROM quotation_items WHERE quotation_id = ? ORDER BY sort_order ASC");
$items_stmt->bind_param('i', $qt_id);
$items_stmt->execute();
$items = $items_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$area_labels = [
    'front_bumper' => 'Front Bumper', 'rear_bumper'  => 'Rear Bumper',
    'hood'         => 'Hood',         'trunk'        => 'Trunk',
    'windshield'   => 'Windshield',   'door_fl'      => 'Left Front Door',
    'door_rl'      => 'Left Rear Door','door_fr'     => 'Right Front Door',
    'door_rr'      => 'Right Rear Door','mirror_left' => 'Left Mirror',
    'mirror_right' => 'Right Mirror', 'headlights'   => 'Headlights',
    'taillights'   => 'Taillights',   'tires_wheels' => 'Tires & Wheels',
];

$service_labels = [
    'repair_panel'   => 'Per Panel Repair',  'repair_full'    => 'Full Body Repair',
    'paint_panel'    => 'Per Panel Paint',   'paint_full'     => 'Full Body Paint',
    'washover_basic' => 'Basic Wash Over',   'washover_full'  => 'Fully Wash Over',
    'custom'         => 'Custom / Mixed',
];

$status_cfg = [
    'draft'            => ['Draft',            'badge-gray',    '#6B7280'],
    'pending_approval' => ['Pending Approval', 'badge-yellow',  '#B8860B'],
    'approved'         => ['Approved',         'badge-green',   '#2E7D52'],
    'converted'        => ['Converted',        'badge-blue',    '#1A6B9A'],
    'cancelled'        => ['Cancelled',        'badge-red',     '#C0392B'],
];
$pay_cfg = [
    'unpaid'  => ['Unpaid',  'badge-red'],
    'partial' => ['Partial', 'badge-yellow'],
    'paid'    => ['Paid',    'badge-green'],
];

// ── HANDLE POST ACTIONS ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_admin) {
    csrf_verify();
    $action = san_str($_POST['action'] ?? '', 30);

    // Approve
    if ($action === 'approve' && $qt['status'] === 'pending_approval') {
        $upd = $conn->prepare("UPDATE quotations SET status='approved', approved_at=NOW() WHERE quotation_id=?");
        $upd->bind_param('i', $qt_id);
        $upd->execute();
        $log = $conn->prepare("INSERT INTO audit_logs (user_id,action,description) VALUES (?,'QUOTATION_APPROVED',?)");
        $d   = ($_SESSION['full_name'] ?? 'Unknown') . ' approved quotation ' . $qt['quotation_number'] . '.';
        $log->bind_param('is', $_SESSION['user_id'], $d);
        $log->execute();
        header("Location: view_quotation.php?id=$qt_id&success=Quotation+approved.");
        exit;
    }

    // Send for approval (from draft)
    if ($action === 'send_approval' && $qt['status'] === 'draft') {
        $upd = $conn->prepare("UPDATE quotations SET status='pending_approval' WHERE quotation_id=?");
        $upd->bind_param('i', $qt_id);
        $upd->execute();
        header("Location: view_quotation.php?id=$qt_id&success=Sent+for+client+approval.");
        exit;
    }

    // Cancel
    if ($action === 'cancel' && in_array($qt['status'], ['draft','pending_approval'])) {
        $upd = $conn->prepare("UPDATE quotations SET status='cancelled' WHERE quotation_id=?");
        $upd->bind_param('i', $qt_id);
        $upd->execute();
        header("Location: view_quotation.php?id=$qt_id&success=Quotation+cancelled.");
        exit;
    }

    // Convert to receipt
    if ($action === 'convert' && $qt['status'] === 'approved' && !$qt['receipt_id']) {
        $amount_paid   = san_float($_POST['amount_paid'] ?? 0);
        $pay_method    = san_enum($_POST['payment_method'] ?? 'cash', ['cash','e_wallet','bank_transfer']);
        $receipt_notes = san_str($_POST['receipt_notes'] ?? '', 500);
        $balance       = max(0, $qt['total'] - $amount_paid);
        $pay_status    = $amount_paid <= 0 ? 'unpaid' : ($balance > 0 ? 'partial' : 'paid');

        // Generate receipt number: OR-YYYYMMDD-XXXX (find next unused)
        $rc_prefix = 'OR-' . date('Ymd') . '-';
        $rc_seq_stmt = $conn->prepare("SELECT receipt_number FROM receipts WHERE receipt_number LIKE ? ORDER BY receipt_number DESC LIMIT 1");
        $rc_like = $rc_prefix . '%';
        $rc_seq_stmt->bind_param('s', $rc_like);
        $rc_seq_stmt->execute();
        $rc_last = $rc_seq_stmt->get_result()->fetch_row();
        $rc_seq  = $rc_last ? (int)substr($rc_last[0], -4) + 1 : 1;
        $rc_num  = $rc_prefix . str_pad($rc_seq, 4, '0', STR_PAD_LEFT);

        $conn->begin_transaction();
        try {
            $ins = $conn->prepare("
                INSERT INTO receipts (quotation_id, receipt_number, amount_paid, balance, payment_method, payment_status, notes, issued_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $ins->bind_param('isddsssi', $qt_id, $rc_num, $amount_paid, $balance, $pay_method, $pay_status, $receipt_notes, $_SESSION['user_id']);
            $ins->execute();

            $upd = $conn->prepare("UPDATE quotations SET status='converted', converted_at=NOW() WHERE quotation_id=?");
            $upd->bind_param('i', $qt_id);
            $upd->execute();

            $log = $conn->prepare("INSERT INTO audit_logs (user_id,action,description) VALUES (?,'RECEIPT_CREATED',?)");
            $d   = ($_SESSION['full_name'] ?? 'Unknown') . ' converted quotation ' . $qt['quotation_number'] . ' to receipt ' . $rc_num . '.';
            $log->bind_param('is', $_SESSION['user_id'], $d);
            $log->execute();

            $conn->commit();

            // Send receipt email to client if they have an email address
            if (!empty($qt['email'])) {
                $service_labels_map = [
                    'repair_panel'=>'Per Panel Repair','repair_full'=>'Full Body Repair',
                    'paint_panel'=>'Per Panel Paint','paint_full'=>'Full Body Paint',
                    'washover_basic'=>'Basic Wash Over','washover_full'=>'Fully Wash Over','custom'=>'Custom / Mixed',
                ];
                sendReceiptEmail(
                    $qt['email'],
                    $qt['full_name'],
                    $rc_num,
                    $qt['quotation_number'],
                    $qt['job_number'],
                    $qt['plate_number'],
                    trim($qt['year_model'] . ' ' . $qt['make'] . ' ' . $qt['model']),
                    $service_labels_map[$qt['service_type']] ?? $qt['service_type'],
                    (float)$qt['total'],
                    $amount_paid,
                    $balance,
                    $pay_method,
                    date('F d, Y'),
                    $items
                );
            }

            header("Location: view_quotation.php?id=$qt_id&success=" . urlencode('Receipt ' . $rc_num . ' issued.'));
            exit;
        } catch (Exception $e) {
            $conn->rollback();
        }
    }

    // Update payment on existing receipt
    if ($action === 'update_payment' && $qt['receipt_id']) {
        $amount_paid   = san_float($_POST['amount_paid'] ?? 0);
        $pay_method    = san_enum($_POST['payment_method'] ?? 'cash', ['cash','e_wallet','bank_transfer']);
        $balance       = max(0, $qt['total'] - $amount_paid);
        $pay_status    = $amount_paid <= 0 ? 'unpaid' : ($balance > 0 ? 'partial' : 'paid');
        $upd = $conn->prepare("UPDATE receipts SET amount_paid=?, balance=?, payment_method=?, payment_status=? WHERE receipt_id=?");
        $upd->bind_param('ddssi', $amount_paid, $balance, $pay_method, $pay_status, $qt['receipt_id']);
        $upd->execute();
        header("Location: view_quotation.php?id=$qt_id&success=Payment+updated.");
        exit;
    }
}

$sc   = $status_cfg[$qt['status']] ?? ['Unknown','badge-gray','#6B7280'];
$svc  = $service_labels[$qt['service_type']] ?? $qt['service_type'];

$page_title  = 'Quotation — ' . $qt['quotation_number'];
$active_page = 'quotations';
$base_path   = '../../';
$extra_css   = '<link rel="stylesheet" href="../../assets/css/shared/quotations.css"/>';
require_once '../../includes/header.php';
require_once '../../includes/navbar.php';
?>

<div class="main">
<?php
$topbar_title      = 'Quotation';
$topbar_breadcrumb = ['Repair Shop', 'Quotations', $qt['quotation_number']];
require_once '../../includes/topbar.php';
?>

<div class="content">

<?php if (!empty($_GET['success'])): ?>
<script>
document.addEventListener('DOMContentLoaded',function(){
  Swal.fire({toast:true,position:'top-end',icon:'success',title:<?= json_encode(san_str($_GET['success'],200)) ?>,showConfirmButton:false,timer:3000,timerProgressBar:true});
});
</script>
<?php endif; ?>
<?php if (!empty($_GET['msg'])): ?>
<script>
document.addEventListener('DOMContentLoaded',function(){
  Swal.fire({toast:true,position:'top-end',icon:'info',title:<?= json_encode(san_str($_GET['msg'],200)) ?>,showConfirmButton:false,timer:3000,timerProgressBar:true});
});
</script>
<?php endif; ?>

<!-- HEADER ROW -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.75rem;margin-bottom:1.25rem;">
  <a href="quotation_list.php" style="display:inline-flex;align-items:center;gap:0.4rem;font-size:0.82rem;color:var(--text-muted);text-decoration:none;" onmouseover="this.style.color='var(--gold)'" onmouseout="this.style.color='var(--text-muted)'">
    <?= icon('chevron-left', 14) ?> Back to Quotations
  </a>
  <div class="qt-action-bar">
    <span class="badge <?= $sc[1] ?>"><?= $sc[0] ?></span>
    <?php if ($is_admin && $qt['status'] === 'draft'): ?>
      <form method="POST" style="display:inline;">
        <?= csrf_field() ?><input type="hidden" name="action" value="send_approval"/>
        <button type="submit" class="btn-primary" style="font-size:0.82rem;"><?= icon('paper-airplane', 13) ?> Send for Approval</button>
      </form>
    <?php elseif ($is_admin && $qt['status'] === 'pending_approval'): ?>
      <form method="POST" style="display:inline;">
        <?= csrf_field() ?><input type="hidden" name="action" value="approve"/>
        <button type="submit" class="btn-primary" style="font-size:0.82rem;"><?= icon('check-circle', 13) ?> Mark Approved</button>
      </form>
    <?php elseif ($is_admin && $qt['status'] === 'approved' && !$qt['receipt_id']): ?>
      <button onclick="document.getElementById('convert-modal').style.display='flex'" class="btn-primary" style="font-size:0.82rem;"><?= icon('document-text', 13) ?> Convert to Receipt</button>
    <?php endif; ?>
    <?php if ($is_admin && in_array($qt['status'], ['draft','pending_approval'])): ?>
      <form method="POST" style="display:inline;" onsubmit="return confirm('Cancel this quotation?')">
        <?= csrf_field() ?><input type="hidden" name="action" value="cancel"/>
        <button type="submit" class="btn-ghost" style="font-size:0.82rem;color:var(--danger);"><?= icon('x-mark', 13) ?> Cancel</button>
      </form>
    <?php endif; ?>
    <?php if ($qt['receipt_id']): ?>
    <a href="print_receipt.php?id=<?= $qt_id ?>" target="_blank" class="btn-ghost" style="font-size:0.82rem;"><?= icon('printer', 13) ?> Print Receipt</a>
    <?php endif; ?>
    <a href="../repair/view_repair.php?id=<?= $qt['job_id'] ?>" class="btn-ghost" style="font-size:0.82rem;"><?= icon('wrench', 13) ?> View Job</a>
  </div>
</div>

<!-- INFO GRID -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.25rem;">

  <!-- QUOTATION DETAILS -->
  <div class="card" style="margin-bottom:0;">
    <div class="card-header">
      <div class="card-icon"><?= icon('receipt', 16) ?></div>
      <div><div class="card-title">Quotation Details</div><div class="card-sub"><?= htmlspecialchars($qt['quotation_number']) ?></div></div>
    </div>
    <div style="padding:1rem 1.25rem;display:flex;flex-direction:column;gap:0;">
      <?php
      $qt_details = [
        ['Quotation #',  $qt['quotation_number']],
        ['Job #',        $qt['job_number']],
        ['Service Type', $svc],
        ['Repair Date',  date('F d, Y', strtotime($qt['repair_date']))],
        ['Status',       $sc[0]],
        ['Created',      date('F d, Y', strtotime($qt['created_at']))],
      ];
      foreach ($qt_details as [$lbl, $val]): ?>
      <div style="display:flex;justify-content:space-between;align-items:center;padding:0.55rem 0;border-bottom:1px solid var(--border);">
        <span style="font-size:0.78rem;color:var(--text-muted);font-weight:500;"><?= $lbl ?></span>
        <span style="font-size:0.82rem;color:var(--text-primary);font-weight:600;"><?= htmlspecialchars($val) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- CLIENT & VEHICLE -->
  <div class="card" style="margin-bottom:0;">
    <div class="card-header">
      <div class="card-icon"><?= icon('user', 16) ?></div>
      <div><div class="card-title">Client &amp; Vehicle</div><div class="card-sub"><?= htmlspecialchars($qt['full_name']) ?></div></div>
      <a href="../clients/view_client.php?id=<?= $qt['client_id'] ?>" class="btn-sm-gold" style="margin-left:auto;"><?= icon('eye', 13) ?> View</a>
    </div>
    <div style="padding:1rem 1.25rem;display:flex;flex-direction:column;gap:0;">
      <?php
      $client_details = [
        ['Client',       $qt['full_name']],
        ['Contact',      $qt['contact_number'] ?: '—'],
        ['Plate',        $qt['plate_number']],
        ['Vehicle',      trim($qt['year_model'] . ' ' . $qt['make'] . ' ' . $qt['model'])],
        ['Color',        $qt['color'] ?: '—'],
      ];
      foreach ($client_details as [$lbl, $val]): ?>
      <div style="display:flex;justify-content:space-between;align-items:center;padding:0.55rem 0;border-bottom:1px solid var(--border);">
        <span style="font-size:0.78rem;color:var(--text-muted);font-weight:500;"><?= $lbl ?></span>
        <span style="font-size:0.82rem;color:var(--text-primary);font-weight:600;"><?= htmlspecialchars($val) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

</div>

<!-- LINE ITEMS -->
<div class="card" style="margin-bottom:1.25rem;">
  <div class="card-header">
    <div class="card-icon"><?= icon('clipboard-list', 16) ?></div>
    <div><div class="card-title">Line Items</div><div class="card-sub"><?= count($items) ?> item<?= count($items) !== 1 ? 's' : '' ?></div></div>
  </div>
  <div style="overflow-x:auto;">
    <table class="line-items-table">
      <thead>
        <tr>
          <th style="text-align:left;">#</th>
          <th style="text-align:left;">Description</th>
          <th style="text-align:left;">Area</th>
          <th style="text-align:center;">Qty</th>
          <th style="text-align:right;">Unit Price</th>
          <th style="text-align:right;">Subtotal</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($items as $i => $it): ?>
        <tr>
          <td style="color:var(--text-muted);font-size:0.75rem;"><?= $i + 1 ?></td>
          <td style="font-weight:600;font-size:0.82rem;color:var(--text-primary);"><?= htmlspecialchars($it['description']) ?></td>
          <td style="font-size:0.75rem;color:var(--text-muted);"><?= $it['area'] ? htmlspecialchars($area_labels[$it['area']] ?? $it['area']) : '—' ?></td>
          <td style="text-align:center;font-size:0.82rem;"><?= number_format($it['qty'], 2) ?></td>
          <td style="text-align:right;font-size:0.82rem;">PHP <?= number_format($it['unit_price'], 2) ?></td>
          <td style="text-align:right;font-weight:700;font-size:0.82rem;">PHP <?= number_format($it['subtotal'], 2) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div style="display:flex;flex-direction:column;align-items:flex-end;padding:1rem 1.25rem;gap:0.35rem;border-top:1px solid var(--border);">
    <div class="qt-total-row"><span class="qt-total-label">Subtotal</span><span class="qt-total-val">PHP <?= number_format($qt['subtotal'], 2) ?></span></div>
    <?php if ($qt['discount'] > 0): ?>
    <div class="qt-total-row"><span class="qt-total-label">Discount</span><span class="qt-total-val" style="color:var(--success);">— PHP <?= number_format($qt['discount'], 2) ?></span></div>
    <?php endif; ?>
    <div class="qt-total-row grand"><span class="qt-total-label">TOTAL</span><span class="qt-total-val">PHP <?= number_format($qt['total'], 2) ?></span></div>
  </div>
</div>

<?php if ($qt['notes']): ?>
<!-- NOTES -->
<div class="card" style="margin-bottom:1.25rem;">
  <div class="card-header">
    <div class="card-icon"><?= icon('document', 16) ?></div>
    <div><div class="card-title">Notes</div></div>
  </div>
  <div style="padding:1rem 1.25rem;font-size:0.82rem;color:var(--text-secondary);white-space:pre-wrap;"><?= htmlspecialchars($qt['notes']) ?></div>
</div>
<?php endif; ?>

<?php if ($qt['receipt_id']): ?>
<!-- RECEIPT PANEL -->
<div class="card" style="margin-bottom:1.25rem;">
  <div class="card-header">
    <div class="card-icon" style="background:var(--success-bg);color:var(--success);"><?= icon('document-text', 16) ?></div>
    <div>
      <div class="card-title">E-Receipt Issued</div>
      <div class="card-sub"><?= htmlspecialchars($qt['receipt_number']) ?></div>
    </div>
    <?php $pc = $pay_cfg[$qt['payment_status']] ?? ['Unknown','badge-gray']; ?>
    <span class="badge <?= $pc[1] ?>" style="margin-left:auto;"><?= $pc[0] ?></span>
  </div>
  <div style="padding:1rem 1.25rem;">
    <div class="receipt-info-grid">
      <div class="receipt-info-row"><span class="receipt-info-label">Receipt #</span><span class="receipt-info-val"><?= htmlspecialchars($qt['receipt_number']) ?></span></div>
      <div class="receipt-info-row"><span class="receipt-info-label">Issued</span><span class="receipt-info-val"><?= date('F d, Y', strtotime($qt['issued_at'])) ?></span></div>
      <div class="receipt-info-row"><span class="receipt-info-label">Total</span><span class="receipt-info-val">PHP <?= number_format($qt['total'], 2) ?></span></div>
      <div class="receipt-info-row"><span class="receipt-info-label">Amount Paid</span><span class="receipt-info-val" style="color:var(--success);">PHP <?= number_format($qt['amount_paid'], 2) ?></span></div>
      <div class="receipt-info-row"><span class="receipt-info-label">Balance</span><span class="receipt-info-val" style="color:<?= $qt['receipt_balance'] > 0 ? 'var(--warning)' : 'var(--success)' ?>;">PHP <?= number_format($qt['receipt_balance'], 2) ?></span></div>
      <div class="receipt-info-row"><span class="receipt-info-label">Payment Method</span><span class="receipt-info-val"><?= ucwords(str_replace('_', ' ', $qt['payment_method'])) ?></span></div>
    </div>
    <!-- Update payment -->
    <?php if ($is_admin && $qt['payment_status'] !== 'paid'): ?>
    <div style="border-top:1px solid var(--border);padding-top:1rem;margin-top:0.5rem;">
      <div style="font-size:0.78rem;font-weight:700;color:var(--text-muted);margin-bottom:0.75rem;text-transform:uppercase;letter-spacing:0.5px;">Update Payment</div>
      <form method="POST" style="display:flex;gap:0.6rem;flex-wrap:wrap;align-items:flex-end;">
        <?= csrf_field() ?><input type="hidden" name="action" value="update_payment"/>
        <div class="field" style="flex:1;min-width:140px;">
          <label class="field-label">Amount Paid</label>
          <input type="number" name="amount_paid" class="field-input" value="<?= $qt['amount_paid'] ?>" min="0" step="0.01" max="<?= $qt['total'] ?>" required/>
        </div>
        <div class="field" style="flex:1;min-width:140px;">
          <label class="field-label">Method</label>
          <select name="payment_method" class="field-select">
            <option value="cash"          <?= $qt['payment_method']==='cash'?'selected':'' ?>>Cash</option>
            <option value="e_wallet"      <?= $qt['payment_method']==='e_wallet'?'selected':'' ?>>E-Wallet</option>
            <option value="bank_transfer" <?= $qt['payment_method']==='bank_transfer'?'selected':'' ?>>Bank Transfer</option>
          </select>
        </div>
        <button type="submit" class="btn-primary" style="margin-bottom:0.05rem;"><?= icon('check', 13) ?> Update</button>
      </form>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

</div>
</div>

<?php if ($is_admin && $qt['status'] === 'approved' && !$qt['receipt_id']): ?>
<!-- CONVERT TO RECEIPT MODAL -->
<div id="convert-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:1000;align-items:center;justify-content:center;padding:1rem;">
  <div style="background:var(--bg-2);border:1px solid var(--border);border-radius:18px;padding:1.75rem;width:100%;max-width:420px;box-shadow:var(--shadow-lg);">
    <div style="font-size:1.05rem;font-weight:800;color:var(--text-primary);margin-bottom:0.2rem;"><?= icon('document-text', 18) ?> Convert to E-Receipt</div>
    <div style="font-size:0.78rem;color:var(--text-muted);margin-bottom:1.25rem;">Total: <strong style="color:var(--text-primary);">PHP <?= number_format($qt['total'], 2) ?></strong></div>
    <form method="POST">
      <?= csrf_field() ?><input type="hidden" name="action" value="convert"/>
      <div style="display:flex;flex-direction:column;gap:0.85rem;margin-bottom:1.25rem;">
        <div class="field">
          <label class="field-label">Amount Paid <span class="req">*</span></label>
          <input type="number" name="amount_paid" class="field-input" value="<?= $qt['total'] ?>" min="0" step="0.01" max="<?= $qt['total'] ?>" required/>
        </div>
        <div class="field">
          <label class="field-label">Payment Method</label>
          <select name="payment_method" class="field-select">
            <option value="cash">Cash</option>
            <option value="e_wallet">E-Wallet</option>
            <option value="bank_transfer">Bank Transfer</option>
          </select>
        </div>
        <div class="field">
          <label class="field-label">Receipt Notes</label>
          <textarea name="receipt_notes" class="field-input" rows="2" placeholder="Optional notes on receipt..." style="resize:vertical;"></textarea>
        </div>
      </div>
      <div style="display:flex;gap:0.5rem;justify-content:flex-end;">
        <button type="button" onclick="document.getElementById('convert-modal').style.display='none'" class="btn-ghost">Cancel</button>
        <button type="submit" class="btn-primary"><?= icon('document-text', 13) ?> Issue Receipt</button>
      </div>
    </form>
  </div>
</div>
<script>
document.getElementById('convert-modal').addEventListener('click', function(e){ if(e.target===this) this.style.display='none'; });
document.addEventListener('keydown', function(e){ if(e.key==='Escape') document.getElementById('convert-modal').style.display='none'; });
</script>
<?php endif; ?>

<?php require_once '../../includes/footer.php'; ?>
