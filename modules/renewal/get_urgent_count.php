<?php
require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . '/../../includes/data_access.php';
require_once '../../config/db.php';
require_once '../../config/settings.php';
require_once '../../config/access.php';
require_once __DIR__ . '/../../includes/api.php';

// Not signed in: the sidebar badge simply shows nothing
if (!isset($_SESSION['user_id'])) api_success(['count' => 0]);

// Sidebar badge = the urgent policies this user is responsible for, with the same window setting as Renewal
// Tracking: the Owner counts all of them; an admin only their own clients' (the page's "My Clients" view —
// the other agents they can browse there are not their follow-ups); anyone else nothing (policies_due_count()).
$urg_days = (int)getSetting($conn, 'renewal_urgent_days', '7');

api_success(['count' => policies_due_count($conn, $urg_days)]);
