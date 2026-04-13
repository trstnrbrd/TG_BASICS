<?php
require_once __DIR__ . "/../../config/session.php";
require_once '../../config/db.php';
require_once '../../config/validators.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header("Location: ../../auth/login.php");
    exit;
}

$checklist_area_keys = [
    'front_bumper','rear_bumper','hood','trunk','windshield',
    'door_fl','door_rl','door_fr','door_rr',
    'mirror_left','mirror_right','headlights','taillights','tires_wheels',
];

$errors  = [];
$success = '';

// ── HANDLE POST ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $client_id   = (int)($_POST['client_id']   ?? 0);
    $vehicle_id  = (int)($_POST['vehicle_id']  ?? 0);
    $repair_date = san_str($_POST['repair_date']  ?? '', 10);
    $release_date= san_str($_POST['release_date'] ?? '', 10);
    $service_type= san_enum($_POST['service_type'] ?? '', [
        'repair_panel','repair_full','paint_panel','paint_full',
        'washover_basic','washover_full','custom'
    ]);
    $additional  = san_str($_POST['additional_damages'] ?? '', 1000);

    if ($client_id  === 0) $errors[] = 'Client is required.';
    if ($vehicle_id === 0) $errors[] = 'Vehicle is required.';
    if ($repair_date === '') $errors[] = 'Repair date is required.';
    if ($service_type === '') $errors[] = 'Service type is required.';

    if (empty($errors)) {
        // Generate job number: RJ-YYYYMMDD-XXXX
        $seq_stmt = $conn->prepare("SELECT COUNT(*) FROM repair_jobs WHERE DATE(created_at) = CURDATE()");
        $seq_stmt->execute();
        $seq_row = $seq_stmt->get_result()->fetch_row();
        $seq     = str_pad(($seq_row[0] + 1), 4, '0', STR_PAD_LEFT);
        $job_num = 'RJ-' . date('Ymd') . '-' . $seq;

        $ins = $conn->prepare("
            INSERT INTO repair_jobs (client_id, vehicle_id, job_number, repair_date, release_date, service_type, additional_damages, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $rel = $release_date ?: null;
        $ins->bind_param('iisssssi', $client_id, $vehicle_id, $job_num, $repair_date, $rel, $service_type, $additional, $_SESSION['user_id']);

        if ($ins->execute()) {
            $job_id = $conn->insert_id;

            // Save checklist
            $cins = $conn->prepare("INSERT INTO repair_checklist (job_id, area_key, condition_value, notes) VALUES (?, ?, ?, ?)");
            foreach ($checklist_area_keys as $key) {
                $cond  = san_enum($_POST['area_' . $key] ?? 'none', ['none', 'minor', 'major']);
                $note  = san_str($_POST['note_' . $key] ?? '', 255);
                $cins->bind_param('isss', $job_id, $key, $cond, $note);
                $cins->execute();
            }

            // Audit log
            $log  = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'REPAIR_JOB_CREATED', ?)");
            $desc = ($_SESSION['full_name'] ?? 'Unknown') . ' created repair job ' . $job_num . '.';
            $log->bind_param('is', $_SESSION['user_id'], $desc);
            $log->execute();

            header("Location: repair_list.php?success=" . urlencode('Repair job ' . $job_num . ' created successfully.'));
            exit;
        } else {
            $errors[] = 'Database error. Please try again.';
        }
    }
}

$page_title  = 'New Repair Job';
$active_page = 'repair';
$base_path   = '../../';
$extra_css   = '<link rel="stylesheet" href="../../assets/css/mechanic/add_repair.css?v=' . filemtime(__DIR__ . '/../../assets/css/mechanic/add_repair.css') . '"/>';
require_once '../../includes/header.php';
require_once '../../includes/navbar.php';

// Exact areas from the physical Unit Inspection form
$checklist_areas = [
    'front_bumper'   => 'Front Bumper',
    'rear_bumper'    => 'Rear Bumper',
    'hood'           => 'Hood',
    'trunk'          => 'Trunk',
    'windshield'     => 'Windshield',
    'door_fl'        => 'Left Front Door',
    'door_rl'        => 'Left Rear Door',
    'door_fr'        => 'Right Front Door',
    'door_rr'        => 'Right Rear Door',
    'mirror_left'    => 'Left Mirror',
    'mirror_right'   => 'Right Mirror',
    'headlights'     => 'Headlights',
    'taillights'     => 'Taillights',
    'tires_wheels'   => 'Tires / Wheels',
];
?>

<div class="main">

<?php
$topbar_title      = 'New Repair Job';
$topbar_breadcrumb = ['Repair Shop', 'Repair Jobs', 'New Job'];
$topbar_show_clock = true;
require_once '../../includes/topbar.php';
?>

  <div class="content">

    <?php if (!empty($errors)): ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
      Swal.fire({
        icon: 'error', title: 'Please fix the following',
        html: <?= json_encode('<ul style="text-align:left;margin:0;padding-left:1.2rem;">' . implode('', array_map(fn($e) => '<li>' . htmlspecialchars($e) . '</li>', $errors)) . '</ul>') ?>,
        confirmButtonColor: '#B8860B'
      });
    });
    </script>
    <?php endif; ?>

    <a href="repair_list.php" class="back-link"><?= icon('arrow-left', 14) ?> Back to Repair Jobs</a>

    <form method="POST" action="" id="repair-form">
      <?= csrf_field() ?>

      <!-- ROW 1: Client/Vehicle + Job Details -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.25rem;">

        <!-- CLIENT & VEHICLE -->
        <div class="card" style="margin-bottom:0;">
          <div class="card-header">
            <div class="card-icon"><?= icon('user', 16) ?></div>
            <div>
              <div class="card-title">Client &amp; Vehicle</div>
              <div class="card-sub">Search for an existing client</div>
            </div>
          </div>
          <div style="padding:1.25rem;display:flex;flex-direction:column;gap:0.9rem;">

            <div class="field">
              <label class="field-label">Client <span class="req">*</span></label>
              <div style="position:relative;">
                <input type="text" id="client-search" class="field-input"
                  placeholder="Search by name or plate number..." autocomplete="off"/>
                <input type="hidden" name="client_id" id="client_id_input"/>
                <div id="client-dropdown"
                     style="display:none;position:absolute;top:100%;left:0;right:0;z-index:100;
                            background:var(--bg-2);border:1px solid var(--border);border-radius:8px;
                            box-shadow:var(--shadow-md);max-height:220px;overflow-y:auto;margin-top:2px;"></div>
              </div>
            </div>

            <div class="field">
              <label class="field-label">Vehicle <span class="req">*</span></label>
              <select name="vehicle_id" id="vehicle_id_select" class="field-select" disabled>
                <option value="">— Select client first —</option>
              </select>
            </div>

            <div id="vehicle-details"
                 style="display:none;background:var(--bg-3);border:1px solid var(--border);border-radius:8px;padding:0.9rem 1rem;">
              <div style="font-size:0.7rem;color:var(--text-muted);font-weight:700;text-transform:uppercase;letter-spacing:0.6px;margin-bottom:0.5rem;">Vehicle Info</div>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.3rem 1rem;font-size:0.8rem;">
                <div><span style="color:var(--text-muted);">Make / Model:</span> <span id="vd-make-model" style="color:var(--text-primary);font-weight:600;"></span></div>
                <div><span style="color:var(--text-muted);">Year:</span> <span id="vd-year" style="color:var(--text-primary);font-weight:600;"></span></div>
                <div><span style="color:var(--text-muted);">Color:</span> <span id="vd-color" style="color:var(--text-primary);font-weight:600;"></span></div>
                <div><span style="color:var(--text-muted);">Plate:</span> <span id="vd-plate" style="color:var(--text-primary);font-weight:600;"></span></div>
              </div>
            </div>

          </div>
        </div>

        <!-- JOB DETAILS -->
        <div class="card" style="margin-bottom:0;">
          <div class="card-header">
            <div class="card-icon"><?= icon('clipboard-list', 16) ?></div>
            <div>
              <div class="card-title">Job Details</div>
              <div class="card-sub">Repair date and service information</div>
            </div>
          </div>
          <div style="padding:1.25rem;display:flex;flex-direction:column;gap:0.9rem;">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.9rem;">
              <div class="field">
                <label class="field-label">Repair Date <span class="req">*</span></label>
                <input type="date" name="repair_date" class="field-input"
                  value="<?= date('Y-m-d') ?>" required/>
              </div>
              <div class="field">
                <label class="field-label">Est. Release Date</label>
                <input type="date" name="release_date" class="field-input"
                  value="<?= date('Y-m-d', strtotime('+7 days')) ?>"/>
              </div>
            </div>

            <div class="field">
              <label class="field-label">Service Type <span class="req">*</span></label>
              <select name="service_type" class="field-select" required>
                <option value="" disabled selected>Select service type</option>
                <optgroup label="Repair &amp; Paint">
                  <option value="repair_panel">Per Panel Repair</option>
                  <option value="repair_full">Full Body Repair</option>
                  <option value="paint_panel">Per Panel Paint</option>
                  <option value="paint_full">Full Body Paint</option>
                </optgroup>
                <optgroup label="Wash Over">
                  <option value="washover_basic">Basic Wash Over</option>
                  <option value="washover_full">Fully Wash Over</option>
                </optgroup>
                <optgroup label="Other">
                  <option value="custom">Custom / Mixed Services</option>
                </optgroup>
              </select>
            </div>

            <div class="field">
              <label class="field-label">Contact Number</label>
              <input type="text" name="contact_number" id="contact_number" class="field-input"
                placeholder="Auto-filled from client record" readonly
                style="background:var(--bg-3);color:var(--text-muted);cursor:default;"/>
            </div>

            <div class="field">
              <label class="field-label">Additional Damages / Remarks</label>
              <textarea name="additional_damages" class="field-input" rows="3"
                placeholder="Additional damages upon inspection..."
                style="resize:vertical;"></textarea>
            </div>

          </div>
        </div>

      </div>

      <!-- EXTERNAL CAR CONDITION CHECKLIST -->
      <div class="card" style="margin-bottom:1.25rem;">
        <div class="card-header">
          <div class="card-icon"><?= icon('clipboard-list', 16) ?></div>
          <div>
            <div class="card-title">Unit Inspection</div>
            <div class="card-sub">Unit Inspection — click a panel on the diagram to mark damage</div>
          </div>
        </div>

        <div style="padding:0 1.25rem 1.25rem;">

          <!-- CAR DIAGRAM -->
          <div class="car-diagram-wrap">

            <!-- LEFT COLUMN: Right Side + Left Side stacked -->
            <div class="car-sides-col">

              <!-- RIGHT SIDE -->
              <div class="car-view-block">
                <div class="car-view-label">Right Side</div>
                <svg viewBox="0 0 420 160" class="car-svg" xmlns="http://www.w3.org/2000/svg">
                  <!-- Body -->
                  <rect x="40" y="80" width="340" height="52" rx="10" fill="var(--bg-3)" stroke="var(--border)" stroke-width="1.5"/>
                  <!-- Cabin/roof shape -->
                  <path d="M110 80 Q130 35 175 28 L270 28 Q315 32 330 80 Z" fill="var(--bg-3)" stroke="var(--border)" stroke-width="1.5"/>
                  <!-- Rear bumper (left) -->
                  <rect x="28" y="82" width="28" height="44" rx="6" class="panel" data-panel="rear_bumper" fill="var(--bg-2)" stroke="var(--border)" stroke-width="1.5"/>
                  <!-- Front bumper (right) -->
                  <rect x="364" y="82" width="28" height="44" rx="6" class="panel" data-panel="front_bumper" fill="var(--bg-2)" stroke="var(--border)" stroke-width="1.5"/>
                  <!-- Trunk (rear quarter) -->
                  <path d="M56 80 L88 78 L88 110 L56 106 Z" class="panel" data-panel="trunk" fill="var(--bg-2)" stroke="var(--border)" stroke-width="1.5"/>
                  <!-- Hood (front quarter) -->
                  <path d="M332 80 L364 82 L364 106 L332 110 Z" class="panel" data-panel="hood" fill="var(--bg-2)" stroke="var(--border)" stroke-width="1.5"/>
                  <!-- Right rear door -->
                  <rect x="96"  y="78" width="105" height="52" rx="3" class="panel" data-panel="door_rr" fill="var(--bg-2)" stroke="var(--border)" stroke-width="1.5"/>
                  <!-- Right front door -->
                  <rect x="210" y="78" width="105" height="52" rx="3" class="panel" data-panel="door_fr" fill="var(--bg-2)" stroke="var(--border)" stroke-width="1.5"/>
                  <!-- Right rear fender -->
                  <path d="M88 78 L96 78 L96 130 L88 130 Z" class="panel" data-panel="rear_right" fill="var(--bg-2)" stroke="var(--border)" stroke-width="1.5"/>
                  <!-- Right front fender -->
                  <path d="M315 78 L323 78 L323 130 L315 130 Z" class="panel" data-panel="front_right" fill="var(--bg-2)" stroke="var(--border)" stroke-width="1.5"/>
                  <!-- Right mirror -->
                  <rect x="300" y="65" width="26" height="12" rx="4" class="panel" data-panel="mirror_right" fill="var(--bg-2)" stroke="var(--border)" stroke-width="1.5"/>
                  <!-- Windshield -->
                  <path d="M195 30 L265 30 Q308 34 320 78 L175 78 Q168 46 195 30Z" class="panel" data-panel="windshield" fill="rgba(100,180,255,0.1)" stroke="var(--border)" stroke-width="1.5"/>
                  <!-- Headlight -->
                  <ellipse cx="374" cy="93" rx="8" ry="6" class="panel" data-panel="headlights" fill="rgba(255,220,100,0.18)" stroke="var(--border)" stroke-width="1.5"/>
                  <!-- Taillight -->
                  <ellipse cx="44" cy="93" rx="8" ry="6" class="panel" data-panel="taillights" fill="rgba(255,80,80,0.18)" stroke="var(--border)" stroke-width="1.5"/>
                  <!-- Wheels (clickable) -->
                  <circle cx="100" cy="136" r="18" class="panel" data-panel="tires_wheels" fill="var(--bg)" stroke="var(--border)" stroke-width="2"/>
                  <circle cx="100" cy="136" r="9"  fill="var(--border)" style="pointer-events:none;"/>
                  <circle cx="316" cy="136" r="18" class="panel" data-panel="tires_wheels" fill="var(--bg)" stroke="var(--border)" stroke-width="2"/>
                  <circle cx="316" cy="136" r="9"  fill="var(--border)" style="pointer-events:none;"/>
                  <!-- Labels -->
                  <text x="143" y="108" class="panel-lbl">RRD</text>
                  <text x="257" y="108" class="panel-lbl">RFD</text>
                </svg>
              </div>

              <!-- LEFT SIDE (mirrored: front on left, rear on right) -->
              <div class="car-view-block">
                <div class="car-view-label">Left Side</div>
                <svg viewBox="0 0 420 160" class="car-svg" xmlns="http://www.w3.org/2000/svg">
                  <!-- Body (same as right side) -->
                  <rect x="40" y="80" width="340" height="52" rx="10" fill="var(--bg-3)" stroke="var(--border)" stroke-width="1.5"/>
                  <!-- Cabin/roof shape — exact mirror of Right Side -->
                  <path d="M310 80 Q290 35 245 28 L150 28 Q105 32 90 80 Z" fill="var(--bg-3)" stroke="var(--border)" stroke-width="1.5"/>
                  <!-- Front bumper (LEFT — front is on the left) -->
                  <rect x="28" y="82" width="28" height="44" rx="6" class="panel" data-panel="front_bumper" fill="var(--bg-2)" stroke="var(--border)" stroke-width="1.5"/>
                  <!-- Rear bumper (RIGHT) -->
                  <rect x="364" y="82" width="28" height="44" rx="6" class="panel" data-panel="rear_bumper" fill="var(--bg-2)" stroke="var(--border)" stroke-width="1.5"/>
                  <!-- Hood (front quarter — left side) -->
                  <path d="M56 80 L88 78 L88 110 L56 106 Z" class="panel" data-panel="hood" fill="var(--bg-2)" stroke="var(--border)" stroke-width="1.5"/>
                  <!-- Trunk (rear quarter — right side) -->
                  <path d="M332 80 L364 82 L364 106 L332 110 Z" class="panel" data-panel="trunk" fill="var(--bg-2)" stroke="var(--border)" stroke-width="1.5"/>
                  <!-- Left front door (front half of body) -->
                  <rect x="96"  y="78" width="105" height="52" rx="3" class="panel" data-panel="door_fl" fill="var(--bg-2)" stroke="var(--border)" stroke-width="1.5"/>
                  <!-- Left rear door (rear half of body) -->
                  <rect x="210" y="78" width="105" height="52" rx="3" class="panel" data-panel="door_rl" fill="var(--bg-2)" stroke="var(--border)" stroke-width="1.5"/>
                  <!-- Front-left fender strip -->
                  <path d="M88 78 L96 78 L96 130 L88 130 Z" class="panel" data-panel="front_left" fill="var(--bg-2)" stroke="var(--border)" stroke-width="1.5"/>
                  <!-- Rear-left fender strip -->
                  <path d="M315 78 L323 78 L323 130 L315 130 Z" class="panel" data-panel="rear_left" fill="var(--bg-2)" stroke="var(--border)" stroke-width="1.5"/>
                  <!-- Left mirror (front side, above front door) -->
                  <rect x="94" y="65" width="26" height="12" rx="4" class="panel" data-panel="mirror_left" fill="var(--bg-2)" stroke="var(--border)" stroke-width="1.5"/>
                  <!-- Windshield — exact mirror of Right Side windshield -->
                  <path d="M225 30 L155 30 Q112 34 100 78 L245 78 Q252 46 225 30 Z" class="panel" data-panel="windshield" fill="rgba(100,180,255,0.1)" stroke="var(--border)" stroke-width="1.5"/>
                  <!-- Headlight (front/left) -->
                  <ellipse cx="46" cy="93" rx="8" ry="6" class="panel" data-panel="headlights" fill="rgba(255,220,100,0.18)" stroke="var(--border)" stroke-width="1.5"/>
                  <!-- Taillight (rear/right) -->
                  <ellipse cx="376" cy="93" rx="8" ry="6" class="panel" data-panel="taillights" fill="rgba(255,80,80,0.18)" stroke="var(--border)" stroke-width="1.5"/>
                  <!-- Wheels (clickable) -->
                  <circle cx="100" cy="136" r="18" class="panel" data-panel="tires_wheels" fill="var(--bg)" stroke="var(--border)" stroke-width="2"/>
                  <circle cx="100" cy="136" r="9"  fill="var(--border)" style="pointer-events:none;"/>
                  <circle cx="316" cy="136" r="18" class="panel" data-panel="tires_wheels" fill="var(--bg)" stroke="var(--border)" stroke-width="2"/>
                  <circle cx="316" cy="136" r="9"  fill="var(--border)" style="pointer-events:none;"/>
                  <!-- Labels -->
                  <text x="143" y="108" class="panel-lbl">LFD</text>
                  <text x="257" y="108" class="panel-lbl">LRD</text>
                </svg>
              </div>

            </div>

            <!-- RIGHT COLUMN: Front + Rear side by side, Top view below -->
            <div class="car-end-col">

              <!-- FRONT + REAR row -->
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">

                <!-- FRONT -->
                <div class="car-view-block">
                  <div class="car-view-label">Front</div>
                  <svg viewBox="0 0 180 150" class="car-svg" xmlns="http://www.w3.org/2000/svg">
                    <!-- Body -->
                    <rect x="22" y="40" width="136" height="72" rx="10" fill="var(--bg-3)" stroke="var(--border)" stroke-width="1.5"/>
                    <!-- Roofline -->
                    <path d="M38 40 Q44 14 90 10 Q136 14 142 40 Z" fill="var(--bg-3)" stroke="var(--border)" stroke-width="1.5"/>
                    <!-- Front bumper -->
                    <rect x="22" y="106" width="136" height="18" rx="6" class="panel" data-panel="front_bumper" fill="var(--bg-2)" stroke="var(--border)" stroke-width="1.5"/>
                    <!-- Hood -->
                    <rect x="40" y="37" width="100" height="24" rx="4" class="panel" data-panel="hood" fill="var(--bg-2)" stroke="var(--border)" stroke-width="1.5"/>
                    <!-- Windshield -->
                    <rect x="44" y="13" width="92" height="26" rx="4" class="panel" data-panel="windshield" fill="rgba(100,180,255,0.12)" stroke="var(--border)" stroke-width="1.5"/>
                    <!-- Left fender -->
                    <rect x="22" y="40" width="20" height="66" rx="4" class="panel" data-panel="front_left" fill="var(--bg-2)" stroke="var(--border)" stroke-width="1.5"/>
                    <!-- Right fender -->
                    <rect x="138" y="40" width="20" height="66" rx="4" class="panel" data-panel="front_right" fill="var(--bg-2)" stroke="var(--border)" stroke-width="1.5"/>
                    <!-- Headlights -->
                    <rect x="25" y="60" width="30" height="18" rx="4" class="panel" data-panel="headlights" fill="rgba(255,220,100,0.18)" stroke="var(--border)" stroke-width="1.5"/>
                    <rect x="125" y="60" width="30" height="18" rx="4" class="panel" data-panel="headlights" fill="rgba(255,220,100,0.18)" stroke="var(--border)" stroke-width="1.5"/>
                    <!-- Mirrors -->
                    <rect x="4"   y="50" width="18" height="11" rx="3" class="panel" data-panel="mirror_left"  fill="var(--bg-2)" stroke="var(--border)" stroke-width="1.5"/>
                    <rect x="158" y="50" width="18" height="11" rx="3" class="panel" data-panel="mirror_right" fill="var(--bg-2)" stroke="var(--border)" stroke-width="1.5"/>
                    <!-- Wheels (clickable) -->
                    <circle cx="32"  cy="132" r="12" class="panel" data-panel="tires_wheels" fill="var(--bg)" stroke="var(--border)" stroke-width="2"/>
                    <circle cx="32"  cy="132" r="6"  fill="var(--border)" style="pointer-events:none;"/>
                    <circle cx="148" cy="132" r="12" class="panel" data-panel="tires_wheels" fill="var(--bg)" stroke="var(--border)" stroke-width="2"/>
                    <circle cx="148" cy="132" r="6"  fill="var(--border)" style="pointer-events:none;"/>
                  </svg>
                </div>

                <!-- REAR -->
                <div class="car-view-block">
                  <div class="car-view-label">Rear</div>
                  <svg viewBox="0 0 180 150" class="car-svg" xmlns="http://www.w3.org/2000/svg">
                    <rect x="22" y="40" width="136" height="72" rx="10" fill="var(--bg-3)" stroke="var(--border)" stroke-width="1.5"/>
                    <path d="M38 40 Q44 14 90 10 Q136 14 142 40 Z" fill="var(--bg-3)" stroke="var(--border)" stroke-width="1.5"/>
                    <!-- Rear bumper -->
                    <rect x="22" y="106" width="136" height="18" rx="6" class="panel" data-panel="rear_bumper" fill="var(--bg-2)" stroke="var(--border)" stroke-width="1.5"/>
                    <!-- Trunk -->
                    <rect x="40" y="37" width="100" height="24" rx="4" class="panel" data-panel="trunk" fill="var(--bg-2)" stroke="var(--border)" stroke-width="1.5"/>
                    <!-- Left rear fender -->
                    <rect x="22" y="40" width="20" height="66" rx="4" class="panel" data-panel="rear_left" fill="var(--bg-2)" stroke="var(--border)" stroke-width="1.5"/>
                    <!-- Right rear fender -->
                    <rect x="138" y="40" width="20" height="66" rx="4" class="panel" data-panel="rear_right" fill="var(--bg-2)" stroke="var(--border)" stroke-width="1.5"/>
                    <!-- Taillights -->
                    <rect x="25"  y="60" width="30" height="18" rx="4" class="panel" data-panel="taillights" fill="rgba(255,80,80,0.18)" stroke="var(--border)" stroke-width="1.5"/>
                    <rect x="125" y="60" width="30" height="18" rx="4" class="panel" data-panel="taillights" fill="rgba(255,80,80,0.18)" stroke="var(--border)" stroke-width="1.5"/>
                    <!-- Wheels (clickable) -->
                    <circle cx="32"  cy="132" r="12" class="panel" data-panel="tires_wheels" fill="var(--bg)" stroke="var(--border)" stroke-width="2"/>
                    <circle cx="32"  cy="132" r="6"  fill="var(--border)" style="pointer-events:none;"/>
                    <circle cx="148" cy="132" r="12" class="panel" data-panel="tires_wheels" fill="var(--bg)" stroke="var(--border)" stroke-width="2"/>
                    <circle cx="148" cy="132" r="6"  fill="var(--border)" style="pointer-events:none;"/>
                  </svg>
                </div>

              </div>

              <!-- TOP VIEW -->
              <div class="car-view-block" style="align-items:center;">
                <div class="car-view-label">Top</div>
                <svg viewBox="0 0 160 310" class="car-svg" style="max-width:130px;margin:0 auto;" xmlns="http://www.w3.org/2000/svg">
                  <!-- Body outline -->
                  <rect x="18" y="35" width="124" height="240" rx="24" fill="var(--bg-3)" stroke="var(--border)" stroke-width="1.5"/>
                  <!-- Front bumper (top) -->
                  <rect x="30" y="16" width="100" height="20" rx="6" class="panel" data-panel="front_bumper" fill="var(--bg-2)" stroke="var(--border)" stroke-width="1.5"/>
                  <!-- Hood -->
                  <rect x="24" y="35" width="112" height="60" rx="12" class="panel" data-panel="hood" fill="var(--bg-2)" stroke="var(--border)" stroke-width="1.5"/>
                  <!-- Windshield -->
                  <rect x="28" y="96" width="104" height="20" rx="4" class="panel" data-panel="windshield" fill="rgba(100,180,255,0.12)" stroke="var(--border)" stroke-width="1.5"/>
                  <!-- Roof -->
                  <rect x="28" y="118" width="104" height="90" rx="6" class="panel" data-panel="roof" fill="var(--bg-2)" stroke="var(--border)" stroke-width="1.5"/>
                  <!-- Trunk -->
                  <rect x="24" y="215" width="112" height="60" rx="12" class="panel" data-panel="trunk" fill="var(--bg-2)" stroke="var(--border)" stroke-width="1.5"/>
                  <!-- Rear bumper (bottom) -->
                  <rect x="30" y="274" width="100" height="20" rx="6" class="panel" data-panel="rear_bumper" fill="var(--bg-2)" stroke="var(--border)" stroke-width="1.5"/>
                  <!-- Left front door -->
                  <rect x="4"  y="118" width="16" height="42" rx="3" class="panel" data-panel="door_fl" fill="var(--bg-2)" stroke="var(--border)" stroke-width="1.5"/>
                  <!-- Left rear door -->
                  <rect x="4"  y="166" width="16" height="42" rx="3" class="panel" data-panel="door_rl" fill="var(--bg-2)" stroke="var(--border)" stroke-width="1.5"/>
                  <!-- Right front door -->
                  <rect x="140" y="118" width="16" height="42" rx="3" class="panel" data-panel="door_fr" fill="var(--bg-2)" stroke="var(--border)" stroke-width="1.5"/>
                  <!-- Right rear door -->
                  <rect x="140" y="166" width="16" height="42" rx="3" class="panel" data-panel="door_rr" fill="var(--bg-2)" stroke="var(--border)" stroke-width="1.5"/>
                  <!-- Left fenders -->
                  <path d="M18 35 Q4 42 4 65 L4 118 L18 118 Z"    class="panel" data-panel="front_left" fill="var(--bg-2)" stroke="var(--border)" stroke-width="1.5"/>
                  <path d="M18 210 L4 210 L4 248 Q4 268 18 275 Z" class="panel" data-panel="rear_left"  fill="var(--bg-2)" stroke="var(--border)" stroke-width="1.5"/>
                  <!-- Right fenders -->
                  <path d="M142 35 Q156 42 156 65 L156 118 L142 118 Z"    class="panel" data-panel="front_right" fill="var(--bg-2)" stroke="var(--border)" stroke-width="1.5"/>
                  <path d="M142 210 L156 210 L156 248 Q156 268 142 275 Z" class="panel" data-panel="rear_right"  fill="var(--bg-2)" stroke="var(--border)" stroke-width="1.5"/>
                  <!-- Mirrors -->
                  <rect x="-4"  y="90" width="16" height="10" rx="3" class="panel" data-panel="mirror_left"  fill="var(--bg-2)" stroke="var(--border)" stroke-width="1.5"/>
                  <rect x="148" y="90" width="16" height="10" rx="3" class="panel" data-panel="mirror_right" fill="var(--bg-2)" stroke="var(--border)" stroke-width="1.5"/>
                  <!-- Tires (clickable) -->
                  <rect x="2"   y="58"  width="16" height="34" rx="5" class="panel" data-panel="tires_wheels" fill="var(--bg)" stroke="var(--border)" stroke-width="1.5"/>
                  <rect x="142" y="58"  width="16" height="34" rx="5" class="panel" data-panel="tires_wheels" fill="var(--bg)" stroke="var(--border)" stroke-width="1.5"/>
                  <rect x="2"   y="218" width="16" height="34" rx="5" class="panel" data-panel="tires_wheels" fill="var(--bg)" stroke="var(--border)" stroke-width="1.5"/>
                  <rect x="142" y="218" width="16" height="34" rx="5" class="panel" data-panel="tires_wheels" fill="var(--bg)" stroke="var(--border)" stroke-width="1.5"/>
                  <text x="80" y="168" text-anchor="middle" font-size="9" fill="var(--text-muted)" font-weight="600">ROOF</text>
                </svg>
              </div>

            </div>

          </div>

          <!-- LEGEND -->
          <div style="display:flex;gap:1.25rem;align-items:center;margin-bottom:1.25rem;flex-wrap:wrap;">
            <span style="font-size:0.72rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">Legend:</span>
            <span class="legend-dot" style="background:var(--bg-2);border:1.5px solid var(--border);">No Damage</span>
            <span class="legend-dot" style="background:rgba(217,119,6,0.18);border:1.5px solid #D97706;">Minor Scratch</span>
            <span class="legend-dot" style="background:rgba(220,38,38,0.18);border:1.5px solid #DC2626;">Major Damage</span>
          </div>

          <!-- HIDDEN INPUTS (submitted with form) -->
          <?php foreach ($checklist_area_keys as $key): ?>
          <input type="hidden" name="area_<?= $key ?>" id="inp_area_<?= $key ?>" value="none"/>
          <input type="hidden" name="note_<?= $key ?>" id="inp_note_<?= $key ?>" value=""/>
          <?php endforeach; ?>

          <!-- CHECKLIST TABLE (synced with diagram) -->
          <table class="tg-table" style="margin-bottom:0;">
            <thead>
              <tr>
                <th style="text-align:left;">Exterior Area</th>
                <th style="width:13%;text-align:center;">No Damage</th>
                <th style="width:13%;text-align:center;">Minor Scratch</th>
                <th style="width:13%;text-align:center;">Major Damage</th>
                <th style="text-align:left;">Notes</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($checklist_areas as $key => $label): ?>
              <tr class="checklist-row" id="row_<?= $key ?>" data-key="<?= $key ?>">
                <td class="area-name" style="font-weight:500;font-size:0.82rem;"><?= $label ?></td>
                <td style="text-align:center;"><span class="radio-dot active" data-val="none"   data-key="<?= $key ?>"><span></span></span></td>
                <td style="text-align:center;"><span class="radio-dot"        data-val="minor"  data-key="<?= $key ?>"><span></span></span></td>
                <td style="text-align:center;"><span class="radio-dot"        data-val="major"  data-key="<?= $key ?>"><span></span></span></td>
                <td>
                  <input type="text" class="field-input note-input" data-key="<?= $key ?>"
                    placeholder="—" style="font-size:0.78rem;padding:0.35rem 0.6rem;"/>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>

        </div>
      </div>

      <!-- FORM ACTIONS -->
      <div class="form-actions" style="background:transparent;border-top:none;padding:0.5rem 0 1.5rem;">
        <a href="repair_list.php" class="btn-ghost">Cancel</a>
        <button type="submit" class="btn-primary"><?= icon('check', 14) ?> Save Repair Job</button>
      </div>

    </form>

  </div>
</div>

<!-- Panel popup element -->
<div id="panel-popup"></div>

<script>
/* Pass PHP data to JS before external script loads */
window.areaLabels = <?= json_encode($checklist_areas) ?>;
</script>
<script src="../../assets/js/mechanic/add_repair.js?v=<?= filemtime(__DIR__ . '/../../assets/js/mechanic/add_repair.js') ?>"></script>

<?php require_once '../../includes/footer.php'; ?>
