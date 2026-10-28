<?php
// Copy this file to config/pusher.php and fill in the values from pusher.com -> your app -> "App Keys".
// config/pusher.php is gitignored and not deployed: edit it by hand on each server (local, demo, production),
// the same way as config/ocr.php. Leave the values empty and the system simply runs without live updates.
define('PUSHER_APP_ID',  '');
define('PUSHER_KEY',     '');
define('PUSHER_SECRET',  '');   // never share this one: not in chat, not in git
define('PUSHER_CLUSTER', 'ap1');
