<?php
/**
 * Data Access Layer: the SQL of the system's key operations, kept in one
 * place. Pages call these functions by name instead of writing the query
 * themselves.
 *
 * Every query here is a prepared statement (values are bound, never joined
 * into the SQL), which is what protects against SQL injection. The host
 * (InfinityFree) does not allow CREATE PROCEDURE, so these functions stand in
 * for database stored procedures; if the system moves to a host that does,
 * only the body of each function needs to change to a CALL.
 *
 * These functions do not begin or commit transactions. A save that writes
 * more than one row still wraps its calls in db_transaction().
 */
require_once __DIR__ . '/transaction.php';

/* =========================================================================
 * Audit log
 * ========================================================================= */

/**
 * Records one entry in the activity log.
 * $user_id is null for entries written by a scheduled job (no one signed in).
 */
function audit_log_add(mysqli $conn, ?int $user_id, string $action, string $description): bool
{
    $stmt = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, ?, ?)");
    $stmt->bind_param('iss', $user_id, $action, $description);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/* =========================================================================
 * Users: sign-in
 * ========================================================================= */

/**
 * The account a sign-in attempt names, with everything the sign-in needs
 * (password hash, lockout state, 2FA switches), or null if there is none.
 */
function user_find_for_login(mysqli $conn, string $username): ?array
{
    $stmt = $conn->prepare("SELECT user_id, username, password, role, full_name, is_active, activation_token, is_hidden,
                                   two_factor_enabled, totp_enabled, email, failed_attempts, locked_until
                            FROM users WHERE username = ?");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Stores a failed sign-in count. $locked_until (Y-m-d H:i:s) locks the
 * account until then; null leaves the current lock value as it is.
 */
function user_record_failed_login(mysqli $conn, string $username, int $attempts, ?string $locked_until = null): void
{
    $stmt = $conn->prepare("UPDATE users SET failed_attempts = ?, locked_until = COALESCE(?, locked_until) WHERE username = ?");
    $stmt->bind_param('iss', $attempts, $locked_until, $username);
    $stmt->execute();
    $stmt->close();
}

/** Resets the failed sign-in count and lifts any lock (correct password). */
function user_clear_failed_logins(mysqli $conn, string $username): void
{
    $stmt = $conn->prepare("UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE username = ?");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $stmt->close();
}

/** Marks the user as signed in now (the "online" indicator and idle sign-out read last_active). */
function user_mark_signed_in(mysqli $conn, int $user_id): void
{
    $stmt = $conn->prepare("UPDATE users SET last_active = NOW() WHERE user_id = ?");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stmt->close();
}

/** Marks the user as signed out (no longer shown online). */
function user_mark_signed_out(mysqli $conn, int $user_id): void
{
    $stmt = $conn->prepare("UPDATE users SET last_active = NULL WHERE user_id = ?");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stmt->close();
}

/**
 * Creates a new 6-digit email sign-in code for the user, valid 10 minutes,
 * and cancels any older unused one. Returns the code (to be emailed).
 * The expiry uses MySQL's NOW() so PHP and MySQL clocks cannot disagree.
 */
function two_factor_code_issue(mysqli $conn, int $user_id): string
{
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    $inv = $conn->prepare("UPDATE two_factor_codes SET used = 1 WHERE user_id = ? AND used = 0");
    $inv->bind_param('i', $user_id);
    $inv->execute();
    $inv->close();

    $ins = $conn->prepare("INSERT INTO two_factor_codes (user_id, code, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))");
    $ins->bind_param('is', $user_id, $code);
    $ins->execute();
    $ins->close();

    return $code;
}

/**
 * Checks an email sign-in code. A correct, unused, unexpired code is marked
 * used (it works once) and true is returned; anything else returns false.
 */
function two_factor_code_consume(mysqli $conn, int $user_id, string $code): bool
{
    $stmt = $conn->prepare("SELECT id FROM two_factor_codes WHERE user_id = ? AND code = ? AND used = 0 AND expires_at > NOW() LIMIT 1");
    $stmt->bind_param('is', $user_id, $code);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return false;

    $mark = $conn->prepare("UPDATE two_factor_codes SET used = 1 WHERE id = ? AND used = 0");
    $mark->bind_param('i', $row['id']);
    $mark->execute();
    $used = $mark->affected_rows === 1;
    $mark->close();
    return $used;
}

/* =========================================================================
 * Clients and vehicles
 * ========================================================================= */

/**
 * A prepared statement kept open for reuse on this connection. Used by the
 * inserts that Import Clients repeats hundreds of times, so each row costs one
 * round trip to the (remote) database instead of two.
 */
function dal_stmt(mysqli $conn, string $sql): mysqli_stmt
{
    static $cache = null;
    $cache ??= new WeakMap();
    $list = $cache[$conn] ?? [];
    if (!isset($list[$sql])) {
        $list[$sql]    = $conn->prepare($sql);
        $cache[$conn] = $list;
    }
    return $list[$sql];
}

/**
 * Whether a plate number is already on an active (not deleted) client's
 * vehicle. $except_vehicle_id skips the vehicle being edited.
 */
function vehicle_plate_taken(mysqli $conn, string $plate_number, int $except_vehicle_id = 0): bool
{
    $stmt = $conn->prepare("SELECT 1 FROM vehicles v INNER JOIN clients c ON v.client_id = c.client_id
                            WHERE v.plate_number = ? AND v.vehicle_id != ? AND c.deleted_at IS NULL LIMIT 1");
    $stmt->bind_param('si', $plate_number, $except_vehicle_id);
    $stmt->execute();
    $taken = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $taken;
}

/**
 * Adds a client and returns its id. $c: full_name, contact_number, email,
 * facebook_name, address, agent_id. The Data Privacy consent is recorded as
 * signed now by $created_by, and the client gets a random token for their
 * public QR page.
 */
function client_create(mysqli $conn, array $c, int $created_by): int
{
    $token = bin2hex(random_bytes(32));
    $stmt  = dal_stmt($conn, "INSERT INTO clients (full_name, contact_number, email, facebook_name, address, created_by, agent_id,
                                                   consent_signed_at, consent_recorded_by, public_token)
                              VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)");
    $agent_id = (int)$c['agent_id'];
    $stmt->bind_param('sssssiiis', $c['full_name'], $c['contact_number'], $c['email'], $c['facebook_name'], $c['address'],
                      $created_by, $agent_id, $created_by, $token);
    $stmt->execute();
    return (int)$conn->insert_id;
}

/**
 * The token of the client's public QR page. A client added before tokens
 * existed gets one now (only if still empty, so two page loads at once
 * cannot give the client two different links).
 */
function client_ensure_public_token(mysqli $conn, int $client_id): string
{
    $token = bin2hex(random_bytes(32));
    $upd = $conn->prepare("UPDATE clients SET public_token = ? WHERE client_id = ? AND (public_token IS NULL OR public_token = '')");
    $upd->bind_param('si', $token, $client_id);
    $upd->execute();
    $upd->close();

    $sel = $conn->prepare("SELECT public_token FROM clients WHERE client_id = ?");
    $sel->bind_param('i', $client_id);
    $sel->execute();
    $row = $sel->get_result()->fetch_assoc();
    $sel->close();
    return (string)($row['public_token'] ?? '');
}

/**
 * Saves the edited details of a client. $c: full_name, contact_number, email,
 * facebook_name, address, agent_id (0 = unassigned).
 */
function client_update(mysqli $conn, int $client_id, array $c): bool
{
    $agent_id = (int)$c['agent_id'] > 0 ? (int)$c['agent_id'] : null;
    $stmt = $conn->prepare("UPDATE clients SET full_name = ?, contact_number = ?, email = ?, facebook_name = ?, address = ?, agent_id = ?
                            WHERE client_id = ?");
    $stmt->bind_param('sssssii', $c['full_name'], $c['contact_number'], $c['email'], $c['facebook_name'], $c['address'], $agent_id, $client_id);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/**
 * Deletes a client: their vehicles are removed (which cascades through the
 * records hanging off them) and the client row is kept but marked deleted.
 * The caller checks client_delete_blockers() first and wraps this, with its
 * audit entry, in db_transaction().
 */
function client_soft_delete(mysqli $conn, int $client_id): void
{
    $del = $conn->prepare("DELETE FROM vehicles WHERE client_id = ?");
    $del->bind_param('i', $client_id);
    $del->execute();
    $del->close();

    $upd = $conn->prepare("UPDATE clients SET deleted_at = NOW() WHERE client_id = ?");
    $upd->bind_param('i', $client_id);
    $upd->execute();
    $upd->close();
}

/**
 * Adds a vehicle to a client and returns its id. $v: plate_number, make,
 * model, year_model, color, motor_number, serial_number.
 */
function vehicle_create(mysqli $conn, int $client_id, array $v): int
{
    $stmt = dal_stmt($conn, "INSERT INTO vehicles (client_id, plate_number, make, model, year_model, color, motor_number, serial_number)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $year = (int)$v['year_model'];
    $stmt->bind_param('isssisss', $client_id, $v['plate_number'], $v['make'], $v['model'], $year, $v['color'], $v['motor_number'], $v['serial_number']);
    $stmt->execute();
    return (int)$conn->insert_id;
}

/**
 * Unregisters (deletes) a vehicle; its policies go with it (ON DELETE
 * CASCADE). The caller checks vehicle_unregister_blockers() first. Returns
 * false if the vehicle was already gone.
 */
function vehicle_delete(mysqli $conn, int $vehicle_id): bool
{
    $stmt = $conn->prepare("DELETE FROM vehicles WHERE vehicle_id = ?");
    $stmt->bind_param('i', $vehicle_id);
    $stmt->execute();
    $deleted = $stmt->affected_rows > 0;
    $stmt->close();
    return $deleted;
}

/** Saves the edited details of a vehicle. $v: same keys as vehicle_create(). */
function vehicle_update(mysqli $conn, int $vehicle_id, array $v): bool
{
    $year = (int)$v['year_model'];
    $stmt = $conn->prepare("UPDATE vehicles SET plate_number = ?, make = ?, model = ?, year_model = ?, color = ?, motor_number = ?, serial_number = ?
                            WHERE vehicle_id = ?");
    $stmt->bind_param('sssisssi', $v['plate_number'], $v['make'], $v['model'], $year, $v['color'], $v['motor_number'], $v['serial_number'], $vehicle_id);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/* =========================================================================
 * Insurance policies and their payments
 *
 * Only the SQL lives here. How the payable is split into installments and
 * which payment status applies (policy_payment_status() in config/access.php)
 * stay with the pages, so the rules are not hidden inside the data layer.
 * ========================================================================= */

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

/* =========================================================================
 * Billing (statements of account for approved claims)
 * ========================================================================= */

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
