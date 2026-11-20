<?php
require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . '/../../includes/data_access.php';
require_once '../../config/db.php';
require_once __DIR__ . '/../../includes/lookups.php';
require_once '../../config/validators.php';
require_once '../../config/access.php';
require_once '../../includes/transaction.php';
require_once '../../includes/idempotency.php';

require_role(['admin', 'super_admin', 'mechanic']);

$checklist_area_keys = array_keys(REPAIR_CHECKLIST_AREAS);   // config/access.php

$errors  = [];
$success = '';

// ── PREFILL FROM CLIENT PROFILE ──
$prefill_data = null;
$prefill_client_id  = (int)($_GET['prefill_client']  ?? 0);
$prefill_vehicle_id = (int)($_GET['prefill_vehicle'] ?? 0);
if ($prefill_client_id > 0 && client_in_scope($conn, $prefill_client_id)) {
    $pc_row = client_find_contact($conn, $prefill_client_id);
    if ($pc_row) {
        $prefill_data = [
            'client_id' => $pc_row['client_id'],
            'name'      => $pc_row['full_name'],
            'contact'   => $pc_row['contact_number'] ?? '',
            'vehicle'   => null,
        ];
        if ($prefill_vehicle_id > 0) {
            $pv_row = client_vehicle($conn, $prefill_client_id, $prefill_vehicle_id);
            if ($pv_row) $prefill_data['vehicle'] = $pv_row;
        }
    }
}

// ── HANDLE POST ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    idem_replay();   // the same form sent twice (double click, Back + Submit) creates one job, not two

    $client_id   = (int)($_POST['client_id']   ?? 0);
    $vehicle_id  = (int)($_POST['vehicle_id']  ?? 0);
    $repair_date = san_str($_POST['repair_date']  ?? '', 10);
    $release_date= san_str($_POST['release_date'] ?? '', 10);
    $service_type= san_enum($_POST['service_type'] ?? '', array_column(service_types_active($conn), 'code'));
    $additional  = san_str($_POST['additional_damages'] ?? '', MAX_NOTE);
    if (text_too_long($_POST['additional_damages'] ?? '', MAX_NOTE)) $errors[] = 'Additional damages note is too long (max ' . MAX_NOTE . ' characters).';

    if ($client_id  === 0) $errors[] = 'Client is required.';
    if ($vehicle_id === 0) $errors[] = 'Vehicle is required.';
    if ($repair_date === '') $errors[] = 'Repair date is required.';
    elseif (!validate_date($repair_date)) $errors[] = 'Repair date is not a valid date.';
    if ($release_date !== '' && !validate_date($release_date)) $errors[] = 'Release date is not a valid date.';
    if ($service_type === '') $errors[] = 'Service type is required.';

    // The client must exist and be one this user may work with (mechanics: walk-in only, admins: their own),
    // and the vehicle must actually belong to that client — the form only ever offers valid pairs.
    if (empty($errors)) {
        if (!client_in_scope($conn, $client_id)) {
            $errors[] = 'Selected client was not found.';
        } else {
            if (client_vehicle($conn, $client_id, $vehicle_id) === null) $errors[] = 'Selected vehicle does not belong to that client.';
        }
    }

    if (empty($errors)) {
        // Repair ID: RJ-YYYYMM-XXXX (record_number_prefix()) — continues from the highest number already used this month. (Counting
        // this month's rows breaks after a delete: it hands out a number that still exists, and the unique key
        // then rejects every new job for the rest of the month.) Wrapped in a retry: two mechanics saving at
        // the same instant can compute the same next number — this recomputes and retries instead of the
        // request crashing with an uncaught duplicate-key error.
        $rel = $release_date ?: null;
        // The job, its checklist and the audit entry are saved together or not at all (includes/transaction.php)
        $created = null;
        try {
            $created = db_transaction($conn, function () use ($conn, $client_id, $vehicle_id, $repair_date, $rel, $service_type, $additional, $checklist_area_keys) {
                [$job_id, $job_num] = insert_with_sequential_number($conn, 'repair_jobs', 'job_number', record_number_prefix('RJ'),
                    function (string $num) use ($conn, $client_id, $vehicle_id, $repair_date, $rel, $service_type, $additional) {
                        $id = repair_job_create($conn, $num, [
                            'client_id' => $client_id, 'vehicle_id' => $vehicle_id, 'repair_date' => $repair_date, 'release_date' => $rel,
                            'service_type' => $service_type, 'additional_damages' => $additional,
                        ], (int)$_SESSION['user_id']);
                        return [$id, $num];
                    }
                );

                // Save checklist
                foreach ($checklist_area_keys as $key) {
                    $cond  = san_enum($_POST['area_' . $key] ?? 'none', array_keys(REPAIR_DAMAGE_LEVELS));
                    $note  = san_str($_POST['note_' . $key] ?? '', 255);
                    repair_checklist_add($conn, (int)$job_id, $key, $cond, $note);
                }

                // Audit log
                $desc = ($_SESSION['full_name'] ?? 'Unknown') . ' created repair job ' . $job_num . '.';
                audit_log_add($conn, $_SESSION['user_id'], 'REPAIR_JOB_CREATED', $desc);

                return $job_num;
            });
        } catch (mysqli_sql_exception $e) {
            error_log('[TG-BASICS] add_repair.php job insert failed: ' . $e->getMessage());
        }

        if ($created !== null) {
            idem_redirect("repair_list.php?success=" . urlencode('Repair job ' . $created . ' created successfully.'));
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

// Exact areas from the physical Unit Inspection form (config/access.php)
$checklist_areas = REPAIR_CHECKLIST_AREAS;

// The inspection list in sectors (REPAIR_CHECKLIST_SECTORS), numbered 1..n in that order; the same number marks
// the part on the diagram. An area no sector names still shows, under "Other".
$checklist_sectors = [];
$area_num          = [];
foreach (REPAIR_CHECKLIST_SECTORS as $sector_key => $sector) {
    $keys = array_values(array_filter($sector['areas'], fn($k) => isset($checklist_areas[$k])));
    if ($keys) $checklist_sectors[$sector_key] = ['label' => $sector['label'], 'areas' => $keys];
}
$unsorted = array_diff($checklist_area_keys, array_merge(...array_column($checklist_sectors, 'areas')));
if ($unsorted) $checklist_sectors['other'] = ['label' => 'Other', 'areas' => array_values($unsorted)];
foreach ($checklist_sectors as $sector) foreach ($sector['areas'] as $k) $area_num[$k] = count($area_num) + 1;
$area_sector = [];
foreach ($checklist_sectors as $sector_key => $sector) foreach ($sector['areas'] as $k) $area_sector[$k] = $sector_key;

// Attributes for a clickable part of the car: only its first shape is a tab stop (the four tires are one area)
$part_seen = [];
$part = function (string $key, string $extra = '') use ($checklist_areas, $area_sector, &$part_seen): string {
    $first = !isset($part_seen[$key]);
    $part_seen[$key] = true;
    $label = htmlspecialchars($checklist_areas[$key] ?? $key, ENT_QUOTES);
    return 'class="panel ' . $extra . '" data-panel="' . $key . '" data-sector="' . ($area_sector[$key] ?? '') . '"'
         . ($first ? ' tabindex="0" role="button" aria-label="' . $label . '"' : ' aria-hidden="true"');
};
// Where each part's number sits on the top view (car coordinates, before the translate)
$car_pins = [
    'front_bumper' => [[120, 37]],
    'hood'         => [[120, 100]],
    'headlights'   => [[66, 66], [174, 66]],
    'windshield'   => [[120, 170]],
    'door_fl'      => [[61, 231]],
    'door_rl'      => [[61, 299]],
    'mirror_left'  => [[12, 187]],
    'door_fr'      => [[179, 231]],
    'door_rr'      => [[179, 299]],
    'mirror_right' => [[228, 187]],
    'trunk'        => [[120, 378]],
    'rear_bumper'  => [[120, 449]],
    'taillights'   => [[66, 419], [174, 419]],
    'tires_wheels' => [[38, 117], [202, 117], [38, 381], [202, 381]],
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

    <a href="repair_list.php" class="back-link" onclick="goBack('repair_list.php'); return false;"><?= icon('arrow-left', 14) ?> Back to Repair Jobs</a>

    <form method="POST" action="" id="repair-form">
      <?= csrf_field() ?><?= idem_field() ?>

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
                  placeholder="Search by client ID, name or plate number..." autocomplete="off"/>
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
                <?php // service_types table: one optgroup per group_label, in sort order
                $svc_groups = [];
                foreach (service_types_active($conn) as $st) $svc_groups[$st['group_label'] ?: 'Other'][] = $st;
                foreach ($svc_groups as $group => $types): ?>
                <optgroup label="<?= htmlspecialchars($group) ?>">
                  <?php foreach ($types as $st): ?>
                  <option value="<?= htmlspecialchars($st['code']) ?>"><?= htmlspecialchars($st['label']) ?></option>
                  <?php endforeach; ?>
                </optgroup>
                <?php endforeach; ?>
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
              <textarea name="additional_damages" class="field-input" rows="3" maxlength="<?= MAX_NOTE ?>"
                placeholder="Additional damages upon inspection..."
                style="resize:vertical;"></textarea>
            </div>

          </div>
        </div>

      </div>

      <!-- UNIT INSPECTION: top view of the car + the areas in sectors (both mark the same radio inputs) -->
      <div class="card" style="margin-bottom:1.25rem;">
        <div class="card-header">
          <div class="card-icon"><?= icon('clipboard-list', 16) ?></div>
          <div>
            <div class="card-title">Unit Inspection</div>
            <div class="card-sub">Tap a part of the car, or pick its condition in the list. The numbers match.</div>
          </div>
        </div>

        <div class="insp-wrap">

          <!-- CAR (top view, front at the top) -->
          <div class="insp-car-col">
            <div class="insp-car">
              <svg viewBox="0 0 300 524" class="car-svg" xmlns="http://www.w3.org/2000/svg" role="group" aria-label="Car seen from above, front at the top">
                <!-- sector names around the car -->
                <text x="150" y="13" class="sector-lbl">&#9650; FRONT</text>
                <text x="150" y="519" class="sector-lbl">REAR</text>
                <text x="10" y="262" class="sector-lbl" transform="rotate(-90 10 262)">LEFT &middot; DRIVER</text>
                <text x="290" y="262" class="sector-lbl" transform="rotate(90 290 262)">RIGHT &middot; PASSENGER</text>

                <g transform="translate(30 30)">
                  <!-- tires sit under the body edge -->
                  <rect x="28"  y="92"  width="20" height="50" rx="6" <?= $part('tires_wheels', 'tire') ?>/>
                  <rect x="192" y="92"  width="20" height="50" rx="6" <?= $part('tires_wheels', 'tire') ?>/>
                  <rect x="28"  y="356" width="20" height="50" rx="6" <?= $part('tires_wheels', 'tire') ?>/>
                  <rect x="192" y="356" width="20" height="50" rx="6" <?= $part('tires_wheels', 'tire') ?>/>

                  <!-- body (not an area) -->
                  <path class="car-body" d="M70 40 C88 26 152 26 170 40 C186 50 194 70 194 100 L196 380 C196 420 186 446 166 454 C148 461 92 461 74 454 C54 446 44 420 44 380 L46 100 C46 70 54 50 70 40 Z"/>

                  <!-- mirrors -->
                  <path d="M46 182 L24 177 C19 177 17 182 19 187 L25 196 L46 196 Z" <?= $part('mirror_left') ?>/>
                  <path d="M194 182 L216 177 C221 177 223 182 221 187 L215 196 L194 196 Z" <?= $part('mirror_right') ?>/>

                  <!-- front -->
                  <path d="M64 44 C86 28 154 28 176 44 L170 56 C148 44 92 44 70 56 Z" <?= $part('front_bumper') ?>/>
                  <path d="M86 58 C108 54 132 54 154 58 L178 80 L176 140 C150 133 90 133 64 140 L62 80 Z" <?= $part('hood') ?>/>
                  <path d="M52 72 C56 59 66 53 80 51 L84 61 C72 63 63 69 57 80 Z" <?= $part('headlights', 'lamp-front') ?>/>
                  <path d="M188 72 C184 59 174 53 160 51 L156 61 C168 63 177 69 183 80 Z" <?= $part('headlights', 'lamp-front') ?>/>
                  <path d="M68 148 C98 138 142 138 172 148 L160 196 C136 190 104 190 80 196 Z" <?= $part('windshield', 'glass') ?>/>

                  <!-- cabin: roof and rear glass are not areas -->
                  <path class="car-roof" d="M80 200 C104 195 136 195 160 200 L160 298 C136 302 104 302 80 298 Z"/>
                  <path class="car-glass" d="M80 302 C104 306 136 306 160 302 L170 336 C140 344 100 344 70 336 Z"/>

                  <!-- doors -->
                  <path d="M46 200 L76 198 L76 263 L46 263 Z" <?= $part('door_fl') ?>/>
                  <path d="M46 267 L76 267 L76 331 L46 336 Z" <?= $part('door_rl') ?>/>
                  <path d="M194 200 L164 198 L164 263 L194 263 Z" <?= $part('door_fr') ?>/>
                  <path d="M194 267 L164 267 L164 331 L194 336 Z" <?= $part('door_rr') ?>/>

                  <!-- rear -->
                  <path d="M68 344 C100 352 140 352 172 344 L178 400 C150 410 90 410 62 400 Z" <?= $part('trunk') ?>/>
                  <path d="M52 404 L80 412 L78 428 C64 424 56 416 52 404 Z" <?= $part('taillights', 'lamp-rear') ?>/>
                  <path d="M188 404 L160 412 L162 428 C176 424 184 416 188 404 Z" <?= $part('taillights', 'lamp-rear') ?>/>
                  <path d="M64 434 C90 447 150 447 176 434 L180 442 C170 455 150 461 120 461 C90 461 70 455 60 442 Z" <?= $part('rear_bumper') ?>/>

                  <!-- numbers (match the list) -->
                  <?php foreach ($car_pins as $key => $spots): if (!isset($area_num[$key])) continue; foreach ($spots as [$px, $py]): ?>
                  <g class="pin" data-pin="<?= $key ?>" transform="translate(<?= $px ?> <?= $py ?>)" aria-hidden="true">
                    <circle r="8.5"/><text y="3.2"><?= $area_num[$key] ?></text>
                  </g>
                  <?php endforeach; endforeach; ?>
                </g>
              </svg>
            </div>

            <div class="insp-legend">
              <span class="legend-chip none"><?= htmlspecialchars(REPAIR_DAMAGE_LEVELS['none']['label']) ?></span>
              <span class="legend-chip minor"><?= htmlspecialchars(REPAIR_DAMAGE_LEVELS['minor']['label']) ?></span>
              <span class="legend-chip major"><?= htmlspecialchars(REPAIR_DAMAGE_LEVELS['major']['label']) ?></span>
            </div>
          </div>

          <!-- AREAS BY SECTOR -->
          <div class="insp-list-col">
            <div class="insp-summary" id="insp-summary" aria-live="polite">No damage marked</div>

            <?php foreach ($checklist_sectors as $sector_key => $sector): ?>
            <section class="insp-sector" data-sector="<?= $sector_key ?>">
              <header class="insp-sector-head">
                <span class="insp-sector-name"><?= htmlspecialchars($sector['label']) ?></span>
                <span class="insp-sector-count" data-count="<?= $sector_key ?>"></span>
              </header>

              <?php foreach ($sector['areas'] as $key): $label = htmlspecialchars($checklist_areas[$key]); ?>
              <div class="insp-row" id="row_<?= $key ?>" data-key="<?= $key ?>" data-sector="<?= $sector_key ?>">
                <span class="insp-num"><?= $area_num[$key] ?></span>
                <span class="insp-name area-name"><?= $label ?></span>
                <div class="insp-seg" role="radiogroup" aria-label="<?= $label ?> condition">
                  <?php foreach (REPAIR_DAMAGE_LEVELS as $lvl => $d): ?>
                  <label class="seg seg-<?= $lvl ?>">
                    <input type="radio" name="area_<?= $key ?>" value="<?= $lvl ?>"<?= $lvl === 'none' ? ' checked' : '' ?>/>
                    <span><?= htmlspecialchars($d['label']) ?></span>
                  </label>
                  <?php endforeach; ?>
                </div>
                <input type="text" class="field-input insp-note note-input" name="note_<?= $key ?>" data-key="<?= $key ?>"
                       maxlength="255" placeholder="Notes for <?= $label ?> (optional)" aria-label="<?= $label ?> notes"/>
              </div>
              <?php endforeach; ?>
            </section>
            <?php endforeach; ?>
          </div>

        </div>
      </div>

      <!-- FORM ACTIONS -->
      <div class="form-actions" style="background:transparent;border-top:none;padding:0.5rem 0 1.5rem;">
        <a href="repair_list.php" class="btn-ghost" onclick="goBack('repair_list.php'); return false;">Cancel</a>
        <button type="submit" class="btn-primary"><?= icon('check', 14) ?> Save Repair Job</button>
      </div>

    </form>

  </div>
</div>

<!-- Panel popup element -->
<div id="panel-popup" role="dialog" aria-label="Part condition"></div>

<script>
window.areaLabels   = <?= json_encode($checklist_areas) ?>;
window.damageLabels = <?= json_encode(array_map(fn($d) => $d['label'], REPAIR_DAMAGE_LEVELS)) ?>;
window.prefillData  = <?= $prefill_data ? json_encode($prefill_data) : 'null' ?>;
</script>
<script src="../../assets/js/mechanic/add_repair.js?v=<?= filemtime(__DIR__ . '/../../assets/js/mechanic/add_repair.js') ?>"></script>
<?php if ($prefill_data): ?>
<script>
(function() {
  if (!window.prefillData) return;
  var d = window.prefillData;
  document.getElementById('client-search').value   = d.name;
  document.getElementById('client_id_input').value = d.client_id;
  document.getElementById('contact_number').value  = d.contact;

  fetch('ajax_get_vehicles.php?client_id=' + d.client_id)
    .then(function(r){ return r.json(); })
    .then(function(resp) {
      var vehicles = resp.vehicles || [];
      var sel = document.getElementById('vehicle_id_select');
      if (!vehicles.length) {
        sel.innerHTML = '<option value="">No vehicles for this client</option>';
        sel.disabled  = true;
        return;
      }
      sel.innerHTML = '<option value="">— Select vehicle —</option>' + vehicles.map(function(v) {
        return '<option value="' + v.vehicle_id + '"'
          + ' data-make="'  + (v.make        || '') + '"'
          + ' data-model="' + (v.model       || '') + '"'
          + ' data-year="'  + (v.year_model  || '') + '"'
          + ' data-color="' + (v.color       || '') + '"'
          + ' data-plate="' + (v.plate_number|| '') + '">'
          + v.plate_number + ' — ' + v.make + ' ' + v.model
          + '</option>';
      }).join('');
      sel.disabled = false;

      if (d.vehicle) {
        sel.value = d.vehicle.vehicle_id;
      } else if (vehicles.length === 1) {
        sel.value = vehicles[0].vehicle_id;
      }
      sel.dispatchEvent(new Event('change'));
    });
})();
</script>
<?php endif; ?>

<?php require_once '../../includes/footer.php'; ?>
