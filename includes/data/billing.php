<?php
/* =========================================================================
 * Billing (statements of account for approved claims)
 * ========================================================================= */
require_once __DIR__ . '/../pagination.php';
require_once __DIR__ . '/../../config/access.php';

/** One billing with its claim, client, vehicle and policy details (for its page), or null. */
function billing_find(mysqli $conn, int $billing_id): ?array
{
    return dal_one($conn, "
        SELECT b.*,
               cl.claim_id, cl.status AS claim_status,
               c.full_name, c.contact_number, c.client_id,
               v.plate_number, v.make, v.model, v.year_model,
               ip.policy_number, ip.coverage_type, ip.participation_fee,
               CASE WHEN u.is_hidden = 1 THEN 'Developer' ELSE u.full_name END AS created_by_name
        FROM billing b
        INNER JOIN claims cl             ON b.claim_id    = cl.claim_id
        INNER JOIN clients c             ON cl.client_id  = c.client_id
        INNER JOIN insurance_policies ip ON cl.policy_id  = ip.policy_id
        LEFT  JOIN vehicles v            ON ip.vehicle_id = v.vehicle_id
        LEFT  JOIN users u               ON b.created_by  = u.user_id
        WHERE b.billing_id = ?", 'i', [$billing_id]);
}

/** The claim details New Billing starts from when opened for one claim, or null. */
function billing_claim_prefill(mysqli $conn, int $claim_id): ?array
{
    return dal_one($conn, "
        SELECT cl.claim_id, cl.incident_date,
               c.full_name, c.client_id,
               ip.policy_number, ip.participation_fee, ip.insurance_company,
               v.make, v.model, v.plate_number
        FROM claims cl
        INNER JOIN clients c             ON cl.client_id  = c.client_id
        INNER JOIN insurance_policies ip ON cl.policy_id  = ip.policy_id
        LEFT  JOIN vehicles v            ON ip.vehicle_id = v.vehicle_id
        WHERE cl.claim_id = ?", 'i', [$claim_id]);
}

/** Claims that can be billed (an approved status, CLAIM_BILLABLE_STATUSES) and have no billing yet, newest first. */
function billing_unbilled_claims(mysqli $conn): array
{
    $st = CLAIM_BILLABLE_STATUSES;
    return dal_all($conn, "
        SELECT cl.claim_id, cl.incident_date, c.full_name, ip.policy_number, v.plate_number, v.make, v.model
        FROM claims cl
        INNER JOIN clients c             ON cl.client_id  = c.client_id
        INNER JOIN insurance_policies ip ON cl.policy_id  = ip.policy_id
        LEFT  JOIN vehicles v            ON ip.vehicle_id = v.vehicle_id
        LEFT  JOIN billing b             ON b.claim_id    = cl.claim_id
        WHERE cl.status IN (" . dal_in($st) . ") AND b.billing_id IS NULL
        ORDER BY cl.created_at DESC", str_repeat('s', count($st)), $st);
}

/** How many claims are waiting to be billed (same rule as billing_unbilled_claims()). */
function billing_unbilled_claims_count(mysqli $conn): int
{
    $st = CLAIM_BILLABLE_STATUSES;
    return (int)dal_value($conn, "
        SELECT COUNT(*) FROM claims cl
        LEFT JOIN billing b ON b.claim_id = cl.claim_id
        WHERE cl.status IN (" . dal_in($st) . ") AND b.billing_id IS NULL", str_repeat('s', count($st)), $st);
}

/**
 * One page of the Billing list. $search matches billing no., client, plate
 * or billed-to; $status is a status code or 'all'; $sort 'newest' / 'oldest'.
 * Returns [rows, pager] (includes/pagination.php).
 */
function billing_list_page(mysqli $conn, string $search, string $status, string $sort): array
{
    $where = ['1=1']; $types = ''; $params = [];
    if ($search !== '') {
        $like    = "%$search%";
        $where[] = '(b.billing_number LIKE ? OR c.full_name LIKE ? OR v.plate_number LIKE ? OR b.billed_to LIKE ?)';
        array_push($params, $like, $like, $like, $like);
        $types  .= 'ssss';
    }
    if ($status !== 'all') {
        $where[]  = 'b.status = ?';
        $params[] = $status;
        $types   .= 's';
    }
    $order = $sort === 'oldest' ? 'b.created_at ASC' : 'b.created_at DESC';
    [$result, $pg] = paginate_query($conn, "
        SELECT b.billing_id, b.billing_number, b.billed_to, b.status,
               b.parts_cost, b.labor_cost, b.other_cost, b.deductible, b.total_amount_due,
               b.created_at, b.sent_at, b.claim_id,
               c.full_name, c.client_id, v.plate_number, v.make, v.model, ip.policy_number
        FROM billing b
        INNER JOIN claims cl             ON b.claim_id    = cl.claim_id
        INNER JOIN clients c             ON cl.client_id  = c.client_id
        INNER JOIN insurance_policies ip ON cl.policy_id  = ip.policy_id
        LEFT  JOIN vehicles v            ON ip.vehicle_id = v.vehicle_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY $order", $types, $params);
    return [$result->fetch_all(MYSQLI_ASSOC), $pg];
}

/** The id of the claim's billing, or null when it has none. */
function billing_id_for_claim(mysqli $conn, int $claim_id): ?int
{
    $id = dal_value($conn, "SELECT billing_id FROM billing WHERE claim_id = ? LIMIT 1", 'i', [$claim_id]);
    return $id === null ? null : (int)$id;
}

/** Whether the claim already has a billing (one billing per claim). */
function billing_exists_for_claim(mysqli $conn, int $claim_id): bool
{
    $stmt = $conn->prepare("SELECT 1 FROM billing WHERE claim_id = ? LIMIT 1");
    $stmt->bind_param('i', $claim_id);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

/**
 * Locks the claim row until the transaction ends, so two people cannot bill
 * the same claim at the same moment. Call inside db_transaction(), before
 * billing_exists_for_claim().
 */
function billing_lock_claim(mysqli $conn, int $claim_id): void
{
    $stmt = $conn->prepare("SELECT claim_id FROM claims WHERE claim_id = ? FOR UPDATE");
    $stmt->bind_param('i', $claim_id);
    $stmt->execute();
    $stmt->get_result()->free();   // read the locked row, or the next query fails with "Commands out of sync"
    $stmt->close();
}

/**
 * Creates a billing with the given billing number and returns its id.
 * $b: claim_id, billed_to, incident_date, repair_date (null = none),
 * parts_cost, labor_cost, other_cost, deductible, signed_by_name,
 * signed_by_title.
 */
function billing_create(mysqli $conn, string $billing_number, array $b, int $created_by): int
{
    $stmt = $conn->prepare("INSERT INTO billing (claim_id, billing_number, billed_to, incident_date, repair_date,
                                                 parts_cost, labor_cost, other_cost, deductible, signed_by_name, signed_by_title, created_by)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('issssddddssi',
        $b['claim_id'], $billing_number, $b['billed_to'], $b['incident_date'], $b['repair_date'],
        $b['parts_cost'], $b['labor_cost'], $b['other_cost'], $b['deductible'],
        $b['signed_by_name'], $b['signed_by_title'], $created_by);
    $stmt->execute();
    $id = (int)$conn->insert_id;
    $stmt->close();
    return $id;
}

/**
 * Saves the edited amounts and details of a billing. $b: billed_to,
 * incident_date, repair_date, parts_cost, labor_cost, other_cost,
 * deductible, notes. $signer (name, title) is copied onto the billing when
 * given; null keeps the current signatory.
 */
function billing_update(mysqli $conn, int $billing_id, array $b, ?array $signer = null): void
{
    $stmt = $conn->prepare("UPDATE billing SET billed_to = ?, incident_date = ?, repair_date = ?, parts_cost = ?, labor_cost = ?,
                                               other_cost = ?, deductible = ?, notes = ?
                            WHERE billing_id = ?");
    $stmt->bind_param('sssddddsi', $b['billed_to'], $b['incident_date'], $b['repair_date'], $b['parts_cost'], $b['labor_cost'],
                      $b['other_cost'], $b['deductible'], $b['notes'], $billing_id);
    $stmt->execute();
    $stmt->close();

    if ($signer) {
        $sg = $conn->prepare("UPDATE billing SET signed_by_name = ?, signed_by_title = ? WHERE billing_id = ?");
        $sg->bind_param('ssi', $signer['name'], $signer['title'], $billing_id);
        $sg->execute();
        $sg->close();
    }
}

/** Changes a billing's status. $sent_at stamps when it was sent; null keeps the earlier stamp. */
function billing_set_status(mysqli $conn, int $billing_id, string $status, ?string $sent_at = null): void
{
    $stmt = $conn->prepare("UPDATE billing SET status = ?, sent_at = COALESCE(?, sent_at) WHERE billing_id = ?");
    $stmt->bind_param('ssi', $status, $sent_at, $billing_id);
    $stmt->execute();
    $stmt->close();
}

/**
 * Makes the billing's received Required Documents exactly $doc_type_ids:
 * unticked ones are removed, newly ticked ones added, and one already
 * received keeps its first received_at.
 */
function billing_set_documents(mysqli $conn, int $billing_id, array $doc_type_ids): void
{
    $ids = array_values(array_unique(array_map('intval', $doc_type_ids)));
    if ($ids) {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $del   = $conn->prepare("DELETE FROM billing_documents WHERE billing_id = ? AND doc_type_id NOT IN ($marks)");
        $del->bind_param('i' . str_repeat('i', count($ids)), $billing_id, ...$ids);
    } else {
        $del = $conn->prepare("DELETE FROM billing_documents WHERE billing_id = ?");
        $del->bind_param('i', $billing_id);
    }
    $del->execute();
    $del->close();

    $ins = $conn->prepare("INSERT IGNORE INTO billing_documents (billing_id, doc_type_id) VALUES (?, ?)");
    foreach ($ids as $t) { $ins->bind_param('ii', $billing_id, $t); $ins->execute(); }
    $ins->close();
}

/** Deletes a billing. */
function billing_delete(mysqli $conn, int $billing_id): void
{
    $stmt = $conn->prepare("DELETE FROM billing WHERE billing_id = ?");
    $stmt->bind_param('i', $billing_id);
    $stmt->execute();
    $stmt->close();
}
