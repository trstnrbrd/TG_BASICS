<?php
/* =========================================================================
 * Clients and vehicles
 * ========================================================================= */

/* ---- Client Records list ---- */
require_once __DIR__ . '/../pagination.php';
require_once __DIR__ . '/../agent_filter.php';

/** SQL that limits to walk-in clients (no policy ever); mechanics only ever see these. $alias: the clients table alias. */
function clients_walkin_sql(string $alias): string
{
    return "NOT EXISTS (SELECT 1 FROM insurance_policies ip WHERE ip.client_id = $alias.client_id)";
}

/** The search box suggestions: up to 8 client/vehicle pairs matching name, contact, plate, make or model. */
function clients_autocomplete(mysqli $conn, string $text, bool $walkins_only): array
{
    $q = '%' . $text . '%';
    return dal_all($conn, "
        SELECT c.client_id, c.full_name, c.contact_number, v.plate_number, v.make, v.model
        FROM clients c
        LEFT JOIN vehicles v ON c.client_id = v.client_id
        WHERE c.deleted_at IS NULL " . ($walkins_only ? 'AND ' . clients_walkin_sql('c') : '') . "
          AND (c.full_name LIKE ? OR c.contact_number LIKE ? OR v.plate_number LIKE ? OR v.make LIKE ? OR v.model LIKE ?)
        GROUP BY c.client_id, v.vehicle_id
        ORDER BY c.full_name ASC
        LIMIT 8", 'sssss', [$q, $q, $q, $q, $q]);
}

/**
 * One page of Client Records. $search in the $filter_by field ('all', 'name',
 * 'plate', 'contact', 'email'); $sort 'newest', 'oldest', 'name_asc',
 * 'name_desc', 'vehicles'; $type 'all', 'insurance', 'walkin'; $af the
 * insurance-agent filter (includes/agent_filter.php). Returns [rows, pager].
 */
function clients_list_page(mysqli $conn, string $search, string $filter_by, string $sort, string $type, array $af): array
{
    $where = []; $types = ''; $params = [];
    if ($search !== '') {
        $like = "%$search%";
        $col  = ['name' => 'c.full_name', 'plate' => 'v.plate_number', 'contact' => 'c.contact_number', 'email' => 'c.email'][$filter_by] ?? null;
        if ($col !== null) {
            $where[] = "$col LIKE ?";
            $types  .= 's'; $params[] = $like;
        } else {
            $where[] = '(c.full_name LIKE ? OR v.plate_number LIKE ? OR c.contact_number LIKE ? OR c.email LIKE ?)';
            $types  .= 'ssss'; array_push($params, $like, $like, $like, $like);
        }
    }
    $where[] = 'c.deleted_at IS NULL';
    $order = match ($sort) {
        'oldest'    => 'c.created_at ASC',
        'name_asc'  => 'c.full_name ASC',
        'name_desc' => 'c.full_name DESC',
        'vehicles'  => 'vehicle_count DESC',
        default     => 'c.created_at DESC',
    };
    $having = ['insurance' => 'HAVING has_policy = 1', 'walkin' => 'HAVING has_policy = 0'][$type] ?? '';
    [$result, $pg] = paginate_query($conn, "
        SELECT c.client_id, c.full_name, c.contact_number, c.email, c.created_by, c.agent_id,
               COUNT(DISTINCT v.vehicle_id) AS vehicle_count, c.created_at,
               COUNT(DISTINCT ip.policy_id) > 0 AS has_policy,
               CASE WHEN u.is_hidden = 1 THEN 'Developer' ELSE u.full_name END AS added_by_name,
               CASE WHEN ag.is_hidden = 1 THEN 'Developer' ELSE ag.full_name END AS agent_name,
               CASE WHEN ag.is_hidden = 1 THEN NULL ELSE ag.profile_photo END AS agent_photo
        FROM clients c
        LEFT JOIN vehicles v            ON c.client_id = v.client_id
        LEFT JOIN insurance_policies ip ON c.client_id = ip.client_id
        LEFT JOIN users u               ON c.created_by = u.user_id
        LEFT JOIN users ag              ON c.agent_id = ag.user_id
        WHERE " . implode(' AND ', $where) . agent_filter_sql($af, 'c.agent_id') . "
        GROUP BY c.client_id
        $having
        ORDER BY $order", $types, $params);
    return [$result->fetch_all(MYSQLI_ASSOC), $pg];
}

/** The list's stat cards for the same agent filter: clients, vehicles and clients added in the last 30 days. */
function clients_list_stats(mysqli $conn, bool $walkins_only, array $af): array
{
    $cond = fn(string $a) => ($walkins_only ? ' AND ' . clients_walkin_sql($a) : '') . agent_filter_sql($af, "$a.agent_id");
    return [
        'clients'  => (int)dal_value($conn, "SELECT COUNT(*) FROM clients c WHERE c.deleted_at IS NULL" . $cond('c')),
        'vehicles' => (int)dal_value($conn, "SELECT COUNT(*) FROM vehicles v INNER JOIN clients c ON v.client_id = c.client_id WHERE c.deleted_at IS NULL" . $cond('c')),
        'recent'   => (int)dal_value($conn, "SELECT COUNT(*) FROM clients c WHERE c.deleted_at IS NULL AND c.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)" . $cond('c')),
    ];
}

/* ---- Import Clients ---- */

/** Plate number and client name of every vehicle of an active client (the import's duplicate check). */
function vehicles_active_plates(mysqli $conn): array
{
    return dal_all($conn, "SELECT v.plate_number, c.full_name FROM vehicles v JOIN clients c ON c.client_id = v.client_id WHERE c.deleted_at IS NULL");
}

/** Which of $plates are now on an active client's vehicle (exact match). Returns the plate numbers found. */
function vehicles_plates_taken(mysqli $conn, array $plates): array
{
    if (!$plates) return [];
    return array_column(dal_all($conn, "SELECT v.plate_number FROM vehicles v INNER JOIN clients c ON v.client_id = c.client_id
                                        WHERE c.deleted_at IS NULL AND v.plate_number IN (" . dal_in($plates) . ")",
                                str_repeat('s', count($plates)), array_values($plates)), 'plate_number');
}

/** UPPER name => ['ABC 1234, XYZ 789', ...] for active clients already in the system with one of these names. */
function clients_with_names(mysqli $conn, array $names): array
{
    $found = [];
    foreach (array_chunk(array_values($names), 200) as $chunk) {
        $rows = dal_all($conn, "
            SELECT c.full_name, GROUP_CONCAT(v.plate_number ORDER BY v.vehicle_id SEPARATOR ', ') AS plates
            FROM clients c LEFT JOIN vehicles v ON v.client_id = c.client_id
            WHERE c.deleted_at IS NULL AND c.full_name IN (" . dal_in($chunk) . ")
            GROUP BY c.client_id", str_repeat('s', count($chunk)), $chunk);
        foreach ($rows as $row) $found[mb_strtoupper($row['full_name'])][] = $row['plates'] ?: 'no vehicle';
    }
    return $found;
}

/* ---- Vehicle pages ---- */

/** A vehicle (all columns) with its client's name, contact number and email, or null. */
function vehicle_find(mysqli $conn, int $vehicle_id): ?array
{
    return dal_one($conn, "
        SELECT v.*, c.full_name AS client_name, c.contact_number, c.email
        FROM vehicles v
        INNER JOIN clients c ON v.client_id = c.client_id
        WHERE v.vehicle_id = ?", 'i', [$vehicle_id]);
}

/** An active client's row (all columns), or null. */
function client_row(mysqli $conn, int $client_id): ?array
{
    return dal_one($conn, "SELECT * FROM clients WHERE client_id = ? AND deleted_at IS NULL", 'i', [$client_id]);
}

/* ---- View Client ---- */

/** An active client with its insurance agent's and adder's names (developer's masked), or null. */
function client_find(mysqli $conn, int $client_id): ?array
{
    return dal_one($conn, "
        SELECT c.*,
               CASE WHEN ag.is_hidden = 1 THEN 'Developer' ELSE ag.full_name END AS agent_name,
               CASE WHEN cb.is_hidden = 1 THEN 'Developer' ELSE cb.full_name END AS added_by_name
        FROM clients c
        LEFT JOIN users ag ON ag.user_id = c.agent_id
        LEFT JOIN users cb ON cb.user_id = c.created_by
        WHERE c.client_id = ? AND c.deleted_at IS NULL", 'i', [$client_id]);
}

/** A client's vehicles, newest first (all columns). */
function client_vehicles(mysqli $conn, int $client_id): array
{
    return dal_all($conn, "SELECT * FROM vehicles WHERE client_id = ? ORDER BY vehicle_id DESC", 'i', [$client_id]);
}

/** A client's policies, newest first, with the vehicle. */
function client_policies(mysqli $conn, int $client_id): array
{
    return dal_all($conn, "
        SELECT p.*, v.plate_number, v.make, v.model, v.year_model
        FROM insurance_policies p
        INNER JOIN vehicles v ON p.vehicle_id = v.vehicle_id
        WHERE p.client_id = ?
        ORDER BY p.created_at DESC", 'i', [$client_id]);
}

/** A client's claims, newest first, with policy no. and vehicle. */
function client_claims(mysqli $conn, int $client_id): array
{
    return dal_all($conn, "
        SELECT cl.claim_id, cl.claim_type, cl.status, cl.incident_date, cl.created_at, cl.denial_reason,
               ip.policy_number, v.plate_number, v.make, v.model
        FROM claims cl
        INNER JOIN insurance_policies ip ON cl.policy_id = ip.policy_id
        LEFT  JOIN vehicles v ON ip.vehicle_id = v.vehicle_id
        WHERE cl.client_id = ?
        ORDER BY cl.created_at DESC", 'i', [$client_id]);
}

/** A client's repair jobs, newest first, with the vehicle. */
function client_repair_jobs(mysqli $conn, int $client_id): array
{
    return dal_all($conn, "
        SELECT j.job_id, j.job_number, j.service_type, j.status, j.repair_date, j.release_date, v.plate_number, v.make, v.model
        FROM repair_jobs j
        INNER JOIN vehicles v ON j.vehicle_id = v.vehicle_id
        WHERE j.client_id = ?
        ORDER BY j.created_at DESC", 'i', [$client_id]);
}

/** The counts on the client's summary strip: vehicles, policies, active_policies (not expired), claims. */
function client_counts(mysqli $conn, int $client_id): array
{
    return [
        'vehicles'        => (int)dal_value($conn, "SELECT COUNT(*) FROM vehicles WHERE client_id = ?", 'i', [$client_id]),
        'policies'        => (int)dal_value($conn, "SELECT COUNT(*) FROM insurance_policies WHERE client_id = ?", 'i', [$client_id]),
        'active_policies' => (int)dal_value($conn, "SELECT COUNT(*) FROM insurance_policies WHERE client_id = ? AND policy_end >= CURDATE()", 'i', [$client_id]),
        'claims'          => (int)dal_value($conn, "SELECT COUNT(*) FROM claims WHERE client_id = ?", 'i', [$client_id]),
    ];
}

/** Whether the client has ever had a policy (an insurance client, not a walk-in). */
function client_has_policy(mysqli $conn, int $client_id): bool
{
    return dal_value($conn, "SELECT 1 FROM insurance_policies WHERE client_id = ? LIMIT 1", 'i', [$client_id]) !== null;
}

/** The client's uploaded PDF documents, newest first. */
function client_documents(mysqli $conn, int $client_id): array
{
    return dal_all($conn, "SELECT doc_id, file_name, original_name, uploaded_at FROM client_documents WHERE client_id = ? ORDER BY uploaded_at DESC",
                   'i', [$client_id]);
}

/** Records an uploaded client document. */
function client_document_add(mysqli $conn, int $client_id, string $file_name, string $original_name, int $uploaded_by): void
{
    dal_exec($conn, "INSERT INTO client_documents (client_id, file_name, original_name, uploaded_by) VALUES (?, ?, ?, ?)",
             'issi', [$client_id, $file_name, $original_name, $uploaded_by]);
}

/** The stored file name of one of the client's documents, or null if it is not theirs. */
function client_document_file(mysqli $conn, int $doc_id, int $client_id): ?string
{
    $f = dal_value($conn, "SELECT file_name FROM client_documents WHERE doc_id = ? AND client_id = ?", 'ii', [$doc_id, $client_id]);
    return $f === null ? null : (string)$f;
}

/** Deletes a client document row. */
function client_document_delete(mysqli $conn, int $doc_id): void
{
    dal_exec($conn, "DELETE FROM client_documents WHERE doc_id = ?", 'i', [$doc_id]);
}

/** Active clients whose name contains $text, by name, at most $limit: [client_id, full_name]. */
function clients_search_by_name(mysqli $conn, string $text, int $limit = 20): array
{
    return dal_all($conn, "SELECT client_id, full_name FROM clients WHERE deleted_at IS NULL AND full_name LIKE ? ORDER BY full_name ASC LIMIT ?",
                   'si', ['%' . $text . '%', $limit]);
}

/** An active (not deleted) client's id and name, or null. */
function client_find_name(mysqli $conn, int $client_id): ?array
{
    return dal_one($conn, "SELECT client_id, full_name FROM clients WHERE client_id = ? AND deleted_at IS NULL", 'i', [$client_id]);
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
