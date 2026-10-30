<?php
require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . '/../../includes/data_access.php';
require_once '../../config/db.php';
require_once '../../config/validators.php';
require_once '../../config/access.php';
require_once '../../includes/transaction.php';
require_once '../../includes/insurance_companies.php';
require_once '../../includes/billing_lists.php';

require_role(['admin', 'super_admin']);

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
               ip.insurance_company,
               v.make, v.model, v.plate_number
        FROM claims cl
        INNER JOIN clients c         ON cl.client_id  = c.client_id
        INNER JOIN insurance_policies ip ON cl.policy_id  = ip.policy_id
        LEFT  JOIN vehicles v        ON ip.vehicle_id = v.vehicle_id
        WHERE cl.claim_id = ?
    ");
    $pf->bind_param('i', $prefill_claim_id);
    $pf->execute();
    $prefill_claim = $pf->get_result()->fetch_assoc();
    // "Billed To" starts as the billing name of the company the claim's policy is under (Settings)
    if ($prefill_claim) $prefill_claim['insurer_name'] = insurance_company_billing_name($conn, $prefill_claim['insurance_company']);
}

// ── HANDLE POST ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $claim_id      = (int)($_POST['claim_id']    ?? 0);
    $billed_to     = san_str($_POST['billed_to']     ?? '', 255);
    $incident_date = san_str($_POST['incident_date']  ?? '', 10);
    $repair_date   = san_str($_POST['repair_date']    ?? '', 10);
    // Each amount checked by read_money(): a typo is an error, never a silent ₱0
    [$parts_cost, $e1] = read_money($_POST['parts_cost'] ?? '', 'Parts cost');
    [$labor_cost, $e2] = read_money($_POST['labor_cost'] ?? '', 'Labor cost');
    [$other_cost, $e3] = read_money($_POST['other_cost'] ?? '', 'Other cost');
    [$deductible, $e4] = read_money($_POST['deductible'] ?? '', 'Deductible');
    foreach ([$e1, $e2, $e3, $e4] as $e) if ($e !== '') $errors[] = $e;
    // the amount due is (parts + labor + other) - deductible: it must never go below zero
    if ($e1 . $e2 . $e3 . $e4 === '' && round($deductible, 2) > round($parts_cost + $labor_cost + $other_cost, 2)) {
        $errors[] = 'The deductible cannot be more than the total cost of repair (₱' . number_format($parts_cost + $labor_cost + $other_cost, 2) . ').';
    }
    $notes         = san_str($_POST['notes'] ?? '', MAX_NOTE);
    if (text_too_long($_POST['notes'] ?? '', MAX_NOTE)) $errors[] = 'Notes are too long (max ' . MAX_NOTE . ' characters).';

    if ($claim_id === 0) $errors[] = 'A linked claim is required.';
    if ($billed_to === '') $errors[] = 'Billed To (insurance company) is required.';
    // who signs as cashier (billing_signatories); their name and title are copied onto the billing
    // (none sent = the default one, first in the list; one sent that is not on the list is refused)
    $signer = ($_POST['signatory_id'] ?? '') === '' ? (billing_signatories_active($conn)[0] ?? null)
                                                    : billing_signatory_pick($conn, $_POST['signatory_id']);
    if (!$signer && billing_signatories_active($conn)) $errors[] = 'Please choose the Authorized Representative / Cashier.';
    $signer_name  = $signer['name']  ?? null;
    $signer_title = $signer['title'] ?? null;
    if ($incident_date !== '' && !validate_date($incident_date)) $errors[] = 'Incident date is not a valid date.';
    if ($repair_date   !== '' && !validate_date($repair_date))   $errors[] = 'Repair date is not a valid date.';

    // only approved claims can be billed
    if (empty($errors)) {
        $cs = $conn->prepare("SELECT status FROM claims WHERE claim_id = ?");
        $cs->bind_param('i', $claim_id);
        $cs->execute();
        $crow = $cs->get_result()->fetch_assoc();
        if (!$crow) {
            $errors[] = 'The selected claim was not found.';
        } elseif (!claim_is_billable($crow['status'])) {
            $errors[] = 'Billing can only be created for approved claims.';
        }
    }

    // Check no duplicate billing for same claim
    if (empty($errors)) {
        if (billing_exists_for_claim($conn, $claim_id)) {
            $errors[] = 'A billing record already exists for this claim.';
        }
    }

    if (empty($errors)) {
        $inc_date = $incident_date ?: null;
        $rep_date = $repair_date   ?: null;
        // lock the claim first, so two people can't both bill it at once
        $created = null;
        try {
            $created = db_transaction($conn, function () use ($conn, $claim_id, $billed_to, $inc_date, $rep_date, $parts_cost, $labor_cost, $other_cost, $deductible, $signer_name, $signer_title) {
                billing_lock_claim($conn, $claim_id);
                if (billing_exists_for_claim($conn, $claim_id)) return ['duplicate' => true];

                [$billing_id, $bill_num] = insert_with_sequential_number($conn, 'billing', 'billing_number', record_number_prefix('BL'),
                    function (string $num) use ($conn, $claim_id, $billed_to, $inc_date, $rep_date, $parts_cost, $labor_cost, $other_cost, $deductible, $signer_name, $signer_title) {
                        $id = billing_create($conn, $num, [
                            'claim_id' => $claim_id, 'billed_to' => $billed_to, 'incident_date' => $inc_date, 'repair_date' => $rep_date,
                            'parts_cost' => $parts_cost, 'labor_cost' => $labor_cost, 'other_cost' => $other_cost, 'deductible' => $deductible,
                            'signed_by_name' => $signer_name, 'signed_by_title' => $signer_title,
                        ], (int)$_SESSION['user_id']);
                        return [$id, $num];
                    }
                );

                $desc = ($_SESSION['full_name'] ?? 'Unknown') . ' created billing ' . $bill_num . '.';
                audit_log_add($conn, $_SESSION['user_id'], 'BILLING_CREATED', $desc);

                return ['num' => $bill_num];
            });
        } catch (mysqli_sql_exception $e) {
            error_log('[TG-BASICS] add_billing.php insert failed: ' . $e->getMessage());
        }

        if (isset($created['num'])) {
            header("Location: billing_list.php?success=" . urlencode($created['num'] . ' created successfully.'));
            exit;
        } elseif (isset($created['duplicate'])) {
            $errors[] = 'A billing record already exists for this claim.';
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
    WHERE cl.status IN ('" . implode("','", CLAIM_BILLABLE_STATUSES) . "')
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
                <select name="claim_id" id="claim_select" class="field-select" required style="width:100%;" onchange="if (this.value) window.location.href = 'add_billing.php?claim_id=' + encodeURIComponent(this.value)">
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
                  value="<?= old('billed_to', $prefill_claim['insurer_name'] ?? (insurance_companies_active($conn)[0]['billing_name'] ?? '')) ?>"
                  placeholder="Insurance company name" required style="width:100%;"/>
              </div>
              <div>
                <label class="field-label">Authorized Representative / Cashier <span style="color:var(--danger)">*</span></label>
                <select name="signatory_id" class="field-input" style="width:100%;">
                  <?php foreach (billing_signatories_active($conn) as $i => $sg):
                    $pick = isset($_POST['signatory_id']) ? (int)$_POST['signatory_id'] === (int)$sg['signatory_id'] : $i === 0;   // the default comes first ?>
                  <option value="<?= (int)$sg['signatory_id'] ?>" <?= $pick ? 'selected' : '' ?>><?= htmlspecialchars($sg['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;">
                <div>
                  <label class="field-label">Incident Date</label>
                  <input type="date" name="incident_date" class="field-input"
                    value="<?= old('incident_date', $prefill_claim['incident_date'] ?? '') ?>"
                    style="width:100%;"/>
                </div>
                <div>
                  <label class="field-label">Repair Date</label>
                  <input type="date" name="repair_date" class="field-input"
                    value="<?= old('repair_date') ?>"
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
                    <input type="number" name="parts_cost" class="field-input field-with-prefix" data-money max="<?= MAX_MONEY ?>"
                      value="<?= old('parts_cost', '0') ?>"
                      min="0" step="0.01" id="parts_cost" oninput="calcTotal()"/>
                  </div>
                </div>
                <div>
                  <label class="field-label">Labor &amp; Materials</label>
                  <div class="field-prefix-wrap">
                    <span class="field-prefix">₱</span>
                    <input type="number" name="labor_cost" class="field-input field-with-prefix" data-money max="<?= MAX_MONEY ?>"
                      value="<?= old('labor_cost', '0') ?>"
                      min="0" step="0.01" id="labor_cost" oninput="calcTotal()"/>
                  </div>
                </div>
                <div>
                  <label class="field-label">Other Charges</label>
                  <div class="field-prefix-wrap">
                    <span class="field-prefix">₱</span>
                    <input type="number" name="other_cost" class="field-input field-with-prefix" data-money max="<?= MAX_MONEY ?>"
                      value="<?= old('other_cost', '0') ?>"
                      min="0" step="0.01" id="other_cost" oninput="calcTotal()"/>
                  </div>
                </div>
                <div>
                  <label class="field-label">Less: Deductible / Participation Fee</label>
                  <div class="field-prefix-wrap">
                    <span class="field-prefix">₱</span>
                    <input type="number" name="deductible" class="field-input field-with-prefix" data-money max="<?= MAX_MONEY ?>"
                      value="<?= old('deductible', $prefill_claim['participation_fee'] ?? '0') ?>"
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
              <textarea name="notes" class="field-input" rows="5" maxlength="<?= MAX_NOTE ?>"
                style="width:100%;resize:vertical;"
                placeholder="Any additional notes about this billing..."><?= old('notes') ?></textarea>
            </div>
          </div>

          <!-- REQUIRED DOCUMENTS INFO -->
          <div class="card" style="margin-bottom:0;border:1px solid var(--gold-muted);">
            <div class="card-header">
              <div class="card-icon" style="background:var(--gold-light);color:var(--gold);"><?= icon('paper-clip', 16) ?></div>
              <div>
                <div class="card-title">Required Documents</div>
                <div class="card-sub">Track these <?= count(billing_docs_for($conn, [])) ?> documents on the billing record</div>
              </div>
            </div>
            <div style="padding:1rem 1.25rem;display:flex;flex-direction:column;gap:0.5rem;">
              <?php foreach (billing_docs_for($conn, []) as $i => $doc):   // billing_document_types (phpMyAdmin) ?>
              <div style="display:flex;align-items:flex-start;gap:0.6rem;padding:0.55rem 0.7rem;background:var(--bg-2);border-radius:8px;">
                <div style="width:20px;height:20px;border-radius:50%;background:var(--gold-light);color:var(--gold);font-size:0.65rem;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:0.05rem;"><?= $i + 1 ?></div>
                <div>
                  <div style="font-size:0.82rem;font-weight:600;color:var(--text-primary);"><?= htmlspecialchars($doc['name']) ?></div>
                  <div style="font-size:0.7rem;color:var(--text-muted);"><?= htmlspecialchars((string)$doc['hint']) ?></div>
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
