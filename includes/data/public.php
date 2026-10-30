<?php
/* =========================================================================
 * The client's public QR page (modules/public/): no sign-in, so every read
 * is tied to the secret token, and only what the page is meant to show
 * ========================================================================= */

/** The active client a QR token belongs to (all columns), or null. */
function public_client_by_token(mysqli $conn, string $token): ?array
{
    return dal_one($conn, "SELECT * FROM clients WHERE public_token = ? AND deleted_at IS NULL", 's', [$token]);
}

/** The client's current (not renewed) policies, soonest to expire first, with days left and vehicle. */
function public_client_policies(mysqli $conn, int $client_id): array
{
    return dal_all($conn, "
        SELECT p.policy_number, p.coverage_type, p.policy_start, p.policy_end, p.payment_status,
               DATEDIFF(p.policy_end, CURDATE()) AS days_left, v.plate_number, v.make, v.model
        FROM insurance_policies p
        INNER JOIN vehicles v ON p.vehicle_id = v.vehicle_id
        WHERE p.client_id = ? AND p.is_renewed = 0
        ORDER BY p.policy_end ASC", 'i', [$client_id]);
}

/** The client's newest $limit repair jobs with the vehicle. */
function public_client_repair_jobs(mysqli $conn, int $client_id, int $limit = 10): array
{
    return dal_all($conn, "
        SELECT j.job_id, j.job_number, j.service_type, j.status, j.repair_date, j.release_date, v.plate_number, v.make, v.model
        FROM repair_jobs j
        INNER JOIN vehicles v ON j.vehicle_id = v.vehicle_id
        WHERE j.client_id = ?
        ORDER BY j.created_at DESC
        LIMIT ?", 'ii', [$client_id, $limit]);
}

/** The progress photos ticked "Show to client" on the client's jobs, oldest first, with the job's status. */
function public_client_shown_photos(mysqli $conn, int $client_id): array
{
    return dal_all($conn, "
        SELECT i.image_id, i.job_id, i.caption, i.uploaded_at, j.status
        FROM repair_job_images i
        INNER JOIN repair_jobs j ON j.job_id = i.job_id
        WHERE j.client_id = ? AND i.show_client = 1
        ORDER BY i.uploaded_at ASC, i.image_id ASC", 'i', [$client_id]);
}

/**
 * One photo for photo.php, only if it is ticked "Show to client" and its
 * job belongs to the (active) client of this token: [file_name, job_id,
 * status], or null.
 */
function public_photo(mysqli $conn, int $image_id, string $token): ?array
{
    return dal_one($conn, "
        SELECT i.file_name, i.job_id, j.status
        FROM repair_job_images i
        INNER JOIN repair_jobs j ON j.job_id = i.job_id
        INNER JOIN clients c     ON c.client_id = j.client_id
        WHERE i.image_id = ? AND i.show_client = 1 AND c.public_token = ? AND c.deleted_at IS NULL", 'is', [$image_id, $token]);
}
