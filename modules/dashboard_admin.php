<?php
require_once __DIR__ . "/../config/session.php";
require_once '../config/db.php';
require_once '../config/settings.php';

$urg_days = (int)getSetting($conn, 'renewal_urgent_days', '7');
$exp_days = (int)getSetting($conn, 'renewal_expiring_days', '30');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header("Location: ../auth/login.php");
    exit;
}

$full_name = $_SESSION['full_name'];
$first_name = explode(' ', $full_name)[0];

// ── STATS QUERIES ──
$total_clients  = $conn->query("SELECT COUNT(*) as c FROM clients")->fetch_assoc()['c'];
$total_vehicles = $conn->query("SELECT COUNT(*) as c FROM vehicles")->fetch_assoc()['c'];
$recent_clients = $conn->query("SELECT COUNT(*) as c FROM clients WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetch_assoc()['c'];
$recent_vehicles = $conn->query("SELECT COUNT(*) as c FROM vehicles WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetch_assoc()['c'];

$total_policies   = $conn->query("SELECT COUNT(*) as c FROM insurance_policies")->fetch_assoc()['c'];
$active_policies  = $conn->query("SELECT COUNT(*) as c FROM insurance_policies WHERE policy_end >= CURDATE()")->fetch_assoc()['c'];
$expiring_soon    = $conn->query("SELECT COUNT(*) as c FROM insurance_policies WHERE DATEDIFF(policy_end, CURDATE()) BETWEEN 0 AND $exp_days")->fetch_assoc()['c'];
$urgent_policies  = $conn->query("SELECT COUNT(*) as c FROM insurance_policies WHERE DATEDIFF(policy_end, CURDATE()) BETWEEN 0 AND $urg_days")->fetch_assoc()['c'];

// ── RENEWAL ALERTS (policies expiring within 30 days) ──
$renewals = $conn->query("
    SELECT p.policy_id, p.policy_number, p.policy_end, p.coverage_type,
           DATEDIFF(p.policy_end, CURDATE()) as days_left,
           c.full_name, v.plate_number, v.make, v.model
    FROM insurance_policies p
    INNER JOIN vehicles v ON p.vehicle_id = v.vehicle_id
    INNER JOIN clients c ON p.client_id = c.client_id
    WHERE DATEDIFF(p.policy_end, CURDATE()) BETWEEN 0 AND $exp_days
    ORDER BY p.policy_end ASC
    LIMIT 6
");

// ── RECENT CLIENTS ──
$recent_list = $conn->query("
    SELECT c.client_id, c.full_name, c.contact_number, c.created_at,
           COUNT(v.vehicle_id) as vehicle_count
    FROM clients c
    LEFT JOIN vehicles v ON c.client_id = v.client_id
    GROUP BY c.client_id
    ORDER BY c.created_at DESC
    LIMIT 5
");

// ── RECENT ACTIVITY (from audit_logs) ──
$activity = $conn->query("
    SELECT a.action, a.description, a.created_at, u.full_name as actor
    FROM audit_logs a
    LEFT JOIN users u ON a.user_id = u.user_id
    ORDER BY a.created_at DESC
    LIMIT 8
");

$activity_items = [];
while ($row = $activity->fetch_assoc()) {
    $ts = strtotime($row['created_at']);
    $diff = time() - $ts;
    if ($diff < 60)        $time_ago = 'Just now';
    elseif ($diff < 3600)  $time_ago = floor($diff / 60) . 'm ago';
    elseif ($diff < 86400) $time_ago = floor($diff / 3600) . 'h ago';
    elseif ($diff < 172800) $time_ago = 'Yesterday, ' . date('g:i A', $ts);
    else $time_ago = date('M d, g:i A', $ts);

    $activity_items[] = [
        'action'      => $row['action'],
        'description' => $row['description'],
        'time_ago'    => $time_ago,
    ];
}

// Greeting & date are now handled in real-time by JavaScript (see bottom of file)

$page_title  = 'Dashboard';
$active_page = 'dashboard';
$base_path   = '../';
$extra_css    = '<link rel="stylesheet" href="' . $base_path . 'assets/css/dashboard.css?v=' . filemtime(__DIR__ . '/../assets/css/dashboard.css') . '"/>';
require_once '../includes/header.php';
require_once '../includes/navbar.php';
?>

<div class="main">

<?php
$topbar_title      = 'Dashboard';
$topbar_breadcrumb = ['Admin Dashboard'];
$topbar_show_clock = true;
require_once '../includes/topbar.php';
?>

  <div class="content">

    <!-- STAT CARDS -->
    <div class="dash-stats">
      <?php
      $stats = [
          ['icon' => 'user',         'theme' => 'gold',  'label' => 'Total Clients',  'value' => $total_clients,  'trend' => ($recent_clients > 0 ? '+' . $recent_clients . ' this month' : 'No new'),  'up' => $recent_clients > 0],
          ['icon' => 'shield-check', 'theme' => 'green', 'label' => 'Active Policies', 'value' => $active_policies, 'trend' => $total_policies . ' total', 'up' => false],
          ['icon' => 'clock',        'theme' => ($urgent_policies > 0 ? 'red' : 'amber'), 'label' => 'Expiring Soon', 'value' => $expiring_soon, 'trend' => ($urgent_policies > 0 ? $urgent_policies . ' urgent' : 'Within ' . $exp_days . ' days'), 'up' => false],
          ['icon' => 'vehicle',      'theme' => 'blue',  'label' => 'Total Vehicles', 'value' => $total_vehicles, 'trend' => ($recent_vehicles > 0 ? '+' . $recent_vehicles . ' this month' : 'Registered'), 'up' => $recent_vehicles > 0],
      ];
      $theme_map = [
          'gold'  => ['accent' => 'linear-gradient(90deg,#D4A017,#E8D5A3)', 'icon_bg' => 'var(--gold-light)',   'icon_color' => 'var(--gold)'],
          'green' => ['accent' => 'linear-gradient(90deg,#2E7D52,#52B788)', 'icon_bg' => 'var(--success-bg)',   'icon_color' => 'var(--success)'],
          'amber' => ['accent' => 'linear-gradient(90deg,#B8860B,#D4A017)', 'icon_bg' => 'var(--warning-bg)',   'icon_color' => 'var(--warning)'],
          'red'   => ['accent' => 'linear-gradient(90deg,#C0392B,#E74C3C)', 'icon_bg' => 'var(--danger-bg)',    'icon_color' => 'var(--danger)'],
          'blue'  => ['accent' => 'linear-gradient(90deg,#1A6B9A,#3498DB)', 'icon_bg' => 'var(--info-bg)',      'icon_color' => 'var(--info)'],
      ];
      foreach ($stats as $s):
          $t = $theme_map[$s['theme']];
      ?>
      <div class="dash-stat">
        <div class="dash-stat-accent" style="background:<?= $t['accent'] ?>;"></div>
        <div class="dash-stat-top">
          <div class="dash-stat-icon" style="background:<?= $t['icon_bg'] ?>;color:<?= $t['icon_color'] ?>;">
            <?= icon($s['icon'], 18) ?>
          </div>
          <span class="dash-stat-badge" style="background:<?= $s['up'] ? 'var(--success-bg)' : 'var(--bg)' ?>;color:<?= $s['up'] ? 'var(--success)' : 'var(--text-muted)' ?>;<?= $s['up'] ? '' : 'border:1px solid var(--border);' ?>">
            <?= $s['trend'] ?>
          </span>
        </div>
        <div class="dash-stat-value"><?= $s['value'] ?></div>
        <div class="dash-stat-label"><?= $s['label'] ?></div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- MAIN GRID -->
    <div class="dash-grid">

      <!-- LEFT COLUMN -->
      <div>

        <!-- RENEWAL ALERTS -->
        <div class="card" style="margin-bottom:1.25rem;">
          <div class="card-header" style="justify-content:space-between;">
            <div style="display:flex;align-items:center;gap:0.75rem;">
              <div class="card-icon"><?= icon('clock', 16) ?></div>
              <div>
                <div class="card-title">Renewal Alerts</div>
                <div class="card-sub">Policies expiring within <?= $exp_days ?> days</div>
              </div>
            </div>
            <?php if ($expiring_soon > 0): ?>
            <a href="renewal/renewal_list.php?filter=expiring" class="btn-sm-gold">
              View All <?= icon('chevron-right', 12) ?>
            </a>
            <?php endif; ?>
          </div>
          <?php if ($renewals->num_rows > 0): ?>
            <?php while ($r = $renewals->fetch_assoc()):
              $is_urgent = $r['days_left'] <= $urg_days;
            ?>
            <a href="renewal/view_policy.php?id=<?= $r['policy_id'] ?>" class="renewal-row">
              <div class="renewal-dot <?= $is_urgent ? 'urgent' : 'expiring' ?>"></div>
              <div class="renewal-info">
                <div class="renewal-name"><?= htmlspecialchars($r['full_name']) ?></div>
                <div class="renewal-meta">
                  <?= htmlspecialchars($r['plate_number'] ?: 'No plate') ?> &middot;
                  <?= htmlspecialchars($r['make'] . ' ' . $r['model']) ?> &middot;
                  <?= htmlspecialchars($r['policy_number']) ?>
                </div>
              </div>
              <div class="renewal-days <?= $is_urgent ? 'urgent' : 'expiring' ?>">
                <?php if ($r['days_left'] == 0): ?>
                  Expires today
                <?php elseif ($r['days_left'] == 1): ?>
                  1 day left
                <?php else: ?>
                  <?= $r['days_left'] ?> days left
                <?php endif; ?>
              </div>
            </a>
            <?php endwhile; ?>
          <?php else: ?>
            <div class="empty-state" style="padding:2rem;">
              <div style="font-size:1.5rem;opacity:0.3;margin-bottom:0.4rem;"><?= icon('check-circle', 28) ?></div>
              <div class="empty-title">All clear</div>
              <div class="empty-desc">No policies expiring within <?= $exp_days ?> days.</div>
            </div>
          <?php endif; ?>
        </div>

        <!-- RECENT CLIENTS -->
        <div class="card">
          <div class="card-header" style="justify-content:space-between;">
            <div style="display:flex;align-items:center;gap:0.75rem;">
              <div class="card-icon"><?= icon('users', 16) ?></div>
              <div>
                <div class="card-title">Recent Clients</div>
                <div class="card-sub">Last 5 added</div>
              </div>
            </div>
            <a href="clients/client_list.php" class="btn-sm-gold">
              View All <?= icon('chevron-right', 12) ?>
            </a>
          </div>
          <?php if ($recent_list->num_rows > 0): ?>
          <table class="tg-table">
            <thead>
              <tr><th>Name</th><th>Contact</th><th>Vehicles</th><th>Added</th></tr>
            </thead>
            <tbody>
              <?php while ($row = $recent_list->fetch_assoc()): ?>
              <tr style="cursor:pointer;" onclick="window.location='clients/view_client.php?id=<?= $row['client_id'] ?>'">
                <td>
                  <div style="display:flex;align-items:center;gap:0.5rem;">
                    <div style="width:26px;height:26px;border-radius:50%;background:linear-gradient(135deg,var(--gold-bright),var(--gold));display:flex;align-items:center;justify-content:center;font-size:0.58rem;font-weight:800;color:#fff;flex-shrink:0;">
                      <?= strtoupper(substr($row['full_name'], 0, 1)) ?>
                    </div>
                    <span style="font-weight:700;color:var(--text-primary);"><?= htmlspecialchars($row['full_name']) ?></span>
                  </div>
                </td>
                <td style="color:var(--text-muted);font-size:0.75rem;"><?= htmlspecialchars($row['contact_number']) ?></td>
                <td><span class="badge badge-gold"><?= $row['vehicle_count'] ?></span></td>
                <td style="font-size:0.72rem;color:var(--text-muted);"><?= date('M d', strtotime($row['created_at'])) ?></td>
              </tr>
              <?php endwhile; ?>
            </tbody>
          </table>
          <?php else: ?>
          <div class="empty-state">
            <div class="empty-icon"><?= icon('users', 28) ?></div>
            <div class="empty-title">No clients yet</div>
            <div class="empty-desc">Start by adding your first client.</div>
          </div>
          <?php endif; ?>
        </div>

      </div>

      <!-- RIGHT COLUMN -->
      <div>

        <!-- QUICK ACTIONS -->
        <div class="card" style="margin-bottom:1.25rem;">
          <div class="card-header">
            <div class="card-icon"><?= icon('arrow-right', 16) ?></div>
            <div>
              <div class="card-title">Quick Actions</div>
              <div class="card-sub">Common tasks</div>
            </div>
          </div>
          <div style="display:flex;flex-direction:column;gap:0.5rem;padding:1rem;">
            <?php
            $actions = [
              ['clients/add_client.php',          'user-plus',        'Add New Client',       'Client and vehicle registration',  '#2E7D52', '#F0FAF4'],
              ['insurance/eligibility_check.php', 'shield-check',     'New Insurance Policy', 'Check eligibility and encode',     '#1A6B9A', '#EBF5FB'],
              ['clients/client_list.php',         'magnifying-glass', 'Search Records',       'Find client, vehicle, or policy',  '#7B3FA0', '#F5EEF8'],
              ['renewal/renewal_list.php',        'clock',            'Renewal Tracking',     'View policy expiry status',        '#B8860B', '#FDF8EE'],
            ];
            foreach ($actions as $a): ?>
            <a href="<?= $a[0] ?>" class="quick-action">
              <div class="quick-action-icon" style="background:<?= $a[5] ?>;color:<?= $a[4] ?>;"><?= icon($a[1], 16) ?></div>
              <div>
                <div class="quick-action-label"><?= $a[2] ?></div>
                <div class="quick-action-hint"><?= $a[3] ?></div>
              </div>
            </a>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- RECENT ACTIVITY -->
        <div class="card">
          <div class="card-header" style="justify-content:space-between;">
            <div style="display:flex;align-items:center;gap:0.75rem;">
              <div class="card-icon"><?= icon('clipboard-list', 16) ?></div>
              <div>
                <div class="card-title">Recent Activity</div>
                <div class="card-sub">Latest system events</div>
              </div>
            </div>
            <?php if ($_SESSION['role'] === 'super_admin'): ?>
            <a href="activity_log.php" class="btn-sm-gold">
              Full Log <?= icon('chevron-right', 12) ?>
            </a>
            <?php endif; ?>
          </div>
          <?php if (empty($activity_items)): ?>
          <div style="padding:2rem;text-align:center;color:var(--text-muted);font-size:0.8rem;">No recent activity.</div>
          <?php else: ?>
            <?php
            $dot_colors = [
                'LOGIN' => 'var(--success)', 'LOGOUT' => 'var(--text-muted)',
                'ACCOUNT_CREATED' => 'var(--gold-bright)', 'ACCOUNT_DELETED' => 'var(--danger)',
                'PASSWORD_RESET' => 'var(--warning)', 'CLIENT_ADDED' => 'var(--success)',
                'CLIENT_UPDATED' => 'var(--warning)', 'VEHICLE_ADDED' => 'var(--success)',
                'POLICY_CREATED' => 'var(--gold-bright)', 'POLICY_SAVED' => 'var(--success)',
            ];
            $total_items = count($activity_items);
            foreach ($activity_items as $i => $item):
                $dot_color = $dot_colors[$item['action']] ?? 'var(--border)';
                $desc = htmlspecialchars($item['description']);
                $desc = preg_replace('/^(\S+\s\S+)/', '<strong>$1</strong>', $desc);
            ?>
            <div class="activity-item">
              <div class="activity-line">
                <div class="activity-dot" style="background:<?= $dot_color ?>;"></div>
                <?php if ($i < $total_items - 1): ?>
                <div class="activity-connector"></div>
                <?php endif; ?>
              </div>
              <div class="activity-body">
                <div class="activity-text"><?= $desc ?></div>
                <div class="activity-time"><?= htmlspecialchars($item['time_ago']) ?></div>
              </div>
            </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

      </div>
    </div>


  </div>
</div>

<script src="../assets/js/shared/dashboard.js"></script>

<?php require_once '../includes/footer.php'; ?>
