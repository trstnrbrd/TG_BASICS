<?php
require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . '/../../includes/data_access.php';
require_once '../../config/db.php';
require_once '../../config/validators.php';
require_once '../../config/access.php';
require_once '../../config/transaction_pin.php';
require_once '../../config/uploads.php';
require_once '../../includes/lookups.php';
require_once '../../includes/icons.php';
require_once '../../config/mailer.php';
require_once '../../includes/transaction.php';
require_once '../../includes/api.php';

require_role(['admin', 'super_admin']);

$claim_id = (int)($_GET['id'] ?? 0);
if (!$claim_id) { header("Location: claims_list.php"); exit; }

// Load claim
$claim = claim_find($conn, $claim_id);
if (!$claim) { header("Location: claims_list.php"); exit; }

// Policy expiry + document progress. Computed here, before any POST handler runs, because the
// status-update guards below read them (they used to be defined further down, after the handlers
// had already returned, so those guards always saw an undefined value and never blocked anything).
$policy_expired = policy_is_expired($claim['policy_end']);
$required_docs  = 7; // policy, OR, CR, license, affidavit, estimate, photos
$docs_done      = (int)$claim['doc_insurance_policy'] + (int)$claim['doc_or'] + (int)$claim['doc_cr']
                + (int)$claim['doc_drivers_license'] + (int)$claim['doc_affidavit']
                + (int)$claim['doc_estimate'] + (int)$claim['doc_damage_photos'];
$all_docs_complete = $docs_done === $required_docs;

// Display number — sequential position, not raw ID
$display_num = claim_display_number($conn, $claim_id);

// Load damage photos
$damage_photos = claim_damage_photos($conn, $claim_id);

// The steps this claim may take next: the flow in config/access.php, minus a status switched off in the statuses table
$next_statuses = array_values(array_intersect(claim_next_statuses($claim['status']), array_keys(statuses_active('claim'))));

/**
 * Moves the claim to $new_status, only from the status this page loaded (the same form sent twice, or two people at
 * once, change it and email the client once). The change and its audit entry are saved together; the client's
 * email goes after the commit. False = someone else changed it first. Used by Update Status and by Send to Admin.
 */
function claim_move_status(mysqli $conn, array $claim, int $claim_id, string $new_status, ?string $denial_reason, string $notes): bool
{
    $from_status = $claim['status'];
    $changed = db_transaction($conn, function () use ($conn, $claim_id, $from_status, $new_status, $denial_reason, $notes) {
        if (!claim_set_status($conn, $claim_id, $from_status, $new_status, $denial_reason, $notes)) return false;
        $desc = ($_SESSION['full_name'] ?? 'Unknown') . ' updated claim #' . $claim_id . ' status to ' . $new_status . '.';
        audit_log_add($conn, $_SESSION['user_id'], 'CLAIM_UPDATED', $desc);
        return true;
    });
    if ($changed && !empty($claim['email'])) {
        sendClaimStatusEmail(
            $claim['email'],
            $claim['full_name'],
            claim_type_badge($conn, $claim['claim_type'])['label'],
            $new_status,
            $claim['policy_number'],
            $claim['plate_number'] ?: 'N/A',
            $denial_reason
        );
    }
    return $changed;
}

// Helper: re-fetch doc counts
function fetchDocCounts($conn, $claim_id) {
    $chk = claim_doc_flags($conn, $claim_id);
    $req  = 7; // policy, OR, CR, license, affidavit, estimate, photos
    $done = (int)$chk['doc_insurance_policy'] + (int)$chk['doc_or'] + (int)$chk['doc_cr']
          + (int)$chk['doc_drivers_license'] + (int)$chk['doc_affidavit']
          + (int)$chk['doc_estimate'] + (int)$chk['doc_damage_photos'];
    return ['req' => $req, 'done' => $done, 'all_done' => $done === $req];
}

// Every AJAX branch below changes data (or sends email), so they all need the CSRF token.
// Answered as JSON because the callers parse the reply as JSON.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['ajax_upload', 'ajax_remove_doc', 'ajax_damage_upload', 'ajax_damage_remove', 'ajax_send_admin_email'] as $ajax_key) {
        if (isset($_POST[$ajax_key])) { csrf_verify_json(); break; }
    }
}

// Handle AJAX file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_upload'])) {
    $doc_field = san_str($_POST['doc_field'] ?? '', 40);
    $allowed   = ['doc_insurance_policy', 'doc_or', 'doc_cr', 'doc_drivers_license', 'doc_affidavit', 'doc_estimate', 'doc_damage_photos'];
    $policy_expired_ajax = policy_is_expired($claim['policy_end']);
    if (!in_array($doc_field, $allowed, true) || !claim_docs_open($claim['status']) || $policy_expired_ajax) {
        api_error('Not allowed.', 403);
    }

    if (!isset($_FILES['doc_file']) || $_FILES['doc_file']['error'] !== UPLOAD_ERR_OK) {
        api_error('Upload failed.');
    }

    $file     = $_FILES['doc_file'];
    $allowed_types = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];
    $finfo    = finfo_open(FILEINFO_MIME_TYPE);
    $mime     = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, $allowed_types)) api_error('Only images and PDFs allowed.');
    if ($file['size'] > 10 * 1024 * 1024) api_error('File too large (max 10MB).', 413);

    $ext      = $mime === 'application/pdf' ? 'pdf' : explode('/', $mime)[1];
    $filename = 'claim_' . $claim_id . '_' . $doc_field . '_' . time() . '.' . $ext;
    $dest     = __DIR__ . '/../../uploads/claims/' . $filename;

    // Delete old file if exists — $doc_field is whitelist-validated above (and again inside the DAL)
    $old = claim_doc_file($conn, $claim_id, $doc_field);
    if ($old && file_exists(__DIR__ . '/../../uploads/claims/' . $old)) {
        unlink(__DIR__ . '/../../uploads/claims/' . $old);
    }

    if (!move_uploaded_file($file['tmp_name'], $dest)) api_error('Could not save file.', 500);

    claim_doc_set($conn, $claim_id, $doc_field, $filename);

    $counts = fetchDocCounts($conn, $claim_id);
    $url    = upload_url('claims', $filename);
    $is_pdf = $mime === 'application/pdf';
    api_success(['url' => $url, 'filename' => $filename, 'is_pdf' => $is_pdf] + $counts);
}

// Handle AJAX file remove
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_remove_doc'])) {
    $doc_field = san_str($_POST['doc_field'] ?? '', 40);
    $allowed   = ['doc_insurance_policy', 'doc_or', 'doc_cr', 'doc_drivers_license', 'doc_affidavit', 'doc_estimate', 'doc_damage_photos'];
    if (!in_array($doc_field, $allowed, true) || !claim_docs_open($claim['status'])) {
        api_error('Not allowed.', 403);
    }

    $old = claim_doc_file($conn, $claim_id, $doc_field);
    if ($old && file_exists(__DIR__ . '/../../uploads/claims/' . $old)) {
        unlink(__DIR__ . '/../../uploads/claims/' . $old);
    }

    claim_doc_set($conn, $claim_id, $doc_field, null);

    $counts = fetchDocCounts($conn, $claim_id);
    api_success($counts);
}

// Handle AJAX damage photo upload (multi)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_damage_upload'])) {
    $policy_expired_ajax = policy_is_expired($claim['policy_end']);
    if (!claim_docs_open($claim['status']) || $policy_expired_ajax) api_error('Not allowed.', 403);
    if (!isset($_FILES['damage_file']) || $_FILES['damage_file']['error'] !== UPLOAD_ERR_OK) api_error('Upload failed.');

    $file  = $_FILES['damage_file'];
    $allowed_types = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, $allowed_types)) api_error('Only images allowed for damage photos.');
    if ($file['size'] > 10 * 1024 * 1024) api_error('File too large (max 10MB).', 413);

    $ext      = explode('/', $mime)[1];
    $filename = 'dmg_' . $claim_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest     = __DIR__ . '/../../uploads/claims/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) api_error('Could not save file.', 500);

    // The photo row and the "photos present" flag change together; if either fails, the saved file is removed
    try {
        $photo_id = db_transaction($conn, fn() => claim_damage_photo_add($conn, $claim_id, $filename));
    } catch (Throwable $e) {
        unlink($dest);
        throw $e;
    }

    $counts = fetchDocCounts($conn, $claim_id);
    $url    = upload_url('claims', $filename);
    api_success(['photo_id' => $photo_id, 'url' => $url, 'filename' => $filename] + $counts);
}

// Handle AJAX damage photo remove
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_damage_remove'])) {
    $policy_expired_ajax = policy_is_expired($claim['policy_end']);
    if (!claim_docs_open($claim['status']) || $policy_expired_ajax) api_error('Not allowed.', 403);

    $photo_id = (int)($_POST['photo_id'] ?? 0);
    // The photo row, the remaining count and the "photos present" flag change together
    // (none left unticks "photos present")
    [$removed_file, $remaining] = db_transaction($conn, fn() => claim_damage_photo_remove($conn, $claim_id, $photo_id));
    // The file goes only after the database change is committed
    if ($removed_file !== null) {
        $path = __DIR__ . '/../../uploads/claims/' . $removed_file;
        if (file_exists($path)) unlink($path);
    }

    $counts = fetchDocCounts($conn, $claim_id);
    api_success(['remaining' => $remaining] + $counts);
}

// Handle AJAX: Send requirements email to admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_send_admin_email'])) {
    // Linked recipients use the user's CURRENT email (follows their account); raw entries use the stored email.
    $notify_emails = claim_notify_emails($conn);
    if (empty($notify_emails)) api_error('No admin email configured. Please set it in Settings.');

    // Reload fresh claim data
    $fresh = claim_row($conn, $claim_id);
    // the claim notes typed in Next Step (sent with Compiling -> Sent to Admin) go into the email and the change
    $send_notes = isset($_POST['notes']) && !text_too_long($_POST['notes'], MAX_NOTE) ? san_str($_POST['notes'], MAX_NOTE) : (string)($fresh['notes'] ?? '');

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
    $photo_idx = 1;
    foreach (claim_damage_photos($conn, $claim_id) as $ph) {
        $ph_path = $upload_dir . $ph['filename'];
        if (file_exists($ph_path)) {
            $attachments[] = ['path' => $ph_path, 'name' => 'Photo_' . $photo_idx . '.' . pathinfo($ph['filename'], PATHINFO_EXTENSION)];
            $photo_idx++;
        }
    }

    $ok = sendClaimRequirementsEmail(
        $notify_emails,
        $claim['full_name'],
        $claim['policy_number'],
        $claim['plate_number'],
        claim_type_badge($conn, $fresh['claim_type'])['label'],
        date('F d, Y', strtotime($fresh['incident_date'])),
        $docStatus,
        $send_notes !== '' ? $send_notes : null,
        $attachments
    );

    if (!$ok) api_error('Failed to send email. Check SMTP settings.', 500);

    // Sending the requirements is the "Sent to Admin" step (owner's choice, 2026-11-20): a claim still compiling moves
    // there too, under the same rules as Update Status. Re-sending later (or from Lack of Requirements) only emails.
    $moved = false;
    if ($claim['status'] === 'compiling'
        && claim_status_block_reason('sent_admin', $next_statuses, $policy_expired, fetchDocCounts($conn, $claim_id)['done']) === null) {
        $moved = claim_move_status($conn, $claim, $claim_id, 'sent_admin', null, $send_notes);
    }
    api_success(['moved' => $moved], $moved ? 'Email sent. The claim is now Sent to Admin.' : 'Email sent successfully.');
}

// Shared delete routine — cleans up all files then removes DB row
function deleteClaim($conn, $claim_id, $display_num, $user_id, $actor_name) {
    $upload_dir = __DIR__ . '/../../uploads/claims/';

    // Every file of the claim (document files, older file columns, damage photos). They are removed only after
    // the database delete is committed (below), so a failed delete keeps them
    $files = [];
    foreach (claim_files($conn, $claim_id) as $f) {
        if (file_exists($upload_dir . $f)) $files[] = $upload_dir . $f;
    }

    // Delete DB row (claim_damage_photos deleted via CASCADE) and its audit entry, together
    db_transaction($conn, function () use ($conn, $claim_id, $user_id, $actor_name) {
        claim_delete($conn, $claim_id);

        $desc = $actor_name . ' deleted claim #' . $claim_id . '.';
        audit_log_add($conn, $user_id, 'CLAIM_DELETED', $desc);
    });

    foreach ($files as $path) unlink($path);
}

// Handle delete (POST, from this page or the claims list) — only in the statuses the Delete
// button is offered for, so an in-progress claim can't be removed by a hand-made request.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_claim'])) {
    csrf_verify();
    if (!pin_consume($conn)) {
        header("Location: view_claim.php?id=$claim_id&error=" . urlencode(PIN_REQUIRED_MSG));
        exit;
    }
    if (!claim_is_deletable($claim['status'])) {
        header("Location: view_claim.php?id=$claim_id&error=" . urlencode('Only resolved, denied, or lack-of-requirements claims can be deleted.'));
        exit;
    }
    deleteClaim($conn, $claim_id, $display_num, $_SESSION['user_id'], $_SESSION['full_name'] ?? 'Unknown');
    header("Location: claims_list.php?success=" . urlencode('Claim #' . $display_num . ' has been deleted.'));
    exit;
}

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    csrf_verify();
    $allowed_statuses = array_keys(statuses_active('claim'));   // statuses table
    $new_status    = san_enum($_POST['new_status'] ?? '', $allowed_statuses);
    $denial_reason = san_str($_POST['denial_reason'] ?? '', MAX_TEXT);
    $notes         = san_str($_POST['notes'] ?? '', MAX_NOTE);
    if (text_too_long($_POST['notes'] ?? '', MAX_NOTE)) {
        header("Location: view_claim.php?id=$claim_id&error=" . urlencode('Notes are too long (max ' . MAX_NOTE . ' characters).'));
        exit;
    }

    // Already at that status (the same form sent twice, or someone else did it first): nothing to change, no second
    // log entry or email. The user asked for this status and the claim has it, so this is not an error.
    if ($new_status !== '' && $new_status === $claim['status']) {
        header("Location: view_claim.php?id=$claim_id&success=" . urlencode('Claim status updated.'));
        exit;
    }

    // One rule for every guard (config/access.php): a valid next step of the flow (a status switched off in the
    // statuses table is skipped), the policy not expired except for deny / lack of requirements / resolve, and at
    // least one requirement uploaded except for deny / lack of requirements
    $block = claim_status_block_reason($new_status, $next_statuses, $policy_expired, $docs_done);
    if ($block !== null) {
        header("Location: view_claim.php?id=$claim_id&error=" . urlencode($block));
        exit;
    }

    if (!claim_move_status($conn, $claim, $claim_id, $new_status, $new_status === 'denied' ? $denial_reason : null, $notes)) {
        header("Location: view_claim.php?id=$claim_id&error=" . urlencode('The status was changed by someone else. Please check it and try again.'));
        exit;
    }
    header("Location: view_claim.php?id=$claim_id&success=" . urlencode('Claim status updated.'));
    exit;
}


$s = claim_status_badge($claim['status']);

$page_title  = 'View Claim';
$active_page = 'claims';
$base_path   = '../../';
require_once '../../includes/header.php';
require_once '../../includes/navbar.php';
?>

<link rel="stylesheet" href="../../assets/css/shared/view_claim.css?v=<?= filemtime(__DIR__ . '/../../assets/css/shared/view_claim.css') ?>"/>

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
        Swal.fire({ toast:true, position:'top-end', icon:'success', titleText:<?= json_encode($_GET['success']) ?>, showConfirmButton:false, timer:3000, timerProgressBar:true });
      });
    </script>
    <?php endif; ?>
    <?php if (isset($_GET['error'])): ?>
    <script>
      document.addEventListener('DOMContentLoaded', function() {
        Swal.fire({ icon:'error', title:'Cannot Submit', text:<?= json_encode($_GET['error']) ?> });
      });
    </script>
    <?php endif; ?>

    <?php
    // ── what the page shows, worked out once ──
    $status     = $claim['status'];
    $ctb        = claim_type_badge($conn, $claim['claim_type']);
    $docs_open  = claim_docs_open($status);
    $billing_id = claim_is_billable($status) ? billing_id_for_claim($conn, $claim_id) : null;

    // Status steps: the claim's main path from the flow (config/access.php), skipping a status switched off in the
    // statuses table. Lack of Requirements / Denied are side branches: shown after the steps, the steps before the
    // earliest one that can lead there count as done.
    $active_codes = array_keys(statuses_active('claim'));
    $path   = array_values(array_filter(claim_main_path(), fn($c) => in_array($c, $active_codes, true) || $c === $status));
    $cur_i  = array_search($status, $path, true);
    $branch = $cur_i === false ? $status : null;
    $done_before = $cur_i === false ? 0 : $cur_i;
    if ($branch !== null) {
        foreach ($path as $i => $c) {
            if (in_array($branch, CLAIM_STATUS_FLOW[$c] ?? [], true)) { $done_before = $i; break; }
        }
    }

    // One line on what this stage means, in the words the shop uses
    $stage_hint = [
        'compiling'            => 'Upload the requirements below, then send them to the admin.',
        'sent_admin'           => 'The requirements were sent to the admin. They can still be added or replaced until the claim goes to the head office.',
        'lack_of_requirements' => 'Some requirements are missing. Reopen the claim to keep compiling.',
        'sent_head_office'     => 'Forwarded to the head office. The requirements are locked from here on.',
        'waiting_loa'          => 'Waiting for the Letter of Authority (LOA).',
        'loa_received'         => 'The LOA is in. The billing can be created now.',
        'pending'              => 'Being processed by the insurer.',
        'approved'             => 'Approved. Mark it Resolved once it is settled.',
        'resolved'             => 'This claim is finished.',
        'denied'               => 'This claim was denied.',
    ][$status] ?? '';
    // What each possible next step does, shown under its name
    $step_hint = [
        'sent_admin'           => $status === 'compiling' ? 'Emails the requirements to the admin and moves the claim on.' : 'Back to the admin.',
        'lack_of_requirements' => 'Stop here: requirements are missing.',
        'compiling'            => 'Reopen to keep collecting requirements.',
        'sent_head_office'     => 'Forward to the head office. Requirements lock after this.',
        'waiting_loa'          => 'The head office is preparing the LOA.',
        'loa_received'         => 'The LOA arrived. Billing opens after this.',
        'pending'              => 'Now being processed by the insurer.',
        'approved'             => 'The insurer approved the claim.',
        'resolved'             => 'Finished. No more changes after this.',
        'denied'               => 'The insurer denied the claim.',
    ];
    $dead_end = ['denied', 'lack_of_requirements'];
    ?>

    <!-- HEADER: who and what; the status itself is shown once, in the steps below -->
    <div class="claim-head">
      <div class="claim-head-main">
        <a href="claims_list.php" class="back-link" onclick="goBack('claims_list.php'); return false;" aria-label="Back to Claims"><?= icon('arrow-left',14) ?></a>
        <div style="min-width:0;">
          <div class="claim-head-title">
            <h2><?= htmlspecialchars($claim['full_name']) ?></h2>
            <span class="badge <?= $ctb['class'] ?>"><?= htmlspecialchars($ctb['label']) ?></span>
          </div>
          <div class="claim-head-sub">
            Claim #<?= (int)$display_num ?> · <?= htmlspecialchars($claim['policy_number']) ?> · Filed <?= date('M d, Y', strtotime($claim['created_at'])) ?> by <?= htmlspecialchars($claim['filed_by'] ?? 'Unknown') ?>
          </div>
        </div>
      </div>
      <?php if (claim_is_deletable($status)): ?>
      <form method="POST" class="claim-head-actions">
        <?= csrf_field() ?>
        <input type="hidden" name="delete_claim" value="1"/>
        <button type="button" class="btn-sm-danger js-delete-claim" data-id="<?= $claim_id ?>"><?= icon('trash',13) ?> Delete Claim</button>
      </form>
      <?php endif; ?>
    </div>

    <?php if ($policy_expired): ?>
    <div class="claim-alert danger">
      <?= icon('exclamation-triangle',18) ?>
      <div>
        <strong>Policy expired</strong>
        Policy <?= htmlspecialchars($claim['policy_number']) ?> expired on <?= date('M d, Y', strtotime($claim['policy_end'])) ?>.
        Uploads and most status changes are locked until it is renewed; only Deny, Lack of Requirements and Resolve stay open.
      </div>
    </div>
    <?php endif; ?>

    <!-- STATUS STEPS -->
    <ol class="claim-steps" aria-label="Claim progress">
      <?php foreach ($path as $i => $code):
        $state = $branch !== null ? ($i < $done_before ? 'done' : 'todo')
               : ($i < $cur_i ? 'done' : ($i === $cur_i ? ($code === 'resolved' ? 'done current' : 'current') : 'todo'));
      ?>
      <li class="claim-step <?= $state ?>"<?= str_contains($state, 'current') ? ' aria-current="step"' : '' ?>>
        <span class="claim-step-dot"><?= str_contains($state, 'done') ? icon('check', 11) : $i + 1 ?></span>
        <span class="claim-step-label"><?= htmlspecialchars(claim_status_badge($code)['label']) ?></span>
      </li>
      <?php endforeach; ?>
      <?php if ($branch !== null): ?>
      <li class="claim-step branch <?= $branch === 'denied' ? 'is-danger' : 'is-warning' ?>" aria-current="step">
        <span class="claim-step-dot"><?= icon($branch === 'denied' ? 'x-mark' : 'exclamation-triangle', 11) ?></span>
        <span class="claim-step-label"><?= htmlspecialchars(claim_status_badge($branch)['label']) ?></span>
      </li>
      <?php endif; ?>
    </ol>

    <!-- NEXT STEP: where the claim is and what can be done now, in one place -->
    <section class="card claim-next" id="next-step">
      <div class="claim-next-now">
        <div class="claim-next-kicker">Current status</div>
        <div class="claim-next-status"><span class="badge <?= $s['class'] ?>"><?= htmlspecialchars($s['label']) ?></span></div>
        <?php if ($stage_hint): ?><p class="claim-next-hint"><?= htmlspecialchars($stage_hint) ?></p><?php endif; ?>
        <?php if ($status === 'denied' && $claim['denial_reason']): ?>
        <p class="claim-next-reason"><?= icon('x-circle', 13) ?> <?= htmlspecialchars($claim['denial_reason']) ?></p>
        <?php endif; ?>
        <?php if ($billing_id !== null): ?>
        <a href="../billing/view_billing.php?id=<?= (int)$billing_id ?>" class="btn-sm-gold claim-next-billing"><?= icon('document-text',13) ?> View Billing</a>
        <?php elseif (claim_is_billable($status)): ?>
        <a href="../billing/add_billing.php?claim_id=<?= $claim_id ?>" class="btn-sm-gold claim-next-billing"><?= icon('document-text',13) ?> Create Billing</a>
        <?php endif; ?>
      </div>

      <div class="claim-next-act">
        <?php if (!$next_statuses): ?>
        <div class="claim-next-done"><?= icon('lock-closed', 15) ?> No further steps. This claim is closed.</div>
        <?php else: ?>
        <form method="POST" id="status-form" data-sends-email="<?= $status === 'compiling' ? '1' : '0' ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="update_status" value="1"/>
          <div class="claim-next-kicker" id="next-step-label">Move the claim to</div>
          <div class="next-options" role="radiogroup" aria-labelledby="next-step-label">
            <?php foreach ($next_statuses as $code):
              $reason     = claim_status_block_reason($code, $next_statuses, $policy_expired, $docs_done);
              $needs_docs = $reason !== null && claim_status_block_reason($code, $next_statuses, $policy_expired, 1) === null;
            ?>
            <label class="next-option<?= in_array($code, $dead_end, true) ? ' is-danger' : '' ?><?= $reason !== null ? ' is-blocked' : '' ?>">
              <input type="radio" name="new_status" value="<?= $code ?>"<?= $reason !== null ? ' disabled' : '' ?><?= $needs_docs ? ' data-needs-docs="1"' : '' ?>/>
              <span class="next-option-body">
                <span class="next-option-name">
                  <?= $code === 'sent_admin' && $status === 'compiling' ? icon('envelope', 13) . ' ' : '' ?><?= htmlspecialchars(claim_status_badge($code)['label']) ?>
                </span>
                <span class="next-option-hint" data-hint="<?= htmlspecialchars($step_hint[$code] ?? '') ?>"><?= htmlspecialchars($reason ?? ($step_hint[$code] ?? '')) ?></span>
              </span>
            </label>
            <?php endforeach; ?>
          </div>

          <div class="field" id="denial-reason-wrap" hidden>
            <label class="field-label" for="denial_reason">Denial Reason</label>
            <select name="denial_reason" id="denial_reason" class="field-input">
              <option value="">— Select Reason —</option>
              <option value="Under the influence (DUI)">Under the influence (DUI)</option>
              <option value="Commercial use — vehicle registered as private">Commercial use (Grab/Uber) — registered as private</option>
              <option value="Incomplete or late premium payment">Incomplete or late premium payment</option>
              <option value="Other">Other</option>
            </select>
          </div>
          <div class="field">
            <label class="field-label" for="claim-notes">Claim notes <span class="field-optional">(optional, saved with the change)</span></label>
            <textarea name="notes" id="claim-notes" class="field-input" rows="2" maxlength="<?= MAX_NOTE ?>" placeholder="Add a note..."><?= htmlspecialchars($claim['notes'] ?? '') ?></textarea>
          </div>
          <button type="submit" id="btn-update-status" class="btn-primary" disabled><?= icon('check-circle',14) ?> <span>Choose a step first</span></button>
        </form>
        <?php endif; ?>
      </div>
    </section>

    <div class="claim-grid">

      <!-- REQUIREMENTS -->
      <section class="card claim-docs">
        <div class="card-header">
          <div class="card-icon"><?= icon('document',16) ?></div>
          <div>
            <div class="card-title">Requirements</div>
            <div class="card-sub" id="doc-count-sub"><?= $docs_done ?>/<?= $required_docs ?> documents received</div>
          </div>
          <div style="margin-left:auto;">
            <?php if (!$docs_open): ?>
            <span class="badge badge-muted"><?= icon('lock-closed',11) ?> Locked</span>
            <?php else: ?>
            <span id="doc-badge" class="badge <?= $all_docs_complete ? 'badge-green' : 'badge-yellow' ?>">
              <?= $all_docs_complete ? icon('check-circle',12) . ' Complete' : ($required_docs - $docs_done) . ' remaining' ?>
            </span>
            <?php endif; ?>
          </div>
        </div>

        <?php if (!$docs_open): ?>
        <div class="claim-alert muted"><?= icon('lock-closed',14) ?> <div>The claim has gone past the admin, so these files can no longer be changed.</div></div>
        <?php elseif ($policy_expired): ?>
        <div class="claim-alert danger"><?= icon('lock-closed',14) ?> <div>Uploads are locked while the policy is expired.</div></div>
        <?php endif; ?>

        <div class="doc-list">
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
          // the upload buttons follow the same rule as the server's upload handler: open status and policy not expired
          $docs_locked = !$docs_open || $policy_expired;
          foreach ($docs as $d):
            $checked   = (bool)$claim[$d[0]];
            $file_name = $claim[$d[0] . '_file'] ?? '';
            $file_url  = $file_name ? htmlspecialchars(upload_url('claims', $file_name)) : '';
            $is_pdf    = $file_name && str_ends_with(strtolower($file_name), '.pdf');
          ?>
          <div class="doc-item <?= $checked ? 'received' : '' ?> <?= $docs_locked ? 'locked' : '' ?>" id="doc-item-<?= $d[0] ?>">
            <div class="doc-checkbox" id="doc-cb-<?= $d[0] ?>"><?php if ($checked): ?><?= icon('check',12) ?><?php endif; ?></div>
            <div class="doc-main">
              <div class="doc-label" id="doc-label-<?= $d[0] ?>"><?= $d[1] ?></div>
              <div class="doc-sub"><?= $d[2] ?></div>
              <?php if ($docs_locked && !$checked): ?><div class="doc-missing">Not submitted</div><?php endif; ?>

              <div class="doc-preview" id="doc-preview-<?= $d[0] ?>"<?= $file_url ? '' : ' style="display:none;"' ?>>
                <?php if ($file_url): ?>
                  <?php if ($is_pdf): ?>
                  <a href="<?= $file_url ?>" target="_blank" class="doc-file-link"><?= icon('document',13) ?> View PDF</a>
                  <?php else: ?>
                  <a href="<?= $file_url ?>" target="_blank"><img src="<?= $file_url ?>" alt="<?= $d[1] ?>" class="doc-thumb" loading="lazy"/></a>
                  <?php endif; ?>
                  <?php if (!$docs_locked): ?>
                  <button type="button" class="doc-remove-btn" data-field="<?= $d[0] ?>" title="Remove"><?= icon('x-mark',11) ?> Remove</button>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </div>
            <?php if (!$docs_locked): ?>
            <div class="doc-actions">
              <label class="doc-upload-btn" title="Take Photo">
                <?= icon('camera', 14) ?> <span class="doc-btn-label">Camera</span>
                <input type="file" accept="image/*" capture="environment" data-field="<?= $d[0] ?>" class="doc-file-input" hidden/>
              </label>
              <label class="doc-upload-btn" title="Upload from Gallery / Files">
                <?= icon('arrow-up-tray', 14) ?> <span class="doc-btn-label">Gallery</span>
                <input type="file" accept="image/*,application/pdf" data-field="<?= $d[0] ?>" class="doc-file-input" hidden/>
              </label>
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>

          <!-- DAMAGE PHOTOS — multi-upload -->
          <?php $dp_checked = (bool)$claim['doc_damage_photos']; ?>
          <div class="doc-item <?= $dp_checked ? 'received' : '' ?> <?= $docs_locked ? 'locked' : '' ?>" id="doc-item-doc_damage_photos">
            <div class="doc-checkbox" id="doc-cb-doc_damage_photos"><?php if ($dp_checked): ?><?= icon('check',12) ?><?php endif; ?></div>
            <div class="doc-main">
              <div class="doc-label" id="doc-label-doc_damage_photos">Damage Photos</div>
              <div class="doc-sub">Clear photos showing the vehicle damage — upload as many as needed</div>
              <?php if ($docs_locked && !$dp_checked): ?><div class="doc-missing">Not submitted</div><?php endif; ?>
              <div id="damage-photo-grid" class="dmg-grid">
                <?php foreach ($damage_photos as $dp_i => $dp): $dp_url = htmlspecialchars(upload_url('claims', $dp['filename'])); ?>
                <div class="dmg-photo-wrap" id="dmg-wrap-<?= $dp['photo_id'] ?>">
                  <a href="<?= $dp_url ?>" target="_blank"><img src="<?= $dp_url ?>" alt="Damage evidence photo <?= $dp_i + 1 ?>" loading="lazy" class="dmg-thumb"/></a>
                  <?php if (!$docs_locked): ?>
                  <button type="button" class="dmg-remove-btn" data-id="<?= $dp['photo_id'] ?>" aria-label="Remove photo"><?= icon('x-mark',10) ?></button>
                  <?php endif; ?>
                </div>
                <?php endforeach; ?>
              </div>
              <?php if (!$docs_locked || $damage_photos): ?>
              <div id="damage-photo-count" class="dmg-count"><?= count($damage_photos) ?> photo<?= count($damage_photos) !== 1 ? 's' : '' ?> uploaded</div>
              <?php endif; ?>
            </div>
            <?php if (!$docs_locked): ?>
            <div class="doc-actions">
              <label class="doc-upload-btn" title="Take Photo">
                <?= icon('camera', 14) ?> <span class="doc-btn-label">Camera</span>
                <input type="file" accept="image/*" capture="environment" class="dmg-file-input" hidden/>
              </label>
              <label class="doc-upload-btn" title="Upload from Gallery">
                <?= icon('arrow-up-tray', 14) ?> <span class="doc-btn-label">Gallery</span>
                <input type="file" accept="image/*" multiple class="dmg-file-input" hidden/>
              </label>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($docs_open && !$policy_expired && $status !== 'compiling'): ?>
        <!-- Re-send (the first send is the "Sent to Admin" step above); only emails, the status stays -->
        <div class="doc-resend">
          <button type="button" id="btn-send-admin-email" class="btn-ghost"<?= $docs_done === 0 ? ' disabled' : '' ?>><?= icon('envelope',14) ?> Email the requirements to the admin again</button>
          <div id="send-btn-hint" class="doc-resend-hint"><?= $docs_done === 0 ? 'Upload at least one requirement before sending.' : 'Only sends the email. The status stays the same.' ?></div>
        </div>
        <?php endif; ?>
      </section>

      <!-- CLAIM DETAILS: incident, notes, client, vehicle, policy in one card -->
      <aside class="card claim-details">
        <div class="card-header">
          <div class="card-icon"><?= icon('clipboard-list',16) ?></div>
          <div><div class="card-title">Claim Details</div></div>
        </div>
        <div class="card-body">
          <div class="detail-group">
            <div class="detail-group-title">Incident</div>
            <dl class="detail-list">
              <div><dt>Date</dt><dd><?= date('F d, Y', strtotime($claim['incident_date'])) ?></dd></div>
              <div><dt>Type</dt><dd><?= htmlspecialchars($ctb['label']) ?></dd></div>
            </dl>
            <?php if (trim((string)$claim['description']) !== ''): ?>
            <p class="detail-text"><?= nl2br(htmlspecialchars($claim['description'])) ?></p>
            <?php endif; ?>
          </div>

          <?php if ($claim['denial_reason'] && $status !== 'denied'): ?>
          <div class="detail-note danger"><div class="detail-group-title">Denial Reason</div><?= htmlspecialchars($claim['denial_reason']) ?></div>
          <?php endif; ?>
          <?php if ($claim['notes']): ?>
          <div class="detail-note"><div class="detail-group-title">Notes</div><?= nl2br(htmlspecialchars($claim['notes'])) ?></div>
          <?php endif; ?>

          <?php foreach ([
            'Client'  => [['Name', $claim['full_name']], ['Contact', $claim['contact_number'] ?: '—']],
            'Vehicle' => [['Plate', $claim['plate_number'] ?: '—'],
                          ['Vehicle', trim(($claim['make'] ?? '') . ' ' . ($claim['model'] ?? '') . ' ' . ($claim['year_model'] ?? '')) ?: '—'],
                          ['Color', $claim['color'] ?: '—']],
            'Policy'  => [['Policy No.', $claim['policy_number']], ['Coverage', $claim['coverage_type']],
                          ['Expires', date('M d, Y', strtotime($claim['policy_end']))], ['Payment', $claim['payment_status']]],
          ] as $group => $rows): ?>
          <div class="detail-group">
            <div class="detail-group-title"><?= $group ?></div>
            <dl class="detail-list">
              <?php foreach ($rows as [$k, $v]): ?>
              <div><dt><?= $k ?></dt><dd<?= $k === 'Expires' && $policy_expired ? ' class="is-danger"' : '' ?>><?= htmlspecialchars((string)$v) ?></dd></div>
              <?php endforeach; ?>
            </dl>
          </div>
          <?php endforeach; ?>
        </div>
      </aside>

    </div>

  </div>
</div>

<script>
// PHP-injected constants for view_claim.js
const REQ_DOCS   = <?= $required_docs ?>;
const DOCS_DONE  = <?= $docs_done ?>;
const CLAIM_URL  = 'view_claim.php?id=<?= $claim_id ?>';
const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
const checkIcon  = `<?= icon('check', 12) ?>`;
const docIcon    = `<?= icon('document', 13) ?>`;
const xIcon      = `<?= icon('x-mark', 10) ?>`;
</script>
<script src="../../assets/js/shared/view_claim.js?v=<?= filemtime(__DIR__.'/../../assets/js/shared/view_claim.js') ?>"></script>

<?php require_once '../../includes/footer.php'; ?>


