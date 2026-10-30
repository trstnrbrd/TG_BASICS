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
