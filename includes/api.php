<?php
/**
 * Same JSON reply format for every AJAX endpoint.
 *   Success: {"ok": true, "message": "...", ...data}
 *   Failure: {"ok": false, "error": "...", "message": "..."} + HTTP status (400, 401, 403, 404, 429, 500)
 */

function api_send(array $body, int $status = 200): void
{
    $GLOBALS['tg_api_ok'] = !empty($body['ok']);   // read by realtime_watch_request() (includes/realtime.php)
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

function api_success(array $data = [], string $message = ''): void
{
    api_send(['ok' => true, 'message' => $message] + $data);
}

function api_error(string $message, int $status = 400, array $extra = []): void
{
    api_send(['ok' => false, 'error' => $message, 'message' => $message] + $extra, $status);
}
