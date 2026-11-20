<?php
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/data_access.php';
require_once '../../config/db.php';
require_once '../../config/validators.php';
require_once '../../config/mailer.php';
require_once '../../config/access.php';
require_once '../../config/dev_access.php';
require_once '../../config/transaction_pin.php';

require_role(['super_admin']);

$full_name = $_SESSION['full_name'];
$initials  = substr(implode('', array_map(fn($w) => strtoupper($w[0] ?? ''), array_filter(explode(' ', $full_name), 'strlen'))), 0, 2);
$admin_id  = (int)$_SESSION['user_id'];

$success = '';
$errors  = [];

// The developer account cannot open this page at all (config/session.php); refusing its changes here as well
// keeps that true even if the page rule ever changes. Emptying $_POST means none of the handlers below runs.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_developer()) {
    csrf_verify();
    $errors[] = 'The developer account cannot change user accounts.';
    $_POST = [];
}

// ── HANDLE DELETE ──
// Only accounts that never did anything (e.g. created by mistake, never activated) can be deleted.
// Everyone else is deactivated instead, so the audit trail and the "created by" links stay intact.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    csrf_verify();
    $del_id = (int)($_POST['user_id'] ?? 0);
    $target = user_find_manageable($conn, $del_id);   // never the hidden developer account

    if (!pin_consume($conn)) {
        $errors[] = PIN_REQUIRED_MSG;
    } elseif ($del_id === $admin_id) {
        $errors[] = 'You cannot delete your own account.';
    } elseif (!$target) {
        $errors[] = 'Account not found.';
    } elseif ($target['role'] === 'super_admin') {
        $errors[] = 'Could not delete account. Super admin accounts cannot be deleted.';
    } elseif (user_has_history($conn, $del_id)) {
        $errors[] = 'This account has activity on record (sign-ins, clients, repair jobs, …), so deleting it would erase that history. Deactivate it instead — they are locked out right away and the records stay intact.';
    } else {
        try {
            if (user_delete($conn, $del_id)) {
                $desc = $full_name . ' deleted account "' . $target['full_name'] . '" (' . $target['role'] . ').';
                audit_log_add($conn, $admin_id, 'ACCOUNT_DELETED', $desc);
                $success = 'Account deleted successfully.';
            } else {
                $errors[] = 'Could not delete account. Please try again.';
            }
        } catch (mysqli_sql_exception $e) {
            error_log('[TG-BASICS] manage_users delete failed: ' . $e->getMessage());
            $errors[] = 'Could not delete account — it is still linked to other records. Deactivate it instead.';
        }
    }
}

// ── HANDLE DEACTIVATE / REACTIVATE ──
// pending     = is_active 0 + activation token still set (invited, never signed up)
// deactivated = is_active 0 + no token (an administrator switched it off)
// A deactivated account is signed out within a minute (config/session.php re-checks the account) and cannot log in.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['deactivate', 'reactivate'], true)) {
    csrf_verify();
    $act    = $_POST['action'];
    $tid    = (int)($_POST['user_id'] ?? 0);
    $target = user_find_manageable($conn, $tid);

    // Deactivating asks for the PIN in the page (manage_users.js); reactivating does not
    if ($act === 'deactivate' && !pin_consume($conn)) {
        $errors[] = PIN_REQUIRED_MSG;
    } elseif ($tid === $admin_id) {
        $errors[] = 'You cannot deactivate your own account.';
    } elseif (!$target) {
        $errors[] = 'Account not found.';
    } elseif ($target['role'] === 'super_admin') {
        $errors[] = 'Super admin accounts are protected.';
    } elseif ($act === 'deactivate' && !(int)$target['is_active']) {
        $errors[] = 'That account is not active.';
    } elseif ($act === 'reactivate' && ((int)$target['is_active'] || $target['activation_token'] !== null)) {
        $errors[] = 'That account is not deactivated.';
    } else {
        user_set_active($conn, $tid, $act !== 'deactivate');

        $log_action = $act === 'deactivate' ? 'ACCOUNT_DEACTIVATED' : 'ACCOUNT_REACTIVATED';
        $desc       = $full_name . ($act === 'deactivate' ? ' deactivated' : ' reactivated') . ' the account of "' . $target['full_name'] . '" (' . $target['role'] . ').';
        audit_log_add($conn, $admin_id, $log_action, $desc);

        $success = $target['full_name'] . ($act === 'deactivate' ? ' was deactivated and can no longer sign in.' : ' was reactivated and can sign in again.');
    }
}

// ── HANDLE CREATE ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    csrf_verify();
    $new_first    = san_str($_POST['new_first_name'] ?? '', MAX_NAME);
    $new_last     = san_str($_POST['new_last_name']  ?? '', MAX_NAME);
    $new_name     = trim($new_first . ' ' . $new_last);
    $new_email    = san_str($_POST['new_email'] ?? '', MAX_EMAIL);
    $new_username = san_str($_POST['new_username'] ?? '', MAX_USERNAME);
    $new_role     = san_enum($_POST['new_role'] ?? '', ['admin', 'mechanic']);

    if ($new_first === '')                   $errors[] = 'First name is required.';
    elseif (!validate_name($new_first))      $errors[] = 'First name contains invalid characters.';
    if ($new_last === '')                    $errors[] = 'Last name is required.';
    elseif (!validate_name($new_last))       $errors[] = 'Last name contains invalid characters.';
    if ($new_email === '')                   $errors[] = 'Email is required.';
    elseif (!validate_email($new_email))     $errors[] = 'Invalid email format.';
    if ($new_username === '')                $errors[] = 'Username is required.';
    elseif (!validate_username($new_username)) $errors[] = 'Username must be 3–50 alphanumeric characters or underscores.';
    if ($new_role === '')                    $errors[] = 'Invalid role selected.';

    // Check duplicate username or email
    if (empty($errors)) {
        if (user_username_or_email_taken($conn, $new_username, $new_email)) {
            $errors[] = 'Username or email already exists.';
        }
    }

    if (empty($errors)) {
        // Generate activation token
        $token    = bin2hex(random_bytes(32));
        // Temporary random password - user sets their own via activation link
        $temp_pw  = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);

        $new_user_id = user_invite($conn, $new_first, $new_last, $new_username, $temp_pw, $new_role, $new_email, $token);
        if ($new_user_id > 0) {
            $activation_link  = app_url('auth/activate.php?token=' . $token);

            $sent = sendActivationEmail($new_email, $new_name, $activation_link);

            // Log it
            $desc = $full_name . ' created account for ' . $new_name . ' (' . $new_role . ').';
            audit_log_add($conn, $admin_id, 'ACCOUNT_CREATED', $desc);

            $success = 'Account created for ' . $new_name . '. ' . ($sent ? 'Activation email sent.' : 'Account created but email failed to send. Share the activation link manually.');
        } else {
            $errors[] = 'Database error. Please try again.';
        }
    }
}

// ── LOAD USERS ──
$users = users_manage_list($conn);   // the developer account is not listed (owner's approval 2026-10-07)
$users_with_history = users_with_history($conn);   // null = unknown → treated as "has history" below

// Row action buttons: active → Deactivate, deactivated → Reactivate, and Delete only while the account has no history
function mu_action_forms(array $u, ?array $history): void {
    $has_history = $history === null || isset($history[(int)$u['user_id']]);
    $forms = [];
    if ($u['acct_status'] === 'active')      $forms[] = ['deactivate', 'btn-sm-gold',   'Deactivate', 'lock-closed',  'js-deactivate-user'];
    if ($u['acct_status'] === 'deactivated') $forms[] = ['reactivate', 'btn-sm-gold',   'Reactivate', 'check-circle', 'js-reactivate-user'];
    if (!$has_history)                       $forms[] = ['delete',     'btn-sm-danger', 'Delete',     'trash',        'js-delete-user'];
    foreach ($forms as [$action, $cls, $title, $icon_name, $js]) { ?>
                <form method="POST" action="" class="mu-act-form">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="<?= $action ?>"/>
                  <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>"/>
                  <button type="button" class="<?= $cls ?> <?= $js ?>" data-name="<?= htmlspecialchars($u['full_name'], ENT_QUOTES) ?>" title="<?= $title ?>" aria-label="<?= $title ?> <?= htmlspecialchars($u['full_name'], ENT_QUOTES) ?>"><?= icon($icon_name, 13) ?><span class="mu-act-label"><?= $title ?></span></button>
                </form>
<?php }
}

// The developer account (config/dev_access.php) is listed like everyone else so the owner can see it exists, but as
// "Developer": no real name, no username, no profile popup, and protected like the owner's own account.
function mu_dev_row(array $u): array {
    if (empty($u['is_hidden'])) return $u;
    return array_merge($u, ['full_name' => 'Developer', 'username' => '', 'profile_photo' => '', 'role' => 'developer']);
}

// When the account was last used: "Online now", "12m ago", "Yesterday", "Nov 3, 2026", or never
function mu_last_seen(array $u): string {
    if (!empty($u['is_online'])) return 'Online now';
    if (empty($u['last_active'])) return $u['acct_status'] === 'pending' ? 'Not activated yet' : 'Never signed in';
    $d = time() - strtotime($u['last_active']);
    if ($d < 3600)   return max(1, (int)floor($d / 60)) . 'm ago';
    if ($d < 86400)  return (int)floor($d / 3600) . 'h ago';
    if ($d < 172800) return 'Yesterday';
    if ($d < 604800) return (int)floor($d / 86400) . ' days ago';
    return date('M j, Y', strtotime($u['last_active']));
}

// Status badge: Active / Pending (invited, not yet activated) / Deactivated (switched off by an admin)
function mu_status_badge(string $status): string {
    return match ($status) {
        'active'      => '<span class="badge badge-green">Active</span>',
        'deactivated' => '<span class="badge badge-red">Deactivated</span>',
        default       => '<span class="badge badge-yellow">Pending</span>',
    };
}

$page_title  = 'Manage Users';
$active_page = 'manage_users';
$base_path   = '../../';
$extra_css   = '<link rel="stylesheet" href="../../assets/css/shared/manage_users.css?v=' . filemtime(__DIR__ . '/../../assets/css/shared/manage_users.css') . '"/>';
require_once '../../includes/header.php';
require_once '../../includes/navbar.php';
?>


<div class="main">

  <?php
$topbar_title      = 'Manage Users';
$topbar_breadcrumb = ['Administration', 'Manage Users'];
require_once '../../includes/topbar.php';
?>

  <div class="content">

    <?php if ($success): ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
      Swal.fire({ toast:true, position:'top-end', icon:'success', titleText:<?= json_encode($success) ?>, showConfirmButton:false, timer:3000, timerProgressBar:true });
    });
    </script>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
      Swal.fire({
        icon: 'error',
        title: 'Something went wrong',
        html: <?= json_encode('<ul style="text-align:left;margin:0;padding-left:1.2rem;">' . implode('', array_map(fn($e) => '<li>' . htmlspecialchars($e) . '</li>', $errors)) . '</ul>') ?>
      });
    });
    </script>
    <?php endif; ?>

    <div class="mu-wrap">

      <?php
      // Store users for both desktop table and mobile cards
      $users_list = [];
      foreach ($users as $u) $users_list[] = mu_dev_row($u);
      $role_labels = [
        'super_admin' => ['Super Admin', 'badge-gold'],
        'admin'       => ['Admin',       'badge-green'],
        'mechanic'    => ['Mechanic',    'badge-blue'],
        'developer'   => ['Developer',   'badge-gray'],
      ];
      // counts for the filter tabs
      $n_all     = count($users_list);
      $n_online  = count(array_filter($users_list, fn($u) => !empty($u['is_online'])));
      $n_admin   = count(array_filter($users_list, fn($u) => in_array($u['role'], ['super_admin', 'admin'], true)));
      $n_mech    = count(array_filter($users_list, fn($u) => $u['role'] === 'mechanic'));
      $n_pending = count(array_filter($users_list, fn($u) => $u['acct_status'] === 'pending'));
      $n_off     = count(array_filter($users_list, fn($u) => $u['acct_status'] === 'deactivated'));
      // which tab a user falls under (data-groups on each row / card)
      $groups = function (array $u): string {
          $g = ['all', in_array($u['role'], ['super_admin', 'admin'], true) ? 'admins' : ($u['role'] === 'mechanic' ? 'mechanics' : '')];
          if ($u['acct_status'] === 'pending') $g[] = 'pending';
          if ($u['acct_status'] === 'deactivated') $g[] = 'deactivated';
          return implode(' ', array_filter($g));
      };
      ?>

      <!-- USER LIST -->
      <div class="card mu-card-main">
        <div class="card-header">
          <div class="card-icon"><?= icon('users', 16) ?></div>
          <div>
            <div class="card-title">System Users</div>
            <div class="card-sub">All staff accounts · <?= $n_all ?> account<?= $n_all !== 1 ? 's' : '' ?> · <span class="mu-online-n"><?= $n_online ?> online now</span></div>
          </div>
          <button type="button" class="btn-primary mu-create-btn" id="js-open-create-modal">
            <?= icon('plus', 14) ?> Create New Account
          </button>
        </div>

        <!-- SEARCH + FILTER TABS (filtering happens in the browser; the list is short) -->
        <div class="mu-toolbar">
          <label class="mu-search">
            <?= icon('magnifying-glass', 14) ?>
            <input type="search" id="mu-search" placeholder="Search name, username or email" autocomplete="off" aria-label="Search users"/>
          </label>
          <div class="mu-tabs" role="tablist" aria-label="Show">
            <?php foreach ([['all', 'All', $n_all], ['admins', 'Admins', $n_admin], ['mechanics', 'Mechanics', $n_mech], ['pending', 'Pending', $n_pending], ['deactivated', 'Deactivated', $n_off]] as [$key, $lbl, $n]):
              if ($n === 0 && !in_array($key, ['all', 'admins', 'mechanics'], true)) continue; ?>
            <button type="button" class="mu-tab<?= $key === 'all' ? ' is-on' : '' ?>" role="tab" aria-selected="<?= $key === 'all' ? 'true' : 'false' ?>" data-group="<?= $key ?>"><?= $lbl ?> <span><?= $n ?></span></button>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- DESKTOP TABLE -->
        <table class="tg-table mu-desktop-table">
          <thead>
            <tr>
              <th class="mu-th-name">Name</th>
              <th>Role</th>
              <th>Status</th>
              <th>Last Seen</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($users_list as $u):
              $rl         = $role_labels[$u['role']] ?? ['Unknown', 'badge-gray'];
              $is_online  = !empty($u['is_online']);
              $u_initials = substr(implode('', array_map(fn($w) => strtoupper($w[0] ?? ''), array_filter(explode(' ', $u['full_name'])))), 0, 2);
              $u_photo    = !empty($u['profile_photo']) ? '../../uploads/avatars/' . htmlspecialchars($u['profile_photo']) : '';
              $profile    = $u['role'] === 'developer' ? '' : ' data-user-id="' . (int)$u['user_id'] . '" title="View profile"';
              $search     = mb_strtolower($u['full_name'] . ' ' . $u['username'] . ' ' . ($u['email'] ?? ''));
            ?>
            <tr class="mu-row is-<?= $u['acct_status'] ?>" data-groups="<?= $groups($u) ?>" data-search="<?= htmlspecialchars($search) ?>">
              <td class="mu-td-name">
                <div class="mu-person">
                  <div class="mu-avatar<?= $is_online ? ' is-online' : '' ?>"<?= $profile ?>>
                    <div class="mu-avatar-pic"><?php if ($u_photo): ?><img src="<?= $u_photo ?>" alt="" loading="lazy"/><?php else: ?><?= $u_initials ?><?php endif; ?></div>
                    <span class="mu-avatar-dot"></span>
                  </div>
                  <div class="mu-person-text"<?= $profile ?>>
                    <div class="mu-name"><?= htmlspecialchars($u['full_name']) ?><?php if ((int)$u['user_id'] === (int)$_SESSION['user_id']): ?> <span class="mu-you">You</span><?php endif; ?></div>
                    <div class="mu-handle"><?= $u['username'] !== '' ? '@' . htmlspecialchars($u['username']) : 'System maintenance' ?><?php if (!empty($u['email']) && $u['role'] !== 'developer'): ?> · <?= htmlspecialchars($u['email']) ?><?php endif; ?></div>
                  </div>
                </div>
              </td>
              <td><span class="badge <?= $rl[1] ?>"><?= $rl[0] ?></span></td>
              <td><?= mu_status_badge($u['acct_status']) ?></td>
              <td class="mu-seen<?= $is_online ? ' is-online' : '' ?>"><?= htmlspecialchars(mu_last_seen($u)) ?></td>
              <td>
                <?php if (!in_array($u['role'], ['super_admin', 'developer'], true)): ?>
                <div class="mu-act-group"><?php mu_action_forms($u, $users_with_history); ?></div>
                <?php else: ?>
                <span class="mu-protected-tag"><?= icon('shield-check', 12) ?> Protected</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <!-- MOBILE CARD LIST -->
        <div class="mu-mobile-list">
          <?php foreach ($users_list as $u):
            $rl         = $role_labels[$u['role']] ?? ['Unknown', 'badge-gray'];
            $is_online  = !empty($u['is_online']);
            $u_initials = substr(implode('', array_map(fn($w) => strtoupper($w[0] ?? ''), array_filter(explode(' ', $u['full_name'])))), 0, 2);
            $u_photo    = !empty($u['profile_photo']) ? '../../uploads/avatars/' . htmlspecialchars($u['profile_photo']) : '';
            $search     = mb_strtolower($u['full_name'] . ' ' . $u['username'] . ' ' . ($u['email'] ?? ''));
          ?>
          <div class="mu-card" data-groups="<?= $groups($u) ?>" data-search="<?= htmlspecialchars($search) ?>">
            <!-- Avatar -->
            <div class="mu-card-avatar" <?= $u['role'] === 'developer' ? '' : 'data-user-id="' . (int)$u['user_id'] . '"' ?>>
              <div class="mu-card-avatar-inner">
                <?php if ($u_photo): ?><img src="<?= $u_photo ?>" alt="" loading="lazy"/><?php else: ?><?= $u_initials ?><?php endif; ?>
              </div>
              <span class="mu-card-dot<?= $is_online ? ' is-online' : '' ?>"></span>
            </div>
            <!-- Name -->
            <div class="mu-card-body" <?= $u['role'] === 'developer' ? '' : 'data-user-id="' . (int)$u['user_id'] . '"' ?>>
              <div class="mu-card-name"><?= htmlspecialchars($u['full_name']) ?></div>
              <div class="mu-card-username"><?= $u['username'] !== '' ? '@' . htmlspecialchars($u['username']) : 'System maintenance' ?> · <?= htmlspecialchars(mu_last_seen($u)) ?></div>
            </div>
            <!-- Badges -->
            <div class="mu-card-badges">
              <span class="badge <?= $rl[1] ?>"><?= $rl[0] ?></span>
              <?= mu_status_badge($u['acct_status']) ?>
            </div>
            <!-- Action -->
            <div class="mu-card-action">
              <?php if (!in_array($u['role'], ['super_admin', 'developer'], true)): ?>
              <?php mu_action_forms($u, $users_with_history); ?>
              <?php else: ?>
              <span class="mu-protected">Protected</span>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <div class="mu-empty" id="mu-empty" hidden><?= icon('magnifying-glass', 22) ?><span>No account matches.</span></div>
      </div>

      <!-- CREATE ACCOUNT MODAL -->
      <div class="modal-overlay" id="create-account-modal" onclick="if(event.target===this)closeCreateAccountModal()">
        <div class="modal-box mu-modal">
          <div class="modal-header">
            <div class="modal-title"><?= icon('user-plus', 16) ?> <span>Create New Account</span></div>
            <button type="button" class="modal-close" onclick="closeCreateAccountModal()" aria-label="Close"><?= icon('x-mark', 14) ?></button>
          </div>
        <form method="POST" action="">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="create"/>
          <div class="mu-modal-body">
            <p class="mu-modal-intro">They get an email with a link to set their own password. The account stays <strong>Pending</strong> until they do.</p>

            <div class="form-grid mu-form-grid">
              <div class="field">
                <label class="field-label">First Name <span class="req">*</span></label>
                <input type="text" name="new_first_name" class="field-input"
                  placeholder="e.g. Juan"
                  value="<?= old('new_first_name') ?>"/>
              </div>
              <div class="field">
                <label class="field-label">Last Name <span class="req">*</span></label>
                <input type="text" name="new_last_name" class="field-input"
                  placeholder="e.g. dela Cruz"
                  value="<?= old('new_last_name') ?>"/>
              </div>
            </div>

            <div class="form-grid mu-form-grid">
              <div class="field">
                <label class="field-label">Email Address <span class="req">*</span></label>
                <input type="email" name="new_email" class="field-input"
                  placeholder="name@email.com"
                  value="<?= old('new_email') ?>"/>
                <span class="field-hint">Activation link will be sent here.</span>
              </div>
              <div class="field">
                <label class="field-label">Username <span class="req">*</span></label>
                <input type="text" name="new_username" class="field-input"
                  placeholder="Letters, numbers, underscores"
                  value="<?= old('new_username') ?>"/>
              </div>
            </div>

            <div class="field">
              <span class="field-label">Role <span class="req">*</span></span>
              <?php $picked_role = is_string($_POST['new_role'] ?? null) ? $_POST['new_role'] : ''; ?>
              <div class="mu-roles" role="radiogroup" aria-label="Role">
                <label class="mu-role">
                  <input type="radio" name="new_role" value="admin"<?= $picked_role === 'admin' ? ' checked' : '' ?>/>
                  <span class="mu-role-icon is-admin"><?= icon('shield-check', 16) ?></span>
                  <span class="mu-role-text"><strong>Admin</strong><small>Clients, insurance policies, claims, billing and repairs</small></span>
                </label>
                <label class="mu-role">
                  <input type="radio" name="new_role" value="mechanic"<?= $picked_role === 'mechanic' ? ' checked' : '' ?>/>
                  <span class="mu-role-icon is-mechanic"><?= icon('wrench', 16) ?></span>
                  <span class="mu-role-text"><strong>Mechanic</strong><small>Repair jobs, inspection, photos and quotations</small></span>
                </label>
              </div>
            </div>

          </div>
          <div class="form-actions">
            <button type="button" class="btn-ghost" onclick="closeCreateAccountModal()">Cancel</button>
            <button type="button" class="btn-primary" id="js-create-btn"><?= icon('envelope', 14) ?> Create &amp; Send Activation</button>
          </div>
        </form>
        </div>
      </div>

    </div>

  </div>
</div>

<script src="../../assets/js/super_admin/manage_users.js?v=<?= filemtime(__DIR__.'/../../assets/js/super_admin/manage_users.js') ?>"></script>
<script>
function openCreateAccountModal() {
  document.getElementById('create-account-modal').classList.add('open');
  setTimeout(function () {
    var first = document.querySelector('[name="new_first_name"]');
    if (first) first.focus();
  }, 80);
}
function closeCreateAccountModal() {
  document.getElementById('create-account-modal').classList.remove('open');
}
document.getElementById('js-open-create-modal').addEventListener('click', openCreateAccountModal);
document.addEventListener('keydown', function (event) {
  if (event.key === 'Escape') closeCreateAccountModal();
});

// Search + tabs: show only the rows / cards that match both
(function () {
  var search = document.getElementById('mu-search');
  var tabs   = Array.prototype.slice.call(document.querySelectorAll('.mu-tab'));
  var items  = Array.prototype.slice.call(document.querySelectorAll('.mu-row, .mu-mobile-list .mu-card'));
  var empty  = document.getElementById('mu-empty');
  var group  = 'all';
  function apply() {
    var q = (search.value || '').trim().toLowerCase(), shownRows = 0;
    items.forEach(function (el) {
      var ok = (' ' + el.dataset.groups + ' ').indexOf(' ' + group + ' ') !== -1 && (!q || el.dataset.search.indexOf(q) !== -1);
      el.hidden = !ok;
      if (ok && el.classList.contains('mu-row')) shownRows++;
    });
    empty.hidden = shownRows > 0;
  }
  tabs.forEach(function (t) {
    t.addEventListener('click', function () {
      group = t.dataset.group;
      tabs.forEach(function (x) { var on = x === t; x.classList.toggle('is-on', on); x.setAttribute('aria-selected', on ? 'true' : 'false'); });
      apply();
    });
  });
  search.addEventListener('input', apply);
})();
<?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors): ?>
openCreateAccountModal();
<?php endif; ?>
</script>

<?php require_once '../../includes/footer.php'; ?>
