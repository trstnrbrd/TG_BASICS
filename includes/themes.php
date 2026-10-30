<?php
// The colour themes a user can pick in Settings > Design Preferences, in one place: the picker (name + preview colors)
const THEMES = [
    'light'              => ['name' => 'Light',              'bg' => '#F4F1EC', 'bg2' => '#FAFAF8', 'sidebar' => '#1C1A17', 'accent' => '#D4A017', 'card' => '#FFFFFF'],
    'dark'               => ['name' => 'Dark',               'bg' => '#141210', 'bg2' => '#1C1A17', 'sidebar' => '#0F0E0D', 'accent' => '#D4A017', 'card' => '#242220'],
    'warm'               => ['name' => 'Warm',               'bg' => '#FAF5EE', 'bg2' => '#F3EBE0', 'sidebar' => '#2C1F12', 'accent' => '#A8865A', 'card' => '#FFFFFF'],
    'high-contrast'      => ['name' => 'High Contrast',      'bg' => '#FFFFFF', 'bg2' => '#F0F0F0', 'sidebar' => '#000000', 'accent' => '#000000', 'card' => '#FFFFFF'],
    'high-contrast-dark' => ['name' => 'High Contrast Dark', 'bg' => '#000000', 'bg2' => '#111111', 'sidebar' => '#000000', 'accent' => '#FFFFFF', 'card' => '#111111'],
];
const THEME_DEFAULT = 'light';

/** A stored or posted theme key, or the default when it is not one of THEMES. */
function theme_valid(?string $theme): string {
    return isset(THEMES[$theme ?? '']) ? $theme : THEME_DEFAULT;
}
