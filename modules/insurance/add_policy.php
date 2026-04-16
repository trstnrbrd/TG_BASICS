<?php
require_once __DIR__ . "/../../config/session.php";
require_once '../../config/db.php';
require_once '../../config/validators.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header("Location: ../../auth/login.php");
    exit;
}

$full_name = $_SESSION['full_name'];
$initials  = substr(implode('', array_map(fn($w) => strtoupper($w[0]), explode(' ', $full_name))), 0, 2);

// Renewal pre-fill: load existing policy data
$renew_from   = isset($_GET['renew_from']) ? (int)$_GET['renew_from'] : (isset($_POST['renew_from']) ? (int)$_POST['renew_from'] : 0);
$renew_policy = null;

if ($renew_from > 0) {
    $rs = $conn->prepare("SELECT * FROM insurance_policies WHERE policy_id = ?");
    $rs->bind_param('i', $renew_from);
    $rs->execute();
    $renew_policy = $rs->get_result()->fetch_assoc();
}

// Get vehicle_id from URL (or from renew_from policy)
$vehicle_id = isset($_GET['vehicle_id']) ? (int)$_GET['vehicle_id'] : 0;

if ($vehicle_id === 0 && $renew_policy) {
    $vehicle_id = (int)$renew_policy['vehicle_id'];
}

if ($vehicle_id === 0) {
    header("Location: eligibility_check.php");
    exit;
}

// Load vehicle + client info
$stmt = $conn->prepare("
    SELECT c.client_id, c.full_name, c.contact_number, c.address,
           v.vehicle_id, v.plate_number, v.make, v.model,
           v.year_model, v.color, v.motor_number, v.serial_number
    FROM vehicles v
    INNER JOIN clients c ON v.client_id = c.client_id
    WHERE v.vehicle_id = ?
");
$stmt->bind_param('i', $vehicle_id);
$stmt->execute();
$vehicle = $stmt->get_result()->fetch_assoc();

if (!$vehicle) {
    header("Location: eligibility_check.php");
    exit;
}

$errors  = [];
$success = false;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    // Validate receipt file if uploaded (for 1st installment)
    $receipt_file_name = null;
    if (!empty($_FILES['first_receipt']['tmp_name']) && $_FILES['first_receipt']['error'] === UPLOAD_ERR_OK) {
        $rf   = $_FILES['first_receipt'];
        $mime = mime_content_type($rf['tmp_name']);
        if (!in_array($mime, ['image/jpeg','image/png','image/webp','image/gif'])) {
            $errors[] = 'Receipt must be a JPEG, PNG, WEBP, or GIF image.';
        } elseif ($rf['size'] > 5 * 1024 * 1024) {
            $errors[] = 'Receipt image too large (max 5 MB).';
        } else {
            $receipt_file_name = '__pending__';
        }
    }

    // Required fields — sanitized
    $policy_number     = san_str($_POST['policy_number'] ?? '', MAX_POLICY_NUM);
    $coverage_type     = san_enum($_POST['coverage_type'] ?? '', ALLOWED_COVERAGE_TYPES);
    $sum_insured       = san_float($_POST['sum_insured'] ?? '');
    $basic_premium     = san_float($_POST['basic_premium'] ?? ''); // stores markup
    $total_premium     = san_float($_POST['total_premium'] ?? '');
    $payable_amount    = $total_premium + $basic_premium; // total client must pay
    $participation_fee = san_float($_POST['participation_fee'] ?? '0');
    $policy_start      = san_str($_POST['policy_start'] ?? '', 10);
    $policy_end        = san_str($_POST['policy_end'] ?? '', 10);
    $payment_terms     = san_enum($_POST['payment_terms'] ?? '1 time', ALLOWED_PAYMENT_TERMS);
    $mortgagee         = san_str($_POST['mortgagee'] ?? '', MAX_MORTGAGEE);
    $notes             = san_str($_POST['notes'] ?? '', MAX_TEXT);

    // Payment terms → months count
    $terms_map  = ['1 time' => 1, '2 months' => 2, '3 months' => 3, '4 months' => 4, '6 months' => 6, '12 months' => 12];
    $num_months = $terms_map[$payment_terms] ?? 1;

    // Installment amounts + payment details from POST — sanitized
    $raw_amounts = $_POST['installment_amount'] ?? [];
    $raw_modes   = $_POST['installment_mode']   ?? [];
    $raw_ctrls   = $_POST['installment_ctrl']   ?? [];
    $installment_amounts = array_map(fn($v) => san_float($v), is_array($raw_amounts) ? $raw_amounts : []);
    $installment_modes   = array_map(fn($v) => san_enum($v, ALLOWED_PAYMENT_MODES), is_array($raw_modes) ? $raw_modes : []);
    $installment_ctrls   = array_map(fn($v) => san_str($v, 50), is_array($raw_ctrls) ? $raw_ctrls : []);

    // Validation
    if ($policy_number === '')   $errors[] = 'Policy number is required.';
    elseif (!validate_policy_number($policy_number)) $errors[] = 'Policy number contains invalid characters.';
    if ($coverage_type === '')   $errors[] = 'Coverage type is required or invalid.';
    if ($sum_insured <= 0)       $errors[] = 'Sum insured must be a valid positive number.';
    if ($basic_premium < 0)      $errors[] = 'Markup must be a valid number (0 or more).';
    if ($total_premium <= 0)     $errors[] = 'Total premium must be a valid positive number.';
    if ($policy_start === '' || !validate_date($policy_start)) $errors[] = 'Starting date is required and must be a valid date.';
    if ($policy_end === '' || !validate_date($policy_end))     $errors[] = 'Inception date is required and must be a valid date.';
    if ($policy_start !== '' && $policy_end !== '' && $policy_end <= $policy_start)
        $errors[] = 'Inception date must be after the starting date.';

    // Check for duplicate policy number (exclude the old policy being renewed)
    if ($policy_number !== '') {
        if ($renew_from > 0) {
            $dup = $conn->prepare("SELECT policy_id FROM insurance_policies WHERE policy_number = ? AND policy_id != ?");
            $dup->bind_param('si', $policy_number, $renew_from);
        } else {
            $dup = $conn->prepare("SELECT policy_id FROM insurance_policies WHERE policy_number = ?");
            $dup->bind_param('s', $policy_number);
        }
        $dup->execute();
        if ($dup->get_result()->num_rows > 0) {
            $errors[] = 'A policy with this policy number already exists.';
        }
    }

    // Compute total paid and balance from installments
    // Payable = total_premium + markup; installments split against payable
    $amount_paid = 0;
    foreach ($installment_amounts as $amt) {
        $amount_paid += (float)$amt;
    }
    $balance = $payable_amount - $amount_paid;

    // Validate: total paid cannot exceed payable amount
    if (empty($errors) && $payable_amount > 0) {
        if ($amount_paid > $payable_amount) {
            $errors[] = 'Total amount paid (₱' . number_format($amount_paid, 2) . ') cannot exceed the payable amount (₱' . number_format($payable_amount, 2) . ').';
        }
    }

    // Validate: mode of payment required for any installment with amount > 0
    if (empty($errors)) {
        foreach ($installment_amounts as $k => $amt) {
            if ((float)$amt > 0 && empty(trim($installment_modes[$k] ?? ''))) {
                $errors[] = 'Payment ' . ($k + 1) . ' requires a mode of payment.';
            }
        }
    }

    // Determine payment_status — check if any installment is overdue
    $today_str   = date('Y-m-d');
    $has_overdue = false;
    $per_month   = round($payable_amount / $num_months, 2);
    for ($i = 0; $i < $num_months; $i++) {
        $due = date('Y-m-d', strtotime($policy_start . ' +' . $i . ' months'));
        $amt_paid_i = (float)($installment_amounts[$i] ?? 0);
        if ($due < $today_str && $amt_paid_i < $per_month) {
            $has_overdue = true;
            break;
        }
    }
    if ($balance <= 0) {
        $payment_status = 'Paid';
    } elseif ($has_overdue) {
        $payment_status = 'Overdue';
    } elseif ($amount_paid > 0) {
        $payment_status = 'Partial';
    } else {
        $payment_status = 'Unpaid';
    }

    if (empty($errors)) {
        $ins = $conn->prepare("
            INSERT INTO insurance_policies (
                client_id, vehicle_id, policy_number, coverage_type,
                sum_insured, markup, total_premium, participation_fee,
                policy_start, policy_end, payment_terms, mortgagee, payment_status,
                amount_paid, balance, notes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->bind_param(
            'iissddddsssssdds',
            $vehicle['client_id'],
            $vehicle_id,
            $policy_number,
            $coverage_type,
            $sum_insured,
            $basic_premium,
            $total_premium,
            $participation_fee,
            $policy_start,
            $policy_end,
            $payment_terms,
            $mortgagee,
            $payment_status,
            $amount_paid,
            $balance,
            $notes
        );

        if ($ins->execute()) {
            $new_policy_id = $conn->insert_id;

            // Insert installment schedule into policy_payments
            // Each installment = (total_premium + markup) / num_months
            $per_installment = round($payable_amount / $num_months, 2);
            for ($i = 0; $i < $num_months; $i++) {
                $due_date   = date('Y-m-d', strtotime($policy_start . ' +' . $i . ' months'));
                $amt_due    = $per_installment;
                $amt_paid_i = (float)($installment_amounts[$i] ?? 0);
                $paid_at    = $amt_paid_i > 0 ? date('Y-m-d H:i:s') : null;
                $inst_mode  = !empty($installment_modes[$i])  ? trim($installment_modes[$i])  : null;
                $inst_ctrl  = !empty($installment_ctrls[$i])  ? trim($installment_ctrls[$i])  : null;

                $pp = $conn->prepare("INSERT INTO policy_payments (policy_id, installment_no, due_date, amount_due, amount_paid, paid_at, payment_mode, control_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $inst_no = $i + 1;
                $pp->bind_param('iisddsss', $new_policy_id, $inst_no, $due_date, $amt_due, $amt_paid_i, $paid_at, $inst_mode, $inst_ctrl);
                $pp->execute();

                // Save receipt for 1st installment if uploaded
                if ($i === 0 && $receipt_file_name === '__pending__') {
                    $new_pp_id = $conn->insert_id;
                    $rf        = $_FILES['first_receipt'];
                    $mime      = mime_content_type($rf['tmp_name']);
                    $ext_map   = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
                    $ext       = $ext_map[$mime] ?? 'jpg';
                    $fname     = 'rcpt_' . $new_pp_id . '_' . time() . '.' . $ext;
                    $dest      = __DIR__ . '/../../uploads/receipts/' . $fname;
                    if (move_uploaded_file($rf['tmp_name'], $dest)) {
                        $upd_rc = $conn->prepare("UPDATE policy_payments SET receipt_file = ? WHERE payment_id = ?");
                        $upd_rc->bind_param('si', $fname, $new_pp_id);
                        $upd_rc->execute();
                    }
                }
            }

            // Delete old policy when renewed (new policy is the active record)
            if ($renew_from > 0) {
                $del_pp = $conn->prepare("DELETE FROM policy_payments WHERE policy_id = ?");
                $del_pp->bind_param('i', $renew_from);
                $del_pp->execute();
                $del_old = $conn->prepare("DELETE FROM insurance_policies WHERE policy_id = ?");
                $del_old->bind_param('i', $renew_from);
                $del_old->execute();
            }

            // Audit log
            $uid  = $_SESSION['user_id'];
            $action = $renew_from > 0 ? 'POLICY_RENEWED' : 'POLICY_CREATED';
            $log  = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, ?, ?)");
            $desc = ($_SESSION['full_name'] ?? 'Unknown') . ($renew_from > 0 ? ' renewed policy as ' : ' created policy ') . $policy_number . ' (' . $coverage_type . ') for vehicle ' . ($vehicle['plate_number'] ?? '') . ' — Payable: ₱' . number_format($payable_amount, 2) . ' (Premium: ₱' . number_format((float)$total_premium, 2) . ' + Markup: ₱' . number_format((float)$basic_premium, 2) . ').';
            $log->bind_param('iss', $uid, $action, $desc);
            $log->execute();

            header("Location: ../renewal/renewal_list.php?success=" . urlencode("Policy " . $policy_number . " saved successfully for " . ($vehicle['full_name'] ?? '') . "."));
            exit;
        } else {
            $errors[] = 'Database error. Please try again.';
        }
    }
}

$page_title  = 'Add Policy';
$active_page = 'insurance';
$base_path   = '../../';
require_once '../../includes/header.php';
require_once '../../includes/navbar.php';
?>

<div class="main">

 <?php
$topbar_title      = 'Add Insurance Policy';
$topbar_breadcrumb = ['Insurance', 'Add Policy'];
require_once '../../includes/topbar.php';
?>
  <div class="content">

    <?php if ($renew_policy): ?>
    <a href="../renewal/view_policy.php?id=<?= $renew_from ?>" class="back-link"><?= icon('arrow-left', 14) ?> Back to Policy</a>
    <?php else: ?>
    <a href="eligibility_check.php" class="back-link"><?= icon('arrow-left', 14) ?> Back to Eligibility Check</a>
    <?php endif; ?>



    <?php if (!empty($errors)): ?>
      <div class="alert alert-danger">
        <div>
          <div style="font-weight:700;margin-bottom:0.35rem;">Please fix the following:</div>
          <?php foreach ($errors as $e): ?>
            <div style="font-size:0.78rem;">&#8226; <?= htmlspecialchars($e) ?></div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <!-- VEHICLE SUMMARY -->
    <div class="card" style="margin-bottom:1.25rem;">
      <div class="card-header">
        <div class="card-icon"><?= icon('vehicle', 16) ?></div>
        <div>
          <div class="card-title">Vehicle Being Insured</div>
          <div class="card-sub">Confirm this is the correct vehicle before encoding</div>
        </div>
      </div>
      <div style="padding:1.25rem 1.5rem;display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;">
        <?php
        $vinfo = [
          ['Client',       $vehicle['full_name']],
          ['Plate Number', $vehicle['plate_number']],
          ['Vehicle',      $vehicle['make'] . ' ' . $vehicle['model'] . ' ' . $vehicle['year_model']],
          ['Color',        $vehicle['color'] ?: 'N/A'],
        ];
        foreach ($vinfo as $vi): ?>
        <div>
          <div style="font-size:0.62rem;letter-spacing:1.2px;text-transform:uppercase;color:var(--text-muted);font-weight:700;margin-bottom:0.25rem;"><?= $vi[0] ?></div>
          <div style="font-size:0.85rem;font-weight:700;color:var(--text-primary);"><?= htmlspecialchars($vi[1]) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- POLICY FORM -->
    <form method="POST" action="" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <div class="card">
        <div class="card-header">
          <div class="card-icon"><?= icon('document', 16) ?></div>
          <div>
            <div class="card-title">Policy Details</div>
            <div class="card-sub">Fields marked <span style="color:var(--gold-bright);">*</span> are required</div>
          </div>
        </div>
        <div style="padding:1.5rem;">

          <!-- POLICY IDENTIFICATION -->
          <div class="field-section">Policy Identification</div>
          <div class="form-grid" style="margin-bottom:1rem;">
            <div class="field">
              <label class="field-label">Policy Number <span class="req">*</span></label>
              <input type="text" name="policy_number" class="field-input" placeholder="e.g. P-BLC-24-1-10-1002-000823"
                value="<?= htmlspecialchars($_POST['policy_number'] ?? '') ?>"/>
              <?php if ($renew_policy): ?><span class="field-hint">Enter the new policy number from the PhilBritish renewal notice.</span><?php endif; ?>
            </div>
            <div class="field">
              <label class="field-label">Coverage Type <span class="req">*</span></label>
              <select name="coverage_type" class="field-select">
                <option value="" disabled <?= empty($_POST['coverage_type']) ? 'selected' : '' ?>>Select coverage type</option>
                <?php
                $coverage_options = [
                  'Comprehensive',
                  'Comprehensive w/o AON/AOG',
                ];
                foreach ($coverage_options as $co):
                  $prefill_cov = $_POST['coverage_type'] ?? ($renew_policy['coverage_type'] ?? '');
                  $sel = ($prefill_cov === $co) ? 'selected' : '';
                ?>
                <option value="<?= $co ?>" <?= $sel ?>><?= $co ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <!-- POLICY PERIOD -->
          <div class="field-section">Policy Period</div>
          <div class="form-grid" style="margin-bottom:1rem;">
            <div class="field">
              <label class="field-label">Starting Date <span class="req">*</span></label>
              <input type="date" name="policy_start" class="field-input"
                value="<?= htmlspecialchars($_POST['policy_start'] ?? '') ?>"/>
            </div>
            <div class="field">
              <label class="field-label">Inception Date <span class="req">*</span></label>
              <input type="date" name="policy_end" class="field-input"
                value="<?= htmlspecialchars($_POST['policy_end'] ?? '') ?>"/>
            </div>
          </div>

          <!-- PREMIUM BREAKDOWN -->
          <div class="field-section">Premium Breakdown</div>
          <div style="margin-bottom:0.75rem;">
            <div class="alert alert-info" style="margin-bottom:0;">
              <?= icon('information-circle', 14) ?> Copy the figures from the PhilBritish policy document. The client pays Total Premium + Markup.
            </div>
          </div>
          <div class="form-grid" style="margin-bottom:1rem;">
            <div class="field">
              <label class="field-label">Sum Insured (PHP) <span class="req">*</span></label>
              <input type="number" step="0.01" min="0" name="sum_insured" class="field-input" placeholder="0.00"
                value="<?= htmlspecialchars($_POST['sum_insured'] ?? ($renew_policy['sum_insured'] ?? '')) ?>"/>
              <span class="field-hint">Coverage amount from the PhilBritish policy.</span>
            </div>
            <div class="field">
              <label class="field-label">Total Premium (PHP) <span class="req">*</span></label>
              <input type="number" step="0.01" min="0" name="total_premium" id="total_premium" class="field-input" placeholder="0.00"
                value="<?= htmlspecialchars($_POST['total_premium'] ?? ($renew_policy['total_premium'] ?? '')) ?>"/>
              <span class="field-hint">Premium amount from PhilBritish.</span>
            </div>
            <div class="field">
              <label class="field-label">Markup (PHP)</label>
              <input type="number" step="0.01" min="0" name="basic_premium" id="markup_field" class="field-input" placeholder="0.00"
                value="<?= htmlspecialchars($_POST['basic_premium'] ?? ($renew_policy['markup'] ?? '0')) ?>"/>
              <span class="field-hint">Additional charge on top of premium paid by client.</span>
            </div>
            <div class="field">
              <label class="field-label">Total Payable (PHP)</label>
              <input type="text" id="total_payable_display" class="field-input" placeholder="0.00" readonly
                style="background:var(--bg-3);font-weight:700;color:var(--gold);cursor:default;"/>
              <span class="field-hint">Total Premium + Markup — amount split into installments.</span>
            </div>
          </div>
          <div class="form-grid" style="margin-bottom:1rem;">
            <div class="field">
              <label class="field-label">Participation Fee (PHP)</label>
              <input type="number" step="0.01" min="0" name="participation_fee" class="field-input" placeholder="0.00"
                value="<?= htmlspecialchars($_POST['participation_fee'] ?? ($renew_policy['participation_fee'] ?? '0')) ?>"/>
              <span class="field-hint">Sedan: ₱2,000 &nbsp;|&nbsp; SUV/Van/Pickup: ₱3,000.</span>
            </div>
          </div>

          <!-- PAYMENT SCHEDULE -->
          <div class="field-section">Payment Schedule</div>
          <?php
          $ph_banks = ['BDO Unibank','BPI (Bank of the Philippine Islands)','Metrobank','PNB (Philippine National Bank)','Land Bank of the Philippines','DBP (Development Bank of the Philippines)','China Bank','Security Bank','UnionBank','RCBC','EastWest Bank','PSBank','AUB (Asia United Bank)','CTBC Bank Philippines','PBCOM','Maybank Philippines','Bank of Commerce','UCPB','Sterling Bank of Asia','Philippine Savings Bank (PSBank)','GCash (GSave)','Maya Bank','Tonik Bank','GoTyme Bank','UNObank','Other'];
          ?>
          <div class="form-grid" style="margin-bottom:1rem;">
            <div class="field">
              <label class="field-label">Payment Terms <span class="req">*</span></label>
              <select name="payment_terms" id="payment_terms" class="field-select">
                <?php
                $prefill_terms = $_POST['payment_terms'] ?? ($renew_policy['payment_terms'] ?? '1 time');
                foreach (['1 time', '3 months', '4 months', '6 months'] as $pt): ?>
                <option value="<?= $pt ?>" <?= ($prefill_terms === $pt) ? 'selected' : '' ?>><?= $pt ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label class="field-label">Mortgagee / Financed By</label>
              <select name="mortgagee" class="field-select">
                <option value="">— None / Cash —</option>
                <?php
                $prefill_mort = $_POST['mortgagee'] ?? ($renew_policy['mortgagee'] ?? '');
                foreach ($ph_banks as $b): ?>
                <option value="<?= $b ?>" <?= ($prefill_mort === $b) ? 'selected' : '' ?>><?= $b ?></option>
                <?php endforeach; ?>
              </select>
              <span class="field-hint">Bank that financed this vehicle, if any.</span>
            </div>
          </div>

          <!-- INSTALLMENT TABLE -->
          <div id="installment-wrap" style="margin-bottom:1rem;">
            <table class="tg-table" id="installment-table">
              <thead>
                <tr>
                  <th>Payment</th>
                  <th>Due Date</th>
                  <th>Mode</th>
                  <th>Control No.</th>
                  <th>Amount Due</th>
                  <th>Amount Paid</th>
                  <th>Receipt</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody id="installment-body"></tbody>
              <tfoot>
                <tr>
                  <td colspan="3" style="text-align:right;font-weight:700;padding:0.6rem 1rem;">Balance:</td>
                  <td colspan="2" id="balance-display" style="font-weight:800;font-size:0.95rem;padding:0.6rem 1rem;"></td>
                </tr>
              </tfoot>
            </table>
          </div>

          <!-- NOTES -->
          <div class="field-section">Additional Notes</div>
          <div class="field" style="margin-bottom:0.5rem;">
            <label class="field-label">Notes (Optional)</label>
            <textarea name="notes" class="field-textarea" placeholder="Any remarks about this policy..."><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
          </div>
          <?php if ($renew_from > 0): ?>
          <input type="hidden" name="renew_from" value="<?= $renew_from ?>"/>
          <?php endif; ?>

        </div>

        <div class="form-actions">
          <?php if ($renew_policy): ?>
          <a href="../renewal/view_policy.php?id=<?= $renew_from ?>" class="btn-ghost"><?= icon('arrow-left', 14) ?> Cancel</a>
          <?php else: ?>
          <a href="eligibility_check.php" class="btn-ghost"><?= icon('arrow-left', 14) ?> Cancel</a>
          <?php endif; ?>
          <button type="submit" class="btn-primary"><?= icon('floppy-disk', 14) ?> <?= $renew_policy ? 'Save Renewed Policy' : 'Save Policy' ?></button>
        </div>
      </div>
    </form>

  </div>
</div>

<?php
$footer_scripts = '
  (function () {
    const termsEl       = document.getElementById("payment_terms");
    const totalEl       = document.getElementById("total_premium");
    const markupEl      = document.getElementById("markup_field");
    const payableEl     = document.getElementById("total_payable_display");
    const startEl       = document.querySelector("[name=\'policy_start\']");
    const tbody         = document.getElementById("installment-body");
    const balanceEl     = document.getElementById("balance-display");

    const termsMap = { "1 time": 1, "3 months": 3, "4 months": 4, "6 months": 6 };

    function addMonths(dateStr, months) {
      if (!dateStr) return "";
      const d = new Date(dateStr);
      d.setMonth(d.getMonth() + months);
      return d.toISOString().split("T")[0];
    }

    function fmt(n) {
      return "₱" + n.toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function getPayable() {
      const total  = parseFloat(totalEl.value)  || 0;
      const markup = parseFloat(markupEl.value) || 0;
      return total + markup;
    }

    function updatePayableDisplay() {
      const payable = getPayable();
      payableEl.value = payable > 0 ? fmt(payable) : "0.00";
    }

    function buildTable() {
      const terms     = termsEl.value;
      const numMonths = termsMap[terms] || 1;
      const payable   = getPayable();
      const start     = startEl ? startEl.value : "";

      updatePayableDisplay();
      tbody.innerHTML = "";

      const perInstallment = payable > 0 ? Math.round((payable / numMonths) * 100) / 100 : 0;
      const ordinals = ["1st","2nd","3rd","4th","5th","6th"];

      for (let i = 0; i < numMonths; i++) {
        const dueDate    = addMonths(start, i);
        const amtDue     = perInstallment;
        const prefillAmt = 0;
        const tr        = document.createElement("tr");
        tr.dataset.idx  = i;

        const label = numMonths === 1
          ? "Full Payment"
          : (ordinals[i] || (i + 1) + "th") + " Payment";

        const modeOpts = ["Cash","Bank Transfer","Check","E-Wallet","Other"];
        const modeSelect = "<select name=\"installment_mode[]\" class=\"field-select\" style=\"width:120px;\">" +
          "<option value=\"\">— Select —</option>" +
          modeOpts.map(function(m){ return "<option value=\"" + m + "\">" + m + "</option>"; }).join("") +
          "</select>";

        const receiptCell = i === 0
          ? "<td style=\"text-align:center;\"><label style=\"display:inline-flex;align-items:center;gap:0.3rem;background:var(--bg-2);border:1px dashed var(--border);border-radius:6px;padding:0.3rem 0.6rem;cursor:pointer;font-size:0.72rem;color:var(--text-muted);white-space:nowrap;\"><svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 20 20\" fill=\"currentColor\" width=\"12\" height=\"12\"><path fill-rule=\"evenodd\" d=\"M15.621 4.379a3 3 0 0 0-4.242 0l-7 7a3 3 0 0 0 4.241 4.243h.001l.497-.5a.75.75 0 0 1 1.064 1.057l-.498.501-.002.002a4.5 4.5 0 0 1-6.364-6.364l7-7a4.5 4.5 0 0 1 6.368 6.36l-3.455 3.553A2.625 2.625 0 1 1 9.52 9.52l3.45-3.451a.75.75 0 1 1 1.061 1.06l-3.45 3.451a1.125 1.125 0 0 0 1.587 1.595l3.454-3.553a3 3 0 0 0 0-4.242Z\" clip-rule=\"evenodd\"/></svg> Attach<input type=\"file\" name=\"first_receipt\" accept=\"image/*\" style=\"display:none;\"/></label></td>"
          : "<td style=\"text-align:center;\"><span style=\"color:var(--text-muted);font-size:0.72rem;\">—</span></td>";

        tr.innerHTML =
          "<td style=\"text-align:center;font-weight:600;white-space:nowrap;\">" + label + "</td>" +
          "<td style=\"text-align:center;\">" + (dueDate || "—") + "</td>" +
          "<td style=\"text-align:center;\">" + modeSelect + "</td>" +
          "<td style=\"text-align:center;\"><input type=\"text\" name=\"installment_ctrl[]\" class=\"field-input\" style=\"width:120px;\" placeholder=\"Ref / OR No.\"/></td>" +
          "<td style=\"text-align:center;\">" + (amtDue > 0 ? fmt(amtDue) : "—") + "</td>" +
          "<td style=\"text-align:center;\"><input type=\"number\" step=\"0.01\" min=\"0\" " +
            "name=\"installment_amount[]\" class=\"field-input inst-amount-input\" " +
            "style=\"width:110px;text-align:right;\" placeholder=\"0.00\" value=\"" + prefillAmt + "\" " +
            "data-due=\"" + amtDue + "\"/></td>" +
          receiptCell +
          "<td style=\"text-align:center;\" class=\"status-cell\"><span class=\"badge badge-red\">Unpaid</span></td>";

        tbody.appendChild(tr);

        tr.querySelector("input.inst-amount-input").addEventListener("input", updateBalance);
      }

      updateBalance();
    }

    function updateBalance() {
      const payable  = getPayable();
      let totalPaid  = 0;

      tbody.querySelectorAll("tr").forEach(function (tr) {
        const input    = tr.querySelector("input.inst-amount-input");
        const statusEl = tr.querySelector(".status-cell");
        const amtDue   = parseFloat(input.dataset.due) || 0;
        const amtPaid  = parseFloat(input.value) || 0;
        totalPaid += amtPaid;

        if (amtPaid >= amtDue && amtDue > 0) {
          tr.style.background = "rgba(34,197,94,0.08)";
          statusEl.innerHTML = "<span class=\"badge badge-green\">Paid</span>";
        } else if (amtPaid > 0) {
          tr.style.background = "rgba(234,179,8,0.06)";
          statusEl.innerHTML = "<span class=\"badge badge-yellow\">Partial</span>";
        } else {
          tr.style.background = "";
          statusEl.innerHTML = "<span class=\"badge badge-red\">Unpaid</span>";
        }
      });

      const balance = payable - totalPaid;
      if (payable > 0) {
        if (totalPaid > payable) {
          balanceEl.textContent = "Exceeds payable amount!";
          balanceEl.style.color = "var(--danger)";
        } else {
          balanceEl.textContent = fmt(balance);
          balanceEl.style.color = balance <= 0 ? "var(--success)" : "var(--warning)";
        }
      } else {
        balanceEl.textContent = "—";
        balanceEl.style.color = "var(--text-muted)";
      }
    }

    termsEl.addEventListener("change", buildTable);
    totalEl.addEventListener("input",  buildTable);
    markupEl.addEventListener("input", buildTable);
    if (startEl)   startEl.addEventListener("change", buildTable);

    buildTable();

    // Form submit validation
    document.querySelector("form").addEventListener("submit", function(e) {
      const rows = tbody.querySelectorAll("tr");
      for (let i = 0; i < rows.length; i++) {
        const input    = rows[i].querySelector("input.inst-amount-input");
        const modeEl   = rows[i].querySelector("select[name=\"installment_mode[]\"]");
        if (!input || !modeEl) continue;
        const amt = parseFloat(input.value) || 0;
        if (amt > 0 && !modeEl.value) {
          e.preventDefault();
          Swal.fire({ icon: "warning", title: "Mode of Payment Required", text: "Please select a mode of payment for every installment that has an amount entered.", confirmButtonColor: "#B8860B" });
          modeEl.focus();
          return;
        }
      }
    });
  })();
';
require_once '../../includes/footer.php';
?>