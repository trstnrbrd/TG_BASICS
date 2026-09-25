<?php
require_once __DIR__ . "/../../config/session.php";
require_once '../../config/db.php';
require_once '../../config/validators.php';
require_once '../../config/settings.php';
require_once '../../config/access.php';
require_once '../../includes/agent_filter.php';
require_once '../../includes/pagination.php';

$urg_days = (int)getSetting($conn, 'renewal_urgent_days', '7');
$exp_days = (int)getSetting($conn, 'renewal_expiring_days', '30');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header("Location: ../../auth/login.php");
    exit;
}

$is_super = $_SESSION['role'] === 'super_admin';

// No vault password (owner's decision, 2026-09-24). Every admin opens Renewal Tracking on the policies of
// THEIR OWN clients ("My Clients") and can switch to another agent or everyone with the same agent filter as
// Client Records — other agents' policies open view-only (view_policy.php). The dashboard alerts and the
// sidebar badge still count only the user's own policies (renewal_scope_sql).
$af        = agent_filter_state($conn);
$scope_sql = '1=1' . agent_filter_sql($af, 'c.agent_id');
// Keeps the chosen agent on the stat-card and Clear links
$agent_qs  = '&agent=' . urlencode($af['value']);

// ── FILTERS ──
$company      = san_enum($_GET['company'] ?? 'PhilBritish', ['PhilBritish', 'Alpha Insurance & Surety Company Inc.']);
$filter       = san_enum($_GET['filter'] ?? 'all', ['all', 'urgent', 'expiring', 'stable', 'expired', 'renewed']);
$search       = validate_search(san_str($_GET['search'] ?? '', MAX_SEARCH));

// ── BUILD QUERY ──
$where_clauses = [];
$params        = [];
$types         = '';

// Old archived policies (is_renewed=1) never appear — only the new replacement policy does
$where_clauses[] = "p.is_renewed = 0";
$where_clauses[] = $scope_sql;
$where_clauses[] = "p.insurance_company = ?";
$params[] = $company;
$types   .= 's';

if ($search !== '') {
    $like = "%$search%";
    $where_clauses[] = "(c.full_name LIKE ? OR c.contact_number LIKE ? OR v.plate_number LIKE ? OR p.policy_number LIKE ?)";
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
    $types   .= 'ssss';
}

switch ($filter) {
    case 'urgent':
        $where_clauses[] = "p.policy_end >= CURDATE() AND DATEDIFF(p.policy_end, CURDATE()) <= $urg_days";
        break;
    case 'expiring':
        $exp_start = $urg_days + 1;
        $where_clauses[] = "p.policy_end >= CURDATE() AND DATEDIFF(p.policy_end, CURDATE()) BETWEEN $exp_start AND $exp_days";
        break;
    case 'stable':
        $where_clauses[] = "p.policy_end >= CURDATE() AND DATEDIFF(p.policy_end, CURDATE()) > $exp_days";
        break;
    case 'expired':
        $where_clauses[] = "p.policy_end < CURDATE()";
        break;
    case 'renewed':
        $where_clauses[] = "p.renewed_at IS NOT NULL";
        break;
}

$where_sql = count($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

$sql = "
    SELECT
        p.policy_id, p.policy_number, p.coverage_type, p.insurance_company,
        p.policy_start, p.policy_end, p.is_renewed, p.renewed_at,
        p.sum_insured, p.total_premium, p.amount_paid, p.balance,
        p.payment_status,
        DATEDIFF(p.policy_end, CURDATE()) AS days_left,
        c.client_id, c.full_name, c.contact_number,
        v.plate_number, v.make, v.model, v.year_model,
        CASE WHEN u.is_hidden = 1 THEN 'Developer' ELSE u.full_name END AS added_by_name,
        CASE WHEN ag.is_hidden = 1 THEN 'Developer' ELSE ag.full_name END AS agent_name,
        CASE WHEN ag.is_hidden = 1 THEN NULL ELSE ag.profile_photo END AS agent_photo
    FROM insurance_policies p
    INNER JOIN clients c ON p.client_id = c.client_id
    INNER JOIN vehicles v ON p.vehicle_id = v.vehicle_id
    LEFT JOIN users u ON p.created_by = u.user_id
    LEFT JOIN users ag ON c.agent_id = ag.user_id
    $where_sql
    ORDER BY p.policy_end ASC
";

// One page at a time (includes/pagination.php) — the total is counted from this same query
[$policies, $pg] = paginate_query($conn, $sql, $types, $params);

// ── SUMMARY COUNTS ──
$exp_start_count = $urg_days + 1;
$counts_stmt = $conn->prepare("
    SELECT
        SUM(CASE WHEN p.is_renewed = 0 THEN 1 ELSE 0 END) AS total,
        SUM(CASE WHEN p.is_renewed = 0 AND p.policy_end >= CURDATE() AND DATEDIFF(p.policy_end, CURDATE()) <= $urg_days THEN 1 ELSE 0 END) AS urgent,
        SUM(CASE WHEN p.is_renewed = 0 AND p.policy_end >= CURDATE() AND DATEDIFF(p.policy_end, CURDATE()) BETWEEN $exp_start_count AND $exp_days THEN 1 ELSE 0 END) AS expiring,
        SUM(CASE WHEN p.is_renewed = 0 AND p.policy_end >= CURDATE() AND DATEDIFF(p.policy_end, CURDATE()) > $exp_days THEN 1 ELSE 0 END) AS stable,
        SUM(CASE WHEN p.is_renewed = 0 AND p.policy_end < CURDATE() THEN 1 ELSE 0 END) AS expired,
        SUM(CASE WHEN p.is_renewed = 0 AND p.renewed_at IS NOT NULL THEN 1 ELSE 0 END) AS renewed
    FROM insurance_policies p
    INNER JOIN clients c ON c.client_id = p.client_id
    WHERE p.insurance_company = ? AND $scope_sql
");
$counts_stmt->bind_param('s', $company);
$counts_stmt->execute();
$counts = $counts_stmt->get_result()->fetch_assoc();

$page_title  = 'Renewal Tracking';
$active_page = 'renewal';
$base_path   = '../../';
require_once '../../includes/header.php';
require_once '../../includes/navbar.php';
?>

<link rel="stylesheet" href="../../assets/css/shared/clients.css?v=<?= filemtime(__DIR__ . '/../../assets/css/shared/clients.css') ?>"/>
<link rel="stylesheet" href="../../assets/css/shared/agent_filter.css?v=<?= filemtime(__DIR__ . '/../../assets/css/shared/agent_filter.css') ?>"/>
<style>
/* Company quick switch — a two-way toggle, so PhilBritish <-> Alpha no longer needs the sidebar menu */
.rnl-company-row { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 1.25rem; }
.rnl-company-switch {
  display: inline-flex; padding: 3px; gap: 3px; border-radius: 100px;
  background: var(--bg-3); border: 1px solid var(--border); box-shadow: var(--shadow);
}
.rnl-company-opt {
  display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.4rem 0.95rem; border-radius: 100px;
  font-size: 0.74rem; font-weight: 600; color: var(--text-muted); text-decoration: none; white-space: nowrap;
  border: 1px solid transparent; transition: background 0.12s, color 0.12s, border-color 0.12s;
}
.rnl-company-opt:hover { color: var(--gold); background: var(--gold-pale); }
.rnl-company-opt.is-active { color: var(--gold); background: var(--gold-pale); border-color: var(--gold-bright); font-weight: 700; }

/* Phones: the switch spans the width (two equal halves); the agent filter takes its own full row in the
   toolbar grid (mobile_tables.css) */
@media (max-width: 768px) {
  .rnl-company-switch { display: flex; width: 100%; }
  .rnl-company-opt { flex: 1; justify-content: center; }
  .rnl-filter-inner > .cl-agent-wrap { grid-column: 1 / -1; }
}
/* Row details open on hover — desktop only. Touch screens have no real
   :hover, so mobile/tablet (<=768px) keeps the original tap-to-expand
   behavior from the global click handler in footer.php instead. */
@media (min-width: 769px) {
  .renewal-list-table .tg-expand-row { display: none; }
  .renewal-list-table .tg-expandable-row:hover + .tg-expand-row,
  .renewal-list-table .tg-expand-row:hover {
    display: table-row !important;
  }
  .renewal-list-table .tg-expandable-row:hover .row-chevron {
    transform: rotate(90deg);
    opacity: 0.7 !important;
  }
}
</style>

<div class="main">

<?php
$topbar_title      = 'Policy Status and Renewal Tracking';
$topbar_breadcrumb = ['Insurance', 'Renewal Tracking'];
require_once '../../includes/topbar.php';
?>

  <div class="content">

    <!-- Company quick switch (PhilBritish <-> Alpha) — keeps the chosen agent, status card and search -->
    <div class="rnl-company-row">
      <nav class="rnl-company-switch" aria-label="Insurance company">
        <?php foreach (['PhilBritish' => 'PhilBritish', 'Alpha Insurance & Surety Company Inc.' => 'Alpha Insurance'] as $co => $co_label):
          $co_on = $company === $co; ?>
        <a href="?<?= htmlspecialchars(http_build_query(['company' => $co, 'filter' => $filter, 'search' => $search, 'agent' => $af['value']])) ?>"
           class="rnl-company-opt<?= $co_on ? ' is-active' : '' ?>" title="<?= htmlspecialchars($co) ?>"<?= $co_on ? ' aria-current="page"' : '' ?>>
          <?= icon('shield-check', 13) ?> <?= htmlspecialchars($co_label) ?>
        </a>
        <?php endforeach; ?>
      </nav>
      <?php if (!$is_super && !agent_filter_is_mine($af)): ?>
      <span style="font-size:0.72rem;color:var(--text-muted);display:inline-flex;align-items:center;gap:0.3rem;"><?= icon('information-circle', 13) ?> Other agents' policies are view-only — only their agent or the Owner can update them.</span>
      <?php endif; ?>
    </div>

    <!-- SUMMARY CARDS (desktop) -->
    <div class="rnl-stat-grid" style="display:grid;grid-template-columns:repeat(6,1fr);gap:0.6rem;margin-bottom:1.25rem;">
      <?php
      $summary = [
        ['all',      'Total Policies',    $counts['total'],    'badge-gold',  'document'],
        ['urgent',   'Urgent (' . $urg_days . 'd)',  $counts['urgent'],   'badge-red',   'exclamation-triangle'],
        ['expiring', 'Expiring (' . $exp_days . 'd)',$counts['expiring'],'badge-yellow','clock'],
        ['stable',   'Stable',            $counts['stable'],   'badge-green', 'check-circle'],
        ['expired',  'Expired',           $counts['expired'],  'badge-gray',  'x-mark'],
        ['renewed',  'Renewed',           $counts['renewed'],  'badge-info',  'arrow-path'],
      ];
      foreach ($summary as [$key, $label, $count, $badge, $ico]):
        $active_card = ($filter === $key) ? 'border-color:var(--gold-bright);background:var(--gold-pale);' : '';
      ?>
      <a href="?company=<?= urlencode($company) ?>&filter=<?= $key ?><?= $search ? '&search='.urlencode($search) : '' ?><?= htmlspecialchars($agent_qs) ?>"
         style="text-decoration:none;">
        <div class="card" style="margin-bottom:0;padding:0.65rem 0.85rem;display:flex;align-items:center;gap:0.55rem;transition:all 0.15s;<?= $active_card ?>">
          <div class="card-icon" style="width:28px;height:28px;border-radius:7px;flex-shrink:0;">
            <?= icon($ico, 13) ?>
          </div>
          <div>
            <div style="font-size:1.1rem;font-weight:800;color:var(--text-primary);line-height:1;letter-spacing:-0.5px;"><?= (int)$count ?></div>
            <div style="font-size:0.56rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.5px;margin-top:0.1rem;"><?= $label ?></div>
          </div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>

    <!-- TOOLBAR -->
    <form method="GET" action="" class="rnl-filter-form" style="margin-bottom:1rem;">
      <div class="rnl-filter-inner" style="display:flex;gap:0.6rem;align-items:center;flex-wrap:wrap;">
        <!-- Mobile-only filter dropdown (replaces stat cards) -->
        <select name="filter" class="filter-input rnl-filter-select" onchange="this.form.submit()" style="display:none;">
          <?php foreach ($summary as [$key, $label, $count, $badge, $ico]): ?>
          <option value="<?= $key ?>" <?= $filter === $key ? 'selected' : '' ?>><?= $label ?> (<?= (int)$count ?>)</option>
          <?php endforeach; ?>
        </select>
        <!-- Desktop hidden filter passthrough -->
        <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>" class="rnl-filter-hidden"/>
        <input type="hidden" name="company" value="<?= htmlspecialchars($company) ?>"/>
        <?php render_agent_filter($af, $base_path, 'Show the policies of an insurance agent\'s clients'); ?>
        <div class="rnl-filter-search" style="position:relative;flex:1;min-width:200px;max-width:420px;">
          <span style="position:absolute;left:0.85rem;top:50%;transform:translateY(-50%);color:var(--text-muted);pointer-events:none;"><?= icon('magnifying-glass', 14) ?></span>
          <input type="text" name="search" class="filter-input"
            placeholder="Search by client, contact number, plate number, or policy number..."
            value="<?= htmlspecialchars($search) ?>"
            style="padding-left:2.4rem;width:100%;"/>
        </div>
        <button type="submit" class="btn-primary rnl-filter-btn"><?= icon('magnifying-glass', 14) ?> Search</button>
        <?php if ($search): ?>
        <a href="?company=<?= urlencode($company) ?>&filter=<?= $filter ?><?= htmlspecialchars($agent_qs) ?>" class="btn-ghost rnl-filter-clear"><?= icon('x-mark', 14) ?> Clear</a>
        <?php endif; ?>
      </div>
    </form>

    <!-- TABLE -->
    <div class="card" style="margin-bottom:0;">
      <div class="card-header">
        <div class="card-icon"><?= icon('clock', 16) ?></div>
        <div>
          <div class="card-title">
            <?php
            $titles = [
              'all'      => 'All Policies',
              'urgent'   => 'Urgent — Expiring Within ' . $urg_days . ' Days',
              'expiring' => 'Expiring Within ' . $exp_days . ' Days',
              'stable'   => 'Stable Policies',
              'expired'  => 'Expired Policies',
              'renewed'  => 'Renewed Policies',
            ];
            echo $titles[$filter] ?? 'All Policies';
            ?>
          </div>
          <div class="card-sub"><?= paginate_summary($pg) ?> &middot; <?= htmlspecialchars(agent_filter_label($af)) ?></div>
        </div>
      </div>

      <?php if ($policies->num_rows > 0): ?>
      <div class="tg-table-wrap mob-card-wrap">
        <table class="tg-table mob-card mob-renewal-table renewal-list-table">
          <thead>
            <tr>
              <th style="text-align:center;">Client</th>
              <th style="text-align:center;">Plate</th>
              <th style="text-align:center;">Expiry Date</th>
              <th style="text-align:center;">Status</th>
              <th style="text-align:center;">Payment</th>
              <th style="text-align:right;">Balance</th>
              <th style="text-align:center;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php while ($row = $policies->fetch_assoc()):
              $rid  = 'rnl-expand-' . $row['policy_id'];
              $days = (int)$row['days_left'];

              if ($row['policy_end'] < date('Y-m-d')) {
                $status_badge = '<span class="badge badge-gray">Expired</span>';
                $row_style    = '';
              } elseif ($days <= $urg_days) {
                $status_badge = '<span class="badge badge-red">' . icon('exclamation-triangle', 10) . ' Urgent &mdash; ' . $days . 'd left</span>';
                $row_style    = 'background:rgba(192,57,43,0.03);';
              } elseif ($days <= $exp_days) {
                $status_badge = '<span class="badge badge-yellow">' . icon('clock', 10) . ' Expiring &mdash; ' . $days . 'd left</span>';
                $row_style    = 'background:rgba(184,134,11,0.03);';
              } else {
                $status_badge = '<span class="badge badge-green">' . icon('check-circle', 10) . ' Stable</span>';
                $row_style    = '';
              }
              // Show Renewed badge for 3 days after renewal, then let it fade to normal status
              if ($row['renewed_at'] && strtotime($row['renewed_at']) >= strtotime('-3 days')) {
                $status_badge .= ' <span class="badge badge-info">' . icon('arrow-path', 10) . ' Renewed</span>';
              }

              $pay_badge = match($row['payment_status']) {
                'Paid'    => '<span class="badge badge-green">Paid</span>',
                'Partial' => '<span class="badge badge-yellow">Partial</span>',
                'Overdue' => '<span class="badge badge-orange">Overdue</span>',
                default   => '<span class="badge badge-red">Unpaid</span>',
              };

              $agent_initials = '';
              if (!empty($row['agent_name'])) {
                  $agent_initials = substr(implode('', array_map(fn($w) => strtoupper($w[0] ?? ''), explode(' ', trim($row['agent_name'])))), 0, 2);
              }
            ?>
            <tr class="tg-expandable-row" data-expand="<?= $rid ?>" tabindex="0" style="cursor:pointer;<?= $row_style ?>">
              <td style="text-align:center;">
                <div style="display:flex;align-items:center;justify-content:center;gap:0.5rem;">
                  <svg class="row-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="flex-shrink:0;opacity:0.35;transition:transform 0.2s;"><polyline points="9 18 15 12 9 6"/></svg>
                  <div>
                    <div style="font-weight:700;color:var(--text-primary);font-size:0.82rem;"><?= htmlspecialchars($row['full_name']) ?></div>
                    <div style="font-size:0.7rem;color:var(--text-muted);"><?= htmlspecialchars($row['contact_number']) ?></div>
                  </div>
                </div>
              </td>
              <td style="text-align:center;"><span class="badge-dark"><?= htmlspecialchars($row['plate_number']) ?></span></td>
              <td style="text-align:center;">
                <div style="font-size:0.82rem;font-weight:700;color:var(--text-primary);"><?= date('M d, Y', strtotime($row['policy_end'])) ?></div>
                <div style="font-size:0.68rem;color:var(--text-muted);"><?= date('M d, Y', strtotime($row['policy_start'])) ?> &mdash; start</div>
              </td>
              <td style="text-align:center;"><?= $status_badge ?></td>
              <td style="text-align:center;"><?= $pay_badge ?></td>
              <td style="text-align:right;">
                <?php if ($row['balance'] > 0): ?>
                  <span style="color:var(--warning);font-weight:700;font-size:0.82rem;">PHP <?= number_format($row['balance'], 2) ?></span>
                <?php else: ?>
                  <span style="color:var(--success);font-weight:700;font-size:0.82rem;"><?= icon('check', 12) ?> Cleared</span>
                <?php endif; ?>
              </td>
              <td style="text-align:center;" onclick="event.stopPropagation()">
                <a href="view_policy.php?id=<?= $row['policy_id'] ?>" class="btn-sm-gold" title="View" style="padding:0.35rem 0.55rem;">
                  <?= icon('eye', 14) ?>
                </a>
              </td>
            </tr>
            <tr class="tg-expand-row" id="<?= $rid ?>" style="display:none;">
              <td colspan="7" style="padding:0;">
                <div class="tg-expand-body">
                  <div class="tg-expand-grid">
                    <div class="tg-expand-item">
                      <span class="tg-expand-label">Policy Number</span>
                      <span class="tg-expand-value"><?= htmlspecialchars($row['policy_number']) ?></span>
                    </div>
                    <div class="tg-expand-item">
                      <span class="tg-expand-label">Coverage</span>
                      <span class="tg-expand-value"><?= htmlspecialchars($row['coverage_type']) ?></span>
                    </div>
                    <div class="tg-expand-item">
                      <span class="tg-expand-label">Sum Insured</span>
                      <span class="tg-expand-value">PHP <?= number_format($row['sum_insured'], 2) ?></span>
                    </div>
                    <div class="tg-expand-item">
                      <span class="tg-expand-label">Total Premium</span>
                      <span class="tg-expand-value">PHP <?= number_format($row['total_premium'], 2) ?></span>
                    </div>
                    <div class="tg-expand-item">
                      <span class="tg-expand-label">Insurance Agent</span>
                      <span class="tg-expand-value">
                        <?php if (!empty($row['agent_name'])): ?>
                        <div style="display:inline-flex;align-items:center;gap:0.45rem;">
                          <div style="width:22px;height:22px;border-radius:50%;background:linear-gradient(135deg,var(--gold-bright),var(--gold));display:flex;align-items:center;justify-content:center;font-size:0.56rem;font-weight:800;color:#fff;flex-shrink:0;overflow:hidden;">
                            <?php if (!empty($row['agent_photo'])): ?>
                              <img src="<?= $base_path ?>uploads/avatars/<?= htmlspecialchars($row['agent_photo']) ?>" alt="" loading="lazy" style="width:100%;height:100%;object-fit:cover;"/>
                            <?php else: ?>
                              <?= htmlspecialchars($agent_initials) ?>
                            <?php endif; ?>
                          </div>
                          <span><?= htmlspecialchars($row['agent_name']) ?></span>
                        </div>
                        <?php else: ?>
                          <em>Unassigned</em>
                        <?php endif; ?>
                      </span>
                    </div>
                    <div class="tg-expand-item">
                      <span class="tg-expand-label">Added By</span>
                      <span class="tg-expand-value"><?= !empty($row['added_by_name']) ? htmlspecialchars($row['added_by_name']) : '—' ?></span>
                    </div>
                  </div>
                </div>
              </td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
      <?php render_pagination($pg); ?>

      <?php else: ?>
      <div class="empty-state">
        <div class="empty-icon-wrap"><?= icon('clock', 26) ?></div>
        <div class="empty-title">No policies found</div>
        <div class="empty-desc">
          <?= $search ? 'No results for your search.' : 'No policies in this category yet.' ?>
          <?php if ($af['value'] !== 'all'): ?>
          Only <?= htmlspecialchars(agent_filter_is_mine($af) ? 'your clients\'' : ($af['value'] === 'none' ? 'unassigned clients\'' : agent_filter_name($af) . '\'s clients\'')) ?> policies are shown.
          <?php endif; ?>
        </div>
        <?php if ($af['value'] !== 'all'): ?>
        <a href="?<?= htmlspecialchars(http_build_query(['company' => $company, 'filter' => $filter, 'search' => $search, 'agent' => 'all'])) ?>" class="btn-primary" style="margin-top:0.9rem;"><?= icon('users', 14) ?> Show All Agents</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

  </div>
</div>

<?php
$footer_scripts = '';
if (!empty($_GET['success'])) {
    $footer_scripts = 'Swal.fire({ toast:true, position:"top-end", icon:"success", titleText:' . json_encode($_GET['success']) . ', showConfirmButton:false, timer:3000, timerProgressBar:true });';
} elseif (!empty($_GET['msg'])) {
    $footer_scripts = 'Swal.fire({ toast:true, position:"top-end", icon:"info", titleText:' . json_encode($_GET['msg']) . ', showConfirmButton:false, timer:3000, timerProgressBar:true });';
}
// Row details open on hover on desktop (CSS-driven) — stop clicks on this
// table from reaching the global click-to-toggle handler in footer.php so it
// can't fight with the hover state. Mobile/tablet has no real hover, so let
// those clicks through to keep the original tap-to-expand behavior there.
$footer_scripts .= '
var rnlTable = document.querySelector(".renewal-list-table");
if (rnlTable) rnlTable.addEventListener("click", function(e) { if (window.innerWidth > 768) e.stopPropagation(); });
';
$footer_extra_scripts = '<script src="../../assets/js/shared/agent_filter.js?v=' . filemtime(__DIR__ . '/../../assets/js/shared/agent_filter.js') . '"></script>';
require_once '../../includes/footer.php';
?>