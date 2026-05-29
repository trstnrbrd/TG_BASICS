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
    $cstmt  = $conn->prepare("SELECT full_name FROM clients WHERE client_id = ? AND deleted_at IS NULL");
    $cstmt->bind_param('i', $del_id);
    $cstmt->execute();
    $cdata = $cstmt->get_result()->fetch_assoc();
    if ($cdata) {
        $dstmt = $conn->prepare("UPDATE clients SET deleted_at = NOW() WHERE client_id = ?");
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

// Load client (exclude soft-deleted)
$stmt = $conn->prepare("SELECT * FROM clients WHERE client_id = ? AND deleted_at IS NULL");
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

// Collect vehicle IDs for eligibility button
$_client_vehicle_ids = [];
$_vtmp = $conn->prepare("SELECT vehicle_id FROM vehicles WHERE client_id = ? ORDER BY vehicle_id DESC");
$_vtmp->bind_param('i', $client_id);
$_vtmp->execute();
$_vr = $_vtmp->get_result();
while ($_vrow = $_vr->fetch_assoc()) $_client_vehicle_ids[] = (int)$_vrow['vehicle_id'];
unset($_vtmp, $_vr, $_vrow);

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

    <a href="client_list.php" class="back-link" onclick="goBack('client_list.php'); return false;"><?= icon('arrow-left', 14) ?> Back to Client Records</a>

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

    <?php
    $public_token = $client['public_token'] ?? '';
    if (empty($public_token)) {
        $gen = $conn->prepare("UPDATE clients SET public_token = SHA2(CONCAT(?, UUID(), 'tgbasics'), 256) WHERE client_id = ? AND public_token IS NULL");
        $gen->bind_param('ii', $client_id, $client_id);
        $gen->execute();
        $rt = $conn->prepare("SELECT public_token FROM clients WHERE client_id = ?");
        $rt->bind_param('i', $client_id);
        $rt->execute();
        $public_token = $rt->get_result()->fetch_assoc()['public_token'] ?? '';
    }
    $public_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                . '://' . $_SERVER['HTTP_HOST']
                . '/TG-BASICS/modules/public/client.php?token=' . urlencode($public_token);
    ?>

    <!-- CLIENT HEADER BANNER -->
    <div style="background:var(--btn-bg);border-radius:12px;padding:1.5rem 1.75rem;margin-bottom:1.25rem;position:relative;overflow:hidden;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
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
    ?>

    <!-- CLIENT INFO CARD (with QR embedded on the right) -->
    <div class="card" style="margin-bottom:1.25rem;">
      <div class="card-header">
        <div class="card-icon"><?= icon('user', 16) ?></div>
        <div>
          <div class="card-title">Client Information</div>
          <div class="card-sub">Personal details on record</div>
        </div>
      </div>
      <div style="padding:1rem 1.5rem;display:grid;grid-template-columns:1fr 200px;gap:1.5rem;align-items:center;">

        <!-- Info fields -->
        <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:0.65rem 2rem;min-width:0;">
          <?php
          $info_rows = [
            ['Full Name',  $client['full_name']],
            ['Contact',    $client['contact_number']],
            ['Email',      $client['email'] ?: 'Not provided'],
            ['Address',    $client['address']],
            ['Date Added', date('F d, Y', strtotime($client['created_at']))],
          ];
          foreach ($info_rows as $r): ?>
          <div style="display:flex;flex-direction:column;gap:0.12rem;min-width:0;">
            <div style="font-size:0.6rem;letter-spacing:1.2px;text-transform:uppercase;color:var(--text-muted);font-weight:700;"><?= $r[0] ?></div>
            <div style="font-size:0.83rem;font-weight:600;color:var(--text-primary);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($r[1]) ?></div>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- QR Code -->
        <div style="border-left:1px solid var(--border);padding-left:0.75rem;display:flex;align-items:center;gap:0.5rem;">
          <div style="position:relative;background:#fff;padding:6px;border-radius:8px;border:1px solid var(--border);box-shadow:var(--shadow);flex-shrink:0;line-height:0;">
            <div id="qr-canvas-wrap" style="width:90px;height:90px;display:block;"></div>
            <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border-radius:50%;width:22px;height:22px;display:flex;align-items:center;justify-content:center;box-shadow:0 0 0 2px #fff;">
              <img src="<?= $base_path ?>assets/img/tg_logo.png" style="width:16px;height:16px;object-fit:contain;border-radius:50%;"/>
            </div>
          </div>
          <div style="display:flex;flex-direction:column;gap:0.35rem;min-width:0;">
            <div style="font-size:0.6rem;color:var(--text-muted);letter-spacing:1px;text-transform:uppercase;font-weight:700;">Digital ID</div>
            <div style="font-size:0.58rem;color:var(--text-muted);">Scan for profile</div>
            <a href="<?= htmlspecialchars($public_url) ?>" target="_blank" class="btn-ghost" style="font-size:0.68rem;padding:0.28rem 0.5rem;display:flex;align-items:center;gap:0.25rem;white-space:nowrap;">
              <?= icon('arrow-top-right-on-square', 11) ?> Preview
            </a>
            <button type="button" onclick="downloadQr()" class="btn-ghost" style="font-size:0.68rem;padding:0.28rem 0.5rem;display:flex;align-items:center;gap:0.25rem;white-space:nowrap;">
              <?= icon('arrow-down-tray', 11) ?> Download
            </button>
          </div>
        </div>

      </div>
    </div>

    <!-- STATS ROW -->
    <?php
    $quick_stats = [
      [icon('vehicle', 16),        $vc,  'Registered Vehicles'],
      [icon('document', 16),       $pc,  'Total Policies'],
      [icon('check-circle', 16),   $apc, 'Active Policies'],
      [icon('clipboard-list', 16), $clc, 'Total Claims'],
    ];
    ?>
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.25rem;">
      <?php foreach ($quick_stats as $qs): ?>
      <div class="card" style="margin-bottom:0;display:flex;align-items:center;gap:1rem;padding:1rem 1.25rem;">
        <div class="card-icon" style="width:42px;height:42px;border-radius:10px;flex-shrink:0;"><?= $qs[0] ?></div>
        <div>
          <div style="font-size:1.5rem;font-weight:800;color:var(--text-primary);line-height:1;letter-spacing:-0.5px;"><?= $qs[1] ?></div>
          <div style="font-size:0.7rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.5px;margin-top:0.15rem;"><?= $qs[2] ?></div>
        </div>
      </div>
      <?php endforeach; ?></div>

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
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1rem;padding:1.25rem;">
        <?php while ($v = $vehicles->fetch_assoc()):
          // Build Imagin Studio URL from vehicle data
          $img_make   = strtolower(trim($v['make']));
          $img_model  = strtolower(str_replace(' ', '-', trim($v['model'])));
          $img_year   = (int)($v['year_model'] ?? 0);
          // Map color name to imagin paint description
          $color_map = [
            'white'       => 'glacier-white',
            'black'       => 'midnight-black',
            'silver'      => 'star-silver',
            'gray'        => 'iron-grey',
            'grey'        => 'iron-grey',
            'red'         => 'flame-red',
            'blue'        => 'ocean-blue',
            'dark blue'   => 'deep-blue',
            'navy'        => 'deep-blue',
            'green'       => 'emerald-green',
            'brown'       => 'hazel-brown',
            'beige'       => 'pearl-beige',
            'orange'      => 'sunset-orange',
            'yellow'      => 'lightning-yellow',
            'maroon'      => 'vintage-maroon',
            'pearl'       => 'pearl-white',
            'champagne'   => 'champagne-gold',
          ];
          $color_key   = strtolower(trim($v['color'] ?? ''));
          $img_paint   = $color_map[$color_key] ?? '';
          $img_url     = 'https://cdn.imagin.studio/getimage?customer=hrjavascript-mastery'
                       . '&make=' . urlencode($img_make)
                       . '&modelFamily=' . urlencode($img_model)
                       . ($img_year ? '&modelYear=' . $img_year : '')
                       . ($img_paint ? '&paintdescription=' . urlencode($img_paint) : '')
                       . '&zoomType=fullscreen&angle=13';
        ?>
        <div style="border:1px solid var(--border);border-radius:12px;overflow:hidden;background:var(--bg-3);box-shadow:var(--shadow);display:flex;flex-direction:column;">

          <!-- 3D Car Image — drag to rotate -->
          <?php $vid = 'vcar-' . $v['vehicle_id']; ?>
          <div class="car3d-wrap" id="<?= $vid ?>"
               data-make="<?= htmlspecialchars($img_make) ?>"
               data-model="<?= htmlspecialchars($img_model) ?>"
               data-year="<?= $img_year ?>"
               data-paint="<?= htmlspecialchars($img_paint) ?>"
               style="background:linear-gradient(135deg,var(--bg-2),var(--bg));padding:1rem;position:relative;min-height:160px;display:flex;align-items:center;justify-content:center;cursor:grab;user-select:none;">
            <img class="car3d-img"
                 src="<?= htmlspecialchars($img_url) ?>"
                 alt="<?= htmlspecialchars($v['make'] . ' ' . $v['model']) ?>"
                 draggable="false"
                 style="width:100%;max-height:140px;object-fit:contain;pointer-events:none;transition:opacity 0.1s;"
                 onerror="this.style.display='none';this.nextElementSibling.style.display='flex'"/>
            <!-- Fallback -->
            <div class="car3d-fallback" style="display:none;flex-direction:column;align-items:center;justify-content:center;gap:0.4rem;color:var(--text-muted);font-size:0.72rem;height:120px;">
              <?= icon('vehicle', 36) ?>
              <span>No preview available</span>
            </div>
            <!-- Plate badge overlay -->
            <div style="position:absolute;bottom:0.6rem;left:0.75rem;">
              <span class="badge-dark" style="font-size:0.7rem;"><?= htmlspecialchars($v['plate_number']) ?></span>
            </div>
            <!-- Drag hint -->
            <div class="car3d-hint" style="position:absolute;bottom:0.6rem;right:0.75rem;font-size:0.6rem;color:var(--text-muted);display:flex;align-items:center;gap:0.25rem;opacity:0.7;">
              <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M5 9l-3 3 3 3M9 5l3-3 3 3M15 19l-3 3-3-3M19 9l3 3-3 3M2 12h20M12 2v20"/></svg>
              Drag to rotate
            </div>
          </div>

          <!-- Vehicle Details -->
          <div style="padding:0.85rem 1rem;flex:1;display:flex;flex-direction:column;gap:0.6rem;">
            <div>
              <div style="font-size:0.95rem;font-weight:800;color:var(--text-primary);letter-spacing:-0.2px;">
                <?= htmlspecialchars($v['make'] . ' ' . $v['model']) ?>
              </div>
              <div style="font-size:0.72rem;color:var(--text-muted);margin-top:0.1rem;">
                <?= htmlspecialchars($v['year_model'] ?: 'Year unknown') ?>
                <?php if ($v['color']): ?> &middot; <?= htmlspecialchars($v['color']) ?><?php endif; ?>
              </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.4rem 0.75rem;">
              <?php
              $vdetails = [
                ['Engine No.',  $v['motor_number']  ?: 'N/A'],
                ['Chassis No.', $v['serial_number'] ?: 'N/A'],
              ];
              foreach ($vdetails as [$vlabel, $vval]): ?>
              <div style="min-width:0;">
                <div style="font-size:0.58rem;letter-spacing:1px;text-transform:uppercase;font-weight:700;color:var(--text-muted);"><?= $vlabel ?></div>
                <div style="font-size:0.75rem;font-weight:600;color:var(--text-secondary);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($vval) ?>"><?= htmlspecialchars($vval) ?></div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Actions -->
          <div style="padding:0.65rem 1rem;border-top:1px solid var(--border);background:var(--bg-2);display:flex;gap:0.4rem;">
            <a href="view_vehicle.php?id=<?= $v['vehicle_id'] ?>" class="btn-sm-gold" title="View Vehicle" style="flex:1;justify-content:center;">
              <?= icon('eye', 13) ?> View
            </a>
            <?php if (!$is_mechanic): ?>
            <a href="../insurance/eligibility_check.php?vehicle_id=<?= $v['vehicle_id'] ?>" class="btn-sm-gold" title="Check Policy" style="padding:0.35rem 0.55rem;">
              <?= icon('shield-check', 13) ?>
            </a>
            <a href="edit_vehicle.php?id=<?= $v['vehicle_id'] ?>" class="btn-sm-gold" title="Edit" style="padding:0.35rem 0.55rem;">
              <?= icon('pencil', 13) ?>
            </a>
            <form method="POST" action="delete_vehicle.php" style="display:inline;"
                  class="js-delete-vehicle-form"
                  data-plate="<?= htmlspecialchars($v['plate_number'], ENT_QUOTES) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="vehicle_id" value="<?= $v['vehicle_id'] ?>"/>
              <button type="submit" class="btn-sm-danger" title="Delete" style="padding:0.35rem 0.55rem;">
                <?= icon('trash', 13) ?>
              </button>
            </form>
            <?php endif; ?>
          </div>

        </div>
        <?php endwhile; ?>
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

    <!-- INSURANCE POLICIES -->
    <div class="card">
      <div class="card-header">
        <div class="card-icon"><?= icon('document', 16) ?></div>
        <div>
          <div class="card-title">Insurance Policies</div>
          <div class="card-sub"><?= $pc ?> polic<?= $pc !== 1 ? 'ies' : 'y' ?> on record</div>
        </div>
        <?php if (!$is_mechanic):
            if (count($_client_vehicle_ids) === 1) {
                $_elig_url = '../insurance/eligibility_check.php?vehicle_id=' . $_client_vehicle_ids[0];
            } elseif (count($_client_vehicle_ids) > 1) {
                $_elig_url = '../insurance/eligibility_check.php?search=' . urlencode($client['full_name']);
            } else {
                $_elig_url = '../insurance/eligibility_check.php';
            }
        ?>
        <a href="<?= $_elig_url ?>" class="btn-primary" style="margin-left:auto;padding:0.5rem 1rem;font-size:0.78rem;"><?= icon('shield-check', 14) ?> Check Eligibility</a>
        <?php endif; ?>
      </div>
      <?php if ($policies->num_rows > 0): ?>
      <div class="tg-table-wrap">
        <table class="tg-table mob-card mob-policy-table">
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
            <tr <?= !$is_mechanic ? "style=\"cursor:pointer;\" onclick=\"window.location='" . $view_url . "'\"" : '' ?>>
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
        <a href="../claims/add_claim.php?client_id=<?= $client_id ?>" class="btn-primary" style="margin-left:auto;padding:0.5rem 1rem;font-size:0.78rem;">
          <?= icon('plus', 14) ?> File New Claim
        </a>
        <?php endif; ?>
      </div>
      <?php if ($claims->num_rows > 0): ?>
      <div class="tg-table-wrap">
        <table class="tg-table mob-card mob-client-claims-table">
          <thead>
            <tr>
              <th>Policy / Vehicle</th>
              <th>Incident Date</th>
              <th>Filed</th>
              <th>Type / Status</th>
              <?php if (!$is_mechanic): ?><th>Actions</th><?php endif; ?>
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
                  <?php if ($cl['claim_type'] === 'repair'): ?>
                    <span class="badge badge-danger">Repair</span>
                  <?php else: ?>
                    <span class="badge badge-info">Cash</span>
                  <?php endif; ?>
                  <span class="badge <?= $cs['class'] ?>"><?= $cs['label'] ?></span>
                </div>
              </td>
              <?php if (!$is_mechanic): ?>
              <td>
                <a href="../claims/view_claim.php?id=<?= $cl['claim_id'] ?>" class="btn-sm-gold" title="View Claim" style="padding:0.35rem 0.55rem;">
                  <?= icon('eye', 14) ?>
                </a>
              </td>
              <?php endif; ?>
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
        <?php
          $_repair_prefill = '../repair/add_repair.php?prefill_client=' . $client_id;
          if (count($_client_vehicle_ids) === 1) $_repair_prefill .= '&prefill_vehicle=' . $_client_vehicle_ids[0];
        ?>
        <a href="<?= htmlspecialchars($_repair_prefill) ?>" class="btn-primary" style="margin-left:auto;padding:0.5rem 1rem;font-size:0.78rem;"><?= icon('plus', 14) ?> Add Repair Job</a>
      </div>
      <?php if ($repair_jobs->num_rows > 0): ?>
      <table class="tg-table mob-card mob-client-repairs-table">
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

  </div>
</div>

<script src="../../assets/js/shared/view_client.js?v=<?= filemtime(__DIR__.'/../../assets/js/shared/view_client.js') ?>"></script>
<script src="../../assets/js/shared/vehicle_3d_rotation.js?v=<?= filemtime(__DIR__.'/../../assets/js/shared/vehicle_3d_rotation.js') ?>"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
(function(){
  var wrap = document.getElementById('qr-canvas-wrap');
  if (wrap && typeof QRCode !== 'undefined') {
    new QRCode(wrap, {
      text: <?= json_encode($public_url) ?>,
      width: 90,
      height: 90,
      colorDark: '#1C1A17',
      colorLight: '#ffffff',
      correctLevel: QRCode.CorrectLevel.M
    });
  }

  window.downloadQr = function() {
    var canvas = document.querySelector('#qr-canvas-wrap canvas');
    if (!canvas) return;
    // Scale up to 400px for a crisp download
    var scale  = Math.ceil(400 / canvas.width);
    var out    = document.createElement('canvas');
    out.width  = canvas.width  * scale;
    out.height = canvas.height * scale;
    var ctx = out.getContext('2d');
    ctx.imageSmoothingEnabled = false;  // keep QR pixels sharp
    ctx.drawImage(canvas, 0, 0, out.width, out.height);
    // Draw TG logo in center
    var logo = new Image();
    logo.src = '<?= $base_path ?>assets/img/tg_logo.png';
    logo.onload = function() {
      var size = Math.round(out.width * 0.18);
      var x    = Math.round((out.width  - size) / 2);
      var y    = Math.round((out.height - size) / 2);
      var pad  = Math.round(size * 0.2);
      // White circle background
      ctx.beginPath();
      ctx.arc(x + size/2, y + size/2, size/2 + pad, 0, Math.PI * 2);
      ctx.fillStyle = '#ffffff';
      ctx.fill();
      // Draw logo
      ctx.drawImage(logo, x, y, size, size);
      var link = document.createElement('a');
      link.download = 'QR-<?= addslashes(htmlspecialchars($client['full_name'])) ?>.png';
      link.href = out.toDataURL('image/png');
      link.click();
    };
    logo.onerror = function() {
      var link = document.createElement('a');
      link.download = 'QR-<?= addslashes(htmlspecialchars($client['full_name'])) ?>.png';
      link.href = out.toDataURL('image/png');
      link.click();
    };
  };
})();
</script>

<?php require_once '../../includes/footer.php'; ?>