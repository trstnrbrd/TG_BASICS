<?php
/* =========================================================================
 * Clients and vehicles
 * ========================================================================= */

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
