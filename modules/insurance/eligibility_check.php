<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header("Location: ../../login.php");
    exit;
}

$full_name = $_SESSION['full_name'];
$initials  = substr(implode('', array_map(fn($w) => strtoupper($w[0]), explode(' ', $full_name))), 0, 2);

$search         = trim($_GET['search'] ?? '');
$selected_vid   = isset($_GET['vehicle_id']) ? (int)$_GET['vehicle_id'] : 0;
$search_results = [];
$vehicle        = null;
$client         = null;
$eligibility    = null;

// Search clients and vehicles
if ($search !== '') {
    $like = "%$search%";
    $stmt = $conn->prepare("
        SELECT c.client_id, c.full_name, c.contact_number,
               v.vehicle_id, v.plate_number, v.make, v.model, v.year_model, v.color
        FROM clients c
        INNER JOIN vehicles v ON c.client_id = v.client_id
        WHERE c.full_name LIKE ? OR v.plate_number LIKE ?
        ORDER BY c.full_name ASC
    ");
    $stmt->bind_param('ss', $like, $like);
    $stmt->execute();
    $search_results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Load selected vehicle and compute eligibility
if ($selected_vid > 0) {
    $stmt = $conn->prepare("
        SELECT c.client_id, c.full_name, c.contact_number, c.address,
               v.vehicle_id, v.plate_number, v.make, v.model,
               v.year_model, v.color, v.motor_number, v.serial_number
        FROM vehicles v
        INNER JOIN clients c ON v.client_id = c.client_id
        WHERE v.vehicle_id = ?
    ");
    $stmt->bind_param('i', $selected_vid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if ($row) {
        $vehicle = $row;
        $client  = $row;

        $current_year = (int)date('Y');
        $year_model   = (int)$row['year_model'];
        $age          = $current_year - $year_model;

        $eligible = $age <= 10;

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

<div class="main">

  <?php
$topbar_title      = 'Insurance Eligibility Check';
$topbar_breadcrumb = ['Insurance', 'Eligibility Check'];
require_once '../../includes/topbar.php';
?>

  <div class="content">

    <a href="../dashboard_admin.php" class="back-link"><?= icon('arrow-left', 14) ?> Back to Dashboard</a>

    <div class="page-header">
      <div class="page-header-title"><?= icon('shield-check', 18) ?> Insurance Eligibility and Policy Processing</div>
      <div class="page-header-sub">Search a client, select a vehicle, and check PhilBritish Insurance eligibility before encoding a policy.</div>
    </div>

    <!-- INFO BOX -->
    <div class="info-box" style="margin-bottom:1.25rem;">
      <?= icon('information-circle', 16) ?>
      <span>PhilBritish Insurance covers vehicles that are <strong>10 years old or newer</strong> based on the year model. Vehicles older than 10 years are not eligible for new policy coverage.</span>
    </div>

    <!-- SEARCH CARD -->
    <div class="card" style="margin-bottom:1.25rem;">
      <div class="card-header">
        <div class="card-icon"><?= icon('magnifying-glass', 16) ?></div>
        <div>
          <div class="card-title">Search Client or Vehicle</div>
          <div class="card-sub">Search by client name or plate number</div>
        </div>
      </div>
      <div style="padding:1.5rem;">
        <form method="GET" action="">
          <div style="display:flex;gap:0.75rem;">
            <div style="position:relative;flex:1;">
              <span style="position:absolute;left:0.85rem;top:50%;transform:translateY(-50%);color:var(--text-muted);pointer-events:none;font-size:0.85rem;line-height:1;"><?= icon('magnifying-glass', 14) ?></span>
              <input
                type="text"
                name="search"
                placeholder="e.g. Juan dela Cruz or NCH 7952"
                value="<?= htmlspecialchars($search) ?>"
                autocomplete="off"
                style="width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text-primary);padding:0.7rem 0.9rem 0.7rem 2.4rem;border-radius:9px;font-family:'Plus Jakarta Sans',sans-serif;font-size:0.85rem;outline:none;transition:border-color 0.15s,box-shadow 0.15s;"
                onfocus="this.style.borderColor='var(--gold-bright)';this.style.boxShadow='0 0 0 3px rgba(212,160,23,0.1)'"
                onblur="this.style.borderColor='var(--border)';this.style.boxShadow='none'"
              />
            </div>
            <button type="submit" class="btn-primary"><?= icon('shield-check', 14) ?> Check Eligibility</button>
          </div>
        </form>

        <?php if ($search !== '' && count($search_results) === 0): ?>
          <div class="empty-state">
            <div class="empty-icon"><?= icon('magnifying-glass', 28) ?></div>
            <div class="empty-title">No results found</div>
            <div class="empty-desc">Try a different client name or plate number.</div>
          </div>

        <?php elseif (count($search_results) > 0 && $selected_vid === 0): ?>
          <div style="margin-top:1.25rem;">
            <table class="tg-table">
              <thead>
                <tr>
                  <th>Client Name</th>
                  <th>Plate Number</th>
                  <th>Vehicle</th>
                  <th>Year Model</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($search_results as $r): ?>
                <tr>
                  <td style="font-weight:700;color:var(--text-primary);"><?= htmlspecialchars($r['full_name']) ?></td>
                  <td><span class="badge badge-dark"><?= htmlspecialchars($r['plate_number']) ?></span></td>
                  <td><?= htmlspecialchars($r['make'] . ' ' . $r['model']) ?></td>
                  <td><?= htmlspecialchars($r['year_model']) ?></td>
                  <td>
                    <a href="?search=<?= urlencode($search) ?>&vehicle_id=<?= $r['vehicle_id'] ?>" class="btn-sm-gold">
                      <?= icon('shield-check', 12) ?> Check
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
          $reason       = 'This vehicle is ' . $eligibility['age'] . ' year' . ($eligibility['age'] !== 1 ? 's' : '') . ' old and qualifies under PhilBritish\'s 10-year eligibility rule. You may proceed to encode the policy.';
        } else {
          $status_class = 'ineligible';
          $icon         = icon('x-mark', 20);
          $verdict      = 'Not Eligible for Coverage';
          $reason       = 'This vehicle is ' . $eligibility['age'] . ' years old and exceeds PhilBritish\'s 10-year eligibility limit. It cannot be covered under a new policy.';
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
            <span><strong>PhilBritish Eligibility Rule:</strong> Vehicles must be 10 years old or newer from the current year to qualify for insurance coverage. Year model <?= $eligibility['year_model'] ?> means the vehicle is <?= $eligibility['age'] ?> year<?= $eligibility['age'] !== 1 ? 's' : '' ?> old as of <?= date('Y') ?>.</span>
          </div>
        </div>

        <!-- Actions -->
        <div style="padding:1rem 1.5rem;background:var(--bg-2);border-top:1px solid var(--border);display:flex;gap:0.75rem;align-items:center;">
          <?php if ($eligibility['eligible'] && !$eligibility['has_active_policy']): ?>
            <a href="add_policy.php?vehicle_id=<?= $vehicle['vehicle_id'] ?>" class="btn-primary">
              <?= icon('document', 14) ?> Proceed to Policy Encoding
            </a>
          <?php endif; ?>
          <a href="eligibility_check.php" class="btn-ghost"><?= icon('arrow-left', 14) ?> New Search</a>
        </div>
      </div>

    <?php endif; ?>

  </div>
</div>

<?php require_once '../../includes/footer.php'; ?>