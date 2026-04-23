<?php
require_once __DIR__ . '/../../config/session.php';
require_once '../../config/db.php';
require_once '../../config/settings.php';

$urg_days = (int)getSetting($conn, 'renewal_urgent_days', '7');
$exp_days = (int)getSetting($conn, 'renewal_expiring_days', '30');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header("Location: ../../auth/login.php");
    exit;
}

$full_name = $_SESSION['full_name'];
$first_name = explode(' ', $full_name)[0];

// ── STATS QUERIES ──
$total_clients  = $conn->query("SELECT COUNT(*) as c FROM clients")->fetch_assoc()['c'];
$total_vehicles = $conn->query("SELECT COUNT(*) as c FROM vehicles")->fetch_assoc()['c'];
$recent_clients  = $conn->query("SELECT COUNT(*) as c FROM clients WHERE YEAR(created_at)=YEAR(NOW()) AND MONTH(created_at)=MONTH(NOW())")->fetch_assoc()['c'];
$recent_vehicles = $conn->query("SELECT COUNT(*) as c FROM vehicles WHERE YEAR(created_at)=YEAR(NOW()) AND MONTH(created_at)=MONTH(NOW())")->fetch_assoc()['c'];

$total_policies   = $conn->query("SELECT COUNT(*) as c FROM insurance_policies WHERE is_renewed = 0")->fetch_assoc()['c'];
$active_policies  = $conn->query("SELECT COUNT(*) as c FROM insurance_policies WHERE is_renewed = 0 AND policy_end >= CURDATE()")->fetch_assoc()['c'];
$es_stmt = $conn->prepare("SELECT COUNT(*) as c FROM insurance_policies WHERE is_renewed = 0 AND DATEDIFF(policy_end, CURDATE()) BETWEEN 0 AND ?");
$es_stmt->bind_param('i', $exp_days);
$es_stmt->execute();
$expiring_soon = $es_stmt->get_result()->fetch_assoc()['c'];

$up_stmt = $conn->prepare("SELECT COUNT(*) as c FROM insurance_policies WHERE is_renewed = 0 AND DATEDIFF(policy_end, CURDATE()) BETWEEN 0 AND ?");
$up_stmt->bind_param('i', $urg_days);
$up_stmt->execute();
$urgent_policies = $up_stmt->get_result()->fetch_assoc()['c'];

// ── RENEWAL ALERTS (policies expiring within 30 days) ──
$rn_stmt = $conn->prepare("
    SELECT p.policy_id, p.policy_number, p.policy_end, p.coverage_type,
           DATEDIFF(p.policy_end, CURDATE()) as days_left,
           c.full_name, v.plate_number, v.make, v.model
    FROM insurance_policies p
    INNER JOIN vehicles v ON p.vehicle_id = v.vehicle_id
    INNER JOIN clients c ON p.client_id = c.client_id
    WHERE p.is_renewed = 0 AND DATEDIFF(p.policy_end, CURDATE()) BETWEEN 0 AND ?
    ORDER BY p.policy_end ASC
    LIMIT 6
");
$rn_stmt->bind_param('i', $exp_days);
$rn_stmt->execute();
$renewals = $rn_stmt->get_result();

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

// ── CHARTS DATA ──

// Client type breakdown — last 6 months (for JS navigator)
$ct_months = [];
for ($m = 5; $m >= 0; $m--) {
    $y  = date('Y', strtotime("-$m months"));
    $mo = date('m', strtotime("-$m months"));
    $ins = (int)$conn->query("SELECT COUNT(DISTINCT c.client_id) as c FROM clients c INNER JOIN insurance_policies ip ON ip.client_id = c.client_id WHERE YEAR(c.created_at)='$y' AND MONTH(c.created_at)='$mo'")->fetch_assoc()['c'];
    $wk  = (int)$conn->query("SELECT COUNT(*) as c FROM clients c LEFT JOIN insurance_policies ip ON ip.client_id = c.client_id WHERE ip.policy_id IS NULL AND YEAR(c.created_at)='$y' AND MONTH(c.created_at)='$mo'")->fetch_assoc()['c'];
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
    $label    = date('M Y', strtotime("-$m months"));
    $y        = date('Y', strtotime("-$m months"));
    $mo       = date('m', strtotime("-$m months"));
    $res      = $conn->query("SELECT COUNT(*) as c FROM insurance_policies WHERE YEAR(created_at)='$y' AND MONTH(created_at)='$mo'");
    $monthly_policies[] = ['label' => $label, 'count' => (int)$res->fetch_assoc()['c']];
}

// Payment status breakdown
$pay_status_res = $conn->query("
    SELECT payment_status, COUNT(*) as c
    FROM insurance_policies
    GROUP BY payment_status
");
$pay_status = ['Paid' => 0, 'Partial' => 0, 'Unpaid' => 0, 'Overdue' => 0];
while ($ps = $pay_status_res->fetch_assoc()) {
    $pay_status[$ps['payment_status']] = (int)$ps['c'];
}

// Repair jobs per month (last 6 months)
$monthly_repairs = [];
for ($m = 5; $m >= 0; $m--) {
    $label = date('M Y', strtotime("-$m months"));
    $y     = date('Y', strtotime("-$m months"));
    $mo    = date('m', strtotime("-$m months"));
    $res   = $conn->query("SELECT COUNT(*) as c FROM repair_jobs WHERE YEAR(created_at)='$y' AND MONTH(created_at)='$mo'");
    $monthly_repairs[] = ['label' => $label, 'count' => (int)$res->fetch_assoc()['c']];
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

    <!-- MAIN GRID: 3 equal columns -->
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1.25rem;margin-bottom:1.25rem;">

      <!-- COL 1: RENEWAL ALERTS -->
      <div class="card" style="margin-bottom:0;max-height:320px;overflow:hidden;display:flex;flex-direction:column;">
          <div class="card-header" style="justify-content:space-between;">
            <div style="display:flex;align-items:center;gap:0.75rem;">
              <div class="card-icon"><?= icon('clock', 16) ?></div>
              <div>
                <div class="card-title">Renewal Alerts</div>
                <div class="card-sub">Policies expiring within <?= $exp_days ?> days</div>
              </div>
            </div>
            <?php if ($expiring_soon > 0): ?>
            <a href="../renewal/renewal_list.php?filter=expiring" class="btn-sm-gold">
              View All <?= icon('chevron-right', 12) ?>
            </a>
            <?php endif; ?>
          </div>
          <div style="overflow-y:auto;flex:1;">
          <?php if ($renewals->num_rows > 0): ?>
            <?php while ($r = $renewals->fetch_assoc()):
              $is_urgent = $r['days_left'] <= $urg_days;
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
              <div class="renewal-days <?= $is_urgent ? 'urgent' : 'expiring' ?>">
                <?php if ($r['days_left'] == 0): ?>Expires today
                <?php elseif ($r['days_left'] == 1): ?>1 day left
                <?php else: ?><?= $r['days_left'] ?> days left<?php endif; ?>
              </div>
            </a>
            <?php endwhile; ?>
          <?php else: ?>
            <div class="empty-state" style="padding:2rem;">
              <div style="opacity:0.3;margin-bottom:0.4rem;"><?= icon('check-circle', 28) ?></div>
              <div class="empty-title">All clear</div>
              <div class="empty-desc">No policies expiring within <?= $exp_days ?> days.</div>
            </div>
          <?php endif; ?>
          </div>
      </div>

      <!-- COL 2: QUICK ACTIONS -->
      <div class="card" style="margin-bottom:0;max-height:320px;overflow:hidden;display:flex;flex-direction:column;">
        <div class="card-header">
          <div class="card-icon"><?= icon('arrow-right', 16) ?></div>
          <div>
            <div class="card-title">Quick Actions</div>
            <div class="card-sub">Common tasks</div>
          </div>
        </div>
        <div style="display:flex;flex-direction:column;gap:0.5rem;padding:1rem;overflow-y:auto;flex:1;">
          <?php
          $actions = [
            ['../clients/add_client.php',          'user-plus',        'Add New Client',       'Client and vehicle registration',  '#2E7D52', 'rgba(46,125,82,0.12)'],
            ['../insurance/eligibility_check.php', 'shield-check',     'New Insurance Policy', 'Check eligibility and encode',     '#1A6B9A', 'rgba(26,107,154,0.12)'],
            ['../clients/client_list.php',         'magnifying-glass', 'Search Records',       'Find client, vehicle, or policy',  '#7B3FA0', 'rgba(123,63,160,0.12)'],
            ['../renewal/renewal_list.php',        'clock',            'Renewal Tracking',     'View policy expiry status',        '#B8860B', 'rgba(184,134,11,0.12)'],
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

      <!-- COL 3: RECENT ACTIVITY -->
      <div class="card" style="margin-bottom:0;max-height:320px;overflow:hidden;display:flex;flex-direction:column;">
        <div class="card-header" style="justify-content:space-between;">
          <div style="display:flex;align-items:center;gap:0.75rem;">
            <div class="card-icon"><?= icon('clipboard-list', 16) ?></div>
            <div>
              <div class="card-title">Recent Activity</div>
              <div class="card-sub">Latest system events</div>
            </div>
          </div>
          <?php if ($_SESSION['role'] === 'super_admin'): ?>
          <a href="activity_log.php" class="btn-sm-gold">Full Log <?= icon('chevron-right', 12) ?></a>
          <?php endif; ?>
        </div>
        <div style="overflow-y:auto;flex:1;">
        <?php if (empty($activity_items)): ?>
        <div style="padding:2rem;text-align:center;color:var(--text-muted);font-size:0.8rem;">No recent activity.</div>
        <?php else: ?>
          <?php
          $dot_colors = [
            'LOGIN'=>'var(--success)','LOGOUT'=>'var(--text-muted)',
            'ACCOUNT_CREATED'=>'var(--gold-bright)','ACCOUNT_DELETED'=>'var(--danger)',
            'PASSWORD_RESET'=>'var(--warning)','CLIENT_ADDED'=>'var(--success)',
            'CLIENT_UPDATED'=>'var(--warning)','VEHICLE_ADDED'=>'var(--success)',
            'POLICY_CREATED'=>'var(--gold-bright)','POLICY_SAVED'=>'var(--success)',
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

    </div>


    <!-- CHARTS ROW: 3 equal columns -->
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1.25rem;margin-bottom:1.25rem;">

      <!-- Client Types PIE -->
      <div class="card" style="margin-bottom:0;">
        <div class="card-header" style="justify-content:space-between;">
          <div style="display:flex;align-items:center;gap:0.75rem;">
            <div class="card-icon"><?= icon('users', 16) ?></div>
            <div>
              <div class="card-title">Client Types</div>
              <div class="card-sub" id="ct-month-label"><?= $cur_ct['label'] ?></div>
            </div>
          </div>
          <a href="../admin/monthly_report.php" class="btn-sm-gold">Full Report <?= icon('chevron-right', 12) ?></a>
        </div>
        <div style="padding:1rem 1.25rem;display:flex;flex-direction:column;align-items:center;gap:1rem;">
          <div style="position:relative;width:180px;height:180px;">
            <canvas id="chart-client-types"></canvas>
            <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;pointer-events:none;">
              <div id="ct-center-month" style="font-size:1.1rem;font-weight:800;color:var(--text-primary);line-height:1;"><?= $cur_ct['short'] ?></div>
              <div id="ct-center-year"  style="font-size:0.58rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;font-weight:600;margin-top:0.15rem;"><?= $cur_ct['year'] ?></div>
            </div>
          </div>
          <div style="display:flex;flex-direction:column;gap:0.5rem;width:100%;">
            <div style="display:flex;align-items:center;justify-content:space-between;">
              <div style="display:flex;align-items:center;gap:0.4rem;">
                <div style="width:8px;height:8px;border-radius:50%;background:#2E7D52;flex-shrink:0;"></div>
                <span style="font-size:0.75rem;color:var(--text-secondary);">Insurance</span>
              </div>
              <div style="display:flex;align-items:center;gap:0.5rem;">
                <span id="ct-ins-val" style="font-size:0.72rem;color:var(--text-muted);"><?= $cur_ct['insurance'] ?></span>
                <span id="ct-ins-pct" style="font-size:0.75rem;font-weight:700;color:#2E7D52;min-width:32px;text-align:right;"><?= $cur_ct['total'] > 0 ? round($cur_ct['insurance']/$cur_ct['total']*100) : 0 ?>%</span>
              </div>
            </div>
            <div style="display:flex;align-items:center;justify-content:space-between;">
              <div style="display:flex;align-items:center;gap:0.4rem;">
                <div style="width:8px;height:8px;border-radius:50%;background:#B8860B;flex-shrink:0;"></div>
                <span style="font-size:0.75rem;color:var(--text-secondary);">Walk-in</span>
              </div>
              <div style="display:flex;align-items:center;gap:0.5rem;">
                <span id="ct-wk-val"  style="font-size:0.72rem;color:var(--text-muted);"><?= $cur_ct['walkin'] ?></span>
                <span id="ct-wk-pct"  style="font-size:0.75rem;font-weight:700;color:#B8860B;min-width:32px;text-align:right;"><?= $cur_ct['total'] > 0 ? round($cur_ct['walkin']/$cur_ct['total']*100) : 0 ?>%</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Policies per Month BAR -->
      <div class="card" style="margin-bottom:0;">
        <div class="card-header">
          <div class="card-icon"><?= icon('document', 16) ?></div>
          <div>
            <div class="card-title">Policies Added</div>
            <div class="card-sub">Last 6 months</div>
          </div>
        </div>
        <div style="padding:1rem 1.25rem 1.25rem;">
          <canvas id="chart-policies" height="160"></canvas>
        </div>
      </div>

      <!-- Repair Jobs per Month BAR -->
      <div class="card" style="margin-bottom:0;">
        <div class="card-header">
          <div class="card-icon"><?= icon('wrench', 16) ?></div>
          <div>
            <div class="card-title">Repair Jobs</div>
            <div class="card-sub">Last 6 months</div>
          </div>
        </div>
        <div style="padding:1rem 1.25rem 1.25rem;">
          <canvas id="chart-repairs" height="160"></canvas>
        </div>
      </div>

    </div>

    <!-- PAYMENT STATUS — full width -->
    <div class="card" style="margin-bottom:0;">
      <div class="card-header">
        <div class="card-icon"><?= icon('receipt', 16) ?></div>
        <div>
          <div class="card-title">Payment Status Breakdown</div>
          <div class="card-sub">All policies — <?= array_sum($pay_status) ?> total</div>
        </div>
      </div>
      <div style="padding:1.5rem;display:grid;grid-template-columns:220px 1fr;gap:2.5rem;align-items:center;">
        <!-- Doughnut -->
        <div style="position:relative;width:220px;height:220px;flex-shrink:0;">
          <canvas id="chart-payment-status"></canvas>
          <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;pointer-events:none;">
            <div style="font-size:1.8rem;font-weight:800;color:var(--text-primary);line-height:1;"><?= array_sum($pay_status) ?></div>
            <div style="font-size:0.6rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;font-weight:600;margin-top:0.2rem;">Policies</div>
          </div>
        </div>
        <!-- Legend bars -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.85rem 2rem;">
          <?php
          $ps_items = [
            ['Paid',    $pay_status['Paid'],    '#2E7D52', 'linear-gradient(90deg,#2E7D52,#52B788)'],
            ['Partial', $pay_status['Partial'], '#B8860B', 'linear-gradient(90deg,#B8860B,#D4A017)'],
            ['Unpaid',  $pay_status['Unpaid'],  '#6B7280', 'linear-gradient(90deg,#4B5563,#6B7280)'],
            ['Overdue', $pay_status['Overdue'], '#C0392B', 'linear-gradient(90deg,#C0392B,#E74C3C)'],
          ];
          $ps_total = max(1, array_sum($pay_status));
          foreach ($ps_items as [$lbl, $val, $clr, $grad]):
            $pct = round($val / $ps_total * 100);
          ?>
          <div>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.3rem;">
              <div style="display:flex;align-items:center;gap:0.4rem;">
                <div style="width:8px;height:8px;border-radius:50%;background:<?= $clr ?>;flex-shrink:0;"></div>
                <span style="font-size:0.78rem;font-weight:600;color:var(--text-primary);"><?= $lbl ?></span>
              </div>
              <div style="display:flex;align-items:center;gap:0.5rem;">
                <span style="font-size:0.7rem;color:var(--text-muted);"><?= $val ?></span>
                <span style="font-size:0.78rem;font-weight:700;color:<?= $clr ?>;min-width:34px;text-align:right;"><?= $pct ?>%</span>
              </div>
            </div>
            <div style="height:5px;background:var(--bg-2);border-radius:3px;overflow:hidden;">
              <div class="ps-bar" data-pct="<?= $pct ?>" style="height:100%;background:<?= $grad ?>;border-radius:3px;width:0%;transition:width 1s cubic-bezier(.4,0,.2,1);"></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="../../assets/js/shared/dashboard.js"></script>
<script>
(function () {
  Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";
  Chart.defaults.color = '#888';
  const gridColor = 'rgba(255,255,255,0.06)';

  // ── Animate payment status bars ──
  setTimeout(function() {
    document.querySelectorAll('.ps-bar').forEach(function(b) {
      b.style.width = b.dataset.pct + '%';
    });
  }, 200);

  // ── CLIENT TYPES: month navigator ──
  // ── DOUGHNUT: Client Types (current month) ──
  new Chart(document.getElementById('chart-client-types'), {
    type: 'doughnut',
    data: {
      labels: ['Insurance', 'Walk-in'],
      datasets: [{
        data: [<?= (int)$insurance_clients ?>, <?= (int)$walkin_clients ?>],
        backgroundColor: ['#2E7D52', '#B8860B'],
        hoverBackgroundColor: ['#3aa366', '#D4A017'],
        borderColor: 'transparent',
        borderWidth: 4,
        hoverOffset: 10,
        borderRadius: 4,
      }]
    },
    options: {
      responsive: true,
      animation: { duration: 1000, easing: 'easeOutQuart' },
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: (ctx) => {
              const t = (<?= (int)$month_clients_total ?>) || 1;
              const pct = Math.round(ctx.parsed / t * 100);
              return ' ' + ctx.label + ': ' + ctx.parsed + ' (' + pct + '%)';
            }
          }
        }
      },
      cutout: '68%',
    }
  });

  const monthlyPolicyLabels = <?= json_encode(array_column($monthly_policies, 'label')) ?>;
  const monthlyPolicyData   = <?= json_encode(array_column($monthly_policies, 'count')) ?>;
  const monthlyRepairLabels = <?= json_encode(array_column($monthly_repairs, 'label')) ?>;
  const monthlyRepairData   = <?= json_encode(array_column($monthly_repairs, 'count')) ?>;
  const payStatusData       = [<?= $pay_status['Paid'] ?>, <?= $pay_status['Partial'] ?>, <?= $pay_status['Unpaid'] ?>, <?= $pay_status['Overdue'] ?>];

  const barAnim = { duration: 900, easing: 'easeOutQuart', delay: (ctx) => ctx.dataIndex * 80 };
  const pieAnim = { duration: 1000, easing: 'easeOutQuart' };

  // ── BAR: Policies per Month ──
  new Chart(document.getElementById('chart-policies'), {
    type: 'bar',
    data: {
      labels: monthlyPolicyLabels,
      datasets: [{
        label: 'Policies',
        data: monthlyPolicyData,
        backgroundColor: 'rgba(212,160,23,0.75)',
        borderColor: '#D4A017',
        borderWidth: 1.5,
        borderRadius: 6,
      }]
    },
    options: {
      responsive: true,
      animation: barAnim,
      plugins: { legend: { display: false } },
      scales: {
        y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 }, grid: { color: gridColor } },
        x: { grid: { display: false }, ticks: { maxRotation: 0, font: { size: 10 } } }
      }
    }
  });

  // ── BAR: Repair Jobs per Month ──
  new Chart(document.getElementById('chart-repairs'), {
    type: 'bar',
    data: {
      labels: monthlyRepairLabels,
      datasets: [{
        label: 'Repair Jobs',
        data: monthlyRepairData,
        backgroundColor: 'rgba(52,152,219,0.75)',
        borderColor: '#3498DB',
        borderWidth: 1.5,
        borderRadius: 6,
      }]
    },
    options: {
      responsive: true,
      animation: barAnim,
      plugins: { legend: { display: false } },
      scales: {
        y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 }, grid: { color: gridColor } },
        x: { grid: { display: false }, ticks: { maxRotation: 0, font: { size: 10 } } }
      }
    }
  });

  // ── DOUGHNUT: Payment Status ──
  new Chart(document.getElementById('chart-payment-status'), {
    type: 'doughnut',
    data: {
      labels: ['Paid', 'Partial', 'Unpaid', 'Overdue'],
      datasets: [{
        data: payStatusData,
        backgroundColor: ['#2E7D52', '#B8860B', '#6B7280', '#C0392B'],
        hoverBackgroundColor: ['#3aa366', '#D4A017', '#9CA3AF', '#e74c3c'],
        borderColor: 'transparent',
        borderWidth: 4,
        hoverOffset: 12,
        borderRadius: 4,
      }]
    },
    options: {
      responsive: true,
      animation: pieAnim,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: (ctx) => {
              const pct = Math.round(ctx.parsed / (payStatusData.reduce((a,b)=>a+b,0)||1) * 100);
              return ' ' + ctx.label + ': ' + ctx.parsed + ' (' + pct + '%)';
            }
          }
        }
      },
      cutout: '68%',
    }
  });
})();
</script>

<?php require_once '../../includes/footer.php'; ?>
