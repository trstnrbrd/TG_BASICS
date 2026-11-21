<?php
// The Unit Inspection's top-view car and its numbering, in one place: New Repair Job (clickable, the form fields
// are the list's radio buttons), View Repair Job (a small read-only copy for reference) and Print Inspection.
// A new inspection area needs an entry in REPAIR_CHECKLIST_AREAS + REPAIR_CHECKLIST_SECTORS (config/access.php),
// a shape in REPAIR_CAR_SHAPES and a number spot in REPAIR_CAR_PINS below.
require_once __DIR__ . '/../config/access.php';

// Every shape of the car (car coordinates, drawn inside translate(30 30)), in drawing order.
// [area key or null for plain body parts, extra class, tag, attributes]
const REPAIR_CAR_SHAPES = [
    // tires sit under the body edge
    ['tires_wheels', 'tire', 'rect', 'x="28" y="92" width="20" height="50" rx="6"'],
    ['tires_wheels', 'tire', 'rect', 'x="192" y="92" width="20" height="50" rx="6"'],
    ['tires_wheels', 'tire', 'rect', 'x="28" y="356" width="20" height="50" rx="6"'],
    ['tires_wheels', 'tire', 'rect', 'x="192" y="356" width="20" height="50" rx="6"'],
    // body (not an area)
    [null, 'car-body', 'path', 'd="M70 40 C88 26 152 26 170 40 C186 50 194 70 194 100 L196 380 C196 420 186 446 166 454 C148 461 92 461 74 454 C54 446 44 420 44 380 L46 100 C46 70 54 50 70 40 Z"'],
    // mirrors
    ['mirror_left',  '', 'path', 'd="M46 182 L24 177 C19 177 17 182 19 187 L25 196 L46 196 Z"'],
    ['mirror_right', '', 'path', 'd="M194 182 L216 177 C221 177 223 182 221 187 L215 196 L194 196 Z"'],
    // front
    ['front_bumper', '', 'path', 'd="M64 44 C86 28 154 28 176 44 L170 56 C148 44 92 44 70 56 Z"'],
    ['hood',         '', 'path', 'd="M86 58 C108 54 132 54 154 58 L178 80 L176 140 C150 133 90 133 64 140 L62 80 Z"'],
    ['headlights', 'lamp-front', 'path', 'd="M52 72 C56 59 66 53 80 51 L84 61 C72 63 63 69 57 80 Z"'],
    ['headlights', 'lamp-front', 'path', 'd="M188 72 C184 59 174 53 160 51 L156 61 C168 63 177 69 183 80 Z"'],
    ['windshield', 'glass', 'path', 'd="M68 148 C98 138 142 138 172 148 L160 196 C136 190 104 190 80 196 Z"'],
    // cabin: roof and rear glass are not areas
    [null, 'car-roof',  'path', 'd="M80 200 C104 195 136 195 160 200 L160 298 C136 302 104 302 80 298 Z"'],
    [null, 'car-glass', 'path', 'd="M80 302 C104 306 136 306 160 302 L170 336 C140 344 100 344 70 336 Z"'],
    // doors
    ['door_fl', '', 'path', 'd="M46 200 L76 198 L76 263 L46 263 Z"'],
    ['door_rl', '', 'path', 'd="M46 267 L76 267 L76 331 L46 336 Z"'],
    ['door_fr', '', 'path', 'd="M194 200 L164 198 L164 263 L194 263 Z"'],
    ['door_rr', '', 'path', 'd="M194 267 L164 267 L164 331 L194 336 Z"'],
    // rear
    ['trunk', '', 'path', 'd="M68 344 C100 352 140 352 172 344 L178 400 C150 410 90 410 62 400 Z"'],
    ['taillights', 'lamp-rear', 'path', 'd="M52 404 L80 412 L78 428 C64 424 56 416 52 404 Z"'],
    ['taillights', 'lamp-rear', 'path', 'd="M188 404 L160 412 L162 428 C176 424 184 416 188 404 Z"'],
    ['rear_bumper', '', 'path', 'd="M64 434 C90 447 150 447 176 434 L180 442 C170 455 150 461 120 461 C90 461 70 455 60 442 Z"'],
];

// Where each part's number sits (car coordinates)
const REPAIR_CAR_PINS = [
    'front_bumper' => [[120, 37]],
    'hood'         => [[120, 100]],
    'headlights'   => [[66, 66], [174, 66]],
    'windshield'   => [[120, 170]],
    'door_fl'      => [[61, 231]],
    'door_rl'      => [[61, 299]],
    'mirror_left'  => [[12, 187]],
    'door_fr'      => [[179, 231]],
    'door_rr'      => [[179, 299]],
    'mirror_right' => [[228, 187]],
    'trunk'        => [[120, 378]],
    'rear_bumper'  => [[120, 449]],
    'taillights'   => [[66, 419], [174, 419]],
    'tires_wheels' => [[38, 117], [202, 117], [38, 381], [202, 381]],
];

// Paper colours written on the shapes as SVG attributes. The app's stylesheets (repair_car.css) override them with
// theme colours; the print page has no such stylesheet, so a print and its PDF (html2canvas) keep these.
// [fill, stroke, stroke-width]
const REPAIR_CAR_PAPER = [
    'car-body'   => ['#F4F1EC', '#8A8072', 1.6],
    'car-roof'   => ['#FFFFFF', '#D8D0C3', 1.2],
    'car-glass'  => ['#DCE8F0', '#D8D0C3', 1.2],
    ''           => ['#FFFFFF', '#8A8072', 1.4],
    'glass'      => ['#DCE8F0', '#8A8072', 1.4],
    'lamp-front' => ['#F7E8BC', '#8A8072', 1.4],
    'lamp-rear'  => ['#F5D3CC', '#8A8072', 1.4],
    'tire'       => ['#57514A', '#57514A', 1.4],
    'minor'      => ['#F6DC92', '#B8860B', 2.2],
    'major'      => ['#EFA79C', '#C0392B', 2.4],
];

/**
 * The inspection list in sectors (REPAIR_CHECKLIST_SECTORS), numbered 1..n in that order; the same number marks the
 * part on the car. An area no sector names still shows, under "Other".
 * Returns ['sectors' => [key => ['label', 'areas' => [area keys]]], 'num' => [area => n], 'sector_of' => [area => sector]].
 */
function repair_inspection_layout(): array
{
    $areas   = REPAIR_CHECKLIST_AREAS;
    $sectors = [];
    foreach (REPAIR_CHECKLIST_SECTORS as $sector_key => $sector) {
        $keys = array_values(array_filter($sector['areas'], fn($k) => isset($areas[$k])));
        if ($keys) $sectors[$sector_key] = ['label' => $sector['label'], 'areas' => $keys];
    }
    $sorted   = $sectors ? array_merge(...array_column($sectors, 'areas')) : [];
    $unsorted = array_values(array_diff(array_keys($areas), $sorted));
    if ($unsorted) $sectors['other'] = ['label' => 'Other', 'areas' => $unsorted];

    $num = $sector_of = [];
    foreach ($sectors as $sector_key => $sector) {
        foreach ($sector['areas'] as $k) {
            $num[$k]       = count($num) + 1;
            $sector_of[$k] = $sector_key;
        }
    }
    return ['sectors' => $sectors, 'num' => $num, 'sector_of' => $sector_of];
}

/** A checklist (area_key => row with condition_value) as area_key => 'none' | 'minor' | 'major'. */
function repair_inspection_conditions(array $checklist): array
{
    $out = [];
    foreach (REPAIR_CHECKLIST_AREAS as $key => $_) {
        $c = $checklist[$key]['condition_value'] ?? 'none';
        $out[$key] = isset(REPAIR_DAMAGE_LEVELS[$c]) ? $c : 'none';
    }
    return $out;
}

/**
 * The top-view car as an inline SVG.
 * $conditions : area_key => 'minor' | 'major' colours that part and its number (anything else = no damage).
 * $interactive: New Repair Job — each area's first shape is a keyboard stop (add_repair.js opens its popup).
 *               Otherwise each part carries a <title> ("Hood: Minor Scratch") as its hover tooltip.
 */
function repair_car_svg(array $conditions = [], bool $interactive = false, string $class = ''): string
{
    $layout = repair_inspection_layout();
    $labels = REPAIR_CHECKLIST_AREAS;
    $paint  = function (string $kind): string {
        [$fill, $stroke, $w] = REPAIR_CAR_PAPER[$kind] ?? REPAIR_CAR_PAPER[''];
        return ' fill="' . $fill . '" stroke="' . $stroke . '" stroke-width="' . $w . '"';
    };
    $state = fn(string $key): string => in_array($conditions[$key] ?? 'none', ['minor', 'major'], true) ? $conditions[$key] : '';

    $svg  = '<svg viewBox="0 0 300 524" class="car-svg' . ($interactive ? '' : ' is-static') . ($class !== '' ? ' ' . $class : '') . '"'
          . ' xmlns="http://www.w3.org/2000/svg" font-family="Plus Jakarta Sans, Segoe UI, Arial, sans-serif"'
          . ' role="group" aria-label="Car seen from above, front at the top">';
    $lbl  = ' class="sector-lbl" font-size="10" font-weight="700" letter-spacing="1.2" fill="#9C9286" text-anchor="middle"';
    $svg .= '<text x="150" y="13"' . $lbl . '>&#9650; FRONT</text>'
          . '<text x="150" y="519"' . $lbl . '>REAR</text>'
          . '<text x="10" y="262"' . $lbl . ' transform="rotate(-90 10 262)">LEFT &#183; DRIVER</text>'
          . '<text x="290" y="262"' . $lbl . ' transform="rotate(90 290 262)">RIGHT &#183; PASSENGER</text>';
    $svg .= '<g transform="translate(30 30)">';

    $seen = [];
    foreach (REPAIR_CAR_SHAPES as [$key, $extra, $tag, $attrs]) {
        if ($key === null) {   // plain body part
            $svg .= '<' . $tag . ' class="' . $extra . '" ' . $attrs . $paint($extra) . '/>';
            continue;
        }
        if (!isset($labels[$key])) continue;   // an area taken out of REPAIR_CHECKLIST_AREAS is not drawn
        $st    = $state($key);
        $label = htmlspecialchars($labels[$key], ENT_QUOTES);
        $first = !isset($seen[$key]);
        $seen[$key] = true;
        $cls   = implode(' ', array_filter(['panel', $extra, $st]));
        $a11y  = $interactive
            ? ($first ? ' tabindex="0" role="button" aria-label="' . $label . '"' : ' aria-hidden="true"')
            : '';
        $open  = '<' . $tag . ' class="' . $cls . '" data-panel="' . $key . '" data-sector="' . ($layout['sector_of'][$key] ?? '') . '" '
               . $attrs . $paint($st !== '' ? $st : $extra) . $a11y;
        if ($interactive) {
            $svg .= $open . '/>';
        } else {
            $cond  = REPAIR_DAMAGE_LEVELS[$st !== '' ? $st : 'none']['label'];
            $svg  .= $open . '><title>' . $label . ': ' . htmlspecialchars($cond, ENT_QUOTES) . '</title></' . $tag . '>';
        }
    }

    // numbers (match the list)
    foreach (REPAIR_CAR_PINS as $key => $spots) {
        if (!isset($layout['num'][$key])) continue;
        $st = $state($key);
        [$cf, $cs, $tf] = match ($st) {
            'minor' => ['#B8860B', '#B8860B', '#FFFFFF'],
            'major' => ['#C0392B', '#C0392B', '#FFFFFF'],
            default => ['#FFFFFF', '#8A8072', '#4A4540'],
        };
        foreach ($spots as [$px, $py]) {
            $svg .= '<g class="pin' . ($st !== '' ? ' ' . $st : '') . '" data-pin="' . $key . '" transform="translate(' . $px . ' ' . $py . ')" aria-hidden="true">'
                  . '<circle r="8.5" fill="' . $cf . '" stroke="' . $cs . '" stroke-width="1.2"/>'
                  . '<text y="3.2" font-size="9.5" font-weight="700" fill="' . $tf . '" text-anchor="middle">' . $layout['num'][$key] . '</text>'
                  . '</g>';
        }
    }
    return $svg . '</g></svg>';
}
