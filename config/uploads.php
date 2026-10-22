<?php
/**
 * config/uploads.php — links to uploaded files that hold client data.
 *
 * Claim documents, client documents, payment receipts and repair-job photos/signatures (driver's licenses,
 * OR/CR, affidavits) are closed to direct requests (.htaccess at the app root and in uploads/), so knowing or
 * guessing a file's URL is not enough to open it. The browser gets them through modules/files/file.php, which
 * checks the signed-in user first. Staff profile photos (uploads/avatars/) stay a plain public link.
 *
 *   upload_url('claims', $filename)                  <img src="<?= htmlspecialchars(upload_url(...)) ?>">
 *   upload_url('repair_jobs', $filename, $job_id)    folders kept per record take the record id
 */

// folder => who may open its files, and whether files sit in a per-record subfolder (uploads/<folder>/<id>/)
const UPLOAD_FOLDERS = [
    'claims'      => ['roles' => ['admin', 'super_admin'],             'per_record' => false],
    'receipts'    => ['roles' => ['admin', 'super_admin'],             'per_record' => false],
    'client_docs' => ['roles' => ['admin', 'super_admin', 'mechanic'], 'per_record' => true],
    'repair_jobs' => ['roles' => ['admin', 'super_admin', 'mechanic'], 'per_record' => true],
];

// Only these are ever served (the upload handlers accept nothing else)
const UPLOAD_SERVE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];

/** Browser link to an uploaded file in one of UPLOAD_FOLDERS. The file name stays last, so "...pdf" checks still work. */
function upload_url(string $folder, string $name, int $record_id = 0): string
{
    $q = ['t' => $folder];
    if ($record_id > 0) $q['id'] = $record_id;
    $q['f'] = $name;
    return app_base_path() . 'modules/files/file.php?' . http_build_query($q);
}
