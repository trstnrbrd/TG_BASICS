<?php
require_once __DIR__ . "/../../config/session.php";
require_once '../../config/db.php';
require_once '../../config/validators.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin', 'mechanic'])) {
    header("Location: ../../auth/login.php");
    exit;
}
$is_mechanic = $_SESSION['role'] === 'mechanic';

$vehicle_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($vehicle_id === 0) {
    header("Location: client_list.php");
    exit;
}

// Load vehicle + client
$stmt = $conn->prepare("
    SELECT v.*, c.full_name AS client_name, c.client_id, c.contact_number, c.email
    FROM vehicles v
    INNER JOIN clients c ON v.client_id = c.client_id
    WHERE v.vehicle_id = ?
");
$stmt->bind_param('i', $vehicle_id);
$stmt->execute();
$v = $stmt->get_result()->fetch_assoc();

if (!$v) {
    header("Location: client_list.php?error=Vehicle not found.");
    exit;
}

$client_id = $v['client_id'];

// Load insurance policies for this vehicle
$pstmt = $conn->prepare("SELECT * FROM insurance_policies WHERE vehicle_id = ? ORDER BY created_at DESC");
$pstmt->bind_param('i', $vehicle_id);
$pstmt->execute();
$policies = $pstmt->get_result();

// Load repair jobs for this vehicle
$rjstmt = $conn->prepare("
    SELECT j.*, c.full_name AS client_name
    FROM repair_jobs j
    INNER JOIN clients c ON j.client_id = c.client_id
    WHERE j.vehicle_id = ?
    ORDER BY j.created_at DESC
");
$rjstmt->bind_param('i', $vehicle_id);
$rjstmt->execute();
$repair_jobs = $rjstmt->get_result();

// Load claims for this vehicle (via policies)
$clstmt = $conn->prepare("
    SELECT cl.*, ip.policy_number
    FROM claims cl
    INNER JOIN insurance_policies ip ON cl.policy_id = ip.policy_id
    WHERE ip.vehicle_id = ?
    ORDER BY cl.created_at DESC
");
$clstmt->bind_param('i', $vehicle_id);
$clstmt->execute();
$claims = $clstmt->get_result();

// Build Imagin Studio URL
$color_map = [
    'white'     => 'glacier-white',
    'black'     => 'midnight-black',
    'silver'    => 'star-silver',
    'gray'      => 'iron-grey',
    'grey'      => 'iron-grey',
    'red'       => 'flame-red',
    'blue'      => 'ocean-blue',
    'dark blue' => 'deep-blue',
    'navy'      => 'deep-blue',
    'green'     => 'emerald-green',
    'brown'     => 'hazel-brown',
    'beige'     => 'pearl-beige',
    'orange'    => 'sunset-orange',
    'yellow'    => 'lightning-yellow',
    'maroon'    => 'vintage-maroon',
    'pearl'     => 'pearl-white',
    'champagne' => 'champagne-gold',
];
$img_make  = strtolower(trim($v['make']));
$img_model = strtolower(str_replace(' ', '-', trim($v['model'])));
$img_year  = (int)($v['year_model'] ?? 0);
$img_paint = $color_map[strtolower(trim($v['color'] ?? ''))] ?? '';
$img_url   = 'https://cdn.imagin.studio/getimage?customer=hrjavascript-mastery'
           . '&make=' . urlencode($img_make)
           . '&modelFamily=' . urlencode($img_model)
           . ($img_year  ? '&modelYear=' . $img_year                   : '')
           . ($img_paint ? '&paintdescription=' . urlencode($img_paint) : '')
           . '&zoomType=fullscreen&angle=13';

$page_title  = 'View Vehicle';
$active_page = 'clients';
$base_path   = '../../';
require_once '../../includes/header.php';
require_once '../../includes/navbar.php';
?>

<link rel="stylesheet" href="../../assets/css/shared/clients.css"/>

<style>
/* ── VEHICLE VIEWER ── */
.vv-stage {
  position: relative;
  width: 100%;
  background: linear-gradient(160deg, var(--bg-2) 0%, var(--bg) 100%);
  border-radius: 16px;
  overflow: hidden;
  margin-bottom: 1.25rem;
  border: 1px solid var(--border);
  box-shadow: var(--shadow-lg);
}

/* top accent line */
.vv-stage::before {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 2px;
  background: linear-gradient(90deg, var(--gold-bright), var(--gold-muted), transparent);
  z-index: 2;
}

/* big 3D drag area */
.vv-canvas {
  width: 100%;
  height: 580px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: grab;
  user-select: none;
  position: relative;
}
.vv-canvas:active { cursor: grabbing; }

.vv-car-img {
  height: 460px;
  width: auto;
  max-width: 92%;
  object-fit: contain;
  pointer-events: none;
  transition: opacity 0.1s;
  filter: drop-shadow(0 32px 56px rgba(0,0,0,0.45));
}

/* fallback */
.vv-fallback {
  display: none;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.75rem;
  color: var(--text-muted);
  font-size: 0.82rem;
  height: 300px;
}

/* drag hint */
.vv-hint {
  position: absolute;
  top: 1rem; right: 1.25rem;
  display: flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.65rem;
  color: var(--text-muted);
  opacity: 0.55;
  pointer-events: none;
}

/* angle indicator dots */
.vv-angles {
  position: absolute;
  bottom: 1.1rem;
  left: 50%;
  transform: translateX(-50%);
  display: flex;
  gap: 4px;
  pointer-events: none;
}
.vv-angle-dot {
  width: 5px; height: 5px;
  border-radius: 50%;
  background: rgba(255,255,255,0.2);
}
.vv-angle-dot.active { background: var(--gold-bright); }

/* bottom info bar */
.vv-info-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1.5rem;
  padding: 1.1rem 1.5rem;
  border-top: 1px solid var(--border);
  flex-wrap: wrap;
}
.vv-title-block { flex: 1; min-width: 0; }
.vv-car-name {
  font-size: 1.45rem;
  font-weight: 900;
  color: var(--text-primary);
  letter-spacing: -0.4px;
  line-height: 1.1;
}
.vv-car-sub {
  font-size: 0.78rem;
  color: var(--text-muted);
  margin-top: 0.25rem;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}
.vv-specs-strip {
  display: flex;
  gap: 2rem;
  flex-shrink: 0;
}
.vv-spec { text-align: center; }
.vv-spec-label {
  font-size: 0.58rem;
  text-transform: uppercase;
  letter-spacing: 1px;
  color: var(--text-muted);
  font-weight: 700;
  margin-bottom: 0.2rem;
}
.vv-spec-val {
  font-size: 0.82rem;
  font-weight: 700;
  color: var(--text-primary);
  font-family: monospace;
  letter-spacing: 0.5px;
}

/* detail grid */
.vv-detail-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 1rem 1.5rem;
  padding: 1.25rem 1.5rem;
}
@media (max-width: 900px) {
  .vv-detail-grid { grid-template-columns: repeat(2, 1fr); }
  .vv-specs-strip { display: none; }
  .vv-car-img { height: 280px; }
  .vv-canvas { height: 340px; }
}
.vv-dl { font-size: 0.6rem; letter-spacing: 1.1px; text-transform: uppercase; font-weight: 700; color: var(--text-muted); margin-bottom: 0.2rem; }
.vv-dv { font-size: 0.88rem; font-weight: 600; color: var(--text-primary); }
</style>

<div class="main">

<?php
$topbar_title      = 'Vehicle Profile';
$topbar_breadcrumb = ['Records', 'Clients', htmlspecialchars($v['client_name']), 'Vehicle'];
require_once '../../includes/topbar.php';
?>

  <div class="content">

    <a href="view_client.php?id=<?= $client_id ?>" class="back-link" onclick="goBack('view_client.php?id=<?= $client_id ?>'); return false;"><?= icon('arrow-left', 14) ?> Back to <?= htmlspecialchars($v['client_name']) ?></a>

    <!-- ── BIG 3D STAGE ── -->
    <div class="vv-stage">

      <!-- Drag canvas -->
      <div class="vv-canvas car3d-wrap"
           data-make="<?= htmlspecialchars($img_make) ?>"
           data-model="<?= htmlspecialchars($img_model) ?>"
           data-year="<?= $img_year ?>"
           data-paint="<?= htmlspecialchars($img_paint) ?>">

        <img class="vv-car-img car3d-img"
             src="<?= htmlspecialchars($img_url) ?>"
             alt="<?= htmlspecialchars($v['make'] . ' ' . $v['model']) ?>"
             draggable="false"
             onerror="this.style.display='none';this.nextElementSibling.style.display='flex'"/>

        <div class="vv-fallback car3d-fallback">
          <?= icon('vehicle', 64) ?>
          <span>No 3D preview available for this vehicle</span>
        </div>

        <!-- Drag hint -->
        <div class="vv-hint car3d-hint">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M5 9l-3 3 3 3M9 5l3-3 3 3M15 19l-3 3-3-3M19 9l3 3-3 3M2 12h20M12 2v20"/></svg>
          Drag to rotate
        </div>

        <!-- Plate badge top-left -->
        <div style="position:absolute;top:1rem;left:1.25rem;z-index:2;">
          <span class="badge-dark" style="font-size:0.75rem;letter-spacing:1.5px;font-weight:800;">
            <?= htmlspecialchars($v['plate_number']) ?>
          </span>
        </div>

        <!-- Angle dots -->
        <div class="vv-angles" id="vv-angle-dots">
          <?php for ($i = 0; $i < 12; $i++): ?>
          <div class="vv-angle-dot <?= $i === 4 ? 'active' : '' ?>"></div>
          <?php endfor; ?>
        </div>
      </div>

      <!-- Info bar -->
      <div class="vv-info-bar">
        <div class="vv-title-block">
          <div class="vv-car-name"><?= htmlspecialchars($v['make'] . ' ' . $v['model']) ?></div>
          <div class="vv-car-sub">
            <?php if ($v['year_model']): ?>
            <span><?= htmlspecialchars($v['year_model']) ?></span>
            <?php endif; ?>
            <?php if ($v['color']): ?>
            <span style="width:8px;height:8px;border-radius:50%;background:var(--gold-muted);display:inline-block;"></span>
            <span><?= htmlspecialchars(ucfirst($v['color'])) ?></span>
            <?php endif; ?>
            <a href="view_client.php?id=<?= $client_id ?>" style="color:var(--gold);font-weight:600;text-decoration:none;margin-left:0.25rem;">
              <?= icon('user', 12) ?> <?= htmlspecialchars($v['client_name']) ?>
            </a>
          </div>
        </div>

        <!-- Spec strip: engine + chassis -->
        <div class="vv-specs-strip">
          <div class="vv-spec">
            <div class="vv-spec-label">Engine No.</div>
            <div class="vv-spec-val"><?= htmlspecialchars($v['motor_number'] ?: '—') ?></div>
          </div>
          <div class="vv-spec">
            <div class="vv-spec-label">Chassis No.</div>
            <div class="vv-spec-val"><?= htmlspecialchars($v['serial_number'] ?: '—') ?></div>
          </div>
        </div>

      </div>
    </div>


  </div>
</div>

<script src="../../assets/js/shared/vehicle_3d_rotation.js?v=<?= filemtime(__DIR__.'/../../assets/js/shared/vehicle_3d_rotation.js') ?>"></script>
<script>
// Sync angle dots with rotation
(function() {
  var wrap  = document.querySelector('.vv-canvas.car3d-wrap');
  var dots  = document.querySelectorAll('#vv-angle-dots .vv-angle-dot');
  if (!wrap || !dots.length) return;

  var TOTAL  = 36;
  var NDOTS  = dots.length;
  var lastDot = 4;

  function setDot(angle) {
    var idx = Math.round((angle / TOTAL) * NDOTS) % NDOTS;
    if (idx === lastDot) return;
    dots[lastDot].classList.remove('active');
    dots[idx].classList.add('active');
    lastDot = idx;
  }

  // Patch: intercept angle changes via MutationObserver on img src
  var img = wrap.querySelector('.car3d-img');
  if (!img) return;
  var observer = new MutationObserver(function() {
    var src = img.src || '';
    var m = src.match(/angle=(\d+)/);
    if (m) setDot(parseInt(m[1]));
  });
  observer.observe(img, { attributes: true, attributeFilter: ['src'] });
})();
</script>

<?php require_once '../../includes/footer.php'; ?>
