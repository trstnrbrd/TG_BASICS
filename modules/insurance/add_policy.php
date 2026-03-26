<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header("Location: ../../auth/login.php");
    exit;
}

$full_name = $_SESSION['full_name'];
$initials  = substr(implode('', array_map(fn($w) => strtoupper($w[0]), explode(' ', $full_name))), 0, 2);

// Get vehicle_id from URL
$vehicle_id = isset($_GET['vehicle_id']) ? (int)$_GET['vehicle_id'] : 0;

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

    // Required fields
    $policy_number     = trim($_POST['policy_number'] ?? '');
    $coverage_type     = trim($_POST['coverage_type'] ?? '');
    $sum_insured       = trim($_POST['sum_insured'] ?? '');
    $basic_premium     = trim($_POST['basic_premium'] ?? '');
    $doc_stamps        = trim($_POST['doc_stamps'] ?? '0');
    $lgt               = trim($_POST['lgt'] ?? '0');
    $vat               = trim($_POST['vat'] ?? '0');
    $other_charges     = trim($_POST['other_charges'] ?? '0');
    $total_premium     = trim($_POST['total_premium'] ?? '');
    $participation_fee = trim($_POST['participation_fee'] ?? '0');
    $policy_start      = trim($_POST['policy_start'] ?? '');
    $policy_end        = trim($_POST['policy_end'] ?? '');
    $payment_status    = trim($_POST['payment_status'] ?? 'Unpaid');
    $amount_paid       = trim($_POST['amount_paid'] ?? '0');
    $notes             = trim($_POST['notes'] ?? '');

    // Validation
    if ($policy_number === '')  $errors[] = 'Policy number is required.';
    if ($coverage_type === '')  $errors[] = 'Coverage type is required.';
    if ($sum_insured === '' || !is_numeric($sum_insured)) $errors[] = 'Sum insured must be a valid number.';
    if ($basic_premium === '' || !is_numeric($basic_premium)) $errors[] = 'Basic premium must be a valid number.';
    if ($total_premium === '' || !is_numeric($total_premium)) $errors[] = 'Total premium must be a valid number.';
    if ($policy_start === '') $errors[] = 'Policy start date is required.';
    if ($policy_end === '')   $errors[] = 'Policy end date is required.';
    if ($policy_start !== '' && $policy_end !== '' && $policy_end <= $policy_start)
        $errors[] = 'Policy end date must be after the start date.';

    // Check for duplicate policy number
    if ($policy_number !== '') {
        $dup = $conn->prepare("SELECT policy_id FROM insurance_policies WHERE policy_number = ?");
        $dup->bind_param('s', $policy_number);
        $dup->execute();
        if ($dup->get_result()->num_rows > 0) {
            $errors[] = 'A policy with this policy number already exists.';
        }
    }

    // Compute balance
    $balance = (float)$total_premium - (float)$amount_paid;

    if (empty($errors)) {
        $ins = $conn->prepare("
            INSERT INTO insurance_policies (
                client_id, vehicle_id, policy_number, coverage_type,
                sum_insured, basic_premium, doc_stamps, lgt, vat, other_charges,
                total_premium, participation_fee, policy_start, policy_end,
                payment_status, amount_paid, balance, notes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->bind_param(
            'iissddddddddsssdds',
            $vehicle['client_id'],
            $vehicle_id,
            $policy_number,
            $coverage_type,
            $sum_insured,
            $basic_premium,
            $doc_stamps,
            $lgt,
            $vat,
            $other_charges,
            $total_premium,
            $participation_fee,
            $policy_start,
            $policy_end,
            $payment_status,
            $amount_paid,
            $balance,
            $notes
        );

        if ($ins->execute()) {
            // Audit log
            $log = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'POLICY_CREATED', ?)");
            $desc = ($_SESSION['full_name'] ?? 'Unknown') . ' created policy ' . $policy_number . ' (' . $coverage_type . ') for vehicle ' . ($vehicle['plate_number'] ?? '') . ' — Premium: ₱' . number_format($total_premium, 2) . '.';
            $log->bind_param('is', $_SESSION['user_id'], $desc);
            $log->execute();

            $success = true;
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

    <a href="eligibility_check.php" class="back-link"><?= icon('arrow-left', 14) ?> Back to Eligibility Check</a>

    <div class="page-header">
      <div class="page-header-title"><?= icon('document', 18) ?> Insurance Policy Encoding</div>
      <div class="page-header-sub">Manually encode all policy details from the PhilBritish renewal notice or new policy document.</div>
    </div>

    <?php if ($success): ?>
      <div class="alert alert-success">
        <?= icon('check-circle', 14) ?> Policy successfully saved for <strong><?= htmlspecialchars($vehicle['full_name']) ?></strong> &mdash; <?= htmlspecialchars($vehicle['plate_number']) ?>.
        <a href="eligibility_check.php" style="margin-left:0.5rem;color:var(--success);font-weight:700;">Back to Search &rarr;</a>
      </div>
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
    <form method="POST" action="">
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
            </div>
            <div class="field">
              <label class="field-label">Coverage Type <span class="req">*</span></label>
              <select name="coverage_type" class="field-select">
                <option value="" disabled <?= empty($_POST['coverage_type']) ? 'selected' : '' ?>>Select coverage type</option>
                <?php
                $coverage_options = [
                  'Comprehensive',
                  'Loss and/or Damage',
                  'Auto Personal Accident',
                  'Excess Third Party Bodily Injury',
                  'Excess Third Party Liability Property Damage',
                  'Third Party Liability Only',
                  'Compulsory Third Party Liability (CTPL)',
                ];
                foreach ($coverage_options as $co):
                  $sel = (($_POST['coverage_type'] ?? '') === $co) ? 'selected' : '';
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
              <label class="field-label">Policy Start Date <span class="req">*</span></label>
              <input type="date" name="policy_start" class="field-input"
                value="<?= htmlspecialchars($_POST['policy_start'] ?? '') ?>"/>
            </div>
            <div class="field">
              <label class="field-label">Policy End Date <span class="req">*</span></label>
              <input type="date" name="policy_end" class="field-input"
                value="<?= htmlspecialchars($_POST['policy_end'] ?? '') ?>"/>
            </div>
          </div>

          <!-- PREMIUM BREAKDOWN -->
          <div class="field-section">Premium Breakdown</div>
          <div style="margin-bottom:0.75rem;">
            <div class="alert alert-info" style="margin-bottom:0;">
              <?= icon('information-circle', 14) ?> Copy these figures directly from the PhilBritish policy document. Leave fields as 0 if not applicable.
            </div>
          </div>
          <div class="form-grid" style="margin-bottom:1rem;">
            <div class="field">
              <label class="field-label">Sum Insured (PHP) <span class="req">*</span></label>
              <input type="number" step="0.01" min="0" name="sum_insured" class="field-input" placeholder="0.00"
                value="<?= htmlspecialchars($_POST['sum_insured'] ?? '') ?>"/>
            </div>
            <div class="field">
              <label class="field-label">Basic Premium (PHP) <span class="req">*</span></label>
              <input type="number" step="0.01" min="0" name="basic_premium" class="field-input" placeholder="0.00"
                value="<?= htmlspecialchars($_POST['basic_premium'] ?? '') ?>"/>
            </div>
            <div class="field">
              <label class="field-label">Doc Stamps (PHP)</label>
              <input type="number" step="0.01" min="0" name="doc_stamps" class="field-input" placeholder="0.00"
                value="<?= htmlspecialchars($_POST['doc_stamps'] ?? '0') ?>"/>
            </div>
            <div class="field">
              <label class="field-label">LGT (PHP)</label>
              <input type="number" step="0.01" min="0" name="lgt" class="field-input" placeholder="0.00"
                value="<?= htmlspecialchars($_POST['lgt'] ?? '0') ?>"/>
            </div>
            <div class="field">
              <label class="field-label">VAT (PHP)</label>
              <input type="number" step="0.01" min="0" name="vat" class="field-input" placeholder="0.00"
                value="<?= htmlspecialchars($_POST['vat'] ?? '0') ?>"/>
            </div>
            <div class="field">
              <label class="field-label">Other Charges (PHP)</label>
              <input type="number" step="0.01" min="0" name="other_charges" class="field-input" placeholder="0.00"
                value="<?= htmlspecialchars($_POST['other_charges'] ?? '0') ?>"/>
            </div>
          </div>

          <div class="form-grid" style="margin-bottom:1rem;">
            <div class="field">
              <label class="field-label">Total Premium (PHP) <span class="req">*</span></label>
              <input type="number" step="0.01" min="0" name="total_premium" id="total_premium" class="field-input" placeholder="0.00"
                value="<?= htmlspecialchars($_POST['total_premium'] ?? '') ?>"/>
              <span class="field-hint">This is the final amount the client owes.</span>
            </div>
            <div class="field">
              <label class="field-label">Participation Fee / Deductible (PHP)</label>
              <input type="number" step="0.01" min="0" name="participation_fee" class="field-input" placeholder="0.00"
                value="<?= htmlspecialchars($_POST['participation_fee'] ?? '0') ?>"/>
              <span class="field-hint">Amount the client pays before insurance kicks in (deductible).</span>
            </div>
          </div>

          <!-- PAYMENT -->
          <div class="field-section">Payment Status</div>
          <div class="form-grid" style="margin-bottom:1rem;">
            <div class="field">
              <label class="field-label">Payment Status <span class="req">*</span></label>
              <select name="payment_status" class="field-select" id="payment_status_select">
                <?php foreach (['Unpaid','Partial','Paid'] as $ps): ?>
                <option value="<?= $ps ?>" <?= (($_POST['payment_status'] ?? 'Unpaid') === $ps) ? 'selected' : '' ?>><?= $ps ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label class="field-label">Amount Paid (PHP)</label>
              <input type="number" step="0.01" min="0" name="amount_paid" id="amount_paid" class="field-input" placeholder="0.00"
                value="<?= htmlspecialchars($_POST['amount_paid'] ?? '0') ?>"/>
              <span class="field-hint" id="balance-display" style="color:var(--text-primary);font-weight:600;"></span>
            </div>
          </div>

          <!-- NOTES -->
          <div class="field-section">Additional Notes</div>
          <div class="field" style="margin-bottom:0.5rem;">
            <label class="field-label">Notes (Optional)</label>
            <textarea name="notes" class="field-textarea" placeholder="Any remarks about this policy..."><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
          </div>

        </div>

        <div class="form-actions">
          <a href="eligibility_check.php" class="btn-ghost"><?= icon('arrow-left', 14) ?> Cancel</a>
          <button type="submit" class="btn-primary"><?= icon('floppy-disk', 14) ?> Save Policy</button>
        </div>
      </div>
    </form>

  </div>
</div>

<?php
$footer_scripts = '
  // Live balance computation
  function updateBalance() {
    const total   = parseFloat(document.getElementById("total_premium").value) || 0;
    const paid    = parseFloat(document.getElementById("amount_paid").value) || 0;
    const balance = total - paid;
    const el      = document.getElementById("balance-display");
    if (total > 0) {
      el.textContent = "Balance: PHP " + balance.toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      el.style.color = balance <= 0 ? "var(--success)" : "var(--warning)";
    } else {
      el.textContent = "";
    }
  }

  document.getElementById("total_premium").addEventListener("input", updateBalance);
  document.getElementById("amount_paid").addEventListener("input", updateBalance);
  updateBalance();
';
require_once '../../includes/footer.php';
?>