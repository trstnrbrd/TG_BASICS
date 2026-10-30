<?php
/* =========================================================================
 * Claims
 * ========================================================================= */
require_once __DIR__ . '/../pagination.php';

/**
 * The seven requirement checkboxes of a claim. Each is a column (1 = received)
 * with its file in "<field>_file". Column names cannot be bound like values,
 * so every function that takes a field checks it against this list first.
 */
const CLAIM_DOC_FIELDS = ['doc_insurance_policy', 'doc_or', 'doc_cr', 'doc_drivers_license', 'doc_affidavit', 'doc_estimate', 'doc_damage_photos'];

/** Older file columns still read when a claim is deleted, so their files go too. */
const CLAIM_OLD_FILE_COLUMNS = ['doc_or_cr_file', 'doc_police_report_file'];

function claim_doc_field_check(string $field): void
{
    if (!in_array($field, CLAIM_DOC_FIELDS, true)) throw new InvalidArgumentException("Unknown claim document field: $field");
}

/** The status code of a claim, or null if there is no such claim. */
function claim_status(mysqli $conn, int $claim_id): ?string
{
    $s = dal_value($conn, "SELECT status FROM claims WHERE claim_id = ?", 'i', [$claim_id]);
    return $s === null ? null : (string)$s;
}

/** One claim with its client, policy, vehicle and who filed it (for its page), or null. */
function claim_find(mysqli $conn, int $claim_id): ?array
{
    return dal_one($conn, "
        SELECT cl.*,
               c.full_name, c.contact_number, c.email, c.address, c.client_id,
               ip.policy_number, ip.coverage_type, ip.policy_start, ip.policy_end,
               ip.total_premium, ip.participation_fee, ip.payment_status,
               v.plate_number, v.make, v.model, v.year_model, v.color, v.motor_number, v.serial_number,
               CASE WHEN u.is_hidden = 1 THEN 'Developer' ELSE u.full_name END AS filed_by
        FROM claims cl
        INNER JOIN clients c             ON cl.client_id  = c.client_id
        INNER JOIN insurance_policies ip ON cl.policy_id  = ip.policy_id
        LEFT  JOIN vehicles v            ON ip.vehicle_id = v.vehicle_id
        LEFT  JOIN users u               ON cl.created_by = u.user_id
        WHERE cl.claim_id = ?", 'i', [$claim_id]);
}

/** The claim row alone (all columns), or null. */
function claim_row(mysqli $conn, int $claim_id): ?array
{
    return dal_one($conn, "SELECT * FROM claims WHERE claim_id = ?", 'i', [$claim_id]);
}

/** The number shown for a claim: its position among all claims, not its database id. */
function claim_display_number(mysqli $conn, int $claim_id): int
{
    return (int)dal_value($conn, "SELECT COUNT(*) FROM claims WHERE claim_id <= ?", 'i', [$claim_id]);
}

/** The claim type and the seven requirement flags (to recount progress after a change), or null. */
function claim_doc_flags(mysqli $conn, int $claim_id): ?array
{
    return dal_one($conn, "SELECT claim_type, " . implode(', ', CLAIM_DOC_FIELDS) . " FROM claims WHERE claim_id = ?", 'i', [$claim_id]);
}

/** The file stored for one requirement ('' when none). */
function claim_doc_file(mysqli $conn, int $claim_id, string $field): string
{
    claim_doc_field_check($field);
    return (string)(dal_value($conn, "SELECT `{$field}_file` FROM claims WHERE claim_id = ?", 'i', [$claim_id]) ?? '');
}

/** Marks one requirement received with its file, or (with null) not received and no file. */
function claim_doc_set(mysqli $conn, int $claim_id, string $field, ?string $file_name): void
{
    claim_doc_field_check($field);
    dal_exec($conn, "UPDATE claims SET `$field` = ?, `{$field}_file` = ? WHERE claim_id = ?", 'isi', [$file_name === null ? 0 : 1, $file_name, $claim_id]);
}

/** The damage photos of a claim, oldest first: [photo_id, filename]. */
function claim_damage_photos(mysqli $conn, int $claim_id): array
{
    return dal_all($conn, "SELECT photo_id, filename FROM claim_damage_photos WHERE claim_id = ? ORDER BY uploaded_at ASC", 'i', [$claim_id]);
}

/** Adds a damage photo and ticks "photos present". Returns the photo id. Call inside db_transaction(). */
function claim_damage_photo_add(mysqli $conn, int $claim_id, string $file_name): int
{
    dal_exec($conn, "INSERT INTO claim_damage_photos (claim_id, filename) VALUES (?, ?)", 'is', [$claim_id, $file_name]);
    $id = (int)$conn->insert_id;
    dal_exec($conn, "UPDATE claims SET doc_damage_photos = 1 WHERE claim_id = ? AND doc_damage_photos = 0", 'i', [$claim_id]);
    return $id;
}

/**
 * Removes one damage photo of the claim; when none are left, unticks
 * "photos present". Returns [removed file name or null, photos left]; the
 * caller deletes the file after the commit. Call inside db_transaction().
 */
function claim_damage_photo_remove(mysqli $conn, int $claim_id, int $photo_id): array
{
    $file = dal_value($conn, "SELECT filename FROM claim_damage_photos WHERE photo_id = ? AND claim_id = ?", 'ii', [$photo_id, $claim_id]);
    if ($file !== null) dal_exec($conn, "DELETE FROM claim_damage_photos WHERE photo_id = ?", 'i', [$photo_id]);
    $left = (int)dal_value($conn, "SELECT COUNT(*) FROM claim_damage_photos WHERE claim_id = ?", 'i', [$claim_id]);
    if ($left === 0) dal_exec($conn, "UPDATE claims SET doc_damage_photos = 0 WHERE claim_id = ?", 'i', [$claim_id]);
    return [$file === null ? null : (string)$file, $left];
}

/** Every file name a claim holds (requirement files, older file columns, damage photos), for deleting it. */
function claim_files(mysqli $conn, int $claim_id): array
{
    $cols  = array_merge(array_map(fn($f) => $f . '_file', CLAIM_DOC_FIELDS), CLAIM_OLD_FILE_COLUMNS);
    $row   = dal_one($conn, "SELECT " . implode(', ', $cols) . " FROM claims WHERE claim_id = ?", 'i', [$claim_id]) ?? [];
    $files = array_values(array_filter($row));
    foreach (dal_all($conn, "SELECT filename FROM claim_damage_photos WHERE claim_id = ?", 'i', [$claim_id]) as $p) $files[] = $p['filename'];
    return $files;
}

/** Deletes a claim (its damage photo rows go with it, ON DELETE CASCADE). */
function claim_delete(mysqli $conn, int $claim_id): void
{
    dal_exec($conn, "DELETE FROM claims WHERE claim_id = ?", 'i', [$claim_id]);
}

/** Files a new claim (status 'compiling') and returns its id. */
function claim_create(mysqli $conn, int $policy_id, int $client_id, string $claim_type, string $incident_date, string $description, int $created_by): int
{
    dal_exec($conn, "INSERT INTO claims (policy_id, client_id, claim_type, incident_date, description, status, created_by)
                     VALUES (?, ?, ?, ?, ?, 'compiling', ?)", 'iisssi', [$policy_id, $client_id, $claim_type, $incident_date, $description, $created_by]);
    return (int)$conn->insert_id;
}

/** Moves a claim to a new status with its denial reason (null unless denied) and notes. */
function claim_set_status(mysqli $conn, int $claim_id, string $status, ?string $denial_reason, string $notes): void
{
    dal_exec($conn, "UPDATE claims SET status = ?, denial_reason = ?, notes = ? WHERE claim_id = ?", 'sssi', [$status, $denial_reason, $notes, $claim_id]);
}

/** A client's policies that have not expired, soonest first, with the vehicle (File New Claim's policy list). */
function claim_client_open_policies(mysqli $conn, int $client_id): array
{
    return dal_all($conn, "
        SELECT ip.policy_id, ip.policy_number, ip.coverage_type, ip.policy_end, v.plate_number, v.make, v.model
        FROM insurance_policies ip
        LEFT JOIN vehicles v ON ip.vehicle_id = v.vehicle_id
        WHERE ip.client_id = ? AND ip.policy_end >= CURDATE()
        ORDER BY ip.policy_end ASC", 'i', [$client_id]);
}

/**
 * One page of the Claims list. $search matches client, plate, policy no.,
 * make or model; $status / $type a code or 'all'; $sort 'newest' / 'oldest'.
 * Returns [rows, pager].
 */
function claims_list_page(mysqli $conn, string $search, string $status, string $type, string $sort): array
{
    $where = ['1=1']; $types = ''; $params = [];
    if ($search !== '') {
        $like    = "%$search%";
        $where[] = '(c.full_name LIKE ? OR v.plate_number LIKE ? OR ip.policy_number LIKE ? OR v.make LIKE ? OR v.model LIKE ?)';
        array_push($params, $like, $like, $like, $like, $like);
        $types  .= 'sssss';
    }
    if ($status !== 'all') { $where[] = 'cl.status = ?';     $params[] = $status; $types .= 's'; }
    if ($type !== 'all')   { $where[] = 'cl.claim_type = ?'; $params[] = $type;   $types .= 's'; }
    $order = $sort === 'oldest' ? 'cl.created_at ASC' : 'cl.created_at DESC';
    [$result, $pg] = paginate_query($conn, "
        SELECT cl.claim_id, cl.claim_type, cl.status, cl.incident_date, cl.created_at,
               cl." . implode(', cl.', CLAIM_DOC_FIELDS) . ",
               c.full_name, c.client_id, v.plate_number, v.make, v.model, ip.policy_number, ip.coverage_type,
               CASE WHEN u.is_hidden = 1 THEN 'Developer' ELSE u.full_name END AS added_by_name,
               CASE WHEN u.is_hidden = 1 THEN NULL ELSE u.profile_photo END AS added_by_photo
        FROM claims cl
        INNER JOIN clients c             ON cl.client_id = c.client_id
        INNER JOIN insurance_policies ip ON cl.policy_id = ip.policy_id
        LEFT  JOIN vehicles v            ON ip.vehicle_id = v.vehicle_id
        LEFT  JOIN users u               ON cl.created_by = u.user_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY $order", $types, $params);
    return [$result->fetch_all(MYSQLI_ASSOC), $pg];
}
