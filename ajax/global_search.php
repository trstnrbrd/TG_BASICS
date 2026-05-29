<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/validators.php';

if (!isset($_SESSION['user_id'])) { http_response_code(401); exit; }

header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 1) { echo json_encode([]); exit; }

$like   = '%' . $conn->real_escape_string($q) . '%';
$role   = $_SESSION['role'] ?? '';
$results = [];

// ── CLIENTS ──
$r = $conn->prepare("
    SELECT client_id, full_name, contact_number, email
    FROM clients
    WHERE deleted_at IS NULL
      AND (full_name LIKE ? OR contact_number LIKE ? OR email LIKE ?)
    LIMIT 4
");
$r->bind_param('sss', $like, $like, $like);
$r->execute();
$rows = $r->get_result();
while ($row = $rows->fetch_assoc()) {
    $results[] = [
        'type'    => 'client',
        'label'   => $row['full_name'],
        'sub'     => $row['contact_number'] ?: ($row['email'] ?: ''),
        'url'     => 'modules/clients/view_client.php?id=' . $row['client_id'],
        'icon'    => 'user',
    ];
}

// ── VEHICLES ──
$r = $conn->prepare("
    SELECT v.vehicle_id, v.plate_number, v.make, v.model, v.year_model, c.full_name, c.client_id
    FROM vehicles v
    INNER JOIN clients c ON v.client_id = c.client_id
    WHERE v.plate_number LIKE ? OR v.make LIKE ? OR v.model LIKE ?
    LIMIT 4
");
$r->bind_param('sss', $like, $like, $like);
$r->execute();
$rows = $r->get_result();
while ($row = $rows->fetch_assoc()) {
    $results[] = [
        'type'    => 'vehicle',
        'label'   => strtoupper($row['plate_number']) . ' — ' . $row['make'] . ' ' . $row['model'],
        'sub'     => $row['full_name'] . ($row['year_model'] ? ' · ' . $row['year_model'] : ''),
        'url'     => 'modules/clients/view_client.php?id=' . $row['client_id'],
        'icon'    => 'truck',
    ];
}

// ── POLICIES (non-mechanic only) ──
if ($role !== 'mechanic') {
    $r = $conn->prepare("
        SELECT ip.policy_id, ip.policy_number, ip.coverage_type, ip.policy_end,
               c.full_name, c.client_id
        FROM insurance_policies ip
        INNER JOIN clients c ON ip.client_id = c.client_id
        WHERE ip.policy_number LIKE ? OR c.full_name LIKE ?
        LIMIT 3
    ");
    $r->bind_param('ss', $like, $like);
    $r->execute();
    $rows = $r->get_result();
    while ($row = $rows->fetch_assoc()) {
        $results[] = [
            'type'    => 'policy',
            'label'   => $row['policy_number'],
            'sub'     => $row['full_name'] . ' · ' . $row['coverage_type'] . ' · expires ' . date('M d, Y', strtotime($row['policy_end'])),
            'url'     => 'modules/insurance/eligibility_check.php?search=' . urlencode($row['full_name']),
            'icon'    => 'shield-check',
        ];
    }

    // ── CLAIMS ──
    $r = $conn->prepare("
        SELECT cl.claim_id, cl.status, cl.claim_type,
               c.full_name, c.client_id,
               v.plate_number
        FROM claims cl
        INNER JOIN clients c ON cl.client_id = c.client_id
        LEFT JOIN insurance_policies ip ON cl.policy_id = ip.policy_id
        LEFT JOIN vehicles v ON ip.vehicle_id = v.vehicle_id
        WHERE c.full_name LIKE ? OR v.plate_number LIKE ? OR ip.policy_number LIKE ?
        LIMIT 3
    ");
    $r->bind_param('sss', $like, $like, $like);
    $r->execute();
    $rows = $r->get_result();
    while ($row = $rows->fetch_assoc()) {
        $results[] = [
            'type'    => 'claim',
            'label'   => $row['full_name'] . ($row['plate_number'] ? ' — ' . $row['plate_number'] : ''),
            'sub'     => ucfirst($row['claim_type']) . ' claim · ' . ucwords(str_replace('_', ' ', $row['status'])),
            'url'     => 'modules/claims/view_claim.php?id=' . $row['claim_id'],
            'icon'    => 'clipboard-list',
        ];
    }
}

// ── REPAIR JOBS ──
$r = $conn->prepare("
    SELECT rj.job_id, rj.job_number, rj.service_type, rj.status,
           c.full_name, c.client_id,
           v.plate_number
    FROM repair_jobs rj
    INNER JOIN clients c ON rj.client_id = c.client_id
    LEFT JOIN vehicles v ON rj.vehicle_id = v.vehicle_id
    WHERE rj.job_number LIKE ? OR c.full_name LIKE ? OR v.plate_number LIKE ?
    LIMIT 3
");
$r->bind_param('sss', $like, $like, $like);
$r->execute();
$rows = $r->get_result();
while ($row = $rows->fetch_assoc()) {
    $results[] = [
        'type'    => 'repair',
        'label'   => '#' . $row['job_number'] . ' — ' . $row['full_name'],
        'sub'     => ($row['service_type'] ?: 'Repair') . ' · ' . ucwords(str_replace('_', ' ', $row['status'])) . ($row['plate_number'] ? ' · ' . $row['plate_number'] : ''),
        'url'     => 'modules/repair/view_repair.php?id=' . $row['job_id'],
        'icon'    => 'wrench',
    ];
}

echo json_encode($results);
