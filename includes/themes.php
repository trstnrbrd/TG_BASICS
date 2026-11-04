<?php
// The colour themes a user can pick in Settings > Design Preferences, in one place: the picker (name + preview colors)
const THEMES = [
    'light'              => ['name' => 'Light',              'bg' => '#F4F1EC', 'bg2' => '#FAFAF8', 'sidebar' => '#1C1A17', 'accent' => '#D4A017', 'card' => '#FFFFFF'],
    'dark'               => ['name' => 'Dark',               'bg' => '#141210', 'bg2' => '#1C1A17', 'sidebar' => '#0F0E0D', 'accent' => '#D4A017', 'card' => '#242220'],
    'warm'               => ['name' => 'Warm',               'bg' => '#F8F1E7', 'bg2' => '#F3E9DC', 'sidebar' => '#2A1C12', 'accent' => '#B8733A', 'card' => '#FFFCF7'],
    'high-contrast'      => ['name' => 'High Contrast',      'bg' => '#FFFFFF', 'bg2' => '#D9D9D9', 'sidebar' => '#FFFFFF', 'accent' => '#000000', 'card' => '#FFFFFF'],
    'high-contrast-dark' => ['name' => 'High Contrast Dark', 'bg' => '#000000', 'bg2' => '#2A2A2A', 'sidebar' => '#000000', 'accent' => '#FFC83D', 'card' => '#0D0D0D'],
];
const THEME_DEFAULT = 'light';

/**
 * "Auto": follow the device's light / dark setting. Stored in users.theme like a theme, but it is a choice,
 * not a theme with its own CSS: the page is printed as THEME_SYSTEM_LIGHT and a small script in the header
 * switches it to THEME_SYSTEM_DARK before it is drawn when the device is dark (and follows later changes).
 */
const THEME_SYSTEM       = 'system';
const THEME_SYSTEM_LIGHT = 'light';
const THEME_SYSTEM_DARK  = 'dark';

/** A stored or posted theme key, or the default when it is not one of THEMES. */
function theme_valid(?string $theme): string {
    return isset(THEMES[$theme ?? '']) ? $theme : THEME_DEFAULT;
}

/** What a user may choose: one of THEMES or THEME_SYSTEM ("Auto"); anything else gives the default. */
function theme_choice_valid(?string $choice): string {
    return $choice === THEME_SYSTEM ? THEME_SYSTEM : theme_valid($choice);
}

/** The theme to print on the page for a choice (Auto prints its light theme; the header script may switch it). */
function theme_for_choice(string $choice): string {
    return $choice === THEME_SYSTEM ? THEME_SYSTEM_LIGHT : theme_valid($choice);
}

/**
 * Layout preferences (Settings > Design Preferences), each user's own, stored together in users.ui_prefs.
 * pref => [default, [value => label]]. The header puts each on <html> as data-text-size / data-sidebar /
 * data-width, and the CSS does the rest (app.css, sidebar.css): no page needs to know about them.
 */
const UI_PREFS = [
    'text_size' => ['normal', ['small' => 'Small', 'normal' => 'Normal', 'large' => 'Large', 'xlarge' => 'Extra Large']],
    'sidebar'   => ['full',   ['full' => 'Full', 'compact' => 'Compact']],
    'width'     => ['full',   ['full' => 'Full width', 'centered' => 'Centered']],
];

/** Every layout preference with a valid value: stored JSON (or a posted array) cleaned, defaults filled in. */
function ui_prefs_clean($raw): array {
    if (is_string($raw)) $raw = json_decode($raw, true);
    $raw = is_array($raw) ? $raw : [];
    $out = [];
    foreach (UI_PREFS as $key => [$default, $options]) {
        $v = $raw[$key] ?? null;
        $out[$key] = is_string($v) && isset($options[$v]) ? $v : $default;
    }
    return $out;
}

/** The data-* attributes for <html> (values are from UI_PREFS, so safe to print). */
function ui_prefs_attrs(array $prefs): string {
    $prefs = ui_prefs_clean($prefs);
    return 'data-text-size="' . $prefs['text_size'] . '" data-sidebar="' . $prefs['sidebar'] . '" data-width="' . $prefs['width'] . '"';
}
