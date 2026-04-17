<?php
require_once __DIR__ . '/../../config/session.php';
require_once '../../config/db.php';
require_once '../../config/validators.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header("Location: ../../auth/login.php");
    exit;
}

// ── FILTER: year (default current year) ──
$available_years = [];
$yr = $conn->query("SELECT DISTINCT YEAR(created_at) as y FROM clients ORDER BY y DESC");
while ($r = $yr->fetch_assoc()) $available_years[] = (int)$r['y'];
if (empty($available_years)) $available_years = [(int)date('Y')];

$sel_year = san_int($_GET['year'] ?? date('Y'), 2000, 2100);
if (!in_array($sel_year, $available_years)) $sel_year = $available_years[0];

// ── BUILD MONTHLY DATA for selected year ──
$months_data = [];
for ($m = 1; $m <= 12; $m++) {
    $label = date('F', mktime(0,0,0,$m,1));
    $short = date('M',  mktime(0,0,0,$m,1));

    $ins = (int)$conn->query("
        SELECT COUNT(DISTINCT c.client_id) as c FROM clients c
        INNER JOIN insurance_policies ip ON ip.client_id = c.client_id
        WHERE YEAR(c.created_at)='$sel_year' AND MONTH(c.created_at)='$m'
    ")->fetch_assoc()['c'];

    $wk = (int)$conn->query("
        SELECT COUNT(*) as c FROM clients c
        LEFT JOIN insurance_policies ip ON ip.client_id = c.client_id
        WHERE ip.policy_id IS NULL
        AND YEAR(c.created_at)='$sel_year' AND MONTH(c.created_at)='$m'
    ")->fetch_assoc()['c'];

    $policies = (int)$conn->query("
        SELECT COUNT(*) as c FROM insurance_policies
        WHERE YEAR(created_at)='$sel_year' AND MONTH(created_at)='$m'
    ")->fetch_assoc()['c'];

    $repairs = (int)$conn->query("
        SELECT COUNT(*) as c FROM repair_jobs
        WHERE YEAR(created_at)='$sel_year' AND MONTH(created_at)='$m'
    ")->fetch_assoc()['c'];

    $months_data[] = [
        'month'     => $m,
        'label'     => $label,
        'short'     => $short,
        'insurance' => $ins,
        'walkin'    => $wk,
        'total'     => $ins + $wk,
        'policies'  => $policies,
        'repairs'   => $repairs,
    ];
}

// ── YEAR TOTALS ──
$year_ins      = array_sum(array_column($months_data, 'insurance'));
$year_wk       = array_sum(array_column($months_data, 'walkin'));
$year_clients  = $year_ins + $year_wk;
$year_policies = array_sum(array_column($months_data, 'policies'));
$year_repairs  = array_sum(array_column($months_data, 'repairs'));

$page_title  = 'Monthly Report';
$active_page = 'monthly_report';
$base_path   = '../../';
$extra_css   = '<link rel="stylesheet" href="' . $base_path . 'assets/css/dashboard.css?v=' . filemtime(__DIR__ . '/../../assets/css/dashboard.css') . '"/>';
require_once '../../includes/header.php';
require_once '../../includes/navbar.php';
?>

<div class="main">

<?php
$topbar_title      = 'Monthly Report';
$topbar_breadcrumb = ['Dashboard', 'Monthly Report'];
require_once '../../includes/topbar.php';
?>

<div class="content">

  <!-- YEAR FILTER -->
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;">
    <div>
      <div style="font-size:1rem;font-weight:700;color:var(--text-primary);">Annual Overview</div>
      <div style="font-size:0.78rem;color:var(--text-muted);margin-top:0.1rem;">Client types, policies, and repairs by month</div>
    </div>
    <form method="GET" style="display:flex;align-items:center;gap:0.5rem;">
      <label style="font-size:0.8rem;color:var(--text-muted);font-weight:500;">Year</label>
      <select name="year" onchange="this.form.submit()" style="padding:0.4rem 0.75rem;border:1px solid var(--border);border-radius:8px;background:var(--bg-2);color:var(--text-primary);font-size:0.82rem;cursor:pointer;">
        <?php foreach ($available_years as $y): ?>
        <option value="<?= $y ?>" <?= $y === $sel_year ? 'selected' : '' ?>><?= $y ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>

  <!-- YEAR SUMMARY CARDS -->
  <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.25rem;">
    <?php
    $summary = [
      ['Total Clients',    $year_clients,  'user',         'gold',  $sel_year . ' total'],
      ['Insurance',        $year_ins,      'shield-check', 'green', round($year_clients > 0 ? $year_ins/$year_clients*100 : 0) . '% of clients'],
      ['Walk-in',          $year_wk,       'wrench',       'amber', round($year_clients > 0 ? $year_wk/$year_clients*100 : 0) . '% of clients'],
      ['Policies Created', $year_policies, 'document',     'blue',  $year_repairs . ' repair jobs'],
    ];
    $theme_map = [
      'gold'  => ['accent'=>'linear-gradient(90deg,#D4A017,#E8D5A3)','icon_bg'=>'var(--gold-light)','icon_color'=>'var(--gold)'],
      'green' => ['accent'=>'linear-gradient(90deg,#2E7D52,#52B788)','icon_bg'=>'var(--success-bg)','icon_color'=>'var(--success)'],
      'amber' => ['accent'=>'linear-gradient(90deg,#B8860B,#D4A017)','icon_bg'=>'var(--warning-bg)','icon_color'=>'var(--warning)'],
      'blue'  => ['accent'=>'linear-gradient(90deg,#1A6B9A,#3498DB)','icon_bg'=>'var(--info-bg)',   'icon_color'=>'var(--info)'],
    ];
    foreach ($summary as [$lbl, $val, $ico, $theme, $sub]):
      $t = $theme_map[$theme];
    ?>
    <div class="dash-stat">
      <div class="dash-stat-accent" style="background:<?= $t['accent'] ?>;"></div>
      <div class="dash-stat-top">
        <div class="dash-stat-icon" style="background:<?= $t['icon_bg'] ?>;color:<?= $t['icon_color'] ?>;"><?= icon($ico, 18) ?></div>
        <span class="dash-stat-badge" style="background:var(--bg);color:var(--text-muted);border:1px solid var(--border);"><?= $sub ?></span>
      </div>
      <div class="dash-stat-value"><?= $val ?></div>
      <div class="dash-stat-label"><?= $lbl ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- CHARTS ROW -->
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.25rem;">

    <!-- Client Types Bar Chart -->
    <div class="card" style="margin-bottom:0;">
      <div class="card-header">
        <div class="card-icon"><?= icon('users', 16) ?></div>
        <div>
          <div class="card-title">Client Types per Month</div>
          <div class="card-sub"><?= $sel_year ?> — Insurance vs Walk-in</div>
        </div>
      </div>
      <div style="padding:1rem 1.25rem 1.25rem;">
        <canvas id="chart-ct-monthly" height="160"></canvas>
      </div>
    </div>

    <!-- Policies + Repairs Bar Chart -->
    <div class="card" style="margin-bottom:0;">
      <div class="card-header">
        <div class="card-icon"><?= icon('document', 16) ?></div>
        <div>
          <div class="card-title">Policies &amp; Repairs per Month</div>
          <div class="card-sub"><?= $sel_year ?></div>
        </div>
      </div>
      <div style="padding:1rem 1.25rem 1.25rem;">
        <canvas id="chart-pr-monthly" height="160"></canvas>
      </div>
    </div>

  </div>

  <!-- MONTHLY BREAKDOWN TABLE -->
  <div class="card" style="margin-bottom:0;">
    <div class="card-header">
      <div class="card-icon"><?= icon('clipboard-list', 16) ?></div>
      <div>
        <div class="card-title">Monthly Breakdown</div>
        <div class="card-sub"><?= $sel_year ?> — all 12 months</div>
      </div>
    </div>
    <div style="overflow-x:auto;">
      <table style="width:100%;border-collapse:collapse;font-size:0.82rem;">
        <thead>
          <tr style="border-bottom:2px solid var(--border);">
            <th style="padding:0.75rem 1.25rem;text-align:left;color:var(--text-muted);font-weight:600;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.5px;">Month</th>
            <th style="padding:0.75rem 1rem;text-align:center;color:var(--text-muted);font-weight:600;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.5px;">Total Clients</th>
            <th style="padding:0.75rem 1rem;text-align:center;color:#2E7D52;font-weight:600;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.5px;">Insurance</th>
            <th style="padding:0.75rem 1rem;text-align:center;color:#B8860B;font-weight:600;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.5px;">Walk-in</th>
            <th style="padding:0.75rem 1rem;text-align:center;color:#1A6B9A;font-weight:600;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.5px;">Policies</th>
            <th style="padding:0.75rem 1rem;text-align:center;color:#7B3FA0;font-weight:600;font-size:0.72rem;text-transform:uppercase;letter-spacing:0.5px;">Repairs</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $cur_m = (int)date('m');
          $cur_y = (int)date('Y');
          foreach ($months_data as $row):
            $is_current = ($row['month'] === $cur_m && $sel_year === $cur_y);
            $is_future  = ($sel_year === $cur_y && $row['month'] > $cur_m);
            $row_style  = $is_current ? 'background:rgba(212,160,23,0.06);' : '';
          ?>
          <tr style="border-bottom:1px solid var(--border);<?= $row_style ?>">
            <td style="padding:0.75rem 1.25rem;">
              <div style="display:flex;align-items:center;gap:0.5rem;">
                <span style="font-weight:<?= $is_current ? '700' : '500' ?>;color:var(--text-primary);"><?= $row['label'] ?></span>
                <?php if ($is_current): ?>
                <span style="font-size:0.62rem;background:rgba(212,160,23,0.15);color:var(--gold);border-radius:4px;padding:0.1rem 0.4rem;font-weight:600;">Current</span>
                <?php endif; ?>
              </div>
            </td>
            <td style="padding:0.75rem 1rem;text-align:center;">
              <?php if ($is_future): ?>
              <span style="color:var(--text-muted);font-size:0.75rem;">—</span>
              <?php else: ?>
              <span style="font-weight:600;color:var(--text-primary);"><?= $row['total'] ?></span>
              <?php endif; ?>
            </td>
            <td style="padding:0.75rem 1rem;text-align:center;">
              <?php if ($is_future): ?>
              <span style="color:var(--text-muted);font-size:0.75rem;">—</span>
              <?php else: ?>
              <span style="color:#2E7D52;font-weight:600;"><?= $row['insurance'] ?></span>
              <?php endif; ?>
            </td>
            <td style="padding:0.75rem 1rem;text-align:center;">
              <?php if ($is_future): ?>
              <span style="color:var(--text-muted);font-size:0.75rem;">—</span>
              <?php else: ?>
              <span style="color:#B8860B;font-weight:600;"><?= $row['walkin'] ?></span>
              <?php endif; ?>
            </td>
            <td style="padding:0.75rem 1rem;text-align:center;">
              <?php if ($is_future): ?>
              <span style="color:var(--text-muted);font-size:0.75rem;">—</span>
              <?php else: ?>
              <span style="color:#1A6B9A;font-weight:600;"><?= $row['policies'] ?></span>
              <?php endif; ?>
            </td>
            <td style="padding:0.75rem 1rem;text-align:center;">
              <?php if ($is_future): ?>
              <span style="color:var(--text-muted);font-size:0.75rem;">—</span>
              <?php else: ?>
              <span style="color:#7B3FA0;font-weight:600;"><?= $row['repairs'] ?></span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr style="border-top:2px solid var(--border);background:var(--bg-2);">
            <td style="padding:0.75rem 1.25rem;font-weight:700;color:var(--text-primary);">Total <?= $sel_year ?></td>
            <td style="padding:0.75rem 1rem;text-align:center;font-weight:700;color:var(--text-primary);"><?= $year_clients ?></td>
            <td style="padding:0.75rem 1rem;text-align:center;font-weight:700;color:#2E7D52;"><?= $year_ins ?></td>
            <td style="padding:0.75rem 1rem;text-align:center;font-weight:700;color:#B8860B;"><?= $year_wk ?></td>
            <td style="padding:0.75rem 1rem;text-align:center;font-weight:700;color:#1A6B9A;"><?= $year_policies ?></td>
            <td style="padding:0.75rem 1rem;text-align:center;font-weight:700;color:#7B3FA0;"><?= $year_repairs ?></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>

</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function () {
  Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";
  Chart.defaults.color = '#888';
  const gridColor = 'rgba(255,255,255,0.06)';
  const barAnim   = { duration: 900, easing: 'easeOutQuart', delay: (ctx) => ctx.dataIndex * 60 };

  const labels   = <?= json_encode(array_column($months_data, 'short')) ?>;
  const insData  = <?= json_encode(array_column($months_data, 'insurance')) ?>;
  const wkData   = <?= json_encode(array_column($months_data, 'walkin')) ?>;
  const polData  = <?= json_encode(array_column($months_data, 'policies')) ?>;
  const repData  = <?= json_encode(array_column($months_data, 'repairs')) ?>;

  // ── Client Types grouped bar ──
  new Chart(document.getElementById('chart-ct-monthly'), {
    type: 'bar',
    data: {
      labels: labels,
      datasets: [
        {
          label: 'Insurance',
          data: insData,
          backgroundColor: 'rgba(46,125,82,0.8)',
          borderColor: '#2E7D52',
          borderWidth: 1.5,
          borderRadius: 5,
        },
        {
          label: 'Walk-in',
          data: wkData,
          backgroundColor: 'rgba(184,134,11,0.8)',
          borderColor: '#B8860B',
          borderWidth: 1.5,
          borderRadius: 5,
        }
      ]
    },
    options: {
      responsive: true,
      animation: barAnim,
      plugins: {
        legend: {
          display: true,
          position: 'bottom',
          labels: { boxWidth: 10, padding: 16, font: { size: 11 } }
        }
      },
      scales: {
        y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 }, grid: { color: gridColor } },
        x: { grid: { display: false }, ticks: { maxRotation: 0, font: { size: 10 } } }
      }
    }
  });

  // ── Policies + Repairs grouped bar ──
  new Chart(document.getElementById('chart-pr-monthly'), {
    type: 'bar',
    data: {
      labels: labels,
      datasets: [
        {
          label: 'Policies',
          data: polData,
          backgroundColor: 'rgba(26,107,154,0.8)',
          borderColor: '#1A6B9A',
          borderWidth: 1.5,
          borderRadius: 5,
        },
        {
          label: 'Repairs',
          data: repData,
          backgroundColor: 'rgba(123,63,160,0.8)',
          borderColor: '#7B3FA0',
          borderWidth: 1.5,
          borderRadius: 5,
        }
      ]
    },
    options: {
      responsive: true,
      animation: barAnim,
      plugins: {
        legend: {
          display: true,
          position: 'bottom',
          labels: { boxWidth: 10, padding: 16, font: { size: 11 } }
        }
      },
      scales: {
        y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 }, grid: { color: gridColor } },
        x: { grid: { display: false }, ticks: { maxRotation: 0, font: { size: 10 } } }
      }
    }
  });
})();
</script>

<?php require_once '../../includes/footer.php'; ?>
