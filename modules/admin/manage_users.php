<?php
require_once __DIR__ . '/../../config/session.php';
require_once '../../config/db.php';
require_once '../../config/validators.php';
require_once '../../config/mailer.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: ../../auth/login.php");
    exit;
}

$full_name = $_SESSION['full_name'];
$initials  = substr(implode('', array_map(fn($w) => strtoupper($w[0]), explode(' ', $full_name))), 0, 2);
$admin_id  = $_SESSION['user_id'];

$success = '';
$errors  = [];

// ── HANDLE DELETE ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    csrf_verify();
    $del_id = (int)($_POST['user_id'] ?? 0);

    // Cannot delete yourself
    if ($del_id === $admin_id) {
        $errors[] = 'You cannot delete your own account.';
    } else {
        $del = $conn->prepare("DELETE FROM users WHERE user_id = ? AND role != 'super_admin'");
        $del->bind_param('i', $del_id);
        if ($del->execute() && $del->affected_rows > 0) {
            // Log it
            $log  = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'ACCOUNT_DELETED', ?)");
            $desc = $full_name . ' deleted user ID ' . $del_id . '.';
            $log->bind_param('is', $admin_id, $desc);
            $log->execute();
            $success = 'Account deleted successfully.';
        } else {
            $errors[] = 'Could not delete account. Super admin accounts cannot be deleted.';
        }
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
        $dup = $conn->prepare("SELECT user_id FROM users WHERE username = ? OR email = ?");
        $dup->bind_param('ss', $new_username, $new_email);
        $dup->execute();
        if ($dup->get_result()->num_rows > 0) {
            $errors[] = 'Username or email already exists.';
        }
    }

    if (empty($errors)) {
        // Generate activation token
        $token    = bin2hex(random_bytes(32));
        // Temporary random password - user sets their own via activation link
        $temp_pw  = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);

        $ins = $conn->prepare("
            INSERT INTO users (first_name, last_name, username, password, role, email, is_active, activation_token)
            VALUES (?, ?, ?, ?, ?, ?, 0, ?)
        ");
        $ins->bind_param('sssssss', $new_first, $new_last, $new_username, $temp_pw, $new_role, $new_email, $token);

        if ($ins->execute()) {
            $new_user_id      = $conn->insert_id;
            $protocol         = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $activation_link  = $protocol . '://' . $_SERVER['HTTP_HOST'] . '/TG-BASICS/auth/activate.php?token=' . $token;

            $sent = sendActivationEmail($new_email, $new_name, $activation_link);

            // Log it
            $log  = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'ACCOUNT_CREATED', ?)");
            $desc = $full_name . ' created account for ' . $new_name . ' (' . $new_role . ').';
            $log->bind_param('is', $admin_id, $desc);
            $log->execute();

            $success = 'Account created for ' . htmlspecialchars($new_name) . '. ' . ($sent ? 'Activation email sent.' : 'Account created but email failed to send. Share the activation link manually.');
        } else {
            $errors[] = 'Database error. Please try again.';
        }
    }
}

// ── LOAD USERS ──
$users = $conn->query("
    SELECT user_id, full_name, username, role, email, is_active, created_at, last_active, profile_photo,
           (last_active IS NOT NULL AND last_active >= NOW() - INTERVAL 5 MINUTE) AS is_online
    FROM users
    ORDER BY FIELD(role, 'super_admin', 'admin', 'mechanic'), full_name ASC
");

// ── LOAD RECENT AUDIT LOGS ──
$logs = $conn->query("
    SELECT a.log_id, a.action, a.description, a.created_at, u.full_name
    FROM audit_logs a
    LEFT JOIN users u ON a.user_id = u.user_id
    ORDER BY a.created_at DESC
    LIMIT 10
");

$page_title  = 'Manage Users';
$active_page = 'manage_users';
$base_path   = '../../';
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
      Swal.fire({ toast:true, position:'top-end', icon:'success', title:<?= json_encode($success) ?>, showConfirmButton:false, timer:3000, timerProgressBar:true });
    });
    </script>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
      Swal.fire({
        icon: 'error',
        title: 'Something went wrong',
        html: <?= json_encode('<ul style="text-align:left;margin:0;padding-left:1.2rem;">' . implode('', array_map(fn($e) => '<li>' . htmlspecialchars($e) . '</li>', $errors)) . '</ul>') ?>,
        confirmButtonColor: '#B8860B'
      });
    });
    </script>
    <?php endif; ?>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.25rem;">

      <!-- USER LIST -->
      <div class="card" style="margin-bottom:0;">
        <div class="card-header">
          <div class="card-icon"><?= icon('users', 16) ?></div>
          <div>
            <div class="card-title">System Users</div>
            <div class="card-sub">All active accounts</div>
          </div>
        </div>
        <?php
        // Store users for both desktop table and mobile cards
        $users_list = [];
        while ($u = $users->fetch_assoc()) $users_list[] = $u;
        $role_labels = [
          'super_admin' => ['Super Admin', 'badge-gold'],
          'admin'       => ['Admin',       'badge-green'],
          'mechanic'    => ['Mechanic',    'badge-blue'],
        ];
        ?>

        <!-- DESKTOP TABLE -->
        <table class="tg-table mu-desktop-table">
          <thead>
            <tr>
              <th style="text-align:left;padding-left:1.25rem;">Name</th>
              <th>Role</th>
              <th>Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($users_list as $u):
              $rl         = $role_labels[$u['role']] ?? ['Unknown', 'badge-gray'];
              $is_online  = !empty($u['is_online']);
              $u_initials = substr(implode('', array_map(fn($w) => strtoupper($w[0] ?? ''), array_filter(explode(' ', $u['full_name'])))), 0, 2);
              $u_photo    = !empty($u['profile_photo']) ? '../../uploads/avatars/' . htmlspecialchars($u['profile_photo']) : '';
            ?>
            <tr>
              <td style="text-align:left;padding-left:1.25rem;">
                <div style="display:flex;align-items:center;gap:0.65rem;">
                  <div data-user-id="<?= $u['user_id'] ?>" title="View profile" style="position:relative;flex-shrink:0;cursor:pointer;">
                    <div style="width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,var(--gold-bright),var(--gold));display:flex;align-items:center;justify-content:center;font-size:0.65rem;font-weight:800;color:#fff;overflow:hidden;border:2px solid var(--border);">
                      <?php if ($u_photo): ?><img src="<?= $u_photo ?>" style="width:100%;height:100%;object-fit:cover;" alt=""/><?php else: ?><?= $u_initials ?><?php endif; ?>
                    </div>
                    <span style="position:absolute;bottom:0;right:0;width:9px;height:9px;border-radius:50%;background:<?= $is_online ? '#22c55e' : 'var(--border)' ?>;border:2px solid var(--bg-3);<?= $is_online ? 'box-shadow:0 0 0 2px rgba(34,197,94,0.25);' : '' ?>"></span>
                  </div>
                  <div data-user-id="<?= $u['user_id'] ?>" title="View profile" style="cursor:pointer;">
                    <div style="font-weight:700;color:var(--text-primary);font-size:0.82rem;line-height:1.2;"><?= htmlspecialchars($u['full_name']) ?></div>
                    <div style="font-size:0.7rem;color:var(--text-muted);">@<?= htmlspecialchars($u['username']) ?></div>
                  </div>
                </div>
              </td>
              <td><span class="badge <?= $rl[1] ?>"><?= $rl[0] ?></span></td>
              <td><?php if ($u['is_active']): ?><span class="badge badge-green">Active</span><?php else: ?><span class="badge badge-yellow">Pending</span><?php endif; ?></td>
              <td>
                <?php if ($u['role'] !== 'super_admin'): ?>
                <form method="POST" action="">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete"/>
                  <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>"/>
                  <button type="button" class="btn-sm-danger js-delete-user" data-name="<?= htmlspecialchars($u['full_name'], ENT_QUOTES) ?>" title="Delete"><?= icon('trash', 14) ?></button>
                </form>
                <?php else: ?>
                <span style="font-size:0.7rem;color:var(--text-muted);">Protected</span>
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
          ?>
          <div class="mu-card">
            <!-- Avatar -->
            <div class="mu-card-avatar" data-user-id="<?= $u['user_id'] ?>">
              <div class="mu-card-avatar-inner">
                <?php if ($u_photo): ?><img src="<?= $u_photo ?>" alt=""/><?php else: ?><?= $u_initials ?><?php endif; ?>
              </div>
              <span class="mu-card-dot" style="background:<?= $is_online ? '#22c55e' : 'var(--border)' ?>;<?= $is_online ? 'box-shadow:0 0 0 2px rgba(34,197,94,0.25);' : '' ?>"></span>
            </div>
            <!-- Name -->
            <div class="mu-card-body" data-user-id="<?= $u['user_id'] ?>">
              <div class="mu-card-name"><?= htmlspecialchars($u['full_name']) ?></div>
              <div class="mu-card-username">@<?= htmlspecialchars($u['username']) ?></div>
            </div>
            <!-- Badges -->
            <div class="mu-card-badges">
              <span class="badge <?= $rl[1] ?>"><?= $rl[0] ?></span>
              <?php if ($u['is_active']): ?>
                <span class="badge badge-green">Active</span>
              <?php else: ?>
                <span class="badge badge-yellow">Pending</span>
              <?php endif; ?>
            </div>
            <!-- Action -->
            <div class="mu-card-action">
              <?php if ($u['role'] !== 'super_admin'): ?>
              <form method="POST" action="">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete"/>
                <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>"/>
                <button type="button" class="btn-sm-danger js-delete-user" data-name="<?= htmlspecialchars($u['full_name'], ENT_QUOTES) ?>" title="Delete"><?= icon('trash', 14) ?></button>
              </form>
              <?php else: ?>
              <span class="mu-protected">Protected</span>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- CREATE ACCOUNT FORM -->
      <div class="card" style="margin-bottom:0;">
        <div class="card-header">
          <div class="card-icon"><?= icon('plus', 16) ?></div>
          <div>
            <div class="card-title">Create New Account</div>
            <div class="card-sub">Activation link will be sent via email</div>
          </div>
        </div>
        <form method="POST" action="">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="create"/>
          <div style="padding:1.25rem;display:flex;flex-direction:column;gap:0.9rem;">

            <div class="form-grid" style="gap:0.9rem;">
              <div class="field">
                <label class="field-label">First Name <span class="req">*</span></label>
                <input type="text" name="new_first_name" class="field-input"
                  placeholder="e.g. Juan"
                  value="<?= htmlspecialchars($_POST['new_first_name'] ?? '') ?>"/>
              </div>
              <div class="field">
                <label class="field-label">Last Name <span class="req">*</span></label>
                <input type="text" name="new_last_name" class="field-input"
                  placeholder="e.g. dela Cruz"
                  value="<?= htmlspecialchars($_POST['new_last_name'] ?? '') ?>"/>
              </div>
            </div>

            <div class="field">
              <label class="field-label">Email Address <span class="req">*</span></label>
              <input type="email" name="new_email" class="field-input"
                placeholder="name@email.com"
                value="<?= htmlspecialchars($_POST['new_email'] ?? '') ?>"/>
              <span class="field-hint">Activation link will be sent here.</span>
            </div>

            <div class="field">
              <label class="field-label">Username <span class="req">*</span></label>
              <input type="text" name="new_username" class="field-input"
                placeholder="Letters, numbers, underscores"
                value="<?= htmlspecialchars($_POST['new_username'] ?? '') ?>"/>
            </div>

            <div class="field">
              <label class="field-label">Role <span class="req">*</span></label>
              <select name="new_role" class="field-select">
                <option value="" disabled <?= empty($_POST['new_role']) ? 'selected' : '' ?>>Select role</option>
                <option value="admin"    <?= (($_POST['new_role'] ?? '') === 'admin')    ? 'selected' : '' ?>>Admin</option>
                <option value="mechanic" <?= (($_POST['new_role'] ?? '') === 'mechanic') ? 'selected' : '' ?>>Mechanic</option>
              </select>
            </div>

          </div>
          <div class="form-actions">
            <button type="button" class="btn-primary" id="js-create-btn"><?= icon('envelope', 14) ?> Create &amp; Send Activation</button>
          </div>
        </form>
      </div>

    </div>

    <!-- AUDIT LOGS -->
    <div class="card">
      <div class="card-header" style="justify-content:space-between;">
        <div style="display:flex;align-items:center;gap:0.75rem;">
          <div class="card-icon"><?= icon('clipboard-list', 16) ?></div>
          <div>
            <div class="card-title">Recent Activity</div>
            <div class="card-sub">Last 10 system events</div>
          </div>
        </div>
        <a href="activity_log.php" class="btn-sm-gold">
          View All <?= icon('chevron-right', 12) ?>
        </a>
      </div>
      <?php if ($logs->num_rows > 0): ?>
      <table class="tg-table mob-card mob-audit-table">
        <thead>
          <tr>
            <th>User</th>
            <th>Action</th>
            <th>Description</th>
            <th>Date &amp; Time</th>
          </tr>
        </thead>
        <tbody>
          <?php while ($log = $logs->fetch_assoc()):
            $action_badges = [
              'LOGIN'            => 'badge-green',
              'LOGOUT'           => 'badge-gray',
              'ACCOUNT_CREATED'  => 'badge-gold',
              'ACCOUNT_DELETED'  => 'badge-red',
              'PASSWORD_RESET'   => 'badge-yellow',
              'CLIENT_ADDED'     => 'badge-green',
              'CLIENT_UPDATED'   => 'badge-yellow',
              'VEHICLE_ADDED'    => 'badge-green',
              'POLICY_CREATED'   => 'badge-gold',
              'POLICY_SAVED'     => 'badge-green',
            ];
            $ab = $action_badges[$log['action']] ?? 'badge-gray';
          ?>
          <tr>
            <td style="font-weight:700;color:<?= $log['full_name'] ? 'var(--text-primary)' : 'var(--text-muted)' ?>;font-size:0.8rem;font-style:<?= $log['full_name'] ? 'normal' : 'italic' ?>;">
              <?= $log['full_name'] ? htmlspecialchars($log['full_name']) : 'Deleted User' ?>
            </td>
            <td><span class="badge <?= $ab ?>"><?= htmlspecialchars($log['action']) ?></span></td>
            <td style="font-size:0.78rem;color:var(--text-secondary);"><?= htmlspecialchars($log['description']) ?></td>
            <td style="font-size:0.72rem;color:var(--text-muted);white-space:nowrap;"><?= date('M d, Y h:i A', strtotime($log['created_at'])) ?></td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
      <?php else: ?>
      <div class="empty-state">
        <div class="empty-icon"><?= icon('clipboard-list', 28) ?></div>
        <div class="empty-title">No logs yet</div>
        <div class="empty-desc">System events will appear here.</div>
      </div>
      <?php endif; ?>
    </div>

  </div>
</div>

<script src="../../assets/js/super_admin/manage_users.js?v=<?= filemtime(__DIR__.'/../../assets/js/super_admin/manage_users.js') ?>"></script>

<?php require_once '../../includes/footer.php'; ?>