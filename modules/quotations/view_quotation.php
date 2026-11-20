<?php
require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . '/../../includes/data_access.php';
require_once '../../config/db.php';
require_once __DIR__ . '/../../includes/lookups.php';
require_once '../../config/validators.php';
require_once '../../config/access.php';
require_once '../../config/mailer.php';
require_once '../../includes/transaction.php';

require_role(['admin', 'super_admin', 'mechanic']);
$is_admin = is_staff();

$qt_id = san_int($_GET['id'] ?? 0, 1);
if (!$qt_id) { header("Location: quotation_list.php"); exit; }

// ── FETCH QUOTATION ──
$qt = quotation_find($conn, $qt_id);
if (!$qt) { header("Location: quotation_list.php"); exit; }

// ── FETCH LINE ITEMS ──
$items = quotation_items($conn, $qt_id);

$area_labels = REPAIR_CHECKLIST_AREAS;   // config/access.php

$service_labels = service_type_labels($conn);   // service_types table (includes/lookups.php)

// ── HANDLE POST ACTIONS ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_admin) {
    csrf_verify();
    $action = san_str($_POST['action'] ?? '', 30);

    // Approve
    // Each step changes the quotation only from the step this page was showing ($from_status), so the same
    // button sent twice, or two people at once, does it (and logs it) once. Already at the step asked for = done,
    // not an error; moved somewhere else in the meantime = the user is told.
    $steps = [
        'approve'       => ['to' => 'approved',         'from' => ['pending_approval'],          'msg' => 'Quotation approved.'],
        'send_approval' => ['to' => 'pending_approval', 'from' => ['draft'],                     'msg' => 'Sent for client approval.'],
        'cancel'        => ['to' => 'cancelled',        'from' => ['draft', 'pending_approval'], 'msg' => 'Quotation cancelled.'],
    ];
    if (isset($steps[$action])) {
        $step = $steps[$action];
        if ($qt['status'] === $step['to']) {
            header("Location: view_quotation.php?id=$qt_id&success=" . urlencode($step['msg']));
            exit;
        }
        if (in_array($qt['status'], $step['from'], true)) {
            if (!quotation_set_status($conn, $qt_id, $step['to'], $qt['status'])) {
                header("Location: view_quotation.php?id=$qt_id&error=" . urlencode('The quotation was changed by someone else. Please check it and try again.'));
                exit;
            }
            if ($action === 'approve') {
                $d = ($_SESSION['full_name'] ?? 'Unknown') . ' approved quotation ' . $qt['quotation_number'] . '.';
                audit_log_add($conn, $_SESSION['user_id'], 'QUOTATION_APPROVED', $d);
            }
            header("Location: view_quotation.php?id=$qt_id&success=" . urlencode($step['msg']));
            exit;
        }
    }

    // Amount paid on a billing statement: a valid amount, never more than the quotation total. A bad value is an
    // error, not a silent ₱0 ("unpaid") or a balance quietly floored at zero.
    $pay_error = function () use ($qt, $qt_id): array {
        [$amt, $err] = read_money($_POST['amount_paid'] ?? '', 'Amount paid', true);
        if ($err === '' && payment_exceeds($amt, (float)$qt['total'])) {
            $err = 'Amount paid cannot be more than the total of ₱' . number_format((float)$qt['total'], 2) . '.';
        }
        if ($err === '' && text_too_long($_POST['receipt_notes'] ?? '', MAX_NOTE)) $err = 'Notes are too long (max ' . MAX_NOTE . ' characters).';
        if ($err !== '') {
            header("Location: view_quotation.php?id=$qt_id&error=" . urlencode($err));
            exit;
        }
        return [$amt];
    };

    // Convert to receipt. Already converted (the same form sent twice): the receipt exists, so answer as the first time.
    if ($action === 'convert' && $qt['receipt_id']) {
        header("Location: view_quotation.php?id=$qt_id&success=" . urlencode('Receipt ' . $qt['receipt_number'] . ' issued.'));
        exit;
    }
    if ($action === 'convert' && $qt['status'] === 'approved' && !$qt['receipt_id']) {
        [$amount_paid] = $pay_error();
        $pay_method    = san_enum($_POST['payment_method'] ?? 'cash', ['cash','e_wallet','bank_transfer']);
        $receipt_notes = san_str($_POST['receipt_notes'] ?? '', MAX_NOTE);
        $balance       = max(0, $qt['total'] - $amount_paid);
        $pay_status    = $amount_paid <= 0 ? 'unpaid' : ($balance > 0 ? 'partial' : 'paid');

        // The status change, the receipt and the audit entry are saved together or not at all (includes/transaction.php).
        // A failure is reported to the user instead of the page silently coming back as if nothing was pressed.
        try {
            $rc_num = db_transaction($conn, function () use ($conn, $qt, $qt_id, $amount_paid, $balance, $pay_method, $pay_status, $receipt_notes) {
                // Approved -> converted first: the row stays locked until the commit, so a second "convert" (the same
                // form sent twice, or someone else at the same moment) finds it already converted and issues nothing.
                // (receipts.quotation_id is also UNIQUE, so the database would refuse a second receipt anyway.)
                if (!quotation_set_status($conn, $qt_id, 'converted', 'approved')) return null;

                // Billing statement number: BS-YYYYMM-XXXX. Retries on a same-instant collision — see
                // insert_with_sequential_number() — instead of failing the whole conversion for it.
                $num = insert_with_sequential_number($conn, 'receipts', 'receipt_number', record_number_prefix('BS'),
                    function (string $num) use ($conn, $qt_id, $amount_paid, $balance, $pay_method, $pay_status, $receipt_notes) {
                        receipt_create($conn, $num, $qt_id, (float)$amount_paid, (float)$balance, $pay_method, $pay_status, $receipt_notes, (int)$_SESSION['user_id']);
                        return $num;
                    }
                );

                $d = ($_SESSION['full_name'] ?? 'Unknown') . ' converted quotation ' . $qt['quotation_number'] . ' to receipt ' . $num . '.';
                audit_log_add($conn, $_SESSION['user_id'], 'RECEIPT_CREATED', $d);
                return $num;
            });
        } catch (Throwable $e) {
            error_log('[TG-BASICS] convert to receipt failed: ' . $e->getMessage());
            header("Location: view_quotation.php?id=$qt_id&error=" . urlencode('The receipt could not be issued. Please try again.'));
            exit;
        }
        if ($rc_num === null) {
            header("Location: view_quotation.php?id=$qt_id&error=" . urlencode('This quotation was already converted to a receipt.'));
            exit;
        }

        // Send receipt email to client if they have an email address (after the commit: never inside the transaction)
        if (!empty($qt['email'])) {
            $service_labels_map = service_type_labels($conn);   // service_types table (includes/lookups.php)
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
    }

    // Update payment on existing receipt
    if ($action === 'update_payment' && $qt['receipt_id']) {
        [$amount_paid] = $pay_error();
        $pay_method    = san_enum($_POST['payment_method'] ?? 'cash', ['cash','e_wallet','bank_transfer']);
        $balance       = max(0, $qt['total'] - $amount_paid);
        $pay_status    = $amount_paid <= 0 ? 'unpaid' : ($balance > 0 ? 'partial' : 'paid');
        receipt_set_payment($conn, (int)$qt['receipt_id'], (float)$amount_paid, (float)$balance, $pay_method, $pay_status);
        header("Location: view_quotation.php?id=$qt_id&success=Payment+updated.");
        exit;
    }
}

$sc   = quotation_status_badge($qt['status']);   // statuses table (includes/statuses.php)
$svc  = $service_labels[$qt['service_type']] ?? $qt['service_type'];

$page_title  = 'Quotation — ' . $qt['quotation_number'];
$active_page = 'quotations';
$base_path   = '../../';
$extra_css   = '<link rel="stylesheet" href="../../assets/css/shared/quotations.css?v=' . filemtime(__DIR__ . '/../../assets/css/shared/quotations.css') . '"/>';
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

<?php if (!empty($_GET['error'])): ?>
<script>
document.addEventListener('DOMContentLoaded',function(){
  Swal.fire({toast:true,position:'top-end',icon:'error',titleText:<?= json_encode(san_str($_GET['error'],200)) ?>,showConfirmButton:false,timer:5000,timerProgressBar:true});
});
</script>
<?php endif; ?>
<?php if (!empty($_GET['success'])): ?>
<script>
document.addEventListener('DOMContentLoaded',function(){
  Swal.fire({toast:true,position:'top-end',icon:'success',titleText:<?= json_encode(san_str($_GET['success'],200)) ?>,showConfirmButton:false,timer:3000,timerProgressBar:true});
});
</script>
<?php endif; ?>
<?php if (!empty($_GET['msg'])): ?>
<script>
document.addEventListener('DOMContentLoaded',function(){
  Swal.fire({toast:true,position:'top-end',icon:'info',titleText:<?= json_encode(san_str($_GET['msg'],200)) ?>,showConfirmButton:false,timer:3000,timerProgressBar:true});
});
</script>
<?php endif; ?>

<?php
// ── what the page shows, worked out once ──
$status   = $qt['status'];
$peso     = fn($n) => '₱' . number_format((float)$n, 2);
$vehicle  = trim($qt['year_model'] . ' ' . $qt['make'] . ' ' . $qt['model']);
$initials = implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', trim($qt['full_name'])) ?: [], 0, 2)));
// The steps are fixed buttons on this page (draft → for approval → approved → billed), so the bar is too;
// Cancelled is a side branch shown after the steps it passed
$qt_path   = ['draft', 'pending_approval', 'approved', 'converted'];
$qt_cur    = array_search($status, $qt_path, true);
$qt_branch = $qt_cur === false ? $status : null;
$qt_done_before = $qt_branch === null ? $qt_cur : 0;
if ($qt_branch === 'cancelled') $qt_done_before = 1;   // cancelled from draft or for approval: at least drafted
$qt_hint = [
    'draft'            => 'Still a draft. Edit the items, then send it to the client for approval.',
    'pending_approval' => 'Sent to the client. Mark it approved once they agree to the price.',
    'approved'         => 'The client agreed. Issue the billing statement when the job is paid or ready to bill.',
    'converted'        => 'Billed. The billing statement below tracks the payment.',
    'cancelled'        => 'Cancelled. It can no longer be approved or billed.',
][$status] ?? '';
$paid_pct = $qt['receipt_id'] && (float)$qt['total'] > 0 ? max(0, min(100, round((float)$qt['amount_paid'] / (float)$qt['total'] * 100))) : 0;
$methods  = ['cash' => 'Cash', 'e_wallet' => 'E-Wallet', 'bank_transfer' => 'Bank Transfer'];
?>

<!-- HERO: which quotation, its status and total, and what can be done -->
<section class="card qv-hero">
  <div class="qv-hero-top">
    <a href="quotation_list.php" class="back-link qv-back" onclick="goBack('quotation_list.php'); return false;" aria-label="Back to Quotations"><?= icon('arrow-left', 14) ?></a>
    <div class="qv-hero-id">
      <div class="qv-kicker">Quotation</div>
      <div class="qv-title-row">
        <h2 class="qv-number"><?= htmlspecialchars($qt['quotation_number']) ?></h2>
        <span class="badge <?= $sc['class'] ?>"><?= htmlspecialchars($sc['label']) ?></span>
      </div>
      <div class="qv-meta">
        <span><?= icon('wrench', 12) ?> <?= htmlspecialchars($svc) ?></span>
        <span><?= icon('user', 12) ?> <?= htmlspecialchars($qt['full_name']) ?></span>
        <span><?= icon('truck', 12) ?> <?= htmlspecialchars($qt['plate_number']) ?></span>
        <span><?= icon('calendar', 12) ?> Created <?= date('M d, Y', strtotime($qt['created_at'])) ?></span>
      </div>
    </div>
    <div class="qv-hero-total">
      <div class="qv-kicker">Total</div>
      <div class="qv-total-amount"><?= $peso($qt['total']) ?></div>
      <?php if ($qt['discount'] > 0): ?><div class="qv-total-note">after <?= $peso($qt['discount']) ?> discount</div><?php endif; ?>
    </div>
  </div>

  <div class="qv-hero-bottom">
    <?php if ($qt_hint): ?><p class="qv-hint"><?= icon('information-circle', 14) ?> <?= htmlspecialchars($qt_hint) ?></p><?php endif; ?>
    <div class="qt-action-bar">
      <?php if ($is_admin && $status === 'draft'): ?>
        <a href="../quotations/add_quotation.php?quotation_id=<?= $qt_id ?>" class="btn-ghost"><?= icon('pencil', 13) ?> Edit Draft</a>
        <form method="POST" class="js-qt-confirm" data-title="Send for approval?" data-text="The quotation can no longer be edited once it is sent for approval." data-button="Yes, send it">
          <?= csrf_field() ?><input type="hidden" name="action" value="send_approval"/>
          <button type="submit" class="btn-primary"><?= icon('paper-airplane', 13) ?> Send for Approval</button>
        </form>
      <?php elseif ($is_admin && $status === 'pending_approval'): ?>
        <form method="POST" class="js-qt-confirm" data-title="Mark this quotation as approved?" data-text="Do this once the client has agreed to the quotation." data-button="Yes, mark approved">
          <?= csrf_field() ?><input type="hidden" name="action" value="approve"/>
          <button type="submit" class="btn-primary"><?= icon('check-circle', 13) ?> Mark Approved</button>
        </form>
      <?php elseif ($is_admin && $status === 'approved' && !$qt['receipt_id']): ?>
        <button type="button" class="btn-primary js-open-convert"><?= icon('document-text', 13) ?> Convert to Billing Statement</button>
      <?php endif; ?>
      <?php if ($qt['receipt_id']): ?>
      <a href="print_receipt.php?id=<?= $qt_id ?>" target="_blank" class="btn-ghost"><?= icon('printer', 13) ?> Print Statement</a>
      <?php endif; ?>
      <a href="../repair/view_repair.php?id=<?= $qt['job_id'] ?>" class="btn-ghost"><?= icon('wrench', 13) ?> View Job</a>
      <?php if ($is_admin && in_array($status, ['draft', 'pending_approval'], true)): ?>
        <form method="POST" class="js-qt-confirm" data-danger="1" data-title="Cancel this quotation?" data-text="A cancelled quotation cannot be approved or turned into a billing statement." data-button="Yes, cancel it">
          <?= csrf_field() ?><input type="hidden" name="action" value="cancel"/>
          <button type="submit" class="btn-ghost qv-btn-danger"><?= icon('x-mark', 13) ?> Cancel Quotation</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- STEPS -->
<ol class="qv-steps" aria-label="Quotation progress">
  <?php foreach ($qt_path as $i => $code):
    $state = $qt_branch !== null ? ($i < $qt_done_before ? 'done' : 'todo')
           : ($i < $qt_cur ? 'done' : ($i === $qt_cur ? ($code === 'converted' ? 'done current' : 'current') : 'todo'));
  ?>
  <li class="qv-step <?= $state ?>"<?= str_contains($state, 'current') ? ' aria-current="step"' : '' ?>>
    <span class="qv-step-dot"><?= str_contains($state, 'done') ? icon('check', 11) : $i + 1 ?></span>
    <span class="qv-step-label"><?= htmlspecialchars(quotation_status_badge($code)['label']) ?></span>
  </li>
  <?php endforeach; ?>
  <?php if ($qt_branch !== null): ?>
  <li class="qv-step branch" aria-current="step">
    <span class="qv-step-dot"><?= icon('x-mark', 11) ?></span>
    <span class="qv-step-label"><?= htmlspecialchars(quotation_status_badge($qt_branch)['label']) ?></span>
  </li>
  <?php endif; ?>
</ol>

<div class="qv-grid">

  <!-- LEFT: the quotation itself -->
  <div class="qv-main">
    <section class="card qv-items">
      <div class="card-header">
        <div class="card-icon"><?= icon('clipboard-list', 16) ?></div>
        <div><div class="card-title">Line Items</div><div class="card-sub"><?= count($items) ?> item<?= count($items) !== 1 ? 's' : '' ?></div></div>
      </div>

      <?php if (!$items): ?>
      <div class="qv-empty">No line items on this quotation.</div>
      <?php else: ?>
      <table class="qv-table">
        <thead>
          <tr><th class="qv-col-num">#</th><th>Description</th><th class="qv-col-r">Qty</th><th class="qv-col-r">Unit Price</th><th class="qv-col-r">Amount</th></tr>
        </thead>
        <tbody>
          <?php foreach ($items as $i => $it): ?>
          <tr>
            <td class="qv-col-num"><span class="qv-num"><?= $i + 1 ?></span></td>
            <td class="qv-desc">
              <div class="qv-desc-text"><?= htmlspecialchars($it['description']) ?></div>
              <?php if ($it['area']): ?><span class="qv-area"><?= icon('map-pin', 10) ?> <?= htmlspecialchars($area_labels[$it['area']] ?? $it['area']) ?></span><?php endif; ?>
            </td>
            <td class="qv-col-r" data-label="Qty"><?= rtrim(rtrim(number_format((float)$it['qty'], 2), '0'), '.') ?></td>
            <td class="qv-col-r" data-label="Unit Price"><?= $peso($it['unit_price']) ?></td>
            <td class="qv-col-r qv-amount" data-label="Amount"><?= $peso($it['subtotal']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>

      <div class="qv-totals">
        <div class="qv-totals-row"><span>Subtotal</span><span><?= $peso($qt['subtotal']) ?></span></div>
        <?php if ($qt['discount'] > 0): ?>
        <div class="qv-totals-row is-discount"><span>Discount</span><span>− <?= $peso($qt['discount']) ?></span></div>
        <?php endif; ?>
        <div class="qv-totals-row is-grand"><span>Total</span><span><?= $peso($qt['total']) ?></span></div>
      </div>
    </section>

    <?php if ($qt['notes']): ?>
    <section class="card qv-notes">
      <div class="card-header">
        <div class="card-icon"><?= icon('document', 16) ?></div>
        <div><div class="card-title">Notes</div></div>
      </div>
      <div class="qv-notes-body"><?= htmlspecialchars($qt['notes']) ?></div>
    </section>
    <?php endif; ?>
  </div>

  <!-- RIGHT: who it is for, the job, and the money -->
  <aside class="qv-side">

    <section class="card qv-client">
      <div class="qv-client-top">
        <div class="qv-avatar" aria-hidden="true"><?= htmlspecialchars(mb_strtoupper($initials)) ?></div>
        <div style="min-width:0;">
          <div class="qv-client-name"><?= htmlspecialchars($qt['full_name']) ?></div>
          <div class="qv-client-sub"><?= htmlspecialchars($qt['contact_number'] ?: 'No contact number') ?></div>
        </div>
        <a href="../clients/view_client.php?id=<?= $qt['client_id'] ?>" class="btn-sm-gold qv-client-link"><?= icon('eye', 13) ?> View</a>
      </div>
      <div class="qv-vehicle">
        <span class="qv-plate"><?= htmlspecialchars($qt['plate_number']) ?></span>
        <div>
          <div class="qv-vehicle-name"><?= htmlspecialchars($vehicle ?: '—') ?></div>
          <div class="qv-client-sub"><?= htmlspecialchars($qt['color'] ?: 'Color not recorded') ?></div>
        </div>
      </div>
      <dl class="qv-dl">
        <div><dt>Repair ID</dt><dd><a href="../repair/view_repair.php?id=<?= $qt['job_id'] ?>"><?= htmlspecialchars($qt['job_number']) ?></a></dd></div>
        <div><dt>Service</dt><dd><?= htmlspecialchars($svc) ?></dd></div>
        <div><dt>Repair Date</dt><dd><?= date('M d, Y', strtotime($qt['repair_date'])) ?></dd></div>
      </dl>
    </section>

    <?php if ($qt['receipt_id']): $pc = receipt_status_badge((string)$qt['payment_status']); ?>
    <section class="card qv-pay">
      <div class="card-header">
        <div class="card-icon qv-icon-success"><?= icon('document-text', 16) ?></div>
        <div>
          <div class="card-title">Billing Statement</div>
          <div class="card-sub"><?= htmlspecialchars($qt['receipt_number']) ?> · Issued <?= date('M d, Y', strtotime($qt['issued_at'])) ?></div>
        </div>
        <span class="badge <?= $pc['class'] ?>" style="margin-left:auto;"><?= htmlspecialchars($pc['label']) ?></span>
      </div>
      <div class="qv-pay-body">
        <div class="qv-pay-figure">
          <span class="qv-pay-paid"><?= $peso($qt['amount_paid']) ?></span>
          <span class="qv-pay-of">paid of <?= $peso($qt['total']) ?></span>
        </div>
        <div class="qv-bar" role="progressbar" aria-valuenow="<?= $paid_pct ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Paid">
          <span style="width:<?= $paid_pct ?>%"></span>
        </div>
        <dl class="qv-dl">
          <div><dt>Balance</dt><dd class="<?= $qt['receipt_balance'] > 0 ? 'is-warning' : 'is-success' ?>"><?= $peso($qt['receipt_balance']) ?></dd></div>
          <div><dt>Payment Method</dt><dd><?= htmlspecialchars($methods[$qt['payment_method']] ?? ucwords(str_replace('_', ' ', (string)$qt['payment_method']))) ?></dd></div>
        </dl>

        <?php if ($is_admin && $qt['payment_status'] !== 'paid'): ?>
        <form method="POST" class="qv-pay-form">
          <?= csrf_field() ?><input type="hidden" name="action" value="update_payment"/>
          <div class="qv-kicker">Update Payment</div>
          <div class="qv-pay-fields">
            <div class="field">
              <label class="field-label" for="upd-amount">Amount Paid</label>
              <input type="number" id="upd-amount" name="amount_paid" class="field-input" data-money value="<?= $qt['amount_paid'] ?>" min="0" step="0.01" max="<?= $qt['total'] ?>" required/>
            </div>
            <div class="field">
              <label class="field-label" for="upd-method">Method</label>
              <select id="upd-method" name="payment_method" class="field-select">
                <?php foreach ($methods as $mv => $ml): ?>
                <option value="<?= $mv ?>"<?= $qt['payment_method'] === $mv ? ' selected' : '' ?>><?= $ml ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <button type="submit" class="btn-primary qv-full"><?= icon('check', 13) ?> Update Payment</button>
        </form>
        <?php endif; ?>
      </div>
    </section>
    <?php elseif ($status !== 'cancelled'): ?>
    <section class="card qv-pay qv-pay-empty">
      <div class="qv-pay-empty-icon"><?= icon('document-text', 20) ?></div>
      <div class="qv-pay-empty-title">No billing statement yet</div>
      <div class="qv-client-sub">
        <?= $status === 'approved' ? 'The client approved this quotation. Convert it to a billing statement to record the payment.' : 'A billing statement can be issued once the client approves the quotation.' ?>
      </div>
      <?php if ($is_admin && $status === 'approved'): ?>
      <button type="button" class="btn-primary qv-full js-open-convert"><?= icon('document-text', 13) ?> Convert to Billing Statement</button>
      <?php endif; ?>
    </section>
    <?php endif; ?>

  </aside>
</div>

</div>
</div>

<?php if ($is_admin && $qt['status'] === 'approved' && !$qt['receipt_id']): ?>
<!-- CONVERT TO RECEIPT MODAL -->
<div id="convert-modal" class="qv-modal" role="dialog" aria-modal="true" aria-labelledby="convert-title" style="display:none;">
  <div class="qv-modal-box">
    <div class="qv-modal-title" id="convert-title"><?= icon('document-text', 18) ?> Convert to Billing Statement</div>
    <div class="qv-modal-sub">Total: <strong>₱<?= number_format((float)$qt['total'], 2) ?></strong></div>
    <form method="POST">
      <?= csrf_field() ?><input type="hidden" name="action" value="convert"/>
      <div class="qv-modal-fields">
        <div class="field">
          <label class="field-label" for="cv-amount">Amount Paid <span class="req">*</span></label>
          <input type="number" id="cv-amount" name="amount_paid" class="field-input" data-money value="<?= $qt['total'] ?>" min="0" step="0.01" max="<?= $qt['total'] ?>" required/>
        </div>
        <div class="field">
          <label class="field-label" for="cv-method">Payment Method</label>
          <select id="cv-method" name="payment_method" class="field-select">
            <option value="cash">Cash</option>
            <option value="e_wallet">E-Wallet</option>
            <option value="bank_transfer">Bank Transfer</option>
          </select>
        </div>
        <div class="field">
          <label class="field-label" for="cv-notes">Statement Notes</label>
          <textarea id="cv-notes" name="receipt_notes" class="field-input" rows="2" maxlength="<?= MAX_NOTE ?>" placeholder="Optional notes on statement..." style="resize:vertical;"></textarea>
        </div>
      </div>
      <div class="qv-modal-actions">
        <button type="button" class="btn-ghost js-close-convert">Cancel</button>
        <button type="submit" class="btn-primary"><?= icon('document-text', 13) ?> Issue Billing Statement</button>
      </div>
    </form>
  </div>
</div>
<script>
(function () {
  var modal = document.getElementById('convert-modal');
  var open  = function () { modal.style.display = 'flex'; var f = document.getElementById('cv-amount'); f && f.focus(); };
  var close = function () { modal.style.display = 'none'; };
  document.querySelectorAll('.js-open-convert').forEach(function (b) { b.addEventListener('click', open); });
  document.querySelectorAll('.js-close-convert').forEach(function (b) { b.addEventListener('click', close); });
  modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
})();
</script>
<?php endif; ?>

<script>
// Send for Approval / Mark Approved / Cancel: a SweetAlert confirmation before the form is sent
// (colours come from footer.php's Swal.mixin; a cancel uses the theme's danger colour)
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('form.js-qt-confirm').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      if (form.dataset.confirmed === '1') return;
      e.preventDefault();
      var danger = form.dataset.danger === '1';
      var opts = {
        title: form.dataset.title,
        text: form.dataset.text,
        icon: danger ? 'warning' : 'question',
        showCancelButton: true,
        confirmButtonText: form.dataset.button,
        cancelButtonText: danger ? 'Keep it' : 'Not yet',
        reverseButtons: true,
      };
      if (danger) opts.confirmButtonColor = 'var(--danger)';
      Swal.fire(opts).then(function (r) {
        if (!r.isConfirmed) return;
        form.dataset.confirmed = '1';
        form.submit();
      });
    });
  });
});
</script>
<?php require_once '../../includes/footer.php'; ?>
