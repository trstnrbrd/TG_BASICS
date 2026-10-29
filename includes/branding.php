<?php
// The two shop logos (TG Customworks, Basic Car Insurance) are not hardcoded (panel revision): every page asks
// brand_logo() for them. To change one, upload the image to assets/img/ (InfinityFree File Manager) and set its
// path in system_settings (phpMyAdmin), e.g. logo_tg_path = assets/img/tg_logo_2027.png. Empty or missing = the
// original logo. The favicon follows the TG logo; the home-screen (PWA) icons in assets/img/pwa stay fixed.
require_once __DIR__ . '/../config/settings.php';

// [web logo, small copy embedded in emails]
const BRAND_LOGO_DEFAULTS = [
    'tg'    => ['assets/img/tg_logo.png',      'assets/img/tg_logo_email.png'],
    'basic' => ['assets/img/LogoBasicCar.png', 'assets/img/LogoBasicCar_email.png'],
];

/** A logo path from settings is used only if it is an image under assets/img/ that really exists. */
function brand_logo_path_ok(string $path): bool {
    return preg_match('#^assets/img/[A-Za-z0-9_\-/]+\.(png|jpe?g|webp)$#i', $path) === 1
        && !str_contains($path, '..')
        && is_file(__DIR__ . '/../' . $path);
}

/**
 * The logo's file path from the project root ('tg' or 'basic'). A custom logo set in settings is used for
 * emails too; otherwise emails get the small *_email.png copy.
 */
function brand_logo_file(string $which, bool $for_email = false): string {
    global $conn;
    $custom = '';
    if (isset($conn) && $conn instanceof mysqli) {
        try {
            $custom = trim(getSetting($conn, "logo_{$which}_path", ''));
        } catch (Throwable $e) {
            $custom = '';
        }
    }
    if ($custom !== '' && brand_logo_path_ok($custom)) return $custom;
    return BRAND_LOGO_DEFAULTS[$which][$for_email ? 1 : 0];
}

/**
 * The logo's URL for src/href, e.g. brand_logo('tg', '../') -> "../assets/img/tg_logo.png?v=1730000000".
 * $prefix is the page's path back to the project root. ?v= changes when the file does, so a replaced logo shows
 * at once even though browsers keep images for 30 days (assets/.htaccess). The path only ever holds
 * [A-Za-z0-9_-/.], so it is safe inside an HTML attribute as is.
 */
function brand_logo(string $which, string $prefix = ''): string {
    $file = brand_logo_file($which);
    return $prefix . $file . '?v=' . (int)@filemtime(__DIR__ . '/../' . $file);
}
