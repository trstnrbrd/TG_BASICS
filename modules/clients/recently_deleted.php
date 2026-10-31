<?php
require_once __DIR__ . '/../../includes/data_access.php';
require_once __DIR__ . '/../../config/session.php';
require_once '../../config/db.php';
require_once '../../config/validators.php';
require_once '../../config/access.php';
require_once '../../config/transaction_pin.php';
require_once '../../config/data_purge.php';
require_once '../../includes/transaction.php';

require_role(['admin', 'super_admin']);

// ── HANDLE RESTORE ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_client_id'])) {
    csrf_verify();
    if (!pin_consume($conn)) {
        header('Location: recently_deleted.php?error=' . urlencode(PIN_REQUIRED_MSG));
        exit;
    }
    $restore_id = (int)$_POST['restore_client_id'];
    $cdata      = client_find_deleted($conn, $restore_id);
    if (!$cdata) {
        header('Location: recently_deleted.php?error=' . urlencode('This client is no longer in Recently Deleted.'));
        exit;
    }
    if (!client_restorable($cdata)) {
        http_response_code(403);
        exit('Not allowed.');
    }
    // Plates must stay unique among active clients, and someone may have registered this one since
    $conflicts = client_restore_plate_conflicts($conn, $restore_id);
    if ($conflicts) {
        header('Location: recently_deleted.php?error=' . urlencode('"' . $cdata['full_name'] . '" cannot be restored — another client now has plate ' . implode(', ', $conflicts) . '.'));
        exit;
    }
    db_transaction($conn, function () use ($conn, $restore_id, $cdata) {
        client_restore($conn, $restore_id);
        $desc = ($_SESSION['full_name'] ?? 'Unknown') . ' restored client "' . $cdata['full_name'] . '" from Recently Deleted.';
        audit_log_add($conn, $_SESSION['user_id'], 'CLIENT_RESTORED', $desc);
    });
    header('Location: recently_deleted.php?success=' . urlencode('"' . $cdata['full_name'] . '" has been restored.'));
    exit;
}

// ── HANDLE PERMANENT DELETE (Owner only) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_forever_client_id'])) {
    csrf_verify();
    if (!is_super_admin()) {
        http_response_code(403);
        exit('Not allowed.');
    }
    if (!pin_consume($conn)) {
        header('Location: recently_deleted.php?error=' . urlencode(PIN_REQUIRED_MSG));
        exit;
    }
    $forever_id = (int)$_POST['delete_forever_client_id'];
    $cdata      = client_find_deleted($conn, $forever_id);
    if (!$cdata) {
        header('Location: recently_deleted.php?error=' . urlencode('This client is no longer in Recently Deleted.'));
        exit;
    }
    // The same guard as the purge: the foreign keys would cascade through these records
    $blockers = client_delete_blockers($conn, $forever_id);
    if ($blockers !== '') {
        header('Location: recently_deleted.php?error=' . urlencode('"' . $cdata['full_name'] . '" cannot be deleted — they still have ' . $blockers . ' on record.'));
        exit;
    }
    db_transaction($conn, function () use ($conn, $forever_id) {
        client_delete_forever($conn, $forever_id);
        // The id only: the point of deleting for good is that their personal data is gone
        audit_log_add($conn, $_SESSION['user_id'], 'CLIENT_PURGED', ($_SESSION['full_name'] ?? 'Unknown') . ' permanently deleted client #' . $forever_id . ' from Recently Deleted.');
    });
    purge_client_files($forever_id);
    header('Location: recently_deleted.php?success=' . urlencode('"' . $cdata['full_name'] . '" has been permanently deleted.'));
    exit;
}

// Same rule as deleting: the Owner sees all, an admin the clients they added or handle as agent
$rows = clients_deleted_list($conn, is_super_admin() ? null : (int)$_SESSION['user_id']);

// The Restore / Delete buttons of one row (the phone cards and the desktop table both print them)
$row_actions = function (array $row): void { ?>
  <form method="POST" action="recently_deleted.php" style="display:inline;">
    <?= csrf_field() ?>
    <input type="hidden" name="restore_client_id" value="<?= (int)$row['client_id'] ?>"/>
    <button type="button" class="btn-sm-gold js-restore-client" title="Restore" aria-label="Restore" style="padding:0.35rem 0.55rem;" data-name="<?= htmlspecialchars($row['full_name'], ENT_QUOTES) ?>"><?= icon('arrow-path', 14) ?></button>
  </form>
  <?php if (is_super_admin()): ?>
  <form method="POST" action="recently_deleted.php" style="display:inline;">
    <?= csrf_field() ?>
    <input type="hidden" name="delete_forever_client_id" value="<?= (int)$row['client_id'] ?>"/>
    <button type="button" class="btn-sm-danger js-delete-forever" title="Delete permanently" aria-label="Delete permanently" style="padding:0.35rem 0.55rem;" data-name="<?= htmlspecialchars($row['full_name'], ENT_QUOTES) ?>"><?= icon('trash', 14) ?></button>
  </form>
  <?php endif;
};
$time_left_badge = function (string $deleted_at): string {
    $days = client_purge_days_left($deleted_at);
    if ($days === 0) return '<span class="badge badge-red">Removing soon</span>';
    return '<span class="badge ' . ($days <= 7 ? 'badge-yellow' : 'badge-gray') . '">' . $days . ' day' . ($days !== 1 ? 's' : '') . ' left</span>';
};

$page_title  = 'Recently Deleted';
$active_page = 'clients';
$base_path   = '../../';
require_once '../../includes/header.php';
require_once '../../includes/navbar.php';
?>

<link rel="stylesheet" href="../../assets/css/shared/clients.css?v=<?= filemtime(__DIR__ . '/../../assets/css/shared/clients.css') ?>"/>
<style>
/* Details on the left; time left and the icon buttons stacked on the right */
.rd-card { display: grid; grid-template-columns: minmax(0, 1fr) auto; column-gap: 0.75rem; align-items: start; padding: 0.9rem 1rem; border-bottom: 1px solid var(--border); }
.rd-card:last-child { border-bottom: none; }
.rd-card .cl-card-name { white-space: normal; }
.rd-card .cl-card-body { grid-row: span 2; }
.rd-card-actions { grid-column: 2; justify-self: end; display: flex; gap: 0.4rem; margin-top: 0.5rem; }
.rd-card-actions button { padding: 0.5rem 0.65rem !important; }
.rd-table-actions { display: inline-flex; gap: 0.4rem; align-items: center; }
</style>

<div class="main">

  <?php
$topbar_title      = 'Recently Deleted';
$topbar_breadcrumb = ['Records', 'Clients', 'Recently Deleted'];
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
        Swal.fire({ icon:'error', title:'Action not completed', text:<?= json_encode($_GET['error']) ?> });
      });
    </script>
    <?php endif; ?>

    <div style="margin-bottom:1rem;">
      <a href="client_list.php" class="btn-ghost"><?= icon('arrow-left', 14) ?> Back to Clients</a>
    </div>

    <div class="card" style="margin-bottom:0;">
      <div class="card-header">
        <div class="card-icon"><?= icon('trash', 16) ?></div>
        <div>
          <div class="card-title">Deleted Clients</div>
          <div class="card-sub">Kept for <?= PURGE_DELETED_CLIENTS_DAYS ?> days, then removed for good along with their vehicles and documents.</div>
        </div>
      </div>

      <?php if ($rows): ?>
      <!-- PHONE CARDS (hidden on desktop) -->
      <div class="cl-mobile-list">
        <?php foreach ($rows as $row): ?>
        <div class="rd-card">
          <div class="cl-card-body">
            <div class="cl-card-name"><?= htmlspecialchars($row['full_name']) ?></div>
            <div class="cl-card-meta"><?= htmlspecialchars($row['contact_number'] ?: 'No contact') ?> · <?= (int)$row['vehicle_count'] ?> vehicle<?= (int)$row['vehicle_count'] !== 1 ? 's' : '' ?></div>
            <div class="cl-card-meta">Deleted <?= date('M d, Y', strtotime($row['deleted_at'])) ?></div>
          </div>
          <div class="cl-card-right"><?= $time_left_badge($row['deleted_at']) ?></div>
          <div class="rd-card-actions"><?php $row_actions($row); ?></div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- DESKTOP TABLE (hidden on phones) -->
      <div class="cl-desktop-table tg-table-wrap">
        <table class="tg-table">
          <thead>
            <tr>
              <th style="text-align:center;">Client</th>
              <th style="text-align:center;">Contact</th>
              <th style="text-align:center;">Vehicles</th>
              <th style="text-align:center;">Deleted On</th>
              <th style="text-align:center;">Time Left</th>
              <th style="text-align:center;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $row): ?>
            <tr>
              <td style="text-align:center;"><?= htmlspecialchars($row['full_name']) ?></td>
              <td style="text-align:center;"><?= htmlspecialchars($row['contact_number'] ?: '—') ?></td>
              <td style="text-align:center;"><?= (int)$row['vehicle_count'] ?></td>
              <td style="text-align:center;"><?= date('M d, Y', strtotime($row['deleted_at'])) ?></td>
              <td style="text-align:center;">
                <?= $time_left_badge($row['deleted_at']) ?>
              </td>
              <td style="text-align:center;">
                <div class="rd-table-actions"><?php $row_actions($row); ?></div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
      <div class="empty-state">
        <div class="empty-icon-wrap"><?= icon('trash', 26) ?></div>
        <div class="empty-title">Nothing here</div>
        <div class="empty-desc">Clients you delete stay here for <?= PURGE_DELETED_CLIENTS_DAYS ?> days.</div>
      </div>
      <?php endif; ?>
    </div>

  </div>
</div>

<?php
$footer_extra_scripts = '<script src="../../assets/js/shared/recently_deleted.js?v=' . filemtime(__DIR__ . '/../../assets/js/shared/recently_deleted.js') . '"></script>';
require_once '../../includes/footer.php';
?>
