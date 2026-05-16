<?php
require_once '../../config/session.php';
require_once '../../config/ocr.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['image'])) {
    echo json_encode(['error' => 'No image uploaded.']);
    exit;
}

$file = $_FILES['image'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['error' => 'Upload error.']);
    exit;
}

$allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];
if (!in_array($file['type'], $allowed)) {
    echo json_encode(['error' => 'Invalid file type.']);
    exit;
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
        'file'                 => new CURLFile($file['tmp_name'], $file['type'], $file['name']),
    ],
    CURLOPT_TIMEOUT        => 30,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$response || $httpCode !== 200) {
    echo json_encode(['error' => 'OCR request failed (HTTP ' . $httpCode . ').']);
    exit;
}

$data = json_decode($response, true);

if (empty($data['ParsedResults'])) {
    $msg = $data['ErrorMessage'][0] ?? 'No text detected in the image.';
    echo json_encode(['error' => $msg]);
    exit;
}

// Concatenate all pages
$text = '';
foreach ($data['ParsedResults'] as $page) {
    $text .= ($page['ParsedText'] ?? '') . "\n";
}
$text = trim($text);

if (!$text) {
    echo json_encode(['error' => 'No text detected in the image.']);
    exit;
}

echo json_encode(['text' => $text]);
