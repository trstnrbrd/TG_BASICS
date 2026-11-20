<?php
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/data_access.php';
require_once __DIR__ . "/../../config/session.php";
require_once '../../config/db.php';
require_once __DIR__ . '/../../includes/lookups.php';
require_once '../../config/validators.php';
require_once '../../config/access.php';
require_once '../../config/transaction_pin.php';
require_once '../../config/uploads.php';
require_once '../../includes/idempotency.php';

require_role(['admin', 'super_admin', 'mechanic']);
$is_mechanic = is_mechanic();

$client_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($client_id === 0) {
    header("Location: client_list.php");
    exit;
}

// Every admin can look up any client; mechanics only walk-in clients (config/access.php)
if (!client_in_scope($conn, $client_id)) {
    header("Location: client_list.php");
    exit;
}

// ...but only the Owner, the admin who encoded this client, or its insurance agent may change the
// record — details, vehicles, documents, delete. Everyone else gets this page read-only.
$can_edit = client_editable($conn, $client_id);

// Handle delete — only ever the client this page already passed the checks for above, and only
// for someone allowed to change it (the UI hides the button from everyone else).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_client_id'])) {
    csrf_verify();
    if (!pin_consume($conn)) {
        header("Location: view_client.php?id=" . $client_id . "&error=" . urlencode(PIN_REQUIRED_MSG));
        exit;
    }
    if (!$can_edit || (int)$_POST['delete_client_id'] !== $client_id) {
        http_response_code(403);
        exit('Not allowed.');
    }
    $del_id = $client_id;
    $cdata  = client_find_name($conn, $del_id);
    if ($cdata) {
        // Same rule as the client list: policies, repair jobs and claims block the delete. The purge
        // later removes the client for good, and its foreign keys would cascade through those records.
        $blockers = client_delete_blockers($conn, $del_id);
        if ($blockers !== '') {
            header("Location: view_client.php?id=" . $del_id . "&error=" . urlencode('"' . $cdata['full_name'] . '" cannot be deleted — they still have ' . $blockers . ' on record.'));
            exit;
        }

        // client marked deleted and the audit entry: all saved together or not at all
        db_transaction($conn, function () use ($conn, $del_id, $cdata) {
            client_soft_delete($conn, $del_id);
            $desc = ($_SESSION['full_name'] ?? 'Unknown') . ' deleted client "' . $cdata['full_name'] . '"' . (!empty($cdata['client_code']) ? ' (' . $cdata['client_code'] . ')' : '') . ' (moved to Recently Deleted).';
            audit_log_add($conn, $_SESSION['user_id'], 'CLIENT_DELETED', $desc);
        });
        header("Location: client_list.php?success=" . urlencode('"' . $cdata['full_name'] . '" has been deleted. You can restore them from Recently Deleted.'));
        exit;
    }
}

// ── HANDLE DOC UPLOAD ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['upload_doc', 'delete_doc'], true) && !$can_edit) {
    csrf_verify();
    header("Location: view_client.php?id=$client_id&error=" . urlencode('Only the Owner, the admin who added this client, or its insurance agent can change its documents.'));
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_doc') {
    csrf_verify();
    idem_replay();   // the same upload sent twice (double click) attaches the PDF once
    // Each way this can fail gets its own message: it used to say "Document uploaded." even when nothing was saved
    $doc_fail = function (string $msg) use ($client_id): void {
        header("Location: view_client.php?id=$client_id&error=" . urlencode($msg));
        exit;
    };
    $file = $_FILES['policy_doc'] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE || empty($file['tmp_name'])) {
        $doc_fail('Choose a PDF file to upload.');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $doc_fail(in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'The file is too large to upload.' : 'The upload did not finish. Please try again.');
    }
    $tmp  = $file['tmp_name'];
    $orig = basename($file['name']);
    if (mime_content_type($tmp) !== 'application/pdf') $doc_fail('Only PDF files can be attached.');
    $upload_dir = __DIR__ . '/../../uploads/client_docs/' . $client_id . '/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
    $fname = uniqid('doc_', true) . '.pdf';
    if (!move_uploaded_file($tmp, $upload_dir . $fname)) $doc_fail('The file could not be saved. Please try again.');
    try {
        client_document_add($conn, $client_id, $fname, $orig, (int)$_SESSION['user_id']);
    } catch (Throwable $e) {
        @unlink($upload_dir . $fname);   // no record, so no file left behind
        error_log('[TG-BASICS] client document save failed: ' . $e->getMessage());
        $doc_fail('The document could not be saved. Please try again.');
    }
    idem_redirect("view_client.php?id=$client_id&success=Document uploaded.");
}

// ── HANDLE DOC DELETE ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_doc') {
    csrf_verify();
    if (!pin_consume($conn)) {
        header("Location: view_client.php?id=$client_id&error=" . urlencode(PIN_REQUIRED_MSG));
        exit;
    }
    if ($can_edit) {
        $doc_id = (int)($_POST['doc_id'] ?? 0);
        if ($doc_id) {
            $doc_file = client_document_file($conn, $doc_id, $client_id);   // null = not this client's
            if ($doc_file !== null) {
                $path = __DIR__ . '/../../uploads/client_docs/' . $client_id . '/' . $doc_file;
                if (file_exists($path)) unlink($path);
                client_document_delete($conn, $doc_id);
            }
        }
    }
    header("Location: view_client.php?id=$client_id&success=Document removed.");
    exit;
}

// Load client (exclude soft-deleted), with its insurance agent and who added it — masked if either is the hidden account
$client = client_find($conn, $client_id);

if (!$client) {
    header("Location: client_list.php?error=Client not found.");
    exit;
}

// Load vehicles
$vehicles = client_vehicles($conn, $client_id);

// Collect vehicle IDs for eligibility button
$_client_vehicle_ids = array_map('intval', array_column($vehicles, 'vehicle_id'));

$claims      = client_claims($conn, $client_id);
$repair_jobs = client_repair_jobs($conn, $client_id);
$policies    = client_policies($conn, $client_id);

// ── FETCH DOCUMENTS ──
$documents = client_documents($conn, $client_id);

// ── CHECK IF CLIENT HAS POLICIES ──
$has_policies = client_has_policy($conn, $client_id);

$page_title  = 'View Client';
$active_page = 'clients';
$base_path   = '../../';
require_once '../../includes/header.php';
require_once '../../includes/navbar.php';
?>

<link rel="stylesheet" href="../../assets/css/shared/clients.css?v=<?= filemtime(__DIR__ . '/../../assets/css/shared/clients.css') ?>"/>
<link rel="stylesheet" href="../../assets/css/shared/view_client.css?v=<?= filemtime(__DIR__ . '/../../assets/css/shared/view_client.css') ?>"/>

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
        Swal.fire({ toast:true, position:'top-end', icon:'success', titleText:<?= json_encode($_GET['success']) ?>, showConfirmButton:false, timer:3000, timerProgressBar:true });
      });
    </script>
    <?php endif; ?>
    <?php if (!empty($_GET['error'])): ?>
    <script>
      document.addEventListener('DOMContentLoaded', function() {
        Swal.fire({ icon: 'error', title: 'Error', text: <?= json_encode($_GET['error']) ?> });
      });
    </script>
    <?php endif; ?>

    <?php
    $public_token = $client['public_token'] ?? '';
    if (empty($public_token)) {
        $public_token = client_ensure_public_token($conn, $client_id);
    }
    $public_url = app_url('modules/public/client.php?token=' . urlencode($public_token));
    $initials   = implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', trim($client['full_name'])) ?: [], 0, 2)));
    ?>

    <!-- CLIENT HEADER BANNER -->
    <div class="vc-banner">
      <div class="vc-banner-main">
        <div class="vc-banner-avatar" aria-hidden="true"><?= htmlspecialchars(mb_strtoupper($initials)) ?></div>
        <div class="vc-banner-id">
          <div class="vc-banner-kicker">Client Profile<?php if (!empty($client['client_code'])): ?> · <span class="vc-banner-code"><?= htmlspecialchars($client['client_code']) ?></span><?php endif; ?></div>
          <div class="vc-banner-name"><?= htmlspecialchars($client['full_name']) ?></div>
          <div class="vc-banner-meta">
            <?php if ($client['contact_number']): ?><span><?= icon('phone', 12) ?> <?= htmlspecialchars($client['contact_number']) ?></span><?php endif; ?>
            <?php if ($client['email']): ?><span><?= icon('envelope', 12) ?> <?= htmlspecialchars($client['email']) ?></span><?php endif; ?>
            <?php if (!$is_mechanic): ?><span><?= icon('user', 12) ?> Agent: <?= htmlspecialchars($client['agent_name'] ?? 'Unassigned') ?></span><?php endif; ?>
          </div>
        </div>
      </div>
      <?php if ($can_edit): ?>
      <div class="vc-banner-actions">
        <a href="edit_client.php?id=<?= $client_id ?>" class="vc-banner-btn"><?= icon('pencil', 14) ?> Edit Client</a>
        <form method="POST" action="" class="vc-inline-form">
          <?= csrf_field() ?>
          <input type="hidden" name="delete_client_id" value="<?= $client_id ?>"/>
          <button type="button" class="vc-banner-btn is-danger js-delete-client-profile" data-name="<?= htmlspecialchars($client['full_name'], ENT_QUOTES) ?>">
            <?= icon('trash', 14) ?> Delete
          </button>
        </form>
      </div>
      <?php endif; ?>
    </div>

    <?php
    ['vehicles' => $vc, 'policies' => $pc] = client_counts($conn, $client_id);   // the Vehicles / Policies card subtitles
    ?>

    <!-- CLIENT INFO CARD (with QR embedded on the right) -->
    <div class="card vc-card">
      <div class="card-header">
        <div class="card-icon"><?= icon('user', 16) ?></div>
        <div>
          <div class="card-title">Client Information</div>
          <div class="card-sub">Personal details on record</div>
        </div>
      </div>
      <div class="vc-info">

        <!-- Info fields -->
        <div class="vc-info-grid">
          <?php
          $info_rows = [
            ['Client ID',  ($client['client_code'] ?? '') ?: '—',                 'key'],
            ['Full Name',  $client['full_name'],                                   'user'],
            ['Contact',    $client['contact_number'] ?: 'Not provided',            'phone'],
            ['Email',      $client['email'] ?: 'Not provided',                     'envelope'],
            ['Facebook',   ($client['facebook_name'] ?? '') ?: 'Not provided',     'at-symbol'],
            ['Address',    $client['address'],                                     'map-pin'],
            ['Date Added', date('F d, Y', strtotime($client['created_at'])),       'calendar'],
          ];
          // Whose client this is — insurance staff only. ("Data Privacy Consent" and "Added By" were taken off
          // this card on the owner's request, 2026-09-24; both are still recorded on the client.)
          if (!$is_mechanic) {
              $info_rows[] = ['Insurance Agent', $client['agent_name'] ?? 'Unassigned', 'shield-check'];
          }
          foreach ($info_rows as [$label, $val, $ic]): ?>
          <div class="vc-field">
            <span class="vc-field-icon"><?= icon($ic, 14) ?></span>
            <div class="vc-field-text">
              <div class="vc-field-label"><?= $label ?></div>
              <div class="vc-field-val<?= str_starts_with((string)$val, 'Not provided') ? ' is-empty' : '' ?>" title="<?= htmlspecialchars((string)$val) ?>"><?= htmlspecialchars((string)$val) ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- QR Code -->
        <div class="vc-qr">
          <div class="vc-qr-code">
            <div id="qr-canvas-wrap"></div>
            <div class="vc-qr-logo"><img src="<?= brand_logo('tg', $base_path) ?>" alt=""/></div>
          </div>
          <div class="vc-qr-text">
            <div class="vc-qr-title">Digital ID</div>
            <div class="vc-qr-sub">The client scans this to see their profile and repair progress.</div>
            <div class="vc-qr-btns">
              <a href="<?= htmlspecialchars($public_url) ?>" target="_blank" class="btn-ghost vc-qr-btn"><?= icon('arrow-top-right-on-square', 12) ?> Preview</a>
              <button type="button" onclick="downloadQr()" class="btn-ghost vc-qr-btn"><?= icon('arrow-down-tray', 12) ?> Download</button>
            </div>
          </div>
        </div>

      </div>
    </div>

    <!-- VEHICLES -->
    <div class="card vc-card">
      <div class="card-header">
        <div class="card-icon"><?= icon('vehicle', 16) ?></div>
        <div>
          <div class="card-title">Registered Vehicles</div>
          <div class="card-sub"><?= $vc ?> vehicle<?= $vc !== 1 ? 's' : '' ?> on record</div>
        </div>
        <?php if ($can_edit): ?>
        <a href="add_vehicle.php?client_id=<?= $client_id ?>" class="btn-primary vc-head-btn"><?= icon('plus', 14) ?> Add Vehicle</a>
        <?php endif; ?>
      </div>

      <?php if ($vehicles): ?>
      <div class="vc-vehicles">
        <?php foreach ($vehicles as $v):
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
        <div class="vc-vehicle">

          <!-- 3D Car Image — drag to rotate -->
          <?php $vid = 'vcar-' . $v['vehicle_id']; ?>
          <div class="car3d-wrap vc-car-stage" id="<?= $vid ?>"
               data-make="<?= htmlspecialchars($img_make) ?>"
               data-model="<?= htmlspecialchars($img_model) ?>"
               data-year="<?= $img_year ?>"
               data-paint="<?= htmlspecialchars($img_paint) ?>">
            <img class="car3d-img vc-car-img"
                 src="<?= htmlspecialchars($img_url) ?>"
                 alt="<?= htmlspecialchars($v['make'] . ' ' . $v['model']) ?>"
                 draggable="false"
                 onerror="this.style.display='none';this.nextElementSibling.style.display='flex'"/>
            <!-- Fallback -->
            <div class="car3d-fallback vc-car-fallback">
              <?= icon('vehicle', 36) ?>
              <span>No preview available</span>
            </div>
            <!-- Plate badge overlay -->
            <span class="vc-plate vc-plate-overlay"><?= htmlspecialchars($v['plate_number']) ?></span>
            <!-- Drag hint -->
            <div class="car3d-hint vc-car-hint">
              <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M5 9l-3 3 3 3M9 5l3-3 3 3M15 19l-3 3-3-3M19 9l3 3-3 3M2 12h20M12 2v20"/></svg>
              Drag to rotate
            </div>
          </div>

          <!-- Vehicle Details -->
          <div class="vc-vehicle-body">
            <div>
              <div class="vc-vehicle-name"><?= htmlspecialchars($v['make'] . ' ' . $v['model']) ?></div>
              <div class="vc-vehicle-sub">
                <?= htmlspecialchars($v['year_model'] ?: 'Year unknown') ?>
                <?php if ($v['color']): ?> &middot; <?= htmlspecialchars($v['color']) ?><?php endif; ?>
              </div>
            </div>
            <div class="vc-vehicle-specs">
              <?php foreach ([['Engine No.', $v['motor_number'] ?: 'N/A'], ['Chassis No.', $v['serial_number'] ?: 'N/A']] as [$vlabel, $vval]): ?>
              <div>
                <div class="vc-field-label"><?= $vlabel ?></div>
                <div class="vc-spec-val" title="<?= htmlspecialchars($vval) ?>"><?= htmlspecialchars($vval) ?></div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Actions -->
          <div class="vc-vehicle-actions">
            <a href="view_vehicle.php?id=<?= $v['vehicle_id'] ?>" class="btn-sm-gold vc-grow" title="View Vehicle"><?= icon('eye', 13) ?> View</a>
            <?php if (!$is_mechanic): ?>
            <a href="../insurance/eligibility_check.php?vehicle_id=<?= $v['vehicle_id'] ?>" class="btn-sm-gold vc-icon-btn" title="Check Policy"><?= icon('shield-check', 13) ?></a>
            <?php endif; ?>
            <?php if ($can_edit): ?>
            <a href="edit_vehicle.php?id=<?= $v['vehicle_id'] ?>" class="btn-sm-gold vc-icon-btn" title="Edit"><?= icon('pencil', 13) ?></a>
            <form method="POST" action="delete_vehicle.php" class="vc-inline-form js-delete-vehicle-form"
                  data-plate="<?= htmlspecialchars($v['plate_number'], ENT_QUOTES) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="vehicle_id" value="<?= $v['vehicle_id'] ?>"/>
              <button type="submit" class="btn-sm-danger vc-icon-btn" title="Unregister Vehicle"><?= icon('trash', 13) ?></button>
            </form>
            <?php endif; ?>
          </div>

        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div class="empty-state">
        <div class="empty-icon"><?= icon('vehicle', 28) ?></div>
        <div class="empty-title">No vehicles yet</div>
        <div class="empty-desc">Add a vehicle to start processing insurance.</div>
        <?php if ($can_edit): ?>
        <a href="add_vehicle.php?client_id=<?= $client_id ?>" class="btn-primary"><?= icon('plus', 14) ?> Add Vehicle</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <?php if ($has_policies): ?>
    <!-- POLICY DOCUMENTS -->
    <div class="card vc-card">
      <div class="card-header">
        <div class="card-icon"><?= icon('paper-clip', 16) ?></div>
        <div>
          <div class="card-title">Policy Documents</div>
          <div class="card-sub"><?= count($documents) ?> file<?= count($documents) !== 1 ? 's' : '' ?> attached</div>
        </div>
        <?php if ($can_edit): ?>
        <button type="button" class="btn-sm-gold vc-head-btn js-toggle-doc-upload"><?= icon('plus', 13) ?> Attach PDF</button>
        <?php endif; ?>
      </div>

      <?php if ($can_edit): ?>
      <div id="doc-upload-panel" class="vc-upload" hidden>
        <form method="POST" enctype="multipart/form-data" class="vc-upload-form">
          <?= csrf_field() ?><?= idem_field() ?>
          <input type="hidden" name="action" value="upload_doc"/>
          <label class="vc-upload-drop">
            <?= icon('arrow-up-tray', 20) ?>
            <span class="vc-upload-title">Choose a PDF file</span>
            <span class="vc-upload-sub">Policy document, LOA, and the like</span>
            <input type="file" name="policy_doc" accept="application/pdf" required class="vc-upload-input"/>
          </label>
          <div class="vc-upload-actions">
            <button type="button" class="btn-ghost js-toggle-doc-upload">Cancel</button>
            <button type="submit" class="btn-primary"><?= icon('arrow-up-tray', 13) ?> Upload</button>
          </div>
        </form>
      </div>
      <?php endif; ?>

      <div class="vc-docs">
        <?php if (empty($documents)): ?>
        <div class="vc-docs-empty"><?= icon('paper-clip', 24) ?><span>No documents attached yet.</span></div>
        <?php else: ?>
        <?php foreach ($documents as $doc):
          $pdf_url = htmlspecialchars(upload_url('client_docs', $doc['file_name'], $client_id));
        ?>
        <div class="vc-doc">
          <!-- File header row -->
          <div class="vc-doc-head">
            <div class="vc-doc-type">PDF</div>
            <div class="vc-doc-name">
              <div title="<?= htmlspecialchars($doc['original_name']) ?>"><?= htmlspecialchars($doc['original_name']) ?></div>
              <span><?= date('M d, Y g:i A', strtotime($doc['uploaded_at'])) ?></span>
            </div>
            <div class="vc-doc-actions">
              <a href="<?= $pdf_url ?>" target="_blank" class="btn-sm-gold vc-icon-btn" title="Open in new tab"><?= icon('arrow-top-right-on-square', 13) ?></a>
              <?php if ($can_edit): ?>
              <form method="POST" class="vc-inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_doc"/>
                <input type="hidden" name="doc_id" value="<?= $doc['doc_id'] ?>"/>
                <?php /* file name passed via a data attribute: inside onclick="...'NAME'..." the browser decodes
                         &#039; back to ' before running the JS, so a crafted file name could break out and run code */ ?>
                <button type="button" data-name="<?= htmlspecialchars($doc['original_name'], ENT_QUOTES) ?>" onclick="confirmDeleteDoc(this, this.dataset.name)"
                  class="btn-sm-danger vc-icon-btn" title="Remove"><?= icon('trash', 12) ?></button>
              </form>
              <?php endif; ?>
            </div>
          </div>
          <!-- PDF Preview -->
          <div class="vc-doc-preview">
            <iframe src="<?= $pdf_url ?>" loading="lazy" title="<?= htmlspecialchars($doc['original_name']) ?>"></iframe>
          </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- INSURANCE POLICIES -->
    <div class="card vc-card">
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
                $_elig_url = '../insurance/eligibility_check.php?search=' . urlencode(($client['client_code'] ?? '') ?: $client['full_name']);   // the ID finds only this client, the name could find namesakes
            } else {
                $_elig_url = '../insurance/eligibility_check.php';
            }
        ?>
        <a href="<?= $_elig_url ?>" class="btn-primary vc-head-btn"><?= icon('shield-check', 14) ?> Check Eligibility</a>
        <?php endif; ?>
      </div>
      <?php if ($policies): ?>
      <div class="tg-table-wrap mob-card-wrap">
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
            <?php foreach ($policies as $p):
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
            <?php endforeach; ?>
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
    ?>
    <div class="card vc-card">
      <div class="card-header">
        <div class="card-icon"><?= icon('clipboard-list', 16) ?></div>
        <div>
          <div class="card-title">Claims History</div>
          <div class="card-sub"><?= count($claims) ?> claim<?= count($claims) !== 1 ? 's' : '' ?> on record</div>
        </div>
        <?php if (!$is_mechanic): ?>
        <a href="../claims/add_claim.php?client_id=<?= $client_id ?>" class="btn-primary vc-head-btn">
          <?= icon('plus', 14) ?> File New Claim
        </a>
        <?php endif; ?>
      </div>
      <?php if ($claims): ?>
      <div class="tg-table-wrap mob-card-wrap">
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
            <?php foreach ($claims as $cl):
              $cs = claim_status_badge($cl['status']);
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
                  <?php $ctb = claim_type_badge($conn, $cl['claim_type']); ?>
                  <span class="badge <?= $ctb['class'] ?>"><?= htmlspecialchars($ctb['label']) ?></span>
                  <span class="badge <?= $cs['class'] ?>"><?= htmlspecialchars($cs['label']) ?></span>
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
            <?php endforeach; ?>
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
    $repair_service_labels = service_type_labels($conn);   // service_types table (includes/lookups.php)
    ?>
    <div class="card vc-card">
      <div class="card-header">
        <div class="card-icon"><?= icon('wrench', 16) ?></div>
        <div>
          <div class="card-title">Repair Jobs</div>
          <div class="card-sub"><?= count($repair_jobs) ?> repair job<?= count($repair_jobs) !== 1 ? 's' : '' ?> on record</div>
        </div>
        <?php
          $_repair_prefill = '../repair/add_repair.php?prefill_client=' . $client_id;
          if (count($_client_vehicle_ids) === 1) $_repair_prefill .= '&prefill_vehicle=' . $_client_vehicle_ids[0];
        ?>
        <a href="<?= htmlspecialchars($_repair_prefill) ?>" class="btn-primary vc-head-btn"><?= icon('plus', 14) ?> Add Repair Job</a>
      </div>
      <?php if ($repair_jobs): ?>
      <div class="tg-table-wrap mob-card-wrap">
      <table class="tg-table mob-card mob-client-repairs-table">
        <thead>
          <tr>
            <th>Repair ID</th>
            <th>Vehicle</th>
            <th>Service</th>
            <th>Repair Date</th>
            <th>Est. Release</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($repair_jobs as $rj):
              $rsb = repair_status_badge($rj['status']);
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
            <td><span class="badge <?= $rsb['class'] ?>"><?= htmlspecialchars($rsb['label']) ?></span></td>
            <td>
              <a href="../repair/view_repair.php?id=<?= $rj['job_id'] ?>" class="btn-sm-gold" title="View">
                <?= icon('eye', 14) ?>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
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
  // Attach PDF / Cancel open and close the upload panel
  document.querySelectorAll('.js-toggle-doc-upload').forEach(function (b) {
    b.addEventListener('click', function () { var p = document.getElementById('doc-upload-panel'); if (p) p.hidden = !p.hidden; });
  });
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
    logo.src = '<?= brand_logo('tg', $base_path) ?>';
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
      link.download = 'QR-<?= addslashes(htmlspecialchars(trim(($client['client_code'] ?? '') . ' ' . $client['full_name']))) ?>.png';
      link.href = out.toDataURL('image/png');
      link.click();
    };
    logo.onerror = function() {
      var link = document.createElement('a');
      link.download = 'QR-<?= addslashes(htmlspecialchars(trim(($client['client_code'] ?? '') . ' ' . $client['full_name']))) ?>.png';
      link.href = out.toDataURL('image/png');
      link.click();
    };
  };
})();
</script> 

<?php require_once '../../includes/footer.php'; ?>