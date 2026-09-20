<?php
require_once __DIR__ . "/../../config/session.php";
require_once '../../config/db.php';
require_once '../../config/validators.php';
require_once '../../config/settings.php';

$urg_days = (int)getSetting($conn, 'renewal_urgent_days', '7');
$exp_days = (int)getSetting($conn, 'renewal_expiring_days', '30');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header("Location: ../../auth/login.php");
    exit;
}

$is_super = $_SESSION['role'] === 'super_admin';

// ── VAULT PASSWORD GATE (Admin only — Super Admin always has full access) ──
// Unlock state is tied to a version stamp, not a plain boolean: whenever the
// Super Admin changes the vault password, renewal_vault_updated_at changes too,
// which instantly invalidates every Admin session's stored unlock — including
// sessions that were already unlocked — without needing to touch other sessions.
$vault_hash    = getSetting($conn, 'renewal_vault_password', '');
$vault_version = getSetting($conn, 'renewal_vault_updated_at', '0');
$vault_unlocked = $is_super || (!empty($_SESSION['renewal_vault_unlocked_at']) && $_SESSION['renewal_vault_unlocked_at'] === $vault_version);
$vault_error    = '';

if (!$is_super && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['vault_password'])) {
    csrf_verify();
    if (!empty($vault_hash) && is_string($_POST['vault_password']) && password_verify($_POST['vault_password'], $vault_hash)) {
        $_SESSION['renewal_vault_unlocked_at'] = $vault_version;
        $vault_unlocked = true;
    } else {
        $vault_error = 'Incorrect password.';
    }
}

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
        CASE WHEN u.is_hidden = 1 THEN 'System Administrator' ELSE u.full_name END AS added_by_name,
        CASE WHEN u.is_hidden = 1 THEN NULL ELSE u.profile_photo END AS added_by_photo
    FROM insurance_policies p
    INNER JOIN clients c ON p.client_id = c.client_id
    INNER JOIN vehicles v ON p.vehicle_id = v.vehicle_id
    LEFT JOIN users u ON p.created_by = u.user_id
    $where_sql
    ORDER BY p.policy_end ASC
";

$stmt = $conn->prepare($sql);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$policies = $stmt->get_result();

// ── SUMMARY COUNTS ──
$exp_start_count = $urg_days + 1;
$counts_stmt = $conn->prepare("
    SELECT
        SUM(CASE WHEN is_renewed = 0 THEN 1 ELSE 0 END) AS total,
        SUM(CASE WHEN is_renewed = 0 AND policy_end >= CURDATE() AND DATEDIFF(policy_end, CURDATE()) <= $urg_days THEN 1 ELSE 0 END) AS urgent,
        SUM(CASE WHEN is_renewed = 0 AND policy_end >= CURDATE() AND DATEDIFF(policy_end, CURDATE()) BETWEEN $exp_start_count AND $exp_days THEN 1 ELSE 0 END) AS expiring,
        SUM(CASE WHEN is_renewed = 0 AND policy_end >= CURDATE() AND DATEDIFF(policy_end, CURDATE()) > $exp_days THEN 1 ELSE 0 END) AS stable,
        SUM(CASE WHEN is_renewed = 0 AND policy_end < CURDATE() THEN 1 ELSE 0 END) AS expired,
        SUM(CASE WHEN is_renewed = 0 AND renewed_at IS NOT NULL THEN 1 ELSE 0 END) AS renewed
    FROM insurance_policies
    WHERE insurance_company = ?
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

<link rel="stylesheet" href="../../assets/css/shared/clients.css"/>
<style>
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

    <?php if (!$vault_unlocked): ?>

    <!-- VAULT LOCK SCREEN (Admin only) -->
    <div style="display:flex;align-items:center;justify-content:center;min-height:60vh;">
    <div class="card" style="max-width:420px;width:100%;margin:0;text-align:center;padding:2.5rem 2rem;">
      <div class="empty-icon-wrap" style="margin:0 auto 1.25rem;"><?= icon('lock-closed', 26) ?></div>
      <div style="font-size:1.05rem;font-weight:800;color:var(--text-primary);margin-bottom:0.4rem;">Renewal Records Locked</div>
      <?php if (empty($vault_hash)): ?>
      <p style="font-size:0.82rem;color:var(--text-muted);line-height:1.6;">The vault password has not been set yet. Please ask the Super Admin to configure it in Settings before this can be unlocked.</p>
      <?php else: ?>
      <p style="font-size:0.82rem;color:var(--text-muted);line-height:1.6;margin-bottom:1.25rem;">Enter the vault password to view PhilBritish and Alpha Insurance renewal records.</p>
      <form method="POST" action="" style="display:flex;flex-direction:column;gap:0.75rem;">
        <?= csrf_field() ?>
        <input type="password" name="vault_password" class="field-input" placeholder="Vault password" autofocus style="text-align:center;"/>
        <?php if ($vault_error): ?><div class="field-error-msg" style="justify-content:center;"><?= icon('exclamation-triangle', 12) ?> <?= htmlspecialchars($vault_error) ?></div><?php endif; ?>
        <button type="submit" class="btn-primary" style="justify-content:center;"><?= icon('lock-closed', 14) ?> Unlock</button>
      </form>
      <?php endif; ?>
    </div>
    </div>

    <?php else: ?>

    <!-- Current company indicator -->
    <div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:1.25rem;">
      <span class="badge badge-gold" style="display:inline-flex;align-items:center;gap:0.35rem;font-size:0.72rem;padding:0.35rem 0.75rem;">
        <?= icon('shield-check', 12) ?> <?= htmlspecialchars($company) ?>
      </span>
      <span style="font-size:0.72rem;color:var(--text-muted);">Switch company from the Policy &rsaquo; Renewal Tracking menu in the sidebar.</span>
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
      <a href="?company=<?= urlencode($company) ?>&filter=<?= $key ?><?= $search ? '&search='.urlencode($search) : '' ?>"
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
        <div class="rnl-filter-search" style="position:relative;flex:1;min-width:200px;max-width:420px;">
          <span style="position:absolute;left:0.85rem;top:50%;transform:translateY(-50%);color:var(--text-muted);pointer-events:none;"><?= icon('magnifying-glass', 14) ?></span>
          <input type="text" name="search" class="filter-input"
            placeholder="Search by client, contact number, plate number, or policy number..."
            value="<?= htmlspecialchars($search) ?>"
            style="padding-left:2.4rem;width:100%;"/>
        </div>
        <button type="submit" class="btn-primary rnl-filter-btn"><?= icon('magnifying-glass', 14) ?> Search</button>
        <?php if ($search): ?>
        <a href="?company=<?= urlencode($company) ?>&filter=<?= $filter ?>" class="btn-ghost rnl-filter-clear"><?= icon('x-mark', 14) ?> Clear</a>
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
          <div class="card-sub"><?= $policies->num_rows ?> record<?= $policies->num_rows !== 1 ? 's' : '' ?></div>
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

              $aby_initials = '';
              if (!empty($row['added_by_name'])) {
                  $aby_initials = substr(implode('', array_map(fn($w) => strtoupper($w[0] ?? ''), explode(' ', trim($row['added_by_name'])))), 0, 2);
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
                      <span class="tg-expand-label">Added By</span>
                      <span class="tg-expand-value">
                        <?php if (!empty($row['added_by_name'])): ?>
                        <div style="display:inline-flex;align-items:center;gap:0.45rem;">
                          <div style="width:22px;height:22px;border-radius:50%;background:linear-gradient(135deg,var(--gold-bright),var(--gold));display:flex;align-items:center;justify-content:center;font-size:0.56rem;font-weight:800;color:#fff;flex-shrink:0;overflow:hidden;">
                            <?php if (!empty($row['added_by_photo'])): ?>
                              <img src="<?= $base_path ?>uploads/avatars/<?= htmlspecialchars($row['added_by_photo']) ?>" alt="" loading="lazy" style="width:100%;height:100%;object-fit:cover;"/>
                            <?php else: ?>
                              <?= htmlspecialchars($aby_initials) ?>
                            <?php endif; ?>
                          </div>
                          <span><?= htmlspecialchars($row['added_by_name']) ?></span>
                        </div>
                        <?php else: ?>
                          —
                        <?php endif; ?>
                      </span>
                    </div>
                  </div>
                </div>
              </td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>

      <?php else: ?>
      <div class="empty-state">
        <div class="empty-icon-wrap"><?= icon('clock', 26) ?></div>
        <div class="empty-title">No policies found</div>
        <div class="empty-desc">
          <?= $search ? 'No results for your search.' : 'No policies in this category yet.' ?>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <?php endif; ?>

  </div>
</div>

<?php
$footer_scripts = '';
if (!empty($_GET['success'])) {
    $footer_scripts = 'Swal.fire({ toast:true, position:"top-end", icon:"success", title:' . json_encode($_GET['success']) . ', showConfirmButton:false, timer:3000, timerProgressBar:true });';
} elseif (!empty($_GET['msg'])) {
    $footer_scripts = 'Swal.fire({ toast:true, position:"top-end", icon:"info", title:' . json_encode($_GET['msg']) . ', showConfirmButton:false, timer:3000, timerProgressBar:true });';
}
// Row details open on hover on desktop (CSS-driven) — stop clicks on this
// table from reaching the global click-to-toggle handler in footer.php so it
// can't fight with the hover state. Mobile/tablet has no real hover, so let
// those clicks through to keep the original tap-to-expand behavior there.
$footer_scripts .= '
var rnlTable = document.querySelector(".renewal-list-table");
if (rnlTable) rnlTable.addEventListener("click", function(e) { if (window.innerWidth > 768) e.stopPropagation(); });
';
require_once '../../includes/footer.php';
?>