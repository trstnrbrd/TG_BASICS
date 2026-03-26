<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header("Location: ../../auth/login.php");
    exit;
}

$full_name = $_SESSION['full_name'];
$initials  = substr(implode('', array_map(fn($w) => strtoupper($w[0]), explode(' ', $full_name))), 0, 2);

$search = trim($_GET['search'] ?? '');
$where  = '';
$params = [];
$types  = '';

if ($search !== '') {
    $where  = "WHERE c.full_name LIKE ? OR v.plate_number LIKE ? OR c.contact_number LIKE ?";
    $like   = "%$search%";
    $params = [$like, $like, $like];
    $types  = 'sss';
}

$sql = "
    SELECT c.client_id, c.full_name, c.contact_number, c.email,
           COUNT(v.vehicle_id) AS vehicle_count, c.created_at
    FROM clients c
    LEFT JOIN vehicles v ON c.client_id = v.client_id
    $where
    GROUP BY c.client_id
    ORDER BY c.created_at DESC
";

$stmt = $conn->prepare($sql);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$total_clients  = $conn->query("SELECT COUNT(*) as c FROM clients")->fetch_assoc()['c'];
$total_vehicles = $conn->query("SELECT COUNT(*) as c FROM vehicles")->fetch_assoc()['c'];
$recent         = $conn->query("SELECT COUNT(*) as c FROM clients WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetch_assoc()['c'];

$page_title  = 'Client Records';
$active_page = 'clients';
$base_path   = '../../';
require_once '../../includes/header.php';
require_once '../../includes/navbar.php';
?>

<div class="main">

  <?php
$topbar_title      = 'Client Records';
$topbar_breadcrumb = ['Records', 'Clients'];
require_once '../../includes/topbar.php';
?>

  <div class="content">

    <?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success"><?= htmlspecialchars($_GET['success']) ?></div>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($_GET['error']) ?></div>
    <?php endif; ?>

    <!-- STATS -->
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:1.5rem;">
      <?php
      $stats = [
        [icon('user', 16), $total_clients,  'Total Clients'],
        [icon('vehicle', 16), $total_vehicles, 'Total Vehicles'],
        [icon('calendar', 16), $recent,         'Added This Month'],
      ];
      foreach ($stats as $s): ?>
      <div class="card" style="margin-bottom:0;display:flex;align-items:center;gap:0.9rem;padding:1.1rem 1.25rem;">
        <div class="card-icon" style="width:42px;height:42px;border-radius:10px;flex-shrink:0;"><?= $s[0] ?></div>
        <div>
          <div style="font-size:1.6rem;font-weight:800;color:var(--text-primary);line-height:1;letter-spacing:-0.5px;"><?= $s[1] ?></div>
          <div style="font-size:0.7rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.5px;margin-top:0.15rem;"><?= $s[2] ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- TOOLBAR -->
    <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;margin-bottom:1rem;flex-wrap:wrap;">
      <form method="GET" action="" style="flex:1;min-width:200px;max-width:420px;">
        <div style="position:relative;">
          <span style="position:absolute;left:0.85rem;top:50%;transform:translateY(-50%);color:var(--text-muted);pointer-events:none;"><?= icon('magnifying-glass', 14) ?></span>
          <input
            type="text"
            name="search"
            placeholder="Search by name, plate number, or contact..."
            value="<?= htmlspecialchars($search) ?>"
            style="width:100%;background:var(--bg-3);border:1px solid var(--border);color:var(--text-primary);padding:0.6rem 0.9rem 0.6rem 2.4rem;border-radius:9px;font-family:'Plus Jakarta Sans',sans-serif;font-size:0.82rem;outline:none;transition:border-color 0.15s,box-shadow 0.15s;box-shadow:var(--shadow);"
            onfocus="this.style.borderColor='var(--gold-bright)';this.style.boxShadow='0 0 0 3px rgba(212,160,23,0.1)'"
            onblur="this.style.borderColor='var(--border)';this.style.boxShadow='var(--shadow)'"
          />
        </div>
      </form>
      <div style="display:flex;gap:0.5rem;align-items:center;flex-shrink:0;">
        <?php if ($search): ?>
        <a href="client_list.php" class="btn-ghost"><?= icon('x-mark', 14) ?> Clear</a>
        <?php endif; ?>
        <a href="add_client.php" class="btn-primary"><?= icon('plus', 14) ?> Add Client</a>
      </div>
    </div>

    <!-- TABLE -->
    <div class="card" style="margin-bottom:0;">
      <div class="card-header">
        <div class="card-icon"><?= icon('users', 16) ?></div>
        <div>
          <div class="card-title"><?= $search ? 'Search Results' : 'All Clients' ?></div>
          <div class="card-sub"><?= $result->num_rows ?> record<?= $result->num_rows !== 1 ? 's' : '' ?></div>
        </div>
      </div>

      <?php if ($result->num_rows > 0): ?>
      <div class="tg-table-wrap">
        <table class="tg-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Full Name</th>
              <th>Contact</th>
              <th>Email</th>
              <th>Vehicles</th>
              <th>Date Added</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php $i = 1; while ($row = $result->fetch_assoc()): ?>
            <tr style="cursor:pointer;" onclick="window.location='view_client.php?id=<?= $row['client_id'] ?>'">
              <td style="color:var(--text-muted);font-size:0.72rem;"><?= $i++ ?></td>
              <td style="font-weight:700;color:var(--text-primary);font-size:0.85rem;"><?= htmlspecialchars($row['full_name']) ?></td>
              <td><?= htmlspecialchars($row['contact_number']) ?></td>
              <td style="color:var(--text-muted);font-size:0.78rem;"><?= htmlspecialchars($row['email'] ?? '-') ?></td>
              <td>
                <span class="badge badge-gold"><?= icon('vehicle', 12) ?> <?= $row['vehicle_count'] ?> vehicle<?= $row['vehicle_count'] != 1 ? 's' : '' ?></span>
              </td>
              <td style="font-size:0.75rem;color:var(--text-muted);"><?= date('M d, Y', strtotime($row['created_at'])) ?></td>
              <td>
                <div style="display:flex;gap:0.4rem;">
                  <a href="delete_client.php?id=<?= $row['client_id'] ?>"
                     class="btn-sm-gold"
                     style="background:var(--danger-bg);color:var(--danger);border-color:var(--danger-border);"
                     onclick="return confirm('Delete <?= htmlspecialchars(addslashes($row['full_name'])) ?> and all their records?')">Delete</a>
                </div>
              </td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
      <div class="empty-state">
        <div class="empty-icon"><?= icon('users', 28) ?></div>
        <div class="empty-title"><?= $search ? 'No results found' : 'No clients yet' ?></div>
        <div class="empty-desc"><?= $search ? 'Try a different name, plate number, or contact.' : 'Start by adding your first client record.' ?></div>
        <?php if (!$search): ?>
        <a href="add_client.php" class="btn-primary"><?= icon('plus', 14) ?> Add First Client</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

  </div>
</div>

<?php require_once '../../includes/footer.php'; ?>