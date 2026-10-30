<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/data_access.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/validators.php';
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/claim_types.php';

if (!isset($_SESSION['user_id'])) api_error('Not signed in.', 401);

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 1) api_success(['results' => []]);

// The term is bound as a prepared-statement parameter below, so it must NOT also go through
// real_escape_string() (that adds backslashes which then become part of the searched text — a name
// like D'Souza would never match). Only LIKE's own wildcards need escaping.
$like   = '%' . addcslashes($q, '\\%_') . '%';
$results = [];

// ── CLIENTS ── (mechanics only ever see walk-in clients, no insurance policy on record)
foreach (search_clients($conn, $like, is_mechanic(), 4) as $row) {
    $results[] = [
        'type'    => 'client',
        'label'   => $row['full_name'],
        'sub'     => $row['contact_number'] ?: ($row['email'] ?: ''),
        'url'     => 'modules/clients/view_client.php?id=' . $row['client_id'],
        'icon'    => 'user',
    ];
}

// ── VEHICLES ── (mechanics only ever see walk-in clients' vehicles)
foreach (search_vehicles($conn, $like, is_mechanic(), 4) as $row) {
    $results[] = [
        'type'    => 'vehicle',
        'label'   => strtoupper($row['plate_number']) . ' — ' . $row['make'] . ' ' . $row['model'],
        'sub'     => $row['full_name'] . ($row['year_model'] ? ' · ' . $row['year_model'] : ''),
        'url'     => 'modules/clients/view_client.php?id=' . $row['client_id'],
        'icon'    => 'truck',
    ];
}

// ── POLICIES (non-mechanic only) ──
if (!is_mechanic()) {
    foreach (search_policies($conn, $like, 3) as $row) {
        $results[] = [
            'type'    => 'policy',
            'label'   => $row['policy_number'],
            'sub'     => $row['full_name'] . ' · ' . $row['coverage_type'] . ' · expires ' . date('M d, Y', strtotime($row['policy_end'])),
            'url'     => 'modules/insurance/eligibility_check.php?search=' . urlencode($row['full_name']),
            'icon'    => 'shield-check',
        ];
    }

    // ── CLAIMS ──
    foreach (search_claims($conn, $like, 3) as $row) {
        $results[] = [
            'type'    => 'claim',
            'label'   => $row['full_name'] . ($row['plate_number'] ? ' — ' . $row['plate_number'] : ''),
            'sub'     => claim_type_badge($conn, $row['claim_type'])['label'] . ' claim · ' . ucwords(str_replace('_', ' ', $row['status'])),
            'url'     => 'modules/claims/view_claim.php?id=' . $row['claim_id'],
            'icon'    => 'clipboard-list',
        ];
    }
}

// ── REPAIR JOBS ──
foreach (search_repair_jobs($conn, $like, 3) as $row) {
    $results[] = [
        'type'    => 'repair',
        'label'   => '#' . $row['job_number'] . ' — ' . $row['full_name'],
        'sub'     => ($row['service_type'] ?: 'Repair') . ' · ' . ucwords(str_replace('_', ' ', $row['status'])) . ($row['plate_number'] ? ' · ' . $row['plate_number'] : ''),
        'url'     => 'modules/repair/view_repair.php?id=' . $row['job_id'],
        'icon'    => 'wrench',
    ];
}

api_success(['results' => $results]);
