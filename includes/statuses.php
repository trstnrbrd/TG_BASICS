<?php
// Every status list (billing, claim, repair) comes from one `statuses` table, keyed by `module` + `code`
// (panel revision; phpMyAdmin only). A record stores the `code`, so a code never changes once used; label, colours,
// icon, wording, order and is_active can. Columns only one module uses are left blank for the others:
//   billing - icon, description (the Update Status choices)
//   repair  - color (its Update Status choice), public_label / public_color / progress (the client's QR page),
//             is_closed (completed / cancelled: off the mechanic's list, photos hidden from the client),
//             is_dead_end (cancelled: reachable from any open status, the flow ends there)
// A repair job moves forward in sort_order (mechanics); the claim flow has branches, so it stays in code
// (CLAIM_STATUS_FLOW, config/access.php). Nothing falls back to a hardcoded list: before the SQL is run the lists
// are empty and every repair job counts as closed, so no photo is ever shown by mistake.

/** All statuses of a module ('billing', 'claim', 'repair'), active or not, in order: [code => row]. Cached. */
function statuses_for(string $module): array {
    static $cache = [];
    if (isset($cache[$module])) return $cache[$module];
    global $conn;
    $cache[$module] = [];
    if (!isset($conn) || !($conn instanceof mysqli)) return $cache[$module];
    try {
        $st = $conn->prepare("SELECT * FROM statuses WHERE module = ? ORDER BY sort_order, label");
        $st->bind_param('s', $module);
        $st->execute();
        foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $cache[$module][$row['code']] = status_row_clean($row);
    } catch (mysqli_sql_exception $e) {
    }
    return $cache[$module];
}

/**
 * One row made safe to print: badge_class, icon and colours are typed by hand in phpMyAdmin, so only a plain
 * "badge-<word>" class, a [a-z-] icon name and a #hex colour are used; anything else falls back to a neutral value.
 */
function status_row_clean(array $r): array {
    $hex = fn($c, $fallback) => preg_match('/^#[0-9A-Fa-f]{3,8}$/', (string)$c) ? $c : $fallback;
    return [
        'code'         => $r['code'],
        'label'        => $r['label'],
        'class'        => preg_match('/^badge-[a-z]+$/', (string)$r['badge_class']) ? $r['badge_class'] : 'badge-gray',
        'icon'         => preg_match('/^[a-z-]+$/', (string)$r['icon']) ? $r['icon'] : 'document-text',
        'desc'         => (string)$r['description'],
        'color'        => $hex($r['color'] ?? '', '#6B7280'),
        'public_label' => (string)($r['public_label'] ?? '') !== '' ? $r['public_label'] : $r['label'],
        'public_color' => $hex($r['public_color'] ?? '', '#7F8C8D'),
        'progress'     => max(0, min(100, (int)($r['progress'] ?? 0))),
        'is_closed'    => (int)($r['is_closed'] ?? 0) === 1,
        'is_dead_end'  => (int)($r['is_dead_end'] ?? 0) === 1,
        'is_active'    => (int)($r['is_active'] ?? 1) === 1,
    ];
}

/** The statuses of a module a record can be moved to now (is_active = 1). */
function statuses_active(string $module): array {
    return array_filter(statuses_for($module), fn($s) => $s['is_active']);
}

/** Everything about one stored status code; a code not in the table shows as itself, neutral. */
function status_badge(string $module, string $code): array {
    return statuses_for($module)[$code] ?? status_row_clean(['code' => $code, 'label' => ucwords(str_replace('_', ' ', $code)),
                                                              'badge_class' => '', 'icon' => '', 'description' => '']);
}

// ── CLAIMS ── (the flow itself is CLAIM_STATUS_FLOW in config/access.php)

function claim_statuses(): array { return statuses_for('claim'); }
function claim_status_badge(string $code): array { return status_badge('claim', $code); }

// ── REPAIR JOBS ── the rules take the status list as a parameter (default: the table) so they can be unit-tested

function repair_statuses(): array { return statuses_for('repair'); }
function repair_status_badge(string $code): array { return status_badge('repair', $code); }

/** Codes of the statuses a job is still being worked on in (not is_closed). */
function repair_open_statuses(?array $list = null): array {
    return array_keys(array_filter($list ?? repair_statuses(), fn($s) => !$s['is_closed']));
}

/** Is the repair job still open? Photos show on the client's QR page only then. An unknown status counts as closed. */
function repair_job_is_open(string $status, ?array $list = null): bool {
    $list ??= repair_statuses();
    return isset($list[$status]) && !$list[$status]['is_closed'];
}

/**
 * May a repair job move from $from to $to?
 * $forward_only (mechanics): only to a later status in the flow (sort_order, dead ends left out) or to a dead end
 * (cancelled), and never out of a closed status. Otherwise (owner, admins): to any other active status, so a wrong
 * update can be corrected.
 */
function repair_status_change_allowed(string $from, string $to, bool $forward_only, ?array $list = null): bool {
    $list ??= repair_statuses();
    if ($from === $to || !isset($list[$to]) || !$list[$to]['is_active']) return false;
    if (!$forward_only) return true;
    if (!isset($list[$from]) || $list[$from]['is_closed']) return false;
    if ($list[$to]['is_dead_end']) return true;
    $flow = array_keys(array_filter($list, fn($s) => !$s['is_dead_end']));
    $i = array_search($from, $flow, true);
    $j = array_search($to, $flow, true);
    return $i !== false && $j !== false && $j > $i;
}
