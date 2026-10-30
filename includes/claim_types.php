<?php
// Claim types (File New Claim, the claims list filter, every claim badge and email) come from the `claim_types`

/** Every claim type, active or not, in display order. Cached for the request. [] until the table exists. */
function claim_types_all(mysqli $conn): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $res = $conn->query("SELECT code, label, badge_class, is_active FROM claim_types ORDER BY sort_order, label");
        $cache = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    } catch (mysqli_sql_exception $e) {
        $cache = [];
    }
    return $cache;
}

/** The types a new claim can be filed as. */
function claim_types_active(mysqli $conn): array {
    return array_values(array_filter(claim_types_all($conn), fn($t) => (int)$t['is_active'] === 1));
}

/**
 * The label and badge class for a stored code. badge_class is typed by hand in phpMyAdmin, so only a plain
 * "badge-<word>" class is used (safe inside class=""); anything else, or a code no longer on the list, shows muted.
 */
function claim_type_badge(mysqli $conn, string $code): array {
    foreach (claim_types_all($conn) as $t) {
        if ($t['code'] !== $code) continue;
        $class = preg_match('/^badge-[a-z]+$/', $t['badge_class']) ? $t['badge_class'] : 'badge-muted';
        return ['label' => $t['label'], 'class' => $class];
    }
    return ['label' => ucfirst($code), 'class' => 'badge-muted'];
}
