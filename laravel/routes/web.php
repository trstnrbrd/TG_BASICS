<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', function () {
    return response('TG BASICS Laravel refactor');
});

Route::get('/_check', function () {
    $sessionCount = session('check_count', 0) + 1;
    session(['check_count' => $sessionCount]);

    Storage::put('phase-0-check.txt', 'storage-ok');

    return response()->json([
        'php_version' => PHP_VERSION,
        'database_now' => DB::selectOne('SELECT NOW() AS now_value')->now_value,
        'database_timezone' => DB::selectOne('SELECT @@session.time_zone AS timezone')->timezone,
        'users_count' => DB::table('users')->count(),
        'session_count' => $sessionCount,
        'storage_read' => Storage::get('phase-0-check.txt'),
        'vendor_files' => count(File::allFiles(base_path('vendor'))),
    ]);
});
