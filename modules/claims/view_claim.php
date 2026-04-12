<?php
require_once __DIR__ . "/../../config/session.php";
require_once '../../config/db.php';
require_once '../../config/validators.php';
require_once '../../includes/icons.php';
require_once '../../config/mailer.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header("Location: ../../auth/login.php");
    exit;
}

$claim_id = (int)($_GET['id'] ?? 0);
if (!$claim_id) { header("Location: claims_list.php"); exit; }

// Load claim
$stmt = $conn->prepare("
    SELECT cl.*,
           c.full_name, c.contact_number, c.email, c.address, c.client_id,
           ip.policy_number, ip.coverage_type, ip.policy_start, ip.policy_end,
           ip.total_premium, ip.participation_fee, ip.payment_status,
           v.plate_number, v.make, v.model, v.year_model, v.color,
           v.motor_number, v.serial_number,
           u.full_name as filed_by
    FROM claims cl
    INNER JOIN clients c         ON cl.client_id  = c.client_id
    INNER JOIN insurance_policies ip ON cl.policy_id  = ip.policy_id
    LEFT  JOIN vehicles v        ON ip.vehicle_id = v.vehicle_id
    LEFT  JOIN users u           ON cl.created_by = u.user_id
    WHERE cl.claim_id = ?
");
$stmt->bind_param('i', $claim_id);
$stmt->execute();
$claim = $stmt->get_result()->fetch_assoc();
if (!$claim) { header("Location: claims_list.php"); exit; }

// Display number — sequential position, not raw ID
$display_num = $conn->query("SELECT COUNT(*) as pos FROM claims WHERE claim_id <= $claim_id")->fetch_assoc()['pos'];

// Load damage photos
$dp_res = $conn->query("SELECT photo_id, filename FROM claim_damage_photos WHERE claim_id = $claim_id ORDER BY uploaded_at ASC");
$damage_photos = $dp_res->fetch_all(MYSQLI_ASSOC);

// Helper: re-fetch doc counts
function fetchDocCounts($conn, $claim_id) {
    $chk = $conn->query("SELECT claim_type, doc_insurance_policy, doc_or, doc_cr, doc_drivers_license, doc_affidavit, doc_estimate, doc_damage_photos FROM claims WHERE claim_id = $claim_id")->fetch_assoc();
    $req  = 7; // policy, OR, CR, license, affidavit, estimate, photos
    $done = (int)$chk['doc_insurance_policy'] + (int)$chk['doc_or'] + (int)$chk['doc_cr']
          + (int)$chk['doc_drivers_license'] + (int)$chk['doc_affidavit']
          + (int)$chk['doc_estimate'] + (int)$chk['doc_damage_photos'];
    return ['req' => $req, 'done' => $done, 'all_done' => $done === $req];
}

// Handle AJAX file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_upload'])) {
    header('Content-Type: application/json');
    $doc_field = san_str($_POST['doc_field'] ?? '', 40);
    $allowed   = ['doc_insurance_policy', 'doc_or', 'doc_cr', 'doc_drivers_license', 'doc_affidavit', 'doc_estimate', 'doc_damage_photos'];
    $policy_expired_ajax = strtotime($claim['policy_end']) < strtotime(date('Y-m-d'));
    $docs_open_statuses = ['document_collection', 'submitted'];
    if (!in_array($doc_field, $allowed, true) || !in_array($claim['status'], $docs_open_statuses) || $policy_expired_ajax) {
        echo json_encode(['ok' => false, 'msg' => 'Not allowed.']); exit;
    }

    if (!isset($_FILES['doc_file']) || $_FILES['doc_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'msg' => 'Upload failed.']); exit;
    }

    $file     = $_FILES['doc_file'];
    $allowed_types = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];
    $finfo    = finfo_open(FILEINFO_MIME_TYPE);
    $mime     = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, $allowed_types)) {
        echo json_encode(['ok' => false, 'msg' => 'Only images and PDFs allowed.']); exit;
    }
    if ($file['size'] > 10 * 1024 * 1024) {
        echo json_encode(['ok' => false, 'msg' => 'File too large (max 10MB).']); exit;
    }

    $ext      = $mime === 'application/pdf' ? 'pdf' : explode('/', $mime)[1];
    $filename = 'claim_' . $claim_id . '_' . $doc_field . '_' . time() . '.' . $ext;
    $dest     = __DIR__ . '/../../uploads/claims/' . $filename;

    // Delete old file if exists
    $old = $conn->query("SELECT {$doc_field}_file FROM claims WHERE claim_id = $claim_id")->fetch_assoc()["{$doc_field}_file"] ?? '';
    if ($old && file_exists(__DIR__ . '/../../uploads/claims/' . $old)) {
        unlink(__DIR__ . '/../../uploads/claims/' . $old);
    }

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        echo json_encode(['ok' => false, 'msg' => 'Could not save file.']); exit;
    }

    $file_col = $doc_field . '_file';
    $upd = $conn->prepare("UPDATE claims SET $doc_field = 1, $file_col = ? WHERE claim_id = ?");
    $upd->bind_param('si', $filename, $claim_id);
    $upd->execute();

    $counts = fetchDocCounts($conn, $claim_id);
    $url    = '../../uploads/claims/' . $filename;
    $is_pdf = $mime === 'application/pdf';
    echo json_encode(['ok' => true, 'url' => $url, 'filename' => $filename, 'is_pdf' => $is_pdf] + $counts);
    exit;
}

// Handle AJAX file remove
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_remove_doc'])) {
    header('Content-Type: application/json');
    $doc_field = $_POST['doc_field'] ?? '';
    $allowed   = ['doc_insurance_policy', 'doc_or', 'doc_cr', 'doc_drivers_license', 'doc_affidavit', 'doc_estimate', 'doc_damage_photos'];
    if (!in_array($doc_field, $allowed) || !in_array($claim['status'], ['document_collection', 'submitted'])) {
        echo json_encode(['ok' => false]); exit;
    }

    $file_col = $doc_field . '_file';
    $old = $conn->query("SELECT {$file_col} FROM claims WHERE claim_id = $claim_id")->fetch_assoc()[$file_col] ?? '';
    if ($old && file_exists(__DIR__ . '/../../uploads/claims/' . $old)) {
        unlink(__DIR__ . '/../../uploads/claims/' . $old);
    }

    $upd = $conn->prepare("UPDATE claims SET $doc_field = 0, $file_col = NULL WHERE claim_id = ?");
    $upd->bind_param('i', $claim_id);
    $upd->execute();

    $counts = fetchDocCounts($conn, $claim_id);
    echo json_encode(['ok' => true] + $counts);
    exit;
}

// Handle AJAX damage photo upload (multi)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_damage_upload'])) {
    header('Content-Type: application/json');
    $policy_expired_ajax = strtotime($claim['policy_end']) < strtotime(date('Y-m-d'));
    if (!in_array($claim['status'], ['document_collection', 'submitted']) || $policy_expired_ajax) { echo json_encode(['ok' => false, 'msg' => 'Not allowed.']); exit; }
    if (!isset($_FILES['damage_file']) || $_FILES['damage_file']['error'] !== UPLOAD_ERR_OK) { echo json_encode(['ok' => false, 'msg' => 'Upload failed.']); exit; }

    $file  = $_FILES['damage_file'];
    $allowed_types = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, $allowed_types)) { echo json_encode(['ok' => false, 'msg' => 'Only images allowed for damage photos.']); exit; }
    if ($file['size'] > 10 * 1024 * 1024) { echo json_encode(['ok' => false, 'msg' => 'File too large (max 10MB).']); exit; }

    $ext      = explode('/', $mime)[1];
    $filename = 'dmg_' . $claim_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest     = __DIR__ . '/../../uploads/claims/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) { echo json_encode(['ok' => false, 'msg' => 'Could not save file.']); exit; }

    $ins = $conn->prepare("INSERT INTO claim_damage_photos (claim_id, filename) VALUES (?, ?)");
    $ins->bind_param('is', $claim_id, $filename);
    $ins->execute();
    $photo_id = $conn->insert_id;

    // Mark doc_damage_photos = 1 if not already
    $conn->query("UPDATE claims SET doc_damage_photos = 1 WHERE claim_id = $claim_id AND doc_damage_photos = 0");

    $counts = fetchDocCounts($conn, $claim_id);
    $url    = '../../uploads/claims/' . $filename;
    echo json_encode(['ok' => true, 'photo_id' => $photo_id, 'url' => $url, 'filename' => $filename] + $counts);
    exit;
}

// Handle AJAX damage photo remove
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_damage_remove'])) {
    header('Content-Type: application/json');
    $policy_expired_ajax = strtotime($claim['policy_end']) < strtotime(date('Y-m-d'));
    if (!in_array($claim['status'], ['document_collection', 'submitted']) || $policy_expired_ajax) { echo json_encode(['ok' => false]); exit; }

    $photo_id = (int)($_POST['photo_id'] ?? 0);
    $row = $conn->query("SELECT filename FROM claim_damage_photos WHERE photo_id = $photo_id AND claim_id = $claim_id")->fetch_assoc();
    if ($row) {
        $path = __DIR__ . '/../../uploads/claims/' . $row['filename'];
        if (file_exists($path)) unlink($path);
        $conn->query("DELETE FROM claim_damage_photos WHERE photo_id = $photo_id");
    }

    // If no photos remain, uncheck doc_damage_photos
    $remaining = $conn->query("SELECT COUNT(*) as c FROM claim_damage_photos WHERE claim_id = $claim_id")->fetch_assoc()['c'];
    if ($remaining === 0) {
        $conn->query("UPDATE claims SET doc_damage_photos = 0 WHERE claim_id = $claim_id");
    }

    $counts = fetchDocCounts($conn, $claim_id);
    echo json_encode(['ok' => true, 'remaining' => $remaining] + $counts);
    exit;
}

// Handle AJAX: Send requirements email to admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_send_admin_email'])) {
    header('Content-Type: application/json');

    $notify_email = getSetting($conn, 'claim_notify_email', '');
    if (!$notify_email) {
        echo json_encode(['ok' => false, 'msg' => 'No admin email configured. Please set it in Settings.']);
        exit;
    }

    // Reload fresh claim data
    $fresh = $conn->query("SELECT * FROM claims WHERE claim_id = $claim_id")->fetch_assoc();

    $upload_dir = __DIR__ . '/../../uploads/claims/';

    // Build doc status + collect file paths for attachment
    $docFields = [
        ['field' => 'doc_insurance_policy', 'label' => 'Policy'],
        ['field' => 'doc_or',               'label' => 'OR — Official Receipt'],
        ['field' => 'doc_cr',               'label' => 'CR — Certificate of Registration'],
        ['field' => 'doc_drivers_license',  'label' => "Driver's License"],
        ['field' => 'doc_affidavit',        'label' => 'Affidavit of Accident'],
        ['field' => 'doc_estimate',         'label' => 'Estimate'],
        ['field' => 'doc_damage_photos',    'label' => 'Proof / Pictures'],
    ];

    $docStatus     = [];
    $attachments   = []; // [ ['path' => ..., 'name' => ...] ]

    foreach ($docFields as $d) {
        $received  = (bool)$fresh[$d['field']];
        $file_col  = $d['field'] . '_file';
        $file_name = $fresh[$file_col] ?? '';
        $file_path = $file_name ? $upload_dir . $file_name : '';

        $docStatus[] = ['label' => $d['label'], 'received' => $received];

        if ($received && $file_path && file_exists($file_path)) {
            $attachments[] = ['path' => $file_path, 'name' => $d['label'] . '.' . pathinfo($file_name, PATHINFO_EXTENSION)];
        }
    }

    // Also attach damage photos (stored in separate table)
    $photos = $conn->query("SELECT filename FROM claim_damage_photos WHERE claim_id = $claim_id ORDER BY uploaded_at ASC");
    $photo_idx = 1;
    while ($ph = $photos->fetch_assoc()) {
        $ph_path = $upload_dir . $ph['filename'];
        if (file_exists($ph_path)) {
            $attachments[] = ['path' => $ph_path, 'name' => 'Photo_' . $photo_idx . '.' . pathinfo($ph['filename'], PATHINFO_EXTENSION)];
            $photo_idx++;
        }
    }

    $ok = sendClaimRequirementsEmail(
        $notify_email,
        $claim['full_name'],
        $claim['policy_number'],
        $claim['plate_number'],
        $fresh['claim_type'],
        date('F d, Y', strtotime($fresh['incident_date'])),
        $docStatus,
        $fresh['notes'] ?? null,
        $attachments
    );

    echo json_encode(['ok' => $ok, 'msg' => $ok ? 'Email sent successfully.' : 'Failed to send email. Check SMTP settings.']);
    exit;
}

// Shared delete routine — cleans up all files then removes DB row
function deleteClaim($conn, $claim_id, $display_num, $user_id, $actor_name) {
    $upload_dir = __DIR__ . '/../../uploads/claims/';

    // Delete all individual doc files (all file columns)
    $doc_cols = [
        'doc_insurance_policy_file', 'doc_or_file', 'doc_cr_file',
        'doc_drivers_license_file', 'doc_affidavit_file', 'doc_estimate_file',
        'doc_damage_photos_file', 'doc_or_cr_file', 'doc_police_report_file',
    ];
    $row = $conn->query("SELECT " . implode(',', $doc_cols) . " FROM claims WHERE claim_id = $claim_id")->fetch_assoc();
    if ($row) {
        foreach ($doc_cols as $col) {
            if (!empty($row[$col]) && file_exists($upload_dir . $row[$col])) {
                unlink($upload_dir . $row[$col]);
            }
        }
    }

    // Delete damage photos from claim_damage_photos table + files
    $photos = $conn->query("SELECT filename FROM claim_damage_photos WHERE claim_id = $claim_id");
    while ($p = $photos->fetch_assoc()) {
        if (file_exists($upload_dir . $p['filename'])) unlink($upload_dir . $p['filename']);
    }

    // Delete DB row (claim_damage_photos deleted via CASCADE)
    $del = $conn->prepare("DELETE FROM claims WHERE claim_id = ?");
    $del->bind_param('i', $claim_id);
    $del->execute();

    $log  = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'CLAIM_DELETED', ?)");
    $desc = $actor_name . ' deleted claim #' . $claim_id . '.';
    $log->bind_param('is', $user_id, $desc);
    $log->execute();
}

// Handle delete (GET trigger from claims_list)
if (isset($_GET['do_delete']) && $_GET['do_delete'] === '1') {
    if (!in_array($claim['status'], ['resolved', 'denied'])) {
        header("Location: claims_list.php");
        exit;
    }
    deleteClaim($conn, $claim_id, $display_num, $_SESSION['user_id'], $_SESSION['full_name'] ?? 'Unknown');
    header("Location: claims_list.php?success=" . urlencode('Claim #' . $display_num . ' has been deleted.'));
    exit;
}

// Handle delete (POST from view page)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_claim'])) {
    deleteClaim($conn, $claim_id, $display_num, $_SESSION['user_id'], $_SESSION['full_name'] ?? 'Unknown');
    header("Location: claims_list.php?success=" . urlencode('Claim #' . $display_num . ' has been deleted.'));
    exit;
}

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $allowed_statuses = ['document_collection', 'submitted', 'under_review', 'approved', 'denied', 'resolved'];
    $new_status    = san_enum($_POST['new_status'] ?? '', $allowed_statuses);
    $denial_reason = san_str($_POST['denial_reason'] ?? '', MAX_TEXT);
    $notes         = san_str($_POST['notes'] ?? '', MAX_TEXT);

    // Guard: block all status changes if policy is expired (except deny/resolve by admin)
    if ($policy_expired && !in_array($new_status, ['denied', 'resolved'])) {
        header("Location: view_claim.php?id=$claim_id&error=" . urlencode('Policy is expired. Please renew the policy before processing this claim.'));
        exit;
    }

    // Guard: block forward status changes if no documents uploaded yet (deny always allowed)
    if ($new_status !== 'denied' && $docs_done === 0) {
        header("Location: view_claim.php?id=$claim_id&error=" . urlencode('Upload at least one requirement before updating the status.'));
        exit;
    }

    // Guard: new status must be a valid next step for this claim
    $next_statuses = match($claim['status']) {
        'document_collection' => ['submitted'],
        'submitted'           => $claim['claim_type'] === 'repair' ? ['under_review', 'denied'] : ['approved', 'denied'],
        'under_review'        => ['approved', 'denied'],
        'approved'            => ['resolved'],
        default               => [],
    };
    if (!in_array($new_status, $next_statuses)) {
        header("Location: view_claim.php?id=$claim_id&error=" . urlencode('Invalid status transition.'));
        exit;
    }

    if (in_array($new_status, $allowed_statuses)) {
        $upd = $conn->prepare("UPDATE claims SET status = ?, denial_reason = ?, notes = ? WHERE claim_id = ?");
        $dr  = $new_status === 'denied' ? $denial_reason : null;
        $upd->bind_param('sssi', $new_status, $dr, $notes, $claim_id);
        $upd->execute();

        $log  = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'CLAIM_UPDATED', ?)");
        $desc = ($_SESSION['full_name'] ?? 'Unknown') . ' updated claim #' . $claim_id . ' status to ' . $new_status . '.';
        $log->bind_param('is', $_SESSION['user_id'], $desc);
        $log->execute();

        // Send email notification to client if they have an email on file
        if (!empty($claim['email'])) {
            sendClaimStatusEmail(
                $claim['email'],
                $claim['full_name'],
                $claim['claim_type'],
                $new_status,
                $claim['policy_number'],
                $claim['plate_number'] ?: 'N/A',
                $new_status === 'denied' ? $denial_reason : null
            );
        }

        header("Location: view_claim.php?id=$claim_id&success=" . urlencode('Claim status updated.'));
        exit;
    }
}

// Policy expiry check — lock claim if policy is expired
$policy_expired = strtotime($claim['policy_end']) < strtotime(date('Y-m-d'));

$status_map = [
    'document_collection' => ['label' => 'Document Collection', 'class' => 'badge-warning'],
    'submitted'           => ['label' => 'Forwarded to Head Office', 'class' => 'badge-info'],
    'under_review'        => ['label' => 'Under Adjuster Review', 'class' => 'badge-orange'],
    'approved'            => ['label' => 'Approved', 'class' => 'badge-success'],
    'denied'              => ['label' => 'Denied', 'class' => 'badge-danger'],
    'resolved'            => ['label' => 'Resolved', 'class' => 'badge-muted'],
];

$required_docs = 7; // policy, OR, CR, license, affidavit, estimate, photos
$docs_done = (int)$claim['doc_insurance_policy'] + (int)$claim['doc_or'] + (int)$claim['doc_cr']
           + (int)$claim['doc_drivers_license'] + (int)$claim['doc_affidavit']
           + (int)$claim['doc_estimate'] + (int)$claim['doc_damage_photos'];
$all_docs_complete = $docs_done === $required_docs;

$s = $status_map[$claim['status']] ?? ['label' => $claim['status'], 'class' => 'badge-muted'];

$page_title  = 'View Claim';
$active_page = 'claims';
$base_path   = '../../';
require_once '../../includes/header.php';
require_once '../../includes/navbar.php';
?>

<link rel="stylesheet" href="../../assets/css/shared/view_claim.css"/>

<div class="main">

<?php
$topbar_title      = 'View Claim';
$topbar_breadcrumb = ['Insurance', 'Claims', 'View Claim'];
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
    <?php if (isset($_GET['error'])): ?>
    <script>
      document.addEventListener('DOMContentLoaded', function() {
        Swal.fire({ icon:'error', title:'Cannot Submit', text:<?= json_encode($_GET['error']) ?>, confirmButtonColor:'#B8860B', customClass:{confirmButton:'swal-btn'} });
      });
    </script>
    <?php endif; ?>

    <!-- PAGE HEADER -->
    <div class="claim-page-header" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:0.75rem;">
      <div>
        <div style="display:flex;align-items:center;gap:0.75rem;">
          <a href="claims_list.php" class="btn-ghost" style="padding:0.4rem 0.75rem;"><?= icon('arrow-left',14) ?> Back</a>
          <h2 style="font-size:1.1rem;font-weight:800;color:var(--text-primary);">Claim #<?= $display_num ?></h2>
          <span class="badge <?= $s['class'] ?>"><?= $s['label'] ?></span>
          <?php if ($claim['claim_type'] === 'repair'): ?>
          <span class="badge badge-danger">Repair</span>
          <?php else: ?>
          <span class="badge badge-info">Claims</span>
          <?php endif; ?>
        </div>
        <div style="font-size:0.72rem;color:var(--text-muted);margin-top:0.35rem;padding-left:0.2rem;">
          Filed <?= date('M d, Y', strtotime($claim['created_at'])) ?> by <?= htmlspecialchars($claim['filed_by'] ?? 'Unknown') ?>
        </div>
      </div>
      <?php if (in_array($claim['status'], ['resolved', 'denied'])): ?>
      <form method="POST" style="display:inline;">
        <input type="hidden" name="delete_claim" value="1"/>
        <button type="button" class="btn-primary js-delete-claim"
          style="background:var(--danger);color:#fff;border-color:var(--danger);display:flex;align-items:center;gap:0.5rem;"
          data-id="<?= $claim_id ?>">
          <?= icon('trash',14) ?> Delete Claim
        </button>
      </form>
      <?php endif; ?>
    </div>

    <!-- POLICY EXPIRED BANNER -->
    <?php if ($policy_expired): ?>
    <div style="background:rgba(192,57,43,0.08);border:1.5px solid rgba(192,57,43,0.35);border-radius:10px;padding:0.85rem 1.1rem;margin-bottom:1rem;display:flex;align-items:center;gap:0.75rem;">
      <span style="color:var(--danger);flex-shrink:0;"><?= icon('exclamation-triangle',18) ?></span>
      <div>
        <div style="font-weight:700;color:var(--danger);font-size:0.88rem;">Policy Expired</div>
        <div style="font-size:0.78rem;color:var(--text-secondary);margin-top:0.1rem;">
          This claim's policy (<strong><?= htmlspecialchars($claim['policy_number']) ?></strong>) expired on
          <strong><?= date('M d, Y', strtotime($claim['policy_end'])) ?></strong>.
          Document uploads and status changes (except Deny / Resolve) are locked until the policy is renewed.
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- STATUS FLOW -->
    <?php
    $flow_statuses = $claim['claim_type'] === 'repair'
        ? ['document_collection','submitted','under_review','approved','resolved']
        : ['document_collection','submitted','approved','resolved'];
    $current = $claim['status'];
    $current_idx = array_search($current, $flow_statuses);
    $is_denied = $current === 'denied';
    ?>
    <div class="status-flow">
      <?php foreach ($flow_statuses as $i => $step):
        $step_label = $status_map[$step]['label'] ?? $step;
        if ($is_denied && $step === 'approved') continue;
        $class = '';
        if ($is_denied && $step === 'under_review') $class = 'denied-step';
        elseif ($current_idx !== false && $i < $current_idx) $class = 'done';
        elseif ($step === $current) $class = 'active';
      ?>
        <?php if ($i > 0 && !($is_denied && $step === 'approved')): ?><span class="status-arrow">&rsaquo;</span><?php endif; ?>
        <div class="status-step <?= $class ?>">
          <?php if ($class === 'done'): ?><?= icon('check-circle',11) ?><?php endif; ?>
          <?= $step_label ?>
        </div>
      <?php endforeach; ?>
      <?php if ($is_denied): ?>
        <span class="status-arrow">&rsaquo;</span>
        <div class="status-step denied-step"><?= icon('x-circle',11) ?> Denied</div>
      <?php endif; ?>
    </div>

    <!-- TOP ROW: Client / Vehicle / Policy -->
    <div class="claim-top-grid" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem;margin-bottom:1rem;">

      <!-- CLIENT -->
      <div class="card" style="margin-bottom:0;">
        <div class="card-header">
          <div class="card-icon"><?= icon('user',16) ?></div>
          <div><div class="card-title">Client</div></div>
        </div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:0.45rem;">
          <?php foreach ([
            ['Name',    $claim['full_name']],
            ['Contact', $claim['contact_number']],
          ] as $row): ?>
          <div style="display:flex;justify-content:space-between;gap:0.75rem;padding:0.35rem 0;border-bottom:1px solid var(--border);font-size:0.8rem;">
            <span style="color:var(--text-muted);font-weight:600;flex-shrink:0;"><?= $row[0] ?></span>
            <span style="color:var(--text-primary);text-align:right;"><?= htmlspecialchars($row[1]) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- VEHICLE -->
      <div class="card" style="margin-bottom:0;">
        <div class="card-header">
          <div class="card-icon"><?= icon('truck',16) ?></div>
          <div><div class="card-title">Vehicle</div></div>
        </div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:0.45rem;">
          <?php foreach ([
            ['Plate',   $claim['plate_number'] ?: '—'],
            ['Vehicle', trim(($claim['make'] ?? '') . ' ' . ($claim['model'] ?? '') . ' ' . ($claim['year_model'] ?? '')) ?: '—'],
            ['Color',   $claim['color'] ?: '—'],
          ] as $row): ?>
          <div style="display:flex;justify-content:space-between;gap:0.75rem;padding:0.35rem 0;border-bottom:1px solid var(--border);font-size:0.8rem;">
            <span style="color:var(--text-muted);font-weight:600;flex-shrink:0;"><?= $row[0] ?></span>
            <span style="color:var(--text-primary);text-align:right;"><?= htmlspecialchars($row[1]) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- POLICY -->
      <div class="card" style="margin-bottom:0;">
        <div class="card-header">
          <div class="card-icon"><?= icon('shield-check',16) ?></div>
          <div><div class="card-title">Policy</div></div>
        </div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:0.45rem;">
          <?php foreach ([
            ['Policy No.',  $claim['policy_number']],
            ['Coverage',    $claim['coverage_type']],
            ['Expires',     date('M d, Y', strtotime($claim['policy_end']))],
            ['Payment',     $claim['payment_status']],
          ] as $row): ?>
          <div style="display:flex;justify-content:space-between;gap:0.75rem;padding:0.35rem 0;border-bottom:1px solid var(--border);font-size:0.8rem;">
            <span style="color:var(--text-muted);font-weight:600;flex-shrink:0;"><?= $row[0] ?></span>
            <span style="color:var(--text-primary);text-align:right;"><?= htmlspecialchars($row[1]) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

    </div>

    <!-- BOTTOM ROW: Documents left, Incident + Update Status right -->
    <div class="claim-bottom-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;align-items:start;">

      <!-- LEFT: DOCUMENT CHECKLIST -->
      <div class="card" style="margin-bottom:0;">
        <div class="card-header">
          <div class="card-icon"><?= icon('document',16) ?></div>
          <div>
            <div class="card-title">Insurance Claim Requirements</div>
            <div class="card-sub" id="doc-count-sub"><?= $docs_done ?>/<?= $required_docs ?> documents received</div>
          </div>
          <div style="margin-left:auto;">
            <span id="doc-badge" class="badge <?= $all_docs_complete ? 'badge-success' : 'badge-warning' ?>">
              <?= $all_docs_complete ? icon('check-circle',12) . ' Complete' : ($required_docs - $docs_done) . ' remaining' ?>
            </span>
          </div>
        </div>
        <div style="padding:1rem;display:flex;flex-direction:column;gap:0.6rem;">
          <?php
          $docs = [
            ['doc_insurance_policy', 'Policy',                   'Copy of the insurance policy document'],
            ['doc_or',               'OR — Official Receipt',    'LTO Official Receipt of the vehicle'],
            ['doc_cr',               'CR — Certificate of Registration', 'LTO Certificate of Registration of the vehicle'],
            ['doc_drivers_license',  "Driver's License",         "Valid driver's license of the insured"],
            ['doc_affidavit',        'Affidavit of Accident',    'Notarized sworn statement describing the accident (date, location, how it occurred)'],
            ['doc_estimate',         'Estimate',                 'Written cost estimate from the repair shop for the damage'],
            // proof/pictures handled separately below as damage photos
          ];
          $docs_locked = !in_array($claim['status'], ['document_collection', 'submitted']);
          foreach ($docs as $d):
            $checked   = (bool)$claim[$d[0]];
            $file_col  = $d[0] . '_file';
            $file_name = $claim[$file_col] ?? '';
            $file_url  = $file_name ? '../../uploads/claims/' . $file_name : '';
            $is_pdf    = $file_name && str_ends_with(strtolower($file_name), '.pdf');
          ?>
          <div class="doc-item <?= $checked ? 'received' : '' ?> <?= $docs_locked ? 'locked' : '' ?>"
               id="doc-item-<?= $d[0] ?>">
            <!-- Left: status icon + label -->
            <div class="doc-checkbox" id="doc-cb-<?= $d[0] ?>">
              <?php if ($checked): ?><?= icon('check',12) ?><?php endif; ?>
            </div>
            <div style="flex:1;min-width:0;">
              <div class="doc-label" id="doc-label-<?= $d[0] ?>"><?= $d[1] ?></div>
              <div class="doc-sub"><?= $d[2] ?></div>

              <?php if ($file_url): ?>
              <!-- File preview -->
              <div class="doc-preview" id="doc-preview-<?= $d[0] ?>" style="margin-top:0.5rem;">
                <?php if ($is_pdf): ?>
                  <a href="<?= $file_url ?>" target="_blank" class="doc-file-link">
                    <?= icon('document',13) ?> View PDF
                  </a>
                <?php else: ?>
                  <a href="<?= $file_url ?>" target="_blank">
                    <img src="<?= $file_url ?>" alt="<?= $d[1] ?>" class="doc-thumb"/>
                  </a>
                <?php endif; ?>
                <?php if (!$docs_locked): ?>
                <button type="button" class="doc-remove-btn" data-field="<?= $d[0] ?>" title="Remove">
                  <?= icon('x-mark',11) ?> Remove
                </button>
                <?php endif; ?>
              </div>
              <?php else: ?>
              <div class="doc-preview" id="doc-preview-<?= $d[0] ?>" style="margin-top:0.5rem;display:none;"></div>
              <?php endif; ?>
            </div>

            <!-- Right: camera + gallery buttons (only when unlocked) -->
            <?php if (!$docs_locked): ?>
            <div style="display:flex;flex-direction:column;gap:0.35rem;flex-shrink:0;margin-left:auto;">
              <label class="doc-upload-btn" title="Take Photo" style="width:auto;padding:0 0.65rem;gap:0.35rem;font-size:0.7rem;font-weight:600;">
                <?= icon('camera', 14) ?> <span class="doc-btn-label">Camera</span>
                <input type="file" accept="image/*" capture="environment" data-field="<?= $d[0] ?>" class="doc-file-input" style="display:none;"/>
              </label>
              <label class="doc-upload-btn" title="Upload from Gallery / Files" style="width:auto;padding:0 0.65rem;gap:0.35rem;font-size:0.7rem;font-weight:600;">
                <?= icon('arrow-up-tray', 14) ?> <span class="doc-btn-label">Gallery</span>
                <input type="file" accept="image/*,application/pdf" data-field="<?= $d[0] ?>" class="doc-file-input" style="display:none;"/>
              </label>
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>

          <!-- DAMAGE PHOTOS — multi-upload -->
          <?php
          $dp_checked = (bool)$claim['doc_damage_photos'];
          ?>
          <div class="doc-item <?= $dp_checked ? 'received' : '' ?> <?= $docs_locked ? 'locked' : '' ?>"
               id="doc-item-doc_damage_photos">
            <div class="doc-checkbox" id="doc-cb-doc_damage_photos">
              <?php if ($dp_checked): ?><?= icon('check',12) ?><?php endif; ?>
            </div>
            <div style="flex:1;min-width:0;">
              <div class="doc-label" id="doc-label-doc_damage_photos">Damage Photos</div>
              <div class="doc-sub">Clear photos showing the vehicle damage — upload as many as needed</div>

              <!-- Photo grid -->
              <div id="damage-photo-grid" style="display:flex;flex-wrap:wrap;gap:0.5rem;margin-top:0.6rem;">
                <?php foreach ($damage_photos as $dp): ?>
                <div class="dmg-photo-wrap" id="dmg-wrap-<?= $dp['photo_id'] ?>" style="position:relative;">
                  <a href="../../uploads/claims/<?= htmlspecialchars($dp['filename']) ?>" target="_blank">
                    <img src="../../uploads/claims/<?= htmlspecialchars($dp['filename']) ?>"
                         style="width:80px;height:80px;object-fit:cover;border-radius:8px;border:1px solid var(--border);display:block;"/>
                  </a>
                  <?php if (!$docs_locked): ?>
                  <button type="button" class="dmg-remove-btn" data-id="<?= $dp['photo_id'] ?>"
                    style="position:absolute;top:-6px;right:-6px;width:20px;height:20px;border-radius:50%;background:var(--danger);color:#fff;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:10px;line-height:1;">
                    <?= icon('x-mark',10) ?>
                  </button>
                  <?php endif; ?>
                </div>
                <?php endforeach; ?>
              </div>

              <div id="damage-photo-count" style="font-size:0.7rem;color:var(--text-muted);margin-top:0.4rem;">
                <?= count($damage_photos) ?> photo<?= count($damage_photos) !== 1 ? 's' : '' ?> uploaded
              </div>
            </div>

            <?php if (!$docs_locked): ?>
            <div style="display:flex;flex-direction:column;gap:0.35rem;flex-shrink:0;margin-left:auto;">
              <label class="doc-upload-btn" title="Take Photo" style="width:auto;padding:0 0.65rem;gap:0.35rem;font-size:0.7rem;font-weight:600;">
                <?= icon('camera', 14) ?> <span class="doc-btn-label">Camera</span>
                <input type="file" accept="image/*" capture="environment" class="dmg-file-input" style="display:none;"/>
              </label>
              <label class="doc-upload-btn" title="Upload from Gallery" style="width:auto;padding:0 0.65rem;gap:0.35rem;font-size:0.7rem;font-weight:600;">
                <?= icon('arrow-up-tray', 14) ?> <span class="doc-btn-label">Gallery</span>
                <input type="file" accept="image/*" multiple class="dmg-file-input" style="display:none;"/>
              </label>
            </div>
            <?php endif; ?>
          </div>

        </div>

        <!-- ACTION BUTTONS — always shown while in document_collection, locked after submitted -->
        <?php if (in_array($claim['status'], ['document_collection', 'submitted']) && !$policy_expired): ?>
        <div style="padding:0 1rem 1rem;display:flex;flex-direction:column;gap:0.5rem;" id="doc-action-btns">
          <button type="button" id="btn-send-admin-email" class="btn-primary" style="width:100%;<?= $docs_done === 0 ? 'opacity:0.45;cursor:not-allowed;' : '' ?>" <?= $docs_done === 0 ? 'disabled' : '' ?>>
            <?= icon('envelope',14) ?> Send Requirements to Admin
          </button>
          <div id="send-btn-hint" style="font-size:0.65rem;color:var(--text-muted);text-align:center;padding:0 0.5rem;"><?= $docs_done === 0 ? 'Upload at least one requirement before sending.' : 'Sends the current requirements checklist to the admin email for review and follow-up.' ?></div>
        </div>
        <?php elseif ($policy_expired && in_array($claim['status'], ['document_collection', 'submitted'])): ?>
        <div style="padding:0 1rem 1rem;">
          <button class="btn-primary" style="width:100%;opacity:0.45;cursor:not-allowed;background:var(--danger);border-color:var(--danger);" disabled>
            <?= icon('lock-closed',14) ?> Policy Expired — Cannot Process
          </button>
        </div>
        <?php elseif ($claim['status'] !== 'document_collection'): ?>
        <div style="padding:0 1rem 1rem;">
          <div style="background:var(--bg-2);border:1px solid var(--border);border-radius:8px;padding:0.65rem 1rem;font-size:0.75rem;color:var(--text-muted);display:flex;align-items:center;gap:0.5rem;">
            <?= icon('lock-closed',13) ?> Documents locked — claim already forwarded.
          </div>
        </div>
        <?php endif; ?>
      </div>

      <!-- RIGHT: INCIDENT DETAILS + UPDATE STATUS -->
      <div style="display:flex;flex-direction:column;gap:1rem;">

        <!-- INCIDENT DETAILS -->
        <div class="card" style="margin-bottom:0;">
          <div class="card-header">
            <div class="card-icon"><?= icon('clipboard-list',16) ?></div>
            <div><div class="card-title">Incident Details</div></div>
          </div>
          <div class="card-body">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem 1.5rem;margin-bottom:1rem;">
              <div>
                <div style="font-size:0.65rem;color:var(--text-muted);font-weight:700;text-transform:uppercase;letter-spacing:0.8px;margin-bottom:0.2rem;">Incident Date</div>
                <div style="font-size:0.88rem;font-weight:600;color:var(--text-primary);"><?= date('F d, Y', strtotime($claim['incident_date'])) ?></div>
              </div>
              <div>
                <div style="font-size:0.65rem;color:var(--text-muted);font-weight:700;text-transform:uppercase;letter-spacing:0.8px;margin-bottom:0.2rem;">Claim Type</div>
                <div style="font-size:0.88rem;font-weight:600;color:var(--text-primary);"><?= $claim['claim_type'] === 'repair' ? 'Repair' : 'Claims' ?></div>
              </div>
            </div>
            <div>
              <div style="font-size:0.65rem;color:var(--text-muted);font-weight:700;text-transform:uppercase;letter-spacing:0.8px;margin-bottom:0.4rem;">Description</div>
              <div style="font-size:0.85rem;color:var(--text-secondary);line-height:1.7;"><?= nl2br(htmlspecialchars($claim['description'])) ?></div>
            </div>
            <?php if ($claim['denial_reason']): ?>
            <div style="margin-top:1rem;padding:0.85rem;background:var(--danger-bg);border:1px solid rgba(192,57,43,0.2);border-radius:8px;">
              <div style="font-size:0.65rem;color:var(--danger);font-weight:700;text-transform:uppercase;letter-spacing:0.8px;margin-bottom:0.25rem;">Denial Reason</div>
              <div style="font-size:0.85rem;color:var(--danger);"><?= htmlspecialchars($claim['denial_reason']) ?></div>
            </div>
            <?php endif; ?>
            <?php if ($claim['notes']): ?>
            <div style="margin-top:0.75rem;padding:0.85rem;background:var(--bg-2);border:1px solid var(--border);border-radius:8px;">
              <div style="font-size:0.65rem;color:var(--text-muted);font-weight:700;text-transform:uppercase;letter-spacing:0.8px;margin-bottom:0.25rem;">Notes</div>
              <div style="font-size:0.82rem;color:var(--text-secondary);line-height:1.6;"><?= nl2br(htmlspecialchars($claim['notes'])) ?></div>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- UPDATE STATUS -->
        <?php if ($claim['status'] !== 'resolved'): ?>
        <div class="card" style="margin-bottom:0;">
          <div class="card-header">
            <div class="card-icon"><?= icon('cog',16) ?></div>
            <div><div class="card-title">Update Status</div></div>
          </div>
          <div class="card-body">
            <?php
            $next_statuses = match($claim['status']) {
                'document_collection' => ['submitted'],
                'submitted'           => $claim['claim_type'] === 'repair' ? ['under_review', 'denied'] : ['approved', 'denied'],
                'under_review'        => ['approved', 'denied'],
                'approved'            => ['resolved'],
                default               => [],
            };
            // When policy expired, only allow deny or resolve
            $allowed_next = $policy_expired
                ? array_values(array_intersect($next_statuses, ['denied', 'resolved']))
                : $next_statuses;
            ?>
            <?php if ($policy_expired && empty($allowed_next)): ?>
            <div style="text-align:center;padding:1.25rem 0.5rem;">
              <div style="color:var(--danger);margin-bottom:0.5rem;"><?= icon('lock-closed',24) ?></div>
              <div style="font-size:0.82rem;font-weight:700;color:var(--danger);">Status Locked</div>
              <div style="font-size:0.75rem;color:var(--text-muted);margin-top:0.3rem;">Policy has expired. Renew the policy to continue processing this claim.</div>
            </div>
            <?php else: ?>
            <?php if ($policy_expired): ?>
            <div style="background:rgba(192,57,43,0.07);border:1px solid rgba(192,57,43,0.25);border-radius:8px;padding:0.55rem 0.75rem;margin-bottom:0.75rem;font-size:0.75rem;color:var(--danger);">
              <?= icon('exclamation-triangle',13) ?> Policy expired — only Deny or Resolve is available.
            </div>
            <?php endif; ?>
            <form method="POST" id="status-form">
              <input type="hidden" name="update_status" value="1"/>
              <div class="field" style="margin-bottom:0.75rem;">
                <label class="field-label">New Status</label>
                <select name="new_status" id="new_status" class="field-input">
                  <?php foreach ($allowed_next as $val):
                    $st = $status_map[$val] ?? ['label' => $val];
                  ?>
                  <option value="<?= $val ?>"><?= $st['label'] ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field" id="denial-reason-wrap" style="display:none;margin-bottom:0.75rem;">
                <label class="field-label">Denial Reason</label>
                <select name="denial_reason" class="field-input">
                  <option value="">— Select Reason —</option>
                  <option value="Under the influence (DUI)">Under the influence (DUI)</option>
                  <option value="Commercial use — vehicle registered as private">Commercial use (Grab/Uber) — registered as private</option>
                  <option value="Incomplete or late premium payment">Incomplete or late premium payment</option>
                  <option value="Other">Other</option>
                </select>
              </div>
              <div class="field" style="margin-bottom:0.75rem;">
                <label class="field-label">Notes <span style="color:var(--text-muted);font-weight:400;">(optional)</span></label>
                <textarea name="notes" class="field-input" rows="2" placeholder="Add a note..."><?= htmlspecialchars($claim['notes'] ?? '') ?></textarea>
              </div>
              <button type="submit" id="btn-update-status" class="btn-primary" style="width:100%;<?= $docs_done === 0 ? 'opacity:0.45;cursor:not-allowed;' : '' ?>" <?= $docs_done === 0 ? 'disabled' : '' ?>>
                <?= icon('check-circle',14) ?> Update Status
              </button>
              <div id="status-btn-hint" style="font-size:0.65rem;color:var(--text-muted);text-align:center;margin-top:0.4rem;<?= $docs_done === 0 ? '' : 'display:none;' ?>">Upload at least one requirement first.</div>
            </form>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>

      </div>
    </div>

  </div>
</div>

<script>
// PHP-injected constants for view_claim.js
const REQ_DOCS   = <?= $required_docs ?>;
const DOCS_DONE  = <?= $docs_done ?>;
const CLAIM_URL  = 'view_claim.php?id=<?= $claim_id ?>';
const checkIcon  = `<?= icon('check', 12) ?>`;
const docIcon    = `<?= icon('document', 13) ?>`;
const xIcon      = `<?= icon('x-mark', 10) ?>`;
</script>
<script src="../../assets/js/shared/view_claim.js"></script>

<?php require_once '../../includes/footer.php'; ?>

<script>
(function() {
  var btn = document.getElementById('btn-send-admin-email');
  if (!btn) return;

  btn.addEventListener('click', function() {
    Swal.fire({
      title: 'Send to Admin?',
      html: 'This will email the current requirements checklist to the admin for review.',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Yes, Send Email',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#D4A017',
    }).then(function(result) {
      if (!result.isConfirmed) return;

      btn.disabled = true;
      btn.innerHTML = '<?= icon("clock", 14) ?> Sending...';

      var fd = new FormData();
      fd.append('ajax_send_admin_email', '1');

      fetch('view_claim.php?id=<?= $claim_id ?>', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
          btn.disabled = false;
          btn.innerHTML = '<?= icon("envelope", 14) ?> Send Requirements to Admin';
          if (data.ok) {
            Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Email sent to admin.', showConfirmButton: false, timer: 3000, timerProgressBar: true });
          } else {
            Swal.fire({ icon: 'error', title: 'Failed to Send', text: data.msg || 'Could not send email. Check SMTP settings.' });
          }
        })
        .catch(function() {
          btn.disabled = false;
          btn.innerHTML = '<?= icon("envelope", 14) ?> Send Requirements to Admin';
          Swal.fire({ icon: 'error', title: 'Error', text: 'An unexpected error occurred.' });
        });
    });
  });
})();
</script>
