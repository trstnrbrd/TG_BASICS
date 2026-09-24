<?php
require_once __DIR__ . "/../../config/session.php";
require_once '../../config/db.php';
require_once '../../config/validators.php';
require_once '../../config/access.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header("Location: ../../auth/login.php");
    exit;
}

// Edit an existing policy (owner's request, 2026-09-24) — every detail an encoder can get wrong: company,
// policy no., coverage, dates, mortgagee, notes and the premium figures. Same rule as payments: only the
// client's insurance agent or the Owner (policy_editable). Payments already recorded are never touched:
// a new premium/commission only re-splits each installment's amount due, and a new starting date only
// moves the due dates. Payment terms (the number of installments) can change only while nothing is paid.

$policy_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$load = function () use ($conn, $policy_id) {
    $st = $conn->prepare("
        SELECT p.*, c.agent_id, c.full_name, v.plate_number, v.make, v.model, v.year_model
        FROM insurance_policies p
        INNER JOIN clients c  ON c.client_id = p.client_id
        INNER JOIN vehicles v ON v.vehicle_id = p.vehicle_id
        WHERE p.policy_id = ? AND c.deleted_at IS NULL
    ");
    $st->bind_param('i', $policy_id);
    $st->execute();
    return $st->get_result()->fetch_assoc();
};
$policy = $policy_id > 0 ? $load() : null;
if (!$policy || !client_in_scope($conn, (int)$policy['client_id'])) {
    header("Location: renewal_list.php");
    exit;
}
if (!policy_editable($policy['agent_id'] !== null ? (int)$policy['agent_id'] : null)) {
    header("Location: view_policy.php?id=" . $policy_id . "&error=" . urlencode('Only the insurance agent of this client or the Owner can edit this policy.'));
    exit;
}

$companies     = ['PhilBritish', 'Alpha Insurance & Surety Company Inc.'];
$term_options  = ['1 time', '3 months', '4 months', '6 months'];
$terms_map     = ['1 time' => 1, '2 months' => 2, '3 months' => 3, '4 months' => 4, '6 months' => 6, '12 months' => 12];
if (!in_array($policy['payment_terms'], $term_options, true) && isset($terms_map[$policy['payment_terms']])) {
    $term_options[] = $policy['payment_terms'];   // an older policy on a term the form no longer offers
}

$load_installments = function (bool $lock = false) use ($conn, $policy_id) {
    $st = $conn->prepare("SELECT * FROM policy_payments WHERE policy_id = ? ORDER BY installment_no" . ($lock ? " FOR UPDATE" : ""));
    $st->bind_param('i', $policy_id);
    $st->execute();
    return $st->get_result()->fetch_all(MYSQLI_ASSOC);
};
// Payment terms stay fixed once a payment or a receipt is on the schedule (re-building it would lose them)
$terms_locked = function (array $inst): bool {
    if (!$inst) return true;   // older policy without an installment schedule
    foreach ($inst as $r) if ((float)$r['amount_paid'] > 0 || !empty($r['receipt_file'])) return true;
    return false;
};
$installments = $load_installments();
$paid_total   = array_sum(array_map(fn($r) => (float)$r['amount_paid'], $installments));
$is_locked    = $terms_locked($installments);

$errors = [];
$money  = fn($v) => 'PHP ' . number_format((float)$v, 2);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $in = [
        'insurance_company' => san_enum($_POST['insurance_company'] ?? '', $companies),
        'policy_number'     => san_str($_POST['policy_number'] ?? '', MAX_POLICY_NUM),
        'coverage_type'     => san_enum($_POST['coverage_type'] ?? '', ALLOWED_COVERAGE_TYPES),
        'policy_start'      => san_str($_POST['policy_start'] ?? '', 10),
        'policy_end'        => san_str($_POST['policy_end'] ?? '', 10),
        'sum_insured'       => san_money($_POST['sum_insured'] ?? ''),
        'total_premium'     => san_money($_POST['total_premium'] ?? ''),
        'markup'            => san_money($_POST['basic_premium'] ?? '0'),
        'participation_fee' => san_money($_POST['participation_fee'] ?? '0'),
        'payment_terms'     => san_enum($_POST['payment_terms'] ?? '', $term_options),
        'mortgagee'         => san_str($_POST['mortgagee'] ?? '', MAX_MORTGAGEE),
        'notes'             => san_str($_POST['notes'] ?? '', MAX_TEXT),
    ];

    if ($in['insurance_company'] === '')                 $errors[] = 'Please choose the insurance company.';
    if ($in['policy_number'] === '')                     $errors[] = 'Policy number is required.';
    elseif (!validate_policy_number($in['policy_number'])) $errors[] = 'Policy number contains invalid characters.';
    if ($in['coverage_type'] === '')                     $errors[] = 'Coverage type is required or invalid.';
    if (!validate_date($in['policy_start']))             $errors[] = 'Starting date is required and must be a valid date.';
    if (!validate_date($in['policy_end']))               $errors[] = 'Inception date is required and must be a valid date.';
    if (validate_date($in['policy_start']) && validate_date($in['policy_end']) && $in['policy_end'] <= $in['policy_start'])
        $errors[] = 'Inception date must be after the starting date.';
    if ($in['sum_insured'] <= 0)                         $errors[] = 'Sum insured must be a valid positive number.';
    if ($in['total_premium'] <= 0)                       $errors[] = 'Total premium must be a valid positive number.';
    if ($in['total_premium'] > 0 && $in['markup'] > $in['total_premium']) $errors[] = 'Commission cannot exceed the total premium.';
    if ($in['payment_terms'] === '')                     $errors[] = 'Payment terms are invalid.';

    if (empty($errors) && $in['policy_number'] !== $policy['policy_number']) {
        $dup = $conn->prepare("SELECT 1 FROM insurance_policies WHERE policy_number = ? AND policy_id <> ? LIMIT 1");
        $dup->bind_param('si', $in['policy_number'], $policy_id);
        $dup->execute();
        if ($dup->get_result()->num_rows > 0) $errors[] = 'Another policy already uses policy number ' . $in['policy_number'] . '.';
    }

    if (empty($errors)) {
        $conn->begin_transaction();
        try {
            // Re-read under a lock: a payment recorded while this form was open still counts
            $lk = $conn->prepare("SELECT policy_id FROM insurance_policies WHERE policy_id = ? FOR UPDATE");
            $lk->bind_param('i', $policy_id);
            $lk->execute();
            $lk->get_result()->free();   // read the locked row so the connection is free for the next query
            $current = $load();
            if (!$current) throw new DomainException('This policy no longer exists.');
            $inst    = $load_installments(true);
            $paid    = array_sum(array_map(fn($r) => (float)$r['amount_paid'], $inst));
            $locked  = $terms_locked($inst);
            if ($locked) $in['payment_terms'] = $current['payment_terms'];   // a disabled field can't change it

            $payable = round($in['total_premium'] - $in['markup'], 2);
            if ($paid - $payable > 0.005) {
                throw new DomainException('The client has already paid ' . $money($paid) . ', which is more than the new payable of '
                    . $money($payable) . ' (Total Premium − Commission). Undo the extra payment first, or check the figures.');
            }

            // What changed, for the activity log (and to skip a save that changes nothing)
            $labels = [
                'insurance_company' => 'Company', 'policy_number' => 'Policy no.', 'coverage_type' => 'Coverage',
                'policy_start' => 'Starting date', 'policy_end' => 'Inception date', 'sum_insured' => 'Sum insured',
                'total_premium' => 'Total premium', 'markup' => 'Commission', 'participation_fee' => 'Participation fee',
                'payment_terms' => 'Payment terms', 'mortgagee' => 'Mortgagee', 'notes' => 'Notes',
            ];
            $money_fields = ['sum_insured', 'total_premium', 'markup', 'participation_fee'];
            $changes = [];
            foreach ($labels as $k => $label) {
                if (in_array($k, $money_fields, true)) {
                    if (abs((float)$current[$k] - $in[$k]) >= 0.005) $changes[] = "$label: " . $money($current[$k]) . ' → ' . $money($in[$k]);
                } elseif ((string)($current[$k] ?? '') !== $in[$k]) {
                    $changes[] = $k === 'notes' ? 'Notes updated' : "$label: " . (($current[$k] ?? '') !== '' ? $current[$k] : '(none)') . ' → ' . ($in[$k] !== '' ? $in[$k] : '(none)');
                }
            }
            if (!$changes) {
                $conn->rollback();
                header("Location: view_policy.php?id=" . $policy_id . "&success=" . urlencode('No changes to save.'));
                exit;
            }

            // Schedule: same split rule as add_policy.php (last installment absorbs the rounding remainder)
            if ($inst) {
                $n = $terms_map[$in['payment_terms']] ?? count($inst);
                if ($in['payment_terms'] !== $current['payment_terms']) {
                    // Only reachable while nothing is paid and no receipt is attached — rebuild the rows
                    $del = $conn->prepare("DELETE FROM policy_payments WHERE policy_id = ?");
                    $del->bind_param('i', $policy_id);
                    $del->execute();
                    $inst = [];
                    $ins = $conn->prepare("INSERT INTO policy_payments (policy_id, installment_no, due_date, amount_due, amount_paid) VALUES (?, ?, ?, 0, 0)");
                    for ($i = 1; $i <= $n; $i++) {
                        $blank = '2000-01-01';
                        $ins->bind_param('iis', $policy_id, $i, $blank);
                        $ins->execute();
                        $inst[] = ['payment_id' => $conn->insert_id, 'installment_no' => $i, 'amount_paid' => 0];
                    }
                } else {
                    $n = count($inst);
                }
                $per = round($payable / $n, 2);
                $upd_pp = $conn->prepare("UPDATE policy_payments SET due_date = ?, amount_due = ? WHERE payment_id = ?");
                foreach ($inst as $i => $r) {
                    $due = date('Y-m-d', strtotime($in['policy_start'] . ' +' . $i . ' months'));
                    $amt = ($i === $n - 1) ? round($payable - $per * ($n - 1), 2) : $per;
                    $upd_pp->bind_param('sdi', $due, $amt, $r['payment_id']);
                    $upd_pp->execute();
                }
                if (abs((float)$current['total_premium'] - (float)$current['markup'] - $payable) >= 0.005 || $in['payment_terms'] !== $current['payment_terms']) {
                    $changes[] = 'Schedule re-split: ' . $n . ' x ' . $money($per);
                }
            }

            // Totals and status — same rules as saving payments on view_policy.php
            $tot = $conn->prepare("
                SELECT COALESCE(SUM(amount_paid), 0),
                       COALESCE(SUM(CASE WHEN due_date < CURDATE() THEN amount_due  END), 0),
                       COALESCE(SUM(CASE WHEN due_date < CURDATE() THEN amount_paid END), 0)
                FROM policy_payments WHERE policy_id = ?
            ");
            $tot->bind_param('i', $policy_id);
            $tot->execute();
            [$total_paid, $due_past, $paid_past] = array_map('floatval', $tot->get_result()->fetch_row());
            if (!$inst) $total_paid = (float)$current['amount_paid'];   // older policy without a schedule
            $balance = $payable - $total_paid;
            if ($balance <= 0)                               $status = 'Paid';
            elseif ($due_past > 0 && $paid_past < $due_past) $status = 'Overdue';
            elseif ($total_paid > 0)                         $status = 'Partial';
            else                                             $status = 'Unpaid';

            $upd = $conn->prepare("
                UPDATE insurance_policies SET insurance_company = ?, policy_number = ?, coverage_type = ?, policy_start = ?, policy_end = ?,
                       sum_insured = ?, markup = ?, total_premium = ?, participation_fee = ?, payment_terms = ?, mortgagee = ?, notes = ?,
                       amount_paid = ?, balance = ?, payment_status = ?
                WHERE policy_id = ?
            ");
            $upd->bind_param('sssssddddsssddsi',
                $in['insurance_company'], $in['policy_number'], $in['coverage_type'], $in['policy_start'], $in['policy_end'],
                $in['sum_insured'], $in['markup'], $in['total_premium'], $in['participation_fee'], $in['payment_terms'], $in['mortgagee'], $in['notes'],
                $total_paid, $balance, $status, $policy_id);
            $upd->execute();

            $uid  = $_SESSION['user_id'];
            $log  = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'POLICY_UPDATED', ?)");
            $desc = ($_SESSION['full_name'] ?? 'Unknown') . ' edited policy ' . $current['policy_number'] . ' of ' . $current['full_name'] . ' — ' . implode('; ', $changes) . '.';
            $log->bind_param('is', $uid, $desc);
            $log->execute();

            $conn->commit();
            header("Location: view_policy.php?id=" . $policy_id . "&success=" . urlencode('Policy ' . $in['policy_number'] . ' updated.'));
            exit;
        } catch (DomainException $e) {
            $conn->rollback();
            $errors[] = $e->getMessage();
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('[TG-BASICS] edit_policy failed: ' . $e->getMessage());
            $errors[] = 'The policy could not be saved. Please try again.';
        }
    }
}

// Form values: what was typed (after an error) or what is stored
$v = fn(string $post_key, string $col) => $_SERVER['REQUEST_METHOD'] === 'POST' ? (string)($_POST[$post_key] ?? '') : (string)($policy[$col] ?? '');
$n_inst = count($installments);

$page_title  = 'Edit Policy';
$active_page = 'renewal';
$base_path   = '../../';
require_once '../../includes/header.php';
require_once '../../includes/navbar.php';
?>

<div class="main">

<?php
$topbar_title      = 'Edit Policy';
$topbar_breadcrumb = ['Insurance', 'Renewal Tracking', 'Edit Policy'];
require_once '../../includes/topbar.php';
?>

  <div class="content">

    <a href="view_policy.php?id=<?= $policy_id ?>" class="back-link"><?= icon('arrow-left', 14) ?> Back to Policy</a>

    <?php if ($errors): ?>
    <div class="alert alert-danger">
      <?= icon('exclamation-triangle', 16) ?>
      <div><?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e) ?></div><?php endforeach; ?></div>
    </div>
    <?php endif; ?>

    <form method="POST" action="" id="edit-policy-form" autocomplete="off">
      <?= csrf_field() ?>
      <div class="card">
        <div class="card-header">
          <div class="card-icon"><?= icon('pencil', 16) ?></div>
          <div>
            <div class="card-title">Edit Policy <?= htmlspecialchars($policy['policy_number']) ?></div>
            <div class="card-sub"><?= htmlspecialchars($policy['full_name']) ?> &middot; <?= htmlspecialchars($policy['plate_number']) ?> &middot; <?= htmlspecialchars(trim($policy['make'] . ' ' . $policy['model'] . ' ' . $policy['year_model'])) ?></div>
          </div>
        </div>
        <div style="padding:1.5rem;">

          <div class="info-box" style="margin-bottom:1.25rem;">
            <?= icon('information-circle', 16) ?>
            <span>
              <?php if ($paid_total > 0): ?>
              <strong><?= $money($paid_total) ?> is already paid</strong> — recorded payments stay as they are.
              <?php endif; ?>
              Changing the Total Premium or Commission re-splits each installment's amount due; changing the Starting Date moves the due dates.
              Client and vehicle details are edited from the client's profile.
            </span>
          </div>

          <!-- POLICY IDENTIFICATION -->
          <div class="field-section">Policy Identification</div>
          <div class="form-grid" style="margin-bottom:1rem;">
            <div class="field">
              <label class="field-label">Insurance Company <span class="req">*</span></label>
              <select name="insurance_company" class="field-select">
                <?php foreach ($companies as $co): ?>
                <option value="<?= htmlspecialchars($co) ?>" <?= $v('insurance_company', 'insurance_company') === $co ? 'selected' : '' ?>><?= htmlspecialchars($co) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label class="field-label">Policy Number <span class="req">*</span></label>
              <input type="text" name="policy_number" class="field-input" maxlength="<?= MAX_POLICY_NUM ?>" value="<?= htmlspecialchars($v('policy_number', 'policy_number')) ?>"/>
            </div>
            <div class="field">
              <label class="field-label">Coverage Type <span class="req">*</span></label>
              <select name="coverage_type" class="field-select">
                <?php foreach (ALLOWED_COVERAGE_TYPES as $ct): ?>
                <option value="<?= htmlspecialchars($ct) ?>" <?= $v('coverage_type', 'coverage_type') === $ct ? 'selected' : '' ?>><?= htmlspecialchars($ct) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <!-- POLICY PERIOD -->
          <div class="field-section">Policy Period</div>
          <div class="form-grid" style="margin-bottom:1rem;">
            <div class="field">
              <label class="field-label">Starting Date <span class="req">*</span></label>
              <input type="date" name="policy_start" id="policy_start" class="field-input" value="<?= htmlspecialchars($v('policy_start', 'policy_start')) ?>"/>
              <span class="field-hint">Installment due dates follow this date.</span>
            </div>
            <div class="field">
              <label class="field-label">Inception Date <span class="req">*</span></label>
              <input type="date" name="policy_end" id="policy_end" class="field-input" value="<?= htmlspecialchars($v('policy_end', 'policy_end')) ?>"/>
            </div>
          </div>

          <!-- PREMIUM BREAKDOWN -->
          <div class="field-section">Premium Breakdown</div>
          <div class="form-grid" style="margin-bottom:1rem;">
            <div class="field">
              <label class="field-label">Sum Insured (PHP) <span class="req">*</span></label>
              <input type="text" inputmode="decimal" autocomplete="off" name="sum_insured" class="field-input money-input" placeholder="0.00" value="<?= htmlspecialchars($v('sum_insured', 'sum_insured')) ?>"/>
            </div>
            <div class="field">
              <label class="field-label">Total Premium (PHP) <span class="req">*</span></label>
              <input type="text" inputmode="decimal" autocomplete="off" name="total_premium" id="total_premium" class="field-input money-input" placeholder="0.00" value="<?= htmlspecialchars($v('total_premium', 'total_premium')) ?>"/>
            </div>
            <div class="field">
              <label class="field-label">Commission (PHP)</label>
              <input type="text" inputmode="decimal" autocomplete="off" name="basic_premium" id="commission_field" class="field-input money-input" placeholder="0.00" value="<?= htmlspecialchars($v('basic_premium', 'markup')) ?>"/>
            </div>
            <div class="field">
              <label class="field-label">Total Payable (PHP)</label>
              <input type="text" id="total_payable_display" class="field-input" readonly style="background:var(--bg-3);font-weight:700;color:var(--gold);cursor:default;"/>
              <span class="field-hint" id="payable-hint">Total Premium − Commission — split into the installments.</span>
            </div>
            <div class="field">
              <label class="field-label">Participation Fee (PHP)</label>
              <input type="text" inputmode="decimal" autocomplete="off" name="participation_fee" class="field-input money-input" placeholder="0.00" value="<?= htmlspecialchars($v('participation_fee', 'participation_fee')) ?>"/>
            </div>
          </div>

          <!-- PAYMENT TERMS + MORTGAGEE -->
          <div class="field-section">Payment</div>
          <?php
          $ph_banks = ['Toyota Financial Services Philippines','BDO Unibank','BPI (Bank of the Philippine Islands)','Metrobank','PNB (Philippine National Bank)','Land Bank of the Philippines','DBP (Development Bank of the Philippines)','China Bank','Security Bank','UnionBank','RCBC','EastWest Bank','PSBank','AUB (Asia United Bank)','CTBC Bank Philippines','PBCOM','Maybank Philippines','Bank of Commerce','UCPB','Sterling Bank of Asia','Philippine Savings Bank (PSBank)','GCash (GSave)','Maya Bank','Tonik Bank','GoTyme Bank','UNObank','Other'];
          $mort          = $v('mortgagee', 'mortgagee');
          $mort_is_other = $mort !== '' && !in_array($mort, $ph_banks, true);
          $terms_val     = $is_locked ? $policy['payment_terms'] : $v('payment_terms', 'payment_terms');
          ?>
          <div class="form-grid" style="margin-bottom:1rem;">
            <div class="field">
              <label class="field-label">Payment Terms <span class="req">*</span></label>
              <select name="payment_terms" id="payment_terms" class="field-select" <?= $is_locked ? 'disabled' : '' ?> data-installments="<?= $n_inst ?>">
                <?php foreach ($term_options as $pt): ?>
                <option value="<?= htmlspecialchars($pt) ?>" data-n="<?= $terms_map[$pt] ?? 1 ?>" <?= $terms_val === $pt ? 'selected' : '' ?>><?= htmlspecialchars($pt) ?></option>
                <?php endforeach; ?>
              </select>
              <?php if ($is_locked): ?>
              <input type="hidden" name="payment_terms" value="<?= htmlspecialchars($policy['payment_terms']) ?>"/>
              <span class="field-hint"><?= $n_inst ? 'Can\'t be changed once a payment or receipt is recorded — undo the payments first to change it.' : 'This older policy has no installment schedule.' ?></span>
              <?php else: ?>
              <span class="field-hint">Changing it rebuilds the installment schedule (nothing is paid yet).</span>
              <?php endif; ?>
            </div>
            <div class="field">
              <label class="field-label">Mortgagee / Financed By</label>
              <select id="mortgagee_select" class="field-select">
                <option value="">— None / Cash —</option>
                <?php foreach ($ph_banks as $b): ?>
                <option value="<?= htmlspecialchars($b) ?>" <?= ($mort_is_other ? 'Other' : $mort) === $b ? 'selected' : '' ?>><?= htmlspecialchars($b) ?></option>
                <?php endforeach; ?>
              </select>
              <input type="text" id="mortgagee_other_input" class="field-input" placeholder="Type financing company name" maxlength="<?= MAX_MORTGAGEE ?>"
                value="<?= htmlspecialchars($mort_is_other ? $mort : '') ?>" style="margin-top:0.5rem;display:<?= $mort_is_other ? 'block' : 'none' ?>;"/>
              <input type="hidden" name="mortgagee" id="mortgagee_hidden" value="<?= htmlspecialchars($mort) ?>"/>
            </div>
          </div>

          <!-- NOTES -->
          <div class="field-section">Notes</div>
          <div class="field" style="margin-bottom:0.5rem;">
            <textarea name="notes" class="field-textarea" placeholder="Any remarks about this policy..."><?= htmlspecialchars($v('notes', 'notes')) ?></textarea>
          </div>

        </div>
        <div class="form-actions">
          <a href="view_policy.php?id=<?= $policy_id ?>" class="btn-ghost"><?= icon('x-mark', 14) ?> Cancel</a>
          <button type="submit" class="btn-primary"><?= icon('floppy-disk', 14) ?> Save Changes</button>
        </div>
      </div>
    </form>

  </div>
</div>

<?php
$footer_extra_scripts = '<script src="../../assets/js/shared/money_input.js?v=' . filemtime(__DIR__ . '/../../assets/js/shared/money_input.js') . '"></script>' . "\n"
    . '<script>window.EP_PAID = ' . json_encode(round($paid_total, 2)) . ';</script>' . "\n"
    . <<<'EDITPOLICY_SCRIPT'
<script>
(function () {
  var form = document.getElementById("edit-policy-form");
  if (!form) return;
  var num = function (el) { return parseFloat(String(el.value).replace(/,/g, "")) || 0; };
  var peso = function (n) { return "₱" + n.toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  var totalEl = document.getElementById("total_premium"), commEl = document.getElementById("commission_field");
  var payEl = document.getElementById("total_payable_display"), hint = document.getElementById("payable-hint");
  var termsEl = document.getElementById("payment_terms");

  // Live Total Payable + the new per-installment amount; warn if it drops below what's already paid
  function refresh() {
    var payable = num(totalEl) - num(commEl);
    payEl.value = payable > 0 ? peso(payable) : "0.00";
    var opt = termsEl.options[termsEl.selectedIndex];
    var n = termsEl.disabled && +termsEl.dataset.installments > 0 ? +termsEl.dataset.installments : (parseInt(opt && opt.dataset.n, 10) || 1);
    if (payable > 0 && window.EP_PAID - payable > 0.005) {
      hint.textContent = "Lower than the " + peso(window.EP_PAID) + " already paid — this can't be saved.";
      hint.style.color = "var(--danger)";
    } else {
      hint.textContent = payable > 0 ? "Split into " + n + " installment" + (n > 1 ? "s" : "") + " of about " + peso(Math.round(payable / n * 100) / 100) + "." : "Total Premium − Commission — split into the installments.";
      hint.style.color = "";
    }
  }
  [totalEl, commEl].forEach(function (el) { el.addEventListener("input", refresh); });
  termsEl.addEventListener("change", refresh);
  refresh();

  // Mortgagee: bank list + "Other" free text -> hidden field (same as Add Policy)
  (function () {
    var sel = document.getElementById("mortgagee_select"), other = document.getElementById("mortgagee_other_input"), hidden = document.getElementById("mortgagee_hidden");
    function sync() { hidden.value = sel.value === "Other" ? other.value.trim() : sel.value; }
    sel.addEventListener("change", function () { other.style.display = sel.value === "Other" ? "block" : "none"; if (sel.value === "Other") other.focus(); sync(); });
    other.addEventListener("input", sync);
  })();

  // Confirm with the list of changes; changing any peso amount also asks for the transaction PIN
  var labels = { insurance_company: "Company", policy_number: "Policy no.", coverage_type: "Coverage", policy_start: "Starting date",
    policy_end: "Inception date", sum_insured: "Sum insured", total_premium: "Total premium", basic_premium: "Commission",
    participation_fee: "Participation fee", payment_terms: "Payment terms", mortgagee: "Mortgagee", notes: "Notes" };
  var moneyKeys = ["sum_insured", "total_premium", "basic_premium", "participation_fee"];
  var read = function (k) { var el = form.querySelector('[name="' + k + '"]:not([disabled])'); if (!el) return ""; return moneyKeys.indexOf(k) > -1 ? num(el).toFixed(2) : el.value.trim(); };
  var start = {};
  var snap = function () { Object.keys(labels).forEach(function (k) { start[k] = read(k); }); };
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", snap); else snap();

  var confirmed = false;
  form.addEventListener("submit", async function (e) {
    if (confirmed) return;
    e.preventDefault();
    var changed = Object.keys(labels).filter(function (k) { return read(k) !== start[k]; });
    if (!changed.length) {
      Swal.fire({ icon: "info", title: "Nothing changed", text: "Edit a field first, or press Cancel to go back.", confirmButtonColor: "#B8860B" });
      return;
    }
    var list = changed.map(function (k) {
      if (k === "notes") return "• Notes updated";
      var from = start[k], to = read(k);
      if (moneyKeys.indexOf(k) > -1) { from = peso(+from); to = peso(+to); }
      return "• " + labels[k] + ": " + (from || "(none)") + " → " + (to || "(none)");
    }).join("\n");
    var r = await Swal.fire({ icon: "question", title: "Save these changes?", text: list, confirmButtonText: "Yes, save", cancelButtonText: "Review again",
      showCancelButton: true, confirmButtonColor: "#B8860B", cancelButtonColor: "#6c757d", reverseButtons: true,
      customClass: { htmlContainer: "ep-change-list" } });
    if (!r.isConfirmed) return;
    if (changed.some(function (k) { return moneyKeys.indexOf(k) > -1; }) && !(await requirePin())) return;
    confirmed = true;
    form.submit();
  });
})();
</script>
<style>.ep-change-list { white-space: pre-line; text-align: left !important; font-size: 0.85rem !important; }</style>
EDITPOLICY_SCRIPT;
require_once '../../includes/footer.php';
?>
