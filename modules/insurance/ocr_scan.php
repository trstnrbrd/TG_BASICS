<?php
require_once '../../config/session.php';
require_once __DIR__ . '/../../includes/api.php';
require_once __DIR__ . '/../../includes/ocr.php';


// Every call spends the shop's OCR.space quota, so it needs a logged-in staff session.
require_role_json(['admin', 'super_admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['image'])) {
    api_error('No image uploaded.', 400);
}

$file = $_FILES['image'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    api_error('Upload error.', 400);
}

// Sniff the real content type — $file['type'] is whatever the client claims.
$allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];
$mime    = finfo_file(finfo_open(FILEINFO_MIME_TYPE), $file['tmp_name']);
if (!in_array($mime, $allowed, true)) {
    api_error('Invalid file type.', 400);
}

$r = ocr_extract_text($conn, $file['tmp_name'], $mime, $file['name']);
if (!$r['ok']) {
    api_error($r['error'], $r['status']);
}
api_success(['text' => $r['text']]);
