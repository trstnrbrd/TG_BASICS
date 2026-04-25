<?php
require_once __DIR__ . "/../../config/session.php";
require_once '../../config/db.php';
require_once '../../config/validators.php';
require_once '../../includes/icons.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header("Location: ../../auth/login.php");
    exit;
}

// ── FILTERS ──
$search        = validate_search(san_str($_GET['search'] ?? '', MAX_SEARCH));
$filter_status = san_enum($_GET['status'] ?? 'all', ['all', 'compiling', 'sent_admin', 'sent_head_office', 'waiting_loa', 'loa_received', 'pending', 'approved', 'lack_of_requirements', 'denied', 'resolved']);
$filter_type   = san_enum($_GET['type'] ?? 'all', array_merge(['all'], ALLOWED_CLAIM_TYPES));
$sort_by       = $_GET['sort'] ?? 'newest';

$where   = ["1=1"];
$params  = [];
$types   = '';

if ($search !== '') {
    $like    = "%$search%";
    $where[] = "(c.full_name LIKE ? OR v.plate_number LIKE ? OR ip.policy_number LIKE ? OR v.make LIKE ? OR v.model LIKE ?)";
    $params  = array_merge($params, [$like, $like, $like, $like, $like]);
    $types  .= 'sssss';
}
if ($filter_status !== 'all') {
    $where[] = "cl.status = ?";
    $params[] = $filter_status;
    $types   .= 's';
}
if ($filter_type !== 'all') {
    $where[] = "cl.claim_type = ?";
    $params[] = $filter_type;
    $types   .= 's';
}

$where_sql = implode(' AND ', $where);
$order = match($sort_by) {
    'oldest' => 'cl.created_at ASC',
    default  => 'cl.created_at DESC',
};

$sql = "
    SELECT cl.claim_id, cl.claim_type, cl.status, cl.incident_date, cl.created_at,
           cl.doc_insurance_policy, cl.doc_or, cl.doc_cr, cl.doc_drivers_license, cl.doc_affidavit, cl.doc_estimate, cl.doc_damage_photos,
           c.full_name, c.client_id,
           v.plate_number, v.make, v.model,
           ip.policy_number, ip.coverage_type
    FROM claims cl
    INNER JOIN clients c  ON cl.client_id = c.client_id
    INNER JOIN insurance_policies ip ON cl.policy_id = ip.policy_id
    LEFT  JOIN vehicles v ON ip.vehicle_id = v.vehicle_id
    WHERE $where_sql
    ORDER BY $order
";

$stmt = $conn->prepare($sql);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$status_map = [
    'compiling'            => ['label' => 'Compiling Requirements', 'class' => 'badge-warning'],
    'sent_admin'           => ['label' => 'Sent to Admin',          'class' => 'badge-info'],
    'sent_head_office'     => ['label' => 'Sent to Head Office',    'class' => 'badge-orange'],
    'waiting_loa'          => ['label' => 'Waiting for LOA',        'class' => 'badge-blue'],
    'loa_received'         => ['label' => 'LOA Received',           'class' => 'badge-teal'],
    'pending'              => ['label' => 'Pending',                'class' => 'badge-yellow'],
    'approved'             => ['label' => 'Approved',               'class' => 'badge-success'],
    'denied'               => ['label' => 'Denied',                 'class' => 'badge-danger'],
    'lack_of_requirements' => ['label' => 'Lack of Requirements',   'class' => 'badge-danger'],
    'resolved'             => ['label' => 'Resolved',               'class' => 'badge-muted'],
];

$page_title  = 'Claims';
$active_page = 'claims';
$base_path   = '../../';
require_once '../../includes/header.php';
require_once '../../includes/navbar.php';
?>

<link rel="stylesheet" href="../../assets/css/shared/claims_list.css"/>

<div class="main">

<?php
$topbar_title      = 'Claims';
$topbar_breadcrumb = ['Insurance', 'Claims'];
require_once '../../includes/topbar.php';
?>

  <div class="content">

    <?php if (isset($_GET['success'])): ?>
    <script>
      document.addEventListener('DOMContentLoaded', function() {
        Swal.fire({ toast:true, position:'top-end', icon:'success', title:<?= json_encode($_GET['success']) ?>, showConfirmButton:false, timer:3000, timerProgressBar:true });
      });
    </script>
    <?php endif; ?>

    <!-- TOOLBAR -->
    <form method="GET" action="" style="margin-bottom:1rem;">
      <div style="display:flex;gap:0.6rem;align-items:center;flex-wrap:wrap;">
        <div style="position:relative;flex:1;min-width:200px;max-width:360px;">
          <span style="position:absolute;left:0.85rem;top:50%;transform:translateY(-50%);color:var(--text-muted);pointer-events:none;"><?= icon('magnifying-glass',14) ?></span>
          <input type="text" name="search" placeholder="Search by name, plate, policy, vehicle..."
            value="<?= htmlspecialchars($search) ?>" class="filter-input" style="padding-left:2.4rem;width:100%;"/>
        </div>
        <select name="status" class="filter-input" style="min-width:210px;">
          <option value="all"                  <?= $filter_status==='all'                 ?'selected':'' ?>>All Statuses</option>
          <option value="compiling"            <?= $filter_status==='compiling'           ?'selected':'' ?>>Compiling Requirements</option>
          <option value="sent_admin"           <?= $filter_status==='sent_admin'          ?'selected':'' ?>>Sent to Admin</option>
          <option value="sent_head_office"     <?= $filter_status==='sent_head_office'    ?'selected':'' ?>>Sent to Head Office</option>
          <option value="waiting_loa"          <?= $filter_status==='waiting_loa'         ?'selected':'' ?>>Waiting for LOA</option>
          <option value="loa_received"         <?= $filter_status==='loa_received'        ?'selected':'' ?>>LOA Received</option>
          <option value="pending"              <?= $filter_status==='pending'             ?'selected':'' ?>>Pending</option>
          <option value="approved"             <?= $filter_status==='approved'            ?'selected':'' ?>>Approved</option>
          <option value="lack_of_requirements" <?= $filter_status==='lack_of_requirements'?'selected':'' ?>>Lack of Requirements</option>
          <option value="denied"               <?= $filter_status==='denied'              ?'selected':'' ?>>Denied</option>
          <option value="resolved"             <?= $filter_status==='resolved'            ?'selected':'' ?>>Resolved</option>
        </select>
        <select name="type" class="filter-input" style="min-width:160px;">
          <option value="all"   <?= $filter_type==='all'?'selected':'' ?>>All Types</option>
          <option value="claims" <?= $filter_type==='claims'?'selected':'' ?>>Claims</option>
          <option value="repair" <?= $filter_type==='repair'?'selected':'' ?>>Repair</option>
        </select>
        <select name="sort" class="filter-input" style="min-width:140px;">
          <option value="newest" <?= $sort_by==='newest'?'selected':'' ?>>Newest First</option>
          <option value="oldest" <?= $sort_by==='oldest'?'selected':'' ?>>Oldest First</option>
        </select>
        <button type="submit" class="btn-primary"><?= icon('magnifying-glass',14) ?> Search</button>
        <?php if ($search || $filter_status !== 'all' || $filter_type !== 'all' || $sort_by !== 'newest'): ?>
        <a href="claims_list.php" class="btn-ghost"><?= icon('x-mark',14) ?> Clear</a>
        <?php endif; ?>
      </div>
    </form>

    <!-- TABLE -->
    <div class="card" style="margin-bottom:0;">
      <div class="card-header">
        <div class="card-icon"><?= icon('clipboard-list',16) ?></div>
        <div>
          <div class="card-title">All Claims</div>
          <div class="card-sub"><?= $result->num_rows ?> record<?= $result->num_rows !== 1 ? 's' : '' ?></div>
        </div>
        <div style="margin-left:auto;">
          <a href="add_claim.php" class="btn-primary"><?= icon('plus',14) ?> File New Claim</a>
        </div>
      </div>

      <?php if ($result->num_rows > 0): ?>
      <div class="tg-table-wrap">
        <table class="tg-table">
          <thead>
            <tr>
              <th style="text-align:left;">Client / Vehicle</th>
              <th style="text-align:center;">Policy No.</th>
              <th>Docs</th>
              <th>Incident Date</th>
              <th>Filed</th>
              <th>Type / Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php while ($row = $result->fetch_assoc()):
              $req_docs  = 7;
              $docs_done = (int)$row['doc_insurance_policy'] + (int)$row['doc_or'] + (int)$row['doc_cr']
                         + (int)$row['doc_drivers_license'] + (int)$row['doc_affidavit']
                         + (int)$row['doc_estimate'] + (int)$row['doc_damage_photos'];
              $s = $status_map[$row['status']] ?? ['label' => $row['status'], 'class' => 'badge-muted'];
              $is_finished = in_array($row['status'], ['resolved', 'denied', 'lack_of_requirements']);
            ?>
            <tr>
              <td>
                <div style="font-weight:700;color:var(--text-primary);font-size:0.85rem;"><?= htmlspecialchars($row['full_name']) ?></div>
                <div style="font-size:0.7rem;color:var(--text-muted);margin-top:0.1rem;">
                  <?= htmlspecialchars($row['plate_number'] ?: '—') ?>
                  <?php if ($row['make']): ?> &middot; <?= htmlspecialchars($row['make'] . ' ' . $row['model']) ?><?php endif; ?>
                </div>
              </td>
              <td style="font-size:0.78rem;color:var(--text-muted);text-align:center;"><?= htmlspecialchars($row['policy_number']) ?></td>
              <td>
                <div style="display:flex;justify-content:center;">
                  <div class="doc-progress" title="<?= $docs_done ?>/<?= $req_docs ?> documents received">
                    <div class="doc-pip <?= $row['doc_insurance_policy'] ? 'filled' : 'empty' ?>" title="Policy"></div>
                    <div class="doc-pip <?= $row['doc_or'] ? 'filled' : 'empty' ?>" title="OR"></div>
                    <div class="doc-pip <?= $row['doc_cr'] ? 'filled' : 'empty' ?>" title="CR"></div>
                    <div class="doc-pip <?= $row['doc_drivers_license'] ? 'filled' : 'empty' ?>" title="Driver's License"></div>
                    <div class="doc-pip <?= $row['doc_affidavit'] ? 'filled' : 'empty' ?>" title="Affidavit"></div>
                    <div class="doc-pip <?= $row['doc_estimate'] ? 'filled' : 'empty' ?>" title="Estimate"></div>
                    <div class="doc-pip <?= $row['doc_damage_photos'] ? 'filled' : 'empty' ?>" title="Photos"></div>
                    <span style="font-size:0.7rem;color:var(--text-muted);margin-left:0.35rem;"><?= $docs_done ?>/<?= $req_docs ?></span>
                  </div>
                </div>
              </td>
              <td style="font-size:0.78rem;color:var(--text-muted);white-space:nowrap;"><?= date('M d, Y', strtotime($row['incident_date'])) ?></td>
              <td style="font-size:0.72rem;color:var(--text-muted);white-space:nowrap;"><?= date('M d, Y', strtotime($row['created_at'])) ?></td>
              <td>
                <div style="display:flex;flex-direction:column;align-items:center;gap:0.3rem;">
                  <?php if ($row['claim_type'] === 'repair'): ?>
                    <span class="badge badge-danger" style="width:fit-content;">Repair</span>
                  <?php else: ?>
                    <span class="badge badge-info" style="width:fit-content;">Claims</span>
                  <?php endif; ?>
                  <span class="badge badge-muted" style="width:fit-content;font-size:0.6rem;"><?= htmlspecialchars($row['coverage_type']) ?></span>
                  <span class="badge <?= $s['class'] ?>" style="width:fit-content;"><?= $s['label'] ?></span>
                </div>
              </td>
              <td>
                <div style="display:flex;align-items:center;gap:0.35rem;justify-content:center;">
                  <a href="view_claim.php?id=<?= $row['claim_id'] ?>" class="btn-sm-gold" title="View" style="padding:0.35rem 0.55rem;">
                    <?= icon('eye',14) ?>
                  </a>
                  <?php if ($is_finished): ?>
                  <button type="button" class="btn-sm-danger js-delete-list"
                    title="Delete" data-id="<?= $row['claim_id'] ?>">
                    <?= icon('trash',14) ?>
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
        <div class="empty-icon"><?= icon('clipboard-list',28) ?></div>
        <div class="empty-title"><?= $search || $filter_status !== 'all' || $filter_type !== 'all' ? 'No results found' : 'No claims filed yet' ?></div>
        <div class="empty-desc"><?= $search || $filter_status !== 'all' || $filter_type !== 'all' ? 'Try adjusting your filters.' : 'File a new claim to get started.' ?></div>
      </div>
      <?php endif; ?>
    </div>

  </div>
</div>

<script src="../../assets/js/shared/claims_list.js?v=<?= filemtime(__DIR__.'/../../assets/js/shared/claims_list.js') ?>"></script>

<?php require_once '../../includes/footer.php'; ?>
