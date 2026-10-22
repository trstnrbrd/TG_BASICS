<?php
/**
 * Serves an uploaded file, but only to a signed-in user with the right role (config/uploads.php).
 *   file.php?t=claims&f=claim_12_doc_or_1729590000.jpg
 * Not allowed or not found -> same plain 404 either way.
 */
require_once __DIR__ . '/../../config/session.php';
require_once '../../config/db.php';
require_once '../../config/access.php';
require_once '../../config/uploads.php';

function file_not_found(): never
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('File not found.');
}

$folder = is_string($_GET['t'] ?? null) ? $_GET['t'] : '';
$name   = is_string($_GET['f'] ?? null) ? $_GET['f'] : '';
$id     = (int)($_GET['id'] ?? 0);
$rule   = UPLOAD_FOLDERS[$folder] ?? null;
if ($rule === null) file_not_found();

require_role($rule['roles']);

// file name only, no folders or ".."
if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,150}$/', $name) || str_contains($name, '..')) file_not_found();
if ($rule['per_record'] && $id <= 0) file_not_found();

// client docs: mechanics only see walk-in clients
if ($folder === 'client_docs' && !client_in_scope($conn, $id)) file_not_found();

$dir  = realpath(__DIR__ . '/../../uploads/' . $folder . ($rule['per_record'] ? '/' . $id : ''));
$path = $dir !== false ? realpath($dir . '/' . $name) : false;
if ($path === false || !is_file($path) || dirname($path) !== $dir) file_not_found();

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = finfo_file($finfo, $path);
finfo_close($finfo);
if (!in_array($mime, UPLOAD_SERVE_TYPES, true)) file_not_found();

session_write_close();
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . $name . '"');
header('Cache-Control: private, no-store');
readfile($path);
