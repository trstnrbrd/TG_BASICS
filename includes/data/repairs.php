<?php
/* =========================================================================
 * Repair jobs: the job, its inspection checklist, photos and sign-off
 * ========================================================================= */
require_once __DIR__ . '/../pagination.php';
require_once __DIR__ . '/../../config/access.php';

/** One repair job with its client and vehicle (for its page), or null. */
function repair_job_find(mysqli $conn, int $job_id): ?array
{
    return dal_one($conn, "
        SELECT j.*,
               c.client_id, c.full_name, c.contact_number, c.email, c.address,
               v.vehicle_id, v.plate_number, v.make, v.model, v.year_model, v.color
        FROM repair_jobs j
        INNER JOIN clients  c ON j.client_id  = c.client_id
        INNER JOIN vehicles v ON j.vehicle_id = v.vehicle_id
        WHERE j.job_id = ?", 'i', [$job_id]);
}

/** The damaged areas of a job's checklist (condition not 'none'), to pre-fill a quotation's lines. */
function repair_checklist_damaged(mysqli $conn, int $job_id): array
{
    return dal_all($conn, "SELECT area_key, condition_value, notes FROM repair_checklist WHERE job_id = ? AND condition_value != 'none'", 'i', [$job_id]);
}

/** The job number of a repair job, or null. */
function repair_job_number(mysqli $conn, int $job_id): ?string
{
    $n = dal_value($conn, "SELECT job_number FROM repair_jobs WHERE job_id = ?", 'i', [$job_id]);
    return $n === null ? null : (string)$n;
}

/**
 * Opens a repair job with the given job number (called inside
 * insert_with_sequential_number()) and returns its id. $j: client_id,
 * vehicle_id, repair_date, release_date (null = none), service_type,
 * additional_damages.
 */
function repair_job_create(mysqli $conn, string $job_number, array $j, int $created_by): int
{
    dal_exec($conn, "INSERT INTO repair_jobs (client_id, vehicle_id, job_number, repair_date, release_date, service_type, additional_damages, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)", 'iisssssi',
             [$j['client_id'], $j['vehicle_id'], $job_number, $j['repair_date'], $j['release_date'], $j['service_type'], $j['additional_damages'], $created_by]);
    return (int)$conn->insert_id;
}

/** Deletes a repair job (its checklist, photos, signatures and quotation rows go with it, ON DELETE CASCADE). */
function repair_job_delete(mysqli $conn, int $job_id): void
{
    dal_exec($conn, "DELETE FROM repair_jobs WHERE job_id = ?", 'i', [$job_id]);
}

/**
 * Moves a job from $from_status to $to_status. Returns false (nothing
 * changed) if the status is no longer $from_status, i.e. someone else
 * changed it since the page was loaded.
 */
function repair_job_set_status(mysqli $conn, int $job_id, string $from_status, string $to_status): bool
{
    return dal_exec($conn, "UPDATE repair_jobs SET status = ? WHERE job_id = ? AND status = ?", 'sis', [$to_status, $job_id, $from_status]) === 1;
}

/** Saves the job's additional damages note. */
function repair_job_set_notes(mysqli $conn, int $job_id, string $notes): void
{
    dal_exec($conn, "UPDATE repair_jobs SET additional_damages = ? WHERE job_id = ?", 'si', [$notes, $job_id]);
}

/** Sets the job's release date (null clears it). */
function repair_job_set_release_date(mysqli $conn, int $job_id, ?string $release_date): void
{
    dal_exec($conn, "UPDATE repair_jobs SET release_date = ? WHERE job_id = ?", 'si', [$release_date, $job_id]);
}

/** Saves one area of the inspection checklist. */
function repair_checklist_add(mysqli $conn, int $job_id, string $area_key, string $condition, string $notes): void
{
    dal_exec($conn, "INSERT INTO repair_checklist (job_id, area_key, condition_value, notes) VALUES (?, ?, ?, ?)", 'isss', [$job_id, $area_key, $condition, $notes]);
}

/** The inspection checklist of a job: area_key => [area_key, condition_value, notes]. */
function repair_checklist(mysqli $conn, int $job_id): array
{
    $out = [];
    foreach (dal_all($conn, "SELECT area_key, condition_value, notes FROM repair_checklist WHERE job_id = ?", 'i', [$job_id]) as $row) {
        $out[$row['area_key']] = $row;
    }
    return $out;
}

/** The job's photos, oldest first. */
function repair_job_images(mysqli $conn, int $job_id): array
{
    return dal_all($conn, "SELECT image_id, file_name, uploaded_by, show_client, caption, uploaded_at FROM repair_job_images
                           WHERE job_id = ? ORDER BY uploaded_at ASC, image_id ASC", 'i', [$job_id]);
}

/** One photo of this job (null if it is not this job's). */
function repair_job_image(mysqli $conn, int $image_id, int $job_id): ?array
{
    return dal_one($conn, "SELECT image_id, file_name, uploaded_by, show_client FROM repair_job_images WHERE image_id = ? AND job_id = ?",
                   'ii', [$image_id, $job_id]);
}

/** Adds an uploaded photo; $show_client puts it on the client's QR page. */
function repair_job_image_add(mysqli $conn, int $job_id, string $file_name, int $uploaded_by, bool $show_client, ?string $caption): void
{
    dal_exec($conn, "INSERT INTO repair_job_images (job_id, file_name, uploaded_by, show_client, caption) VALUES (?, ?, ?, ?, ?)",
             'isiis', [$job_id, $file_name, $uploaded_by, $show_client ? 1 : 0, $caption]);
}

/** Shows or hides a photo on the client's QR page. */
function repair_job_image_set_visible(mysqli $conn, int $image_id, bool $show_client): void
{
    dal_exec($conn, "UPDATE repair_job_images SET show_client = ? WHERE image_id = ?", 'ii', [$show_client ? 1 : 0, $image_id]);
}

/** Deletes a photo row (the caller removes the file after the commit). */
function repair_job_image_delete(mysqli $conn, int $image_id): void
{
    dal_exec($conn, "DELETE FROM repair_job_images WHERE image_id = ?", 'i', [$image_id]);
}

/** The inspection sign-off: signer_role ('client' / 'staff') => [signer_role, signer_name, file_name, signed_at]. */
function repair_job_signatures(mysqli $conn, int $job_id): array
{
    $out = [];
    foreach (dal_all($conn, "SELECT signer_role, signer_name, file_name, signed_at FROM repair_job_signatures WHERE job_id = ?", 'i', [$job_id]) as $row) {
        $out[$row['signer_role']] = $row;
    }
    return $out;
}

/**
 * Records one side's signature. A side can sign only once: the UNIQUE
 * (job_id, signer_role) key makes a second one throw error 1062.
 */
function repair_job_signature_add(mysqli $conn, int $job_id, string $signer_role, string $signer_name, string $file_name, int $captured_by): void
{
    dal_exec($conn, "INSERT INTO repair_job_signatures (job_id, signer_role, signer_name, file_name, captured_by) VALUES (?, ?, ?, ?, ?)",
             'isssi', [$job_id, $signer_role, $signer_name, $file_name, $captured_by]);
}

/** The mechanic dashboard counts: in progress, completed today, pending. */
function repair_dashboard_counts(mysqli $conn): array
{
    return [
        'active'          => (int)dal_value($conn, "SELECT COUNT(*) FROM repair_jobs WHERE status = 'in_progress'"),
        'completed_today' => (int)dal_value($conn, "SELECT COUNT(*) FROM repair_jobs WHERE status = 'completed' AND updated_at >= CURDATE() AND updated_at < CURDATE() + INTERVAL 1 DAY"),
        'pending'         => (int)dal_value($conn, "SELECT COUNT(*) FROM repair_jobs WHERE status = 'pending'"),
    ];
}

/** The newest $limit open jobs (repair_open_statuses()), with client and vehicle. */
function repair_jobs_open_recent(mysqli $conn, int $limit): array
{
    $open = repair_open_statuses();
    if (!$open) return [];
    return dal_all($conn, "
        SELECT j.job_id, j.job_number, j.service_type, j.status, j.repair_date, j.release_date,
               c.full_name, v.plate_number, v.make, v.model
        FROM repair_jobs j
        INNER JOIN clients  c ON j.client_id  = c.client_id
        INNER JOIN vehicles v ON j.vehicle_id = v.vehicle_id
        WHERE j.status IN (" . dal_in($open) . ")
        ORDER BY j.created_at DESC
        LIMIT ?", str_repeat('s', count($open)) . 'i', array_merge(array_values($open), [$limit]));
}

/**
 * One page of the Repair Jobs list. $search matches client, plate or job no.;
 * $status a status code or 'all'; $sort 'newest' / 'oldest'. Returns [rows, pager].
 */
function repair_jobs_list_page(mysqli $conn, string $search, string $status, string $sort): array
{
    $where = ['1=1']; $types = ''; $params = [];
    if ($search !== '') {
        $like    = "%$search%";
        $where[] = '(c.full_name LIKE ? OR v.plate_number LIKE ? OR j.job_number LIKE ?)';
        array_push($params, $like, $like, $like);
        $types  .= 'sss';
    }
    if ($status !== 'all') { $where[] = 'j.status = ?'; $params[] = $status; $types .= 's'; }
    $order = $sort === 'oldest' ? 'j.created_at ASC' : 'j.created_at DESC';
    [$result, $pg] = paginate_query($conn, "
        SELECT j.job_id, j.job_number, j.service_type, j.status, j.repair_date, j.release_date,
               c.client_id, c.full_name, c.contact_number,
               v.vehicle_id, v.plate_number, v.make, v.model, v.year_model
        FROM repair_jobs j
        INNER JOIN clients  c ON j.client_id  = c.client_id
        INNER JOIN vehicles v ON j.vehicle_id = v.vehicle_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY $order", $types, $params);
    return [$result->fetch_all(MYSQLI_ASSOC), $pg];
}
