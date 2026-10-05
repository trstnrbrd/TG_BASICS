<?php
require_once '../../config/session.php';
require_once '../../config/ocr.php';
require_once __DIR__ . '/../../includes/api.php';


// Every call spends the shop's OCR.space quota, so it needs a logged-in staff session.
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    api_error('Unauthorized.', 401);
}

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

$ch = curl_init('https://api.ocr.space/parse/image');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => [
        'apikey'               => OCR_SPACE_API_KEY,
        'language'             => 'eng',
        'OCREngine'            => '2',
        'isTable'              => 'true',
        'isCreateSearchablePdf'=> 'false',
        'file'                 => new CURLFile($file['tmp_name'], $mime, $file['name']),
    ],
    CURLOPT_TIMEOUT        => 30,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$response || $httpCode !== 200) {
    api_error('OCR request failed (HTTP ' . $httpCode . ').', 500);
}

$data = json_decode($response, true);

if (empty($data['ParsedResults'])) {
    $msg = $data['ErrorMessage'][0] ?? 'No text detected in the image.';
    api_error($msg, 400);
}

// Concatenate all pages
$text = '';
foreach ($data['ParsedResults'] as $page) {
    $text .= ($page['ParsedText'] ?? '') . "\n";
}
$text = trim($text);

if (!$text) {
    api_error('No text detected in the image.', 400);
}

api_success(['text' => $text]);
