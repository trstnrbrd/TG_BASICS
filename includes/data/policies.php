<?php
/* =========================================================================
 * Insurance policies and their payments
 *
 * Only the SQL lives here. How the payable is split into installments and
 * which payment status applies (policy_payment_status() in config/access.php)
 * stay with the pages, so the rules are not hidden inside the data layer.
 * ========================================================================= */
require_once __DIR__ . '/../../config/access.php';
require_once __DIR__ . '/../pagination.php';
require_once __DIR__ . '/../agent_filter.php';

/* ---- Reading a policy ---- */

/** A policy with days left, its client (and agent's name, developer's masked) and vehicle, for View Policy; or null. */
function policy_find(mysqli $conn, int $policy_id): ?array
{
    return dal_one($conn, "
        SELECT p.*, DATEDIFF(p.policy_end, CURDATE()) AS days_left,
               c.full_name, c.contact_number, c.email, c.address, c.agent_id,
               (SELECT CASE WHEN ag.is_hidden = 1 THEN 'Developer' ELSE ag.full_name END FROM users ag WHERE ag.user_id = c.agent_id) AS agent_name,
               v.plate_number, v.make, v.model, v.year_model, v.color, v.motor_number, v.serial_number
        FROM insurance_policies p
        INNER JOIN clients c  ON p.client_id  = c.client_id
        INNER JOIN vehicles v ON p.vehicle_id = v.vehicle_id
        WHERE p.policy_id = ?", 'i', [$policy_id]);
}

/** A policy of an active client with the client's agent and name and the vehicle, for Edit Policy; or null. */
function policy_find_for_edit(mysqli $conn, int $policy_id): ?array
{
    return dal_one($conn, "
        SELECT p.*, c.agent_id, c.full_name, v.plate_number, v.make, v.model, v.year_model
        FROM insurance_policies p
        INNER JOIN clients c  ON c.client_id = p.client_id
        INNER JOIN vehicles v ON v.vehicle_id = p.vehicle_id
        WHERE p.policy_id = ? AND c.deleted_at IS NULL", 'i', [$policy_id]);
}

/** A policy of an active client with its client's agent (client_agent_id), to renew it; or null. */
function policy_find_for_renewal(mysqli $conn, int $policy_id): ?array
{
    return dal_one($conn, "
        SELECT p.*, c.agent_id AS client_agent_id
        FROM insurance_policies p
        INNER JOIN clients c ON p.client_id = c.client_id
        WHERE p.policy_id = ? AND c.deleted_at IS NULL", 'i', [$policy_id]);
}

/** Locks the policy row until the transaction ends (Edit Policy re-reads it under this lock). */
function policy_lock(mysqli $conn, int $policy_id): void
{
    dal_one($conn, "SELECT policy_id FROM insurance_policies WHERE policy_id = ? FOR UPDATE", 'i', [$policy_id]);
}

/** A policy's installment rows in order; with $lock they stay locked until the transaction ends. */
function policy_installments(mysqli $conn, int $policy_id, bool $lock = false): array
{
    return dal_all($conn, "SELECT * FROM policy_payments WHERE policy_id = ? ORDER BY installment_no ASC" . ($lock ? " FOR UPDATE" : ""), 'i', [$policy_id]);
}

/** One installment of this policy (payment_id, receipt_file), or null if it is not this policy's. */
function policy_payment_find(mysqli $conn, int $payment_id, int $policy_id): ?array
{
    return dal_one($conn, "SELECT payment_id, receipt_file FROM policy_payments WHERE payment_id = ? AND policy_id = ?", 'ii', [$payment_id, $policy_id]);
}

/** How many claims are filed on a policy. */
function policy_claims_count(mysqli $conn, int $policy_id): int
{
    return (int)dal_value($conn, "SELECT COUNT(*) FROM claims WHERE policy_id = ?", 'i', [$policy_id]);
}

/** Whether another policy already uses this policy number ($except_policy_id: the policy being edited or renewed). */
function policy_number_taken(mysqli $conn, string $policy_number, int $except_policy_id = 0): bool
{
    return dal_value($conn, "SELECT 1 FROM insurance_policies WHERE policy_number = ? AND policy_id <> ? LIMIT 1", 'si', [$policy_number, $except_policy_id]) !== null;
}

/* ---- Eligibility check / New Policy ---- */

/** The search on Eligibility Check: client, contact, plate, make/model, engine or chassis no. */
const POLICY_VEHICLE_SEARCH_SQL = "c.deleted_at IS NULL AND (c.full_name LIKE ? OR v.plate_number LIKE ? OR v.make LIKE ? OR v.model LIKE ?
                                   OR c.contact_number LIKE ? OR v.motor_number LIKE ? OR v.serial_number LIKE ? OR CONCAT(v.make, ' ', v.model) LIKE ?)";

/** Up to $limit vehicles (with client) matching $text, by client name — the live suggestions. */
function policy_vehicle_suggest(mysqli $conn, string $text, int $limit = 8): array
{
    return dal_all($conn, "
        SELECT c.client_id, c.full_name, c.contact_number, v.vehicle_id, v.plate_number, v.make, v.model, v.year_model
        FROM clients c INNER JOIN vehicles v ON c.client_id = v.client_id
        WHERE " . POLICY_VEHICLE_SEARCH_SQL . "
        ORDER BY c.full_name ASC
        LIMIT ?", str_repeat('s', 8) . 'i', array_merge(array_fill(0, 8, "%$text%"), [$limit]));
}

/** One page of vehicles (with client) matching $text, by client name. Returns [rows, pager]. */
function policy_vehicle_search_page(mysqli $conn, string $text): array
{
    [$result, $pg] = paginate_query($conn, "
        SELECT c.client_id, c.full_name, c.contact_number, v.vehicle_id, v.plate_number, v.make, v.model, v.year_model, v.color
        FROM clients c INNER JOIN vehicles v ON c.client_id = v.client_id
        WHERE " . POLICY_VEHICLE_SEARCH_SQL . "
        ORDER BY c.full_name ASC", str_repeat('s', 8), array_fill(0, 8, "%$text%"));
    return [$result->fetch_all(MYSQLI_ASSOC), $pg];
}

/** A vehicle of an active client with the client's details, to check eligibility or file a policy on it; or null. */
function policy_vehicle_with_client(mysqli $conn, int $vehicle_id): ?array
{
    return dal_one($conn, "
        SELECT c.client_id, c.full_name, c.contact_number, c.address,
               v.vehicle_id, v.plate_number, v.make, v.model, v.year_model, v.color, v.motor_number, v.serial_number
        FROM vehicles v INNER JOIN clients c ON v.client_id = c.client_id
        WHERE v.vehicle_id = ? AND c.deleted_at IS NULL", 'i', [$vehicle_id]);
}

/** A vehicle of an active client found by its exact plate number, with the client's details; or null. */
function policy_vehicle_by_plate(mysqli $conn, string $plate_number): ?array
{
    return dal_one($conn, "
        SELECT c.client_id, c.full_name, c.contact_number, c.address,
               v.vehicle_id, v.plate_number, v.make, v.model, v.year_model, v.color, v.motor_number, v.serial_number
        FROM vehicles v INNER JOIN clients c ON v.client_id = c.client_id
        WHERE v.plate_number = ? AND c.deleted_at IS NULL
        LIMIT 1", 's', [$plate_number]);
}

/** The terms of the vehicle's latest policy (to pre-fill a new one), or null. */
function vehicle_last_policy_terms(mysqli $conn, int $vehicle_id): ?array
{
    return dal_one($conn, "
        SELECT coverage_type, sum_insured, total_premium, markup, participation_fee, payment_terms, mortgagee
        FROM insurance_policies WHERE vehicle_id = ?
        ORDER BY policy_end DESC
        LIMIT 1", 'i', [$vehicle_id]);
}

/** Whether the vehicle has a policy that has not expired yet. */
function vehicle_has_active_policy(mysqli $conn, int $vehicle_id): bool
{
    return dal_value($conn, "SELECT 1 FROM insurance_policies WHERE vehicle_id = ? AND policy_end >= CURDATE() LIMIT 1", 'i', [$vehicle_id]) !== null;
}

/* ---- Renewal Tracking ---- */

/**
 * The WHERE condition of a renewal window (urgent / expiring / stable /
 * expired / renewed, or '' for all) and its bound values.
 */
function policy_window_sql(string $window, int $urgent_days, int $expiring_days): array
{
    return match ($window) {
        'urgent'   => ['p.policy_end >= CURDATE() AND DATEDIFF(p.policy_end, CURDATE()) <= ?', 'i', [$urgent_days]],
        'expiring' => ['p.policy_end >= CURDATE() AND DATEDIFF(p.policy_end, CURDATE()) BETWEEN ? AND ?', 'ii', [$urgent_days + 1, $expiring_days]],
        'stable'   => ['p.policy_end >= CURDATE() AND DATEDIFF(p.policy_end, CURDATE()) > ?', 'i', [$expiring_days]],
        'expired'  => ['p.policy_end < CURDATE()', '', []],
        'renewed'  => ['p.renewed_at IS NOT NULL', '', []],
        default    => ['1=1', '', []],
    };
}

/**
 * One page of Renewal Tracking for one insurance company: current (not
 * renewed) policies, soonest to expire first, in the $filter window, matching
 * $search, for the insurance-agent filter $af. Returns [rows, pager].
 */
function policies_renewal_page(mysqli $conn, string $company, string $filter, string $search, array $af, int $urgent_days, int $expiring_days): array
{
    $where = ['p.is_renewed = 0', '1=1' . agent_filter_sql($af, 'c.agent_id'), 'p.insurance_company = ?'];
    $types = 's'; $params = [$company];
    if ($search !== '') {
        $like    = "%$search%";
        $where[] = '(c.full_name LIKE ? OR c.contact_number LIKE ? OR v.plate_number LIKE ? OR p.policy_number LIKE ?)';
        array_push($params, $like, $like, $like, $like);
        $types  .= 'ssss';
    }
    [$wsql, $wt, $wp] = policy_window_sql($filter, $urgent_days, $expiring_days);
    $where[] = $wsql; $types .= $wt; $params = array_merge($params, $wp);
    [$result, $pg] = paginate_query($conn, "
        SELECT p.policy_id, p.policy_number, p.coverage_type, p.insurance_company,
               p.policy_start, p.policy_end, p.is_renewed, p.renewed_at,
               p.sum_insured, p.total_premium, p.amount_paid, p.balance, p.payment_status,
               DATEDIFF(p.policy_end, CURDATE()) AS days_left,
               c.client_id, c.full_name, c.contact_number, v.plate_number, v.make, v.model, v.year_model,
               CASE WHEN u.is_hidden = 1 THEN 'Developer' ELSE u.full_name END AS added_by_name,
               CASE WHEN ag.is_hidden = 1 THEN 'Developer' ELSE ag.full_name END AS agent_name,
               CASE WHEN ag.is_hidden = 1 THEN NULL ELSE ag.profile_photo END AS agent_photo
        FROM insurance_policies p
        INNER JOIN clients c  ON p.client_id  = c.client_id
        INNER JOIN vehicles v ON p.vehicle_id = v.vehicle_id
        LEFT JOIN users u  ON p.created_by = u.user_id
        LEFT JOIN users ag ON c.agent_id   = ag.user_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY p.policy_end ASC", $types, $params);
    return [$result->fetch_all(MYSQLI_ASSOC), $pg];
}

/** The Renewal Tracking stat cards for one company and agent filter: total, urgent, expiring, stable, expired, renewed. */
function policies_renewal_counts(mysqli $conn, string $company, array $af, int $urgent_days, int $expiring_days): array
{
    $cols = []; $types = ''; $params = [];
    foreach (['urgent', 'expiring', 'stable', 'expired', 'renewed'] as $w) {
        [$wsql, $wt, $wp] = policy_window_sql($w, $urgent_days, $expiring_days);
        $cols[] = "SUM(CASE WHEN p.is_renewed = 0 AND $wsql THEN 1 ELSE 0 END) AS $w";
        $types .= $wt; $params = array_merge($params, $wp);
    }
    return dal_one($conn, "
        SELECT SUM(CASE WHEN p.is_renewed = 0 THEN 1 ELSE 0 END) AS total, " . implode(', ', $cols) . "
        FROM insurance_policies p
        INNER JOIN clients c ON c.client_id = p.client_id
        WHERE p.insurance_company = ? AND 1=1" . agent_filter_sql($af, 'c.agent_id'), $types . 's', array_merge($params, [$company])) ?? [];
}

/**
 * How many current (not renewed) policies expire within $days days from
 * today, counting only the policies the signed-in user follows up
 * (renewal_scope_sql(): the Owner all, an admin their own clients').
 */
function policies_due_count(mysqli $conn, int $days): int
{
    return (int)dal_value($conn, "
        SELECT COUNT(*) FROM insurance_policies p INNER JOIN clients c ON c.client_id = p.client_id
        WHERE p.is_renewed = 0 AND DATEDIFF(p.policy_end, CURDATE()) BETWEEN 0 AND ? AND " . renewal_scope_sql('c'), 'i', [$days]);
}

/** The first $limit of those policies, soonest first, with client and vehicle. */
function policies_due_list(mysqli $conn, int $days, int $limit): array
{
    return dal_all($conn, "
        SELECT p.policy_id, p.policy_number, p.policy_end, p.coverage_type,
               DATEDIFF(p.policy_end, CURDATE()) AS days_left,
               c.full_name, v.plate_number, v.make, v.model
        FROM insurance_policies p
        INNER JOIN vehicles v ON p.vehicle_id = v.vehicle_id
        INNER JOIN clients c  ON p.client_id  = c.client_id
        WHERE p.is_renewed = 0 AND DATEDIFF(p.policy_end, CURDATE()) BETWEEN 0 AND ? AND " . renewal_scope_sql('c') . "
        ORDER BY p.policy_end ASC
        LIMIT ?", 'ii', [$days, $limit]);
}

/**
 * Files a new policy and returns its id. $p: client_id, vehicle_id,
 * policy_number, insurance_company, coverage_type, sum_insured, markup
 * (the commission), total_premium, participation_fee, policy_start,
 * policy_end, payment_terms, mortgagee, payment_status, amount_paid,
 * balance, notes.
 */
function policy_create(mysqli $conn, array $p, int $created_by): int
{
    $stmt = $conn->prepare("INSERT INTO insurance_policies (
                                client_id, vehicle_id, policy_number, insurance_company, coverage_type,
                                sum_insured, markup, total_premium, participation_fee,
                                policy_start, policy_end, payment_terms, mortgagee, payment_status,
                                amount_paid, balance, notes, created_by
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('iisssddddsssssddsi',
        $p['client_id'], $p['vehicle_id'], $p['policy_number'], $p['insurance_company'], $p['coverage_type'],
        $p['sum_insured'], $p['markup'], $p['total_premium'], $p['participation_fee'],
        $p['policy_start'], $p['policy_end'], $p['payment_terms'], $p['mortgagee'], $p['payment_status'],
        $p['amount_paid'], $p['balance'], $p['notes'], $created_by);
    $stmt->execute();
    $id = (int)$conn->insert_id;
    $stmt->close();
    return $id;
}

/**
 * Saves the edited details of a policy together with its recomputed totals.
 * $p: insurance_company, policy_number, coverage_type, policy_start,
 * policy_end, sum_insured, markup, total_premium, participation_fee,
 * payment_terms, mortgagee, notes.
 */
function policy_update(mysqli $conn, int $policy_id, array $p, float $amount_paid, float $balance, string $payment_status): void
{
    $stmt = $conn->prepare("UPDATE insurance_policies SET insurance_company = ?, policy_number = ?, coverage_type = ?, policy_start = ?, policy_end = ?,
                                   sum_insured = ?, markup = ?, total_premium = ?, participation_fee = ?, payment_terms = ?, mortgagee = ?, notes = ?,
                                   amount_paid = ?, balance = ?, payment_status = ?
                            WHERE policy_id = ?");
    $stmt->bind_param('sssssddddsssddsi',
        $p['insurance_company'], $p['policy_number'], $p['coverage_type'], $p['policy_start'], $p['policy_end'],
        $p['sum_insured'], $p['markup'], $p['total_premium'], $p['participation_fee'], $p['payment_terms'], $p['mortgagee'], $p['notes'],
        $amount_paid, $balance, $payment_status, $policy_id);
    $stmt->execute();
    $stmt->close();
}

/**
 * Stores a policy's payment totals and status. $notes replaces the notes
 * when given; null keeps them.
 */
function policy_set_totals(mysqli $conn, int $policy_id, float $amount_paid, float $balance, string $payment_status, ?string $notes = null): void
{
    $stmt = $conn->prepare("UPDATE insurance_policies SET amount_paid = ?, balance = ?, payment_status = ?, notes = COALESCE(?, notes) WHERE policy_id = ?");
    $stmt->bind_param('ddssi', $amount_paid, $balance, $payment_status, $notes, $policy_id);
    $stmt->execute();
    $stmt->close();
}

/** Changes only the payment status of a policy (the page's overdue sync). */
function policy_set_payment_status(mysqli $conn, int $policy_id, string $payment_status): void
{
    $stmt = $conn->prepare("UPDATE insurance_policies SET payment_status = ? WHERE policy_id = ?");
    $stmt->bind_param('si', $payment_status, $policy_id);
    $stmt->execute();
    $stmt->close();
}

/** Marks $old_policy_id as renewed and stamps the renewal time on $new_policy_id. */
function policy_mark_renewed(mysqli $conn, int $old_policy_id, int $new_policy_id): void
{
    $old = $conn->prepare("UPDATE insurance_policies SET is_renewed = 1 WHERE policy_id = ?");
    $old->bind_param('i', $old_policy_id);
    $old->execute();
    $old->close();

    $new = $conn->prepare("UPDATE insurance_policies SET renewed_at = NOW() WHERE policy_id = ?");
    $new->bind_param('i', $new_policy_id);
    $new->execute();
    $new->close();
}

/**
 * Deletes a policy (its payment rows go with it, ON DELETE CASCADE). With
 * $with_claims its claims are deleted too. Returns the claim files that
 * belonged to those claims, for the caller to remove from disk only after
 * the transaction has committed.
 */
function policy_delete(mysqli $conn, int $policy_id, bool $with_claims): array
{
    $files = [];
    if ($with_claims) {
        $cf = $conn->prepare("SELECT doc_or_cr_file, doc_drivers_license_file, doc_insurance_policy_file, doc_damage_photos_file, doc_police_report_file
                              FROM claims WHERE policy_id = ?");
        $cf->bind_param('i', $policy_id);
        $cf->execute();
        foreach ($cf->get_result()->fetch_all(MYSQLI_NUM) as $row) {
            foreach ($row as $f) if ($f) $files[] = $f;
        }
        $cf->close();

        $ph = $conn->prepare("SELECT p.filename FROM claim_damage_photos p INNER JOIN claims c ON p.claim_id = c.claim_id WHERE c.policy_id = ?");
        $ph->bind_param('i', $policy_id);
        $ph->execute();
        foreach ($ph->get_result()->fetch_all(MYSQLI_NUM) as $row) if ($row[0]) $files[] = $row[0];
        $ph->close();

        $dc = $conn->prepare("DELETE FROM claims WHERE policy_id = ?");
        $dc->bind_param('i', $policy_id);
        $dc->execute();
        $dc->close();
    }

    $del = $conn->prepare("DELETE FROM insurance_policies WHERE policy_id = ?");
    $del->bind_param('i', $policy_id);
    $del->execute();
    $del->close();
    return $files;
}

/**
 * Marks the given policies "Overdue" (only those still Unpaid or Partial).
 * Returns how many changed.
 */
function policies_mark_overdue(mysqli $conn, array $policy_ids): int
{
    $ids = array_values(array_map('intval', $policy_ids));
    if (!$ids) return 0;
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt  = $conn->prepare("UPDATE insurance_policies SET payment_status = 'Overdue'
                             WHERE policy_id IN ($marks) AND payment_status IN ('Unpaid', 'Partial')");
    $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    $stmt->execute();
    $n = $stmt->affected_rows;
    $stmt->close();
    return $n;
}

/** Moves every policy of a renamed insurance company to its new name. Returns how many moved. */
function policies_rename_company(mysqli $conn, string $old_name, string $new_name): int
{
    $stmt = $conn->prepare("UPDATE insurance_policies SET insurance_company = ? WHERE insurance_company = ?");
    $stmt->bind_param('ss', $new_name, $old_name);
    $stmt->execute();
    $n = $stmt->affected_rows;
    $stmt->close();
    return $n;
}

/** Records that today's urgent renewal reminder went out for the given policies. */
function policies_stamp_urgent_reminder(mysqli $conn, array $policy_ids): void
{
    $ids = array_values(array_map('intval', $policy_ids));
    if (!$ids) return;
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt  = $conn->prepare("UPDATE insurance_policies SET last_urgent_reminder_sent = CURDATE() WHERE policy_id IN ($marks)");
    $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    $stmt->execute();
    $stmt->close();
}

/**
 * Adds one installment row to a policy's payment schedule and returns its
 * id. A row already paid passes its amount, time, mode and control number.
 */
function policy_payment_add(mysqli $conn, int $policy_id, int $installment_no, string $due_date, float $amount_due,
                            float $amount_paid = 0.0, ?string $paid_at = null, ?string $payment_mode = null, ?string $control_number = null): int
{
    $stmt = $conn->prepare("INSERT INTO policy_payments (policy_id, installment_no, due_date, amount_due, amount_paid, paid_at, payment_mode, control_number)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('iisddsss', $policy_id, $installment_no, $due_date, $amount_due, $amount_paid, $paid_at, $payment_mode, $control_number);
    $stmt->execute();
    $id = (int)$conn->insert_id;
    $stmt->close();
    return $id;
}

/** Removes every installment row of a policy (before its schedule is rebuilt). */
function policy_payments_clear(mysqli $conn, int $policy_id): void
{
    $stmt = $conn->prepare("DELETE FROM policy_payments WHERE policy_id = ?");
    $stmt->bind_param('i', $policy_id);
    $stmt->execute();
    $stmt->close();
}

/** Sets the due date and amount due of one installment (schedule re-split). */
function policy_payment_set_schedule(mysqli $conn, int $payment_id, string $due_date, float $amount_due): void
{
    $stmt = $conn->prepare("UPDATE policy_payments SET due_date = ?, amount_due = ? WHERE payment_id = ?");
    $stmt->bind_param('sdi', $due_date, $amount_due, $payment_id);
    $stmt->execute();
    $stmt->close();
}

/** Records what was paid on one installment (null time/mode/control clear them). */
function policy_payment_record(mysqli $conn, int $payment_id, float $amount_paid, ?string $paid_at, ?string $payment_mode, ?string $control_number): void
{
    $stmt = $conn->prepare("UPDATE policy_payments SET amount_paid = ?, paid_at = ?, payment_mode = ?, control_number = ? WHERE payment_id = ?");
    $stmt->bind_param('dsssi', $amount_paid, $paid_at, $payment_mode, $control_number, $payment_id);
    $stmt->execute();
    $stmt->close();
}

/**
 * Undoes a saved installment payment so it can be entered again. Returns
 * false if it was not paid (already undone, e.g. a double-click or two tabs).
 */
function policy_payment_undo(mysqli $conn, int $payment_id, int $policy_id): bool
{
    $stmt = $conn->prepare("UPDATE policy_payments SET amount_paid = 0, paid_at = NULL, payment_mode = NULL, control_number = NULL
                            WHERE payment_id = ? AND policy_id = ? AND amount_paid > 0");
    $stmt->bind_param('ii', $payment_id, $policy_id);
    $stmt->execute();
    $undone = $stmt->affected_rows === 1;
    $stmt->close();
    return $undone;
}

/** Sets (or, with null, clears) the receipt image of one installment. */
function policy_payment_set_receipt(mysqli $conn, int $payment_id, ?string $file_name): void
{
    $stmt = $conn->prepare("UPDATE policy_payments SET receipt_file = ? WHERE payment_id = ?");
    $stmt->bind_param('si', $file_name, $payment_id);
    $stmt->execute();
    $stmt->close();
}

/**
 * The stored payment totals of a policy's schedule:
 * [total paid, amount due on past due dates, amount paid on past due dates].
 */
function policy_payment_totals(mysqli $conn, int $policy_id): array
{
    $stmt = $conn->prepare("SELECT COALESCE(SUM(amount_paid), 0),
                                   COALESCE(SUM(CASE WHEN due_date < CURDATE() THEN amount_due  END), 0),
                                   COALESCE(SUM(CASE WHEN due_date < CURDATE() THEN amount_paid END), 0)
                            FROM policy_payments WHERE policy_id = ?");
    $stmt->bind_param('i', $policy_id);
    $stmt->execute();
    $row = array_map('floatval', $stmt->get_result()->fetch_row());
    $stmt->close();
    return $row;
}
