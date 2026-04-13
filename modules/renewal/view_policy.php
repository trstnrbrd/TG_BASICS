<?php
require_once __DIR__ . "/../../config/session.php";
require_once '../../config/db.php';
require_once '../../config/validators.php';
require_once '../../config/settings.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header("Location: ../../auth/login.php");
    exit;
}

require_once '../../includes/icons.php';

$urg_days = (int)getSetting($conn, 'renewal_urgent_days', '7');
$exp_days = (int)getSetting($conn, 'renewal_expiring_days', '30');

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

// ── HANDLE DELETE ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_policy'])) {
    csrf_verify();
    $with_claims = isset($_POST['delete_claims']) && $_POST['delete_claims'] === '1';

    if ($with_claims) {
        // Delete claim files first
        $upload_dir = __DIR__ . '/../../uploads/claims/';
        $cr_stmt = $conn->prepare("SELECT doc_or_cr_file, doc_drivers_license_file, doc_insurance_policy_file, doc_damage_photos_file, doc_police_report_file FROM claims WHERE policy_id = ?");
        $cr_stmt->bind_param('i', $policy_id);
        $cr_stmt->execute();
        $claim_rows = $cr_stmt->get_result();
        while ($cr = $claim_rows->fetch_assoc()) {
            foreach ($cr as $f) {
                if ($f && file_exists($upload_dir . $f)) unlink($upload_dir . $f);
            }
        }
        $ph_stmt = $conn->prepare("SELECT filename FROM claim_damage_photos cdp INNER JOIN claims c ON cdp.claim_id = c.claim_id WHERE c.policy_id = ?");
        $ph_stmt->bind_param('i', $policy_id);
        $ph_stmt->execute();
        $photos = $ph_stmt->get_result();
        while ($p = $photos->fetch_assoc()) {
            if (file_exists($upload_dir . $p['filename'])) unlink($upload_dir . $p['filename']);
        }
        $del_cl = $conn->prepare("DELETE cl FROM claims cl WHERE cl.policy_id = ?");
        $del_cl->bind_param('i', $policy_id);
        $del_cl->execute();
    }

    $del = $conn->prepare("DELETE FROM insurance_policies WHERE policy_id = ?");
    $del->bind_param('i', $policy_id);
    $del->execute();

    $uid  = $_SESSION['user_id'];
    $log  = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'POLICY_DELETED', ?)");
    $desc = ($_SESSION['full_name'] ?? 'Unknown') . ' deleted policy ' . $policy['policy_number'] . ($with_claims ? ' and all associated claims.' : '.');
    $log->bind_param('is', $uid, $desc);
    $log->execute();

    header("Location: renewal_list.php?msg=" . urlencode("Policy " . $policy['policy_number'] . " deleted."));
    exit;
}

// ── LOAD INSTALLMENTS ──
$installments_res = $conn->prepare("SELECT * FROM policy_payments WHERE policy_id = ? ORDER BY installment_no ASC");
$installments_res->bind_param('i', $policy_id);
$installments_res->execute();
$installments = $installments_res->get_result()->fetch_all(MYSQLI_ASSOC);
$has_installments = count($installments) > 0;

// ── AUTO-SYNC OVERDUE STATUS ──
// If any installment is past due and underpaid, push Overdue to the policy record
if ($has_installments && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $today_check = date('Y-m-d');
    $auto_overdue = false;
    foreach ($installments as $inst) {
        if ($inst['due_date'] && $inst['due_date'] < $today_check && (float)$inst['amount_paid'] < (float)$inst['amount_due']) {
            $auto_overdue = true;
            break;
        }
    }
    $current_status = $policy['payment_status'];
    if ($auto_overdue && $current_status !== 'Paid' && $current_status !== 'Overdue') {
        $ao_upd = $conn->prepare("UPDATE insurance_policies SET payment_status = 'Overdue' WHERE policy_id = ?");
        $ao_upd->bind_param('i', $policy_id);
        $ao_upd->execute();
        $policy['payment_status'] = 'Overdue';
    } elseif (!$auto_overdue && $current_status === 'Overdue') {
        // Recalculate back to correct status
        $total_paid_check = array_sum(array_column($installments, 'amount_paid'));
        $bal_check = (float)$policy['total_premium'] - $total_paid_check;
        $revert = $bal_check <= 0 ? 'Paid' : ($total_paid_check > 0 ? 'Partial' : 'Unpaid');
        $rv_upd = $conn->prepare("UPDATE insurance_policies SET payment_status = ? WHERE policy_id = ?");
        $rv_upd->bind_param('si', $revert, $policy_id);
        $rv_upd->execute();
        $policy['payment_status'] = $revert;
    }
}

// ── HANDLE PAYMENT UPDATE ──
$pay_errors  = [];
$pay_success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_payment'])) {
    csrf_verify();

    if ($has_installments) {
        // Installment-based update
        $raw_amounts  = $_POST['installment_amount'] ?? [];
        $raw_modes    = $_POST['payment_mode']       ?? [];
        $raw_ctrls    = $_POST['control_number']     ?? [];

        // Sanitize arrays
        $amounts      = array_map(fn($v) => san_float($v, 0.0), is_array($raw_amounts) ? $raw_amounts : []);
        $pay_modes    = array_map(fn($v) => san_enum(is_string($v) ? $v : '', ALLOWED_PAYMENT_MODES), is_array($raw_modes) ? $raw_modes : []);
        $ctrl_numbers = array_map(fn($v) => san_str(is_string($v) ? $v : '', 60), is_array($raw_ctrls) ? $raw_ctrls : []);

        $valid = true;
        foreach ($raw_amounts as $k => $v) {
            if (!is_numeric($v) || (float)$v < 0) {
                $pay_errors[] = 'Installment #' . ($k + 1) . ' amount must be a valid non-negative number.';
                $valid = false;
            }
        }
        // Mode of payment required for any installment with amount > 0
        if ($valid) {
            foreach ($installments as $row) {
                $idx      = $row['installment_no'] - 1;
                $amt_paid = $amounts[$idx] ?? 0.0;
                $mode     = $pay_modes[$idx] ?? '';
                if ($amt_paid > 0 && $mode === '') {
                    $pay_errors[] = 'Payment ' . $row['installment_no'] . ' requires a mode of payment.';
                    $valid = false;
                }
            }
        }

        if ($valid) {
            // Pre-check: total across all installments must not exceed total premium
            $check_total = 0;
            foreach ($installments as $row) {
                $check_total += (float)($amounts[$row['installment_no'] - 1] ?? 0);
            }
            if ($check_total > (float)$policy['total_premium']) {
                $pay_errors[] = 'Total amount paid (₱' . number_format($check_total, 2) . ') cannot exceed the total premium (₱' . number_format($policy['total_premium'], 2) . ').';
                $valid = false;
            }
        }

        if ($valid) {
            $total_paid = 0;
            foreach ($installments as $row) {
                $inst_no  = $row['installment_no'];
                $idx      = $inst_no - 1;
                $amt_paid = $amounts[$idx] ?? 0.0;
                $paid_at  = $amt_paid > 0 ? date('Y-m-d H:i:s') : null;
                $pay_mode = !empty($pay_modes[$idx])    ? $pay_modes[$idx]    : null;
                $ctrl_num = !empty($ctrl_numbers[$idx]) ? $ctrl_numbers[$idx] : null;
                $total_paid += $amt_paid;

                $upd_pp = $conn->prepare("UPDATE policy_payments SET amount_paid = ?, paid_at = ?, payment_mode = ?, control_number = ? WHERE payment_id = ?");
                $upd_pp->bind_param('dsssi', $amt_paid, $paid_at, $pay_mode, $ctrl_num, $row['payment_id']);
                $upd_pp->execute();
            }

            $new_balance  = (float)$policy['total_premium'] - $total_paid;
            $today        = date('Y-m-d');
            $has_overdue  = false;
            foreach ($installments as $row) {
                $idx = $row['installment_no'] - 1;
                $amt_paid_check = (float)($amounts[$idx] ?? 0);
                if ($row['due_date'] && $row['due_date'] < $today && $amt_paid_check < (float)$row['amount_due']) {
                    $has_overdue = true;
                    break;
                }
            }
            if ($new_balance <= 0) {
                $new_status = 'Paid';
            } elseif ($has_overdue) {
                $new_status = 'Overdue';
            } elseif ($total_paid > 0) {
                $new_status = 'Partial';
            } else {
                $new_status = 'Unpaid';
            }

            $upd = $conn->prepare("UPDATE insurance_policies SET amount_paid = ?, balance = ?, payment_status = ? WHERE policy_id = ?");
            $upd->bind_param('ddsi', $total_paid, $new_balance, $new_status, $policy_id);

            if ($upd->execute()) {
                $uid  = $_SESSION['user_id'];
                $log  = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'PAYMENT_RECORDED', ?)");
                $desc = ($_SESSION['full_name'] ?? 'Unknown') . ' updated installment payments for policy ' . $policy['policy_number'] . '. Total paid: PHP ' . number_format($total_paid, 2) . '. Status: ' . $new_status . '.';
                $log->bind_param('is', $uid, $desc);
                $log->execute();

                header("Location: view_policy.php?id=" . $policy_id . "&success=" . urlencode("Payment schedule updated successfully."));
                exit;
            } else {
                $pay_errors[] = 'Database error. Please try again.';
            }
        }

    } else {
        // Legacy single-amount update (policies without installment rows)
        $payment_amount = san_float($_POST['payment_amount'] ?? '', 0.0);
        $payment_notes  = san_str($_POST['payment_notes'] ?? '', MAX_TEXT);

        if ($payment_amount <= 0)
            $pay_errors[] = 'Payment amount must be greater than zero.';
        elseif ($payment_amount > (float)$policy['balance'])
            $pay_errors[] = 'Payment amount cannot exceed the remaining balance of PHP ' . number_format($policy['balance'], 2) . '.';

        if (empty($pay_errors)) {
            $new_amount_paid = (float)$policy['amount_paid'] + $payment_amount;
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
                $uid  = $_SESSION['user_id'];
                $log  = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'PAYMENT_RECORDED', ?)");
                $desc = ($_SESSION['full_name'] ?? 'Unknown') . ' recorded payment of PHP ' . number_format($payment_amount, 2) . ' for policy ' . $policy['policy_number'] . '. New balance: PHP ' . number_format($new_balance, 2) . '. Status: ' . $new_status . '.';
                $log->bind_param('is', $uid, $desc);
                $log->execute();

                header("Location: view_policy.php?id=" . $policy_id . "&success=" . urlencode("Payment of PHP " . number_format((float)$payment_amount, 2) . " recorded successfully."));
                exit;
            } else {
                $pay_errors[] = 'Database error. Please try again.';
            }
        }
    }
}

$days     = (int)$policy['days_left'];
$expired  = $policy['policy_end'] < date('Y-m-d');

// Count linked claims
$claims_count_res = $conn->prepare("SELECT COUNT(*) AS cnt FROM claims WHERE policy_id = ?");
$claims_count_res->bind_param('i', $policy_id);
$claims_count_res->execute();
$claims_count = (int)$claims_count_res->get_result()->fetch_assoc()['cnt'];

if ($policy['is_renewed']) {
    $status_label = 'Renewed';
    $status_color = 'var(--info)';
    $status_bg    = 'var(--info-bg, rgba(59,130,246,0.1))';
    $status_border= 'var(--info-border, rgba(59,130,246,0.3))';
    $status_icon  = icon('arrow-path', 16);
} elseif ($expired) {
    $status_label = 'Expired';
    $status_color = 'var(--text-muted)';
    $status_bg    = 'var(--bg-2)';
    $status_border= 'var(--border)';
    $status_icon  = icon('x-mark', 16);
} elseif ($days <= $urg_days) {
    $status_label = 'Urgent - ' . $days . ' day' . ($days !== 1 ? 's' : '') . ' left';
    $status_color = 'var(--danger)';
    $status_bg    = 'var(--danger-bg)';
    $status_border= 'var(--danger-border)';
    $status_icon  = icon('exclamation-triangle', 16);
} elseif ($days <= $exp_days) {
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

    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;flex-wrap:wrap;gap:0.5rem;">
      <a href="renewal_list.php" class="back-link" style="margin-bottom:0;"><?= icon('arrow-left', 14) ?> Back to Renewal Tracking</a>
      <div style="display:flex;gap:0.5rem;align-items:center;">
        <?php if (!$policy['is_renewed'] && ($expired || $days <= $exp_days)): ?>
        <a href="../insurance/add_policy.php?renew_from=<?= $policy_id ?>"
          style="display:inline-flex;align-items:center;gap:0.4rem;background:var(--gold-pale);color:var(--gold-bright);border:1px solid var(--gold-bright);padding:0.45rem 1rem;border-radius:8px;font-size:0.78rem;font-weight:700;cursor:pointer;text-decoration:none;">
          <?= icon('arrow-path', 14) ?> Renew Policy
        </a>
        <?php endif; ?>
        <button type="button" id="delete-policy-btn"
          style="display:inline-flex;align-items:center;gap:0.4rem;background:var(--danger-bg);color:var(--danger);border:1px solid var(--danger-border);padding:0.45rem 1rem;border-radius:8px;font-size:0.78rem;font-weight:700;cursor:pointer;">
          <?= icon('trash', 14) ?> Delete Policy
        </button>
      </div>
    </div>

    <?php if (isset($_GET['success'])): ?>
    <script>document.addEventListener('DOMContentLoaded',function(){ Swal.fire({ toast:true, position:'top-end', icon:'success', title:<?= json_encode($_GET['success']) ?>, showConfirmButton:false, timer:3000, timerProgressBar:true }); });</script>
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
            ['Engine Number',  $policy['motor_number'] ?: 'N/A'],
            ['Chassis Number', $policy['serial_number'] ?: 'N/A'],
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

        <!-- COVERAGE PERIOD + PAYMENT INFO -->
        <div class="field-section">Coverage Period</div>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem 1.5rem;margin-bottom:1.5rem;">
          <?php
          $mortgagee_val = $policy['mortgagee'] ?? '';
          $period_info = [
            ['Starting Date',        date('F d, Y', strtotime($policy['policy_start']))],
            ['Inception Date',       date('F d, Y', strtotime($policy['policy_end']))],
            ['Days Remaining',       $expired ? 'Expired' : $days . ' day' . ($days !== 1 ? 's' : '')],
            ['Payment Terms',        $policy['payment_terms'] ?? '1 time'],
            ['Mortgagee / Financed By', $mortgagee_val ?: null],
          ];
          foreach ($period_info as [$label, $val]): ?>
          <div>
            <div style="font-size:0.62rem;letter-spacing:1.2px;text-transform:uppercase;color:var(--text-muted);font-weight:700;margin-bottom:0.2rem;"><?= $label ?></div>
            <div style="font-size:0.9rem;font-weight:700;color:var(--text-primary);">
              <?= $val ? htmlspecialchars($val) : '<span style="color:var(--text-muted);font-weight:400;">None / Cash</span>' ?>
            </div>
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
            ['Participation Fee', $policy['participation_fee']],
          ];
          foreach ($breakdown as [$label, $amount]):
            if ((float)$amount == 0) continue; ?>
          <div style="display:flex;justify-content:space-between;align-items:center;padding:0.65rem 1rem;border-bottom:1px solid var(--border);">
            <span style="font-size:0.78rem;color:var(--text-secondary);"><?= $label ?></span>
            <span style="font-size:0.82rem;font-weight:600;color:var(--text-primary);">&#8369;<?= number_format((float)$amount, 2) ?></span>
          </div>
          <?php endforeach; ?>
          <!-- TOTAL -->
          <div style="display:flex;justify-content:space-between;align-items:center;padding:0.75rem 1rem;background:var(--sidebar-bg);">
            <span style="font-size:0.82rem;font-weight:700;color:var(--gold-bright);">Total Premium</span>
            <span style="font-size:1rem;font-weight:800;color:var(--gold-bright);">&#8369;<?= number_format($policy['total_premium'], 2) ?></span>
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
        <?php
        $ps = $policy['payment_status'];
        $ps_bg    = $ps === 'Paid' ? 'var(--success-bg)'  : ($ps === 'Partial' ? 'var(--warning-bg)'  : ($ps === 'Overdue' ? 'rgba(230,126,34,0.15)' : 'var(--danger-bg)'));
        $ps_color = $ps === 'Paid' ? 'var(--success)'     : ($ps === 'Partial' ? 'var(--warning)'     : ($ps === 'Overdue' ? '#e67e22'               : 'var(--danger)'));
        ?>
        <span class="badge" style="background:<?= $ps_bg ?>;color:<?= $ps_color ?>;font-weight:700;font-size:0.72rem;padding:0.3rem 0.75rem;border-radius:100px;">
          <?= $policy['payment_status'] ?>
        </span>
      </div>
      <div style="padding:1.5rem;">
        <!-- Top row: Total Premium + Amount Paid -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">
          <div style="background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:1rem;text-align:center;">
            <div style="font-size:0.62rem;letter-spacing:1px;text-transform:uppercase;color:var(--text-muted);font-weight:700;margin-bottom:0.35rem;">Total Premium</div>
            <div style="font-size:1.2rem;font-weight:800;color:var(--text-primary);">&#8369;<?= number_format($policy['total_premium'], 2) ?></div>
          </div>
          <div style="background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:1rem;text-align:center;">
            <div style="font-size:0.62rem;letter-spacing:1px;text-transform:uppercase;color:var(--text-muted);font-weight:700;margin-bottom:0.35rem;">Amount Paid</div>
            <div style="font-size:1.2rem;font-weight:800;color:var(--success);">&#8369;<?= number_format($policy['amount_paid'], 2) ?></div>
          </div>
        </div>
        <!-- Balance — full width -->
        <div style="background:var(--bg);border:2px solid <?= $policy['balance'] > 0 ? 'var(--warning-border)' : 'var(--success-border)' ?>;border-radius:10px;padding:1.25rem 1.5rem;display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;">
          <div style="font-size:0.62rem;letter-spacing:1.2px;text-transform:uppercase;color:var(--text-muted);font-weight:700;">Balance Remaining</div>
          <div style="font-size:1.6rem;font-weight:800;color:<?= $policy['balance'] > 0 ? 'var(--warning)' : 'var(--success)' ?>;">
            <?= $policy['balance'] > 0 ? '&#8369;' . number_format($policy['balance'], 2) : icon('check-circle', 20) . ' Cleared' ?>
          </div>
        </div>

        <?php if ($policy['notes']): ?>
        <div class="info-box" style="margin-bottom:1.25rem;">
          <?= icon('information-circle', 14) ?>
          <span><strong>Notes:</strong> <?= nl2br(htmlspecialchars($policy['notes'])) ?></span>
        </div>
        <?php endif; ?>

        <!-- PAYMENT FORM -->
        <div style="border-top:1px solid var(--border);padding-top:1.25rem;">
          <div class="field-section"><?= icon('banknotes', 14) ?> <?= $has_installments ? 'Payment Schedule' : 'Record Payment' ?></div>

          <?php if (!empty($pay_errors)): ?>
          <script>document.addEventListener('DOMContentLoaded',function(){ Swal.fire({ icon:'error', title:'Payment Error', html:<?= json_encode('<ul style="text-align:left;margin:0;padding-left:1.2rem;">' . implode('', array_map(fn($e) => '<li>' . htmlspecialchars($e) . '</li>', $pay_errors)) . '</ul>') ?>, confirmButtonColor:'#B8860B' }); });</script>
          <?php endif; ?>

          <form method="POST" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="record_payment" value="1"/>

            <?php if ($has_installments): ?>
            <!-- Installment schedule table -->
            <?php $pay_mode_opts = ['Cash','GCash','Maya','Bank Transfer','Check','E-Wallet','Other']; ?>
            <div class="tg-table-wrap" style="margin-bottom:1rem;overflow-x:auto;">
              <table class="tg-table" id="installment-view-table" style="min-width:600px;">
                <thead>
                  <tr>
                    <th style="text-align:center;white-space:nowrap;">Payment</th>
                    <th style="text-align:center;white-space:nowrap;">Due Date</th>
                    <th style="text-align:center;white-space:nowrap;">Mode</th>
                    <th style="text-align:center;white-space:nowrap;">Control No.</th>
                    <th style="text-align:center;white-space:nowrap;">Amount Due</th>
                    <th style="text-align:center;white-space:nowrap;">Amount Paid</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                  $ordinals   = ['1st','2nd','3rd','4th','5th','6th'];
                  $total_inst = count($installments);
                  foreach ($installments as $inst):
                    $idx        = $inst['installment_no'] - 1;
                    $amt_due    = (float)$inst['amount_due'];
                    $amt_paid   = (float)$inst['amount_paid'];
                    $due_date   = $inst['due_date'] ?? '';
                    $paid_at    = $inst['paid_at'] ?? '';
                    $is_locked  = $amt_paid > 0;  // already paid — lock inputs
                    $is_overdue = $due_date && $due_date < date('Y-m-d') && $amt_paid < $amt_due;

                    if ($amt_paid >= $amt_due && $amt_due > 0) {
                        $row_bg = 'background:rgba(34,197,94,0.08);';
                    } elseif ($is_overdue) {
                        $row_bg = 'background:rgba(230,126,34,0.07);';
                    } elseif ($amt_paid > 0) {
                        $row_bg = 'background:rgba(234,179,8,0.06);';
                    } else {
                        $row_bg = '';
                    }

                    $inst_label = $total_inst === 1
                        ? 'Full Payment'
                        : ($ordinals[$idx] ?? ($inst['installment_no'] . 'th')) . ' Payment';

                    // Use POST values on validation failure, otherwise DB values
                    $v_amount = isset($_POST['installment_amount'][$idx]) ? $_POST['installment_amount'][$idx] : number_format($amt_paid, 2, '.', '');
                    $v_mode   = isset($_POST['payment_mode'][$idx])       ? $_POST['payment_mode'][$idx]       : ($inst['payment_mode'] ?? '');
                    $v_ctrl   = isset($_POST['control_number'][$idx])     ? $_POST['control_number'][$idx]     : ($inst['control_number'] ?? '');

                    $lock_attr  = $is_locked ? 'disabled' : '';
                    $lock_style = $is_locked ? 'opacity:0.6;pointer-events:none;background:var(--bg-2);' : '';
                  ?>
                  <tr style="<?= $row_bg ?>" data-due="<?= $amt_due ?>" data-due-date="<?= htmlspecialchars($due_date) ?>">
                    <td style="text-align:center;font-weight:600;white-space:nowrap;"><?= $inst_label ?></td>
                    <td style="text-align:center;white-space:nowrap;">
                      <div style="font-size:0.82rem;"><?= $due_date ? date('M d, Y', strtotime($due_date)) : '—' ?></div>
                      <?php if ($paid_at): ?>
                      <div style="font-size:0.68rem;color:var(--success);font-weight:600;margin-top:0.15rem;">
                        Paid <?= date('M d, Y', strtotime($paid_at)) ?>
                      </div>
                      <?php endif; ?>
                    </td>
                    <td style="text-align:center;">
                      <?php if ($is_locked): ?>
                        <!-- Hidden input to preserve value on POST -->
                        <input type="hidden" name="payment_mode[]" value="<?= htmlspecialchars($v_mode) ?>"/>
                        <span style="font-size:0.82rem;font-weight:600;color:var(--text-primary);"><?= $v_mode ?: '—' ?></span>
                      <?php else: ?>
                        <select name="payment_mode[]" class="field-select" style="width:130px;">
                          <option value="">— Select —</option>
                          <?php foreach ($pay_mode_opts as $m): ?>
                          <option value="<?= $m ?>" <?= $v_mode === $m ? 'selected' : '' ?>><?= $m ?></option>
                          <?php endforeach; ?>
                        </select>
                      <?php endif; ?>
                    </td>
                    <td style="text-align:center;">
                      <?php if ($is_locked): ?>
                        <input type="hidden" name="control_number[]" value="<?= htmlspecialchars($v_ctrl) ?>"/>
                        <span style="font-size:0.82rem;font-weight:600;color:var(--text-primary);"><?= $v_ctrl ?: '—' ?></span>
                      <?php else: ?>
                        <input type="text" name="control_number[]" class="field-input"
                          style="width:130px;" placeholder="Ref / OR No."
                          value="<?= htmlspecialchars($v_ctrl) ?>"/>
                      <?php endif; ?>
                    </td>
                    <td style="text-align:center;font-weight:700;">&#8369;<?= number_format($amt_due, 2) ?></td>
                    <td style="text-align:center;">
                      <?php if ($is_locked): ?>
                        <input type="hidden" name="installment_amount[]" value="<?= htmlspecialchars($v_amount) ?>"/>
                        <span style="font-size:0.9rem;font-weight:700;color:var(--success);">&#8369;<?= number_format($amt_paid, 2) ?></span>
                      <?php else: ?>
                        <input type="number" step="0.01" min="0"
                          name="installment_amount[]"
                          class="field-input inst-paid-input"
                          style="width:110px;text-align:right;"
                          placeholder="0.00"
                          value="<?= htmlspecialchars($v_amount) ?>"
                          data-due="<?= $amt_due ?>"/>
                      <?php endif; ?>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
                <tfoot></tfoot>
              </table>
            </div>

            <?php else: ?>
            <!-- Legacy single-payment form -->
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
            <?php endif; ?>

            <div style="display:flex;gap:0.5rem;justify-content:flex-end;">
              <button type="submit" id="btn-save-payment" class="btn-primary"><?= icon('check-circle', 14) ?> Save Payment</button>
            </div>
          </form>
        </div>
      </div>
    </div>

  </div>
</div>

<!-- Hidden delete form — submitted by Swal -->
<form id="delete-policy-form" method="POST" action="" style="display:none;">
  <?= csrf_field() ?>
  <input type="hidden" name="delete_policy" value="1"/>
  <input type="hidden" name="delete_claims" id="delete-claims-val" value="0"/>
</form>

<?php
$has_inst_js = $has_installments;

if ($has_inst_js) {
    $footer_scripts = '
      (function () {
        const rows = document.querySelectorAll("#installment-view-table tbody tr");

        const today = new Date(); today.setHours(0,0,0,0);

        function refresh() {
          rows.forEach(function (tr) {
            const input = tr.querySelector("input.inst-paid-input");
            if (!input) return; // locked row — skip
            const due   = parseFloat(input.dataset.due) || 0;
            const paid  = parseFloat(input.value) || 0;
            const dueDateStr = tr.dataset.dueDate;
            const dueDate    = dueDateStr ? new Date(dueDateStr) : null;
            const overdue    = dueDate && dueDate < today && paid < due;

            if (paid >= due && due > 0) {
              tr.style.background = "rgba(34,197,94,0.08)";
            } else if (overdue) {
              tr.style.background = "rgba(230,126,34,0.07)";
            } else if (paid > 0) {
              tr.style.background = "rgba(234,179,8,0.06)";
            } else {
              tr.style.background = "";
            }
          });
        }

        rows.forEach(function (tr) {
          tr.querySelector("input.inst-paid-input").addEventListener("input", refresh);
        });
      })();
    ';
} elseif ((float)$policy['balance'] > 0) {
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
<script>
(function () {
  var btn = document.getElementById("delete-policy-btn");
  if (!btn) return;

  var hasClaims   = <?= $claims_count > 0 ? 'true' : 'false' ?>;
  var claimsCount = <?= (int)$claims_count ?>;
  var policyNum   = <?= json_encode($policy['policy_number']) ?>;

  btn.addEventListener("click", function () {
    if (hasClaims) {
      Swal.fire({
        icon: "warning",
        title: "Delete Policy",
        html: "Policy <strong>" + policyNum + "</strong> has <strong>" + claimsCount + " linked claim" + (claimsCount > 1 ? "s" : "") + "</strong>.<br><br>What do you want to do with the claims?",
        showDenyButton: true,
        showCancelButton: true,
        confirmButtonText: "Delete policy & claims",
        denyButtonText: "Delete policy, keep claims",
        cancelButtonText: "Cancel",
        confirmButtonColor: "#c0392b",
        denyButtonColor: "#B8860B",
        cancelButtonColor: "#6b7280"
      }).then(function (result) {
        if (result.isConfirmed) {
          submitDelete("1");
        } else if (result.isDenied) {
          submitDelete("0");
        }
      });
    } else {
      Swal.fire({
        icon: "warning",
        title: "Delete Policy?",
        html: "You are about to permanently delete policy <strong>" + policyNum + "</strong>.<br>This cannot be undone.",
        showCancelButton: true,
        confirmButtonText: "Yes, delete it",
        cancelButtonText: "Cancel",
        confirmButtonColor: "#c0392b",
        cancelButtonColor: "#6b7280"
      }).then(function (result) {
        if (result.isConfirmed) {
          submitDelete("0");
        }
      });
    }
  });

  function submitDelete(withClaims) {
    document.getElementById("delete-claims-val").value = withClaims;
    document.getElementById("delete-policy-form").submit();
  }
})();
</script>

<script>
(function() {
  var form = document.querySelector('form [name="record_payment"]');
  if (!form) return;
  form = form.closest('form');

  form.addEventListener('submit', function(e) {
    var table = document.getElementById('installment-view-table');
    if (!table) return; // non-installment form, no check needed

    var rows = table.querySelectorAll('tbody tr');
    for (var i = 0; i < rows.length; i++) {
      var amtInput  = rows[i].querySelector('input.inst-amount-input');
      var modeSelect = rows[i].querySelector('select[name^="payment_mode"]');
      if (!amtInput || !modeSelect) continue; // locked row — skip

      var amt = parseFloat(amtInput.value) || 0;
      if (amt > 0 && !modeSelect.value) {
        e.preventDefault();
        Swal.fire({
          icon: 'warning',
          title: 'Mode of Payment Required',
          text: 'Please select a mode of payment for every installment that has an amount entered.',
          confirmButtonColor: '#B8860B'
        });
        modeSelect.focus();
        return;
      }
    }
  });
})();
</script>