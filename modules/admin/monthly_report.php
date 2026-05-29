<?php
require_once __DIR__ . '/../../config/session.php';
require_once '../../config/db.php';
require_once '../../config/validators.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header("Location: ../../auth/login.php");
    exit;
}

// ── FILTER: year (default current year) ──
// Build year range: from earliest data year (or current-2) up to current year
$current_year = (int)date('Y');
$earliest_row = $conn->query("SELECT MIN(YEAR(created_at)) as y FROM clients")->fetch_assoc();
$earliest_year = (int)($earliest_row['y'] ?? $current_year);
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

    // Stats rule: include client if not deleted, OR deleted in a different month/year than added
    $sc = "(c.deleted_at IS NULL OR (YEAR(c.deleted_at) != YEAR(c.created_at) OR MONTH(c.deleted_at) != MONTH(c.created_at)))";

    $ins = (int)$conn->query("
        SELECT COUNT(DISTINCT c.client_id) as c FROM clients c
        INNER JOIN insurance_policies ip ON ip.client_id = c.client_id
        WHERE YEAR(c.created_at)='$sel_year' AND MONTH(c.created_at)='$m' AND $sc
    ")->fetch_assoc()['c'];

    $wk = (int)$conn->query("
        SELECT COUNT(*) as c FROM clients c
        LEFT JOIN insurance_policies ip ON ip.client_id = c.client_id
        WHERE ip.policy_id IS NULL
        AND YEAR(c.created_at)='$sel_year' AND MONTH(c.created_at)='$m' AND $sc
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
$company_name    = 'TG Customworks & Basic Car Insurance';
$company_address = '49 Villa Tierra St., San Roque, Pandi, Bulacan';
?>

<style>
/* ── PRINT ── */
@media print {
  @page { size: A4 portrait; margin: 1cm 1.5cm; }

  /* Hide all chrome */
  .mob-nav, .mob-topbar, .mob-more-overlay, .mob-more-sheet,
  .sidebar-overlay, #page-loader, .sidebar, .topbar,
  .mr-year-wrap, .mr-print-btn, .mr-year-filter-row, .mr-no-print { display: none !important; }

  /* Base */
  body  { background: #fff !important; color: #111 !important; margin: 0 !important; font-size: 0.72rem !important; }
  .main { padding-left: 0 !important; background: #fff !important; }
  .content { padding: 0 !important; max-width: 100% !important; }

  /* Print header */
  .mr-print-header {
    display: flex !important;
    align-items: center !important;
    gap: 0.75rem !important;
    margin-bottom: 0.5rem !important;
    padding-bottom: 0.4rem !important;
    border-bottom: 2px solid #B8860B !important;
  }
  .mr-print-header img { height: 32px !important; }
  .mr-print-header > div[style*="font-size:1rem"] { font-size: 0.85rem !important; font-weight: 800 !important; }
  .mr-print-header > div[style*="font-size:0.75rem"] { font-size: 0.62rem !important; }
  .mr-print-header > div[style*="font-size:0.9rem"] { font-size: 0.78rem !important; font-weight: 700 !important; }
  .mr-print-header > div[style*="font-size:0.7rem"] { font-size: 0.6rem !important; }

  /* ── STAT SUMMARY — flex row, always inline ── */
  .mr-stat-grid {
    display: flex !important;
    flex-direction: row !important;
    gap: 0 !important;
    margin-bottom: 0.5rem !important;
    border: 1px solid #ccc !important;
    page-break-inside: avoid;
    break-inside: avoid;
  }
  .dash-stat {
    flex: 1 !important;
    background: #fff !important;
    border: none !important;
    border-right: 1px solid #ccc !important;
    box-shadow: none !important;
    border-radius: 0 !important;
    padding: 0.35rem 0.75rem !important;
  }
  .dash-stat:last-child { border-right: none !important; }
  .dash-stat-accent { display: none !important; }
  .dash-stat-icon   { display: none !important; }
  .dash-stat-top    { margin-bottom: 0.1rem !important; }
  .dash-stat-badge  {
    background: none !important; border: none !important; padding: 0 !important;
    color: #888 !important; font-size: 0.55rem !important; font-weight: 500 !important;
  }
  .dash-stat-value {
    font-size: 1.5rem !important; font-weight: 800 !important;
    color: #111 !important; line-height: 1 !important; margin-bottom: 0.1rem !important;
  }
  .dash-stat-label {
    font-size: 0.52rem !important; font-weight: 700 !important;
    text-transform: uppercase !important; letter-spacing: 1.2px !important; color: #555 !important;
  }

  /* ── CHARTS — side by side, compact ── */
  .mr-charts-row {
    display: grid !important;
    grid-template-columns: 1fr 1fr !important;
    gap: 0.6rem !important;
    margin-bottom: 0.5rem !important;
    page-break-inside: avoid;
    break-inside: avoid;
  }
  .mr-charts-row .card > div[style] { padding: 0.3rem 0.5rem !important; }

  /* ── CARDS — strip digital styling ── */
  .card {
    border: none !important; box-shadow: none !important;
    border-radius: 0 !important; background: #fff !important; margin-bottom: 0 !important;
  }
  .card-header {
    padding: 0 0 0.25rem 0 !important;
    border-bottom: 1px solid #ccc !important;
    margin-bottom: 0 !important;
    background: none !important;
  }
  .card-icon  { display: none !important; }
  .card-title {
    font-size: 0.62rem !important; font-weight: 700 !important;
    color: #111 !important; text-transform: uppercase !important; letter-spacing: 0.8px !important;
  }
  .card-sub { font-size: 0.55rem !important; color: #666 !important; }

  /* ── TABLE ── */
  table { border-collapse: collapse !important; width: 100% !important; font-size: 0.65rem !important; }
  th {
    background: #f4f4f4 !important; color: #333 !important;
    border: 1px solid #bbb !important; padding: 0.2rem 0.5rem !important; text-align: center !important;
    print-color-adjust: exact; -webkit-print-color-adjust: exact;
  }
  td { color: #111 !important; border: 1px solid #d0d0d0 !important; padding: 0.15rem 0.5rem !important; }
  /* Override inline td padding from the table */
  td[style*="padding:0.75rem"] { padding: 0.15rem 0.5rem !important; }
  tfoot td {
    background: #f0f0f0 !important; font-weight: 700 !important;
    print-color-adjust: exact; -webkit-print-color-adjust: exact;
  }
  /* Current month highlight — subtle */
  tr[style*="background:rgba(212"] td { background: #fdf8ea !important; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
  /* Hide "Current" badge inline style overflow */
  span[style*="font-size:0.62rem"] { display: none !important; }
}
</style>

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
  <div class="mr-print-header" style="display:none;align-items:center;gap:1rem;margin-bottom:1.5rem;padding-bottom:1rem;border-bottom:2px solid #B8860B;">
    <img src="../../assets/img/tg_logo.png" style="height:44px;width:auto;object-fit:contain;" alt="TG">
    <div style="width:1px;height:40px;background:#ddd;"></div>
    <img src="../../assets/img/LogoBasicCar.png" style="height:44px;width:auto;object-fit:contain;" alt="Basic Car">
    <div style="width:1px;height:40px;background:#ddd;"></div>
    <div>
      <div style="font-size:1rem;font-weight:800;color:#1a1710;"><?= htmlspecialchars($company_name) ?></div>
      <div style="font-size:0.75rem;color:#555;"><?= htmlspecialchars($company_address) ?></div>
    </div>
    <div style="margin-left:auto;text-align:right;">
      <div style="font-size:0.9rem;font-weight:700;color:#B8860B;">Monthly Report — <?= $sel_year ?></div>
      <div style="font-size:0.7rem;color:#888;">Generated: <?= date('F d, Y  h:i A') ?> &nbsp;|&nbsp; By: <?= htmlspecialchars($_SESSION['full_name'] ?? '') ?></div>
    </div>
  </div>

  <!-- YEAR FILTER -->
  <div class="mr-year-filter-row" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;">
    <div>
      <div style="font-size:1rem;font-weight:700;color:var(--text-primary);">Annual Overview</div>
      <div style="font-size:0.78rem;color:var(--text-muted);margin-top:0.1rem;">Client types, policies, and repairs by month</div>
    </div>
    <div style="display:flex;align-items:center;gap:0.6rem;">
    <button class="mr-print-btn btn-ghost" onclick="window.print()" style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.38rem 0.85rem;font-size:0.8rem;">
      <?= icon('printer', 13) ?> Print
    </button>
    <!-- Custom year dropdown -->
    <div class="mr-year-wrap" style="position:relative;">
      <button id="mr-year-btn" type="button"
              style="display:inline-flex;align-items:center;gap:0.5rem;padding:0.38rem 0.85rem;background:var(--bg-2);border:1px solid var(--border);border-radius:8px;color:var(--text-primary);font-size:0.82rem;font-weight:600;cursor:pointer;">
        <?= icon('calendar', 13) ?>
        <?= $sel_year ?>
        <?= icon('chevron-down', 11) ?>
      </button>
      <div id="mr-year-dd"
           style="display:none;position:absolute;top:calc(100% + 6px);right:0;z-index:200;background:var(--bg-2);border:1px solid var(--border);border-radius:10px;box-shadow:var(--shadow-lg);min-width:110px;overflow:hidden;">
        <?php foreach ($available_years as $y): ?>
        <a href="?year=<?= $y ?>"
           style="display:flex;align-items:center;justify-content:space-between;padding:0.5rem 0.9rem;font-size:0.8rem;font-weight:<?= $y === $sel_year ? '700' : '500' ?>;text-decoration:none;color:<?= $y === $sel_year ? 'var(--gold)' : 'var(--text-secondary)' ?>;transition:background 0.1s;"
           onmouseover="this.style.background='var(--gold-pale)'" onmouseout="this.style.background=''">
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
      btn.addEventListener('click', function(e){
        e.stopPropagation();
        dd.style.display = dd.style.display === 'block' ? 'none' : 'block';
      });
      document.addEventListener('click', function(){ dd.style.display = 'none'; });
    })();
    </script>
    </div><!-- end print+year flex -->
  </div><!-- end year filter row -->

  <!-- YEAR SUMMARY CARDS -->
  <div class="mr-stat-grid" style="display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.25rem;">
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
  <div class="mr-charts-row" style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.25rem;">

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
var chartCT, chartPR;
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

  const sharedOptions = (extraDatasets) => ({
    responsive: true,
    maintainAspectRatio: true,
    animation: barAnim,
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

  chartCT = new Chart(document.getElementById('chart-ct-monthly'), {
    type: 'bar',
    data: {
      labels,
      datasets: [
        { label: 'Insurance', data: insData, backgroundColor: 'rgba(46,125,82,0.8)',  borderColor: '#2E7D52', borderWidth: 1.5, borderRadius: 5 },
        { label: 'Walk-in',   data: wkData,  backgroundColor: 'rgba(184,134,11,0.8)', borderColor: '#B8860B', borderWidth: 1.5, borderRadius: 5 }
      ]
    },
    options: sharedOptions()
  });

  chartPR = new Chart(document.getElementById('chart-pr-monthly'), {
    type: 'bar',
    data: {
      labels,
      datasets: [
        { label: 'Policies', data: polData, backgroundColor: 'rgba(26,107,154,0.8)',  borderColor: '#1A6B9A', borderWidth: 1.5, borderRadius: 5 },
        { label: 'Repairs',  data: repData, backgroundColor: 'rgba(123,63,160,0.8)',  borderColor: '#7B3FA0', borderWidth: 1.5, borderRadius: 5 }
      ]
    },
    options: sharedOptions()
  });

  // Resize charts to a clean fixed size before printing so canvas renders correctly
  const PRINT_H = 120;
  window.addEventListener('beforeprint', function () {
    [chartCT, chartPR].forEach(c => {
      c.options.animation = false;
      const w = c.canvas.parentNode.offsetWidth || 320;
      c.resize(w, PRINT_H);
    });
  });
  window.addEventListener('afterprint', function () {
    [chartCT, chartPR].forEach(c => {
      c.options.animation = barAnim;
      c.resize();
    });
  });
})();
</script>

<?php require_once '../../includes/footer.php'; ?>
