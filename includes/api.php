<?php
/**
 * includes/api.php — one JSON reply format for every endpoint that answers with JSON.
 *
 *   Success:  {"ok": true,  "message": "...", ...data}      HTTP 200
 *   Failure:  {"ok": false, "error": "...", "message": "..."} with a real HTTP status:
 *             400 bad input, 401 not signed in, 403 not allowed / wrong PIN, 404 not found,
 *             429 too many attempts, 500 server or upstream failure
 *
 * "error" and "message" carry the same text on failure while the older front-end code is migrated.
 * Usage:
 *   api_success(['vehicles' => $rows]);
 *   api_error('Client not found.', 404);
 */

function api_send(array $body, int $status = 200): void
{
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
