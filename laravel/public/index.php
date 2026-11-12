<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// The root .htaccess rewrites every request into public/, so the app is reached at <folder>/ and not
// <folder>/public/. Tell Laravel its base path is the folder, or every route would be a 404.
$script = $_SERVER['SCRIPT_NAME'] ?? '';
$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
if (str_ends_with($script, '/public/index.php') && !str_starts_with($uriPath, dirname($script))) {
    $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = substr($script, 0, -strlen('/public/index.php')) . '/index.php';
}

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
