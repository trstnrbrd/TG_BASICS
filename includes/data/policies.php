<?php
/* =========================================================================
 * Insurance policies and their payments
 *
 * Only the SQL lives here. How the payable is split into installments and
 * which payment status applies (policy_payment_status() in config/access.php)
 * stay with the pages, so the rules are not hidden inside the data layer.
 * ========================================================================= */
require_once __DIR__ . '/../../config/access.php';

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
