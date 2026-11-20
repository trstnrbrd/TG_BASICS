<?php
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/data_access.php';
require_once '../../config/db.php';
require_once '../../config/settings.php';
require_once '../../config/access.php';
require_once '../../includes/insurance_companies.php';
require_once '../../includes/backup.php';
require_once '../../config/dev_access.php';

$urg_days = setting_int($conn, 'renewal_urgent_days');
$exp_days = setting_int($conn, 'renewal_expiring_days');

require_role(['admin', 'super_admin']);
require_once '../../config/validators.php';   // csrf_token() for the reminder call below

$full_name = $_SESSION['full_name'];
$first_name = explode(' ', $full_name)[0];

// ── STATS ──
['total_clients' => $total_clients, 'total_vehicles' => $total_vehicles, 'recent_clients' => $recent_clients,
 'recent_vehicles' => $recent_vehicles, 'total_policies' => $total_policies, 'active_policies' => $active_policies] = dashboard_counts($conn);
// Renewal figures follow the same scope as Renewal Tracking: the Owner sees all, an admin only the
// policies of clients they are the insurance agent of (config/access.php).
$expiring_soon   = policies_due_count($conn, $exp_days);
$urgent_policies = policies_due_count($conn, $urg_days);

// ── RENEWAL ALERTS (policies expiring within the "expiring" window) ──
$renewals = policies_due_list($conn, $exp_days, 6);

// ── TEAM MEMBERS ──
$team = users_team($conn);

// ── RECENT ACTIVITY (from audit_logs) ──
$activity_items = [];
foreach (audit_log_recent($conn, 8) as $row) {
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

// ── CHARTS DATA ──

// Client type breakdown — last 6 months (for JS navigator)
$ct_months = [];
for ($m = 5; $m >= 0; $m--) {
    [$ins, $wk] = report_client_types_in_month($conn, (int)date('Y', strtotime("-$m months")), (int)date('m', strtotime("-$m months")));
    $ct_months[] = [
        'label'     => date('F Y', strtotime("-$m months")),
        'short'     => date('M',   strtotime("-$m months")),
        'year'      => date('Y',   strtotime("-$m months")),
        'insurance' => $ins,
        'walkin'    => $wk,
        'total'     => $ins + $wk,
    ];
}
// Current month (last entry) used for initial PHP render
$cur_ct = end($ct_months);
$insurance_clients   = $cur_ct['insurance'];
$walkin_clients      = $cur_ct['walkin'];
$month_clients_total = $cur_ct['total'];

// Policies added per month (last 6 months)
$monthly_policies = [];
for ($m = 5; $m >= 0; $m--) {
    $monthly_policies[] = ['label' => date('M Y', strtotime("-$m months")),
                           'count' => report_policies_in_month($conn, (int)date('Y', strtotime("-$m months")), (int)date('m', strtotime("-$m months")))];
}

// Payment status breakdown
$pay_status = array_replace(['Paid' => 0, 'Partial' => 0, 'Unpaid' => 0, 'Overdue' => 0], report_payment_status_counts($conn));

// Repair jobs per month (last 6 months)
$monthly_repairs = [];
for ($m = 5; $m >= 0; $m--) {
    $monthly_repairs[] = ['label' => date('M Y', strtotime("-$m months")),
                          'count' => report_repairs_in_month($conn, (int)date('Y', strtotime("-$m months")), (int)date('m', strtotime("-$m months")))];
}

// Greeting & date are now handled in real-time by JavaScript (see bottom of file)

$page_title  = 'Dashboard';
$active_page = 'dashboard';
$base_path   = '../../';
$extra_css    = '<link rel="stylesheet" href="' . $base_path . 'assets/css/dashboard.css?v=' . filemtime(__DIR__ . '/../../assets/css/dashboard.css') . '"/>';
require_once '../../includes/header.php';
require_once '../../includes/navbar.php';
?>

<div class="main">

<?php
$topbar_title      = 'Dashboard';
$topbar_breadcrumb = ['Admin Dashboard'];
$topbar_show_clock = true;
require_once '../../includes/topbar.php';
?>

  <div class="content">

    <?php if (is_super_admin() && !is_developer() && ($bk = db_backup_status($conn))['overdue']): ?>
    <!-- Weekly backup reminder for the Owner (Settings > System Settings > Database Backup) -->
    <div class="alert alert-warning dash-backup" role="status">
      <?= icon('exclamation-triangle', 15) ?>
      <span class="dash-backup-text"><?= $bk['at'] ? 'The last database backup was ' . $bk['days'] . ' days ago.' : 'No database backup has been downloaded yet.' ?> Download one so the records stay safe even if the server has problems.</span>
      <a href="settings.php#db-backup" class="btn-primary dash-backup-btn"><?= icon('arrow-down-tray', 13) ?> Back Up Now</a>
    </div>
    <?php endif; ?>

    <?php
    // ── small helpers for this page ──
    // A sparkline (6 monthly values) as an inline SVG path, drawn in the card's own colour (currentColor)
    $spark = function (array $vals): string {
        $n = count($vals); if ($n < 2) return '';
        $max = max(1, max($vals)); $w = 100; $h = 26;
        $pts = [];
        foreach (array_values($vals) as $i => $v) $pts[] = [round($i * $w / ($n - 1), 2), round($h - 2 - ($v / $max) * ($h - 6), 2)];
        $line = 'M' . implode(' L', array_map(fn($p) => $p[0] . ' ' . $p[1], $pts));
        $area = $line . " L$w $h L0 $h Z";
        [$lx, $ly] = end($pts);
        return '<svg class="dash-spark" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" aria-hidden="true">'
             . '<path class="dash-spark-area" d="' . $area . '"/><path class="dash-spark-line" d="' . $line . '"/>'
             . '<circle class="dash-spark-dot" cx="' . $lx . '" cy="' . $ly . '" r="2.4"/></svg>';
    };
    $pct = fn($a, $b) => $b > 0 ? (int)round($a / $b * 100) : 0;
    // "12 this month · +3 vs last month" under a monthly chart
    $month_delta = function (array $rows): string {
        $now = (int)end($rows)['count']; $prev = (int)($rows[count($rows) - 2]['count'] ?? 0); $d = $now - $prev;
        return $now . ' this month · ' . ($d === 0 ? 'same as last month' : ($d > 0 ? '+' : '−') . abs($d) . ' vs last month');
    };
    $new_clients_6m = array_column($ct_months, 'total');
    $overdue_pay    = (int)$pay_status['Overdue'];
    ?>

    <!-- HELLO: who is signed in, today, and what needs attention -->
    <div class="dash-hello">
      <div class="dash-hello-text">
        <div class="dash-hello-title"><span id="dash-greet">Hello</span>, <?= htmlspecialchars($first_name) ?></div>
        <div class="dash-hello-sub"><?= $urgent_policies > 0 || $overdue_pay > 0 ? 'A few things need you today.' : ($expiring_soon > 0 ? 'Some renewals are coming up.' : 'Everything is on track.') ?></div>
      </div>
      <div class="dash-hello-chips">
        <?php if ($urgent_policies > 0): ?>
        <a class="dash-chip is-danger" href="../renewal/renewal_list.php?agent=all&amp;filter=urgent"><span class="dash-chip-dot"></span><?= $urgent_policies ?> urgent renewal<?= $urgent_policies !== 1 ? 's' : '' ?></a>
        <?php endif; ?>
        <?php if ($expiring_soon > $urgent_policies): ?>
        <a class="dash-chip is-warning" href="../renewal/renewal_list.php?agent=all&amp;filter=expiring"><span class="dash-chip-dot"></span><?= $expiring_soon - $urgent_policies ?> expiring within <?= $exp_days ?> days</a>
        <?php endif; ?>
        <?php if ($overdue_pay > 0): ?>
        <span class="dash-chip is-danger"><span class="dash-chip-dot"></span><?= $overdue_pay ?> overdue payment<?= $overdue_pay !== 1 ? 's' : '' ?></span>
        <?php endif; ?>
        <?php if ($expiring_soon === 0 && $overdue_pay === 0): ?>
        <span class="dash-chip is-success"><span class="dash-chip-dot"></span>All clear</span>
        <?php endif; ?>
      </div>
    </div>

    <!-- STAT CARDS -->
    <div class="dash-stats">
      <?php
      $stats = [
          ['icon' => 'user',         'theme' => 'gold',  'label' => 'Total Clients',  'value' => $total_clients,  'trend' => ($recent_clients > 0 ? '+' . $recent_clients . ' this month' : 'No new'),  'up' => $recent_clients > 0,
           'href' => '../clients/client_list.php?agent=all',
           'micro' => $spark($new_clients_6m) . '<span class="dash-micro-note">new clients, 6 months</span>'],
          ['icon' => 'shield-check', 'theme' => 'green', 'label' => 'Active Policies', 'value' => $active_policies, 'trend' => $total_policies . ' total', 'up' => false,
           'href' => '../renewal/renewal_list.php?agent=all',
           'micro' => '<span class="dash-meter"><span style="width:' . $pct($active_policies, $total_policies) . '%"></span></span><span class="dash-micro-note">' . $pct($active_policies, $total_policies) . '% of all policies active</span>'],
          ['icon' => 'clock',        'theme' => ($urgent_policies > 0 ? 'red' : 'amber'), 'label' => 'Expiring Soon', 'value' => $expiring_soon, 'trend' => ($urgent_policies > 0 ? $urgent_policies . ' urgent' : 'Within ' . $exp_days . ' days'), 'up' => false,
           'href' => '../renewal/renewal_list.php?agent=all&filter=' . ($urgent_policies > 0 ? 'urgent' : 'expiring'),
           'micro' => '<span class="dash-meter"><span style="width:' . $pct($expiring_soon, max(1, $active_policies)) . '%"></span></span><span class="dash-micro-note">' . $pct($expiring_soon, max(1, $active_policies)) . '% of active policies</span>'],
          ['icon' => 'vehicle',      'theme' => 'blue',  'label' => 'Total Vehicles', 'value' => $total_vehicles, 'trend' => ($recent_vehicles > 0 ? '+' . $recent_vehicles . ' this month' : 'Registered'), 'up' => $recent_vehicles > 0,
           'href' => '../clients/client_list.php?agent=all',
           'micro' => '<span class="dash-micro-note">' . ($total_clients > 0 ? number_format($total_vehicles / $total_clients, 1) : '0') . ' per client on average</span>'],
      ];
      // Card colours: the dash-stat--<theme> classes in assets/css/dashboard.css
      foreach ($stats as $s):
      ?>
      <a class="dash-stat dash-stat--<?= $s['theme'] ?>" href="<?= htmlspecialchars($s['href']) ?>" aria-label="<?= htmlspecialchars($s['label'] . ': ' . $s['value']) ?>">
        <div class="dash-stat-accent"></div>
        <div class="dash-stat-top">
          <div class="dash-stat-icon">
            <?= icon($s['icon'], 18) ?>
          </div>
          <span class="dash-stat-badge<?= $s['up'] ? ' is-up' : '' ?>">
            <?= $s['trend'] ?>
          </span>
        </div>
        <div class="dash-stat-value"><?= $s['value'] ?></div>
        <div class="dash-stat-label"><?= $s['label'] ?></div>
        <div class="dash-stat-micro"><?= $s['micro'] ?></div>
      </a>
      <?php endforeach; ?>
    </div>

    <!-- MAIN GRID: 3 equal columns (Recent Activity is Super Admin only, so Admins get 2) -->
    <div class="dash-main-grid <?= is_super_admin() ? 'is-three' : 'is-two' ?>">

      <!-- COL 1: RENEWAL ALERTS -->
      <div class="card dash-panel">
          <div class="card-header">
            <div class="card-icon"><?= icon('clock', 16) ?></div>
            <div>
              <div class="card-title">Renewal Alerts</div>
              <div class="card-sub">Policies expiring within <?= $exp_days ?> days</div>
            </div>
            <?php if ($expiring_soon > 0): ?>
            <a href="../renewal/renewal_list.php?filter=expiring" class="btn-sm-gold dash-panel-link">
              View All <?= icon('chevron-right', 12) ?>
            </a>
            <?php endif; ?>
          </div>
          <div class="dash-panel-body">
          <?php if ($renewals): ?>
            <?php foreach ($renewals as $r):
              $is_urgent = $r['days_left'] <= $urg_days;
              $closeness = $exp_days > 0 ? max(4, min(100, (int)round((1 - $r['days_left'] / $exp_days) * 100))) : 100;
            ?>
            <a href="../renewal/view_policy.php?id=<?= $r['policy_id'] ?>" class="renewal-row">
              <div class="renewal-dot <?= $is_urgent ? 'urgent' : 'expiring' ?>"></div>
              <div class="renewal-info">
                <div class="renewal-name"><?= htmlspecialchars($r['full_name']) ?></div>
                <div class="renewal-meta">
                  <?= htmlspecialchars($r['plate_number'] ?: 'No plate') ?> &middot;
                  <?= htmlspecialchars($r['make'] . ' ' . $r['model']) ?>
                </div>
              </div>
              <div class="renewal-side">
                <div class="renewal-days <?= $is_urgent ? 'urgent' : 'expiring' ?>">
                  <?php if ($r['days_left'] == 0): ?>Expires today
                  <?php elseif ($r['days_left'] == 1): ?>1 day left
                  <?php else: ?><?= $r['days_left'] ?> days left<?php endif; ?>
                </div>
                <span class="renewal-meter <?= $is_urgent ? 'urgent' : 'expiring' ?>" aria-hidden="true"><span style="width:<?= $closeness ?>%"></span></span>
              </div>
            </a>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="empty-state dash-empty">
              <div class="dash-empty-icon"><?= icon('check-circle', 28) ?></div>
              <div class="empty-title">All clear</div>
              <div class="empty-desc">No policies expiring within <?= $exp_days ?> days.</div>
            </div>
          <?php endif; ?>
          </div>
      </div>

      <!-- COL 2: QUICK ACTIONS -->
      <div class="card dash-panel">
        <div class="card-header">
          <div class="card-icon"><?= icon('arrow-right', 16) ?></div>
          <div>
            <div class="card-title">Quick Actions</div>
            <div class="card-sub">Common tasks</div>
          </div>
        </div>
        <div class="dash-panel-body dash-actions">
          <?php
          // tone = the quick-action-icon colour class (qa-<tone>) in assets/css/dashboard.css
          $actions = [
            ['../clients/add_client.php',          'user-plus',        'Add New Client',       'Client and vehicle registration',  'green'],
            ['../insurance/eligibility_check.php', 'shield-check',     'New Insurance Policy', 'Check eligibility and encode',     'blue'],
          ];
          foreach (insurance_companies_tracked($conn) as $dash_co) {
            $actions[] = ['../renewal/renewal_list.php?company=' . urlencode($dash_co['name']), 'shield-check', $dash_co['short_name'], 'Renewal tracking & policy expiry', 'gold'];
          }
          foreach ($actions as $a): ?>
          <a href="<?= htmlspecialchars($a[0]) ?>" class="quick-action">
            <div class="quick-action-icon qa-<?= $a[4] ?>"><?= icon($a[1], 16) ?></div>
            <div class="quick-action-text">
              <div class="quick-action-label"><?= htmlspecialchars($a[2]) ?></div>
              <div class="quick-action-hint"><?= htmlspecialchars($a[3]) ?></div>
            </div>
            <span class="quick-action-go" aria-hidden="true"><?= icon('chevron-right', 14) ?></span>
          </a>
          <?php endforeach; ?>
        </div>
      </div>

      <?php if (is_super_admin()): ?>
      <!-- COL 3: RECENT ACTIVITY (Super Admin only — shows system-wide events across all staff) -->
      <div class="card dash-panel">
        <div class="card-header">
          <div class="card-icon"><?= icon('clipboard-list', 16) ?></div>
          <div>
            <div class="card-title">Recent Activity</div>
            <div class="card-sub">Latest system events</div>
          </div>
          <a href="activity_log.php" class="btn-sm-gold dash-panel-link">Full Log <?= icon('chevron-right', 12) ?></a>
        </div>
        <div class="dash-panel-body">
        <?php if (empty($activity_items)): ?>
        <div class="dash-none">No recent activity.</div>
        <?php else: ?>
          <?php
          require_once __DIR__ . '/../../includes/audit_actions.php';   // one colour per action, shared with the Activity Log
          $total_items = count($activity_items);
          foreach ($activity_items as $i => $item):
            $dot_color = audit_action_style($item['action'])['dot'];
            $desc = htmlspecialchars($item['description']);
            $desc = preg_replace('/^(\S+\s\S+)/', '<strong>$1</strong>', $desc);
          ?>
          <div class="activity-item">
            <div class="activity-line">
              <div class="activity-dot" style="background:<?= $dot_color ?>;"></div>
              <?php if ($i < $total_items - 1): ?><div class="activity-connector"></div><?php endif; ?>
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
      <?php endif; ?>

    </div>


    <!-- CHARTS ROW: 3 equal columns -->
    <div class="dash-charts-grid">

      <!-- Client Types PIE (‹ › steps through the last 6 months) -->
      <div class="card dash-chart-card">
        <div class="card-header">
          <div class="card-icon"><?= icon('users', 16) ?></div>
          <div>
            <div class="card-title">Client Types</div>
            <div class="card-sub" id="ct-month-label"><?= $cur_ct['label'] ?></div>
          </div>
          <div class="dash-month-nav" role="group" aria-label="Choose the month">
            <button type="button" class="dash-nav-btn" id="ct-prev" aria-label="Previous month"><?= icon('chevron-left', 14) ?></button>
            <button type="button" class="dash-nav-btn" id="ct-next" aria-label="Next month" disabled><?= icon('chevron-right', 14) ?></button>
          </div>
        </div>
        <div class="dash-ct-body">
          <div class="dash-donut dash-donut-sm">
            <canvas id="chart-client-types"></canvas>
            <div class="dash-donut-center">
              <div id="ct-center-month" class="dash-donut-big"><?= $cur_ct['short'] ?></div>
              <div id="ct-center-year" class="dash-donut-small"><?= $cur_ct['year'] ?></div>
            </div>
          </div>
          <div class="dash-ct-legend">
            <div class="dash-ct-row">
              <span class="dash-key"><span class="dash-key-dot is-success"></span>Insurance</span>
              <span class="dash-ct-nums"><span id="ct-ins-val" class="dash-ct-val"><?= $cur_ct['insurance'] ?></span><span id="ct-ins-pct" class="dash-ct-pct is-success"><?= $cur_ct['total'] > 0 ? round($cur_ct['insurance']/$cur_ct['total']*100) : 0 ?>%</span></span>
            </div>
            <div class="dash-ct-row">
              <span class="dash-key"><span class="dash-key-dot is-gold"></span>Walk-in</span>
              <span class="dash-ct-nums"><span id="ct-wk-val" class="dash-ct-val"><?= $cur_ct['walkin'] ?></span><span id="ct-wk-pct" class="dash-ct-pct is-gold"><?= $cur_ct['total'] > 0 ? round($cur_ct['walkin']/$cur_ct['total']*100) : 0 ?>%</span></span>
            </div>
            <div class="dash-ct-dots" aria-hidden="true"><?php foreach ($ct_months as $i => $_m): ?><span class="<?= $i === count($ct_months) - 1 ? 'is-on' : '' ?>"></span><?php endforeach; ?></div>
          </div>
          <a href="../admin/monthly_report.php" class="dash-ct-report">Full monthly report <?= icon('chevron-right', 12) ?></a>
        </div>
      </div>

      <!-- Policies per Month BAR -->
      <div class="card dash-chart-card">
        <div class="card-header">
          <div class="card-icon"><?= icon('document', 16) ?></div>
          <div>
            <div class="card-title">Policies Added</div>
            <div class="card-sub"><?= htmlspecialchars($month_delta($monthly_policies)) ?></div>
          </div>
        </div>
        <div class="dash-chart-body">
          <canvas id="chart-policies" height="160"></canvas>
        </div>
      </div>

      <!-- Repair Jobs per Month BAR -->
      <div class="card dash-chart-card">
        <div class="card-header">
          <div class="card-icon"><?= icon('wrench', 16) ?></div>
          <div>
            <div class="card-title">Repair Jobs</div>
            <div class="card-sub"><?= htmlspecialchars($month_delta($monthly_repairs)) ?></div>
          </div>
        </div>
        <div class="dash-chart-body">
          <canvas id="chart-repairs" height="160"></canvas>
        </div>
      </div>

    </div>

    <!-- TEAM MEMBERS — full width -->
    <div class="card dash-team-card">
      <div class="card-header">
        <div class="card-icon"><?= icon('users', 16) ?></div>
        <div>
          <div class="card-title">Team</div>
          <?php $online_n = count(array_filter($team, fn($t) => !empty($t['is_online']))); ?>
          <div class="card-sub">All TG-BASICS users · <span class="dash-online-n"><?= $online_n ?> online now</span></div>
        </div>
      </div>
      <div class="dash-team">
        <?php
        $role_labels_map = ['super_admin'=>'Owner','admin'=>'Admin','mechanic'=>'Mechanic'];
        $role_badge_map  = ['super_admin'=>'badge-gold','admin'=>'badge-green','mechanic'=>'badge-blue'];
        foreach ($team as $tm):
          $tm_initials = substr(implode('', array_map(fn($w) => strtoupper($w[0] ?? ''), array_filter(explode(' ', $tm['full_name'])))), 0, 2);
          $tm_photo    = !empty($tm['profile_photo']) ? '../../uploads/avatars/' . htmlspecialchars($tm['profile_photo']) : '';
          $tm_online   = !empty($tm['is_online']);
          $tm_rl       = $role_labels_map[$tm['role']] ?? $tm['role'];
          $tm_rb       = $role_badge_map[$tm['role']] ?? 'badge-gray';
        ?>
        <div class="dash-member<?= $tm_online ? ' is-online' : '' ?>" data-user-id="<?= $tm['user_id'] ?>" title="View profile" role="button" tabindex="0">
          <!-- Avatar -->
          <div class="dash-member-avatar">
            <div class="dash-member-pic">
              <?php if ($tm_photo): ?>
                <img src="<?= $tm_photo ?>" alt="" loading="lazy"/>
              <?php else: ?>
                <?= $tm_initials ?>
              <?php endif; ?>
            </div>
            <span class="dash-member-dot" aria-label="<?= $tm_online ? 'Online' : 'Offline' ?>"></span>
          </div>
          <!-- Info -->
          <div class="dash-member-info">
            <div class="dash-member-name"><?= htmlspecialchars($tm['full_name']) ?></div>
            <div class="dash-member-tags">
              <span class="badge <?= $tm_rb ?> dash-member-role"><?= $tm_rl ?></span>
              <?php if ($tm['user_id'] == $_SESSION['user_id']): ?>
              <span class="dash-member-you">You</span>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- PAYMENT STATUS — full width (hover a row to find it in the chart, click it to hide / show it) -->
    <div class="card dash-pay-card">
      <div class="card-header">
        <div class="card-icon"><?= icon('receipt', 16) ?></div>
        <div>
          <div class="card-title">Payment Status Breakdown</div>
          <div class="card-sub">All policies — <?= array_sum($pay_status) ?> total · click a status to hide or show it</div>
        </div>
      </div>
      <div class="dash-pay">
        <!-- Doughnut -->
        <div class="dash-donut dash-donut-lg">
          <canvas id="chart-payment-status"></canvas>
          <div class="dash-donut-center">
            <div class="tg-count dash-donut-total" id="ps-total"><?= array_sum($pay_status) ?></div>
            <div class="dash-donut-small">Policies</div>
          </div>
        </div>
        <!-- Legend bars -->
        <div class="dash-pay-legend">
          <?php
          // tone = the colour class (is-<tone>) in assets/css/dashboard.css; the chart reads the same theme colours
          $ps_items = [
            ['Paid',    $pay_status['Paid'],    'success'],
            ['Partial', $pay_status['Partial'], 'gold'],
            ['Unpaid',  $pay_status['Unpaid'],  'muted'],
            ['Overdue', $pay_status['Overdue'], 'danger'],
          ];
          $ps_total = max(1, array_sum($pay_status));
          foreach ($ps_items as $ps_i => [$lbl, $val, $tone]):
            $ps_pct = round($val / $ps_total * 100);
          ?>
          <button type="button" class="ps-item is-<?= $tone ?>" data-index="<?= $ps_i ?>" aria-pressed="true">
            <span class="ps-item-top">
              <span class="dash-key"><span class="dash-key-dot is-<?= $tone ?>"></span><?= $lbl ?></span>
              <span class="ps-item-nums"><span class="ps-item-val"><?= $val ?></span><span class="ps-item-pct"><?= $ps_pct ?>%</span></span>
            </span>
            <span class="ps-track"><span class="ps-bar" data-pct="<?= $ps_pct ?>" style="width:0%;"></span></span>
          </button>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/motion@13.4.3/dist/motion.min.js"></script>
<script src="../../assets/js/shared/dash_motion.js?v=<?= filemtime(__DIR__ . '/../../assets/js/shared/dash_motion.js') ?>"></script>
<script src="../../assets/js/shared/dashboard.js?v=<?= filemtime(__DIR__ . '/../../assets/js/shared/dashboard.js') ?>"></script>
<script>
(function () {
  // ── Colours come from the theme (app.css variables), so every theme and dark mode get their own ──
  const css = getComputedStyle(document.documentElement);
  const v = (name, fallback) => (css.getPropertyValue(name) || '').trim() || fallback;
  const C = {
    success: v('--success', '#2e7d52'), gold: v('--gold', '#b8860b'), info: v('--info', '#1a6b9a'),
    danger: v('--danger', '#c0392b'), muted: v('--text-muted', '#888'), text: v('--text-primary', '#222'),
    border: v('--border', 'rgba(0,0,0,0.1)'), panel: v('--bg-2', '#fff'),
  };
  // a colour with some transparency (works for #rgb / #rrggbb and rgb()/rgba())
  const fade = (c, a) => {
    if (c.startsWith('#')) {
      const h = c.length === 4 ? c.slice(1).split('').map((x) => x + x).join('') : c.slice(1, 7);
      return 'rgba(' + parseInt(h.slice(0, 2), 16) + ',' + parseInt(h.slice(2, 4), 16) + ',' + parseInt(h.slice(4, 6), 16) + ',' + a + ')';
    }
    const m = c.match(/[\d.]+/g); return m ? 'rgba(' + m[0] + ',' + m[1] + ',' + m[2] + ',' + a + ')' : c;
  };

  Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";
  Chart.defaults.color = C.muted;
  const tooltip = {
    backgroundColor: C.panel, titleColor: C.text, bodyColor: C.text, borderColor: C.border, borderWidth: 1,
    padding: 10, cornerRadius: 10, displayColors: true, boxPadding: 4, usePointStyle: true,
  };

  // ── Greeting by the computer's own clock ──
  const h = new Date().getHours();
  const greet = document.getElementById('dash-greet');
  if (greet) greet.textContent = h < 12 ? 'Good morning' : h < 18 ? 'Good afternoon' : 'Good evening';

  // ── Motion (assets/js/shared/dash_motion.js): cards rise in, chart cards lift on hover, the payment total
  // counts up, and every chart below is only drawn once it is on screen, so its animation is actually seen ──
  tgRiseInView('.dash-main-grid > .card, .dash-charts-grid > .card, .content > .card');
  tgHoverLift('.dash-charts-grid > .card');
  tgCountUp('.tg-count');

  // ── Payment status bars fill in when their card comes into view ──
  const psBars = document.querySelectorAll('.ps-bar');
  tgWhenInView(psBars.length ? psBars[0].closest('.card') : null, function () {
    setTimeout(function () { psBars.forEach(function (b) { b.style.width = b.dataset.pct + '%'; }); }, 150);
  });

  // ── DOUGHNUT: Client Types, one month at a time (‹ › walks through the last 6 months) ──
  const ctMonths = <?= json_encode(array_values($ct_months)) ?>;
  let ctIdx = ctMonths.length - 1, ctChart = null;
  const ctData = () => [ctMonths[ctIdx].insurance, ctMonths[ctIdx].walkin];
  const setText = (id, t) => { const el = document.getElementById(id); if (el) el.textContent = t; };
  function showMonth() {
    const m = ctMonths[ctIdx], t = m.total || 0;
    setText('ct-month-label', m.label); setText('ct-center-month', m.short); setText('ct-center-year', m.year);
    setText('ct-ins-val', m.insurance); setText('ct-wk-val', m.walkin);
    setText('ct-ins-pct', (t ? Math.round(m.insurance / t * 100) : 0) + '%');
    setText('ct-wk-pct', (t ? Math.round(m.walkin / t * 100) : 0) + '%');
    document.getElementById('ct-prev').disabled = ctIdx === 0;
    document.getElementById('ct-next').disabled = ctIdx === ctMonths.length - 1;
    document.querySelectorAll('.dash-ct-dots span').forEach((d, i) => d.classList.toggle('is-on', i === ctIdx));
    if (ctChart) { ctChart.data.datasets[0].data = ctData(); ctChart.update(); }
  }
  document.getElementById('ct-prev').addEventListener('click', () => { if (ctIdx > 0) { ctIdx--; showMonth(); } });
  document.getElementById('ct-next').addEventListener('click', () => { if (ctIdx < ctMonths.length - 1) { ctIdx++; showMonth(); } });

  tgChartInView('chart-client-types', (animate) => (ctChart = new Chart(document.getElementById('chart-client-types'), {
    type: 'doughnut',
    data: {
      labels: ['Insurance', 'Walk-in'],
      datasets: [{
        data: ctData(),
        backgroundColor: [C.success, C.gold],
        hoverBackgroundColor: [C.success, C.gold],
        borderColor: C.panel, borderWidth: 3, hoverOffset: 8, borderRadius: 6, spacing: 2,
      }]
    },
    options: {
      responsive: true,
      animation: animate ? { duration: 900, easing: 'easeOutQuart' } : false,
      plugins: {
        legend: { display: false },
        tooltip: Object.assign({}, tooltip, { callbacks: { label: (ctx) => {
          const t = ctMonths[ctIdx].total || 1;
          return ' ' + ctx.label + ': ' + ctx.parsed + ' (' + Math.round(ctx.parsed / t * 100) + '%)';
        } } }),
      },
      cutout: '72%',
    }
  })));

  const monthlyPolicyLabels = <?= json_encode(array_column($monthly_policies, 'label')) ?>;
  const monthlyPolicyData   = <?= json_encode(array_column($monthly_policies, 'count')) ?>;
  const monthlyRepairLabels = <?= json_encode(array_column($monthly_repairs, 'label')) ?>;
  const monthlyRepairData   = <?= json_encode(array_column($monthly_repairs, 'count')) ?>;
  const payStatusData       = [<?= $pay_status['Paid'] ?>, <?= $pay_status['Partial'] ?>, <?= $pay_status['Unpaid'] ?>, <?= $pay_status['Overdue'] ?>];

  const barAnim = { duration: 900, easing: 'easeOutQuart', delay: (ctx) => ctx.dataIndex * 70 };
  const pieAnim = { duration: 1000, easing: 'easeOutQuart' };

  // A minimal bar chart: soft gradient bars, the current month in full colour, no axis lines
  function monthBars(canvasId, labels, data, color, name) {
    tgChartInView(canvasId, (animate) => new Chart(document.getElementById(canvasId), {
      type: 'bar',
      data: {
        labels: labels.map((l) => l.split(' ')[0]),
        datasets: [{
          label: name,
          data: data,
          backgroundColor: (ctx) => {
            const last = ctx.dataIndex === data.length - 1;
            const area = ctx.chart.chartArea;
            if (!area) return fade(color, last ? 0.9 : 0.35);
            const g = ctx.chart.ctx.createLinearGradient(0, area.bottom, 0, area.top);
            g.addColorStop(0, fade(color, last ? 0.55 : 0.12));
            g.addColorStop(1, fade(color, last ? 0.95 : 0.45));
            return g;
          },
          hoverBackgroundColor: color,
          borderRadius: 8, borderSkipped: false, maxBarThickness: 34,
        }]
      },
      options: {
        responsive: true,
        animation: animate ? barAnim : false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { display: false },
          tooltip: Object.assign({}, tooltip, { callbacks: { title: (items) => labels[items[0].dataIndex] } }),
        },
        scales: {
          y: { beginAtZero: true, border: { display: false }, ticks: { stepSize: 1, precision: 0, padding: 6 }, grid: { color: fade(C.muted, 0.12), drawTicks: false } },
          x: { border: { display: false }, grid: { display: false }, ticks: { maxRotation: 0, font: { size: 10 } } }
        }
      }
    }));
  }
  monthBars('chart-policies', monthlyPolicyLabels, monthlyPolicyData, C.gold, 'Policies');
  monthBars('chart-repairs', monthlyRepairLabels, monthlyRepairData, C.info, 'Repair Jobs');

  // ── DOUGHNUT: Payment Status, tied to its legend (hover = highlight, click = hide / show) ──
  const psColors = [C.success, C.gold, C.muted, C.danger];
  const psItems  = Array.from(document.querySelectorAll('.ps-item'));
  let psChart = null;
  function psRefreshTotal() {
    if (!psChart) return;
    const shown = payStatusData.reduce((s, n, i) => s + (psChart.getDataVisibility(i) ? n : 0), 0);
    setText('ps-total', shown);
  }
  function psHighlight(i) {
    psItems.forEach((it, k) => it.classList.toggle('is-hot', k === i));
    if (!psChart) return;
    const els = i === null || !psChart.getDataVisibility(i) ? [] : [{ datasetIndex: 0, index: i }];
    psChart.setActiveElements(els);   // the slice only: a tooltip would cover the total in the middle
    psChart.update();
  }
  psItems.forEach((it) => {
    const i = +it.dataset.index;
    it.addEventListener('mouseenter', () => psHighlight(i));
    it.addEventListener('focus', () => psHighlight(i));
    it.addEventListener('mouseleave', () => psHighlight(null));
    it.addEventListener('blur', () => psHighlight(null));
    it.addEventListener('click', () => {
      if (!psChart) return;
      psChart.toggleDataVisibility(i);
      const on = psChart.getDataVisibility(i);
      it.classList.toggle('is-off', !on);
      it.setAttribute('aria-pressed', on ? 'true' : 'false');
      psChart.update();
      psRefreshTotal();
      psHighlight(on ? i : null);
    });
  });

  tgChartInView('chart-payment-status', (animate) => (psChart = new Chart(document.getElementById('chart-payment-status'), {
    type: 'doughnut',
    data: {
      labels: ['Paid', 'Partial', 'Unpaid', 'Overdue'],
      datasets: [{
        data: payStatusData,
        backgroundColor: psColors,
        hoverBackgroundColor: psColors,
        borderColor: C.panel, borderWidth: 3, hoverOffset: 12, borderRadius: 6, spacing: 2,
      }]
    },
    options: {
      responsive: true,
      animation: animate ? pieAnim : false,
      onHover: (e, els) => psItems.forEach((it, k) => it.classList.toggle('is-hot', !!els.length && els[0].index === k)),
      plugins: {
        legend: { display: false },
        tooltip: Object.assign({}, tooltip, { callbacks: { label: (ctx) => {
          const pct = Math.round(ctx.parsed / (payStatusData.reduce((a, b) => a + b, 0) || 1) * 100);
          return ' ' + ctx.label + ': ' + ctx.parsed + ' (' + pct + '%)';
        } } }),
      },
      cutout: '72%',
    }
  })));

  // Team cards open the profile with Enter / Space too (the click is handled in footer.php)
  document.querySelectorAll('.dash-member[data-user-id]').forEach((m) => m.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); m.click(); }
  }));
})();

// Backup for the daily cron job (reminder emails, cleanup), run in the background after the page loads
window.addEventListener('load', function () {
  var fd = new FormData();
  fd.append('csrf_token', <?= json_encode(csrf_token()) ?>);
  fetch('../../ajax/daily_jobs.php', { method: 'POST', body: fd }).catch(function () {});
});
</script>

<?php require_once '../../includes/footer.php'; ?>
