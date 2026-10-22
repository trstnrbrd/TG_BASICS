<?php
/**
 * Links to uploaded client files (claims, documents, receipts, repair photos).
 * These folders are blocked from direct access — files are only served through
 * modules/files/file.php, which checks that the user is signed in first.
 * Staff profile photos (uploads/avatars/) are the only public ones.
 *
 *   upload_url('claims', $filename)
 *   upload_url('repair_jobs', $filename, $job_id)   // 'repair_jobs' keeps one folder per job
 */

// folder => roles allowed to see its files, and whether files are in a per-record subfolder
const UPLOAD_FOLDERS = [
    'claims'      => ['roles' => ['admin', 'super_admin'],             'per_record' => false],
    'receipts'    => ['roles' => ['admin', 'super_admin'],             'per_record' => false],
    'client_docs' => ['roles' => ['admin', 'super_admin', 'mechanic'], 'per_record' => true],
    'repair_jobs' => ['roles' => ['admin', 'super_admin', 'mechanic'], 'per_record' => true],
];

// Only these file types are ever served
const UPLOAD_SERVE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];

/** Link to an uploaded file, served through file.php. */
function upload_url(string $folder, string $name, int $record_id = 0): string
{
    $q = ['t' => $folder];
    if ($record_id > 0) $q['id'] = $record_id;
    $q['f'] = $name;
    return app_base_path() . 'modules/files/file.php?' . http_build_query($q);
}
