<?php
require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . '/../../includes/data_access.php';
require_once '../../config/db.php';
require_once __DIR__ . '/../../includes/lookups.php';
require_once '../../config/validators.php';
require_once '../../config/access.php';
require_once '../../includes/pagination.php';
require_once '../../includes/transaction.php';
require_once '../../config/transaction_pin.php';
require_once '../../includes/list_toolbar.php';

require_role(['admin', 'super_admin', 'mechanic']);

// ── DELETE ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    if (!is_staff()) { header("Location: repair_list.php"); exit; }
    csrf_verify();
    if (!pin_consume($conn)) {   // behind the Transaction PIN prompt, so the server checks the PIN too
        header("Location: repair_list.php?error=" . urlencode(PIN_REQUIRED_MSG));
        exit;
    }
    $del_id = san_int($_POST['job_id'] ?? 0, 1);
    if ($del_id) {
        $job_number = repair_job_number($conn, $del_id);
        if ($job_number !== null) {
            db_transaction($conn, function () use ($conn, $del_id, $job_number) {
                repair_job_delete($conn, $del_id);
                $desc = ($_SESSION['full_name'] ?? 'Unknown') . ' deleted repair job ' . $job_number . '.';
                audit_log_add($conn, $_SESSION['user_id'], 'REPAIR_JOB_DELETED', $desc);
            });
        }
    }
    header("Location: repair_list.php?success=" . urlencode('Repair job deleted.'));
    exit;
}

// ── FILTERS ──
$search        = validate_search(san_str($_GET['search'] ?? '', MAX_SEARCH));
$filter_status = san_enum($_GET['status'] ?? 'all', array_merge(['all'], array_keys(repair_statuses())));
$sort_by       = san_enum($_GET['sort'] ?? 'newest', ['newest', 'oldest']);

// One page at a time (includes/pagination.php)
[$jobs, $pg] = repair_jobs_list_page($conn, $search, $filter_status, $sort_by);

$service_labels = service_type_labels($conn);   // service_types table (includes/lookups.php)


$page_title  = 'Repair Jobs';
$active_page = 'repair';
$base_path   = '../../';
$extra_css   = '<link rel="stylesheet" href="../../assets/css/mechanic/repair_list.css?v=' . filemtime(__DIR__ . '/../../assets/css/mechanic/repair_list.css') . '"/>' . lt_css();
require_once '../../includes/header.php';
require_once '../../includes/navbar.php';
?>

<div class="main">

<?php
$topbar_title      = 'Repair Jobs';
$topbar_breadcrumb = ['Repair Shop', 'Repair Jobs'];
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
      Swal.fire({ icon:'error', title:'Not deleted', text:<?= json_encode(san_str($_GET['error'], 200)) ?>, confirmButtonColor:'#D4A017' });
    });
    </script>
    <?php endif; ?>

    <!-- TOOLBAR (shared look: assets/css/shared/list_toolbar.css) -->
    <form method="GET" action="" class="list-toolbar repair-toolbar">
      <?= lt_search($search, 'Search by client ID, client, plate or Repair ID...', 'Search repair jobs') ?>
      <div class="lt-filters">
        <?php
        $status_opts = ['all' => 'All'];
        foreach (repair_statuses() as $key => $st) $status_opts[$key] = $st['label'];
        echo lt_pill('status', 'Status', $status_opts, $filter_status, 'all');
        echo lt_pill('sort', 'Sort', ['newest' => 'Newest', 'oldest' => 'Oldest'], $sort_by, 'newest');
        ?>
      </div>
      <?php if ($search !== '' || $filter_status !== 'all' || $sort_by !== 'newest'): ?>
      <?= lt_clear('repair_list.php') ?>
      <?php endif; ?>
      <div class="lt-actions">
        <a href="add_repair.php" class="btn-primary"><?= icon('plus', 14) ?> New Repair Job</a>
      </div>
    </form>

    <!-- JOB TABLE -->
    <div class="card">
      <div class="card-header">
        <div class="card-icon"><?= icon('wrench', 16) ?></div>
        <div>
          <div class="card-title">Repair Jobs</div>
          <div class="card-sub"><?= paginate_summary($pg, 'job') ?></div>
        </div>
      </div>

      <?php if ($jobs): ?>
      <table class="tg-table repair-jobs-table">
        <thead>
          <tr>
            <th>Repair ID</th>
            <th>Client</th>
            <th>Vehicle</th>
            <th>Service</th>
            <th>Repair Date</th>
            <th>Est. Release</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($jobs as $j):
              $sb  = repair_status_badge($j['status']);
              $svc = $service_labels[$j['service_type']] ?? $j['service_type'];
          ?>
          <tr>
            <td><span class="record-id"><?= htmlspecialchars($j['job_number']) ?></span></td>
            <td>
              <div class="cell-primary"><?= htmlspecialchars($j['full_name']) ?></div>
              <div class="cell-sub"><?= htmlspecialchars($j['contact_number'] ?? '') ?></div>
            </td>
            <td>
              <div class="cell-primary"><?= htmlspecialchars($j['plate_number']) ?></div>
              <div class="cell-sub"><?= htmlspecialchars(trim($j['year_model'] . ' ' . $j['make'] . ' ' . $j['model'])) ?></div>
            </td>
            <td><span class="cell-service"><?= htmlspecialchars($svc) ?></span></td>
            <td><span class="cell-date"><?= date('M d, Y', strtotime($j['repair_date'])) ?></span></td>
            <td><span class="cell-date-muted"><?= $j['release_date'] ? date('M d, Y', strtotime($j['release_date'])) : '—' ?></span></td>
            <td><span class="badge <?= $sb['class'] ?>"><?= htmlspecialchars($sb['label']) ?></span></td>
            <td>
              <div style="display:flex;gap:0.35rem;justify-content:center;">
                <a href="view_repair.php?id=<?= $j['job_id'] ?>" class="btn-sm-gold" title="View">
                  <?= icon('eye', 14) ?>
                </a>
                <?php if (is_staff()): ?>
                <button type="button" class="btn-sm-danger btn-delete-job" data-id="<?= $j['job_id'] ?>" data-num="<?= htmlspecialchars($j['job_number']) ?>" title="Delete">
                  <?= icon('trash', 13) ?>
                </button>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php render_pagination($pg); ?>

      <?php else: ?>
      <div class="empty-state">
        <div class="empty-icon-wrap"><?= icon('wrench', 26) ?></div>
        <div class="empty-title">No repair jobs found</div>
        <div class="empty-desc">
          <?= ($search !== '' || $filter_status !== 'all') ? 'Try adjusting your search or filters.' : 'Create the first repair job to get started.' ?>
        </div>

      </div>
      <?php endif; ?>
    </div>

  </div>
</div>

<?php if (is_staff()): ?>
<form id="delete-job-form" method="POST" style="display:none;">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="delete"/>
  <input type="hidden" name="job_id" id="delete-job-id"/>
</form>
<script>
document.querySelectorAll('.btn-delete-job').forEach(btn => {
  btn.addEventListener('click', function () {
    const id  = this.dataset.id;
    const num = this.dataset.num;
    Swal.fire({
      title: 'Delete ' + num + '?',
      text: 'This will permanently delete the repair job and all related records.',
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
        document.getElementById('delete-job-id').value = id;
        document.getElementById('delete-job-form').submit();
      }
    });
  });
});
</script>
<?php endif; ?>
<?php $footer_extra_scripts = ($footer_extra_scripts ?? '') . lt_js(); require_once '../../includes/footer.php'; ?>
