<?php
require_once __DIR__ . "/../../config/session.php";
require_once '../../config/db.php';
require_once '../../config/validators.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header("Location: ../../auth/login.php");
    exit;
}

// ── CHECK ELIGIBLE CLAIMS (claims without billing) ──
$eligible_claims = $conn->query("
    SELECT COUNT(*) AS cnt
    FROM claims cl
    LEFT JOIN billing b ON b.claim_id = cl.claim_id
    WHERE cl.status IN ('loa_received','pending','approved','resolved')
      AND b.billing_id IS NULL
")->fetch_assoc()['cnt'] ?? 0;

// ── FILTERS ──
$search        = validate_search(san_str($_GET['search'] ?? '', MAX_SEARCH));
$filter_status = san_enum($_GET['status'] ?? 'all', ['all', 'draft', 'sent', 'paid', 'unpaid']);
$sort_by       = san_enum($_GET['sort']   ?? 'newest', ['newest', 'oldest']);

$where  = ["1=1"];
$params = [];
$types  = '';

if ($search !== '') {
    $like    = "%$search%";
    $where[] = "(b.billing_number LIKE ? OR c.full_name LIKE ? OR v.plate_number LIKE ? OR b.billed_to LIKE ?)";
    $params  = array_merge($params, [$like, $like, $like, $like]);
    $types  .= 'ssss';
}
if ($filter_status !== 'all') {
    $where[] = "b.status = ?";
    $params[] = $filter_status;
    $types   .= 's';
}

$where_sql = implode(' AND ', $where);
$order     = $sort_by === 'oldest' ? 'b.created_at ASC' : 'b.created_at DESC';

$sql = "
    SELECT b.billing_id, b.billing_number, b.billed_to, b.status,
           b.parts_cost, b.labor_cost, b.other_cost, b.deductible, b.total_amount_due,
           b.doc_release_of_claim, b.doc_drivers_license, b.doc_billing_statement,
           b.created_at, b.sent_at,
           b.claim_id,
           c.full_name, c.client_id,
           v.plate_number, v.make, v.model,
           ip.policy_number
    FROM billing b
    INNER JOIN claims cl          ON b.claim_id    = cl.claim_id
    INNER JOIN clients c          ON cl.client_id  = c.client_id
    INNER JOIN insurance_policies ip ON cl.policy_id  = ip.policy_id
    LEFT  JOIN vehicles v         ON ip.vehicle_id = v.vehicle_id
    WHERE $where_sql
    ORDER BY $order
";
$stmt = $conn->prepare($sql);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$status_map = [
    'draft'  => ['Draft',   'badge-gray'],
    'sent'   => ['Sent',    'badge-blue'],
    'paid'   => ['Paid',    'badge-green'],
    'unpaid' => ['Unpaid',  'badge-yellow'],
];

$page_title  = 'Billing';
$active_page = 'billing';
$base_path   = '../../';
$extra_css   = '<link rel="stylesheet" href="../../assets/css/shared/billing.css?v=' . @filemtime(__DIR__ . '/../../assets/css/shared/billing.css') . '"/>';
require_once '../../includes/header.php';
require_once '../../includes/navbar.php';
?>

<div class="main">
<?php
$topbar_title      = 'Billing';
$topbar_breadcrumb = ['Insurance', 'Billing'];
$topbar_show_clock = true;
require_once '../../includes/topbar.php';
?>

  <div class="content">

    <?php if (!empty($_GET['success'])): ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
      Swal.fire({ toast:true, position:'top-end', icon:'success', title:<?= json_encode(san_str($_GET['success'], 200)) ?>, showConfirmButton:false, timer:3500, timerProgressBar:true });
    });
    </script>
    <?php endif; ?>

    <!-- FILTERS -->
    <form method="GET" action="" style="margin-bottom:1rem;">
      <div style="display:flex;gap:0.6rem;align-items:center;flex-wrap:wrap;">
        <div style="position:relative;flex:1;min-width:200px;max-width:360px;">
          <span style="position:absolute;left:0.85rem;top:50%;transform:translateY(-50%);color:var(--text-muted);pointer-events:none;"><?= icon('magnifying-glass', 14) ?></span>
          <input type="text" name="search" class="filter-input"
            placeholder="Search by billing #, client, plate, or insurer..."
            value="<?= htmlspecialchars($search) ?>" style="padding-left:2.4rem;width:100%;"/>
        </div>
        <select name="status" class="filter-input" style="width:150px;">
          <option value="all"    <?= $filter_status==='all'    ?'selected':'' ?>>All Statuses</option>
          <option value="draft"  <?= $filter_status==='draft'  ?'selected':'' ?>>Draft</option>
          <option value="sent"   <?= $filter_status==='sent'   ?'selected':'' ?>>Sent</option>
          <option value="paid"   <?= $filter_status==='paid'   ?'selected':'' ?>>Paid</option>
          <option value="unpaid" <?= $filter_status==='unpaid' ?'selected':'' ?>>Unpaid</option>
        </select>
        <select name="sort" class="filter-input" style="width:145px;">
          <option value="newest" <?= $sort_by==='newest'?'selected':'' ?>>Newest First</option>
          <option value="oldest" <?= $sort_by==='oldest'?'selected':'' ?>>Oldest First</option>
        </select>
        <button type="submit" class="btn-primary"><?= icon('magnifying-glass', 14) ?> Search</button>
        <?php if ($search !== '' || $filter_status !== 'all'): ?>
        <a href="billing_list.php" class="btn-ghost"><?= icon('x-mark', 14) ?> Clear</a>
        <?php endif; ?>
      </div>
    </form>

    <!-- TABLE -->
    <div class="card">
      <div class="card-header">
        <div class="card-icon"><?= icon('document-text', 16) ?></div>
        <div>
          <div class="card-title">Billing Records</div>
          <div class="card-sub"><?= $result->num_rows ?> record<?= $result->num_rows !== 1 ? 's' : '' ?> found</div>
        </div>
        <div style="margin-left:auto;">
          <a href="add_billing.php" class="btn-primary"><?= icon('plus', 14) ?> New Billing</a>
        </div>
      </div>

      <?php if ($result->num_rows > 0): ?>
      <table class="tg-table">
        <thead>
          <tr>
            <th>Billing #</th>
            <th>Client / Vehicle</th>
            <th>Billed To</th>
            <th>Docs</th>
            <th style="text-align:right;">Cost of Repair</th>
            <th style="text-align:right;">Deductible</th>
            <th style="text-align:right;">Total Due</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php while ($row = $result->fetch_assoc()):
            $sb        = $status_map[$row['status']] ?? ['Unknown', 'badge-gray'];
            $docs_done = (int)$row['doc_release_of_claim'] + (int)$row['doc_drivers_license'] + (int)$row['doc_billing_statement'];
            $total_repair = (float)$row['parts_cost'] + (float)$row['labor_cost'] + (float)$row['other_cost'];
          ?>
          <tr>
            <td><span class="job-num"><?= htmlspecialchars($row['billing_number']) ?></span></td>
            <td>
              <div class="cell-primary"><?= htmlspecialchars($row['full_name']) ?></div>
              <div class="cell-sub"><?= htmlspecialchars($row['plate_number'] ?? '—') ?>
                <?php if ($row['make']): ?> · <?= htmlspecialchars($row['make'] . ' ' . $row['model']) ?><?php endif; ?>
              </div>
            </td>
            <td style="font-size:0.8rem;color:var(--text-secondary);"><?= htmlspecialchars($row['billed_to']) ?></td>
            <td>
              <div style="display:flex;justify-content:center;align-items:center;gap:3px;">
                <div class="doc-pip <?= $row['doc_release_of_claim']  ? 'filled' : 'empty' ?>" title="Release of Claim"></div>
                <div class="doc-pip <?= $row['doc_drivers_license']   ? 'filled' : 'empty' ?>" title="Driver's License"></div>
                <div class="doc-pip <?= $row['doc_billing_statement'] ? 'filled' : 'empty' ?>" title="Billing Statement"></div>
                <span style="font-size:0.7rem;color:var(--text-muted);margin-left:3px;"><?= $docs_done ?>/3</span>
              </div>
            </td>
            <td style="text-align:right;font-size:0.82rem;">₱<?= number_format($total_repair, 2) ?></td>
            <td style="text-align:right;font-size:0.82rem;color:var(--danger);">−₱<?= number_format($row['deductible'], 2) ?></td>
            <td style="text-align:right;font-weight:700;color:var(--gold);">₱<?= number_format($row['total_amount_due'], 2) ?></td>
            <td><span class="badge <?= $sb[1] ?>"><?= $sb[0] ?></span></td>
            <td>
              <div style="display:flex;align-items:center;gap:0.35rem;justify-content:center;">
                <a href="view_billing.php?id=<?= $row['billing_id'] ?>" class="btn-sm-gold" title="View">
                  <?= icon('eye', 14) ?>
                </a>
                <button type="button" class="btn-sm-danger js-delete-billing" title="Delete"
                  data-id="<?= $row['billing_id'] ?>" data-num="<?= htmlspecialchars($row['billing_number']) ?>">
                  <?= icon('trash', 14) ?>
                </button>
              </div>
            </td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
      <?php else: ?>
      <div class="empty-state">
        <div class="empty-icon"><?= icon('document-text', 32) ?></div>
        <div class="empty-title"><?= ($search !== '' || $filter_status !== 'all') ? 'No records found' : 'No billing records yet' ?></div>
        <div class="empty-desc">
          <?= ($search !== '' || $filter_status !== 'all') ? 'Try adjusting your search or filters.' : 'Create a billing record after a claim is approved.' ?>
        </div>
      </div>
      <?php endif; ?>
    </div>

  </div>
</div>

<script>
document.querySelectorAll('.js-delete-billing').forEach(function(btn) {
  btn.addEventListener('click', function() {
    const id  = this.dataset.id;
    const num = this.dataset.num;
    Swal.fire({
      icon: 'warning',
      title: 'Delete Billing Record?',
      text: num + ' will be permanently deleted. This cannot be undone.',
      showCancelButton: true,
      confirmButtonText: 'Yes, delete it',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#c0392b',
      cancelButtonColor: '#6c757d',
    }).then(async function(result) {
      if (result.isConfirmed) {
        const ok = await requirePin();
        if (!ok) return;
        window.location = 'view_billing.php?id=' + id + '&do_delete=1';
      }
    });
  });
});
</script>

<?php require_once '../../includes/footer.php'; ?>
