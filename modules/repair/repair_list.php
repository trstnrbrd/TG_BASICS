<?php
require_once __DIR__ . "/../../config/session.php";
require_once '../../config/db.php';
require_once '../../config/validators.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin', 'mechanic'])) {
    header("Location: ../../auth/login.php");
    exit;
}

$role = $_SESSION['role'];

// ── DELETE ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    if (!in_array($role, ['admin', 'super_admin'])) { header("Location: repair_list.php"); exit; }
    csrf_verify();
    $del_id = san_int($_POST['job_id'] ?? 0, 1);
    if ($del_id) {
        $job_row = $conn->prepare("SELECT job_number FROM repair_jobs WHERE job_id = ?");
        $job_row->bind_param('i', $del_id);
        $job_row->execute();
        $job_row = $job_row->get_result()->fetch_assoc();
        if ($job_row) {
            $del = $conn->prepare("DELETE FROM repair_jobs WHERE job_id = ?");
            $del->bind_param('i', $del_id);
            $del->execute();
            $log = $conn->prepare("INSERT INTO audit_logs (user_id,action,description) VALUES (?,'REPAIR_JOB_DELETED',?)");
            $desc = ($_SESSION['full_name'] ?? 'Unknown') . ' deleted repair job ' . $job_row['job_number'] . '.';
            $log->bind_param('is', $_SESSION['user_id'], $desc);
            $log->execute();
        }
    }
    header("Location: repair_list.php?success=" . urlencode('Repair job deleted.'));
    exit;
}

// ── FILTERS ──
$search        = validate_search(san_str($_GET['search'] ?? '', MAX_SEARCH));
$filter_status = san_enum($_GET['status'] ?? 'all', ['all', 'pending', 'in_progress', 'for_pickup', 'completed', 'cancelled']);
$sort_by       = san_enum($_GET['sort'] ?? 'newest', ['newest', 'oldest']);

// ── STATS ──
$count_stmt = $conn->prepare("SELECT
    SUM(status = 'in_progress') AS active,
    SUM(status = 'pending')     AS pending,
    SUM(status = 'completed')   AS completed,
    SUM(status = 'for_pickup')  AS pickup
FROM repair_jobs");
$count_stmt->execute();
$counts = $count_stmt->get_result()->fetch_assoc();
$stat_active    = (int)($counts['active']    ?? 0);
$stat_pending   = (int)($counts['pending']   ?? 0);
$stat_completed = (int)($counts['completed'] ?? 0);
$stat_pickup    = (int)($counts['pickup']    ?? 0);

// ── BUILD QUERY ──
$where  = ["1=1"];
$params = [];
$types  = '';

if ($search !== '') {
    $like    = "%$search%";
    $where[] = "(c.full_name LIKE ? OR v.plate_number LIKE ? OR j.job_number LIKE ?)";
    $params  = array_merge($params, [$like, $like, $like]);
    $types  .= 'sss';
}
if ($filter_status !== 'all') {
    $where[] = "j.status = ?";
    $params[] = $filter_status;
    $types   .= 's';
}

$where_sql = implode(' AND ', $where);
$order     = $sort_by === 'oldest' ? 'j.created_at ASC' : 'j.created_at DESC';

$sql = "
    SELECT j.job_id, j.job_number, j.service_type, j.status,
           j.repair_date, j.release_date,
           c.client_id, c.full_name, c.contact_number,
           v.vehicle_id, v.plate_number, v.make, v.model, v.year_model
    FROM repair_jobs j
    INNER JOIN clients  c ON j.client_id  = c.client_id
    INNER JOIN vehicles v ON j.vehicle_id = v.vehicle_id
    WHERE $where_sql
    ORDER BY $order
";
$stmt = $conn->prepare($sql);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$jobs = $stmt->get_result();

$service_labels = [
    'repair_panel'   => 'Per Panel Repair',
    'repair_full'    => 'Full Body Repair',
    'paint_panel'    => 'Per Panel Paint',
    'paint_full'     => 'Full Body Paint',
    'washover_basic' => 'Basic Wash Over',
    'washover_full'  => 'Fully Wash Over',
    'custom'         => 'Custom / Mixed',
];

$status_badges = [
    'pending'     => ['Pending',     'badge-yellow'],
    'in_progress' => ['In Progress', 'badge-blue'],
    'for_pickup'  => ['For Pickup',  'badge-gold'],
    'completed'   => ['Completed',   'badge-green'],
    'cancelled'   => ['Cancelled',   'badge-gray'],
];

$page_title  = 'Repair Jobs';
$active_page = 'repair';
$base_path   = '../../';
$extra_css   = '<link rel="stylesheet" href="../../assets/css/mechanic/repair_list.css?v=' . filemtime(__DIR__ . '/../../assets/css/mechanic/repair_list.css') . '"/>';
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
      Swal.fire({ toast:true, position:'top-end', icon:'success', title:<?= json_encode(san_str($_GET['success'], 200)) ?>, showConfirmButton:false, timer:3000, timerProgressBar:true });
    });
    </script>
    <?php endif; ?>

    <!-- STAT CARDS -->
    <?php
    $stats = [
        ['icon' => 'wrench',       'icon_bg' => 'var(--info-bg)',    'icon_color' => 'var(--info)',    'label' => 'In Progress', 'value' => $stat_active,    'trend' => 'Active jobs'],
        ['icon' => 'clock',        'icon_bg' => 'var(--warning-bg)', 'icon_color' => 'var(--warning)', 'label' => 'Pending',     'value' => $stat_pending,   'trend' => 'Awaiting start'],
        ['icon' => 'truck',        'icon_bg' => 'var(--gold-light)', 'icon_color' => 'var(--gold)',    'label' => 'For Pickup',  'value' => $stat_pickup,    'trend' => 'Ready for client'],
        ['icon' => 'check-circle', 'icon_bg' => 'var(--success-bg)', 'icon_color' => 'var(--success)', 'label' => 'Completed',   'value' => $stat_completed, 'trend' => 'All time'],
    ];
    ?>
    <div class="rl-stats">
      <?php foreach ($stats as $s): ?>
      <div class="rl-stat">
        <div class="rl-stat-icon" style="background:<?= $s['icon_bg'] ?>;color:<?= $s['icon_color'] ?>;">
          <?= icon($s['icon'], 20) ?>
        </div>
        <div class="rl-stat-body">
          <div class="rl-stat-value"><?= $s['value'] ?></div>
          <div class="rl-stat-label"><?= $s['label'] ?></div>
          <div class="rl-stat-trend"><?= $s['trend'] ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- FILTERS -->
    <form method="GET" action="" style="margin-bottom:1rem;">
      <div style="display:flex;gap:0.6rem;align-items:center;flex-wrap:wrap;">
        <div style="position:relative;flex:1;min-width:200px;max-width:360px;">
          <span style="position:absolute;left:0.85rem;top:50%;transform:translateY(-50%);color:var(--text-muted);pointer-events:none;"><?= icon('magnifying-glass', 14) ?></span>
          <input type="text" name="search" class="filter-input"
            placeholder="Search by client, plate, or job #..."
            value="<?= htmlspecialchars($search) ?>" style="padding-left:2.4rem;width:100%;"/>
        </div>
        <select name="status" class="filter-input" style="width:160px;">
          <option value="all"         <?= $filter_status === 'all'         ? 'selected' : '' ?>>All Statuses</option>
          <option value="pending"     <?= $filter_status === 'pending'     ? 'selected' : '' ?>>Pending</option>
          <option value="in_progress" <?= $filter_status === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
          <option value="for_pickup"  <?= $filter_status === 'for_pickup'  ? 'selected' : '' ?>>For Pickup</option>
          <option value="completed"   <?= $filter_status === 'completed'   ? 'selected' : '' ?>>Completed</option>
          <option value="cancelled"   <?= $filter_status === 'cancelled'   ? 'selected' : '' ?>>Cancelled</option>
        </select>
        <select name="sort" class="filter-input" style="width:140px;">
          <option value="newest" <?= $sort_by === 'newest' ? 'selected' : '' ?>>Newest First</option>
          <option value="oldest" <?= $sort_by === 'oldest' ? 'selected' : '' ?>>Oldest First</option>
        </select>
        <button type="submit" class="btn-primary"><?= icon('magnifying-glass', 14) ?> Search</button>
        <?php if ($search !== '' || $filter_status !== 'all'): ?>
        <a href="repair_list.php" class="btn-ghost"><?= icon('x-mark', 14) ?> Clear</a>
        <?php endif; ?>
      </div>
    </form>

    <!-- JOB TABLE -->
    <div class="card">
      <div class="card-header">
        <div class="card-icon"><?= icon('wrench', 16) ?></div>
        <div>
          <div class="card-title">Repair Jobs</div>
          <div class="card-sub"><?= $jobs->num_rows ?> job<?= $jobs->num_rows !== 1 ? 's' : '' ?> found</div>
        </div>
        <div style="margin-left:auto;">
          <a href="add_repair.php" class="btn-primary"><?= icon('plus', 14) ?> New Repair Job</a>
        </div>
      </div>

      <?php if ($jobs->num_rows > 0): ?>
      <table class="tg-table">
        <thead>
          <tr>
            <th>Job #</th>
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
          <?php while ($j = $jobs->fetch_assoc()):
              $sb  = $status_badges[$j['status']] ?? ['Unknown', 'badge-gray'];
              $svc = $service_labels[$j['service_type']] ?? $j['service_type'];
          ?>
          <tr>
            <td><span class="job-num"><?= htmlspecialchars($j['job_number']) ?></span></td>
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
            <td><span class="badge <?= $sb[1] ?>"><?= $sb[0] ?></span></td>
            <td>
              <div style="display:flex;gap:0.35rem;justify-content:center;">
                <a href="view_repair.php?id=<?= $j['job_id'] ?>" class="btn-sm-gold" title="View">
                  <?= icon('eye', 14) ?>
                </a>
                <?php if (in_array($role, ['admin','super_admin'])): ?>
                <button type="button" class="btn-sm-danger btn-delete-job" data-id="<?= $j['job_id'] ?>" data-num="<?= htmlspecialchars($j['job_number']) ?>" title="Delete">
                  <?= icon('trash', 13) ?>
                </button>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>

      <?php else: ?>
      <div class="empty-state">
        <div class="empty-icon"><?= icon('wrench', 32) ?></div>
        <div class="empty-title">No repair jobs found</div>
        <div class="empty-desc">
          <?= ($search !== '' || $filter_status !== 'all') ? 'Try adjusting your search or filters.' : 'Create the first repair job to get started.' ?>
        </div>
        <?php if ($search === '' && $filter_status === 'all'): ?>
        <a href="add_repair.php" class="btn-primary" style="margin-top:1rem;display:inline-flex;align-items:center;gap:0.4rem;">
          <?= icon('plus', 14) ?> New Repair Job
        </a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

  </div>
</div>

<?php if (in_array($role, ['admin','super_admin'])): ?>
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
    }).then(result => {
      if (result.isConfirmed) {
        document.getElementById('delete-job-id').value = id;
        document.getElementById('delete-job-form').submit();
      }
    });
  });
});
</script>
<?php endif; ?>
<?php require_once '../../includes/footer.php'; ?>
