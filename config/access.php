<?php

/*
 * Business rules and access rules used by many pages.
 * Kept in one place so every page follows the same rule.
 */

// Claim statuses where each action is allowed
const CLAIM_DOCS_OPEN_STATUSES = ['compiling', 'sent_admin', 'lack_of_requirements'];
const CLAIM_DELETABLE_STATUSES = ['resolved', 'denied', 'lack_of_requirements'];
const CLAIM_BILLABLE_STATUSES  = ['loa_received', 'pending', 'approved', 'resolved'];

/** Can documents still be uploaded or removed for this claim? */
function claim_docs_open(string $status): bool
{
    return in_array($status, CLAIM_DOCS_OPEN_STATUSES, true);
}

/** Can this claim be deleted? Only when it is already finished. */
function claim_is_deletable(string $status): bool
{
    return in_array($status, CLAIM_DELETABLE_STATUSES, true);
}

/** Can we create a billing for this claim? */
function claim_is_billable(string $status): bool
{
    return in_array($status, CLAIM_BILLABLE_STATUSES, true);
}

// Repair job statuses in the order a job moves through them. 'cancelled' is a dead end, reachable from any open status.
const REPAIR_STATUS_FLOW = ['pending', 'in_progress', 'for_pickup', 'completed'];

/**
 * May a repair job move from $from to $to?
 * $forward_only (mechanics): only to a later status or to cancelled, and never out of completed / cancelled.
 * Otherwise (owner, admins): any other status, so a wrong update can be corrected.
 */
function repair_status_change_allowed(string $from, string $to, bool $forward_only): bool
{
    if ($from === $to) return false;
    if (!$forward_only) return true;
    if ($from === 'completed' || $from === 'cancelled') return false;
    if ($to === 'cancelled') return true;
    $i = array_search($from, REPAIR_STATUS_FLOW, true);
    $j = array_search($to, REPAIR_STATUS_FLOW, true);
    return $i !== false && $j !== false && $j > $i;
}

/** Is the repair job still open (pending, in progress, for pickup)? Photos show on the client's QR page only then. */
function repair_job_is_open(string $status): bool
{
    return !in_array($status, ['completed', 'cancelled'], true);
}

/**
 * May the signed-in user show / hide / delete this repair photo? Only while the job is open (a finished job's
 * photos stay as its record); a mechanic only their own uploads, the owner and admins any photo.
 */
function repair_photo_editable(int $uploaded_by, string $job_status): bool
{
    if (!repair_job_is_open($job_status)) return false;
    if (is_staff()) return true;
    return is_mechanic() && $uploaded_by === (int)($_SESSION['user_id'] ?? 0);
}

/** Is the policy already expired? */
function policy_is_expired(string $policy_end): bool
{
    return $policy_end < date('Y-m-d');
}

/**
 * Paid, Overdue, Partial or Unpaid.
 * Amounts are rounded to centavos first, because adding decimals in PHP is not exact
 * (1028.14 + 1028.13 = 2056.2700000000004).
 */
function policy_payment_status(float $payable, float $total_paid, bool $has_overdue = false): string
{
    if (round($payable - $total_paid, 2) <= 0) return 'Paid';
    if ($has_overdue) return 'Overdue';
    return round($total_paid, 2) > 0 ? 'Partial' : 'Unpaid';
}

/** Is the amount paid more than what the client owes? (compared in centavos) */
function payment_exceeds(float $paid, float $payable): bool
{
    return round($paid, 2) > round($payable, 2);
}

/** Can this user open the client? Admins: all clients. Mechanics: walk-in clients only (no policy). */
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

/** Can this user edit the client? Owner: all. Admin: only clients they added or handle as agent. */
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

/** Can this user edit the policy? Owner: all. Admin: only if they are the client's agent. */
function policy_editable(?int $agent_id): bool
{
    $role = $_SESSION['role'] ?? '';
    if ($role === 'super_admin') return true;
    return $role === 'admin' && $agent_id !== null && $agent_id > 0 && $agent_id === (int)($_SESSION['user_id'] ?? 0);
}

/** SQL filter for Renewal Tracking: the owner sees all, an admin sees only their own clients. */
function renewal_scope_sql(string $client_alias = 'c'): string
{
    $role = $_SESSION['role'] ?? '';
    if ($role === 'super_admin') return '1=1';
    if ($role === 'admin') return $client_alias . '.agent_id = ' . (int)($_SESSION['user_id'] ?? 0);
    return '1=0';
}

/** Users who can be a client's insurance agent: active admins and the owner (not the hidden developer account). */
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

/** Clients with the same name, for the "this name already exists" warning. Only a warning: two people can share a name. */
function same_name_clients(mysqli $conn, string $name, int $limit = 5): array
{
    $name = strtoupper(trim(preg_replace('/\s+/', ' ', $name)));
    if ($name === '') return [];
    $st = $conn->prepare("
        SELECT c.client_id, c.full_name, GROUP_CONCAT(v.plate_number ORDER BY v.vehicle_id SEPARATOR ', ') AS plates
        FROM clients c
        LEFT JOIN vehicles v ON v.client_id = c.client_id
        WHERE c.deleted_at IS NULL AND c.full_name = ?
        GROUP BY c.client_id
        ORDER BY c.client_id
        LIMIT " . max(1, (int)$limit));
    $st->bind_param('s', $name);
    $st->execute();
    return $st->get_result()->fetch_all(MYSQLI_ASSOC);
}

/** Agent name for dropdowns, e.g. "Juan — Owner" or "Maria (you)". */
function agent_option_label(array $agent): string
{
    $label = $agent['full_name'] . ($agent['role'] === 'super_admin' ? ' — Owner' : '');
    if ((int)$agent['user_id'] === (int)($_SESSION['user_id'] ?? 0)) $label .= ' (you)';
    return $label;
}

/** Records that stop a client from being deleted, e.g. "1 policy(s)". Empty = safe to delete. */
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
 * Users who already did something in the system (logs, clients, jobs ...).
 * These can only be deactivated, not deleted, so their history is kept.
 * Returns null on error (then every user is treated as having history, to be safe).
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

/** Records that stop a vehicle from being removed (repair jobs, claims). Empty = safe to remove. */
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
