<?php
require_once __DIR__ . "/../../config/session.php";
require_once '../../config/db.php';
require_once '../../config/validators.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin', 'mechanic'])) {
    header("Location: ../../auth/login.php");
    exit;
}
$is_mechanic = $_SESSION['role'] === 'mechanic';

$client_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($client_id === 0) {
    header("Location: client_list.php");
    exit;
}

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_client_id'])) {
    csrf_verify();
    $del_id = (int)$_POST['delete_client_id'];
    $cstmt  = $conn->prepare("SELECT full_name FROM clients WHERE client_id = ?");
    $cstmt->bind_param('i', $del_id);
    $cstmt->execute();
    $cdata = $cstmt->get_result()->fetch_assoc();
    if ($cdata) {
        $dstmt = $conn->prepare("DELETE FROM clients WHERE client_id = ?");
        $dstmt->bind_param('i', $del_id);
        $dstmt->execute();
        $log  = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'CLIENT_DELETED', ?)");
        $desc = ($_SESSION['full_name'] ?? 'Unknown') . ' deleted client "' . $cdata['full_name'] . '" and all associated records.';
        $log->bind_param('is', $_SESSION['user_id'], $desc);
        $log->execute();
        header("Location: client_list.php?success=" . urlencode('"' . $cdata['full_name'] . '" has been deleted.'));
        exit;
    }
}

// ── HANDLE DOC UPLOAD ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_doc') {
    csrf_verify();
    if (!$is_mechanic && !empty($_FILES['policy_doc']['tmp_name'])) {
        $tmp  = $_FILES['policy_doc']['tmp_name'];
        $orig = basename($_FILES['policy_doc']['name']);
        $mime = mime_content_type($tmp);
        if ($mime === 'application/pdf') {
            $upload_dir = __DIR__ . '/../../uploads/client_docs/' . $client_id . '/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $fname = uniqid('doc_', true) . '.pdf';
            if (move_uploaded_file($tmp, $upload_dir . $fname)) {
                $ins = $conn->prepare("INSERT INTO client_documents (client_id, file_name, original_name, uploaded_by) VALUES (?,?,?,?)");
                $ins->bind_param('issi', $client_id, $fname, $orig, $_SESSION['user_id']);
                $ins->execute();
            }
        }
    }
    header("Location: view_client.php?id=$client_id&success=Document uploaded.");
    exit;
}

// ── HANDLE DOC DELETE ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_doc') {
    csrf_verify();
    if (!$is_mechanic) {
        $doc_id = (int)($_POST['doc_id'] ?? 0);
        if ($doc_id) {
            $fd = $conn->prepare("SELECT file_name FROM client_documents WHERE doc_id = ? AND client_id = ?");
            $fd->bind_param('ii', $doc_id, $client_id);
            $fd->execute();
            $fd_row = $fd->get_result()->fetch_assoc();
            if ($fd_row) {
                $path = __DIR__ . '/../../uploads/client_docs/' . $client_id . '/' . $fd_row['file_name'];
                if (file_exists($path)) unlink($path);
                $del = $conn->prepare("DELETE FROM client_documents WHERE doc_id = ?");
                $del->bind_param('i', $doc_id);
                $del->execute();
            }
        }
    }
    header("Location: view_client.php?id=$client_id&success=Document removed.");
    exit;
}

// Load client
$stmt = $conn->prepare("SELECT * FROM clients WHERE client_id = ?");
$stmt->bind_param('i', $client_id);
$stmt->execute();
$client = $stmt->get_result()->fetch_assoc();

if (!$client) {
    header("Location: client_list.php?error=Client not found.");
    exit;
}

// Load vehicles
$vstmt = $conn->prepare("SELECT * FROM vehicles WHERE client_id = ? ORDER BY vehicle_id DESC");
$vstmt->bind_param('i', $client_id);
$vstmt->execute();
$vehicles = $vstmt->get_result();

// Load claims
$clstmt = $conn->prepare("
    SELECT cl.claim_id, cl.claim_type, cl.status, cl.incident_date, cl.created_at, cl.denial_reason,
           ip.policy_number, v.plate_number, v.make, v.model
    FROM claims cl
    INNER JOIN insurance_policies ip ON cl.policy_id = ip.policy_id
    LEFT  JOIN vehicles v ON ip.vehicle_id = v.vehicle_id
    WHERE cl.client_id = ?
    ORDER BY cl.created_at DESC
");
$clstmt->bind_param('i', $client_id);
$clstmt->execute();
$claims = $clstmt->get_result();

// Load repair jobs
$rjstmt = $conn->prepare("
    SELECT j.job_id, j.job_number, j.service_type, j.status, j.repair_date, j.release_date,
           v.plate_number, v.make, v.model
    FROM repair_jobs j
    INNER JOIN vehicles v ON j.vehicle_id = v.vehicle_id
    WHERE j.client_id = ?
    ORDER BY j.created_at DESC
");
$rjstmt->bind_param('i', $client_id);
$rjstmt->execute();
$repair_jobs = $rjstmt->get_result();

// Load policies
$pstmt = $conn->prepare("
    SELECT p.*, v.plate_number, v.make, v.model, v.year_model
    FROM insurance_policies p
    INNER JOIN vehicles v ON p.vehicle_id = v.vehicle_id
    WHERE p.client_id = ?
    ORDER BY p.created_at DESC
");
$pstmt->bind_param('i', $client_id);
$pstmt->execute();
$policies = $pstmt->get_result();

// ── FETCH DOCUMENTS ──
$doc_stmt = $conn->prepare("SELECT doc_id, file_name, original_name, uploaded_at FROM client_documents WHERE client_id = ? ORDER BY uploaded_at DESC");
$doc_stmt->bind_param('i', $client_id);
$doc_stmt->execute();
$documents = $doc_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ── CHECK IF CLIENT HAS POLICIES ──
$has_policies = $conn->prepare("SELECT 1 FROM insurance_policies WHERE client_id = ? LIMIT 1");
$has_policies->bind_param('i', $client_id);
$has_policies->execute();
$has_policies = (bool)$has_policies->get_result()->num_rows;

$page_title  = 'View Client';
$active_page = 'clients';
$base_path   = '../../';
require_once '../../includes/header.php';
require_once '../../includes/navbar.php';
?>

<link rel="stylesheet" href="../../assets/css/shared/clients.css"/>

<div class="main">

<?php
$topbar_title      = 'Client Profile';
$topbar_breadcrumb = ['Records', 'Clients', htmlspecialchars($client['full_name'])];
require_once '../../includes/topbar.php';
?>

  <div class="content">

    <a href="client_list.php" class="back-link"><?= icon('arrow-left', 14) ?> Back to Client Records</a>

    <?php if (!empty($_GET['success'])): ?>
    <script>
      document.addEventListener('DOMContentLoaded', function() {
        Swal.fire({ toast:true, position:'top-end', icon:'success', title:<?= json_encode($_GET['success']) ?>, showConfirmButton:false, timer:3000, timerProgressBar:true });
      });
    </script>
    <?php endif; ?>
    <?php if (!empty($_GET['error'])): ?>
    <script>
      document.addEventListener('DOMContentLoaded', function() {
        Swal.fire({
          icon: 'error',
          title: 'Error',
          text: <?= json_encode($_GET['error']) ?>,
          confirmButtonColor: '#B8860B'
        });
      });
    </script>
    <?php endif; ?>

    <!-- CLIENT HEADER BANNER -->
    <div style="background:var(--sidebar-bg);border-radius:12px;padding:1.5rem 1.75rem;margin-bottom:1.25rem;position:relative;overflow:hidden;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
      <div style="position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--gold-bright),var(--gold-muted),transparent);"></div>
      <div style="position:absolute;right:2rem;top:50%;transform:translateY(-50%);font-size:5rem;font-weight:800;color:rgba(212,160,23,0.05);pointer-events:none;"><?= icon('user', 28) ?></div>
      <div style="position:relative;z-index:1;">
        <div style="font-size:0.7rem;color:rgba(200,192,176,0.45);letter-spacing:1.5px;text-transform:uppercase;font-weight:600;margin-bottom:0.3rem;">Client Profile</div>
        <div style="font-size:1.4rem;font-weight:800;color:#fff;letter-spacing:-0.3px;margin-bottom:0.2rem;"><?= htmlspecialchars($client['full_name']) ?></div>
        <div style="font-size:0.78rem;color:rgba(200,192,176,0.5);">
           <?= htmlspecialchars($client['contact_number']) ?>
          <?php if ($client['email']): ?>
          &nbsp;&nbsp; <?= htmlspecialchars($client['email']) ?>
          <?php endif; ?>
        </div>
      </div>
      <?php if (!$is_mechanic): ?>
      <div style="position:relative;z-index:1;display:flex;gap:0.6rem;flex-shrink:0;">
        <a href="edit_client.php?id=<?= $client_id ?>" class="btn-ghost" style="background:rgba(255,255,255,0.05);border-color:rgba(255,255,255,0.1);color:rgba(200,192,176,0.7);">
          <?= icon('pencil', 14) ?> Edit Client
        </a>
        <form method="POST" action="" style="display:inline;">
          <?= csrf_field() ?>
          <input type="hidden" name="delete_client_id" value="<?= $client_id ?>"/>
          <button type="button"
             class="btn-ghost js-delete-client-profile"
             style="background:rgba(192,57,43,0.1);border-color:rgba(192,57,43,0.3);color:#E74C3C;"
             data-name="<?= htmlspecialchars($client['full_name'], ENT_QUOTES) ?>">
            <?= icon('trash', 14) ?> Delete
          </button>
        </form>
      </div>
      <?php endif; ?>
    </div>

    <!-- TOP GRID: Client Info + Quick Stats -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.25rem;">

      <!-- CLIENT INFO -->
      <div class="card" style="margin-bottom:0;">
        <div class="card-header">
          <div class="card-icon"><?= icon('user', 16) ?></div>
          <div>
            <div class="card-title">Client Information</div>
            <div class="card-sub">Personal details on record</div>
          </div>
        </div>
        <div style="padding:1.25rem 1.5rem;display:flex;flex-direction:column;gap:1rem;">
          <?php
          $info_rows = [
            ['Full Name',     $client['full_name']],
            [' Contact',       $client['contact_number']],
            [' Email',         $client['email'] ?: 'Not provided'],
            ['Address',       $client['address']],
            ['Date Added',    date('F d, Y', strtotime($client['created_at']))],
          ];
          foreach ($info_rows as $r): ?>
          <div style="display:flex;flex-direction:column;gap:0.15rem;">
            <div style="font-size:0.62rem;letter-spacing:1.2px;text-transform:uppercase;color:var(--text-muted);font-weight:700;"><?= $r[0] ?></div>
            <div style="font-size:0.88rem;font-weight:600;color:var(--text-primary);"><?= htmlspecialchars($r[1]) ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- QUICK STATS -->
      <div style="display:flex;flex-direction:column;gap:1rem;">
        <?php
        $vehicle_count = $conn->prepare("SELECT COUNT(*) as c FROM vehicles WHERE client_id = ?");
        $vehicle_count->bind_param('i', $client_id);
        $vehicle_count->execute();
        $vc = $vehicle_count->get_result()->fetch_assoc()['c'];

        $policy_count = $conn->prepare("SELECT COUNT(*) as c FROM insurance_policies WHERE client_id = ?");
        $policy_count->bind_param('i', $client_id);
        $policy_count->execute();
        $pc = $policy_count->get_result()->fetch_assoc()['c'];

        $active_policy = $conn->prepare("SELECT COUNT(*) as c FROM insurance_policies WHERE client_id = ? AND policy_end >= CURDATE()");
        $active_policy->bind_param('i', $client_id);
        $active_policy->execute();
        $apc = $active_policy->get_result()->fetch_assoc()['c'];

        $claim_count = $conn->prepare("SELECT COUNT(*) as c FROM claims WHERE client_id = ?");
        $claim_count->bind_param('i', $client_id);
        $claim_count->execute();
        $clc = $claim_count->get_result()->fetch_assoc()['c'];

        $quick_stats = [
          [icon('vehicle', 16),        $vc,  'Registered Vehicles', 'badge-gold'],
          [icon('document', 16),       $pc,  'Total Policies',      'badge-green'],
          [icon('check-circle', 16),   $apc, 'Active Policies',     'badge-green'],
          [icon('clipboard-list', 16), $clc, 'Total Claims',        'badge-gold'],
        ];
        foreach ($quick_stats as $qs): ?>
        <div class="card" style="margin-bottom:0;display:flex;align-items:center;gap:1rem;padding:1rem 1.25rem;">
          <div class="card-icon" style="width:42px;height:42px;border-radius:10px;font-size:1.1rem;flex-shrink:0;"><?= $qs[0] ?></div>
          <div>
            <div style="font-size:1.5rem;font-weight:800;color:var(--text-primary);line-height:1;letter-spacing:-0.5px;"><?= $qs[1] ?></div>
            <div style="font-size:0.7rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.5px;margin-top:0.15rem;"><?= $qs[2] ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

    </div>

    <!-- VEHICLES -->
    <div class="card">
      <div class="card-header">
        <div class="card-icon"><?= icon('vehicle', 16) ?></div>
        <div>
          <div class="card-title">Registered Vehicles</div>
          <div class="card-sub"><?= $vc ?> vehicle<?= $vc !== 1 ? 's' : '' ?> on record</div>
        </div>
        <?php if (!$is_mechanic): ?>
        <a href="add_vehicle.php?client_id=<?= $client_id ?>" class="btn-primary" style="margin-left:auto;padding:0.5rem 1rem;font-size:0.78rem;">
          <?= icon('plus', 14) ?> Add Vehicle
        </a>
        <?php endif; ?>
      </div>
      <?php if ($vehicles->num_rows > 0): ?>
      <div class="tg-table-wrap">
        <table class="tg-table">
          <thead>
            <tr>
              <th style="text-align:center;">Plate Number</th>
              <th style="text-align:center;">Make &amp; Model</th>
              <th style="text-align:center;">Year</th>
              <th style="text-align:center;">Color</th>
              <th style="text-align:center;">Engine No.</th>
              <th style="text-align:center;">Chassis No.</th>
              <th style="text-align:center;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php while ($v = $vehicles->fetch_assoc()): ?>
            <tr>
              <td style="text-align:center;"><span class="badge-dark"><?= htmlspecialchars($v['plate_number']) ?></span></td>
              <td style="font-weight:700;color:var(--text-primary);text-align:center;"><?= htmlspecialchars($v['make'] . ' ' . $v['model']) ?></td>
              <td style="text-align:center;"><?= htmlspecialchars($v['year_model']) ?></td>
              <td style="text-align:center;"><?= htmlspecialchars($v['color'] ?: 'N/A') ?></td>
              <td style="font-size:0.75rem;color:var(--text-muted);text-align:center;"><?= htmlspecialchars($v['motor_number'] ?: 'N/A') ?></td>
              <td style="font-size:0.75rem;color:var(--text-muted);text-align:center;"><?= htmlspecialchars($v['serial_number'] ?: 'N/A') ?></td>
              <td style="text-align:center;">
                <div style="display:inline-flex;gap:0.4rem;align-items:center;">
                  <?php if (!$is_mechanic): ?>
                  <a href="../insurance/eligibility_check.php?vehicle_id=<?= $v['vehicle_id'] ?>" class="btn-sm-gold" title="Check Policy" style="padding:0.35rem 0.55rem;">
                    <?= icon('shield-check', 14) ?>
                  </a>
                  <a href="edit_vehicle.php?id=<?= $v['vehicle_id'] ?>" class="btn-sm-gold" title="Edit" style="padding:0.35rem 0.55rem;">
                    <?= icon('pencil', 14) ?>
                  </a>
                  <form method="POST" action="delete_vehicle.php" style="display:inline;"
                        class="js-delete-vehicle-form"
                        data-plate="<?= htmlspecialchars($v['plate_number'], ENT_QUOTES) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="vehicle_id" value="<?= $v['vehicle_id'] ?>"/>
                    <button type="submit" class="btn-sm-danger" title="Delete">
                      <?= icon('trash', 14) ?>
                    </button>
                  </form>
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
        <div class="empty-icon"><?= icon('vehicle', 28) ?></div>
        <div class="empty-title">No vehicles yet</div>
        <div class="empty-desc">Add a vehicle to start processing insurance.</div>
        <?php if (!$is_mechanic): ?>
        <a href="add_vehicle.php?client_id=<?= $client_id ?>" class="btn-primary"><?= icon('plus', 14) ?> Add Vehicle</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- INSURANCE POLICIES -->
    <div class="card">
      <div class="card-header">
        <div class="card-icon"><?= icon('document', 16) ?></div>
        <div>
          <div class="card-title">Insurance Policies</div>
          <div class="card-sub"><?= $pc ?> polic<?= $pc !== 1 ? 'ies' : 'y' ?> on record</div>
        </div>
        <?php if (!$is_mechanic): ?><a href="../insurance/eligibility_check.php" class="btn-primary" style="margin-left:auto;padding:0.5rem 1rem;font-size:0.78rem;"><?= icon('shield-check', 14) ?> Check Eligibility</a><?php endif; ?>
      </div>
      <?php if ($policies->num_rows > 0): ?>
      <div class="tg-table-wrap">
        <table class="tg-table">
          <thead>
            <tr>
              <th style="text-align:center;">Policy Number</th>
              <th style="text-align:center;">Vehicle</th>
              <th style="text-align:center;">Coverage</th>
              <th style="text-align:center;">Mortgagee</th>
              <th style="text-align:center;">Period</th>
              <th style="text-align:right;">Total Premium</th>
              <th style="text-align:right;">Balance</th>
              <th style="text-align:center;">Status</th>
            </tr>
          </thead>
          <tbody>
            <?php while ($p = $policies->fetch_assoc()):
              $today     = new DateTime();
              $end_date  = new DateTime($p['policy_end']);
              $days_left = (int)$today->diff($end_date)->format('%r%a');

              if ($days_left < 0) {
                $status_badge = '<span class="badge badge-gray">Expired</span>';
              } elseif ($days_left <= 7) {
                $status_badge = '<span class="badge badge-red">Urgent - ' . $days_left . 'd left</span>';
              } elseif ($days_left <= 30) {
                $status_badge = '<span class="badge badge-yellow">Expiring - ' . $days_left . 'd left</span>';
              } else {
                $status_badge = '<span class="badge badge-green">Active</span>';
              }

              $pay_badge = match($p['payment_status']) {
                'Paid'    => '<span class="badge badge-green">Paid</span>',
                'Partial' => '<span class="badge badge-yellow">Partial</span>',
                'Overdue' => '<span class="badge badge-orange">Overdue</span>',
                default   => '<span class="badge badge-red">Unpaid</span>',
              };
              $view_url = '../../modules/renewal/view_policy.php?id=' . $p['policy_id'];
            ?>
            <tr style="cursor:pointer;" onclick="window.location='<?= $view_url ?>'">
              <td style="font-weight:700;color:var(--text-primary);font-size:0.78rem;text-align:center;"><?= htmlspecialchars($p['policy_number']) ?></td>
              <td style="text-align:center;">
                <span class="badge-dark"><?= htmlspecialchars($p['plate_number']) ?></span>
                <div style="font-size:0.72rem;color:var(--text-muted);margin-top:0.2rem;"><?= htmlspecialchars($p['make'] . ' ' . $p['model'] . ' ' . $p['year_model']) ?></div>
              </td>
              <td style="font-size:0.78rem;text-align:center;"><?= htmlspecialchars($p['coverage_type']) ?></td>
              <td style="text-align:center;font-size:0.78rem;">
                <?php if (!empty($p['mortgagee'])): ?>
                  <span style="font-weight:600;color:var(--text-primary);"><?= htmlspecialchars($p['mortgagee']) ?></span>
                <?php else: ?>
                  <span style="color:var(--text-muted);">None / Cash</span>
                <?php endif; ?>
              </td>
              <td style="font-size:0.75rem;color:var(--text-muted);white-space:nowrap;text-align:center;">
                <?= date('M d, Y', strtotime($p['policy_start'])) ?><br/>
                <?= date('M d, Y', strtotime($p['policy_end'])) ?>
              </td>
              <td style="font-weight:700;color:var(--text-primary);text-align:right;">&#8369;<?= number_format($p['total_premium'], 2) ?></td>
              <td style="text-align:right;">
                <?php if ($p['balance'] > 0): ?>
                <span style="color:var(--warning);font-weight:700;font-size:0.82rem;">&#8369;<?= number_format($p['balance'], 2) ?></span>
                <?php else: ?>
                <span style="color:var(--success);font-weight:700;font-size:0.82rem;"><?= icon('check', 14) ?> Cleared</span>
                <?php endif; ?>
              </td>
              <td style="text-align:center;"><?= $status_badge ?> <?= $pay_badge ?></td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
      <div class="empty-state">
        <div class="empty-icon"><?= icon('document', 28) ?></div>
        <div class="empty-title">No policies yet</div>
        <div class="empty-desc">Check vehicle eligibility first before encoding a policy.</div>
      </div>
      <?php endif; ?>
    </div>

    <!-- CLAIMS HISTORY -->
    <?php
    $claim_status_map = [
        'document_collection' => ['label' => 'Document Collection', 'class' => 'badge-yellow'],
        'submitted'           => ['label' => 'Submitted to Head Office', 'class' => 'badge-info'],
        'under_review'        => ['label' => 'Under Adjuster Review', 'class' => 'badge-orange'],
        'approved'            => ['label' => 'Approved', 'class' => 'badge-green'],
        'denied'              => ['label' => 'Denied', 'class' => 'badge-red'],
        'resolved'            => ['label' => 'Resolved', 'class' => 'badge-gray'],
    ];
    ?>
    <div class="card">
      <div class="card-header">
        <div class="card-icon"><?= icon('clipboard-list', 16) ?></div>
        <div>
          <div class="card-title">Claims History</div>
          <div class="card-sub"><?= $claims->num_rows ?> claim<?= $claims->num_rows !== 1 ? 's' : '' ?> on record</div>
        </div>
        <?php if (!$is_mechanic): ?>
        <a href="../claims/add_claim.php" class="btn-primary" style="margin-left:auto;padding:0.5rem 1rem;font-size:0.78rem;">
          <?= icon('plus', 14) ?> File New Claim
        </a>
        <?php endif; ?>
      </div>
      <?php if ($claims->num_rows > 0): ?>
      <div class="tg-table-wrap">
        <table class="tg-table">
          <thead>
            <tr>
              <th>Policy / Vehicle</th>
              <th>Incident Date</th>
              <th>Filed</th>
              <th>Type / Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php while ($cl = $claims->fetch_assoc()):
              $cs = $claim_status_map[$cl['status']] ?? ['label' => $cl['status'], 'class' => 'badge-gray'];
            ?>
            <tr>
              <td>
                <div style="font-weight:700;font-size:0.82rem;color:var(--text-primary);"><?= htmlspecialchars($cl['policy_number']) ?></div>
                <div style="font-size:0.7rem;color:var(--text-muted);margin-top:0.1rem;">
                  <?= htmlspecialchars($cl['plate_number'] ?: '—') ?>
                  <?php if ($cl['make']): ?> &middot; <?= htmlspecialchars($cl['make'] . ' ' . $cl['model']) ?><?php endif; ?>
                </div>
              </td>
              <td style="font-size:0.78rem;color:var(--text-muted);white-space:nowrap;"><?= date('M d, Y', strtotime($cl['incident_date'])) ?></td>
              <td style="font-size:0.72rem;color:var(--text-muted);white-space:nowrap;"><?= date('M d, Y', strtotime($cl['created_at'])) ?></td>
              <td>
                <div style="display:flex;flex-direction:column;align-items:center;gap:0.3rem;">
                  <?php if ($cl['claim_type'] === 'major'): ?>
                    <span class="badge badge-red">Major / 3rd Party</span>
                  <?php else: ?>
                    <span class="badge badge-info">Minor</span>
                  <?php endif; ?>
                  <span class="badge <?= $cs['class'] ?>"><?= $cs['label'] ?></span>
                </div>
              </td>
              <td>
                <a href="../claims/view_claim.php?id=<?= $cl['claim_id'] ?>" class="btn-sm-gold" title="View Claim" style="padding:0.35rem 0.55rem;">
                  <?= icon('eye', 14) ?>
                </a>
              </td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
      <div class="empty-state">
        <div class="empty-icon"><?= icon('clipboard-list', 28) ?></div>
        <div class="empty-title">No claims filed</div>
        <div class="empty-desc">No insurance claims have been filed for this client yet.</div>
      </div>
      <?php endif; ?>
    </div>

    <!-- REPAIR JOBS -->
    <?php
    $repair_status_badges = [
        'pending'     => ['Pending',     'badge-yellow'],
        'in_progress' => ['In Progress', 'badge-blue'],
        'for_pickup'  => ['For Pickup',  'badge-gold'],
        'completed'   => ['Completed',   'badge-green'],
        'cancelled'   => ['Cancelled',   'badge-gray'],
    ];
    $repair_service_labels = [
        'repair_panel'   => 'Per Panel Repair',
        'repair_full'    => 'Full Body Repair',
        'paint_panel'    => 'Per Panel Paint',
        'paint_full'     => 'Full Body Paint',
        'washover_basic' => 'Basic Wash Over',
        'washover_full'  => 'Fully Wash Over',
        'custom'         => 'Custom / Mixed',
    ];
    ?>
    <div class="card">
      <div class="card-header" style="justify-content:space-between;">
        <div style="display:flex;align-items:center;gap:0.75rem;">
          <div class="card-icon"><?= icon('wrench', 16) ?></div>
          <div>
            <div class="card-title">Repair Jobs</div>
            <div class="card-sub">Repair history for this client</div>
          </div>
        </div>
        <a href="../repair/repair_list.php" class="btn-primary" style="margin-left:auto;padding:0.5rem 1rem;font-size:0.78rem;"><?= icon('wrench', 14) ?> All Jobs</a>
      </div>
      <?php if ($repair_jobs->num_rows > 0): ?>
      <table class="tg-table">
        <thead>
          <tr>
            <th>Job #</th>
            <th>Vehicle</th>
            <th>Service</th>
            <th>Repair Date</th>
            <th>Est. Release</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php while ($rj = $repair_jobs->fetch_assoc()):
              $rsb = $repair_status_badges[$rj['status']] ?? ['Unknown', 'badge-gray'];
              $rsv = $repair_service_labels[$rj['service_type']] ?? $rj['service_type'];
          ?>
          <tr>
            <td style="font-weight:700;color:var(--gold);font-size:0.8rem;"><?= htmlspecialchars($rj['job_number']) ?></td>
            <td>
              <div style="font-weight:600;font-size:0.82rem;"><?= htmlspecialchars($rj['plate_number']) ?></div>
              <div style="font-size:0.7rem;color:var(--text-muted);"><?= htmlspecialchars($rj['make'] . ' ' . $rj['model']) ?></div>
            </td>
            <td style="font-size:0.8rem;"><?= htmlspecialchars($rsv) ?></td>
            <td style="font-size:0.8rem;white-space:nowrap;"><?= date('M d, Y', strtotime($rj['repair_date'])) ?></td>
            <td style="font-size:0.8rem;white-space:nowrap;color:var(--text-muted);">
              <?= $rj['release_date'] ? date('M d, Y', strtotime($rj['release_date'])) : '—' ?>
            </td>
            <td><span class="badge <?= $rsb[1] ?>"><?= $rsb[0] ?></span></td>
            <td>
              <a href="../repair/view_repair.php?id=<?= $rj['job_id'] ?>" class="btn-sm-gold" title="View">
                <?= icon('eye', 14) ?>
              </a>
            </td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
      <?php else: ?>
      <div class="empty-state">
        <div class="empty-icon"><?= icon('wrench', 28) ?></div>
        <div class="empty-title">No repair jobs yet</div>
        <div class="empty-desc">No repair jobs have been filed for this client yet.</div>
      </div>
      <?php endif; ?>
    </div>

    <?php if ($has_policies): ?>
    <!-- POLICY DOCUMENTS -->
    <div class="card">
      <div class="card-header">
        <div class="card-icon"><?= icon('paper-clip', 16) ?></div>
        <div>
          <div class="card-title">Policy Documents</div>
          <div class="card-sub"><?= count($documents) ?> file<?= count($documents) !== 1 ? 's' : '' ?> attached</div>
        </div>
        <?php if (!$is_mechanic): ?>
        <button type="button" onclick="document.getElementById('doc-upload-panel').style.display=document.getElementById('doc-upload-panel').style.display==='none'?'block':'none'" class="btn-sm-gold" style="margin-left:auto;">
          <?= icon('plus', 13) ?> Attach PDF
        </button>
        <?php endif; ?>
      </div>

      <?php if (!$is_mechanic): ?>
      <div id="doc-upload-panel" style="display:none;padding:1rem 1.25rem;border-bottom:1px solid var(--border);background:var(--bg-2);">
        <form method="POST" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:0.75rem;">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="upload_doc"/>
          <label style="font-size:0.78rem;font-weight:600;color:var(--text-muted);">Select PDF file (policy document, LOA, etc.)</label>
          <input type="file" name="policy_doc" accept="application/pdf" required
            style="font-size:0.8rem;padding:0.5rem;border:1px dashed var(--gold-muted);border-radius:8px;background:var(--bg-3);color:var(--text-primary);width:100%;"/>
          <div style="display:flex;gap:0.5rem;justify-content:flex-end;">
            <button type="button" onclick="document.getElementById('doc-upload-panel').style.display='none'" class="btn-ghost" style="font-size:0.8rem;">Cancel</button>
            <button type="submit" class="btn-primary" style="font-size:0.8rem;"><?= icon('arrow-up-tray', 13) ?> Upload</button>
          </div>
        </form>
      </div>
      <?php endif; ?>

      <div style="padding:1rem 1.25rem;display:flex;flex-direction:column;gap:0.75rem;">
        <?php if (empty($documents)): ?>
        <div style="text-align:center;padding:1.5rem;color:var(--text-muted);font-size:0.82rem;">
          <?= icon('paper-clip', 24) ?><br/>No documents attached yet.
        </div>
        <?php else: ?>
        <?php foreach ($documents as $doc):
          $pdf_url = '../../uploads/client_docs/' . $client_id . '/' . htmlspecialchars($doc['file_name']);
        ?>
        <div style="border:1px solid var(--border);border-radius:10px;overflow:hidden;">
          <!-- File header row -->
          <div style="display:flex;align-items:center;gap:0.75rem;padding:0.65rem 1rem;background:var(--bg-2);">
            <div style="background:var(--danger-bg);color:var(--danger);border-radius:6px;padding:0.3rem 0.5rem;font-size:0.65rem;font-weight:700;">PDF</div>
            <div style="flex:1;min-width:0;">
              <div style="font-size:0.82rem;font-weight:600;color:var(--text-primary);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($doc['original_name']) ?></div>
              <div style="font-size:0.68rem;color:var(--text-muted);"><?= date('M d, Y g:i A', strtotime($doc['uploaded_at'])) ?></div>
            </div>
            <div style="display:flex;gap:0.4rem;flex-shrink:0;">
              <a href="<?= $pdf_url ?>" target="_blank" class="btn-sm-gold" style="font-size:0.72rem;padding:0.3rem 0.65rem;" title="Open in new tab">
                <?= icon('arrow-top-right-on-square', 13) ?>
              </a>
              <?php if (!$is_mechanic): ?>
              <form method="POST" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_doc"/>
                <input type="hidden" name="doc_id" value="<?= $doc['doc_id'] ?>"/>
                <button type="button" onclick="confirmDeleteDoc(this, '<?= htmlspecialchars($doc['original_name'], ENT_QUOTES) ?>')"
                  class="btn-sm-danger" style="font-size:0.72rem;padding:0.3rem 0.55rem;" title="Remove">
                  <?= icon('trash', 12) ?>
                </button>
              </form>
              <?php endif; ?>
            </div>
          </div>
          <!-- PDF Preview -->
          <div style="width:100%;height:480px;background:#1a1a1a;">
            <iframe src="<?= $pdf_url ?>" style="width:100%;height:100%;border:none;" loading="lazy"></iframe>
          </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

  </div>
</div>

<script src="../../assets/js/shared/view_client.js"></script>
<script>
function confirmDeleteDoc(btn, name) {
  const form = btn.closest('form');
  Swal.fire({
    title: 'Remove document?',
    text: '"' + name + '" will be permanently deleted.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#C0392B',
    cancelButtonColor: '#6B7280',
    confirmButtonText: 'Yes, remove',
  }).then(async r => { if (r.isConfirmed) { const ok = await requirePin(); if (ok) form.submit(); } });
}
</script>

<?php require_once '../../includes/footer.php'; ?>