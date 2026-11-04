<?php
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/data_access.php';
require_once '../../config/db.php';
require_once '../../config/validators.php';

require_role(['admin', 'super_admin']);

// ── FILTER: year (default current year) ──
// Build year range: from earliest data year (or current-2) up to current year
$current_year = (int)date('Y');
$earliest_year = report_first_year($conn) ?? $current_year;
$range_start   = min($earliest_year, $current_year - 2);

$available_years = [];
for ($y = $current_year; $y >= $range_start; $y--) {
    $available_years[] = $y;
}

$sel_year = san_int($_GET['year'] ?? $current_year, 2000, 2100);
// Allow any year in valid range, not just data-bearing years
if ($sel_year < $range_start || $sel_year > $current_year) $sel_year = $current_year;

// ── BUILD MONTHLY DATA for selected year ──
$months_data = [];
for ($m = 1; $m <= 12; $m++) {
    $label = date('F', mktime(0,0,0,$m,1));
    $short = date('M',  mktime(0,0,0,$m,1));

    // Stats rule (inside the function): a deleted client still counts unless deleted in the month it was added
    [$ins, $wk] = report_client_types_in_month($conn, $sel_year, $m);
    $policies   = report_policies_in_month($conn, $sel_year, $m);
    $repairs    = report_repairs_in_month($conn, $sel_year, $m);

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
$extra_css   = '<link rel="stylesheet" href="' . $base_path . 'assets/css/dashboard.css?v=' . filemtime(__DIR__ . '/../../assets/css/dashboard.css') . '"/>'
             . '<link rel="stylesheet" href="' . $base_path . 'assets/css/monthly_report.css?v=' . filemtime(__DIR__ . '/../../assets/css/monthly_report.css') . '"/>';
require_once '../../includes/header.php';
require_once '../../includes/navbar.php';
require_once __DIR__ . '/../../config/settings.php';
$company = company_info($conn);   // Settings > Company Info (was typed in here, so an edit there never reached the printout)

// Monthly Breakdown columns: key in $months_data => [heading, colour class (monthly_report.css), year total]
$mr_columns = [
    'total'     => ['Total Clients', '',                $year_clients],
    'insurance' => ['Insurance',     'mr-c-insurance',  $year_ins],
    'walkin'    => ['Walk-in',       'mr-c-walkin',     $year_wk],
    'policies'  => ['Policies',      'mr-c-policies',   $year_policies],
    'repairs'   => ['Repairs',       'mr-c-repairs',    $year_repairs],
];
?>


<div class="main">

<?php
$topbar_title      = 'Monthly Report';
$topbar_breadcrumb = ['Dashboard', 'Monthly Report'];
require_once '../../includes/topbar.php';
?>

<div class="content">

  <a href="../admin/dashboard_admin.php" class="back-link mr-no-print" onclick="goBack('../admin/dashboard_admin.php'); return false;">
    <?= icon('arrow-left', 14) ?> Back to Dashboard
  </a>

  <!-- PRINT HEADER (hidden on screen, visible on print) -->
  <div class="mr-print-header">
    <img src="<?= brand_logo('tg', '../../') ?>" class="mr-print-logo" alt="TG">
    <div class="mr-print-sep"></div>
    <img src="<?= brand_logo('basic', '../../') ?>" class="mr-print-logo" alt="Basic Car">
    <div class="mr-print-sep"></div>
    <div>
      <div class="mr-print-name"><?= htmlspecialchars($company['name']) ?></div>
      <div class="mr-print-addr"><?= htmlspecialchars($company['address']) ?></div>
    </div>
    <div class="mr-print-meta">
      <div class="mr-print-title">Monthly Report — <?= $sel_year ?></div>
      <div class="mr-print-gen">Generated: <?= date('F d, Y  h:i A') ?> &nbsp;|&nbsp; By: <?= htmlspecialchars($_SESSION['full_name'] ?? '') ?></div>
    </div>
  </div>

  <!-- YEAR FILTER -->
  <div class="mr-year-filter-row">
    <div>
      <div class="mr-heading">Annual Overview</div>
      <div class="mr-subheading">Client types, policies, and repairs by month</div>
    </div>
    <div class="mr-actions">
    <button class="mr-print-btn btn-ghost" onclick="window.print()">
      <?= icon('printer', 13) ?> Print
    </button>
    <!-- Custom year dropdown -->
    <div class="mr-year-wrap">
      <button id="mr-year-btn" class="mr-year-btn" type="button" aria-haspopup="true" aria-expanded="false">
        <?= icon('calendar', 13) ?>
        <?= $sel_year ?>
        <?= icon('chevron-down', 11) ?>
      </button>
      <div id="mr-year-dd" class="mr-year-dd">
        <?php foreach ($available_years as $y): ?>
        <a href="?year=<?= $y ?>" class="mr-year-opt<?= $y === $sel_year ? ' is-current' : '' ?>">
          <?= $y ?>
          <?php if ($y === $sel_year): ?><?= icon('check', 11) ?><?php endif; ?>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <script>
    (function(){
      var btn = document.getElementById('mr-year-btn');
      var dd  = document.getElementById('mr-year-dd');
      function show(on) { dd.classList.toggle('open', on); btn.setAttribute('aria-expanded', on ? 'true' : 'false'); }
      btn.addEventListener('click', function(e){ e.stopPropagation(); show(!dd.classList.contains('open')); });
      document.addEventListener('click', function(){ show(false); });
    })();
    </script>
    </div><!-- end print+year flex -->
  </div><!-- end year filter row -->

  <!-- YEAR SUMMARY CARDS -->
  <div class="mr-stat-grid">
    <?php
    $summary = [
      ['Total Clients',    $year_clients,  'user',         'gold',  $sel_year . ' total'],
      ['Insurance',        $year_ins,      'shield-check', 'green', round($year_clients > 0 ? $year_ins/$year_clients*100 : 0) . '% of clients'],
      ['Walk-in',          $year_wk,       'wrench',       'amber', round($year_clients > 0 ? $year_wk/$year_clients*100 : 0) . '% of clients'],
      ['Policies Created', $year_policies, 'document',     'blue',  $year_repairs . ' repair jobs'],
    ];
    // Card colours: the dash-stat--<theme> classes in assets/css/dashboard.css
    foreach ($summary as [$lbl, $val, $ico, $theme, $sub]):
    ?>
    <div class="dash-stat dash-stat--<?= $theme ?>">
      <div class="dash-stat-accent"></div>
      <div class="dash-stat-top">
        <div class="dash-stat-icon"><?= icon($ico, 18) ?></div>
        <span class="dash-stat-badge"><?= $sub ?></span>
      </div>
      <div class="dash-stat-value"><?= $val ?></div>
      <div class="dash-stat-label"><?= $lbl ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- CHARTS ROW -->
  <div class="mr-charts-row">

    <!-- Client Types Bar Chart -->
    <div class="card">
      <div class="card-header">
        <div class="card-icon"><?= icon('users', 16) ?></div>
        <div>
          <div class="card-title">Client Types per Month</div>
          <div class="card-sub"><?= $sel_year ?> — Insurance vs Walk-in</div>
        </div>
      </div>
      <div class="mr-chart-body">
        <canvas id="chart-ct-monthly" height="160"></canvas>
      </div>
    </div>

    <!-- Policies + Repairs Bar Chart -->
    <div class="card">
      <div class="card-header">
        <div class="card-icon"><?= icon('document', 16) ?></div>
        <div>
          <div class="card-title">Policies &amp; Repairs per Month</div>
          <div class="card-sub"><?= $sel_year ?></div>
        </div>
      </div>
      <div class="mr-chart-body">
        <canvas id="chart-pr-monthly" height="160"></canvas>
      </div>
    </div>

  </div>

  <!-- MONTHLY BREAKDOWN TABLE (columns: $mr_columns at the top; colours: monthly_report.css) -->
  <div class="card mr-breakdown">
    <div class="card-header">
      <div class="card-icon"><?= icon('clipboard-list', 16) ?></div>
      <div>
        <div class="card-title">Monthly Breakdown</div>
        <div class="card-sub"><?= $sel_year ?> — all 12 months</div>
      </div>
    </div>
    <div class="mr-table-wrap">
      <table class="mr-table">
        <thead>
          <tr>
            <th>Month</th>
            <?php foreach ($mr_columns as [$heading, $cls]): ?>
            <th class="<?= $cls ?>"><?= $heading ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php
          $cur_m = (int)date('m');
          $cur_y = (int)date('Y');
          foreach ($months_data as $row):
            $is_current = ($row['month'] === $cur_m && $sel_year === $cur_y);
            $is_future  = ($sel_year === $cur_y && $row['month'] > $cur_m);
          ?>
          <tr<?= $is_current ? ' class="is-current"' : '' ?>>
            <td>
              <div class="mr-month">
                <?= $row['label'] ?>
                <?php if ($is_current): ?><span class="mr-current-badge">Current</span><?php endif; ?>
              </div>
            </td>
            <?php foreach ($mr_columns as $key => [, $cls]): ?>
            <?php if ($is_future): ?>
            <td class="mr-future">—</td>
            <?php else: ?>
            <td class="<?= $cls ?>"><?= $row[$key] ?></td>
            <?php endif; ?>
            <?php endforeach; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td>Total <?= $sel_year ?></td>
            <?php foreach ($mr_columns as [, $cls, $year_total]): ?>
            <td class="<?= $cls ?>"><?= $year_total ?></td>
            <?php endforeach; ?>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>

</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/motion@13.4.3/dist/motion.min.js"></script>
<script src="../../assets/js/shared/dash_motion.js?v=<?= filemtime(__DIR__ . '/../../assets/js/shared/dash_motion.js') ?>"></script>
<script>
var chartCT, chartPR;
(function () {
  Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";
  Chart.defaults.color = '#888';
  const gridColor = 'rgba(255,255,255,0.06)';

  // Series colours come from monthly_report.css (--mr-insurance …), the same ones the table uses.
  // bar(): the solid colour for the border, the same colour at 80% for the fill.
  const rootCss = getComputedStyle(document.documentElement);
  const bar = (name) => {
    const hex = rootCss.getPropertyValue('--mr-' + name).trim().replace('#', '');
    if (!/^[0-9a-f]{3}([0-9a-f]{3})?$/i.test(hex)) return {};   // stylesheet missing: Chart.js default colours
    const n = parseInt(hex.length === 3 ? hex.replace(/./g, '$&$&') : hex, 16);
    return { borderColor: '#' + hex, backgroundColor: 'rgba(' + (n >> 16 & 255) + ',' + (n >> 8 & 255) + ',' + (n & 255) + ',0.8)' };
  };
  const barAnim   = { duration: 900, easing: 'easeOutQuart', delay: (ctx) => ctx.dataIndex * 60 };

  // ── Motion (assets/js/shared/dash_motion.js): stat numbers count up, cards rise in and chart cards lift on
  // hover; each chart is drawn once it is on screen (dash_motion draws any still waiting before printing) ──
  tgCountUp('.mr-stat-grid .dash-stat-value');
  tgRiseInView('.mr-charts-row > .card, .mr-charts-row ~ .card');
  tgHoverLift('.mr-charts-row > .card');

  const labels   = <?= json_encode(array_column($months_data, 'short')) ?>;
  const insData  = <?= json_encode(array_column($months_data, 'insurance')) ?>;
  const wkData   = <?= json_encode(array_column($months_data, 'walkin')) ?>;
  const polData  = <?= json_encode(array_column($months_data, 'policies')) ?>;
  const repData  = <?= json_encode(array_column($months_data, 'repairs')) ?>;

  const sharedOptions = (animate) => ({
    responsive: true,
    maintainAspectRatio: true,
    animation: animate ? barAnim : false,
    plugins: {
      legend: {
        display: true,
        position: 'bottom',
        labels: { boxWidth: 10, padding: 12, font: { size: 11 } }
      }
    },
    scales: {
      y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 }, grid: { color: gridColor } },
      x: { grid: { display: false }, ticks: { maxRotation: 0, font: { size: 10 } } }
    }
  });

  tgChartInView('chart-ct-monthly', (animate) => { chartCT = new Chart(document.getElementById('chart-ct-monthly'), {
    type: 'bar',
    data: {
      labels,
      datasets: [
        Object.assign({ label: 'Insurance', data: insData, borderWidth: 1.5, borderRadius: 5 }, bar('insurance')),
        Object.assign({ label: 'Walk-in',   data: wkData,  borderWidth: 1.5, borderRadius: 5 }, bar('walkin'))
      ]
    },
    options: sharedOptions(animate)
  }); });

  tgChartInView('chart-pr-monthly', (animate) => { chartPR = new Chart(document.getElementById('chart-pr-monthly'), {
    type: 'bar',
    data: {
      labels,
      datasets: [
        Object.assign({ label: 'Policies', data: polData, borderWidth: 1.5, borderRadius: 5 }, bar('policies')),
        Object.assign({ label: 'Repairs',  data: repData, borderWidth: 1.5, borderRadius: 5 }, bar('repairs'))
      ]
    },
    options: sharedOptions(animate)
  }); });

  // Resize charts to a clean fixed size before printing so canvas renders correctly (dash_motion.js's own
  // beforeprint handler, registered earlier, has already drawn any chart that was still waiting)
  const PRINT_H = 120;
  window.addEventListener('beforeprint', function () {
    [chartCT, chartPR].filter(Boolean).forEach(c => {
      c.options.animation = false;
      const w = c.canvas.parentNode.offsetWidth || 320;
      c.resize(w, PRINT_H);
    });
  });
  window.addEventListener('afterprint', function () {
    [chartCT, chartPR].filter(Boolean).forEach(c => {
      c.options.animation = barAnim;
      c.resize();
    });
  });
})();
</script>

<?php require_once '../../includes/footer.php'; ?>
