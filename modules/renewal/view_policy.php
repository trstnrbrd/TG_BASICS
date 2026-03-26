<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header("Location: ../../auth/login.php");
    exit;
}

require_once '../../includes/icons.php';

$policy_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($policy_id === 0) {
    header("Location: renewal_list.php");
    exit;
}

$stmt = $conn->prepare("
    SELECT
        p.*,
        DATEDIFF(p.policy_end, CURDATE()) AS days_left,
        c.full_name, c.contact_number, c.email, c.address,
        v.plate_number, v.make, v.model, v.year_model, v.color,
        v.motor_number, v.serial_number
    FROM insurance_policies p
    INNER JOIN clients c ON p.client_id = c.client_id
    INNER JOIN vehicles v ON p.vehicle_id = v.vehicle_id
    WHERE p.policy_id = ?
");
$stmt->bind_param('i', $policy_id);
$stmt->execute();
$policy = $stmt->get_result()->fetch_assoc();

if (!$policy) {
    header("Location: renewal_list.php");
    exit;
}

// ── HANDLE PAYMENT UPDATE ──
$pay_errors  = [];
$pay_success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_payment'])) {
    $payment_amount = trim($_POST['payment_amount'] ?? '');
    $payment_notes  = trim($_POST['payment_notes'] ?? '');

    if ($payment_amount === '' || !is_numeric($payment_amount))
        $pay_errors[] = 'Payment amount must be a valid number.';
    elseif ((float)$payment_amount <= 0)
        $pay_errors[] = 'Payment amount must be greater than zero.';
    elseif ((float)$payment_amount > (float)$policy['balance'])
        $pay_errors[] = 'Payment amount cannot exceed the remaining balance of PHP ' . number_format($policy['balance'], 2) . '.';

    if (empty($pay_errors)) {
        $new_amount_paid = (float)$policy['amount_paid'] + (float)$payment_amount;
        $new_balance     = (float)$policy['total_premium'] - $new_amount_paid;
        $new_status      = ($new_balance <= 0) ? 'Paid' : 'Partial';

        $new_notes = $policy['notes'];
        if ($payment_notes !== '') {
            $note_entry = '[Payment ' . date('M d, Y') . '] ' . $payment_notes;
            $new_notes  = $new_notes ? $new_notes . "\n" . $note_entry : $note_entry;
        }

        $upd = $conn->prepare("UPDATE insurance_policies SET amount_paid = ?, balance = ?, payment_status = ?, notes = ? WHERE policy_id = ?");
        $upd->bind_param('ddssi', $new_amount_paid, $new_balance, $new_status, $new_notes, $policy_id);

        if ($upd->execute()) {
            // Audit log
            $uid = $_SESSION['user_id'];
            $log = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'PAYMENT_RECORDED', ?)");
            $desc = ($_SESSION['full_name'] ?? 'Unknown') . ' recorded payment of PHP ' . number_format((float)$payment_amount, 2) . ' for policy ' . $policy['policy_number'] . '. New balance: PHP ' . number_format($new_balance, 2) . '. Status: ' . $new_status . '.';
            $log->bind_param('is', $uid, $desc);
            $log->execute();

            header("Location: view_policy.php?id=" . $policy_id . "&success=" . urlencode("Payment of PHP " . number_format((float)$payment_amount, 2) . " recorded successfully."));
            exit;
        } else {
            $pay_errors[] = 'Database error. Please try again.';
        }
    }
}

$days     = (int)$policy['days_left'];
$expired  = $policy['policy_end'] < date('Y-m-d');

if ($expired) {
    $status_label = 'Expired';
    $status_color = 'var(--text-muted)';
    $status_bg    = 'var(--bg-2)';
    $status_border= 'var(--border)';
    $status_icon  = icon('x-mark', 16);
} elseif ($days <= 7) {
    $status_label = 'Urgent - ' . $days . ' day' . ($days !== 1 ? 's' : '') . ' left';
    $status_color = 'var(--danger)';
    $status_bg    = 'var(--danger-bg)';
    $status_border= 'var(--danger-border)';
    $status_icon  = icon('exclamation-triangle', 16);
} elseif ($days <= 30) {
    $status_label = 'Expiring - ' . $days . ' days left';
    $status_color = 'var(--warning)';
    $status_bg    = 'var(--warning-bg)';
    $status_border= 'var(--warning-border)';
    $status_icon  = icon('clock', 16);
} else {
    $status_label = 'Stable';
    $status_color = 'var(--success)';
    $status_bg    = 'var(--success-bg)';
    $status_border= 'var(--success-border)';
    $status_icon  = icon('check-circle', 16);
}

$page_title  = 'View Policy';
$active_page = 'renewal';
$base_path   = '../../';
require_once '../../includes/header.php';
require_once '../../includes/navbar.php';
?>

<div class="main">

<?php
$topbar_title      = 'Policy Details';
$topbar_breadcrumb = ['Insurance', 'Renewal Tracking', 'View Policy'];
require_once '../../includes/topbar.php';
?>

  <div class="content">

    <a href="renewal_list.php" class="back-link"><?= icon('arrow-left', 14) ?> Back to Renewal Tracking</a>

    <?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success"><?= htmlspecialchars($_GET['success']) ?></div>
    <?php endif; ?>

    <!-- POLICY STATUS BANNER -->
    <div style="background:var(--sidebar-bg);border-radius:12px;padding:1.5rem 1.75rem;margin-bottom:1.25rem;position:relative;overflow:hidden;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
      <div style="position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--gold-bright),var(--gold-muted),transparent);"></div>
      <div style="position:relative;z-index:1;">
        <div style="font-size:0.68rem;color:rgba(200,192,176,0.45);letter-spacing:1.5px;text-transform:uppercase;font-weight:600;margin-bottom:0.3rem;">Policy Number</div>
        <div style="font-size:1.3rem;font-weight:800;color:#fff;letter-spacing:-0.3px;margin-bottom:0.2rem;"><?= htmlspecialchars($policy['policy_number']) ?></div>
        <div style="font-size:0.78rem;color:rgba(200,192,176,0.5);"><?= htmlspecialchars($policy['coverage_type']) ?></div>
      </div>
      <div style="position:relative;z-index:1;display:flex;align-items:center;gap:0.6rem;background:<?= $status_bg ?>;border:1px solid <?= $status_border ?>;color:<?= $status_color ?>;padding:0.6rem 1.1rem;border-radius:100px;font-size:0.8rem;font-weight:700;">
        <?= $status_icon ?> <?= $status_label ?>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.25rem;">

      <!-- CLIENT INFO -->
      <div class="card" style="margin-bottom:0;">
        <div class="card-header">
          <div class="card-icon"><?= icon('user', 16) ?></div>
          <div>
            <div class="card-title">Client Information</div>
            <div class="card-sub">Policyholder details</div>
          </div>
          <a href="../clients/view_client.php?id=<?= $policy['client_id'] ?>" class="btn-sm-gold" style="margin-left:auto;">
            <?= icon('eye', 12) ?> View Profile
          </a>
        </div>
        <div style="padding:1.25rem 1.5rem;display:flex;flex-direction:column;gap:0.85rem;">
          <?php
          $client_info = [
            ['Full Name',   $policy['full_name']],
            ['Contact',     $policy['contact_number']],
            ['Email',       $policy['email'] ?: 'Not provided'],
            ['Address',     $policy['address']],
          ];
          foreach ($client_info as [$label, $val]): ?>
          <div>
            <div style="font-size:0.62rem;letter-spacing:1.2px;text-transform:uppercase;color:var(--text-muted);font-weight:700;margin-bottom:0.2rem;"><?= $label ?></div>
            <div style="font-size:0.85rem;font-weight:600;color:var(--text-primary);"><?= htmlspecialchars($val) ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- VEHICLE INFO -->
      <div class="card" style="margin-bottom:0;">
        <div class="card-header">
          <div class="card-icon"><?= icon('vehicle', 16) ?></div>
          <div>
            <div class="card-title">Vehicle Information</div>
            <div class="card-sub">Insured vehicle details</div>
          </div>
        </div>
        <div style="padding:1.25rem 1.5rem;display:flex;flex-direction:column;gap:0.85rem;">
          <?php
          $vehicle_info = [
            ['Plate Number',   $policy['plate_number']],
            ['Make & Model',   $policy['make'] . ' ' . $policy['model'] . ' ' . $policy['year_model']],
            ['Color',          $policy['color'] ?: 'N/A'],
            ['Motor Number',   $policy['motor_number'] ?: 'N/A'],
            ['Serial Number',  $policy['serial_number'] ?: 'N/A'],
          ];
          foreach ($vehicle_info as [$label, $val]): ?>
          <div>
            <div style="font-size:0.62rem;letter-spacing:1.2px;text-transform:uppercase;color:var(--text-muted);font-weight:700;margin-bottom:0.2rem;"><?= $label ?></div>
            <div style="font-size:0.85rem;font-weight:600;color:var(--text-primary);">
              <?php if ($label === 'Plate Number'): ?>
                <span class="badge-dark"><?= htmlspecialchars($val) ?></span>
              <?php else: ?>
                <?= htmlspecialchars($val) ?>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

    </div>

    <!-- POLICY DETAILS -->
    <div class="card" style="margin-bottom:1.25rem;">
      <div class="card-header">
        <div class="card-icon"><?= icon('document', 16) ?></div>
        <div>
          <div class="card-title">Policy Details</div>
          <div class="card-sub">Coverage period and premium breakdown</div>
        </div>
      </div>
      <div style="padding:1.5rem;">

        <!-- COVERAGE PERIOD -->
        <div class="field-section">Coverage Period</div>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:1.5rem;">
          <?php
          $period_info = [
            ['Policy Start',  date('F d, Y', strtotime($policy['policy_start']))],
            ['Policy End',    date('F d, Y', strtotime($policy['policy_end']))],
            ['Days Remaining', $expired ? 'Expired' : $days . ' day' . ($days !== 1 ? 's' : '')],
          ];
          foreach ($period_info as [$label, $val]): ?>
          <div>
            <div style="font-size:0.62rem;letter-spacing:1.2px;text-transform:uppercase;color:var(--text-muted);font-weight:700;margin-bottom:0.2rem;"><?= $label ?></div>
            <div style="font-size:0.9rem;font-weight:700;color:var(--text-primary);"><?= htmlspecialchars($val) ?></div>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- PREMIUM BREAKDOWN -->
        <div class="field-section">Premium Breakdown</div>
        <div style="background:var(--bg);border:1px solid var(--border);border-radius:10px;overflow:hidden;">
          <?php
          $breakdown = [
            ['Sum Insured',       $policy['sum_insured']],
            ['Basic Premium',     $policy['basic_premium']],
            ['Doc Stamps',        $policy['doc_stamps']],
            ['LGT',               $policy['lgt']],
            ['VAT',               $policy['vat']],
            ['Other Charges',     $policy['other_charges']],
            ['Participation Fee', $policy['participation_fee']],
          ];
          foreach ($breakdown as [$label, $amount]):
            if ((float)$amount == 0) continue; ?>
          <div style="display:flex;justify-content:space-between;align-items:center;padding:0.65rem 1rem;border-bottom:1px solid var(--border);">
            <span style="font-size:0.78rem;color:var(--text-secondary);"><?= $label ?></span>
            <span style="font-size:0.82rem;font-weight:600;color:var(--text-primary);">PHP <?= number_format((float)$amount, 2) ?></span>
          </div>
          <?php endforeach; ?>
          <!-- TOTAL -->
          <div style="display:flex;justify-content:space-between;align-items:center;padding:0.75rem 1rem;background:var(--sidebar-bg);">
            <span style="font-size:0.82rem;font-weight:700;color:var(--gold-bright);">Total Premium</span>
            <span style="font-size:1rem;font-weight:800;color:var(--gold-bright);">PHP <?= number_format($policy['total_premium'], 2) ?></span>
          </div>
        </div>

      </div>
    </div>

    <!-- PAYMENT STATUS -->
    <div class="card">
      <div class="card-header" style="justify-content:space-between;">
        <div style="display:flex;align-items:center;gap:0.75rem;">
          <div class="card-icon"><?= icon('receipt', 16) ?></div>
          <div>
            <div class="card-title">Payment Status</div>
            <div class="card-sub">Balance and payment tracking</div>
          </div>
        </div>
        <span class="badge" style="background:<?= $policy['payment_status'] === 'Paid' ? 'var(--success-bg)' : ($policy['payment_status'] === 'Partial' ? 'var(--warning-bg)' : 'var(--danger-bg)') ?>;color:<?= $policy['payment_status'] === 'Paid' ? 'var(--success)' : ($policy['payment_status'] === 'Partial' ? 'var(--warning)' : 'var(--danger)') ?>;font-weight:700;font-size:0.72rem;padding:0.3rem 0.75rem;border-radius:100px;">
          <?= $policy['payment_status'] ?>
        </span>
      </div>
      <div style="padding:1.5rem;">
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:1.25rem;">
          <div style="background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:1rem;text-align:center;">
            <div style="font-size:0.62rem;letter-spacing:1px;text-transform:uppercase;color:var(--text-muted);font-weight:700;margin-bottom:0.35rem;">Total Premium</div>
            <div style="font-size:1.2rem;font-weight:800;color:var(--text-primary);">PHP <?= number_format($policy['total_premium'], 2) ?></div>
          </div>
          <div style="background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:1rem;text-align:center;">
            <div style="font-size:0.62rem;letter-spacing:1px;text-transform:uppercase;color:var(--text-muted);font-weight:700;margin-bottom:0.35rem;">Amount Paid</div>
            <div style="font-size:1.2rem;font-weight:800;color:var(--success);">PHP <?= number_format($policy['amount_paid'], 2) ?></div>
          </div>
          <div style="background:var(--bg);border:1px solid <?= $policy['balance'] > 0 ? 'var(--warning-border)' : 'var(--success-border)' ?>;border-radius:10px;padding:1rem;text-align:center;">
            <div style="font-size:0.62rem;letter-spacing:1px;text-transform:uppercase;color:var(--text-muted);font-weight:700;margin-bottom:0.35rem;">Balance</div>
            <div style="font-size:1.2rem;font-weight:800;color:<?= $policy['balance'] > 0 ? 'var(--warning)' : 'var(--success)' ?>;">
              <?= $policy['balance'] > 0 ? 'PHP ' . number_format($policy['balance'], 2) : icon('check', 16) . ' Cleared' ?>
            </div>
          </div>
        </div>

        <?php if ($policy['notes']): ?>
        <div class="info-box" style="margin-bottom:1.25rem;">
          <?= icon('information-circle', 14) ?>
          <span><strong>Notes:</strong> <?= nl2br(htmlspecialchars($policy['notes'])) ?></span>
        </div>
        <?php endif; ?>

        <?php if ((float)$policy['balance'] > 0): ?>
        <!-- RECORD PAYMENT FORM -->
        <div style="border-top:1px solid var(--border);padding-top:1.25rem;">
          <div class="field-section"><?= icon('banknotes', 14) ?> Record Payment</div>

          <?php if (!empty($pay_errors)): ?>
          <div class="alert alert-danger" style="margin-bottom:1rem;">
            <?php foreach ($pay_errors as $e): ?>
            <div style="font-size:0.78rem;">&#8226; <?= htmlspecialchars($e) ?></div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

          <form method="POST" action="">
            <input type="hidden" name="record_payment" value="1"/>
            <div class="form-grid" style="margin-bottom:1rem;">
              <div class="field">
                <label class="field-label">Payment Amount (PHP) <span class="req">*</span></label>
                <input type="number" step="0.01" min="0.01" max="<?= $policy['balance'] ?>" name="payment_amount" id="payment_amount" class="field-input"
                  placeholder="0.00" value="<?= htmlspecialchars($_POST['payment_amount'] ?? '') ?>"/>
                <span class="field-hint">Remaining balance: PHP <?= number_format($policy['balance'], 2) ?></span>
                <span class="field-hint" id="balance-after" style="font-weight:600;"></span>
              </div>
              <div class="field">
                <label class="field-label">Payment Notes</label>
                <input type="text" name="payment_notes" class="field-input"
                  placeholder="e.g. Cash payment, GCash, etc."
                  value="<?= htmlspecialchars($_POST['payment_notes'] ?? '') ?>"/>
                <span class="field-hint">Optional remarks for this payment.</span>
              </div>
            </div>
            <div style="display:flex;gap:0.5rem;justify-content:flex-end;">
              <button type="submit" class="btn-primary"><?= icon('check-circle', 14) ?> Record Payment</button>
            </div>
          </form>
        </div>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>

<?php
if ((float)$policy['balance'] > 0) {
    $footer_scripts = '
      const payInput = document.getElementById("payment_amount");
      if (payInput) {
        payInput.addEventListener("input", function() {
          const balance = ' . (float)$policy['balance'] . ';
          const payment = parseFloat(this.value) || 0;
          const after = balance - payment;
          const el = document.getElementById("balance-after");
          if (payment > 0 && payment <= balance) {
            el.textContent = "Balance after payment: PHP " + after.toLocaleString("en-PH", {minimumFractionDigits:2, maximumFractionDigits:2});
            el.style.color = after <= 0 ? "var(--success)" : "var(--warning)";
          } else if (payment > balance) {
            el.textContent = "Amount exceeds remaining balance";
            el.style.color = "var(--danger)";
          } else {
            el.textContent = "";
          }
        });
      }
    ';
}
require_once '../../includes/footer.php';
?>