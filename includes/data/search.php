<?php
/* =========================================================================
 * The top bar's global search (ajax/global_search.php). $like is the
 * search text already wrapped in % with LIKE's own wildcards escaped.
 * $walkins_only: mechanics only ever see walk-in clients (no policy).
 * ========================================================================= */

function search_clients(mysqli $conn, string $like, bool $walkins_only, int $limit = 4): array
{
    return dal_all($conn, "
        SELECT c.client_id, c.full_name, c.contact_number, c.email
        FROM clients c
        WHERE c.deleted_at IS NULL AND (c.full_name LIKE ? OR c.contact_number LIKE ? OR c.email LIKE ?)
        " . ($walkins_only ? 'AND ' . clients_walkin_sql('c') : '') . "
        LIMIT ?", 'sssi', [$like, $like, $like, $limit]);
}

function search_vehicles(mysqli $conn, string $like, bool $walkins_only, int $limit = 4): array
{
    return dal_all($conn, "
        SELECT v.vehicle_id, v.plate_number, v.make, v.model, v.year_model, c.full_name, c.client_id
        FROM vehicles v INNER JOIN clients c ON v.client_id = c.client_id
        WHERE (v.plate_number LIKE ? OR v.make LIKE ? OR v.model LIKE ?)
        " . ($walkins_only ? 'AND ' . clients_walkin_sql('c') : '') . "
        LIMIT ?", 'sssi', [$like, $like, $like, $limit]);
}

function search_policies(mysqli $conn, string $like, int $limit = 3): array
{
    return dal_all($conn, "
        SELECT ip.policy_id, ip.policy_number, ip.coverage_type, ip.policy_end, c.full_name, c.client_id
        FROM insurance_policies ip INNER JOIN clients c ON ip.client_id = c.client_id
        WHERE ip.policy_number LIKE ? OR c.full_name LIKE ?
        LIMIT ?", 'ssi', [$like, $like, $limit]);
}

function search_claims(mysqli $conn, string $like, int $limit = 3): array
{
    return dal_all($conn, "
        SELECT cl.claim_id, cl.status, cl.claim_type, c.full_name, c.client_id, v.plate_number
        FROM claims cl
        INNER JOIN clients c ON cl.client_id = c.client_id
        LEFT JOIN insurance_policies ip ON cl.policy_id = ip.policy_id
        LEFT JOIN vehicles v ON ip.vehicle_id = v.vehicle_id
        WHERE c.full_name LIKE ? OR v.plate_number LIKE ? OR ip.policy_number LIKE ?
        LIMIT ?", 'sssi', [$like, $like, $like, $limit]);
}

function search_repair_jobs(mysqli $conn, string $like, int $limit = 3): array
{
    return dal_all($conn, "
        SELECT rj.job_id, rj.job_number, rj.service_type, rj.status, c.full_name, c.client_id, v.plate_number
        FROM repair_jobs rj
        INNER JOIN clients c ON rj.client_id = c.client_id
        LEFT JOIN vehicles v ON rj.vehicle_id = v.vehicle_id
        WHERE rj.job_number LIKE ? OR c.full_name LIKE ? OR v.plate_number LIKE ?
        LIMIT ?", 'sssi', [$like, $like, $like, $limit]);
}
