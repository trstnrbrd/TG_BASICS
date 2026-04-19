<?php
require_once __DIR__ . "/../../config/session.php";
require_once '../../config/db.php';
require_once '../../config/validators.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header("Location: ../../auth/login.php");
    exit;
}

$errors  = [];
$success = '';

// Pre-fill from claim if ?claim_id= passed
$prefill_claim_id = (int)($_GET['claim_id'] ?? 0);
$prefill_claim    = null;
if ($prefill_claim_id) {
    $pf = $conn->prepare("
        SELECT cl.claim_id, cl.incident_date,
               c.full_name, c.client_id,
               ip.policy_number,
               ip.participation_fee,
               v.make, v.model, v.plate_number,
               COALESCE(ins.insurer_name, 'PhilBritish Insurance Corp.') AS insurer_name
        FROM claims cl
        INNER JOIN clients c         ON cl.client_id  = c.client_id
        INNER JOIN insurance_policies ip ON cl.policy_id  = ip.policy_id
        LEFT  JOIN vehicles v        ON ip.vehicle_id = v.vehicle_id
        LEFT  JOIN (SELECT 'PhilBritish Insurance Corp.' AS insurer_name) ins ON 1=1
        WHERE cl.claim_id = ?
    ");
    $pf->bind_param('i', $prefill_claim_id);
    $pf->execute();
    $prefill_claim = $pf->get_result()->fetch_assoc();
}

// ── HANDLE POST ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $claim_id      = (int)($_POST['claim_id']    ?? 0);
    $billed_to     = san_str($_POST['billed_to']     ?? '', 255);
    $incident_date = san_str($_POST['incident_date']  ?? '', 10);
    $repair_date   = san_str($_POST['repair_date']    ?? '', 10);
    $parts_cost    = (float)str_replace(',', '', $_POST['parts_cost']  ?? '0');
    $labor_cost    = (float)str_replace(',', '', $_POST['labor_cost']  ?? '0');
    $other_cost    = (float)str_replace(',', '', $_POST['other_cost']  ?? '0');
    $deductible    = (float)str_replace(',', '', $_POST['deductible']  ?? '0');
    $notes         = san_str($_POST['notes'] ?? '', 1000);

    if ($claim_id === 0) $errors[] = 'A linked claim is required.';
    if ($billed_to === '') $errors[] = 'Billed To (insurance company) is required.';
    if ($parts_cost < 0 || $labor_cost < 0 || $other_cost < 0) $errors[] = 'Costs must be 0 or positive.';
    if ($deductible < 0) $errors[] = 'Deductible must be 0 or positive.';

    // Check no duplicate billing for same claim
    if (empty($errors)) {
        $dup = $conn->prepare("SELECT billing_id FROM billing WHERE claim_id = ?");
        $dup->bind_param('i', $claim_id);
        $dup->execute();
        if ($dup->get_result()->num_rows > 0) {
            $errors[] = 'A billing record already exists for this claim.';
        }
    }

    if (empty($errors)) {
        // Generate billing number: BILL-YYYYMMDD-XXXX
        $seq_stmt = $conn->prepare("SELECT COUNT(*) FROM billing WHERE DATE(created_at) = CURDATE()");
        $seq_stmt->execute();
        $seq_row = $seq_stmt->get_result()->fetch_row();
        $seq     = str_pad(($seq_row[0] + 1), 4, '0', STR_PAD_LEFT);
        $bill_num = 'BILL-' . date('Ymd') . '-' . $seq;

        $ins = $conn->prepare("
            INSERT INTO billing
              (claim_id, billing_number, billed_to, incident_date, repair_date, parts_cost, labor_cost, other_cost, deductible, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $inc_date = $incident_date ?: null;
        $rep_date = $repair_date   ?: null;
        $ins->bind_param(
            'issssddddi',
            $claim_id, $bill_num, $billed_to, $inc_date, $rep_date,
            $parts_cost, $labor_cost, $other_cost, $deductible,
            $_SESSION['user_id']
        );

        if ($ins->execute()) {
            $billing_id = $conn->insert_id;

            $log  = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'BILLING_CREATED', ?)");
            $desc = ($_SESSION['full_name'] ?? 'Unknown') . ' created billing ' . $bill_num . '.';
            $log->bind_param('is', $_SESSION['user_id'], $desc);
            $log->execute();

            header("Location: billing_list.php?success=" . urlencode($bill_num . ' created successfully.'));
            exit;
        } else {
            $errors[] = 'Database error. Please try again.';
        }
    }
}

// ── LOAD APPROVED CLAIMS (for dropdown) ──
$claims_res = $conn->query("
    SELECT cl.claim_id, cl.incident_date,
           c.full_name,
           ip.policy_number,
           v.plate_number, v.make, v.model
    FROM claims cl
    INNER JOIN clients c          ON cl.client_id  = c.client_id
    INNER JOIN insurance_policies ip ON cl.policy_id  = ip.policy_id
    LEFT  JOIN vehicles v         ON ip.vehicle_id = v.vehicle_id
    LEFT  JOIN billing b          ON b.claim_id = cl.claim_id
    WHERE cl.status IN ('loa_received','pending','approved','resolved')
      AND b.billing_id IS NULL
    ORDER BY cl.created_at DESC
");

$page_title  = 'New Billing';
$active_page = 'billing';
$base_path   = '../../';
$extra_css   = '<link rel="stylesheet" href="../../assets/css/shared/billing.css?v=' . @filemtime(__DIR__ . '/../../assets/css/shared/billing.css') . '"/>';
require_once '../../includes/header.php';
require_once '../../includes/navbar.php';
?>

<div class="main">
<?php
$topbar_title      = 'New Billing';
$topbar_breadcrumb = ['Insurance', 'Billing', 'New'];
$topbar_show_clock = true;
require_once '../../includes/topbar.php';
?>

  <div class="content">

    <?php if (!empty($errors)): ?>
    <div class="alert-error" style="margin-bottom:1rem;padding:0.9rem 1.1rem;background:var(--danger-bg);border:1px solid var(--danger);border-radius:10px;color:var(--danger);font-size:0.83rem;">
      <?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e) ?></div><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="">
      <?= csrf_field() ?>

      <div style="display:grid;grid-template-columns:1.5fr 1fr;gap:1.25rem;align-items:start;">

        <!-- LEFT COLUMN -->
        <div style="display:flex;flex-direction:column;gap:1.25rem;">

          <!-- CLAIM LINK -->
          <div class="card" style="margin-bottom:0;">
            <div class="card-header">
              <div class="card-icon"><?= icon('clipboard-list', 16) ?></div>
              <div>
                <div class="card-title">Linked Claim</div>
                <div class="card-sub">Select the approved claim this billing is for</div>
              </div>
            </div>
            <div style="padding:1.25rem;display:flex;flex-direction:column;gap:1rem;">
              <div>
                <label class="field-label">Claim <span style="color:var(--danger)">*</span></label>
                <?php
                $sel_claim = (int)($_POST['claim_id'] ?? $prefill_claim_id);
                ?>
                <select name="claim_id" id="claim_select" class="field-select" required style="width:100%;" onchange="this.form.submit()">
                  <option value="">— Select approved claim —</option>
                  <?php while ($cr = $claims_res->fetch_assoc()): ?>
                  <option value="<?= $cr['claim_id'] ?>" <?= $sel_claim == $cr['claim_id'] ? 'selected' : '' ?>>
                    #<?= $cr['claim_id'] ?> — <?= htmlspecialchars($cr['full_name']) ?>
                    (<?= htmlspecialchars($cr['plate_number'] ?: 'No plate') ?>
                    · <?= htmlspecialchars($cr['policy_number']) ?>)
                  </option>
                  <?php endwhile; ?>
                </select>
                <?php if ($prefill_claim): ?>
                <div style="margin-top:0.5rem;padding:0.65rem 0.9rem;background:var(--bg-2);border-radius:8px;font-size:0.8rem;color:var(--text-secondary);">
                  <?= icon('user', 12) ?> <strong><?= htmlspecialchars($prefill_claim['full_name']) ?></strong>
                  &nbsp;·&nbsp; <?= htmlspecialchars($prefill_claim['plate_number'] ?? '—') ?>
                  <?= $prefill_claim['make'] ? '(' . htmlspecialchars($prefill_claim['make'] . ' ' . $prefill_claim['model']) . ')' : '' ?>
                  &nbsp;·&nbsp; Policy: <?= htmlspecialchars($prefill_claim['policy_number']) ?>
                </div>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <!-- BILLING DETAILS -->
          <div class="card" style="margin-bottom:0;">
            <div class="card-header">
              <div class="card-icon"><?= icon('document-text', 16) ?></div>
              <div>
                <div class="card-title">Billing Details</div>
                <div class="card-sub">Insurance company and repair dates</div>
              </div>
            </div>
            <div style="padding:1.25rem;display:flex;flex-direction:column;gap:1rem;">
              <div>
                <label class="field-label">Billed To (Insurance Company) <span style="color:var(--danger)">*</span></label>
                <input type="text" name="billed_to" class="field-input"
                  value="<?= htmlspecialchars($_POST['billed_to'] ?? $prefill_claim['insurer_name'] ?? 'PhilBritish Insurance Corp.') ?>"
                  placeholder="e.g. PhilBritish Insurance Corp." required style="width:100%;"/>
              </div>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                <div>
                  <label class="field-label">Incident Date</label>
                  <input type="date" name="incident_date" class="field-input"
                    value="<?= htmlspecialchars($_POST['incident_date'] ?? $prefill_claim['incident_date'] ?? '') ?>"
                    style="width:100%;"/>
                </div>
                <div>
                  <label class="field-label">Repair Date</label>
                  <input type="date" name="repair_date" class="field-input"
                    value="<?= htmlspecialchars($_POST['repair_date'] ?? '') ?>"
                    style="width:100%;"/>
                </div>
              </div>
            </div>
          </div>

          <!-- COST BREAKDOWN -->
          <div class="card" style="margin-bottom:0;">
            <div class="card-header">
              <div class="card-icon"><?= icon('calculator', 16) ?></div>
              <div>
                <div class="card-title">Cost Breakdown</div>
                <div class="card-sub">Parts, labor, and deductible</div>
              </div>
            </div>
            <div style="padding:1.25rem;display:flex;flex-direction:column;gap:1rem;">
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                <div>
                  <label class="field-label">Total Parts</label>
                  <div class="field-prefix-wrap">
                    <span class="field-prefix">₱</span>
                    <input type="number" name="parts_cost" class="field-input field-with-prefix"
                      value="<?= htmlspecialchars($_POST['parts_cost'] ?? '0') ?>"
                      min="0" step="0.01" id="parts_cost" oninput="calcTotal()"/>
                  </div>
                </div>
                <div>
                  <label class="field-label">Labor &amp; Materials</label>
                  <div class="field-prefix-wrap">
                    <span class="field-prefix">₱</span>
                    <input type="number" name="labor_cost" class="field-input field-with-prefix"
                      value="<?= htmlspecialchars($_POST['labor_cost'] ?? '0') ?>"
                      min="0" step="0.01" id="labor_cost" oninput="calcTotal()"/>
                  </div>
                </div>
                <div>
                  <label class="field-label">Other Charges</label>
                  <div class="field-prefix-wrap">
                    <span class="field-prefix">₱</span>
                    <input type="number" name="other_cost" class="field-input field-with-prefix"
                      value="<?= htmlspecialchars($_POST['other_cost'] ?? '0') ?>"
                      min="0" step="0.01" id="other_cost" oninput="calcTotal()"/>
                  </div>
                </div>
                <div>
                  <label class="field-label">Less: Deductible / Participation Fee</label>
                  <div class="field-prefix-wrap">
                    <span class="field-prefix">₱</span>
                    <input type="number" name="deductible" class="field-input field-with-prefix"
                      value="<?= htmlspecialchars($_POST['deductible'] ?? ($prefill_claim['participation_fee'] ?? '0')) ?>"
                      min="0" step="0.01" id="deductible" oninput="calcTotal()"/>
                  </div>
                </div>
              </div>

              <!-- TOTAL PREVIEW -->
              <div class="billing-total-preview" id="total_preview">
                <div class="billing-total-label">Total Amount Due</div>
                <div class="billing-total-value" id="total_display">₱0.00</div>
              </div>
            </div>
          </div>

        </div>

        <!-- RIGHT COLUMN -->
        <div style="display:flex;flex-direction:column;gap:1.25rem;">

          <!-- NOTES -->
          <div class="card" style="margin-bottom:0;">
            <div class="card-header">
              <div class="card-icon"><?= icon('pencil', 16) ?></div>
              <div>
                <div class="card-title">Notes</div>
                <div class="card-sub">Optional remarks</div>
              </div>
            </div>
            <div style="padding:1.25rem;">
              <textarea name="notes" class="field-input" rows="5"
                style="width:100%;resize:vertical;"
                placeholder="Any additional notes about this billing..."><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
            </div>
          </div>

          <!-- REQUIRED DOCUMENTS INFO -->
          <div class="card" style="margin-bottom:0;border:1px solid var(--gold-muted);">
            <div class="card-header">
              <div class="card-icon" style="background:var(--gold-light);color:var(--gold);"><?= icon('paper-clip', 16) ?></div>
              <div>
                <div class="card-title">Required Documents</div>
                <div class="card-sub">Track these 3 documents on the billing record</div>
              </div>
            </div>
            <div style="padding:1rem 1.25rem;display:flex;flex-direction:column;gap:0.5rem;">
              <?php
              $req_docs = [
                  ['Release of Claim', 'Client signature + 2 witnesses'],
                  ["Driver's License", '3 signatures required'],
                  ['Billing Statement', 'Signed by Jean Paolo (Cashier)'],
              ];
              foreach ($req_docs as $i => [$name, $hint]): ?>
              <div style="display:flex;align-items:flex-start;gap:0.6rem;padding:0.55rem 0.7rem;background:var(--bg-2);border-radius:8px;">
                <div style="width:20px;height:20px;border-radius:50%;background:var(--gold-light);color:var(--gold);font-size:0.65rem;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:0.05rem;"><?= $i + 1 ?></div>
                <div>
                  <div style="font-size:0.82rem;font-weight:600;color:var(--text-primary);"><?= $name ?></div>
                  <div style="font-size:0.7rem;color:var(--text-muted);"><?= $hint ?></div>
                </div>
              </div>
              <?php endforeach; ?>
              <div style="font-size:0.72rem;color:var(--text-muted);margin-top:0.25rem;">
                You can mark documents as received after creating the billing record.
              </div>
            </div>
          </div>

          <!-- ACTIONS -->
          <div style="display:flex;flex-direction:column;gap:0.6rem;">
            <button type="submit" class="btn-primary" style="width:100%;justify-content:center;gap:0.4rem;">
              <?= icon('plus', 14) ?> Create Billing Record
            </button>
            <a href="billing_list.php" class="btn-ghost" style="width:100%;justify-content:center;">Cancel</a>
          </div>

        </div>
      </div>
    </form>

  </div>
</div>

<script>
function calcTotal() {
    const parts     = parseFloat(document.getElementById('parts_cost').value)  || 0;
    const labor     = parseFloat(document.getElementById('labor_cost').value)  || 0;
    const other     = parseFloat(document.getElementById('other_cost').value)  || 0;
    const deduct    = parseFloat(document.getElementById('deductible').value)  || 0;
    const total     = parts + labor + other - deduct;
    document.getElementById('total_display').textContent = '₱' + total.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
    document.getElementById('total_preview').style.borderColor = total > 0 ? 'var(--gold)' : 'var(--border)';
}
calcTotal();
</script>

<?php require_once '../../includes/footer.php'; ?>
