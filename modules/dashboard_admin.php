<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    header("Location: ../auth/login.php");
    exit;
}

$full_name = $_SESSION['full_name'];
$initials  = substr(implode('', array_map(fn($w) => strtoupper($w[0]), explode(' ', $full_name))), 0, 2);

$total_clients  = $conn->query("SELECT COUNT(*) as c FROM clients")->fetch_assoc()['c'];
$total_vehicles = $conn->query("SELECT COUNT(*) as c FROM vehicles")->fetch_assoc()['c'];
$recent_clients = $conn->query("SELECT COUNT(*) as c FROM clients WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetch_assoc()['c'];
$active_repairs  = 0;
$claims_progress = 0;

$recent_list = $conn->query("
    SELECT c.client_id, c.full_name, c.contact_number, c.created_at,
           COUNT(v.vehicle_id) as vehicle_count
    FROM clients c
    LEFT JOIN vehicles v ON c.client_id = v.client_id
    GROUP BY c.client_id
    ORDER BY c.created_at DESC
    LIMIT 5
");

$page_title  = 'Dashboard';
$active_page = 'dashboard';
$base_path   = '../';
require_once '../includes/header.php';
require_once '../includes/navbar.php';

?>

<div class="main">

<?php
$topbar_title      = 'Dashboard';
$topbar_breadcrumb = ['Admin Dashboard'];
require_once '../includes/topbar.php';
?>

  <div class="content">

    <!-- WELCOME BANNER -->
    <div style="background:var(--sidebar-bg);border-radius:12px;padding:1.5rem 2rem;margin-bottom:1.75rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;position:relative;overflow:hidden;">
      <div style="position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--gold-bright),var(--gold-muted),transparent);"></div>
      <div style="position:absolute;right:2rem;top:50%;transform:translateY(-50%);font-size:6rem;font-weight:800;color:rgba(212,160,23,0.05);letter-spacing:-3px;pointer-events:none;">TG</div>
      <div style="position:relative;z-index:1;">
        <div style="font-size:0.72rem;color:rgba(200,192,176,0.5);letter-spacing:1.5px;text-transform:uppercase;font-weight:600;margin-bottom:0.3rem;">Good day</div>
        <div style="font-size:1.35rem;font-weight:800;color:#fff;letter-spacing:-0.3px;margin-bottom:0.2rem;">
          Welcome back, <span style="color:var(--gold-bright);"><?= htmlspecialchars(explode(' ', $full_name)[0]) ?></span>
        </div>
        <div style="font-size:0.75rem;color:rgba(200,192,176,0.45);">TG Customworks &amp; Basic Car Insurance Services &mdash; Pandi, Bulacan</div>
      </div>
      <div style="position:relative;z-index:1;text-align:right;">
        <div style="font-size:2rem;font-weight:800;color:var(--gold-bright);line-height:1;letter-spacing:-1px;"><?= date('d') ?></div>
        <div style="font-size:0.7rem;color:rgba(200,192,176,0.45);text-transform:uppercase;letter-spacing:1.5px;font-weight:600;"><?= date('M Y') ?></div>
      </div>
    </div>

    <!-- STAT CARDS -->
    <div id="stats-root" style="display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.75rem;"></div>

    <!-- BOTTOM GRID -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;">

      <!-- RECENT CLIENTS -->
      <div class="card">
        <div class="card-header">
          <div class="card-icon"><?= icon('users', 16) ?></div>
          <div>
            <div class="card-title">Recent Clients</div>
            <div class="card-sub">Last 5 added</div>
          </div>
          <a href="clients/client_list.php" style="margin-left:auto;font-size:0.72rem;color:var(--gold);text-decoration:none;font-weight:600;">View all &rarr;</a>
        </div>
        <?php if ($recent_list->num_rows > 0): ?>
        <table class="tg-table">
          <thead>
            <tr>
              <th>Name</th><th>Contact</th><th>Vehicles</th><th>Added</th>
            </tr>
          </thead>
          <tbody>
            <?php while ($row = $recent_list->fetch_assoc()): ?>
            <tr style="cursor:pointer;" onclick="window.location='clients/view_client.php?id=<?= $row['client_id'] ?>'">
              <td style="font-weight:700;color:var(--text-primary);"><?= htmlspecialchars($row['full_name']) ?></td>
              <td style="color:var(--text-muted);font-size:0.75rem;"><?= htmlspecialchars($row['contact_number']) ?></td>
              <td><span class="badge badge-gold"><?= icon('vehicle', 12) ?> <?= $row['vehicle_count'] ?></span></td>
              <td style="font-size:0.72rem;color:var(--text-muted);"><?= date('M d', strtotime($row['created_at'])) ?></td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
        <?php else: ?>
        <div class="empty-state">
          <div class="empty-icon"><?= icon('users', 28) ?></div>
          <div class="empty-title">No clients yet</div>
          <div class="empty-desc">Start by adding your first client.</div>
        </div>
        <?php endif; ?>
      </div>

      <!-- QUICK ACTIONS -->
      <div class="card">
        <div class="card-header">
          <div class="card-icon"><?= icon('arrow-right', 16) ?></div>
          <div>
            <div class="card-title">Quick Actions</div>
            <div class="card-sub">Common tasks</div>
          </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;padding:1.25rem;">
          <?php
          $actions = [
            ['clients/add_client.php',          icon('user',16),             'Add Client',       'New client and vehicle'],
            ['insurance/eligibility_check.php', icon('shield-check',16),     'New Policy',       'Check eligibility first'],
            ['repair/repair_list.php',          icon('wrench',16),           'New Repair Job',   'Log incoming vehicle'],
            ['claims/claims_list.php',          icon('clipboard-list',16),   'Log Claim',        'New insurance claim'],
            ['clients/client_list.php',         icon('magnifying-glass',16), 'Search Records',   'Find client or plate'],
            ['repair/quotation_list.php',       icon('receipt',16),          'Generate Receipt', 'Quotation to e-receipt'],
          ];
          foreach ($actions as $a): ?>
          <a href="<?= $a[0] ?>" style="display:flex;align-items:center;gap:0.75rem;padding:1rem 1.1rem;background:var(--bg);border:1px solid var(--border);border-radius:10px;text-decoration:none;transition:all 0.15s;"
            onmouseover="this.style.background='var(--gold-pale)';this.style.borderColor='var(--gold-muted)'"
            onmouseout="this.style.background='var(--bg)';this.style.borderColor='var(--border)'">
            <div style="width:38px;height:38px;border-radius:9px;background:var(--gold-light);display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0;"><?= $a[1] ?></div>
            <div>
              <div style="font-size:0.78rem;font-weight:700;color:var(--text-primary);"><?= $a[2] ?></div>
              <div style="font-size:0.67rem;color:var(--text-muted);margin-top:0.1rem;"><?= $a[3] ?></div>
            </div>
          </a>
          <?php endforeach; ?>
        </div>
      </div>

    </div>
  </div>
</div>

<?php
$footer_scripts = '
  function AnimatedCounter({ target, duration = 1200 }) {
    const [count, setCount] = useState(0);
    useEffect(() => {
      if (target === 0) { setCount(0); return; }
      let start = 0;
      const step = Math.ceil(target / (duration / 20));
      const timer = setInterval(() => {
        start += step;
        if (start >= target) { setCount(target); clearInterval(timer); }
        else setCount(start);
      }, 20);
      return () => clearInterval(timer);
    }, [target]);
    return React.createElement("span", null, count);
  }

  function StatCard({ icon, cardClass, label, value, trend, trendUp }) {
    const colors = {
      gold:  { top:"linear-gradient(90deg,#D4A017,#E8D5A3)", icon:"var(--gold-light)" },
      green: { top:"linear-gradient(90deg,#2E7D52,#52B788)", icon:"var(--success-bg)" },
      blue:  { top:"linear-gradient(90deg,#1A6B9A,#3498DB)", icon:"var(--info-bg)" },
      red:   { top:"linear-gradient(90deg,#C0392B,#E74C3C)", icon:"var(--danger-bg)" },
    };
    const c = colors[cardClass] || colors.gold;
    return React.createElement("div", {
      style:{ background:"var(--bg-3)", border:"1px solid var(--border)", borderRadius:"12px", padding:"1.25rem", display:"flex", flexDirection:"column", gap:"0.75rem", boxShadow:"var(--shadow)", position:"relative", overflow:"hidden", transition:"box-shadow 0.2s,transform 0.2s" },
      onMouseOver: e => { e.currentTarget.style.transform="translateY(-2px)"; e.currentTarget.style.boxShadow="var(--shadow-md)"; },
      onMouseOut:  e => { e.currentTarget.style.transform="translateY(0)";    e.currentTarget.style.boxShadow="var(--shadow)"; },
    },
      React.createElement("div",{style:{position:"absolute",top:0,left:0,right:0,height:"3px",background:c.top,borderRadius:"12px 12px 0 0"}}),
      React.createElement("div",{style:{display:"flex",alignItems:"center",justifyContent:"space-between"}},
        React.createElement("div",{style:{width:"40px",height:"40px",borderRadius:"10px",background:c.icon,display:"flex",alignItems:"center",justifyContent:"center",fontSize:"0.7rem",fontWeight:"800",color:"var(--text-secondary)",letterSpacing:"0.5px"}},icon),
        React.createElement("span",{style:{fontSize:"0.65rem",fontWeight:"700",padding:"0.2rem 0.5rem",borderRadius:"100px",background:trendUp?"var(--success-bg)":"var(--bg)",color:trendUp?"var(--success)":"var(--text-muted)",border:trendUp?"none":"1px solid var(--border)"}},trend)
      ),
      React.createElement("div",{style:{fontSize:"2rem",fontWeight:"800",color:"var(--text-primary)",lineHeight:"1",letterSpacing:"-1px"}},
        React.createElement(AnimatedCounter,{target:value})
      ),
      React.createElement("div",{style:{fontSize:"0.7rem",color:"var(--text-muted)",fontWeight:"600",textTransform:"uppercase",letterSpacing:"0.8px"}},label)
    );
  }

  const statsData = [
    { icon:"CL", cardClass:"gold", label:"Total Clients",      value:' . (int)$total_clients  . ', trend:"+' . (int)$recent_clients . ' this month", trendUp:' . ($recent_clients > 0 ? 'true' : 'false') . ' },
    { icon:"VH", cardClass:"gold", label:"Total Vehicles",     value:' . (int)$total_vehicles . ', trend:"Registered",   trendUp:false },
    { icon:"RP", cardClass:"blue", label:"Active Repairs",     value:' . (int)$active_repairs  . ', trend:"In progress", trendUp:false },
    { icon:"CL", cardClass:"red",  label:"Claims In Progress", value:' . (int)$claims_progress . ', trend:"Pending",     trendUp:false },
  ];

  ReactDOM.createRoot(document.getElementById("stats-root")).render(
    React.createElement(React.Fragment, null,
      statsData.map((s,i) => React.createElement(StatCard,{key:i,...s}))
    )
  );
';
require_once '../includes/footer.php';
?>