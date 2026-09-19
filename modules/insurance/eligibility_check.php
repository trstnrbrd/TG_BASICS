<?php
require_once __DIR__ . "/../../config/session.php";
require_once '../../config/db.php';
require_once '../../config/validators.php';
require_once '../../config/settings.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header("Location: ../../auth/login.php");
    exit;
}

$is_scoped_admin = $_SESSION['role'] === 'admin';

// Unlocking the Renewal Tracking vault proves Sir PG authorized this Admin for
// cross-company records — that same trust lifts the per-admin client scoping
// here too, so a vault-unlocked Admin can search/find any client, not just
// their own. Uses the same version-stamp check as the vault gate itself.
if ($is_scoped_admin) {
    $vault_version = getSetting($conn, 'renewal_vault_updated_at', '0');
    if (!empty($_SESSION['renewal_vault_unlocked_at']) && $_SESSION['renewal_vault_unlocked_at'] === $vault_version) {
        $is_scoped_admin = false;
    }
}

// AJAX handler
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    $q = san_str($_GET['search'] ?? '', 100);
    if ($q === '') { echo ''; exit; }
    $like = "%$q%";
    $scope_sql = $is_scoped_admin ? "AND c.created_by = ?" : '';
    $stmt = $conn->prepare("
        SELECT c.client_id, c.full_name, c.contact_number,
               v.vehicle_id, v.plate_number, v.make, v.model, v.year_model
        FROM clients c
        INNER JOIN vehicles v ON c.client_id = v.client_id
        WHERE c.deleted_at IS NULL
          AND (c.full_name LIKE ? OR v.plate_number LIKE ? OR v.make LIKE ?
           OR v.model LIKE ? OR c.contact_number LIKE ? OR v.motor_number LIKE ?
           OR v.serial_number LIKE ? OR CONCAT(v.make,' ',v.model) LIKE ?)
          $scope_sql
        ORDER BY c.full_name ASC
        LIMIT 8
    ");
    if ($is_scoped_admin) {
        $stmt->bind_param('ssssssssi', $like, $like, $like, $like, $like, $like, $like, $like, $_SESSION['user_id']);
    } else {
        $stmt->bind_param('ssssssss', $like, $like, $like, $like, $like, $like, $like, $like);
    }
    $stmt->execute();
    $rows = $stmt->get_result();
    if ($rows->num_rows === 0) {
        echo '<div style="padding:1rem;text-align:center;font-size:0.8rem;color:var(--text-muted);">No results found</div>';
        exit;
    }
    while ($r = $rows->fetch_assoc()) {
        echo '
        <div class="live-result-item" onclick="window.location=\'?vehicle_id=' . $r['vehicle_id'] . '\'" style="padding:0.75rem 1rem;cursor:pointer;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border);transition:background 0.1s;" onmouseover="this.style.background=\'var(--gold-pale)\'" onmouseout="this.style.background=\'\'">
          <div>
            <div style="font-weight:700;font-size:0.85rem;color:var(--text-primary);">' . htmlspecialchars($r['full_name']) . '</div>
            <div style="font-size:0.72rem;color:var(--text-muted);">' . htmlspecialchars($r['make'] . ' ' . $r['model'] . ' ' . $r['year_model']) . '</div>
          </div>
          <span class="badge-dark">' . htmlspecialchars($r['plate_number']) . '</span>
        </div>';
    }
    exit;
}

$full_name = $_SESSION['full_name'];
$initials  = substr(implode('', array_map(fn($w) => strtoupper($w[0]), explode(' ', $full_name))), 0, 2);

$search         = validate_search(san_str($_GET['search'] ?? '', MAX_SEARCH));
$selected_vid   = isset($_GET['vehicle_id']) ? (int)$_GET['vehicle_id'] : 0;
$search_results = [];
$vehicle        = null;
$client         = null;
$eligibility    = null;

// Search clients and vehicles
if ($search !== '') {
    $like = "%$search%";
    $scope_sql = $is_scoped_admin ? "AND c.created_by = ?" : '';
    $stmt = $conn->prepare("
        SELECT c.client_id, c.full_name, c.contact_number,
               v.vehicle_id, v.plate_number, v.make, v.model, v.year_model, v.color
        FROM clients c
        INNER JOIN vehicles v ON c.client_id = v.client_id
        WHERE c.deleted_at IS NULL
          AND (c.full_name LIKE ? OR v.plate_number LIKE ? OR v.make LIKE ?
           OR v.model LIKE ? OR c.contact_number LIKE ? OR v.motor_number LIKE ?
           OR v.serial_number LIKE ? OR CONCAT(v.make,' ',v.model) LIKE ?)
          $scope_sql
        ORDER BY c.full_name ASC
    ");
    if ($is_scoped_admin) {
        $stmt->bind_param('ssssssssi', $like, $like, $like, $like, $like, $like, $like, $like, $_SESSION['user_id']);
    } else {
        $stmt->bind_param('ssssssss', $like, $like, $like, $like, $like, $like, $like, $like);
    }
    $stmt->execute();
    $search_results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Load selected vehicle and compute eligibility
if ($selected_vid > 0) {
    $vid_scope_sql = $is_scoped_admin ? "AND c.created_by = ?" : '';
    $stmt = $conn->prepare("
        SELECT c.client_id, c.full_name, c.contact_number, c.address,
               v.vehicle_id, v.plate_number, v.make, v.model,
               v.year_model, v.color, v.motor_number, v.serial_number
        FROM vehicles v
        INNER JOIN clients c ON v.client_id = c.client_id
        WHERE v.vehicle_id = ? AND c.deleted_at IS NULL
        $vid_scope_sql
    ");
    if ($is_scoped_admin) {
        $stmt->bind_param('ii', $selected_vid, $_SESSION['user_id']);
    } else {
        $stmt->bind_param('i', $selected_vid);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if ($row) {
        $vehicle = $row;
        $client  = $row;

        $current_year    = (int)date('Y');
        $year_model      = (int)$row['year_model'];
        $age             = $current_year - $year_model;
        $max_vehicle_age = (int)getSetting($conn, 'eligibility_max_age', '10');

        $eligible = $age <= $max_vehicle_age;

        $policy_check = $conn->prepare("
            SELECT policy_id FROM insurance_policies
            WHERE vehicle_id = ? AND policy_end >= CURDATE()
            LIMIT 1
        ");
        $policy_check->bind_param('i', $selected_vid);
        $policy_check->execute();
        $has_active_policy = $policy_check->get_result()->num_rows > 0;

        $eligibility = [
            'age'               => $age,
            'year_model'        => $year_model,
            'eligible'          => $eligible,
            'has_active_policy' => $has_active_policy,
        ];
    }
}

$page_title  = 'Insurance Eligibility Check';
$active_page = 'insurance';
$base_path   = '../../';
require_once '../../includes/header.php';
require_once '../../includes/navbar.php';
?>

<style>
/* Custom-drawn radio circle — native accent-color rendering can overflow the
   ring on some browsers, so this is fully hand-drawn to keep the dot centered
   and proportional regardless of OS/browser. */
.tg-radio-gold {
  appearance: none; -webkit-appearance: none;
  margin: 0; width: 16px; height: 16px; flex-shrink: 0;
  border: 2px solid var(--border); border-radius: 50%;
  cursor: pointer; position: relative;
  transition: border-color 0.15s;
}
.tg-radio-gold:checked { border-color: var(--gold-bright); }
.tg-radio-gold:checked::after {
  content: ''; position: absolute; top: 50%; left: 50%;
  width: 8px; height: 8px; border-radius: 50%;
  background: var(--gold-bright);
  transform: translate(-50%, -50%);
}
</style>

<div class="main">

  <?php
$topbar_title      = 'Insurance Eligibility Check';
$topbar_breadcrumb = ['Insurance', 'Eligibility Check'];
require_once '../../includes/topbar.php';
?>

  <div class="content" style="padding-top:1rem;">

    <a href="../admin/dashboard_admin.php" class="back-link" onclick="goBack('../admin/dashboard_admin.php'); return false;" style="margin-bottom:0.5rem;"><?= icon('arrow-left', 14) ?> Back to Dashboard</a>

    <!-- INFO BOX -->
    <div class="info-box" style="margin-bottom:1.25rem;">
      <?= icon('information-circle', 16) ?>
      <span>Both PhilBritish and Alpha Insurance &amp; Surety Company Inc. cover vehicles that are <strong><?= (int)getSetting($conn, 'eligibility_max_age', '10') ?> years old or newer</strong> based on the year model. Vehicles older than <?= (int)getSetting($conn, 'eligibility_max_age', '10') ?> years are not eligible for new policy coverage with either company.</span>
    </div>

    <!-- SEARCH CARD -->
    <div class="card" style="margin-bottom:1.25rem;overflow:visible;">
      <div class="card-header">
        <div class="card-icon"><?= icon('magnifying-glass', 16) ?></div>
        <div>
          <div class="card-title">Search Client or Vehicle</div>
          <div class="card-sub">Search by name, plate, make, model, contact, engine no., or chassis no.</div>
        </div>
      </div>
      <div style="padding:1.5rem;overflow:visible;position:relative;">
        <div style="position:relative;">
  <div style="display:flex;gap:0.75rem;">
    <div style="position:relative;flex:1;">
      <span style="position:absolute;left:0.85rem;top:50%;transform:translateY(-50%);color:var(--text-muted);pointer-events:none;font-size:0.85rem;line-height:1;"><?= icon('magnifying-glass', 14) ?></span>
      <input
        type="text"
        id="live-search"
        placeholder="Search by name, plate, make, model, contact, engine no., chassis no..."
        autocomplete="off"
        autofocus
        style="width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text-primary);padding:0.7rem 0.9rem 0.7rem 2.4rem;border-radius:9px;font-family:'Plus Jakarta Sans',sans-serif;font-size:0.85rem;outline:none;transition:border-color 0.15s,box-shadow 0.15s;"
        onfocus="this.style.borderColor='var(--gold-bright)';this.style.boxShadow='0 0 0 3px rgba(212,160,23,0.1)'"
        onblur="setTimeout(()=>{document.getElementById('live-dropdown').style.display='none'},200)"
      />
    </div>
  </div>
  <div id="live-dropdown" style="display:none;position:absolute;top:100%;left:0;right:0;background:var(--bg-3);border:1px solid var(--border);border-radius:9px;box-shadow:var(--shadow-md);z-index:50;margin-top:0.35rem;overflow:hidden;"></div>
</div>

        <?php if ($search !== '' && count($search_results) === 0): ?>
          <div class="empty-state">
            <div class="empty-icon"><?= icon('magnifying-glass', 28) ?></div>
            <div class="empty-title">No results found</div>
            <div class="empty-desc">Try a different client name or plate number.</div>
          </div>

        <?php elseif (count($search_results) > 0 && $selected_vid === 0): ?>
          <div style="margin-top:1.25rem;">
<style>.tg-table tbody tr:hover { background: var(--gold-light) !important; }</style>
            <table class="tg-table mob-card mob-eligibility-table">
              <thead>
                <tr>
                  <th style="text-align:center;">Client Name</th>
                  <th style="text-align:center;">Plate Number</th>
                  <th style="text-align:center;">Vehicle</th>
                  <th style="text-align:center;">Year Model</th>
                  <th style="text-align:center;">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($search_results as $r): ?>
                <tr>
                  <td style="font-weight:700;color:var(--text-primary);text-align:center;"><?= htmlspecialchars($r['full_name']) ?></td>
                  <td style="text-align:center;"><span class="badge-dark"><?= htmlspecialchars($r['plate_number']) ?></span></td>
                  <td style="text-align:center;"><?= htmlspecialchars($r['make'] . ' ' . $r['model']) ?></td>
                  <td style="text-align:center;"><?= htmlspecialchars($r['year_model']) ?></td>
                  <td style="text-align:center;">
                    <a href="?search=<?= urlencode($search) ?>&vehicle_id=<?= $r['vehicle_id'] ?>" class="btn-sm-gold" title="Check Eligibility" style="padding:0.35rem 0.55rem;">
                      <?= icon('shield-check', 14) ?>
                    </a>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ELIGIBILITY RESULT -->
    <?php if ($eligibility !== null && $vehicle !== null): ?>

      <?php
        if ($eligibility['has_active_policy']) {
          $status_class = 'warning';
          $icon         = icon('exclamation-triangle', 20);
          $verdict      = 'Active Policy Exists';
          $reason       = $vehicle['make'] . ' ' . $vehicle['model'] . ' (' . $vehicle['plate_number'] . ') already has an active policy on record. Encode a renewal instead.';
        } elseif ($eligibility['eligible']) {
          $status_class = 'eligible';
          $icon         = icon('check-circle', 20);
          $verdict      = 'Eligible for Coverage';
          $reason       = 'This vehicle is ' . $eligibility['age'] . ' year' . ($eligibility['age'] !== 1 ? 's' : '') . ' old and qualifies under the ' . $max_vehicle_age . '-year eligibility rule. You may proceed to encode the policy.';
        } else {
          $status_class = 'ineligible';
          $icon         = icon('x-mark', 20);
          $verdict      = 'Not Eligible for Coverage';
          $reason       = 'This vehicle is ' . $eligibility['age'] . ' years old and exceeds the ' . $max_vehicle_age . '-year eligibility limit. It cannot be covered under a new policy.';
        }

        $header_styles = [
          'eligible'   => 'background:var(--success-bg);border-bottom:1px solid var(--success-border);',
          'ineligible' => 'background:var(--danger-bg);border-bottom:1px solid var(--danger-border);',
          'warning'    => 'background:var(--warning-bg);border-bottom:1px solid var(--warning-border);',
        ];
        $verdict_colors = [
          'eligible'   => 'color:var(--success);',
          'ineligible' => 'color:var(--danger);',
          'warning'    => 'color:var(--warning);',
        ];
        $icon_bg = [
          'eligible'   => 'background:rgba(46,125,82,0.12);',
          'ineligible' => 'background:rgba(192,57,43,0.12);',
          'warning'    => 'background:rgba(184,134,11,0.12);',
        ];
      ?>

      <div class="card">
        <!-- Header -->
        <div style="padding:1.25rem 1.5rem;display:flex;align-items:center;gap:1rem;<?= $header_styles[$status_class] ?>">
          <div style="width:52px;height:52px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.5rem;flex-shrink:0;<?= $icon_bg[$status_class] ?>"><?= $icon ?></div>
          <div>
            <div style="font-size:1.1rem;font-weight:800;margin-bottom:0.2rem;<?= $verdict_colors[$status_class] ?>"><?= $verdict ?></div>
            <div style="font-size:0.78rem;color:var(--text-secondary);line-height:1.5;"><?= htmlspecialchars($reason) ?></div>
          </div>
        </div>

        <!-- Details -->
        <div style="padding:1.25rem 1.5rem;">
          <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:1.25rem;">
            <?php
            $details = [
              ['Client',       $client['full_name']],
              ['Plate Number', $vehicle['plate_number']],
              ['Vehicle',      $vehicle['make'] . ' ' . $vehicle['model']],
              ['Year Model',   $vehicle['year_model']],
              ['Vehicle Age',  $eligibility['age'] . ' year' . ($eligibility['age'] !== 1 ? 's' : '') . ' old'],
              ['Color',        $vehicle['color'] ?: 'N/A'],
            ];
            foreach ($details as $d): ?>
            <div style="display:flex;flex-direction:column;gap:0.2rem;">
              <span style="font-size:0.62rem;letter-spacing:1.2px;text-transform:uppercase;color:var(--text-muted);font-weight:700;"><?= $d[0] ?></span>
              <span style="font-size:0.88rem;font-weight:700;color:var(--text-primary);"><?= htmlspecialchars($d[1]) ?></span>
            </div>
            <?php endforeach; ?>
          </div>

          <div class="info-box">
            <?= icon('document', 14) ?>
            <span><strong>Eligibility Rule:</strong> Vehicles must be <?= $max_vehicle_age ?> years old or newer from the current year to qualify for insurance coverage. Year model <?= $eligibility['year_model'] ?> means the vehicle is <?= $eligibility['age'] ?> year<?= $eligibility['age'] !== 1 ? 's' : '' ?> old as of <?= date('Y') ?>.</span>
          </div>

          <?php if ($eligibility['eligible'] && !$eligibility['has_active_policy']): ?>
          <div class="field-section" style="margin-top:1.25rem;">Insure With <span class="req">*</span></div>
          <div id="company-pick-group" style="display:flex;gap:0.75rem;flex-wrap:wrap;">
            <label class="company-pick-option" style="flex:1;min-width:220px;display:flex;align-items:center;gap:0.6rem;padding:0.85rem 1rem;border:1.5px solid var(--border);border-radius:9px;cursor:pointer;transition:border-color 0.15s,background 0.15s;">
              <input type="radio" name="insurance_company_pick" value="PhilBritish" class="tg-radio-gold"/>
              <span style="font-weight:700;font-size:0.85rem;color:var(--text-primary);">PhilBritish</span>
            </label>
            <label class="company-pick-option" style="flex:1;min-width:220px;display:flex;align-items:center;gap:0.6rem;padding:0.85rem 1rem;border:1.5px solid var(--border);border-radius:9px;cursor:pointer;transition:border-color 0.15s,background 0.15s;">
              <input type="radio" name="insurance_company_pick" value="Alpha Insurance & Surety Company Inc." class="tg-radio-gold"/>
              <span style="font-weight:700;font-size:0.85rem;color:var(--text-primary);">Alpha Insurance &amp; Surety Company Inc.</span>
            </label>
          </div>
          <span class="field-hint" id="company-pick-hint">Select which company this policy will be filed under before proceeding.</span>
          <?php endif; ?>
        </div>

        <!-- Actions -->
        <div style="padding:1rem 1.5rem;background:var(--bg-2);border-top:1px solid var(--border);display:flex;gap:0.75rem;align-items:center;">
          <?php if ($eligibility['eligible'] && !$eligibility['has_active_policy']): ?>
            <a href="#" id="proceed-to-policy-btn" data-base-url="add_policy.php?vehicle_id=<?= $vehicle['vehicle_id'] ?>" class="btn-primary" style="opacity:0.5;pointer-events:none;" aria-disabled="true">
              <?= icon('document', 14) ?> Proceed to Policy Encoding
            </a>
          <?php endif; ?>
          <a href="eligibility_check.php" class="btn-ghost"><?= icon('arrow-left', 14) ?> New Search</a>
        </div>
      </div>

    <?php endif; ?>

  </div>
</div>

<script src="../../assets/js/shared/eligibility_check.js"></script>
</script>

<?php require_once '../../includes/footer.php'; ?>