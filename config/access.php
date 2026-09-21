<?php
// Who may work with which client, and which deletes would silently wipe other records.
// One copy of the rules so the client pages, vehicle pages and repair pages cannot drift apart.

/**
 * Is this client within the signed-in user's scope?
 *   super_admin — every client
 *   admin       — only clients they personally added (created_by)
 *   mechanic    — only walk-in clients (nobody with an insurance policy on record)
 * Soft-deleted clients are out of scope for everyone.
 */
function client_in_scope(mysqli $conn, int $client_id): bool
{
    if ($client_id <= 0) return false;

    $role = $_SESSION['role'] ?? '';
    $sql  = "SELECT 1 FROM clients c WHERE c.client_id = ? AND c.deleted_at IS NULL";

    if ($role === 'admin') {
        $stmt = $conn->prepare($sql . " AND c.created_by = ? LIMIT 1");
        $uid  = (int)($_SESSION['user_id'] ?? 0);
        $stmt->bind_param('ii', $client_id, $uid);
    } elseif ($role === 'mechanic') {
        $stmt = $conn->prepare($sql . " AND NOT EXISTS (SELECT 1 FROM insurance_policies ip WHERE ip.client_id = c.client_id) LIMIT 1");
        $stmt->bind_param('i', $client_id);
    } elseif ($role === 'super_admin') {
        $stmt = $conn->prepare($sql . " LIMIT 1");
        $stmt->bind_param('i', $client_id);
    } else {
        return false;
    }
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

/**
 * What still hangs off this client and would be destroyed (or orphaned) by deleting them?
 * Returns e.g. "1 policy(s), 2 repair job(s)", or '' when the client can be deleted safely.
 * Vehicles alone are not a blocker — they go with the client.
 */
function client_delete_blockers(mysqli $conn, int $client_id): string
{
    $stmt = $conn->prepare("
        SELECT
          (SELECT COUNT(*) FROM insurance_policies WHERE client_id = ?) AS policies,
          (SELECT COUNT(*) FROM repair_jobs        WHERE client_id = ?) AS repairs,
          (SELECT COUNT(*) FROM claims             WHERE client_id = ?) AS claims
    ");
    $stmt->bind_param('iii', $client_id, $client_id, $client_id);
    $stmt->execute();
    $n = $stmt->get_result()->fetch_assoc();

    $parts = [];
    if ($n['policies']) $parts[] = $n['policies'] . ' policy(s)';
    if ($n['repairs'])  $parts[] = $n['repairs']  . ' repair job(s)';
    if ($n['claims'])   $parts[] = $n['claims']   . ' claim(s)';
    return implode(', ', $parts);
}

/**
 * Which user accounts have left a mark on the system? Deleting such an account would either erase
 * its audit trail (audit_logs cascades) or leave orphaned "created by" ids on real records, so those
 * accounts are deactivated instead. Returns [user_id => true], or null if it could not be worked out
 * (callers then treat every account as having history — the safe direction).
 */
function users_with_history(mysqli $conn): ?array
{
    static $refs = [
        ['audit_logs', 'user_id'],
        ['clients', 'created_by'], ['clients', 'consent_recorded_by'],
        ['insurance_policies', 'created_by'], ['claims', 'created_by'],
        ['repair_jobs', 'created_by'], ['repair_job_images', 'uploaded_by'],
        ['quotations', 'created_by'], ['receipts', 'issued_by'],
        ['billing', 'created_by'], ['client_documents', 'uploaded_by'],
    ];
    $parts = [];
    foreach ($refs as [$table, $col]) {
        $parts[] = "SELECT DISTINCT `$col` AS uid FROM `$table` WHERE `$col` IS NOT NULL";
    }
    try {
        $res = $conn->query(implode(' UNION ', $parts));
        $set = [];
        while ($row = $res->fetch_row()) $set[(int)$row[0]] = true;
        return $set;
    } catch (Throwable $e) {
        error_log('[TG-BASICS] users_with_history failed: ' . $e->getMessage());
        return null;
    }
}

function user_has_history(mysqli $conn, int $user_id): bool
{
    $set = users_with_history($conn);
    return $set === null || isset($set[$user_id]);
}

/**
 * Unregistering a vehicle deletes its policies (the confirm dialog says so), but the database
 * also cascades to the vehicle's repair jobs -> quotations -> e-receipts, and orphans any claim
 * filed against its policies. Those are financial records nobody was warned about, so they block.
 * Returns e.g. "2 repair job(s), 1 claim(s)", or '' when the vehicle can be unregistered.
 */
function vehicle_unregister_blockers(mysqli $conn, int $vehicle_id): string
{
    $stmt = $conn->prepare("
        SELECT
          (SELECT COUNT(*) FROM repair_jobs WHERE vehicle_id = ?) AS repairs,
          (SELECT COUNT(*) FROM claims cl
             INNER JOIN insurance_policies ip ON cl.policy_id = ip.policy_id
             WHERE ip.vehicle_id = ?) AS claims
    ");
    $stmt->bind_param('ii', $vehicle_id, $vehicle_id);
    $stmt->execute();
    $n = $stmt->get_result()->fetch_assoc();

    $parts = [];
    if ($n['repairs']) $parts[] = $n['repairs'] . ' repair job(s)';
    if ($n['claims'])  $parts[] = $n['claims']  . ' claim(s)';
    return implode(', ', $parts);
}
