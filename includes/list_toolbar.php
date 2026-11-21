<?php
// The pieces of the shared list toolbar (assets/css/shared/list_toolbar.css + assets/js/shared/list_toolbar.js),
// so every list page prints the same markup. Layout of a toolbar:
//   <form method="GET" class="list-toolbar">
//     [render_agent_filter()]  lt_search(...)  <div class="lt-filters"> lt_pill(...) … </div>
//     [lt_clear(...)]  <div class="lt-actions"> primary button [+ ⋯ menu] </div>
//   </form>
// The page links both assets with ?v=filemtime cache-busters (lt_assets()).
require_once __DIR__ . '/icons.php';

/** <link> + <script> tags for the toolbar, with cache-busters. $base = path to the app root ('../../'). */
function lt_css(string $base = '../../'): string
{
    $f = __DIR__ . '/../assets/css/shared/list_toolbar.css';
    return '<link rel="stylesheet" href="' . $base . 'assets/css/shared/list_toolbar.css?v=' . filemtime($f) . '"/>';
}
function lt_js(string $base = '../../'): string
{
    $f = __DIR__ . '/../assets/js/shared/list_toolbar.js';
    return '<script src="' . $base . 'assets/js/shared/list_toolbar.js?v=' . filemtime($f) . '"></script>';
}

/**
 * The search box with its go button inside. $extra is trusted HTML placed inside the box (an autocomplete
 * dropdown); $input_id gives the input an id for page scripts.
 */
function lt_search(string $value, string $placeholder, string $aria = 'Search', string $input_id = '', string $extra = ''): string
{
    return '<div class="lt-search">'
         . '<span class="lt-search-icon">' . icon('magnifying-glass', 14) . '</span>'
         . '<input type="text" name="search"' . ($input_id !== '' ? ' id="' . htmlspecialchars($input_id) . '"' : '')
         . ' placeholder="' . htmlspecialchars($placeholder) . '" value="' . htmlspecialchars($value) . '" autocomplete="off" aria-label="' . htmlspecialchars($aria) . '"/>'
         . '<button type="submit" class="lt-go" title="Search" aria-label="Search">' . icon('arrow-right', 14) . '</button>'
         . $extra
         . '</div>';
}

/**
 * One filter pill: a label and a real <select> that applies on change. It is highlighted while it is not on
 * $default. $opts = value => text (both escaped here). $class adds e.g. 'lt-phone-only'.
 */
function lt_pill(string $name, string $label, array $opts, string $current, string $default, string $class = ''): string
{
    $cls = trim('lt-pill' . ($current !== $default ? ' is-set' : '') . ' ' . $class);
    $out = '<label class="' . $cls . '"><span>' . htmlspecialchars($label) . '</span>'
         . '<select name="' . htmlspecialchars($name) . '" aria-label="' . htmlspecialchars($label) . '">';
    foreach ($opts as $v => $t) {
        $v    = (string)$v;
        $out .= '<option value="' . htmlspecialchars($v) . '"' . ($current === $v ? ' selected' : '') . '>' . htmlspecialchars((string)$t) . '</option>';
    }
    return $out . '</select></label>';
}

/** The "✕ Clear" link back to the unfiltered list. */
function lt_clear(string $href): string
{
    return '<a href="' . htmlspecialchars($href) . '" class="lt-clear">' . icon('x-mark', 13) . ' Clear</a>';
}
