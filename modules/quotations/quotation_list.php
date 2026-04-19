<?php
require_once __DIR__ . "/../../config/session.php";
require_once '../../config/db.php';
require_once '../../config/validators.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin', 'mechanic'])) {
    header("Location: ../../auth/login.php");
    exit;
}
$is_admin = in_array($_SESSION['role'], ['admin', 'super_admin']);

// ── DELETE ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    if (!$is_admin) { header("Location: quotation_list.php"); exit; }
    csrf_verify();
    $del_id = san_int($_POST['quotation_id'] ?? 0, 1);
    if ($del_id) {
        $qt_row = $conn->prepare("SELECT quotation_number FROM quotations WHERE quotation_id = ?");
        $qt_row->bind_param('i', $del_id);
        $qt_row->execute();
        $qt_row = $qt_row->get_result()->fetch_assoc();
        if ($qt_row) {
            $del = $conn->prepare("DELETE FROM quotations WHERE quotation_id = ?");
            $del->bind_param('i', $del_id);
            $del->execute();
            $log = $conn->prepare("INSERT INTO audit_logs (user_id,action,description) VALUES (?,'QUOTATION_DELETED',?)");
            $desc = ($_SESSION['full_name'] ?? 'Unknown') . ' deleted quotation ' . $qt_row['quotation_number'] . '.';
            $log->bind_param('is', $_SESSION['user_id'], $desc);
            $log->execute();
        }
    }
    header("Location: quotation_list.php?success=" . urlencode('Quotation deleted.'));
    exit;
}

// ── FILTERS ──
$filter = san_enum($_GET['filter'] ?? 'all', ['all','draft','pending_approval','approved','converted','cancelled']);
$search = validate_search(san_str($_GET['search'] ?? '', MAX_SEARCH));

// ── SUMMARY COUNTS ──
$counts = $conn->query("
    SELECT
        COUNT(*) AS total,
        SUM(status='draft')            AS draft,
        SUM(status='pending_approval') AS pending,
        SUM(status='approved')         AS approved,
        SUM(status='converted')        AS converted,
        SUM(status='cancelled')        AS cancelled
    FROM quotations
")->fetch_assoc();

// ── BUILD QUERY ──
$where = [];
$params = [];
$types  = '';

if ($filter !== 'all') {
    $where[]  = "q.status = ?";
    $params[] = $filter;
    $types   .= 's';
}
if ($search !== '') {
    $like     = "%$search%";
    $where[]  = "(c.full_name LIKE ? OR v.plate_number LIKE ? OR q.quotation_number LIKE ? OR j.job_number LIKE ?)";
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
    $types   .= 'ssss';
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "
    SELECT q.quotation_id, q.quotation_number, q.status, q.total, q.discount, q.created_at,
           j.job_id, j.job_number, j.service_type,
           c.full_name, c.contact_number,
           v.plate_number, v.make, v.model, v.year_model,
           r.receipt_number, r.payment_status
    FROM quotations q
    INNER JOIN repair_jobs j ON q.job_id = j.job_id
    INNER JOIN clients     c ON j.client_id  = c.client_id
    INNER JOIN vehicles    v ON j.vehicle_id = v.vehicle_id
    LEFT  JOIN receipts    r ON r.quotation_id = q.quotation_id
    $where_sql
    ORDER BY q.created_at DESC
";

$stmt = $conn->prepare($sql);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result();

$service_labels = [
    'repair_panel'   => 'Per Panel Repair',  'repair_full'    => 'Full Body Repair',
    'paint_panel'    => 'Per Panel Paint',   'paint_full'     => 'Full Body Paint',
    'washover_basic' => 'Basic Wash Over',   'washover_full'  => 'Fully Wash Over',
    'custom'         => 'Custom / Mixed',
];

$status_cfg = [
    'draft'            => ['Draft',            'badge-gray'],
    'pending_approval' => ['Pending Approval', 'badge-yellow'],
    'approved'         => ['Approved',         'badge-green'],
    'converted'        => ['Converted',        'badge-blue'],
    'cancelled'        => ['Cancelled',        'badge-red'],
];
$pay_cfg = [
    'unpaid'  => ['Unpaid',  'badge-red'],
    'partial' => ['Partial', 'badge-yellow'],
    'paid'    => ['Paid',    'badge-green'],
];

$page_title  = 'Quotations & Receipts';
$active_page = 'quotations';
$base_path   = '../../';
$extra_css   = '<link rel="stylesheet" href="../../assets/css/shared/quotations.css"/>';
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
  Swal.fire({toast:true,position:'top-end',icon:'success',title:<?= json_encode(san_str($_GET['success'],200)) ?>,showConfirmButton:false,timer:3000,timerProgressBar:true});
});
</script>
<?php endif; ?>

<!-- STAT CARDS -->
<div class="qt-stats">
  <?php
  $stat_rows = [
    ['all',      'document-text',  'Total',           $counts['total'],    'linear-gradient(90deg,#D4A017,#E8D5A3)', 'var(--gold-light)',  'var(--gold)'],
    ['pending_approval','clock',   'Pending Approval',$counts['pending'],  'linear-gradient(90deg,#9A6B00,#D4A017)', 'var(--warning-bg)', 'var(--warning)'],
    ['approved', 'check-circle',   'Approved',        $counts['approved'], 'linear-gradient(90deg,#2E7D52,#52B788)', 'var(--success-bg)', 'var(--success)'],
    ['converted','receipt',        'Converted',       $counts['converted'],'linear-gradient(90deg,#1A3A5C,#1A6B9A)', 'rgba(26,107,154,0.12)','#1A6B9A'],
  ];
  foreach ($stat_rows as [$key, $ico, $lbl, $val, $grad, $ibg, $icol]):
    $active_style = $filter === $key ? 'border-color:var(--gold-bright);background:var(--gold-pale);' : '';
  ?>
  <a href="?filter=<?= $key ?><?= $search ? '&search='.urlencode($search) : '' ?>" style="text-decoration:none;">
    <div class="qt-stat" style="<?= $active_style ?>">
      <div class="qt-stat-accent" style="background:<?= $grad ?>;"></div>
      <div class="qt-stat-icon" style="background:<?= $ibg ?>;color:<?= $icol ?>;"><?= icon($ico, 18) ?></div>
      <div class="qt-stat-body">
        <div class="qt-stat-value"><?= (int)$val ?></div>
        <div class="qt-stat-label"><?= $lbl ?></div>
      </div>
    </div>
  </a>
  <?php endforeach; ?>
</div>

<!-- FILTER TABS + SEARCH -->
<div class="card" style="margin-bottom:1.25rem;">
  <div style="padding:1rem 1.25rem;display:flex;gap:0.6rem;align-items:center;flex-wrap:wrap;">
    <div style="display:flex;gap:0.35rem;flex-wrap:wrap;flex:1;">
      <?php foreach (['all'=>'All', 'draft'=>'Draft', 'pending_approval'=>'Pending', 'approved'=>'Approved', 'converted'=>'Converted', 'cancelled'=>'Cancelled'] as $k => $l): ?>
      <a href="?filter=<?= $k ?><?= $search ? '&search='.urlencode($search) : '' ?>"
         class="<?= $filter === $k ? 'btn-primary' : 'btn-ghost' ?>"
         style="font-size:0.78rem;padding:0.35rem 0.9rem;"><?= $l ?></a>
      <?php endforeach; ?>
    </div>
    <form method="GET" style="display:flex;gap:0.5rem;align-items:center;">
      <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>"/>
      <div style="position:relative;">
        <span style="position:absolute;left:0.7rem;top:50%;transform:translateY(-50%);color:var(--text-muted);pointer-events:none;"><?= icon('magnifying-glass', 13) ?></span>
        <input type="text" name="search" class="filter-input" value="<?= htmlspecialchars($search) ?>"
          placeholder="Search client, plate, quotation #..." style="padding-left:2rem;min-width:220px;"/>
      </div>
      <button type="submit" class="btn-primary" style="font-size:0.8rem;"><?= icon('magnifying-glass', 13) ?> Search</button>
      <?php if ($search): ?><a href="?filter=<?= $filter ?>" class="btn-ghost" style="font-size:0.8rem;"><?= icon('x-mark', 13) ?> Clear</a><?php endif; ?>
    </form>
  </div>
</div>

<!-- TABLE -->
<div class="card">
  <div class="card-header" style="justify-content:space-between;">
    <div style="display:flex;align-items:center;gap:0.75rem;">
      <div class="card-icon"><?= icon('receipt', 16) ?></div>
      <div>
        <div class="card-title">Quotations</div>
        <div class="card-sub"><?= $rows->num_rows ?> record<?= $rows->num_rows !== 1 ? 's' : '' ?></div>
      </div>
    </div>
    <a href="../repair/repair_list.php" class="btn-sm-gold"><?= icon('wrench', 12) ?> Repair Jobs</a>
  </div>

  <?php if ($rows->num_rows > 0): ?>
  <div class="tg-table-wrap">
    <table class="tg-table">
      <thead>
        <tr>
          <th style="text-align:center;">Quotation #</th>
          <th style="text-align:center;">Client</th>
          <th style="text-align:center;">Plate</th>
          <th style="text-align:center;">Service</th>
          <th style="text-align:center;">Status</th>
          <th style="text-align:right;">Total</th>
          <th style="text-align:center;">Receipt</th>
          <th style="text-align:center;">Date</th>
          <th style="text-align:center;">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php while ($row = $rows->fetch_assoc()):
          $sc = $status_cfg[$row['status']] ?? ['Unknown','badge-gray'];
        ?>
        <tr>
          <td style="text-align:center;"><span class="qt-num"><?= htmlspecialchars($row['quotation_number']) ?></span></td>
          <td style="text-align:center;">
            <div style="font-weight:700;font-size:0.82rem;color:var(--text-primary);"><?= htmlspecialchars($row['full_name']) ?></div>
            <div style="font-size:0.7rem;color:var(--text-muted);"><?= htmlspecialchars($row['contact_number']) ?></div>
          </td>
          <td style="text-align:center;"><span class="badge-dark"><?= htmlspecialchars($row['plate_number']) ?></span></td>
          <td style="text-align:center;font-size:0.75rem;color:var(--text-muted);"><?= htmlspecialchars($service_labels[$row['service_type']] ?? $row['service_type']) ?></td>
          <td style="text-align:center;"><span class="badge <?= $sc[1] ?>"><?= $sc[0] ?></span></td>
          <td style="text-align:right;font-weight:700;font-size:0.82rem;color:var(--text-primary);">PHP <?= number_format($row['total'], 2) ?></td>
          <td style="text-align:center;">
            <?php if ($row['receipt_number']):
              $pc = $pay_cfg[$row['payment_status']] ?? ['—','badge-gray'];
            ?>
              <div style="font-size:0.73rem;color:var(--text-muted);font-family:monospace;"><?= htmlspecialchars($row['receipt_number']) ?></div>
              <span class="badge <?= $pc[1] ?>" style="font-size:0.65rem;margin-top:0.15rem;"><?= $pc[0] ?></span>
            <?php else: ?>
              <span style="color:var(--text-muted);font-size:0.75rem;">—</span>
            <?php endif; ?>
          </td>
          <td style="text-align:center;font-size:0.75rem;color:var(--text-muted);white-space:nowrap;"><?= date('M d, Y', strtotime($row['created_at'])) ?></td>
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
        <?php endwhile; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
  <div class="empty-state">
    <div class="empty-icon"><?= icon('receipt', 32) ?></div>
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
    }).then(result => {
      if (result.isConfirmed) {
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
    $footer_scripts = 'Swal.fire({toast:true,position:"top-end",icon:"success",title:' . json_encode($_GET['success']) . ',showConfirmButton:false,timer:3000,timerProgressBar:true});';
}
require_once '../../includes/footer.php';
?>
