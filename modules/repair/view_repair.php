<?php
require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . '/../../includes/data_access.php';
require_once '../../config/db.php';
require_once __DIR__ . '/../../includes/lookups.php';
require_once '../../config/validators.php';
require_once '../../config/mailer.php';
require_once '../../includes/transaction.php';
require_once '../../config/uploads.php';
require_once '../../config/access.php';
require_once '../../config/transaction_pin.php';
require_once '../../includes/idempotency.php';
require_once '../../includes/repair_inspection.php';

require_role(['admin', 'super_admin', 'mechanic']);

$role   = $_SESSION['role'];
$job_id = san_int($_GET['id'] ?? 0, 1);
if (!$job_id) { header("Location: repair_list.php"); exit; }

// ── FETCH JOB ──
$job = repair_job_find($conn, $job_id);
if (!$job) { header("Location: repair_list.php"); exit; }

// ── FETCH CHECKLIST ── (area_key => row)
$checklist = repair_checklist($conn, $job_id);

// ── FETCH IMAGES ──
$images = repair_job_images($conn, $job_id);

// One photo of this job, for the show / hide / delete actions below (null if it is not this job's)
$find_photo = fn(int $img_id): ?array => repair_job_image($conn, $img_id, $job_id);
$photo_log = function (string $action, string $what) use ($conn, $job) {
    $desc = ($_SESSION['full_name'] ?? 'Unknown') . ' ' . $what . ' on repair job ' . $job['job_number'] . '.';
    audit_log_add($conn, $_SESSION['user_id'], $action, $desc);
};

// ── HANDLE PHOTO DELETE ── only while the job is open; a mechanic only their own upload (repair_photo_editable())
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_image') {
    csrf_verify();
    // the page asks for the Transaction PIN first (requirePin()); the server makes sure it was given
    if (!pin_consume($conn)) {
        header("Location: view_repair.php?id=$job_id&error=" . urlencode(PIN_REQUIRED_MSG));
        exit;
    }
    $photo = $find_photo(san_int($_POST['image_id'] ?? 0, 1));
    if (!$photo || !repair_photo_editable((int)$photo['uploaded_by'], $job['status'])) {
        header("Location: view_repair.php?id=$job_id&error=" . urlencode('This photo can no longer be removed.'));
        exit;
    }
    db_transaction($conn, function () use ($conn, $photo, $photo_log) {
        repair_job_image_delete($conn, (int)$photo['image_id']);
        $photo_log('REPAIR_PHOTO_DELETED', 'removed a ' . ($photo['show_client'] ? 'client-visible' : 'private') . ' photo');
    });
    $path = __DIR__ . '/../../uploads/repair_jobs/' . $job_id . '/' . basename($photo['file_name']);
    if (is_file($path)) unlink($path);   // only after the row is gone (CLAUDE.md: files after the commit)
    header("Location: view_repair.php?id=$job_id&success=Photo removed.");
    exit;
}

// ── HANDLE SHOW / HIDE ON THE CLIENT'S QR PAGE ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_photo') {
    csrf_verify();
    $photo = $find_photo(san_int($_POST['image_id'] ?? 0, 1));
    if (!$photo || !repair_photo_editable((int)$photo['uploaded_by'], $job['status'])) {
        header("Location: view_repair.php?id=$job_id&error=" . urlencode('This photo can no longer be changed.'));
        exit;
    }
    $show = ($_POST['show'] ?? '') === '1' ? 1 : 0;
    db_transaction($conn, function () use ($conn, $photo, $show, $photo_log) {
        repair_job_image_set_visible($conn, (int)$photo['image_id'], (bool)$show);
        $photo_log('REPAIR_PHOTO_VISIBILITY', $show ? 'showed a photo to the client' : 'hid a photo from the client');
    });
    header("Location: view_repair.php?id=$job_id&success=" . urlencode($show ? 'Photo is now shown to the client.' : 'Photo is now hidden from the client.'));
    exit;
}

// ── HANDLE PHOTO UPLOAD ── metadata (GPS, phone model, date) is stripped before the file is kept
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_image') {
    csrf_verify();
    idem_replay();   // the same upload sent twice (double click) saves the photos once
    require_once '../../includes/image_meta.php';
    $files = $_FILES['job_images'] ?? null;
    if (!$files || !is_array($files['tmp_name'] ?? null) || ($files['name'][0] ?? '') === '') {
        header("Location: view_repair.php?id=$job_id&error=" . urlencode('Choose at least one photo to upload.'));
        exit;
    }
    if (text_too_long($_POST['caption'] ?? '', MAX_NOTE)) {
        header("Location: view_repair.php?id=$job_id&error=" . urlencode('The caption is too long (max ' . MAX_NOTE . ' characters).'));
        exit;
    }
    $caption = san_str($_POST['caption'] ?? '', MAX_NOTE);
    $caption = $caption === '' ? null : $caption;
    // a finished job's photos never reach the QR page, so they are never marked for it
    $show    = (($_POST['show_client'] ?? '') === '1' && repair_job_is_open($job['status'])) ? 1 : 0;
    $upload_dir = __DIR__ . '/../../uploads/repair_jobs/' . $job_id . '/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
    $types   = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $saved   = [];
    $skipped = 0;
    foreach ($files['tmp_name'] as $i => $tmp) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($files['size'][$i] ?? 0) > REPAIR_PHOTO_MAX_BYTES) { $skipped++; continue; }
        $mime = mime_content_type($tmp);
        if (!isset($types[$mime]) || !strip_image_metadata($tmp, $mime)) { $skipped++; continue; }
        $fname = bin2hex(random_bytes(12)) . '.' . $types[$mime];
        if (move_uploaded_file($tmp, $upload_dir . $fname)) $saved[] = $fname; else $skipped++;
    }
    if ($saved) {
        try {
            db_transaction($conn, function () use ($conn, $saved, $job_id, $show, $caption, $photo_log) {
                foreach ($saved as $fname) {
                    repair_job_image_add($conn, $job_id, $fname, (int)$_SESSION['user_id'], (bool)$show, $caption);
                }
                $photo_log('REPAIR_PHOTO_UPLOADED', 'uploaded ' . count($saved) . ' photo(s)' . ($show ? ' shown to the client' : ''));
            });
        } catch (Throwable $e) {
            foreach ($saved as $fname) @unlink($upload_dir . $fname);
            throw $e;
        }
    }
    $msg = count($saved) . ' photo(s) uploaded.' . ($skipped ? ' ' . $skipped . ' skipped (not a JPG/PNG/WEBP/GIF image, unreadable, or over ' . (REPAIR_PHOTO_MAX_BYTES >> 20) . ' MB).' : '');
    if ($saved) idem_redirect("view_repair.php?id=$job_id&success=" . urlencode($msg));
    header("Location: view_repair.php?id=$job_id&error=" . urlencode($msg));
    exit;
}

// ── HANDLE INSPECTION SIGN-OFF (AJAX) ──
// The paper checklist was signed by the client and by the shop; this records the same two signatures. A signature,
// once saved, is final — the UNIQUE (job_id, signer_role) key refuses a second one for the same side.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sign_inspection') {
    require_once '../../includes/api.php';
    csrf_verify_json();

    $signer_role = san_enum($_POST['signer_role'] ?? '', ['client', 'staff']);
    if ($signer_role === '') api_error('Choose who is signing.');
    // The shop's signature is always the person signed in. The client's name defaults to the client on record,
    // but someone else (a driver, a family member) may sign on their behalf, so it can be typed.
    $signer_name = $signer_role === 'staff'
        ? (string)($_SESSION['full_name'] ?? '')
        : san_str($_POST['signer_name'] ?? '', 150);
    if ($signer_name === '') api_error('Enter the name of the person signing.');

    $data = $_POST['signature'] ?? '';
    if (!is_string($data) || !preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $data, $m)) {
        api_error('The signature could not be read. Please sign again.');
    }
    $png  = base64_decode($m[1], true);
    $info = $png !== false ? getimagesizefromstring($png) : false;
    if ($png === false || strlen($png) > 512000 || !$info || $info[2] !== IMAGETYPE_PNG
        || $info[0] < 40 || $info[1] < 20 || $info[0] > 4000 || $info[1] > 3000) {
        api_error('The signature could not be read. Please sign again.');
    }

    $dir = __DIR__ . '/../../uploads/repair_jobs/' . $job_id . '/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $fname = 'sig_' . $signer_role . '_' . bin2hex(random_bytes(8)) . '.png';
    if (file_put_contents($dir . $fname, $png) === false) api_error('The signature could not be saved. Please try again.', 500);

    try {
        db_transaction($conn, function () use ($conn, $job_id, $job, $signer_role, $signer_name, $fname) {
            repair_job_signature_add($conn, $job_id, $signer_role, $signer_name, $fname, (int)$_SESSION['user_id']);
            $desc = ($_SESSION['full_name'] ?? 'Unknown') . ' recorded the ' . ($signer_role === 'client' ? 'client' : 'shop')
                  . ' signature (' . $signer_name . ') on the inspection checklist of repair job ' . $job['job_number'] . '.';
            audit_log_add($conn, $_SESSION['user_id'], 'REPAIR_SIGNED', $desc);
        });
    } catch (mysqli_sql_exception $e) {
        @unlink($dir . $fname);
        if ($e->getCode() === 1062) api_error('This side has already been signed.', 409);
        error_log('[TG-BASICS] sign_inspection failed: ' . $e->getMessage());
        api_error('The signature could not be saved. Please try again.', 500);
    }

    api_success([
        'signer_name' => $signer_name,
        'signed_at'   => date('M d, Y · g:i A'),
        'url'         => upload_url('repair_jobs', $fname, $job_id),
    ], 'Signature saved.');
}

// ── FETCH SIGNATURES ──
$signatures = repair_job_signatures($conn, $job_id);   // signer_role => row

// ── HANDLE STATUS UPDATE ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrf_verify();

    if ($_POST['action'] === 'update_status') {
        $new_status = san_enum($_POST['status'] ?? '', array_keys(repair_statuses()));
        if ($new_status && $new_status === $job['status']) {
            // already at that status (sent twice, or someone else did it first): nothing to change, not an error
            header("Location: view_repair.php?id=$job_id&success=Status updated.");
            exit;
        }
        if ($new_status) {
            if (!repair_status_change_allowed($job['status'], $new_status, is_mechanic())) {
                header("Location: view_repair.php?id=$job_id&error=" . urlencode('This job can no longer go back to that status.'));
                exit;
            }
            // The status change and its audit entry are saved together or not at all (includes/transaction.php).
            // "AND status = ?" makes it a no-op if someone else changed the status since this page was loaded.
            $changed = db_transaction($conn, function () use ($conn, $job_id, $job, $new_status) {
                if (!repair_job_set_status($conn, $job_id, $job['status'], $new_status)) return false;
                $desc = ($_SESSION['full_name'] ?? 'Unknown') . ' updated repair job ' . $job['job_number'] . ' status to ' . $new_status . '.';
                audit_log_add($conn, $_SESSION['user_id'], 'REPAIR_STATUS_UPDATED', $desc);
                return true;
            });
            if (!$changed) {
                header("Location: view_repair.php?id=$job_id&error=" . urlencode('The status was changed by someone else. Please check it and try again.'));
                exit;
            }

            if ($new_status === 'completed' && !empty($job['email'])) {
                $pub_token = client_public_token($conn, (int)$job['client_id']);
                $pub_url   = app_url('modules/public/client.php?token=' . urlencode($pub_token));

                $svc_labels = service_type_labels($conn);   // service_types table (includes/lookups.php)
                $svc = $svc_labels[$job['service_type']] ?? $job['service_type'];
                sendRepairCompletedEmail(
                    $job['email'],
                    $job['full_name'],
                    $job['job_number'],
                    $job['plate_number'],
                    $job['make'] . ' ' . $job['model'] . ' ' . $job['year_model'],
                    $svc,
                    $job['release_date'] ?: null,
                    $pub_url
                );
            }

            header("Location: view_repair.php?id=$job_id&success=Status updated.");
            exit;
        }
    }

    if ($_POST['action'] === 'update_notes') {
        if (text_too_long($_POST['additional_damages'] ?? '', MAX_NOTE)) {
            header("Location: view_repair.php?id=$job_id&error=" . urlencode('Notes are too long (max ' . MAX_NOTE . ' characters).'));
            exit;
        }
        $notes = san_str($_POST['additional_damages'] ?? '', MAX_NOTE);
        repair_job_set_notes($conn, $job_id, $notes);
        header("Location: view_repair.php?id=$job_id&success=Notes updated.");
        exit;
    }

    if ($_POST['action'] === 'update_release') {
        $rel = san_str($_POST['release_date'] ?? '', 10);
        if ($rel === '' || validate_date($rel)) {
            repair_job_set_release_date($conn, $job_id, $rel ?: null);
            header("Location: view_repair.php?id=$job_id&success=Release date updated.");
            exit;
        }
    }
}

// ── LABEL MAPS ──
$service_labels = service_type_labels($conn);   // service_types table (includes/lookups.php)

// Unit Inspection: numbering + each area's condition (includes/repair_inspection.php)
$insp_layout = repair_inspection_layout();
$insp_cond   = repair_inspection_conditions($checklist);
$damaged     = array_filter($insp_cond, fn($c) => $c !== 'none');
$damage_word = count($damaged) === 1 ? 'area' : 'areas';

$sb      = repair_status_badge($job['status']);   // statuses table (includes/statuses.php)
$svc_lbl = $service_labels[$job['service_type']] ?? $job['service_type'];

// statuses this user may pick next; a mechanic on a completed / cancelled job has none, so no button
$next_statuses  = array_filter(array_keys(repair_statuses()), fn($s) => repair_status_change_allowed($job['status'], $s, is_mechanic()));
$existing_qt_id = quotation_id_for_job($conn, $job_id);

$css = fn(string $path) => '<link rel="stylesheet" href="../../assets/css/' . $path . '?v=' . filemtime(__DIR__ . '/../../assets/css/' . $path) . '"/>';
$page_title  = 'Repair Job — ' . $job['job_number'];
$active_page = 'repair';
$base_path   = '../../';
$extra_css   = $css('mechanic/repair_list.css') . $css('shared/repair_car.css') . $css('mechanic/view_repair.css');
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
  Swal.fire({ toast:true, position:'top-end', icon:'success', titleText:<?= json_encode(san_str($_GET['success'],200)) ?>, showConfirmButton:false, timer:3000, timerProgressBar:true });
});
</script>
<?php endif; ?>
<?php if (!empty($_GET['error'])): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
  Swal.fire({ toast:true, position:'top-end', icon:'error', titleText:<?= json_encode(san_str($_GET['error'],200)) ?>, showConfirmButton:false, timer:4000, timerProgressBar:true });
});
</script>
<?php endif; ?>

  <!-- BACK + HEADER ROW -->
  <div class="rv-head">
    <a href="repair_list.php" class="back-link" onclick="goBack('repair_list.php'); return false;" aria-label="Back to Repair Jobs"><?= icon('arrow-left', 14) ?></a>
    <div class="rv-head-actions">
      <span class="badge <?= $sb['class'] ?>"><?= htmlspecialchars($sb['label']) ?></span>
      <?php if ($next_statuses): ?>
      <button type="button" onclick="document.getElementById('status-modal').style.display='flex'" class="btn-primary">
        <?= icon('arrow-right', 13) ?> Update Status
      </button>
      <?php endif; ?>
      <?php if ($existing_qt_id !== null): ?>
        <a href="../quotations/view_quotation.php?id=<?= $existing_qt_id ?>" class="btn-primary"><?= icon('receipt', 13) ?> View Quotation</a>
      <?php else: ?>
        <a href="../quotations/add_quotation.php?job_id=<?= $job_id ?>" class="btn-primary"><?= icon('receipt', 13) ?> Generate Quotation</a>
      <?php endif; ?>
    </div>
  </div>

  <!-- TOP GRID: Job Info + Client/Vehicle -->
  <div class="rv-grid">

    <!-- JOB DETAILS -->
    <div class="card">
      <div class="card-header">
        <div class="card-icon"><?= icon('wrench', 16) ?></div>
        <div>
          <div class="card-title">Job Details</div>
          <div class="card-sub"><?= htmlspecialchars($job['job_number']) ?></div>
        </div>
      </div>
      <div class="rv-body">
        <div class="rv-dl">
          <div class="rv-row"><span class="rv-lbl"><?= icon('wrench', 13) ?> Service Type</span><span class="rv-val"><?= htmlspecialchars($svc_lbl) ?></span></div>
          <div class="rv-row"><span class="rv-lbl"><?= icon('calendar', 13) ?> Repair Date</span><span class="rv-val"><?= date('F d, Y', strtotime($job['repair_date'])) ?></span></div>
          <div class="rv-row"><span class="rv-lbl"><?= icon('calendar', 13) ?> Est. Release</span>
            <span class="rv-val<?= $job['release_date'] ? '' : ' muted' ?>"><?= $job['release_date'] ? date('F d, Y', strtotime($job['release_date'])) : 'Not set' ?></span></div>
          <div class="rv-row"><span class="rv-lbl"><?= icon('arrow-path', 13) ?> Status</span><span class="rv-val"><span class="badge <?= $sb['class'] ?>"><?= htmlspecialchars($sb['label']) ?></span></span></div>
          <div class="rv-row"><span class="rv-lbl"><?= icon('clock', 13) ?> Date Created</span><span class="rv-val"><?= date('F d, Y g:i A', strtotime($job['created_at'])) ?></span></div>
        </div>

        <?php if ($sb['progress'] > 0): ?>
        <div class="rv-progress" aria-label="Repair progress <?= $sb['progress'] ?>%">
          <div class="rv-progress-top"><span>Repair progress</span><span><?= $sb['progress'] ?>%</span></div>
          <div class="rv-progress-bar"><span style="width:<?= $sb['progress'] ?>%;"></span></div>
        </div>
        <?php endif; ?>

        <!-- Quick release date update -->
        <form method="POST" class="rv-release">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="update_release"/>
          <label for="rv-release-date">Est. release</label>
          <input type="date" id="rv-release-date" name="release_date" value="<?= htmlspecialchars($job['release_date'] ?? '') ?>"/>
          <button type="submit" class="btn-sm-gold"><?= icon('check', 13) ?> Set Release</button>
        </form>
      </div>
    </div>

    <!-- CLIENT & VEHICLE -->
    <div class="card">
      <div class="card-header">
        <div class="card-icon"><?= icon('user', 16) ?></div>
        <div>
          <div class="card-title">Client &amp; Vehicle</div>
          <div class="card-sub"><?= htmlspecialchars($job['full_name']) ?></div>
        </div>
        <a href="../clients/view_client.php?id=<?= $job['client_id'] ?>" class="btn-sm-gold" style="margin-left:auto;"><?= icon('eye', 13) ?> View Client</a>
      </div>
      <div class="rv-body">
        <div class="rv-dl">
          <div class="rv-group">Client</div>
          <div class="rv-row"><span class="rv-lbl"><?= icon('user', 13) ?> Client</span><span class="rv-val"><?= htmlspecialchars($job['full_name']) ?></span></div>
          <div class="rv-row"><span class="rv-lbl"><?= icon('phone', 13) ?> Contact</span><span class="rv-val<?= $job['contact_number'] ? '' : ' muted' ?>"><?= htmlspecialchars($job['contact_number'] ?: '—') ?></span></div>
          <div class="rv-row"><span class="rv-lbl"><?= icon('envelope', 13) ?> Email</span><span class="rv-val<?= $job['email'] ? '' : ' muted' ?>"><?= htmlspecialchars($job['email'] ?: '—') ?></span></div>
          <div class="rv-group">Vehicle</div>
          <div class="rv-row"><span class="rv-lbl"><?= icon('truck', 13) ?> Plate Number</span><span class="rv-val"><span class="badge-dark"><?= htmlspecialchars($job['plate_number']) ?></span></span></div>
          <div class="rv-row"><span class="rv-lbl"><?= icon('truck', 13) ?> Make &amp; Model</span><span class="rv-val"><?= htmlspecialchars(trim($job['year_model'] . ' ' . $job['make'] . ' ' . $job['model'])) ?></span></div>
          <div class="rv-row"><span class="rv-lbl"><?= icon('swatch', 13) ?> Color</span><span class="rv-val<?= $job['color'] ? '' : ' muted' ?>"><?= htmlspecialchars($job['color'] ?: '—') ?></span></div>
        </div>
      </div>
    </div>

  </div>

  <!-- INSPECTION CHECKLIST: a small copy of the car from New Repair Job, for reference -->
  <div class="card rv-card" id="inspection-card">
    <div class="card-header">
      <div class="card-icon"><?= icon('clipboard-list', 16) ?></div>
      <div>
        <div class="card-title">Unit Inspection Checklist</div>
        <div class="card-sub">Damage assessment at intake</div>
      </div>
      <div class="rv-head-right">
        <?php if ($damaged): ?>
        <span class="badge badge-red"><?= count($damaged) ?> <?= $damage_word ?> damaged</span>
        <?php else: ?>
        <span class="badge badge-green"><?= icon('check', 11) ?> No damage</span>
        <?php endif; ?>
        <a href="print_inspection.php?id=<?= $job_id ?>" class="btn-sm-gold" id="print-inspection" target="_blank" rel="noopener"><?= icon('printer', 13) ?> <span>Print</span></a>
      </div>
    </div>
    <div class="rv-body">
      <div class="rv-insp">
        <div class="rv-insp-car">
          <?= repair_car_svg($insp_cond) ?>
          <div class="insp-legend">
            <span class="legend-chip none"><?= htmlspecialchars(REPAIR_DAMAGE_LEVELS['none']['label']) ?></span>
            <span class="legend-chip minor"><?= htmlspecialchars(REPAIR_DAMAGE_LEVELS['minor']['label']) ?></span>
            <span class="legend-chip major"><?= htmlspecialchars(REPAIR_DAMAGE_LEVELS['major']['label']) ?></span>
          </div>
        </div>

        <div class="rv-insp-list">
          <?php if (!$damaged): ?>
          <div class="rv-clean"><?= icon('check-circle', 22) ?>
            <div>No damage recorded on intake.<small>All <?= count($insp_cond) ?> areas were checked as No Damage.</small></div>
          </div>
          <?php else: ?>
          <div class="rv-insp-title">Damage found</div>
          <div class="rv-dmg-list">
            <?php foreach ($insp_layout['num'] as $key => $n):
              $cond = $insp_cond[$key] ?? 'none';
              if ($cond === 'none') continue;
              $note = trim((string)($checklist[$key]['notes'] ?? '')); ?>
            <div class="rv-dmg <?= $cond ?>" data-key="<?= $key ?>">
              <span class="rv-num"><?= $n ?></span>
              <span class="rv-dmg-name"><?= htmlspecialchars(REPAIR_CHECKLIST_AREAS[$key]) ?></span>
              <span class="rv-cond <?= $cond ?>"><?= htmlspecialchars(REPAIR_DAMAGE_LEVELS[$cond]['label']) ?></span>
              <?php if ($note !== ''): ?><div class="rv-dmg-note"><?= htmlspecialchars($note) ?></div><?php endif; ?>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

          <?php if ($damaged && count($damaged) < count($insp_cond)): ?>
          <div class="rv-insp-title">No damage</div>
          <div class="rv-ok-list">
            <?php foreach ($insp_layout['num'] as $key => $n): if (($insp_cond[$key] ?? 'none') !== 'none') continue; ?>
            <span class="rv-ok" data-key="<?= $key ?>"><span class="rv-num"><?= $n ?></span><?= htmlspecialchars(REPAIR_CHECKLIST_AREAS[$key]) ?></span>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- INSPECTION SIGN-OFF -->
  <?php
  $signed_count = count($signatures);
  $sig_sides = [
      'client' => ['Client', 'Confirms the condition recorded above', $job['full_name']],
      'staff'  => ['Shop Representative', 'Inspected and recorded by', $_SESSION['full_name'] ?? ''],
  ];
  ?>
  <div class="card rv-card" id="signoff-card">
    <div class="card-header">
      <div class="card-icon"><?= icon('pencil', 16) ?></div>
      <div>
        <div class="card-title">Inspection Sign-off</div>
        <div class="card-sub">Client and shop signatures on the intake checklist</div>
      </div>
      <div class="rv-head-right" id="signoff-status">
        <?php if ($signed_count === 2): ?>
        <span class="badge badge-green"><?= icon('check', 11) ?> Fully signed</span>
        <?php else: ?>
        <span class="badge badge-yellow"><?= $signed_count ?> of 2 signed</span>
        <?php endif; ?>
      </div>
    </div>
    <div class="rv-body rv-sig-grid">
      <?php foreach ($sig_sides as $side => [$side_label, $side_hint, $default_name]):
        $sig = $signatures[$side] ?? null; ?>
      <div class="sig-slot<?= $sig ? ' is-signed' : '' ?>" data-side="<?= $side ?>">
        <div class="sig-slot-head">
          <span class="sig-slot-role"><?= $side_label ?></span>
          <span class="sig-slot-hint"><?= $side_hint ?></span>
        </div>
        <?php if ($sig): ?>
        <div class="sig-body signed">
          <img src="<?= htmlspecialchars(upload_url('repair_jobs', $sig['file_name'], $job_id)) ?>" alt="<?= $side_label ?> signature"/>
        </div>
        <div class="sig-slot-foot">
          <strong><?= htmlspecialchars($sig['signer_name']) ?></strong>
          Signed <?= date('M d, Y · g:i A', strtotime($sig['signed_at'])) ?>
        </div>
        <?php else: ?>
        <div class="sig-body empty">
          <span>Not signed yet</span>
          <button type="button" class="btn-sm-gold" onclick="openSignModal('<?= $side ?>', <?= htmlspecialchars(json_encode($default_name), ENT_QUOTES) ?>)">
            <?= icon('pencil', 12) ?> Sign
          </button>
        </div>
        <div class="sig-slot-foot">
          <?= $side === 'client' ? 'Hand the device to the client to sign' : 'Signs as ' . htmlspecialchars($default_name) ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- VEHICLE PHOTOS -->
  <div class="card rv-card">
    <div class="card-header">
      <div class="card-icon"><?= icon('camera', 16) ?></div>
      <div>
        <div class="card-title">Vehicle Photos</div>
        <div class="card-sub"><?= count($images) ?> photo<?= count($images) !== 1 ? 's' : '' ?> attached</div>
      </div>
      <div class="rv-head-right">
        <button type="button" onclick="document.getElementById('upload-panel').classList.toggle('open')" class="btn-sm-gold">
          <?= icon('plus', 13) ?> Add Photos
        </button>
      </div>
    </div>

    <!-- UPLOAD FORM -->
    <div id="upload-panel" class="rv-upload">
      <form method="POST" enctype="multipart/form-data">
        <?= csrf_field() ?><?= idem_field() ?>
        <input type="hidden" name="action" value="upload_image"/>
        <label class="rv-upload-lbl">Select images (JPG, PNG, WEBP — multiple allowed, up to <?= REPAIR_PHOTO_MAX_BYTES >> 20 ?> MB each)</label>
        <input type="file" name="job_images[]" multiple accept="image/jpeg,image/png,image/webp,image/gif" data-compress="photo"/>
        <?php if (repair_job_is_open($job['status'])): ?>
        <label class="rv-upload-check">
          <input type="checkbox" name="show_client" value="1" id="photo-show-client"/>
          <span><strong>Show these photos to the client</strong> on their QR page while the job is open.
            <small>Photograph the vehicle only: no faces, no other cars' plates, no documents left inside. Location and phone details are removed from the photos automatically.</small></span>
        </label>
        <input type="text" name="caption" class="field-input" maxlength="<?= MAX_NOTE ?>" placeholder="Caption for the client (optional), e.g. Primer applied, Ready for pickup"/>
        <?php endif; ?>
        <div class="rv-upload-actions">
          <button type="button" onclick="document.getElementById('upload-panel').classList.remove('open')" class="btn-ghost" style="font-size:0.8rem;">Cancel</button>
          <button type="submit" class="btn-primary" style="font-size:0.8rem;"><?= icon('arrow-up-tray', 13) ?> Upload</button>
        </div>
      </form>
    </div>

    <!-- GALLERY -->
    <div class="rv-body">
      <?php if (empty($images)): ?>
      <div class="rv-empty"><?= icon('camera', 24) ?>No photos yet. Add photos of the vehicle for reference.</div>
      <?php else: ?>
      <div class="rv-gallery">
        <?php foreach ($images as $img):
          $img_url  = htmlspecialchars(upload_url('repair_jobs', $img['file_name'], $job_id));
          $can_edit = repair_photo_editable((int)$img['uploaded_by'], $job['status']);
          $shown    = (int)$img['show_client'] === 1;
        ?>
        <div class="rp-photo<?= $shown ? ' is-shown' : '' ?>" data-image-id="<?= (int)$img['image_id'] ?>">
          <img src="<?= $img_url ?>" alt="Vehicle photo" loading="lazy" onclick="openLightbox('<?= $img_url ?>')"/>
          <span class="rp-badge"><?= icon($shown ? 'eye' : 'lock-closed', 10) ?> <?= $shown ? 'Shown to client' : 'Private' ?></span>
          <?php if ($can_edit): ?>
          <div class="rp-tools">
            <form method="POST">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle_photo"/>
              <input type="hidden" name="image_id" value="<?= (int)$img['image_id'] ?>"/>
              <input type="hidden" name="show" value="<?= $shown ? '0' : '1' ?>"/>
              <button type="submit" class="rp-tool rp-toggle" title="<?= $shown ? 'Hide from the client' : 'Show to the client' ?>" aria-label="<?= $shown ? 'Hide from the client' : 'Show to the client' ?>">
                <?= icon($shown ? 'lock-closed' : 'eye', 12) ?>
              </button>
            </form>
            <form method="POST">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_image"/>
              <input type="hidden" name="image_id" value="<?= (int)$img['image_id'] ?>"/>
              <button type="button" class="rp-tool danger" onclick="confirmDeleteImg(this)" aria-label="Delete photo">
                <?= icon('trash', 12) ?>
              </button>
            </form>
          </div>
          <?php endif; ?>
          <div class="rp-cap">
            <?php if ($img['caption'] !== null && $img['caption'] !== ''): ?><div><?= htmlspecialchars($img['caption']) ?></div><?php endif; ?>
            <?= date('M d, Y', strtotime($img['uploaded_at'])) ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ADDITIONAL REMARKS -->
  <div class="card rv-card">
    <div class="card-header">
      <div class="card-icon"><?= icon('document', 16) ?></div>
      <div>
        <div class="card-title">Additional Damages / Remarks</div>
        <div class="card-sub">Notes added upon inspection</div>
      </div>
    </div>
    <div class="rv-body">
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_notes"/>
        <textarea name="additional_damages" rows="4" maxlength="<?= MAX_NOTE ?>" class="rv-notes"
          placeholder="Additional damages upon inspection..."><?= htmlspecialchars($job['additional_damages'] ?? '') ?></textarea>
        <div class="rv-notes-actions">
          <button type="submit" class="btn-sm-gold"><?= icon('check', 13) ?> Save Notes</button>
        </div>
      </form>
    </div>
  </div>

</div>
</div>

<!-- LIGHTBOX -->
<div id="img-lightbox" onclick="closeLightbox()">
  <img src="" alt="Vehicle photo"/>
</div>

<!-- STATUS UPDATE MODAL -->
<?php if ($next_statuses): ?>
<div id="status-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
  <div style="background:var(--bg-2);border:1px solid var(--border);border-radius:16px;padding:1.75rem;width:100%;max-width:360px;box-shadow:var(--shadow-lg);">
    <div style="font-size:1rem;font-weight:700;color:var(--text-primary);margin-bottom:0.25rem;">Update Job Status</div>
    <div style="font-size:0.78rem;color:var(--text-muted);margin-bottom:1.25rem;"><?= htmlspecialchars($job['job_number']) ?></div>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update_status"/>
      <div style="display:flex;flex-direction:column;gap:0.5rem;margin-bottom:1.25rem;">
        <?php foreach (statuses_active('repair') as $val => ['label' => $lbl, 'class' => $badge, 'color' => $clr]):
            $is_current = $job['status'] === $val;
            $can_pick   = in_array($val, $next_statuses, true); ?>
        <label class="status-opt<?= $can_pick ? '' : ' is-locked' ?>" style="display:flex;align-items:center;gap:0.65rem;padding:0.65rem 0.85rem;border:1px solid var(--border);border-radius:10px;cursor:<?= $can_pick ? 'pointer' : 'not-allowed' ?>;transition:background 0.1s;<?= $can_pick ? '' : 'opacity:0.45;' ?>"
               <?php if ($can_pick): ?>onmouseover="this.style.background='var(--bg-3)'" onmouseout="this.style.background=''"<?php endif; ?>>
          <input type="radio" name="status" value="<?= $val ?>" class="status-radio" <?= $is_current ? 'checked' : '' ?> <?= $can_pick ? '' : 'disabled' ?> style="--rc:<?= $clr ?>;"/>
          <span class="badge <?= $badge ?>"><?= htmlspecialchars($lbl) ?></span>
          <?php if ($is_current): ?><span style="margin-left:auto;font-size:0.68rem;color:var(--text-muted);">Current</span><?php endif; ?>
        </label>
        <?php endforeach; ?>
      </div>
      <?php if (is_mechanic()): ?>
      <div style="font-size:0.72rem;color:var(--text-muted);margin:-0.5rem 0 1rem;">A job can only move forward. Ask an admin if a status was set by mistake.</div>
      <?php endif; ?>
      <div style="display:flex;gap:0.5rem;justify-content:flex-end;">
        <button type="button" onclick="document.getElementById('status-modal').style.display='none'" class="btn-ghost">Cancel</button>
        <button type="submit" class="btn-primary"><?= icon('check', 13) ?> Save</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>
<!-- SIGNATURE MODAL -->
<div id="sig-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:1000;align-items:center;justify-content:center;padding:1rem;">
  <div style="background:var(--bg-2);border:1px solid var(--border);border-radius:16px;padding:1.5rem;width:100%;max-width:580px;box-shadow:var(--shadow-lg);">
    <div style="font-size:1rem;font-weight:700;color:var(--text-primary);" id="sig-title">Client Signature</div>
    <div style="font-size:0.76rem;color:var(--text-muted);margin:0.25rem 0 1rem;" id="sig-attest"></div>
    <div id="sig-name-wrap" style="margin-bottom:0.85rem;">
      <label for="sig-name" style="display:block;font-size:0.72rem;font-weight:600;color:var(--text-muted);margin-bottom:0.3rem;">Name of person signing</label>
      <input type="text" id="sig-name" class="field-input" maxlength="150" autocomplete="off"/>
    </div>
    <!-- White pad in both themes: the stored ink is dark, and a signature should look like one on paper -->
    <div style="position:relative;background:#fff;border:1.5px dashed var(--gold-muted);border-radius:12px;height:210px;">
      <canvas id="sig-canvas" style="width:100%;height:100%;display:block;border-radius:12px;cursor:crosshair;"></canvas>
      <div style="position:absolute;left:8%;right:8%;bottom:46px;border-bottom:1px solid #D6CFC2;pointer-events:none;"></div>
      <div id="sig-hint" style="position:absolute;left:0;right:0;bottom:22px;text-align:center;font-size:0.7rem;color:#9C9286;pointer-events:none;">Sign above the line</div>
    </div>
    <div id="sig-error" style="display:none;margin-top:0.6rem;font-size:0.75rem;color:#C0392B;"></div>
    <div style="display:flex;gap:0.5rem;justify-content:space-between;margin-top:1rem;flex-wrap:wrap;">
      <button type="button" class="btn-ghost" onclick="sigPad && sigPad.clear(); document.getElementById('sig-hint').style.display='';"><?= icon('arrow-path', 13) ?> Clear</button>
      <div style="display:flex;gap:0.5rem;">
        <button type="button" class="btn-ghost" onclick="closeSignModal()">Cancel</button>
        <button type="button" class="btn-primary" id="sig-save" onclick="saveSignature()"><?= icon('check', 13) ?> Save Signature</button>
      </div>
    </div>
  </div>
</div>

<script>
// no modal when this user has no status left to pick (a mechanic on a completed / cancelled job)
var statusModal = document.getElementById('status-modal');
if (statusModal) statusModal.addEventListener('click', function(e) {
  if (e.target === this) this.style.display = 'none';
});
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    if (statusModal) statusModal.style.display = 'none';
    closeLightbox();
    closeSignModal();
  }
});

// ── Inspection sign-off ──
let sigPad = null, sigSide = null;
const SIG_TEXT = {
  client: ['Client Signature', 'By signing, I confirm the condition of my vehicle as recorded in the Unit Inspection Checklist for <?= htmlspecialchars($job['job_number'], ENT_QUOTES) ?>.'],
  staff:  ['Shop Representative Signature', 'By signing, I confirm I inspected this vehicle and recorded its condition in the checklist for <?= htmlspecialchars($job['job_number'], ENT_QUOTES) ?>.'],
};
function openSignModal(side, defaultName) {
  sigSide = side;
  document.getElementById('sig-title').textContent = SIG_TEXT[side][0];
  document.getElementById('sig-attest').textContent = SIG_TEXT[side][1];
  document.getElementById('sig-name-wrap').style.display = side === 'client' ? '' : 'none';
  document.getElementById('sig-name').value = defaultName || '';
  document.getElementById('sig-error').style.display = 'none';
  document.getElementById('sig-hint').style.display = '';
  document.getElementById('sig-modal').style.display = 'flex';
  // The canvas is measured once it is visible, so it is sized to the modal on every open
  const canvas = document.getElementById('sig-canvas');
  if (!sigPad) {
    sigPad = new SignaturePad(canvas);
    canvas.addEventListener('pointerdown', () => { document.getElementById('sig-hint').style.display = 'none'; });
  } else {
    sigPad.resize();
  }
  sigPad.clear();
}
function closeSignModal() {
  const m = document.getElementById('sig-modal');
  if (m) m.style.display = 'none';
}
document.getElementById('sig-modal').addEventListener('click', function(e) {
  if (e.target === this) closeSignModal();
});
function sigError(msg) {
  const el = document.getElementById('sig-error');
  el.textContent = msg;
  el.style.display = '';
}
async function saveSignature() {
  if (!sigPad || sigPad.isEmpty()) return sigError('Please sign inside the box first.');
  const name = document.getElementById('sig-name').value.trim();
  if (sigSide === 'client' && !name) return sigError('Enter the name of the person signing.');
  const btn = document.getElementById('sig-save');
  btn.disabled = true;
  const fd = new FormData();
  fd.append('csrf_token', window._csrf || '');
  fd.append('action', 'sign_inspection');
  fd.append('signer_role', sigSide);
  fd.append('signer_name', name);
  fd.append('signature', sigPad.toDataURL());
  try {
    const res = await fetch(location.pathname + location.search, { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.ok) { btn.disabled = false; return sigError(data.message || 'The signature could not be saved.'); }
    closeSignModal();
    await Swal.fire({ icon: 'success', title: 'Signature saved', timer: 1300, showConfirmButton: false });
    location.reload();
  } catch (e) {
    btn.disabled = false;
    sigError('Could not reach the server. Check your connection and try again.');
  }
}

// ── Inspection: pointing at a part on the car lights up its entry in the list, and the other way round ──
document.querySelectorAll('#inspection-card [data-key]').forEach(function (item) {
  var key = item.dataset.key;
  var parts = document.querySelectorAll('#inspection-card [data-panel="' + key + '"]');
  var set = function (on) { item.classList.toggle('hot', on); parts.forEach(function (p) { p.classList.toggle('hot', on); }); };
  item.addEventListener('mouseenter', function () { set(true); });
  item.addEventListener('mouseleave', function () { set(false); });
  parts.forEach(function (p) {
    p.addEventListener('mouseenter', function () { set(true); });
    p.addEventListener('mouseleave', function () { set(false); });
  });
});

// ── Lightbox ──
function openLightbox(src) {
  let lb = document.getElementById('img-lightbox');
  lb.querySelector('img').src = src;
  lb.style.display = 'flex';
}
function closeLightbox() {
  const lb = document.getElementById('img-lightbox');
  if (lb) lb.style.display = 'none';
}

// ── Delete image confirm ──
function confirmDeleteImg(btn) {
  const form = btn.closest('form');
  Swal.fire({
    title: 'Remove photo?',
    text: 'This photo will be permanently deleted.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: 'var(--danger)',
    confirmButtonText: 'Yes, remove',
  }).then(async r => { if (r.isConfirmed) { const ok = await requirePin(); if (ok) form.submit(); } });
}
</script>

<?php
$footer_extra_scripts = '<script src="../../assets/js/shared/signature_pad.js?v=' . filemtime(__DIR__ . '/../../assets/js/shared/signature_pad.js') . '"></script>';
require_once '../../includes/footer.php';
?>
