<?php
/**
 * One repair progress photo for the client's public QR page (client.php). No sign-in, so every request is checked:
 *   1. the QR token is a real client's,
 *   2. the photo belongs to a repair job of THAT client,
 *   3. the shop ticked "Show to client" on it,
 *   4. the job is still open (repair_job_is_open()).
 * Anything else is a plain 404, the same for every reason, so nothing can be learned by guessing ids.
 * uploads/repair_jobs stays closed to direct links (root .htaccess); this is the only public way in.
 */
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/access.php';

header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');

$not_found = function (): never {
    http_response_code(404);
    header('Cache-Control: no-store');
    exit;
};

$token = is_string($_GET['token'] ?? null) ? $_GET['token'] : '';
$id    = is_string($_GET['id'] ?? null) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
if (strlen($token) !== 64 || !ctype_xdigit($token) || $id <= 0) $not_found();

$st = $conn->prepare("
    SELECT i.file_name, i.job_id, j.status
    FROM repair_job_images i
    INNER JOIN repair_jobs j ON j.job_id = i.job_id
    INNER JOIN clients c     ON c.client_id = j.client_id
    WHERE i.image_id = ? AND i.show_client = 1 AND c.public_token = ? AND c.deleted_at IS NULL
");
$st->bind_param('is', $id, $token);
$st->execute();
$ph = $st->get_result()->fetch_assoc();
if (!$ph || !repair_job_is_open($ph['status'])) $not_found();

$path = __DIR__ . '/../../uploads/repair_jobs/' . (int)$ph['job_id'] . '/' . basename($ph['file_name']);
if (!is_file($path)) $not_found();
$mime = mime_content_type($path);
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) $not_found();

// private: the photo can disappear the moment the job closes or the shop hides it, so no shared caching
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline');
header('Cache-Control: private, max-age=300');
readfile($path);
