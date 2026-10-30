<?php
/* =========================================================================
 * Developer tools (tools/, never deployed): which uploaded files the
 * database still points to, so tools/cleanup_uploads.php only ever lists
 * files nothing refers to
 * ========================================================================= */

/** Every file name a claim refers to: each requirement's file column, the older file columns, and damage photos. */
function upload_files_claims(mysqli $conn): array
{
    $cols  = array_merge(array_map(fn($f) => $f . '_file', CLAIM_DOC_FIELDS), CLAIM_OLD_FILE_COLUMNS);
    $files = [];
    foreach (dal_all($conn, "SELECT " . implode(', ', $cols) . " FROM claims") as $row) {
        foreach ($row as $f) if ($f !== null && $f !== '') $files[] = $f;
    }
    foreach (dal_all($conn, "SELECT filename FROM claim_damage_photos WHERE filename IS NOT NULL AND filename != ''") as $r) $files[] = $r['filename'];
    return $files;
}

/** "client_id/file_name" of every client document. */
function upload_files_client_docs(mysqli $conn): array
{
    return array_map(fn($r) => $r['client_id'] . '/' . $r['file_name'], dal_all($conn, "SELECT client_id, file_name FROM client_documents"));
}

/** "job_id/file_name" of every repair photo and inspection signature (both live in uploads/repair_jobs/<job_id>/). */
function upload_files_repair(mysqli $conn): array
{
    return array_map(fn($r) => $r['job_id'] . '/' . $r['file_name'], dal_all($conn, "
        SELECT job_id, file_name FROM repair_job_images
        UNION ALL
        SELECT job_id, file_name FROM repair_job_signatures"));
}

/** Every installment receipt image. */
function upload_files_receipts(mysqli $conn): array
{
    return array_column(dal_all($conn, "SELECT receipt_file FROM policy_payments WHERE receipt_file IS NOT NULL AND receipt_file != ''"), 'receipt_file');
}

/** Every profile photo. */
function upload_files_avatars(mysqli $conn): array
{
    return array_column(dal_all($conn, "SELECT profile_photo FROM users WHERE profile_photo IS NOT NULL AND profile_photo != ''"), 'profile_photo');
}
