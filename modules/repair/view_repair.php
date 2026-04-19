<?php
require_once __DIR__ . "/../../config/session.php";
require_once '../../config/db.php';
require_once '../../config/validators.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin', 'mechanic'])) {
    header("Location: ../../auth/login.php");
    exit;
}

$role   = $_SESSION['role'];
$job_id = san_int($_GET['id'] ?? 0, 1);
if (!$job_id) { header("Location: repair_list.php"); exit; }

// ── FETCH JOB ──
$stmt = $conn->prepare("
    SELECT j.*,
           c.client_id, c.full_name, c.contact_number, c.email,
           v.vehicle_id, v.plate_number, v.make, v.model, v.year_model, v.color
    FROM repair_jobs j
    INNER JOIN clients  c ON j.client_id  = c.client_id
    INNER JOIN vehicles v ON j.vehicle_id = v.vehicle_id
    WHERE j.job_id = ?
");
$stmt->bind_param('i', $job_id);
$stmt->execute();
$job = $stmt->get_result()->fetch_assoc();
if (!$job) { header("Location: repair_list.php"); exit; }

// ── FETCH CHECKLIST ──
$cl_stmt = $conn->prepare("SELECT area_key, condition_value, notes FROM repair_checklist WHERE job_id = ?");
$cl_stmt->bind_param('i', $job_id);
$cl_stmt->execute();
$cl_rows = $cl_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$checklist = [];
foreach ($cl_rows as $row) $checklist[$row['area_key']] = $row;

// ── HANDLE STATUS UPDATE ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrf_verify();

    if ($_POST['action'] === 'update_status') {
        $new_status = san_enum($_POST['status'] ?? '', ['pending','in_progress','for_pickup','completed','cancelled']);
        if ($new_status) {
            $upd = $conn->prepare("UPDATE repair_jobs SET status = ? WHERE job_id = ?");
            $upd->bind_param('si', $new_status, $job_id);
            $upd->execute();
            $log  = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'REPAIR_STATUS_UPDATED', ?)");
            $desc = ($_SESSION['full_name'] ?? 'Unknown') . ' updated repair job ' . $job['job_number'] . ' status to ' . $new_status . '.';
            $log->bind_param('is', $_SESSION['user_id'], $desc);
            $log->execute();
            header("Location: view_repair.php?id=$job_id&success=Status updated.");
            exit;
        }
    }

    if ($_POST['action'] === 'update_notes') {
        $notes = san_str($_POST['additional_damages'] ?? '', 1000);
        $upd   = $conn->prepare("UPDATE repair_jobs SET additional_damages = ? WHERE job_id = ?");
        $upd->bind_param('si', $notes, $job_id);
        $upd->execute();
        header("Location: view_repair.php?id=$job_id&success=Notes updated.");
        exit;
    }

    if ($_POST['action'] === 'update_release') {
        $rel = san_str($_POST['release_date'] ?? '', 10);
        if ($rel === '' || validate_date($rel)) {
            $upd = $conn->prepare("UPDATE repair_jobs SET release_date = ? WHERE job_id = ?");
            $rd  = $rel ?: null;
            $upd->bind_param('si', $rd, $job_id);
            $upd->execute();
            header("Location: view_repair.php?id=$job_id&success=Release date updated.");
            exit;
        }
    }
}

// ── LABEL MAPS ──
$service_labels = [
    'repair_panel'   => 'Per Panel Repair',
    'repair_full'    => 'Full Body Repair',
    'paint_panel'    => 'Per Panel Paint',
    'paint_full'     => 'Full Body Paint',
    'washover_basic' => 'Basic Wash Over',
    'washover_full'  => 'Fully Wash Over',
    'custom'         => 'Custom / Mixed',
];

$status_map = [
    'pending'     => ['Pending',     'badge-yellow', '#B8860B'],
    'in_progress' => ['In Progress', 'badge-blue',   '#1A6B9A'],
    'for_pickup'  => ['For Pickup',  'badge-gold',   '#D4A017'],
    'completed'   => ['Completed',   'badge-green',  '#2E7D52'],
    'cancelled'   => ['Cancelled',   'badge-gray',   '#6B7280'],
];

$area_labels = [
    'front_bumper' => 'Front Bumper', 'rear_bumper'  => 'Rear Bumper',
    'hood'         => 'Hood',         'trunk'        => 'Trunk',
    'windshield'   => 'Windshield',   'door_fl'      => 'Front-Left Door',
    'door_rl'      => 'Rear-Left Door','door_fr'     => 'Front-Right Door',
    'door_rr'      => 'Rear-Right Door','mirror_left' => 'Left Mirror',
    'mirror_right' => 'Right Mirror', 'headlights'   => 'Headlights',
    'taillights'   => 'Taillights',   'tires_wheels' => 'Tires & Wheels',
];

$cond_map = [
    'none'  => ['No Damage',    '#6B7280', 'rgba(107,114,128,0.1)'],
    'minor' => ['Minor Damage', '#B8860B', 'rgba(184,134,11,0.12)'],
    'major' => ['Major Damage', '#C0392B', 'rgba(192,57,43,0.12)'],
];

$sb      = $status_map[$job['status']] ?? ['Unknown','badge-gray','#6B7280'];
$svc_lbl = $service_labels[$job['service_type']] ?? $job['service_type'];

$page_title  = 'Repair Job — ' . $job['job_number'];
$active_page = 'repair';
$base_path   = '../../';
$extra_css   = '<link rel="stylesheet" href="../../assets/css/mechanic/repair_list.css?v=' . filemtime(__DIR__ . '/../../assets/css/mechanic/repair_list.css') . '"/>';
require_once '../../includes/header.php';
require_once '../../includes/navbar.php';
?>

<div class="main">
<?php
$topbar_title      = 'Repair Job';
$topbar_breadcrumb = ['Repair Shop', 'Repair Jobs', $job['job_number']];
require_once '../../includes/topbar.php';
?>

<div class="content">

<?php if (!empty($_GET['success'])): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
  Swal.fire({ toast:true, position:'top-end', icon:'success', title:<?= json_encode(san_str($_GET['success'],200)) ?>, showConfirmButton:false, timer:3000, timerProgressBar:true });
});
</script>
<?php endif; ?>

  <!-- BACK + HEADER ROW -->
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;flex-wrap:wrap;gap:0.75rem;">
    <a href="repair_list.php" style="display:inline-flex;align-items:center;gap:0.4rem;font-size:0.82rem;color:var(--text-muted);text-decoration:none;" onmouseover="this.style.color='var(--gold)'" onmouseout="this.style.color='var(--text-muted)'">
      <?= icon('chevron-left', 14) ?> Back to Repair Jobs
    </a>
    <div style="display:flex;align-items:center;gap:0.5rem;">
      <span class="badge <?= $sb[1] ?>"><?= $sb[0] ?></span>
      <?php if (in_array($role, ['admin','super_admin','mechanic'])): ?>
      <button onclick="document.getElementById('status-modal').style.display='flex'" class="btn-primary" style="padding:0.45rem 1rem;font-size:0.8rem;">
        <?= icon('arrow-right', 13) ?> Update Status
      </button>
      <?php endif; ?>
      <?php if (in_array($role, ['admin','super_admin'])): ?>
      <?php
        $qt_chk = $conn->prepare("SELECT quotation_id FROM quotations WHERE job_id = ? LIMIT 1");
        $qt_chk->bind_param('i', $job_id);
        $qt_chk->execute();
        $existing_qt = $qt_chk->get_result()->fetch_assoc();
      ?>
      <?php if ($existing_qt): ?>
        <a href="../quotations/view_quotation.php?id=<?= $existing_qt['quotation_id'] ?>" class="btn-primary" style="padding:0.45rem 1rem;font-size:0.8rem;"><?= icon('receipt', 13) ?> View Quotation</a>
      <?php else: ?>
        <a href="../quotations/add_quotation.php?job_id=<?= $job_id ?>" class="btn-primary" style="padding:0.45rem 1rem;font-size:0.8rem;"><?= icon('receipt', 13) ?> Generate Quotation</a>
      <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- TOP GRID: Job Info + Client/Vehicle -->
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.25rem;">

    <!-- JOB DETAILS -->
    <div class="card" style="margin-bottom:0;">
      <div class="card-header">
        <div class="card-icon"><?= icon('wrench', 16) ?></div>
        <div>
          <div class="card-title">Job Details</div>
          <div class="card-sub"><?= htmlspecialchars($job['job_number']) ?></div>
        </div>
      </div>
      <div style="padding:1rem 1.25rem;display:flex;flex-direction:column;gap:0.75rem;">
        <?php
        $details = [
          ['Service Type',   $svc_lbl],
          ['Repair Date',    date('F d, Y', strtotime($job['repair_date']))],
          ['Est. Release',   $job['release_date'] ? date('F d, Y', strtotime($job['release_date'])) : '—'],
          ['Status',         $sb[0]],
          ['Date Created',   date('F d, Y g:i A', strtotime($job['created_at']))],
        ];
        foreach ($details as [$lbl, $val]): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:0.5rem 0;border-bottom:1px solid var(--border);">
          <span style="font-size:0.78rem;color:var(--text-muted);font-weight:500;"><?= $lbl ?></span>
          <span style="font-size:0.82rem;color:var(--text-primary);font-weight:600;"><?= htmlspecialchars($val) ?></span>
        </div>
        <?php endforeach; ?>

        <!-- Quick release date update -->
        <?php if (in_array($role, ['admin','super_admin','mechanic'])): ?>
        <form method="POST" style="display:flex;gap:0.5rem;align-items:center;margin-top:0.25rem;">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="update_release"/>
          <input type="date" name="release_date" value="<?= htmlspecialchars($job['release_date'] ?? '') ?>"
                 style="flex:1;padding:0.4rem 0.65rem;border:1px solid var(--border);border-radius:8px;background:var(--bg-2);color:var(--text-primary);font-size:0.8rem;"/>
          <button type="submit" class="btn-sm-gold" style="white-space:nowrap;"><?= icon('check', 13) ?> Set Release</button>
        </form>
        <?php endif; ?>
      </div>
    </div>

    <!-- CLIENT & VEHICLE -->
    <div class="card" style="margin-bottom:0;">
      <div class="card-header">
        <div class="card-icon"><?= icon('user', 16) ?></div>
        <div>
          <div class="card-title">Client &amp; Vehicle</div>
          <div class="card-sub"><?= htmlspecialchars($job['full_name']) ?></div>
        </div>
        <a href="../clients/view_client.php?id=<?= $job['client_id'] ?>" class="btn-sm-gold" style="margin-left:auto;"><?= icon('eye', 13) ?> View Client</a>
      </div>
      <div style="padding:1rem 1.25rem;display:flex;flex-direction:column;gap:0.75rem;">
        <?php
        $client_info = [
          ['Client',       $job['full_name']],
          ['Contact',      $job['contact_number'] ?: '—'],
          ['Email',        $job['email'] ?: '—'],
        ];
        $vehicle_info = [
          ['Plate Number', $job['plate_number']],
          ['Make & Model', trim($job['year_model'] . ' ' . $job['make'] . ' ' . $job['model'])],
          ['Color',        $job['color'] ?: '—'],
        ];
        foreach (array_merge($client_info, $vehicle_info) as [$lbl, $val]): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:0.5rem 0;border-bottom:1px solid var(--border);">
          <span style="font-size:0.78rem;color:var(--text-muted);font-weight:500;"><?= $lbl ?></span>
          <span style="font-size:0.82rem;color:var(--text-primary);font-weight:600;"><?= htmlspecialchars($val) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

  </div>

  <!-- INSPECTION CHECKLIST -->
  <div class="card" style="margin-bottom:1.25rem;">
    <div class="card-header">
      <div class="card-icon"><?= icon('clipboard-list', 16) ?></div>
      <div>
        <div class="card-title">Unit Inspection Checklist</div>
        <div class="card-sub">Damage assessment at intake</div>
      </div>
    </div>
    <div style="padding:1rem 1.25rem;">
      <?php
      $has_damage = false;
      foreach ($checklist as $row) {
          if ($row['condition_value'] !== 'none') { $has_damage = true; break; }
      }
      if (empty($checklist) || !$has_damage): ?>
      <div style="text-align:center;padding:1.5rem;color:var(--text-muted);font-size:0.82rem;">
        <?= icon('check-circle', 24) ?><br/>No damage recorded on intake.
      </div>
      <?php else: ?>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:0.65rem;">
        <?php foreach ($area_labels as $key => $label):
          $entry = $checklist[$key] ?? ['condition_value'=>'none','notes'=>''];
          $cond  = $entry['condition_value'];
          if ($cond === 'none') continue;
          [$cond_lbl, $cond_color, $cond_bg] = $cond_map[$cond];
        ?>
        <div style="background:<?= $cond_bg ?>;border:1px solid <?= $cond_color ?>33;border-radius:10px;padding:0.75rem 1rem;">
          <div style="font-size:0.75rem;font-weight:700;color:var(--text-primary);margin-bottom:0.2rem;"><?= $label ?></div>
          <div style="font-size:0.7rem;font-weight:600;color:<?= $cond_color ?>;"><?= $cond_lbl ?></div>
          <?php if ($entry['notes']): ?>
          <div style="font-size:0.68rem;color:var(--text-muted);margin-top:0.3rem;"><?= htmlspecialchars($entry['notes']) ?></div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <!-- Also show no-damage areas in a compact list -->
      <div style="margin-top:0.75rem;display:flex;flex-wrap:wrap;gap:0.4rem;">
        <?php foreach ($area_labels as $key => $label):
          $entry = $checklist[$key] ?? ['condition_value'=>'none'];
          if ($entry['condition_value'] !== 'none') continue;
        ?>
        <span style="font-size:0.68rem;background:var(--bg-2);border:1px solid var(--border);border-radius:6px;padding:0.2rem 0.55rem;color:var(--text-muted);"><?= $label ?></span>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ADDITIONAL REMARKS -->
  <div class="card" style="margin-bottom:0;">
    <div class="card-header">
      <div class="card-icon"><?= icon('document', 16) ?></div>
      <div>
        <div class="card-title">Additional Damages / Remarks</div>
        <div class="card-sub">Notes added upon inspection</div>
      </div>
    </div>
    <div style="padding:1rem 1.25rem;">
      <?php if (in_array($role, ['admin','super_admin','mechanic'])): ?>
      <form method="POST" style="display:flex;flex-direction:column;gap:0.75rem;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_notes"/>
        <textarea name="additional_damages" rows="4"
          style="width:100%;padding:0.65rem 0.85rem;border:1px solid var(--border);border-radius:10px;background:var(--bg-2);color:var(--text-primary);font-size:0.82rem;resize:vertical;font-family:inherit;"
          placeholder="Additional damages upon inspection..."><?= htmlspecialchars($job['additional_damages'] ?? '') ?></textarea>
        <div style="display:flex;justify-content:flex-end;">
          <button type="submit" class="btn-sm-gold"><?= icon('check', 13) ?> Save Notes</button>
        </div>
      </form>
      <?php else: ?>
      <p style="font-size:0.82rem;color:var(--text-secondary);white-space:pre-wrap;"><?= $job['additional_damages'] ? htmlspecialchars($job['additional_damages']) : '<span style="color:var(--text-muted);">No remarks.</span>' ?></p>
      <?php endif; ?>
    </div>
  </div>

</div>
</div>

<!-- STATUS UPDATE MODAL -->
<div id="status-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
  <div style="background:var(--bg-2);border:1px solid var(--border);border-radius:16px;padding:1.75rem;width:100%;max-width:360px;box-shadow:var(--shadow-lg);">
    <div style="font-size:1rem;font-weight:700;color:var(--text-primary);margin-bottom:0.25rem;">Update Job Status</div>
    <div style="font-size:0.78rem;color:var(--text-muted);margin-bottom:1.25rem;"><?= htmlspecialchars($job['job_number']) ?></div>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update_status"/>
      <div style="display:flex;flex-direction:column;gap:0.5rem;margin-bottom:1.25rem;">
        <?php foreach ($status_map as $val => [$lbl, $badge, $clr]): ?>
        <label style="display:flex;align-items:center;gap:0.65rem;padding:0.65rem 0.85rem;border:1px solid var(--border);border-radius:10px;cursor:pointer;transition:background 0.1s;"
               onmouseover="this.style.background='var(--bg-3)'" onmouseout="this.style.background=''">
          <input type="radio" name="status" value="<?= $val ?>" <?= $job['status'] === $val ? 'checked' : '' ?> style="accent-color:<?= $clr ?>;width:15px;height:15px;"/>
          <span class="badge <?= $badge ?>"><?= $lbl ?></span>
        </label>
        <?php endforeach; ?>
      </div>
      <div style="display:flex;gap:0.5rem;justify-content:flex-end;">
        <button type="button" onclick="document.getElementById('status-modal').style.display='none'" class="btn-ghost">Cancel</button>
        <button type="submit" class="btn-primary"><?= icon('check', 13) ?> Save</button>
      </div>
    </form>
  </div>
</div>
<script>
document.getElementById('status-modal').addEventListener('click', function(e) {
  if (e.target === this) this.style.display = 'none';
});
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') document.getElementById('status-modal').style.display = 'none';
});
</script>

<?php require_once '../../includes/footer.php'; ?>
