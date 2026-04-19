<?php
require_once __DIR__ . "/../../config/session.php";
require_once '../../config/db.php';
require_once '../../config/validators.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header("Location: ../../auth/login.php");
    exit;
}

$billing_id = (int)($_GET['id'] ?? 0);
if (!$billing_id) { header("Location: billing_list.php"); exit; }

function loadBilling($conn, $billing_id) {
    $stmt = $conn->prepare("
        SELECT b.*,
               cl.claim_id, cl.status AS claim_status,
               c.full_name, c.contact_number, c.client_id,
               v.plate_number, v.make, v.model, v.year_model,
               ip.policy_number, ip.coverage_type, ip.participation_fee,
               u.full_name AS created_by_name
        FROM billing b
        INNER JOIN claims cl          ON b.claim_id   = cl.claim_id
        INNER JOIN clients c          ON cl.client_id = c.client_id
        INNER JOIN insurance_policies ip ON cl.policy_id = ip.policy_id
        LEFT  JOIN vehicles v         ON ip.vehicle_id = v.vehicle_id
        LEFT  JOIN users u            ON b.created_by  = u.user_id
        WHERE b.billing_id = ?
    ");
    $stmt->bind_param('i', $billing_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

$billing = loadBilling($conn, $billing_id);
if (!$billing) { header("Location: billing_list.php"); exit; }

// ── DELETE HANDLER ──
if (isset($_GET['do_delete']) && (int)$_GET['do_delete'] === 1) {
    $del = $conn->prepare("DELETE FROM billing WHERE billing_id = ?");
    $del->bind_param('i', $billing_id);
    $del->execute();
    $log = $conn->prepare("INSERT INTO audit_logs (user_id,action,description) VALUES (?,'BILLING_DELETED',?)");
    $desc = ($_SESSION['full_name'] ?? '') . ' deleted billing ' . $billing['billing_number'] . '.';
    $log->bind_param('is', $_SESSION['user_id'], $desc);
    $log->execute();
    header("Location: billing_list.php?success=" . urlencode('Billing record deleted.')); exit;
}

// ── POST HANDLERS ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = san_str($_POST['action'] ?? '', 30);

    if ($action === 'update_status') {
        $new_status = san_enum($_POST['status'] ?? '', ['draft', 'sent', 'paid', 'unpaid']);
        if ($new_status) {
            $sent_at = ($new_status === 'sent' && !$billing['sent_at']) ? date('Y-m-d H:i:s') : null;
            $upd = $conn->prepare("UPDATE billing SET status=?, sent_at=COALESCE(?,sent_at) WHERE billing_id=?");
            $upd->bind_param('ssi', $new_status, $sent_at, $billing_id);
            $upd->execute();
            $log = $conn->prepare("INSERT INTO audit_logs (user_id,action,description) VALUES (?,'BILLING_STATUS_UPDATED',?)");
            $desc = ($_SESSION['full_name'] ?? '') . ' changed billing ' . $billing['billing_number'] . ' to ' . $new_status . '.';
            $log->bind_param('is', $_SESSION['user_id'], $desc);
            $log->execute();
        }
        header("Location: view_billing.php?id=$billing_id&success=Status+updated"); exit;
    }

    if ($action === 'update_docs') {
        $dr = isset($_POST['doc_release_of_claim'])  ? 1 : 0;
        $dl = isset($_POST['doc_drivers_license'])   ? 1 : 0;
        $ds = isset($_POST['doc_billing_statement']) ? 1 : 0;
        $upd = $conn->prepare("UPDATE billing SET doc_release_of_claim=?,doc_drivers_license=?,doc_billing_statement=? WHERE billing_id=?");
        $upd->bind_param('iiii', $dr, $dl, $ds, $billing_id);
        $upd->execute();
        $log = $conn->prepare("INSERT INTO audit_logs (user_id,action,description) VALUES (?,'BILLING_DOCS_UPDATED',?)");
        $desc = ($_SESSION['full_name'] ?? '') . ' updated docs for billing ' . $billing['billing_number'] . '.';
        $log->bind_param('is', $_SESSION['user_id'], $desc);
        $log->execute();
        header("Location: view_billing.php?id=$billing_id&success=Documents+updated"); exit;
    }

    if ($action === 'update_costs') {
        $parts  = (float)str_replace(',', '', $_POST['parts_cost'] ?? '0');
        $labor  = (float)str_replace(',', '', $_POST['labor_cost'] ?? '0');
        $other  = (float)str_replace(',', '', $_POST['other_cost'] ?? '0');
        $deduct = (float)str_replace(',', '', $_POST['deductible'] ?? '0');
        $billed = san_str($_POST['billed_to']      ?? '', 255);
        $notes  = san_str($_POST['notes']           ?? '', 1000);
        $inc_d  = san_str($_POST['incident_date']   ?? '', 10) ?: null;
        $rep_d  = san_str($_POST['repair_date']     ?? '', 10) ?: null;
        $upd = $conn->prepare("UPDATE billing SET billed_to=?,incident_date=?,repair_date=?,parts_cost=?,labor_cost=?,other_cost=?,deductible=?,notes=? WHERE billing_id=?");
        $upd->bind_param('sssddddsi', $billed, $inc_d, $rep_d, $parts, $labor, $other, $deduct, $notes, $billing_id);
        $upd->execute();
        $log = $conn->prepare("INSERT INTO audit_logs (user_id,action,description) VALUES (?,'BILLING_UPDATED',?)");
        $desc = ($_SESSION['full_name'] ?? '') . ' updated billing ' . $billing['billing_number'] . '.';
        $log->bind_param('is', $_SESSION['user_id'], $desc);
        $log->execute();
        header("Location: view_billing.php?id=$billing_id&success=Billing+updated"); exit;
    }
}

$billing = loadBilling($conn, $billing_id);

$status_map = [
    'draft'  => ['Draft',  'badge-gray',   'pencil'],
    'sent'   => ['Sent',   'badge-blue',   'paper-airplane'],
    'paid'   => ['Paid',   'badge-green',  'check-circle'],
    'unpaid' => ['Unpaid', 'badge-yellow', 'clock'],
];
$sb = $status_map[$billing['status']] ?? ['Unknown', 'badge-gray', 'document-text'];

$total_repair = (float)$billing['parts_cost'] + (float)$billing['labor_cost'] + (float)$billing['other_cost'];
$docs_done    = (int)$billing['doc_release_of_claim'] + (int)$billing['doc_drivers_license'] + (int)$billing['doc_billing_statement'];

$page_title  = $billing['billing_number'];
$active_page = 'billing';
$base_path   = '../../';
$extra_css   = '<link rel="stylesheet" href="../../assets/css/shared/billing.css?v=' . @filemtime(__DIR__ . '/../../assets/css/shared/billing.css') . '"/>';
require_once '../../includes/header.php';
require_once '../../includes/navbar.php';
?>

<div class="main">
<?php
$topbar_title      = htmlspecialchars($billing['billing_number']);
$topbar_breadcrumb = ['Insurance', 'Billing', $billing['billing_number']];
$topbar_show_clock = true;
require_once '../../includes/topbar.php';
?>

  <div class="content">

    <?php if (!empty($_GET['success'])): ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
      Swal.fire({ toast:true, position:'top-end', icon:'success', title:<?= json_encode(san_str($_GET['success'], 200)) ?>, showConfirmButton:false, timer:3000, timerProgressBar:true });
    });
    </script>
    <?php endif; ?>

    <!-- PAGE HEADER -->
    <div class="vb-header">
      <div class="vb-header-left">
        <a href="billing_list.php" class="btn-ghost" style="padding:0.4rem 0.7rem;"><?= icon('arrow-left', 14) ?></a>
        <div>
          <div style="display:flex;align-items:center;gap:0.6rem;">
            <span style="font-size:1.1rem;font-weight:800;color:var(--text-primary);"><?= htmlspecialchars($billing['billing_number']) ?></span>
            <span class="badge <?= $sb[1] ?>"><?= $sb[0] ?></span>
          </div>
          <div style="font-size:0.72rem;color:var(--text-muted);margin-top:0.15rem;">
            <?= htmlspecialchars($billing['full_name']) ?> &nbsp;·&nbsp;
            <?= htmlspecialchars($billing['plate_number'] ?? '—') ?> &nbsp;·&nbsp;
            Created <?= date('M d, Y', strtotime($billing['created_at'])) ?>
          </div>
        </div>
      </div>
      <div class="vb-header-right">
        <button type="button" class="btn-ghost" onclick="document.getElementById('status-modal').classList.add('open')">
          <?= icon('arrow-path', 14) ?> Update Status
        </button>
        <button type="button" class="btn-primary" onclick="window.print()">
          <?= icon('printer', 14) ?> Print
        </button>
      </div>
    </div>

    <!-- MAIN GRID -->
    <div class="vb-grid">

      <!-- LEFT: STATEMENT + TIMELINE -->
      <div style="display:flex;flex-direction:column;gap:1rem;">
        <div class="card vb-statement" id="printable-statement" style="margin-bottom:0;">

          <!-- Statement header -->
          <div class="vb-stmt-top">
            <div>
              <div class="vb-stmt-title">BILLING STATEMENT</div>
              <div class="vb-stmt-meta">No. <?= htmlspecialchars($billing['billing_number']) ?> &nbsp;·&nbsp; <?= date('F d, Y', strtotime($billing['created_at'])) ?></div>
            </div>
            <?php if ($billing['sent_at']): ?>
            <div style="text-align:right;">
              <div class="vb-stmt-meta">Sent to insurer</div>
              <div style="font-size:0.78rem;font-weight:600;color:var(--info);"><?= date('M d, Y', strtotime($billing['sent_at'])) ?></div>
            </div>
            <?php endif; ?>
          </div>

          <!-- Billed to -->
          <div class="vb-billed-to">
            <div class="vb-field-label">BILLED TO</div>
            <div class="vb-billed-name"><?= htmlspecialchars($billing['billed_to']) ?></div>
          </div>

          <!-- Client / vehicle info -->
          <div class="vb-info-grid">
            <div><span class="vb-field-label">Client</span><div class="vb-info-val"><?= htmlspecialchars($billing['full_name']) ?></div></div>
            <div>
              <span class="vb-field-label">Vehicle</span>
              <div class="vb-info-val">
                <?= htmlspecialchars($billing['plate_number'] ?? '—') ?>
                <?php if ($billing['make']): ?> &nbsp;(<?= htmlspecialchars(trim($billing['year_model'] . ' ' . $billing['make'] . ' ' . $billing['model'])) ?>)<?php endif; ?>
              </div>
            </div>
            <div><span class="vb-field-label">Policy No.</span><div class="vb-info-val"><?= htmlspecialchars($billing['policy_number']) ?></div></div>
            <div>
              <span class="vb-field-label">Incident Date</span>
              <div class="vb-info-val"><?= $billing['incident_date'] ? date('M d, Y', strtotime($billing['incident_date'])) : '—' ?></div>
            </div>
            <?php if ($billing['repair_date']): ?>
            <div>
              <span class="vb-field-label">Repair Date</span>
              <div class="vb-info-val"><?= date('M d, Y', strtotime($billing['repair_date'])) ?></div>
            </div>
            <?php endif; ?>
          </div>

          <!-- Cost breakdown -->
          <table class="vb-cost-table">
            <thead>
              <tr><th>Description</th><th>Amount</th></tr>
            </thead>
            <tbody>
              <tr><td>Total Parts</td><td>₱<?= number_format($billing['parts_cost'], 2) ?></td></tr>
              <tr><td>Labor &amp; Materials</td><td>₱<?= number_format($billing['labor_cost'], 2) ?></td></tr>
              <?php if ((float)$billing['other_cost'] > 0): ?>
              <tr><td>Other Charges</td><td>₱<?= number_format($billing['other_cost'], 2) ?></td></tr>
              <?php endif; ?>
              <tr class="vb-subtotal"><td>Total Cost of Repair</td><td>₱<?= number_format($total_repair, 2) ?></td></tr>
              <tr class="vb-deduct"><td>Less: Deductible / Participation Fee</td><td>−₱<?= number_format($billing['deductible'], 2) ?></td></tr>
            </tbody>
            <tfoot>
              <tr class="vb-total"><td>TOTAL AMOUNT DUE</td><td>₱<?= number_format($billing['total_amount_due'], 2) ?></td></tr>
            </tfoot>
          </table>

          <?php if ($billing['notes']): ?>
          <div class="vb-notes"><?= nl2br(htmlspecialchars($billing['notes'])) ?></div>
          <?php endif; ?>

          <!-- Signature footer -->
          <div class="vb-sig-row">
            <div style="font-size:0.75rem;color:var(--text-muted);">
              Prepared by: <strong style="color:var(--text-primary);"><?= htmlspecialchars($billing['created_by_name'] ?? '—') ?></strong>
            </div>
            <div class="vb-sig-block">
              <div class="vb-sig-label">Authorized Representative / Cashier</div>
              <div class="vb-sig-name">Jean Paolo De Guzman</div>
            </div>
          </div>
        </div>

        <!-- TIMELINE -->
        <div class="card" style="margin-bottom:0;">
          <div class="card-header">
            <div class="card-icon"><?= icon('clock', 16) ?></div>
            <div><div class="card-title">Timeline</div></div>
          </div>
          <div style="padding:0.75rem 1.25rem;display:flex;flex-wrap:wrap;gap:0.5rem 2.5rem;">
            <?php
            $tl = [
                ['Created',      date('M d, Y g:i A', strtotime($billing['created_at']))],
                ['Last Updated', date('M d, Y g:i A', strtotime($billing['updated_at']))],
                ['Created By',   htmlspecialchars($billing['created_by_name'] ?? '—')],
            ];
            if ($billing['sent_at']) $tl[] = ['Sent to Insurer', date('M d, Y g:i A', strtotime($billing['sent_at']))];
            foreach ($tl as [$lbl, $val]): ?>
            <div>
              <div style="font-size:0.62rem;text-transform:uppercase;letter-spacing:0.8px;color:var(--text-muted);font-weight:700;"><?= $lbl ?></div>
              <div style="font-size:0.8rem;font-weight:600;color:var(--text-primary);margin-top:0.1rem;"><?= $val ?></div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

      </div>

      <!-- RIGHT: CONTROLS -->
      <div>

        <!-- QUICK INFO -->
        <div class="card" style="margin-bottom:0;">
          <div class="card-header">
            <div class="card-icon"><?= icon('user', 16) ?></div>
            <div>
              <div class="card-title">Client &amp; Policy</div>
              <div class="card-sub"><?= htmlspecialchars($billing['policy_number']) ?></div>
            </div>
          </div>
          <div style="padding:0.6rem 1rem;display:flex;flex-direction:column;gap:0.3rem;">
            <?php
            $info_rows = [
                ['Client',   htmlspecialchars($billing['full_name'])],
                ['Contact',  htmlspecialchars($billing['contact_number'] ?? '—')],
                ['Plate',    htmlspecialchars($billing['plate_number'] ?? '—')],
                ['Coverage', htmlspecialchars($billing['coverage_type'] ?? '—')],
            ];
            foreach ($info_rows as [$lbl, $val]): ?>
            <div class="billing-detail-row">
              <span class="billing-detail-label"><?= $lbl ?></span>
              <span class="billing-detail-val"><?= $val ?></span>
            </div>
            <?php endforeach; ?>
            <div style="display:flex;gap:0.4rem;margin-top:0.3rem;">
              <a href="../claims/view_claim.php?id=<?= $billing['claim_id'] ?>" class="btn-sm-gold"><?= icon('clipboard-list', 12) ?> Claim</a>
              <a href="../clients/view_client.php?id=<?= $billing['client_id'] ?>" class="btn-sm-gold"><?= icon('user', 12) ?> Client</a>
            </div>
          </div>
        </div>

        <!-- REQUIRED DOCUMENTS -->
        <div class="card" style="margin-bottom:0;">
          <div class="card-header">
            <div class="card-icon" style="background:var(--gold-light);color:var(--gold);"><?= icon('paper-clip', 16) ?></div>
            <div>
              <div class="card-title">Required Documents</div>
              <div class="card-sub"><?= $docs_done ?>/3 received</div>
            </div>
          </div>
          <form method="POST" action="" style="padding:0.6rem 1rem;display:flex;flex-direction:column;gap:0.35rem;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_docs"/>
            <?php
            $docs = [
                ['doc_release_of_claim',  'Release of Claim',   'Client signature + 2 witnesses', $billing['doc_release_of_claim']],
                ['doc_drivers_license',   "Driver's License",   '3 signatures required',           $billing['doc_drivers_license']],
                ['doc_billing_statement', 'Billing Statement',  'Signed by Jean Paolo (Cashier)',  $billing['doc_billing_statement']],
            ];
            foreach ($docs as [$field, $label, $hint, $checked]): ?>
            <label class="billing-doc-check <?= $checked ? 'checked' : '' ?>">
              <input type="checkbox" name="<?= $field ?>" value="1" <?= $checked ? 'checked' : '' ?>
                onchange="this.closest('form').submit()" style="display:none;"/>
              <div class="billing-doc-tick <?= $checked ? 'done' : '' ?>"><?= $checked ? icon('check', 11) : '' ?></div>
              <div>
                <div class="billing-doc-name"><?= $label ?></div>
                <div class="billing-doc-hint"><?= $hint ?></div>
              </div>
            </label>
            <?php endforeach; ?>
          </form>
        </div>

        <!-- EDIT DETAILS -->
        <div class="card" style="margin-bottom:0;">
          <div class="card-header">
            <div class="card-icon"><?= icon('pencil', 16) ?></div>
            <div>
              <div class="card-title">Edit Details</div>
              <div class="card-sub">Update costs, dates, or insurer</div>
            </div>
          </div>
          <form method="POST" action="" style="padding:0.6rem 1rem;display:flex;flex-direction:column;gap:0.5rem;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_costs"/>

            <div>
              <label class="field-label">Billed To</label>
              <input type="text" name="billed_to" class="field-input" value="<?= htmlspecialchars($billing['billed_to']) ?>" style="width:100%;"/>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;">
              <div>
                <label class="field-label">Incident Date</label>
                <input type="date" name="incident_date" class="field-input" value="<?= htmlspecialchars($billing['incident_date'] ?? '') ?>" style="width:100%;"/>
              </div>
              <div>
                <label class="field-label">Repair Date</label>
                <input type="date" name="repair_date" class="field-input" value="<?= htmlspecialchars($billing['repair_date'] ?? '') ?>" style="width:100%;"/>
              </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;">
              <div>
                <label class="field-label">Total Parts</label>
                <div class="field-prefix-wrap">
                  <span class="field-prefix">₱</span>
                  <input type="number" name="parts_cost" class="field-input field-with-prefix"
                    value="<?= $billing['parts_cost'] ?>" min="0" step="0.01" id="ep_parts" oninput="editCalc()"/>
                </div>
              </div>
              <div>
                <label class="field-label">Labor &amp; Materials</label>
                <div class="field-prefix-wrap">
                  <span class="field-prefix">₱</span>
                  <input type="number" name="labor_cost" class="field-input field-with-prefix"
                    value="<?= $billing['labor_cost'] ?>" min="0" step="0.01" id="ep_labor" oninput="editCalc()"/>
                </div>
              </div>
              <div>
                <label class="field-label">Other Charges</label>
                <div class="field-prefix-wrap">
                  <span class="field-prefix">₱</span>
                  <input type="number" name="other_cost" class="field-input field-with-prefix"
                    value="<?= $billing['other_cost'] ?>" min="0" step="0.01" id="ep_other" oninput="editCalc()"/>
                </div>
              </div>
              <div>
                <label class="field-label">Less: Deductible</label>
                <div class="field-prefix-wrap">
                  <span class="field-prefix">₱</span>
                  <input type="number" name="deductible" class="field-input field-with-prefix"
                    value="<?= $billing['deductible'] ?>" min="0" step="0.01" id="ep_deduct" oninput="editCalc()"/>
                </div>
              </div>
            </div>

            <div class="vb-total-preview">
              <span>Total Amount Due</span>
              <span id="ep_total">₱<?= number_format($billing['total_amount_due'], 2) ?></span>
            </div>

            <div>
              <label class="field-label">Notes</label>
              <textarea name="notes" class="field-input" rows="1" style="width:100%;resize:vertical;"><?= htmlspecialchars($billing['notes'] ?? '') ?></textarea>
            </div>

            <div style="display:flex;justify-content:flex-end;">
              <button type="submit" class="btn-sm-gold"><?= icon('check', 13) ?> Save Changes</button>
            </div>
          </form>
        </div>


      </div>
    </div>

  </div>
</div>

<!-- STATUS MODAL -->
<div class="modal-overlay" id="status-modal" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="modal-box" style="max-width:400px;">
    <div class="modal-header">
      <div class="modal-title"><?= icon('arrow-path', 16) ?> Update Status</div>
      <button class="modal-close" onclick="document.getElementById('status-modal').classList.remove('open')"><?= icon('x-mark', 14) ?></button>
    </div>
    <form method="POST" action="">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update_status"/>
      <div style="padding:1.25rem;display:flex;flex-direction:column;gap:0.65rem;">
        <?php foreach ($status_map as $val => [$label, $cls, $ico]): ?>
        <label class="vb-status-opt <?= $billing['status'] === $val ? 'selected' : '' ?>">
          <input type="radio" name="status" value="<?= $val ?>" <?= $billing['status'] === $val ? 'checked' : '' ?>
            onchange="document.querySelectorAll('.vb-status-opt').forEach(el=>el.classList.remove('selected'));this.closest('label').classList.add('selected')"
            style="display:none;"/>
          <div class="vb-status-opt-icon"><?= icon($ico, 15) ?></div>
          <div>
            <span class="badge <?= $cls ?>"><?= $label ?></span>
            <div style="font-size:0.7rem;color:var(--text-muted);margin-top:0.1rem;">
              <?php $descs = ['draft'=>'Not yet sent','sent'=>'Sent to insurance company','paid'=>'Insurance company paid','unpaid'=>'Sent but unpaid']; echo $descs[$val] ?? ''; ?>
            </div>
          </div>
        </label>
        <?php endforeach; ?>
      </div>
      <div style="padding:0 1.25rem 1.25rem;display:flex;justify-content:flex-end;gap:0.5rem;">
        <button type="button" class="btn-ghost" onclick="document.getElementById('status-modal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn-primary">Save</button>
      </div>
    </form>
  </div>
</div>

<script>
function editCalc() {
    const p = parseFloat(document.getElementById('ep_parts').value)  || 0;
    const l = parseFloat(document.getElementById('ep_labor').value)  || 0;
    const o = parseFloat(document.getElementById('ep_other').value)  || 0;
    const d = parseFloat(document.getElementById('ep_deduct').value) || 0;
    document.getElementById('ep_total').textContent = '₱' + (p+l+o-d).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
}
document.addEventListener('keydown', e => { if (e.key==='Escape') document.getElementById('status-modal').classList.remove('open'); });
</script>

<?php require_once '../../includes/footer.php'; ?>
