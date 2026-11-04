<?php
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/data_access.php';
require_once __DIR__ . "/../../config/session.php";
require_once '../../config/db.php';
require_once '../../config/validators.php';
require_once '../../config/access.php';
require_once '../../includes/transaction.php';
require_once '../../config/transaction_pin.php';
require_once '../../includes/billing.php';
require_once __DIR__ . '/../../config/settings.php';   // company_info(): the shop name on the printed statement

require_role(['admin', 'super_admin']);

$billing_id = (int)($_GET['id'] ?? 0);
if (!$billing_id) { header("Location: billing_list.php"); exit; }

$billing = billing_find($conn, $billing_id);
if (!$billing) { header("Location: billing_list.php"); exit; }

// ── POST HANDLERS ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = san_str($_POST['action'] ?? '', 30);

    // Delete is a POST with a CSRF token — it used to be a GET link, which any page could trigger.
    if ($action === 'delete_billing') {
        // the Delete button asks for the Transaction PIN (requirePin), so the server checks it was given
        if (!pin_consume($conn)) {
            header("Location: view_billing.php?id=$billing_id&error=" . urlencode(PIN_REQUIRED_MSG)); exit;
        }
        db_transaction($conn, function () use ($conn, $billing_id, $billing) {
            billing_delete($conn, $billing_id);
            $desc = ($_SESSION['full_name'] ?? '') . ' deleted billing ' . $billing['billing_number'] . '.';
            audit_log_add($conn, $_SESSION['user_id'], 'BILLING_DELETED', $desc);
        });
        header("Location: billing_list.php?success=" . urlencode('Billing record deleted.')); exit;
    }

    if ($action === 'update_status') {
        $new_status = san_enum($_POST['status'] ?? '', array_column(billing_statuses_active($conn), 'code'));
        // not one of the active statuses: say so (it used to report "Status updated" with nothing saved)
        if (!$new_status) { header("Location: view_billing.php?id=$billing_id&error=" . urlencode('Please choose one of the listed statuses.')); exit; }
        $sent_at = ($new_status === BILLING_STATUS_SENT && !$billing['sent_at']) ? date('Y-m-d H:i:s') : null;
        db_transaction($conn, function () use ($conn, $billing_id, $billing, $new_status, $sent_at) {
            billing_set_status($conn, $billing_id, $new_status, $sent_at);
            $desc = ($_SESSION['full_name'] ?? '') . ' changed billing ' . $billing['billing_number'] . ' to ' . $new_status . '.';
            audit_log_add($conn, $_SESSION['user_id'], 'BILLING_STATUS_UPDATED', $desc);
        });
        header("Location: view_billing.php?id=$billing_id&success=Status+updated"); exit;
    }

    if ($action === 'update_docs') {
        // The ticked documents (doc[] = doc_type_id). Only a type shown on this billing counts: an active one, or
        // an inactive one it already has. A received one keeps its first received_at.
        $shown   = array_column(billing_docs_for($conn, billing_docs_received($conn, [$billing_id])[$billing_id] ?? []), 'doc_type_id');
        $shown   = array_map('intval', $shown);
        $ticked  = array_values(array_intersect(array_map('intval', is_array($_POST['doc'] ?? null) ? $_POST['doc'] : []), $shown));
        db_transaction($conn, function () use ($conn, $billing_id, $billing, $shown, $ticked) {
            billing_set_documents($conn, $billing_id, $ticked);
            $desc = ($_SESSION['full_name'] ?? '') . ' updated docs for billing ' . $billing['billing_number'] . '.';
            audit_log_add($conn, $_SESSION['user_id'], 'BILLING_DOCS_UPDATED', $desc);
        });
        header("Location: view_billing.php?id=$billing_id&success=Documents+updated"); exit;
    }

    if ($action === 'update_costs') {
        // Billed To, the four amounts, notes and dates: the check shared with Add Billing (includes/billing.php)
        [$bf, $form_errors] = billing_form_check($_POST);
        if ($form_errors) { header("Location: view_billing.php?id=$billing_id&error=" . urlencode($form_errors[0])); exit; }
        // the cashier who signs (billing_signatories); their name and title are copied onto the billing
        // (none chosen = keep the one it has)
        $signer = billing_signatory_pick($conn, $_POST['signatory_id'] ?? 0);

        db_transaction($conn, function () use ($conn, $billing_id, $billing, $bf, $signer) {
            billing_update($conn, $billing_id, $bf, $signer ?: null);
            $desc = ($_SESSION['full_name'] ?? '') . ' updated billing ' . $billing['billing_number'] . '.';
            audit_log_add($conn, $_SESSION['user_id'], 'BILLING_UPDATED', $desc);
        });
        header("Location: view_billing.php?id=$billing_id&success=Billing+updated"); exit;
    }
}

$billing = billing_find($conn, $billing_id);

$sb = billing_status_badge($conn, $billing['status']);   // statuses: the statuses table, module 'billing' (includes/billing.php)

$total_repair = (float)$billing['parts_cost'] + (float)$billing['labor_cost'] + (float)$billing['other_cost'];
$docs         = billing_docs_for($conn, billing_docs_received($conn, [$billing_id])[$billing_id] ?? []);
$docs_done    = count(array_filter($docs, fn($d) => $d['received']));

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
      Swal.fire({ toast:true, position:'top-end', icon:'success', titleText:<?= json_encode(san_str($_GET['success'], 200)) ?>, showConfirmButton:false, timer:3000, timerProgressBar:true });
    });
    </script>
    <?php endif; ?>
    <?php if (!empty($_GET['error'])): ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
      Swal.fire({ icon:'error', title:'Could not save', text:<?= json_encode(san_str($_GET['error'], 200)) ?> });
    });
    </script>
    <?php endif; ?>

    <!-- PAGE HEADER -->
    <div class="vb-header">
      <div class="vb-header-left">
        <a href="billing_list.php" class="btn-ghost vb-back" onclick="goBack('billing_list.php'); return false;"><?= icon('arrow-left', 14) ?></a>
        <div>
          <div class="vb-title-row">
            <span class="vb-title"><?= htmlspecialchars($billing['billing_number']) ?></span>
            <span class="badge <?= $sb['class'] ?>"><?= htmlspecialchars($sb['label']) ?></span>
          </div>
          <div class="vb-subtitle">
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
        <button type="button" id="delete-billing-btn" data-num="<?= htmlspecialchars($billing['billing_number']) ?>" class="vb-btn-delete">
          <?= icon('trash', 14) ?> Delete
        </button>
      </div>
    </div>

    <!-- MAIN GRID -->
    <div class="vb-grid">

      <!-- LEFT: STATEMENT + TIMELINE -->
      <div class="vb-left-col">
        <div class="card vb-statement" id="printable-statement">

          <!-- Print-only logo header -->
          <div class="vb-print-logo">
            <img src="<?= brand_logo('tg', '../../') ?>" alt="TG Customworks"/>
            <div class="vb-print-logo-divider"></div>
            <img src="<?= brand_logo('basic', '../../') ?>" alt="Basic Car Insurance"/>
            <div class="vb-print-logo-divider"></div>
            <div class="vb-print-logo-text"><?= htmlspecialchars(company_info($conn)['name']) ?><br>Official Billing Statement</div>
          </div>

          <!-- Statement header -->
          <div class="vb-stmt-top">
            <div>
              <div class="vb-stmt-title">BILLING STATEMENT</div>
              <div class="vb-stmt-meta">No. <?= htmlspecialchars($billing['billing_number']) ?> &nbsp;·&nbsp; <?= date('F d, Y', strtotime($billing['created_at'])) ?></div>
            </div>
            <?php if ($billing['sent_at']): ?>
            <div class="vb-sent">
              <div class="vb-stmt-meta">Sent to insurer</div>
              <div class="vb-sent-date"><?= date('M d, Y', strtotime($billing['sent_at'])) ?></div>
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
            <div class="vb-prepared">
              Prepared by: <strong><?= htmlspecialchars($billing['created_by_name'] ?? '—') ?></strong>
            </div>
            <div class="vb-sig-block">
              <div class="vb-sig-label"><?= htmlspecialchars($billing['signed_by_title'] ?: BILLING_SIGNER_TITLE) ?></div>
              <div class="vb-sig-name"><?= htmlspecialchars($billing['signed_by_name'] ?? '') ?></div>
            </div>
          </div>
        </div>

        <!-- TIMELINE -->
        <div class="card">
          <div class="card-header">
            <div class="card-icon"><?= icon('clock', 16) ?></div>
            <div><div class="card-title">Timeline</div></div>
          </div>
          <div class="vb-timeline">
            <?php
            $tl = [
                ['Created',      date('M d, Y g:i A', strtotime($billing['created_at']))],
                ['Last Updated', date('M d, Y g:i A', strtotime($billing['updated_at']))],
                ['Created By',   htmlspecialchars($billing['created_by_name'] ?? '—')],
            ];
            if ($billing['sent_at']) $tl[] = ['Sent to Insurer', date('M d, Y g:i A', strtotime($billing['sent_at']))];
            foreach ($tl as [$lbl, $val]): ?>
            <div>
              <div class="vb-tl-label"><?= $lbl ?></div>
              <div class="vb-tl-val"><?= $val ?></div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

      </div>

      <!-- RIGHT: CONTROLS -->
      <div>

        <!-- QUICK INFO -->
        <div class="card">
          <div class="card-header">
            <div class="card-icon"><?= icon('user', 16) ?></div>
            <div>
              <div class="card-title">Client &amp; Policy</div>
              <div class="card-sub"><?= htmlspecialchars($billing['policy_number']) ?></div>
            </div>
          </div>
          <div class="vb-info-list">
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
            <div class="vb-info-links">
              <a href="../claims/view_claim.php?id=<?= $billing['claim_id'] ?>" class="btn-sm-gold"><?= icon('clipboard-list', 12) ?> Claim</a>
              <a href="../clients/view_client.php?id=<?= $billing['client_id'] ?>" class="btn-sm-gold"><?= icon('user', 12) ?> Client</a>
            </div>
          </div>
        </div>

        <!-- REQUIRED DOCUMENTS -->
        <div class="card">
          <div class="card-header">
            <div class="card-icon vb-icon-soft"><?= icon('paper-clip', 16) ?></div>
            <div>
              <div class="card-title">Required Documents</div>
              <div class="card-sub"><?= $docs_done ?>/<?= count($docs) ?> received</div>
            </div>
          </div>
          <form method="POST" action="" class="vb-docs-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_docs"/>
            <?php foreach ($docs as $d):
              $checked = $d['received']; ?>
            <label class="billing-doc-check <?= $checked ? 'checked' : '' ?>">
              <input type="checkbox" name="doc[]" value="<?= (int)$d['doc_type_id'] ?>" <?= $checked ? 'checked' : '' ?>
                onchange="this.closest('form').submit()" style="display:none;"/>
              <div class="billing-doc-tick <?= $checked ? 'done' : '' ?>"><?= $checked ? icon('check', 11) : '' ?></div>
              <div>
                <div class="billing-doc-name"><?= htmlspecialchars($d['name']) ?></div>
                <div class="billing-doc-hint"><?= htmlspecialchars((string)$d['hint']) ?></div>
              </div>
            </label>
            <?php endforeach; ?>
          </form>
        </div>

        <!-- EDIT DETAILS -->
        <div class="card">
          <div class="card-header">
            <div class="card-icon"><?= icon('pencil', 16) ?></div>
            <div>
              <div class="card-title">Edit Details</div>
              <div class="card-sub">Update costs, dates, or insurer</div>
            </div>
          </div>
          <form method="POST" action="" class="vb-edit-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_costs"/>

            <div>
              <label class="field-label">Billed To</label>
              <input type="text" name="billed_to" class="field-input" value="<?= htmlspecialchars($billing['billed_to']) ?>"/>
            </div>

            <div>
              <label class="field-label"><?= BILLING_SIGNER_TITLE ?></label>
              <?php
              $signers   = billing_signatories_active($conn);
              $on_list   = in_array($billing['signed_by_name'], array_column($signers, 'name'), true);
              ?>
              <select name="signatory_id" class="field-input">
                <?php if (!$on_list): ?>
                <option value=""><?= htmlspecialchars($billing['signed_by_name'] ?: '— None —') ?> (keep)</option>
                <?php endif; ?>
                <?php foreach ($signers as $sg): ?>
                <option value="<?= (int)$sg['signatory_id'] ?>" <?= $on_list && $sg['name'] === $billing['signed_by_name'] ? 'selected' : '' ?>><?= htmlspecialchars($sg['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="vb-two-col">
              <div>
                <label class="field-label">Incident Date</label>
                <input type="date" name="incident_date" class="field-input" value="<?= htmlspecialchars($billing['incident_date'] ?? '') ?>"/>
              </div>
              <div>
                <label class="field-label">Repair Date</label>
                <input type="date" name="repair_date" class="field-input" value="<?= htmlspecialchars($billing['repair_date'] ?? '') ?>"/>
              </div>
            </div>

            <div class="vb-two-col">
              <div>
                <label class="field-label">Total Parts</label>
                <div class="field-prefix-wrap">
                  <span class="field-prefix">₱</span>
                  <input type="number" name="parts_cost" class="field-input field-with-prefix" data-money max="<?= MAX_MONEY ?>"
                    value="<?= $billing['parts_cost'] ?>" min="0" step="0.01" id="ep_parts" oninput="editCalc()"/>
                </div>
              </div>
              <div>
                <label class="field-label">Labor &amp; Materials</label>
                <div class="field-prefix-wrap">
                  <span class="field-prefix">₱</span>
                  <input type="number" name="labor_cost" class="field-input field-with-prefix" data-money max="<?= MAX_MONEY ?>"
                    value="<?= $billing['labor_cost'] ?>" min="0" step="0.01" id="ep_labor" oninput="editCalc()"/>
                </div>
              </div>
              <div>
                <label class="field-label">Other Charges</label>
                <div class="field-prefix-wrap">
                  <span class="field-prefix">₱</span>
                  <input type="number" name="other_cost" class="field-input field-with-prefix" data-money max="<?= MAX_MONEY ?>"
                    value="<?= $billing['other_cost'] ?>" min="0" step="0.01" id="ep_other" oninput="editCalc()"/>
                </div>
              </div>
              <div>
                <label class="field-label">Less: Deductible</label>
                <div class="field-prefix-wrap">
                  <span class="field-prefix">₱</span>
                  <input type="number" name="deductible" class="field-input field-with-prefix" data-money max="<?= MAX_MONEY ?>"
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
              <textarea name="notes" class="field-input" rows="1" maxlength="<?= MAX_NOTE ?>"><?= htmlspecialchars($billing['notes'] ?? '') ?></textarea>
            </div>

            <div class="vb-form-actions">
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
      <button class="modal-close" onclick="document.getElementById('status-modal').classList.remove('open')" aria-label="Close"><?= icon('x-mark', 14) ?></button>
    </div>
    <form method="POST" action="">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update_status"/>
      <div class="vb-modal-body">
        <?php foreach (billing_statuses_active($conn) as $row_st):
          $val = $row_st['code'];
          $st  = billing_status_badge($conn, $val); ?>
        <label class="vb-status-opt <?= $billing['status'] === $val ? 'selected' : '' ?>">
          <input type="radio" name="status" value="<?= htmlspecialchars($val) ?>" <?= $billing['status'] === $val ? 'checked' : '' ?>
            onchange="document.querySelectorAll('.vb-status-opt').forEach(el=>el.classList.remove('selected'));this.closest('label').classList.add('selected')"
            style="display:none;"/>
          <div class="vb-status-opt-icon"><?= icon($st['icon'], 15) ?></div>
          <div>
            <span class="badge <?= $st['class'] ?>"><?= htmlspecialchars($st['label']) ?></span>
            <div class="vb-status-desc">
              <?= htmlspecialchars($st['desc']) ?>
            </div>
          </div>
        </label>
        <?php endforeach; ?>
      </div>
      <div class="vb-modal-actions">
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
    const t = document.getElementById('ep_total');
    t.textContent = '₱' + (p+l+o-d).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
    t.classList.toggle('is-negative', p+l+o-d < 0);   // the save would be refused: deductible > total cost
    t.title = p+l+o-d < 0 ? 'The deductible cannot be more than the total cost of repair.' : '';
}
document.addEventListener('keydown', e => { if (e.key==='Escape') document.getElementById('status-modal').classList.remove('open'); });
</script>

<!-- Delete: confirm, then the Transaction PIN, then a POST with the CSRF token (checked again on the server) -->
<form id="delete-billing-form" method="POST" action="" style="display:none;">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="delete_billing"/>
</form>
<script>
document.getElementById('delete-billing-btn').addEventListener('click', function () {
  const num = this.dataset.num;
  Swal.fire({
    icon: 'warning',
    title: 'Delete Billing Record?',
    text: num + ' will be permanently deleted. This cannot be undone.',
    showCancelButton: true,
    confirmButtonText: 'Yes, delete it',
    cancelButtonText: 'Cancel',
    confirmButtonColor: 'var(--danger)',
  }).then(async function (result) {
    if (!result.isConfirmed) return;
    if (!(await requirePin())) return;
    document.getElementById('delete-billing-form').submit();
  });
});
</script>

<?php require_once '../../includes/footer.php'; ?>
