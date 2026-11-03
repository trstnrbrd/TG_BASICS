<?php
/**
 * Adapter for OCR.space: the only code that knows its request and reply format. Pages get plain text back.
 *
 *   $r = ocr_extract_text($conn, $tmp_path, $mime, $file_name);
 *   // ['ok' => true, 'text' => '...']  or  ['ok' => false, 'error' => 'message', 'status' => 400|500|503]
 *
 * Calls go through the circuit breaker (includes/circuit_breaker.php): after 3 failures of the service in a row
 * the scan answers at once with "temporarily unavailable" instead of waiting 30 s on a dead service. A picture
 * with no readable text is the picture's fault, not the service's, and does not count as a failure.
 *
 * To switch OCR providers, change this file only.
 */

require_once __DIR__ . '/circuit_breaker.php';

const OCR_TIMEOUT = 30;

function ocr_extract_text(?mysqli $conn, string $path, string $mime, string $name): array
{
    $key_file = __DIR__ . '/../config/ocr.php';
    if (is_file($key_file)) require_once $key_file;
    if (!defined('OCR_SPACE_API_KEY') || OCR_SPACE_API_KEY === '') {
        return ['ok' => false, 'error' => 'OCR is not set up on this server.', 'status' => 500];
    }

    $r = breaker_call($conn, 'ocr', fn() => ocr_space_request($path, $mime, $name));
    if ($r === null) {
        return ['ok' => false, 'status' => 503,
                'error' => 'Auto-fill (OCR) is temporarily unavailable. Please type the details in; it will be tried again in a few minutes.'];
    }
    if (!$r['ok']) return ['ok' => false, 'error' => $r['error'], 'status' => 500];

    $data = $r['data'];
    if (empty($data['ParsedResults'])) {
        return ['ok' => false, 'error' => $data['ErrorMessage'][0] ?? 'No text detected in the image.', 'status' => 400];
    }
    $text = '';
    foreach ($data['ParsedResults'] as $page) $text .= ($page['ParsedText'] ?? '') . "\n";   // all pages
    $text = trim($text);
    if ($text === '') return ['ok' => false, 'error' => 'No text detected in the image.', 'status' => 400];
    return ['ok' => true, 'text' => $text];
}

/**
 * One request to OCR.space. ok = the service answered (HTTP 200 with JSON); ok false = the service failed.
 * TG_OCR_URL overrides the address for tests only.
 */
function ocr_space_request(string $path, string $mime, string $name): array
{
    $url = getenv('TG_OCR_URL') ?: 'https://api.ocr.space/parse/image';
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => [
            'apikey'                => OCR_SPACE_API_KEY,
            'language'              => 'eng',
            'OCREngine'             => '2',
            'isTable'               => 'true',
            'isCreateSearchablePdf' => 'false',
            'file'                  => new CURLFile($path, $mime, $name),
        ],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => OCR_TIMEOUT,
    ]);
    $response = curl_exec($ch);
    $code     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = is_string($response) ? json_decode($response, true) : null;
    if ($code !== 200 || !is_array($data)) {
        return ['ok' => false, 'error' => 'OCR request failed (HTTP ' . $code . ').'];
    }
    return ['ok' => true, 'data' => $data];
}
