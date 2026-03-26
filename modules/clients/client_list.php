<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header("Location: ../../auth/login.php");
    exit;
}

$full_name = $_SESSION['full_name'];
$initials  = substr(implode('', array_map(fn($w) => strtoupper($w[0]), explode(' ', $full_name))), 0, 2);

$search    = trim($_GET['search'] ?? '');
$filter_by = $_GET['filter_by'] ?? 'all';
$sort_by   = $_GET['sort'] ?? 'newest';
$where     = '';
$params    = [];
$types     = '';

if ($search !== '') {
    $like = "%$search%";
    switch ($filter_by) {
        case 'name':
            $where  = "WHERE c.full_name LIKE ?";
            $params = [$like];
            $types  = 's';
            break;
        case 'plate':
            $where  = "WHERE v.plate_number LIKE ?";
            $params = [$like];
            $types  = 's';
            break;
        case 'contact':
            $where  = "WHERE c.contact_number LIKE ?";
            $params = [$like];
            $types  = 's';
            break;
        case 'email':
            $where  = "WHERE c.email LIKE ?";
            $params = [$like];
            $types  = 's';
            break;
        default:
            $where  = "WHERE (c.full_name LIKE ? OR v.plate_number LIKE ? OR c.contact_number LIKE ? OR c.email LIKE ?)";
            $params = [$like, $like, $like, $like];
            $types  = 'ssss';
    }
}

$order = match ($sort_by) {
    'oldest'   => 'c.created_at ASC',
    'name_asc' => 'c.full_name ASC',
    'name_desc'=> 'c.full_name DESC',
    'vehicles' => 'vehicle_count DESC',
    default    => 'c.created_at DESC',
};

$sql = "
    SELECT c.client_id, c.full_name, c.contact_number, c.email,
           COUNT(v.vehicle_id) AS vehicle_count, c.created_at
    FROM clients c
    LEFT JOIN vehicles v ON c.client_id = v.client_id
    $where
    GROUP BY c.client_id
    ORDER BY $order
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
    <form method="GET" action="" style="margin-bottom:1rem;">
      <div style="display:flex;gap:0.6rem;align-items:center;flex-wrap:wrap;">

        <!-- SEARCH INPUT -->
        <div style="position:relative;flex:1;min-width:200px;max-width:400px;">
          <span style="position:absolute;left:0.85rem;top:50%;transform:translateY(-50%);color:var(--text-muted);pointer-events:none;"><?= icon('magnifying-glass', 14) ?></span>
          <input type="text" name="search"
            placeholder="Search clients..."
            value="<?= htmlspecialchars($search) ?>"
            class="filter-input" style="padding-left:2.4rem;width:100%;"/>
        </div>

        <!-- FILTER BY -->
        <select name="filter_by" class="filter-input" style="min-width:140px;">
          <option value="all"     <?= $filter_by === 'all' ? 'selected' : '' ?>>All Fields</option>
          <option value="name"    <?= $filter_by === 'name' ? 'selected' : '' ?>>Name</option>
          <option value="plate"   <?= $filter_by === 'plate' ? 'selected' : '' ?>>Plate Number</option>
          <option value="contact" <?= $filter_by === 'contact' ? 'selected' : '' ?>>Contact</option>
          <option value="email"   <?= $filter_by === 'email' ? 'selected' : '' ?>>Email</option>
        </select>

        <!-- SORT BY -->
        <select name="sort" class="filter-input" style="min-width:150px;">
          <option value="newest"   <?= $sort_by === 'newest' ? 'selected' : '' ?>>Newest First</option>
          <option value="oldest"   <?= $sort_by === 'oldest' ? 'selected' : '' ?>>Oldest First</option>
          <option value="name_asc" <?= $sort_by === 'name_asc' ? 'selected' : '' ?>>Name A–Z</option>
          <option value="name_desc"<?= $sort_by === 'name_desc' ? 'selected' : '' ?>>Name Z–A</option>
          <option value="vehicles" <?= $sort_by === 'vehicles' ? 'selected' : '' ?>>Most Vehicles</option>
        </select>

        <!-- BUTTONS -->
        <button type="submit" class="btn-primary"><?= icon('magnifying-glass', 14) ?> Search</button>
        <?php if ($search || $filter_by !== 'all' || $sort_by !== 'newest'): ?>
        <a href="client_list.php" class="btn-ghost"><?= icon('x-mark', 14) ?> Clear</a>
        <?php endif; ?>
        <a href="add_client.php" class="btn-primary"><?= icon('plus', 14) ?> Add Client</a>
      </div>
    </form>

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