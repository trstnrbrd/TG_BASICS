<?php
// The two shop logos (TG Customworks, Basic Car Insurance) are not hardcoded (panel revision): every page asks
// brand_logo() for them, and the paths live in system_settings (phpMyAdmin):
//   logo_tg_path, logo_basic_path             -> the logo on every page (and the favicon, for the TG logo)
//   logo_tg_email_path, logo_basic_email_path -> the small copy embedded in emails
// To change one, upload the image to assets/img/ (InfinityFree File Manager) and set its path, e.g.
// logo_tg_path = assets/img/tg_logo_2027.png. The home-screen (PWA) icons in assets/img/pwa stay fixed.
require_once __DIR__ . '/../config/settings.php';

/** A logo path from settings is used only if it is an image under assets/img/ that really exists. */
function brand_logo_path_ok(string $path): bool {
    return preg_match('#^assets/img/[A-Za-z0-9_\-/]+\.(png|jpe?g|webp)$#i', $path) === 1
        && !str_contains($path, '..')
        && is_file(__DIR__ . '/../' . $path);
}

/**
 * The logo's file path from the project root ('tg' or 'basic'), or '' when its setting is missing or does not
 * point at an image under assets/img/.
 */
function brand_logo_file(string $which, bool $for_email = false): string {
    global $conn;
    if (!isset($conn) || !($conn instanceof mysqli)) return '';
    try {
        $path = trim(getSetting($conn, 'logo_' . $which . ($for_email ? '_email' : '') . '_path', ''));
    } catch (Throwable $e) {
        return '';
    }
    return $path !== '' && brand_logo_path_ok($path) ? $path : '';
}

/**
 * The logo's URL for src/href, e.g. brand_logo('tg', '../') -> "../assets/img/tg_logo.png?v=1730000000".
 * $prefix is the page's path back to the project root. ?v= changes when the file does, so a replaced logo shows
 * at once even though browsers keep images for 30 days (assets/.htaccess). The path only ever holds
 * [A-Za-z0-9_-/.], so it is safe inside an HTML attribute as is. '' when the logo is not set up.
 */
function brand_logo(string $which, string $prefix = ''): string {
    $file = brand_logo_file($which);
    return $file === '' ? '' : $prefix . $file . '?v=' . (int)@filemtime(__DIR__ . '/../' . $file);
}
