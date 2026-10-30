<?php
// Backup for live updates when Pusher can't be reached (or isn't set up): the newest activity id. Every save
// writes an audit_logs row, so a bigger number than last time means something changed. assets/js/shared/realtime.js
define('TG_PASSIVE_REQUEST', true);   // not the user's activity: never keeps the session from timing out (config/session.php)
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/data_access.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/api.php';

if (!isset($_SESSION['user_id'])) api_error('Not signed in.', 401);
session_write_close();   // read-only: don't hold the session lock while other requests from this tab run
api_success(['latest' => audit_log_latest_id($conn)]);
