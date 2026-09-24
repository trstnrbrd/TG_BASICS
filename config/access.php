<?php

function client_in_scope(mysqli $conn, int $client_id): bool
{
    if ($client_id <= 0) return false;

    $role = $_SESSION['role'] ?? '';
    $sql  = "SELECT 1 FROM clients c WHERE c.client_id = ? AND c.deleted_at IS NULL";

    if ($role === 'mechanic') {
        $sql .= " AND NOT EXISTS (SELECT 1 FROM insurance_policies ip WHERE ip.client_id = c.client_id)";
    } elseif ($role !== 'admin' && $role !== 'super_admin') {
        return false;
    }
    $stmt = $conn->prepare($sql . " LIMIT 1");
    $stmt->bind_param('i', $client_id);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

function client_editable(mysqli $conn, int $client_id): bool
{
    if ($client_id <= 0) return false;

    $role = $_SESSION['role'] ?? '';
    if ($role === 'super_admin') {
        $stmt = $conn->prepare("SELECT 1 FROM clients WHERE client_id = ? AND deleted_at IS NULL LIMIT 1");
        $stmt->bind_param('i', $client_id);
    } elseif ($role === 'admin') {
        $uid  = (int)($_SESSION['user_id'] ?? 0);
        $stmt = $conn->prepare("SELECT 1 FROM clients WHERE client_id = ? AND deleted_at IS NULL AND (created_by = ? OR agent_id = ?) LIMIT 1");
        $stmt->bind_param('iii', $client_id, $uid, $uid);
    } else {
        return false;
    }
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}


function policy_editable(?int $agent_id): bool
{
    $role = $_SESSION['role'] ?? '';
    if ($role === 'super_admin') return true;
    return $role === 'admin' && $agent_id !== null && $agent_id > 0 && $agent_id === (int)($_SESSION['user_id'] ?? 0);
}


function renewal_scope_sql(string $client_alias = 'c'): string
{
    $role = $_SESSION['role'] ?? '';
    if ($role === 'super_admin') return '1=1';
    if ($role === 'admin') return $client_alias . '.agent_id = ' . (int)($_SESSION['user_id'] ?? 0);
    return '1=0';
}

/**
 * Who a client can belong to: active admins and super admins — never mechanics, and never the hidden
 * oversight account (its real name must not surface anywhere in the UI). Returns [user_id => row].
 */
function insurance_agents(mysqli $conn): array
{
    $res = $conn->query("
        SELECT user_id, full_name, role, profile_photo FROM users
        WHERE role IN ('admin', 'super_admin') AND is_active = 1 AND is_hidden = 0
        ORDER BY FIELD(role, 'super_admin', 'admin'), full_name
    ");
    $agents = [];
    while ($row = $res->fetch_assoc()) $agents[(int)$row['user_id']] = $row;
    return $agents;
}

/** Dropdown label for an insurance agent: "Name — Owner" for the super admin, "(you)" for the signed-in user. */
function agent_option_label(array $agent): string
{
    $label = $agent['full_name'] . ($agent['role'] === 'super_admin' ? ' — Owner' : '');
    if ((int)$agent['user_id'] === (int)($_SESSION['user_id'] ?? 0)) $label .= ' (you)';
    return $label;
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
        ['clients', 'created_by'], ['clients', 'consent_recorded_by'], ['clients', 'agent_id'],
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
