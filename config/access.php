<?php

/*
 * Business rules and access rules used by many pages.
 * Kept in one place so every page follows the same rule.
 */

// The status lists themselves (billing, claim, repair) and the repair-flow rules read the `statuses` table
require_once __DIR__ . '/../includes/statuses.php';
// The rules decide here; the counts and look-ups they need are in the data layer (includes/data/)
require_once __DIR__ . '/../includes/data_access.php';

// Where a claim can go next from each status (view_claim.php: the Update Status choices and its server check)
const CLAIM_STATUS_FLOW = [
    'compiling'            => ['sent_admin', 'lack_of_requirements'],
    'sent_admin'           => ['sent_head_office', 'lack_of_requirements'],
    'sent_head_office'     => ['waiting_loa', 'denied'],
    'waiting_loa'          => ['loa_received', 'denied'],
    'loa_received'         => ['pending', 'denied'],
    'pending'              => ['approved', 'denied'],
    'approved'             => ['resolved'],
    'lack_of_requirements' => ['compiling'],
];

/** The statuses a claim in $status can move to ([] for denied / resolved, the end of the flow). */
function claim_next_statuses(string $status): array
{
    return CLAIM_STATUS_FLOW[$status] ?? [];
}

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

// Unit Inspection: the areas on the physical inspection form (repair_checklist.area_key). Each one is also a shape
// drawn on the New Repair Job diagram (data-panel="<key>"), so an area is added in code with its shape, not as data.
const REPAIR_CHECKLIST_AREAS = [
    'front_bumper' => 'Front Bumper',
    'rear_bumper'  => 'Rear Bumper',
    'hood'         => 'Hood',
    'trunk'        => 'Trunk',
    'windshield'   => 'Windshield',
    'door_fl'      => 'Left Front Door',
    'door_rl'      => 'Left Rear Door',
    'door_fr'      => 'Right Front Door',
    'door_rr'      => 'Right Rear Door',
    'mirror_left'  => 'Left Mirror',
    'mirror_right' => 'Right Mirror',
    'headlights'   => 'Headlights',
    'taillights'   => 'Taillights',
    'tires_wheels' => 'Tires / Wheels',
];

// The condition an area can be marked with (repair_checklist.condition_value): label, text colour, background
const REPAIR_DAMAGE_LEVELS = [
    'none'  => ['label' => 'No Damage',     'color' => '#6B7280', 'bg' => 'rgba(107,114,128,0.1)'],
    'minor' => ['label' => 'Minor Scratch', 'color' => '#B8860B', 'bg' => 'rgba(184,134,11,0.12)'],
    'major' => ['label' => 'Major Damage',  'color' => '#C0392B', 'bg' => 'rgba(192,57,43,0.12)'],
];

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
 * Where a policy stands on renewal: 'expired', 'urgent', 'expiring' or 'stable'. The two thresholds are the
 * renewal_urgent_days / renewal_expiring_days settings. Used by View Policy and the renewal list, so both always
 * agree.
 */
function policy_renewal_status(string $policy_end, int $days_left, int $urgent_days, int $expiring_days): string
{
    if (policy_is_expired($policy_end)) return 'expired';
    if ($days_left <= $urgent_days)     return 'urgent';
    if ($days_left <= $expiring_days)   return 'expiring';
    return 'stable';
}

const POLICY_RENEWED_BADGE_DAYS = 3;

/** Renewed within the last POLICY_RENEWED_BADGE_DAYS days (the "Renewed" badge). */
function policy_recently_renewed(?string $renewed_at): bool
{
    return !empty($renewed_at) && strtotime($renewed_at) >= strtotime('-' . POLICY_RENEWED_BADGE_DAYS . ' days');
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
    if ($role === 'mechanic') return client_exists_active($conn, $client_id, true);   // walk-ins only
    if ($role !== 'admin' && $role !== 'super_admin') return false;
    return client_exists_active($conn, $client_id);
}

/** Can this user edit the client? Owner: all. Admin: only clients they added or handle as agent. */
function client_editable(mysqli $conn, int $client_id): bool
{
    if ($client_id <= 0) return false;

    $role = $_SESSION['role'] ?? '';
    if ($role === 'super_admin') return client_exists_active($conn, $client_id);
    if ($role === 'admin')       return client_belongs_to($conn, $client_id, (int)($_SESSION['user_id'] ?? 0));
    return false;
}

/** Can this user restore the deleted client ($client: created_by, agent_id)? Same rule as deleting: owner all, admin their own. */
function client_restorable(array $client): bool
{
    $role = $_SESSION['role'] ?? '';
    if ($role === 'super_admin') return true;
    if ($role !== 'admin') return false;
    $me = (int)($_SESSION['user_id'] ?? 0);
    return (int)$client['created_by'] === $me || (int)$client['agent_id'] === $me;
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
    $agents = [];
    foreach (insurance_agents_rows($conn) as $row) $agents[(int)$row['user_id']] = $row;
    return $agents;
}

/** Clients with the same name, for the "this name already exists" warning. Only a warning: two people can share a name. */
function same_name_clients(mysqli $conn, string $name, int $limit = 5): array
{
    $name = strtoupper(trim(preg_replace('/\s+/', ' ', $name)));
    if ($name === '') return [];
    return clients_named($conn, $name, max(1, $limit));
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
    $n = client_record_counts($conn, $client_id);

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
    try {
        return array_fill_keys(user_ids_with_history($conn), true);   // the columns: USER_HISTORY_COLUMNS
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
    $n = vehicle_record_counts($conn, $vehicle_id);

    $parts = [];
    if ($n['repairs']) $parts[] = $n['repairs'] . ' repair job(s)';
    if ($n['claims'])  $parts[] = $n['claims']  . ' claim(s)';
    return implode(', ', $parts);
}
