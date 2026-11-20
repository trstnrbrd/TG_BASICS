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

  <?php
  // ── what the page shows, worked out once ──
  $is_this_year = $sel_year === (int)date('Y');
  $last_month   = $is_this_year ? (int)date('n') : 12;          // charts stop at this month (no empty future)
  $shown        = array_slice($months_data, 0, $last_month);
  // A sparkline (monthly values) as an inline SVG path in the card's own colour (dashboard.css .dash-spark)
  $spark = function (array $vals): string {
      $n = count($vals); if ($n < 2) return '';
      $max = max(1, max($vals)); $w = 100; $h = 26; $pts = [];
      foreach (array_values($vals) as $i => $v) $pts[] = [round($i * $w / ($n - 1), 2), round($h - 2 - ($v / $max) * ($h - 6), 2)];
      $line = 'M' . implode(' L', array_map(fn($p) => $p[0] . ' ' . $p[1], $pts));
      [$lx, $ly] = end($pts);
      return '<svg class="dash-spark" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" aria-hidden="true">'
           . '<path class="dash-spark-area" d="' . $line . " L$w $h L0 $h Z" . '"/><path class="dash-spark-line" d="' . $line . '"/>'
           . '<circle class="dash-spark-dot" cx="' . $lx . '" cy="' . $ly . '" r="2.4"/></svg>';
  };
  // the busiest month of a column, as "Sep (10)", or "—" when the whole year is zero
  $best = function (string $key) use ($shown): string {
      $col = array_column($shown, $key); if (!$col || max($col) === 0) return '—';
      $i = array_search(max($col), $col, true);
      return $shown[$i]['short'] . ' (' . $col[$i] . ')';
  };
  // per-column maximum, for the little bars in the table
  $col_max = [];
  foreach (array_keys($mr_columns) as $k) $col_max[$k] = max(1, max(array_column($months_data, $k)));
  ?>

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
      <div class="mr-kicker">Annual Overview</div>
      <div class="mr-heading"><?= $sel_year ?> at a glance</div>
      <div class="mr-subheading">Client types, policies, and repairs by month<?= $is_this_year ? ' · up to ' . date('F') : '' ?></div>
    </div>
    <div class="mr-actions mr-no-print">
      <nav class="mr-years" aria-label="Choose the year">
        <?php foreach (array_reverse($available_years) as $y): ?>
        <a href="?year=<?= $y ?>" class="mr-year-pill<?= $y === $sel_year ? ' is-current' : '' ?>"<?= $y === $sel_year ? ' aria-current="page"' : '' ?>><?= $y ?></a>
        <?php endforeach; ?>
      </nav>
      <button class="mr-print-btn btn-ghost" type="button" onclick="window.print()">
        <?= icon('printer', 13) ?> Print
      </button>
    </div>
  </div>

  <!-- YEAR SUMMARY CARDS -->
  <div class="mr-stat-grid">
    <?php
    $summary = [
      ['Total Clients',    $year_clients,  'user',         'gold',  $sel_year . ' total',                                                        'total'],
      ['Insurance',        $year_ins,      'shield-check', 'green', round($year_clients > 0 ? $year_ins/$year_clients*100 : 0) . '% of clients', 'insurance'],
      ['Walk-in',          $year_wk,       'wrench',       'amber', round($year_clients > 0 ? $year_wk/$year_clients*100 : 0) . '% of clients',  'walkin'],
      ['Policies Created', $year_policies, 'document',     'blue',  $year_repairs . ' repair jobs',                                              'policies'],
    ];
    // Card colours: the dash-stat--<theme> classes in assets/css/dashboard.css
    foreach ($summary as [$lbl, $val, $ico, $theme, $sub, $key]):
    ?>
    <div class="dash-stat dash-stat--<?= $theme ?>">
      <div class="dash-stat-accent"></div>
      <div class="dash-stat-top">
        <div class="dash-stat-icon"><?= icon($ico, 18) ?></div>
        <span class="dash-stat-badge"><?= $sub ?></span>
      </div>
      <div class="dash-stat-value"><?= $val ?></div>
      <div class="dash-stat-label"><?= $lbl ?></div>
      <div class="dash-stat-micro"><?= $spark(array_column($shown, $key)) ?><span class="dash-micro-note">Best: <?= htmlspecialchars($best($key)) ?></span></div>
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
        <div class="mr-legend" data-chart="ct">
          <button type="button" class="mr-legend-item" data-ds="0" aria-pressed="true"><span class="mr-dot mr-bg-insurance"></span>Insurance</button>
          <button type="button" class="mr-legend-item" data-ds="1" aria-pressed="true"><span class="mr-dot mr-bg-walkin"></span>Walk-in</button>
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
          <div class="card-sub"><?= $sel_year ?> — policies as bars, repairs as a line</div>
        </div>
        <div class="mr-legend" data-chart="pr">
          <button type="button" class="mr-legend-item" data-ds="0" aria-pressed="true"><span class="mr-dot mr-bg-policies"></span>Policies</button>
          <button type="button" class="mr-legend-item" data-ds="1" aria-pressed="true"><span class="mr-dot mr-bg-repairs"></span>Repairs</button>
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
        <div class="card-sub"><?= $sel_year ?> — all 12 months · point at a month to find it in the charts</div>
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
          <tr class="<?= trim(($is_current ? 'is-current ' : '') . ($is_future ? 'is-future' : '')) ?>" data-month="<?= $row['month'] - 1 ?>">
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
            <td class="<?= $cls ?><?= $row[$key] === 0 ? ' is-zero' : '' ?>">
              <span class="mr-cell"><span class="mr-num"><?= $row[$key] ?></span><span class="mr-cellbar"><span style="width:<?= round($row[$key] / $col_max[$key] * 100) ?>%"></span></span></span>
            </td>
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
  // Colours: the series colours are --mr-* in monthly_report.css (theme colours, so dark mode works too),
  // the same ones the table and the legend use
  const css  = getComputedStyle(document.documentElement);
  const v    = (name, fb) => (css.getPropertyValue(name) || '').trim() || fb;
  const fade = (c, a) => {
    if (c.startsWith('#')) {
      const h = c.length === 4 ? c.slice(1).replace(/./g, '$&$&') : c.slice(1, 7);
      return 'rgba(' + parseInt(h.slice(0, 2), 16) + ',' + parseInt(h.slice(2, 4), 16) + ',' + parseInt(h.slice(4, 6), 16) + ',' + a + ')';
    }
    const m = c.match(/[\d.]+/g); return m ? 'rgba(' + m[0] + ',' + m[1] + ',' + m[2] + ',' + a + ')' : c;
  };
  const S = { insurance: v('--mr-insurance', '#2E7D52'), walkin: v('--mr-walkin', '#B8860B'), policies: v('--mr-policies', '#1A6B9A'), repairs: v('--mr-repairs', '#7B3FA0') };
  const muted = v('--text-muted', '#888'), text = v('--text-primary', '#222'), panel = v('--bg-2', '#fff'), border = v('--border', 'rgba(0,0,0,.1)');
  Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";
  Chart.defaults.color = muted;

  const barAnim = { duration: 900, easing: 'easeOutQuart', delay: (ctx) => ctx.dataIndex * 60 };

  // ── Motion (assets/js/shared/dash_motion.js): stat numbers count up, cards rise in and chart cards lift on
  // hover; each chart is drawn once it is on screen (dash_motion draws any still waiting before printing) ──
  tgCountUp('.mr-stat-grid .dash-stat-value');
  tgRiseInView('.mr-charts-row > .card, .mr-charts-row ~ .card');
  tgHoverLift('.mr-charts-row > .card');

  // the charts stop at the last month reached (this year: this month), so no empty future
  const upto     = <?= (int)$last_month ?>;
  const labels   = <?= json_encode(array_column($months_data, 'short')) ?>.slice(0, upto);
  const insData  = <?= json_encode(array_column($months_data, 'insurance')) ?>.slice(0, upto);
  const wkData   = <?= json_encode(array_column($months_data, 'walkin')) ?>.slice(0, upto);
  const polData  = <?= json_encode(array_column($months_data, 'policies')) ?>.slice(0, upto);
  const repData  = <?= json_encode(array_column($months_data, 'repairs')) ?>.slice(0, upto);
  const fullName = <?= json_encode(array_column($months_data, 'label')) ?>;

  const tooltip = {
    backgroundColor: panel, titleColor: text, bodyColor: text, borderColor: border, borderWidth: 1,
    padding: 10, cornerRadius: 10, boxPadding: 4, usePointStyle: true,
    callbacks: { title: (items) => fullName[items[0].dataIndex] + ' <?= (int)$sel_year ?>' },
  };
  const options = (animate, stacked) => ({
    responsive: true,
    maintainAspectRatio: true,
    animation: animate ? barAnim : false,
    interaction: { mode: 'index', intersect: false },
    onHover: (e, els) => markRow(els.length ? els[0].index : null),
    onClick: (e, els) => { if (els.length) jumpToRow(els[0].index); },
    plugins: { legend: { display: false }, tooltip },
    scales: {
      y: { stacked, beginAtZero: true, border: { display: false }, ticks: { stepSize: 1, precision: 0, padding: 6 }, grid: { color: fade(muted, 0.12), drawTicks: false } },
      x: { stacked, border: { display: false }, grid: { display: false }, ticks: { maxRotation: 0, font: { size: 10 } } }
    }
  });
  const soft = (color) => (ctx) => {
    const a = ctx.chart.chartArea; if (!a) return fade(color, 0.7);
    const g = ctx.chart.ctx.createLinearGradient(0, a.bottom, 0, a.top);
    g.addColorStop(0, fade(color, 0.45)); g.addColorStop(1, fade(color, 0.9)); return g;
  };

  tgChartInView('chart-ct-monthly', (animate) => { chartCT = new Chart(document.getElementById('chart-ct-monthly'), {
    type: 'bar',
    data: {
      labels,
      datasets: [
        { label: 'Insurance', data: insData, backgroundColor: soft(S.insurance), hoverBackgroundColor: S.insurance, borderRadius: 6, borderSkipped: false, maxBarThickness: 30 },
        { label: 'Walk-in',   data: wkData,  backgroundColor: soft(S.walkin),    hoverBackgroundColor: S.walkin,    borderRadius: 6, borderSkipped: false, maxBarThickness: 30 },
      ]
    },
    options: options(animate, true)
  }); });

  tgChartInView('chart-pr-monthly', (animate) => { chartPR = new Chart(document.getElementById('chart-pr-monthly'), {
    type: 'bar',
    data: {
      labels,
      datasets: [
        { label: 'Policies', data: polData, backgroundColor: soft(S.policies), hoverBackgroundColor: S.policies, borderRadius: 6, borderSkipped: false, maxBarThickness: 30, order: 2 },
        { label: 'Repairs',  data: repData, type: 'line', borderColor: S.repairs, backgroundColor: fade(S.repairs, 0.12), fill: true, tension: 0.35,
          pointRadius: 3.5, pointHoverRadius: 6, pointBackgroundColor: panel, pointBorderColor: S.repairs, pointBorderWidth: 2, borderWidth: 2.5, order: 1 },
      ]
    },
    options: options(animate, false)
  }); });

  // ── Legend chips: click to hide / show a series ──
  document.querySelectorAll('.mr-legend').forEach((lg) => lg.querySelectorAll('.mr-legend-item').forEach((b) => {
    b.addEventListener('click', () => {
      const chart = lg.dataset.chart === 'ct' ? chartCT : chartPR; if (!chart) return;
      const i = +b.dataset.ds, on = !chart.isDatasetVisible(i);
      chart.setDatasetVisibility(i, on); chart.update();
      b.setAttribute('aria-pressed', on ? 'true' : 'false'); b.classList.toggle('is-off', !on);
    });
  }));

  // ── Table <-> charts: a month row lights up its bars, a bar lights up its row ──
  const rows = Array.from(document.querySelectorAll('.mr-table tbody tr[data-month]'));
  function markRow(i) { rows.forEach((r) => r.classList.toggle('is-hot', +r.dataset.month === i)); }
  function jumpToRow(i) {
    const r = rows.find((x) => +x.dataset.month === i); if (!r) return;
    r.scrollIntoView({ behavior: 'smooth', block: 'center' });
    r.classList.remove('is-flash'); void r.offsetWidth; r.classList.add('is-flash');
  }
  function showMonth(i) {
    [chartCT, chartPR].filter(Boolean).forEach((c) => {
      const els = i === null || i >= upto ? [] : c.data.datasets.map((d, k) => ({ datasetIndex: k, index: i })).filter((e) => c.isDatasetVisible(e.datasetIndex));
      c.setActiveElements(els);
      c.tooltip.setActiveElements(els, { x: 0, y: 0 });
      c.update('none');
    });
  }
  rows.forEach((r) => {
    r.addEventListener('mouseenter', () => { markRow(+r.dataset.month); showMonth(+r.dataset.month); });
    r.addEventListener('mouseleave', () => { markRow(null); showMonth(null); });
  });

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
