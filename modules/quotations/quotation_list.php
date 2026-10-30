<?php
require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . '/../../includes/data_access.php';
require_once '../../config/db.php';
require_once __DIR__ . '/../../includes/service_types.php';
require_once '../../config/validators.php';
require_once '../../config/access.php';
require_once '../../config/transaction_pin.php';
require_once '../../includes/pagination.php';
require_once '../../includes/transaction.php';

require_role(['admin', 'super_admin', 'mechanic']);
$is_admin = is_staff();

// ── DELETE ── behind the Transaction PIN prompt, so the server checks the PIN too
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    if (!$is_admin) { header("Location: quotation_list.php"); exit; }
    csrf_verify();
    if (!pin_consume($conn)) {
        header("Location: quotation_list.php?error=" . urlencode(PIN_REQUIRED_MSG));
        exit;
    }
    $del_id = san_int($_POST['quotation_id'] ?? 0, 1);
    if ($del_id) {
        $qt_number = quotation_number($conn, $del_id);
        if ($qt_number !== null) {
            db_transaction($conn, function () use ($conn, $del_id, $qt_number) {
                quotation_delete($conn, $del_id);
                $desc = ($_SESSION['full_name'] ?? 'Unknown') . ' deleted quotation ' . $qt_number . '.';
                audit_log_add($conn, $_SESSION['user_id'], 'QUOTATION_DELETED', $desc);
            });
        }
    }
    header("Location: quotation_list.php?success=" . urlencode('Quotation deleted.'));
    exit;
}

// ── FILTERS ── (same layout as the repair list: search, status dropdown, newest / oldest)
$filter  = san_enum($_GET['status'] ?? 'all', array_merge(['all'], array_keys(quotation_statuses())));
$search  = validate_search(san_str($_GET['search'] ?? '', MAX_SEARCH));
$sort_by = san_enum($_GET['sort'] ?? 'newest', ['newest', 'oldest']);

// One page at a time (includes/pagination.php)
[$rows, $pg] = quotations_list_page($conn, $filter, $search, $sort_by);

$service_labels = service_type_labels($conn);   // service_types table (includes/service_types.php)

$page_title  = 'Quotations & Receipts';
$active_page = 'quotations';
$base_path   = '../../';
$extra_css   = '<link rel="stylesheet" href="../../assets/css/shared/quotations.css?v=' . filemtime(__DIR__ . '/../../assets/css/shared/quotations.css') . '"/>';
require_once '../../includes/header.php';
require_once '../../includes/navbar.php';
?>

<div class="main">
<?php
$topbar_title      = 'Quotations & Receipts';
$topbar_breadcrumb = ['Repair Shop', 'Quotations & Receipts'];
require_once '../../includes/topbar.php';
?>

<div class="content">

<?php if (!empty($_GET['success'])): ?>
<script>
document.addEventListener('DOMContentLoaded',function(){
  Swal.fire({toast:true,position:'top-end',icon:'success',titleText:<?= json_encode(san_str($_GET['success'],200)) ?>,showConfirmButton:false,timer:3000,timerProgressBar:true});
});
</script>
<?php endif; ?>
<?php if (!empty($_GET['error'])): ?>
<script>
document.addEventListener('DOMContentLoaded',function(){
  Swal.fire({icon:'error',title:'Not deleted',text:<?= json_encode(san_str($_GET['error'],200)) ?>,confirmButtonColor:'#D4A017'});
});
</script>
<?php endif; ?>

<!-- FILTERS (same layout as the repair list) -->
<form method="GET" action="" class="rl-filter-form" style="margin-bottom:1rem;">
  <div class="rl-filter-inner" style="display:flex;gap:0.6rem;align-items:center;flex-wrap:wrap;">
    <div class="rl-filter-search" style="position:relative;flex:1;min-width:200px;max-width:360px;">
      <span style="position:absolute;left:0.85rem;top:50%;transform:translateY(-50%);color:var(--text-muted);pointer-events:none;"><?= icon('magnifying-glass', 14) ?></span>
      <input type="text" name="search" class="filter-input"
        placeholder="Search client, plate, quotation #..."
        value="<?= htmlspecialchars($search) ?>" style="padding-left:2.4rem;width:100%;"/>
    </div>
    <select name="status" class="filter-input rl-filter-status" style="width:170px;">
      <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>All Statuses</option>
      <?php foreach (quotation_statuses() as $key => $st): ?>
      <option value="<?= htmlspecialchars($key) ?>" <?= $filter === $key ? 'selected' : '' ?>><?= htmlspecialchars($st['label']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="sort" class="filter-input rl-filter-sort" style="width:140px;">
      <option value="newest" <?= $sort_by === 'newest' ? 'selected' : '' ?>>Newest First</option>
      <option value="oldest" <?= $sort_by === 'oldest' ? 'selected' : '' ?>>Oldest First</option>
    </select>
    <button type="submit" class="btn-primary rl-filter-btn"><?= icon('magnifying-glass', 14) ?> Search</button>
    <?php if ($search !== '' || $filter !== 'all' || $sort_by !== 'newest'): ?>
    <a href="quotation_list.php" class="btn-ghost rl-filter-clear"><?= icon('x-mark', 14) ?> Clear</a>
    <?php endif; ?>
  </div>
</form>

<!-- TABLE -->
<div class="card">
  <div class="card-header" style="justify-content:space-between;">
    <div style="display:flex;align-items:center;gap:0.75rem;">
      <div class="card-icon"><?= icon('receipt', 16) ?></div>
      <div>
        <div class="card-title">Quotations</div>
        <div class="card-sub"><?= paginate_summary($pg) ?></div>
      </div>
    </div>
    <a href="../repair/repair_list.php" class="btn-sm-gold"><?= icon('wrench', 12) ?> Repair Jobs</a>
  </div>

  <?php if ($rows): ?>
    <style>
    @media (min-width: 769px) {
      .mob-quotation-table thead th { padding: 0.45rem 0.75rem; }
      .mob-quotation-table tbody td { padding: 0.45rem 0.75rem; }
      .mob-quotation-table thead th:first-child,
      .mob-quotation-table tbody td:first-child { text-align: left !important; }
    }
    </style>
    <div class="tg-table-wrap mob-card-wrap">
    <table class="tg-table mob-card mob-quotation-table">
      <thead>
        <tr>
          <th style="text-align:left;">Client</th>
          <th style="text-align:center;">Plate</th>
          <th style="text-align:center;">Service</th>
          <th style="text-align:center;">Date</th>
          <th style="text-align:right;">Total</th>
          <th style="text-align:center;">Statement</th>
          <th style="text-align:center;">Status</th>
          <th style="text-align:center;">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $row):
          $sc = quotation_status_badge($row['status']);   // statuses table (includes/statuses.php)
        ?>
        <tr>
          <td style="text-align:left;">
            <div style="font-weight:700;font-size:0.82rem;color:var(--text-primary);"><?= htmlspecialchars($row['full_name']) ?></div>
            <div style="font-size:0.7rem;color:var(--text-muted);"><?= htmlspecialchars($row['contact_number']) ?></div>
          </td>
          <td style="text-align:center;"><span class="badge-dark"><?= htmlspecialchars($row['plate_number']) ?></span></td>
          <td style="text-align:center;font-size:0.75rem;color:var(--text-muted);"><?= htmlspecialchars($service_labels[$row['service_type']] ?? $row['service_type']) ?></td>
          <td style="text-align:center;font-size:0.75rem;color:var(--text-muted);white-space:nowrap;"><?= date('M d, Y', strtotime($row['created_at'])) ?></td>
          <td style="text-align:right;font-weight:700;font-size:0.82rem;color:var(--text-primary);">PHP <?= number_format($row['total'], 2) ?></td>
          <td style="text-align:center;">
            <?php if ($row['receipt_number']):
              $pc = receipt_status_badge($row['payment_status']);
            ?>
              <div style="font-size:0.73rem;color:var(--text-muted);font-family:monospace;"><?= htmlspecialchars($row['receipt_number']) ?></div>
              <span class="badge <?= $pc['class'] ?>" style="font-size:0.65rem;margin-top:0.15rem;"><?= htmlspecialchars($pc['label']) ?></span>
            <?php else: ?>
              <span style="color:var(--text-muted);font-size:0.75rem;">—</span>
            <?php endif; ?>
          </td>
          <td style="text-align:center;"><span class="badge <?= $sc['class'] ?>"><?= htmlspecialchars($sc['label']) ?></span></td>
          <td style="text-align:center;">
            <div style="display:flex;gap:0.35rem;justify-content:center;">
              <a href="view_quotation.php?id=<?= $row['quotation_id'] ?>" class="btn-sm-gold" title="View" style="padding:0.35rem 0.55rem;"><?= icon('eye', 14) ?></a>
              <?php if ($is_admin): ?>
              <button type="button" class="btn-sm-danger btn-delete-qt" data-id="<?= $row['quotation_id'] ?>" data-num="<?= htmlspecialchars($row['quotation_number']) ?>" title="Delete">
                <?= icon('trash', 13) ?>
              </button>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php render_pagination($pg); ?>
  <?php else: ?>
  <div class="empty-state">
    <div class="empty-icon-wrap"><?= icon('receipt', 26) ?></div>
    <div class="empty-title">No quotations found</div>
    <div class="empty-desc"><?= $search ? 'No results for your search.' : 'Generate a quotation from a repair job to get started.' ?></div>
  </div>
  <?php endif; ?>
</div>

</div>
</div>

<form id="delete-qt-form" method="POST" style="display:none;">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="delete"/>
  <input type="hidden" name="quotation_id" id="delete-qt-id"/>
</form>
<script>
document.querySelectorAll('.rl-filter-form select[name="status"], .rl-filter-form select[name="sort"]').forEach(function (select) {
  select.addEventListener('change', function () {
    this.form.submit();
  });
});
document.querySelectorAll('.btn-delete-qt').forEach(btn => {
  btn.addEventListener('click', function () {
    const id  = this.dataset.id;
    const num = this.dataset.num;
    Swal.fire({
      title: 'Delete ' + num + '?',
      text: 'This will permanently delete the quotation and its receipt if any.',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#C0392B',
      cancelButtonColor: '#6B7280',
      confirmButtonText: 'Yes, delete',
      cancelButtonText: 'Cancel',
    }).then(async result => {
      if (result.isConfirmed) {
        const ok = await requirePin();
        if (!ok) return;
        document.getElementById('delete-qt-id').value = id;
        document.getElementById('delete-qt-form').submit();
      }
    });
  });
});
</script>
<?php
$footer_scripts = '';
if (!empty($_GET['success'])) {
    $footer_scripts = 'Swal.fire({toast:true,position:"top-end",icon:"success",titleText:' . json_encode($_GET['success']) . ',showConfirmButton:false,timer:3000,timerProgressBar:true});';
}
require_once '../../includes/footer.php';
?>
