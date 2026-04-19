<?php
require_once __DIR__ . "/../../config/session.php";
require_once '../../config/db.php';
require_once '../../config/validators.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header("Location: ../../auth/login.php");
    exit;
}

$job_id = san_int($_GET['job_id'] ?? 0, 1);
if (!$job_id) { header("Location: quotation_list.php"); exit; }

// ── FETCH JOB ──
$stmt = $conn->prepare("
    SELECT j.*,
           c.client_id, c.full_name, c.contact_number, c.email, c.address,
           v.vehicle_id, v.plate_number, v.make, v.model, v.year_model, v.color
    FROM repair_jobs j
    INNER JOIN clients  c ON j.client_id  = c.client_id
    INNER JOIN vehicles v ON j.vehicle_id = v.vehicle_id
    WHERE j.job_id = ?
");
$stmt->bind_param('i', $job_id);
$stmt->execute();
$job = $stmt->get_result()->fetch_assoc();
if (!$job) { header("Location: quotation_list.php"); exit; }

// Block if quotation already exists for this job
$chk = $conn->prepare("SELECT quotation_id, quotation_number FROM quotations WHERE job_id = ? LIMIT 1");
$chk->bind_param('i', $job_id);
$chk->execute();
$existing = $chk->get_result()->fetch_assoc();
if ($existing) {
    header("Location: view_quotation.php?id={$existing['quotation_id']}&msg=" . urlencode('Quotation already exists for this job.'));
    exit;
}

// ── FETCH CHECKLIST (pre-fill line items from damage) ──
$cl_stmt = $conn->prepare("SELECT area_key, condition_value, notes FROM repair_checklist WHERE job_id = ? AND condition_value != 'none'");
$cl_stmt->bind_param('i', $job_id);
$cl_stmt->execute();
$damaged_areas = $cl_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$area_labels = [
    'front_bumper' => 'Front Bumper', 'rear_bumper'  => 'Rear Bumper',
    'hood'         => 'Hood',         'trunk'        => 'Trunk',
    'windshield'   => 'Windshield',   'door_fl'      => 'Left Front Door',
    'door_rl'      => 'Left Rear Door','door_fr'     => 'Right Front Door',
    'door_rr'      => 'Right Rear Door','mirror_left' => 'Left Mirror',
    'mirror_right' => 'Right Mirror', 'headlights'   => 'Headlights',
    'taillights'   => 'Taillights',   'tires_wheels' => 'Tires & Wheels',
];

$service_labels = [
    'repair_panel'   => 'Per Panel Repair',
    'repair_full'    => 'Full Body Repair',
    'paint_panel'    => 'Per Panel Paint',
    'paint_full'     => 'Full Body Paint',
    'washover_basic' => 'Basic Wash Over',
    'washover_full'  => 'Fully Wash Over',
    'custom'         => 'Custom / Mixed',
];

// ── HANDLE POST ──
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $descs      = $_POST['desc']       ?? [];
    $areas      = $_POST['area']       ?? [];
    $qtys       = $_POST['qty']        ?? [];
    $prices     = $_POST['unit_price'] ?? [];
    $discount   = san_float($_POST['discount'] ?? 0);
    $notes      = san_str($_POST['notes'] ?? '', 1000);
    $status     = san_enum($_POST['status'] ?? 'draft', ['draft', 'pending_approval']);

    $items = [];
    foreach ($descs as $i => $raw_desc) {
        $desc  = san_str($raw_desc, 255);
        $area  = san_str($areas[$i] ?? '', 50);
        $qty   = san_float($qtys[$i] ?? 1, 0.01);
        $price = san_float($prices[$i] ?? 0);
        if ($desc === '') continue;
        $items[] = [
            'desc'       => $desc,
            'area'       => $area,
            'qty'        => $qty,
            'unit_price' => $price,
            'subtotal'   => round($qty * $price, 2),
            'sort_order' => $i,
        ];
    }

    if (empty($items)) $errors[] = 'Add at least one line item.';

    if (empty($errors)) {
        $subtotal = array_sum(array_column($items, 'subtotal'));
        $total    = max(0, $subtotal - $discount);

        // Generate quotation number: Q-YYYYMMDD-XXXX (find next unused number)
        $prefix = 'Q-' . date('Ymd') . '-';
        $seq_stmt = $conn->prepare("SELECT quotation_number FROM quotations WHERE quotation_number LIKE ? ORDER BY quotation_number DESC LIMIT 1");
        $like = $prefix . '%';
        $seq_stmt->bind_param('s', $like);
        $seq_stmt->execute();
        $last = $seq_stmt->get_result()->fetch_row();
        $seq = $last ? (int)substr($last[0], -4) + 1 : 1;
        $qt_num = $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);

        $conn->begin_transaction();
        try {
            $ins = $conn->prepare("
                INSERT INTO quotations (job_id, quotation_number, status, subtotal, discount, total, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $ins->bind_param('issdddsi', $job_id, $qt_num, $status, $subtotal, $discount, $total, $notes, $_SESSION['user_id']);
            $ins->execute();
            $qt_id = $conn->insert_id;

            $item_ins = $conn->prepare("
                INSERT INTO quotation_items (quotation_id, description, area, qty, unit_price, subtotal, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($items as $it) {
                $item_ins->bind_param('issdddi', $qt_id, $it['desc'], $it['area'], $it['qty'], $it['unit_price'], $it['subtotal'], $it['sort_order']);
                $item_ins->execute();
            }

            $log  = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'QUOTATION_CREATED', ?)");
            $desc = ($_SESSION['full_name'] ?? 'Unknown') . ' created quotation ' . $qt_num . ' for job ' . $job['job_number'] . '.';
            $log->bind_param('is', $_SESSION['user_id'], $desc);
            $log->execute();

            $conn->commit();
            header("Location: view_quotation.php?id=$qt_id&success=" . urlencode('Quotation ' . $qt_num . ' created.'));
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = 'Database error. Please try again.';
        }
    }
}

// ── PRE-BUILD SUGGESTED ITEMS from checklist + service type ──
$suggested = [];
$svc = $job['service_type'];
foreach ($damaged_areas as $dmg) {
    $lbl   = $area_labels[$dmg['area_key']] ?? $dmg['area_key'];
    $cond  = $dmg['condition_value'];
    $price = 3500.00;
    if (str_contains($svc, 'paint')) $price = 3500.00;
    $suggested[] = [
        'desc'       => ($cond === 'major' ? 'Major Damage Repair — ' : 'Minor Scratch Repair — ') . $lbl,
        'area'       => $dmg['area_key'],
        'qty'        => 1,
        'unit_price' => $price,
        'subtotal'   => $price,
    ];
}
if (empty($suggested)) {
    $default_prices = [
        'repair_panel' => 3500, 'repair_full' => 3500,
        'paint_panel'  => 3500, 'paint_full'  => 3500,
        'washover_basic' => 0,  'washover_full' => 0,
        'custom' => 0,
    ];
    $default_price = $default_prices[$svc] ?? 0;
    $suggested[] = [
        'desc'       => $service_labels[$svc] ?? 'Service',
        'area'       => '',
        'qty'        => 1,
        'unit_price' => $default_price,
        'subtotal'   => $default_price,
    ];
}

$page_title  = 'New Quotation';
$active_page = 'quotations';
$base_path   = '../../';
$extra_css   = '<link rel="stylesheet" href="../../assets/css/shared/quotations.css"/>';
require_once '../../includes/header.php';
require_once '../../includes/navbar.php';
?>

<div class="main">
<?php
$topbar_title      = 'New Quotation';
$topbar_breadcrumb = ['Repair Shop', 'Quotations', 'New'];
require_once '../../includes/topbar.php';
?>

<div class="content">

<?php if (!empty($errors)): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
  Swal.fire({ icon:'error', title:'Please fix the following',
    html: <?= json_encode('<ul style="text-align:left;margin:0;padding-left:1.2rem;">' . implode('', array_map(fn($e) => '<li>' . htmlspecialchars($e) . '</li>', $errors)) . '</ul>') ?>,
    confirmButtonColor:'#B8860B' });
});
</script>
<?php endif; ?>

<a href="../repair/view_repair.php?id=<?= $job_id ?>" style="display:inline-flex;align-items:center;gap:0.4rem;font-size:0.82rem;color:var(--text-muted);text-decoration:none;margin-bottom:1.25rem;" onmouseover="this.style.color='var(--gold)'" onmouseout="this.style.color='var(--text-muted)'">
  <?= icon('chevron-left', 14) ?> Back to Repair Job
</a>

<form method="POST" id="qt-form">
  <?= csrf_field() ?>

  <!-- JOB SUMMARY BANNER -->
  <div class="card" style="margin-bottom:1.25rem;background:var(--bg-2);">
    <div style="padding:1rem 1.25rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.75rem;">
      <div style="display:flex;align-items:center;gap:1rem;">
        <div class="card-icon"><?= icon('wrench', 16) ?></div>
        <div>
          <div style="font-size:0.82rem;font-weight:700;color:var(--text-primary);"><?= htmlspecialchars($job['job_number']) ?> — <?= htmlspecialchars($job['full_name']) ?></div>
          <div style="font-size:0.73rem;color:var(--text-muted);"><?= htmlspecialchars($job['plate_number']) ?> &nbsp;·&nbsp; <?= htmlspecialchars($job['year_model'] . ' ' . $job['make'] . ' ' . $job['model']) ?> &nbsp;·&nbsp; <?= htmlspecialchars($service_labels[$job['service_type']] ?? $job['service_type']) ?></div>
        </div>
      </div>
      <span class="badge badge-yellow"><?= icon('clock', 10) ?> <?= ucfirst(str_replace('_', ' ', $job['status'])) ?></span>
    </div>
  </div>

  <!-- LINE ITEMS -->
  <div class="card" style="margin-bottom:1.25rem;">
    <div class="card-header" style="justify-content:space-between;">
      <div style="display:flex;align-items:center;gap:0.75rem;">
        <div class="card-icon"><?= icon('receipt', 16) ?></div>
        <div>
          <div class="card-title">Line Items</div>
          <div class="card-sub">Services and parts to be charged</div>
        </div>
      </div>
      <button type="button" id="btn-add-row" class="btn-sm-gold"><?= icon('plus', 13) ?> Add Item</button>
    </div>

    <!-- PRICE REFERENCE -->
    <div style="padding:0.75rem 1.25rem;border-bottom:1px solid var(--border);background:var(--bg-3);">
      <div style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:var(--text-muted);margin-bottom:0.4rem;">Quick Price Reference</div>
      <div class="price-presets">
        <span class="price-chip" data-price="3500">Panel Repair — P3,500</span>
        <span class="price-chip" data-price="4000">Panel Repair (Red/Orange) — P4,000</span>
        <span class="price-chip" data-price="4500">Panel Repair (Red/Orange+) — P4,500</span>
        <span class="price-chip" data-price="5500">Pearl White — P5,500</span>
        <span class="price-chip" data-price="6000">Pearl White+ — P6,000</span>
        <span class="price-chip" data-price="6000">PMS Sedan — P6,000</span>
        <span class="price-chip" data-price="6500">PMS Sedan+ — P6,500</span>
      </div>
    </div>

    <!-- TABLE HEADER -->
    <div style="overflow-x:auto;">
      <table class="line-items-table" id="items-table">
        <thead>
          <tr>
            <th style="text-align:left;min-width:220px;">Description</th>
            <th style="text-align:left;min-width:130px;">Area</th>
            <th style="text-align:center;width:70px;">Qty</th>
            <th style="text-align:right;width:130px;">Unit Price</th>
            <th style="text-align:right;width:130px;">Subtotal</th>
            <th style="width:40px;"></th>
          </tr>
        </thead>
        <tbody id="items-body">
          <?php foreach ($suggested as $i => $it): ?>
          <tr class="item-row" data-idx="<?= $i ?>">
            <td><input type="text" name="desc[]" class="field-input item-desc" value="<?= htmlspecialchars($it['desc']) ?>" placeholder="Service / part description" required style="width:100%;min-width:180px;"/></td>
            <td>
              <select name="area[]" class="field-select item-area" style="font-size:0.78rem;padding:0.38rem 0.6rem;">
                <option value="">— General —</option>
                <?php foreach ($area_labels as $k => $lbl): ?>
                <option value="<?= $k ?>" <?= $it['area'] === $k ? 'selected' : '' ?>><?= $lbl ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td><input type="number" name="qty[]" class="field-input item-qty" value="<?= $it['qty'] ?>" min="0.01" step="0.01" style="text-align:center;width:65px;" required/></td>
            <td><input type="number" name="unit_price[]" class="field-input item-price" value="<?= $it['unit_price'] ?>" min="0" step="0.01" style="text-align:right;width:120px;" required/></td>
            <td style="text-align:right;font-weight:700;font-size:0.82rem;color:var(--text-primary);white-space:nowrap;" class="item-sub">PHP <?= number_format($it['subtotal'], 2) ?></td>
            <td style="text-align:center;">
              <button type="button" class="btn-remove-row" title="Remove" style="background:none;border:none;cursor:pointer;color:var(--danger);padding:0.25rem;"><?= icon('trash', 14) ?></button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- TOTALS -->
    <div style="display:flex;flex-direction:column;align-items:flex-end;padding:1rem 1.25rem;gap:0.4rem;border-top:1px solid var(--border);">
      <div class="qt-total-row">
        <span class="qt-total-label">Subtotal</span>
        <span class="qt-total-val" id="disp-subtotal">PHP 0.00</span>
      </div>
      <div class="qt-total-row" style="align-items:center;">
        <span class="qt-total-label">Discount</span>
        <span style="display:flex;align-items:center;gap:0.35rem;">
          <span style="font-size:0.82rem;color:var(--text-muted);">PHP</span>
          <input type="number" name="discount" id="discount-input" value="0" min="0" step="0.01"
            style="width:110px;text-align:right;padding:0.35rem 0.55rem;border:1px solid var(--border);border-radius:8px;background:var(--bg-2);color:var(--text-primary);font-size:0.82rem;font-family:monospace;"/>
        </span>
      </div>
      <div class="qt-total-row grand">
        <span class="qt-total-label">TOTAL</span>
        <span class="qt-total-val" id="disp-total">PHP 0.00</span>
      </div>
    </div>
  </div>

  <!-- NOTES + STATUS -->
  <div style="display:grid;grid-template-columns:1fr auto;gap:1.25rem;margin-bottom:1.25rem;align-items:start;">
    <div class="card" style="margin-bottom:0;">
      <div class="card-header">
        <div class="card-icon"><?= icon('document', 16) ?></div>
        <div><div class="card-title">Notes</div><div class="card-sub">Optional remarks for this quotation</div></div>
      </div>
      <div style="padding:1rem 1.25rem;">
        <textarea name="notes" class="field-input" rows="3" placeholder="e.g. Client agreed on partial payment, special color requested..." style="resize:vertical;width:100%;"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
      </div>
    </div>
    <div class="card" style="margin-bottom:0;min-width:220px;">
      <div class="card-header">
        <div class="card-icon"><?= icon('check-circle', 16) ?></div>
        <div><div class="card-title">Save As</div></div>
      </div>
      <div style="padding:1rem 1.25rem;display:flex;flex-direction:column;gap:0.5rem;">
        <label style="display:flex;align-items:center;gap:0.6rem;padding:0.6rem 0.8rem;border:1px solid var(--border);border-radius:10px;cursor:pointer;" onmouseover="this.style.background='var(--bg-3)'" onmouseout="this.style.background=''">
          <input type="radio" name="status" value="draft" checked style="accent-color:var(--gold);"/>
          <div>
            <div style="font-size:0.82rem;font-weight:600;color:var(--text-primary);">Draft</div>
            <div style="font-size:0.7rem;color:var(--text-muted);">Save and edit later</div>
          </div>
        </label>
        <label style="display:flex;align-items:center;gap:0.6rem;padding:0.6rem 0.8rem;border:1px solid var(--border);border-radius:10px;cursor:pointer;" onmouseover="this.style.background='var(--bg-3)'" onmouseout="this.style.background=''">
          <input type="radio" name="status" value="pending_approval" style="accent-color:var(--gold);"/>
          <div>
            <div style="font-size:0.82rem;font-weight:600;color:var(--text-primary);">Send for Approval</div>
            <div style="font-size:0.7rem;color:var(--text-muted);">Mark as awaiting client</div>
          </div>
        </label>
      </div>
    </div>
  </div>

  <!-- FORM ACTIONS -->
  <div style="display:flex;justify-content:flex-end;gap:0.6rem;padding-bottom:2rem;">
    <a href="../repair/view_repair.php?id=<?= $job_id ?>" class="btn-ghost">Cancel</a>
    <button type="submit" class="btn-primary"><?= icon('check', 14) ?> Save Quotation</button>
  </div>

</form>
</div>
</div>

<!-- HIDDEN ROW TEMPLATE -->
<template id="row-template">
  <tr class="item-row">
    <td><input type="text" name="desc[]" class="field-input item-desc" placeholder="Service / part description" required style="width:100%;min-width:180px;"/></td>
    <td>
      <select name="area[]" class="field-select item-area" style="font-size:0.78rem;padding:0.38rem 0.6rem;">
        <option value="">— General —</option>
        <?php foreach ($area_labels as $k => $lbl): ?>
        <option value="<?= $k ?>"><?= $lbl ?></option>
        <?php endforeach; ?>
      </select>
    </td>
    <td><input type="number" name="qty[]" class="field-input item-qty" value="1" min="0.01" step="0.01" style="text-align:center;width:65px;" required/></td>
    <td><input type="number" name="unit_price[]" class="field-input item-price" value="0" min="0" step="0.01" style="text-align:right;width:120px;" required/></td>
    <td style="text-align:right;font-weight:700;font-size:0.82rem;color:var(--text-primary);white-space:nowrap;" class="item-sub">PHP 0.00</td>
    <td style="text-align:center;">
      <button type="button" class="btn-remove-row" title="Remove" style="background:none;border:none;cursor:pointer;color:var(--danger);padding:0.25rem;"><?= icon('trash', 14) ?></button>
    </td>
  </tr>
</template>

<script>
(function () {
  const body     = document.getElementById('items-body');
  const tmpl     = document.getElementById('row-template');
  const dispSub  = document.getElementById('disp-subtotal');
  const dispTot  = document.getElementById('disp-total');
  const discInp  = document.getElementById('discount-input');

  function fmt(n) { return 'PHP ' + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }

  function recalc() {
    let sub = 0;
    body.querySelectorAll('.item-row').forEach(row => {
      const qty   = parseFloat(row.querySelector('.item-qty').value)   || 0;
      const price = parseFloat(row.querySelector('.item-price').value) || 0;
      const st    = qty * price;
      row.querySelector('.item-sub').textContent = fmt(st);
      sub += st;
    });
    const disc  = parseFloat(discInp.value) || 0;
    const total = Math.max(0, sub - disc);
    dispSub.textContent = fmt(sub);
    dispTot.textContent = fmt(total);
  }

  function bindRow(row) {
    row.querySelectorAll('.item-qty, .item-price').forEach(inp => inp.addEventListener('input', recalc));
    row.querySelector('.btn-remove-row').addEventListener('click', function () {
      if (body.querySelectorAll('.item-row').length > 1) { row.remove(); recalc(); }
      else Swal.fire({ toast:true, position:'top-end', icon:'warning', title:'Need at least one item', showConfirmButton:false, timer:2000 });
    });
  }

  body.querySelectorAll('.item-row').forEach(bindRow);
  discInp.addEventListener('input', recalc);
  recalc();

  document.getElementById('btn-add-row').addEventListener('click', function () {
    const clone = tmpl.content.cloneNode(true).querySelector('tr');
    body.appendChild(clone);
    bindRow(clone);
    clone.querySelector('.item-desc').focus();
  });

  document.querySelectorAll('.price-chip').forEach(chip => {
    chip.addEventListener('click', function () {
      const price = this.dataset.price;
      const focused = body.querySelector('.item-price:focus') || body.querySelector('.item-row:last-child .item-price');
      if (focused) { focused.value = price; recalc(); }
    });
  });
})();
</script>

<?php require_once '../../includes/footer.php'; ?>
