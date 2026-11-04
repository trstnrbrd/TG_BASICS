<?php
/**
 * includes/lookups.php
 * The small lists kept in phpMyAdmin-managed tables (no admin page, owner's choice): mortgagees,
 * claim types, repair service types and the landing-page testimonials. Each reads its table
 * through the DAL and falls back to [] until the table exists.
 * (Was mortgagees.php, claim_types.php, service_types.php, testimonials.php until 2026-11-04.)
 */

/* =========================================================================
 * MORTGAGEES ("Mortgagee / Financed By" on Add / Edit Policy)
 * ========================================================================= */

// The banks / financing companies in the "Mortgagee / Financed By" dropdown (Add Policy, Edit Policy) come from
// the `mortgagees` table, not a hardcoded list (panel revision). Rows are added and changed by hand in
// phpMyAdmin; is_active = 0 hides one from the dropdown without touching the policies that already name it.
// "Other" (type any name) is not a row: the pages always add it last.

/** The active mortgagee names, in display order. */
function mortgagees_active(mysqli $conn): array {
    require_once __DIR__ . '/data_access.php';
    return mortgagees_rows($conn);   // table not set up yet: [] (only "None / Cash" and "Other" until the SQL is run)
}

/* =========================================================================
 * CLAIM TYPES
 * ========================================================================= */

// Claim types (File New Claim, the claims list filter, every claim badge and email) come from the `claim_types`

/** Every claim type, active or not, in display order. Cached for the request. [] until the table exists. */
function claim_types_all(mysqli $conn): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    require_once __DIR__ . '/data_access.php';
    return $cache = claim_types_rows($conn);   // [] before the SQL is run
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

/* =========================================================================
 * REPAIR SERVICE TYPES
 * ========================================================================= */

// Repair service types (New Repair Job, every repair / quotation page, the client's QR page, the emails) come from
// the `service_types` table, not a hardcoded list (panel revision). Rows are added and changed by hand in
// phpMyAdmin. A job stores the type's `code` (repair_jobs.service_type), so a code never changes once used;
// label, group_label (the heading it sits under in the dropdown) and default_price (the first line's price on a
// new quotation) can. is_active = 0 stops a type being offered for a new job; old jobs keep showing it.
// A job saved with a code that is not in the table (older entries such as "Body Repair") shows that text as is.

/** Every service type, active or not, in display order. Cached for the request. [] until the table exists. */
function service_types_all(mysqli $conn): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    require_once __DIR__ . '/data_access.php';
    return $cache = service_types_rows($conn);   // [] before the SQL is run
}

/** The types a new job can be booked as. */
function service_types_active(mysqli $conn): array {
    return array_values(array_filter(service_types_all($conn), fn($t) => (int)$t['is_active'] === 1));
}

/** [code => label] for every type, for pages that show a job's service (unknown codes fall back to themselves). */
function service_type_labels(mysqli $conn): array {
    return array_column(service_types_all($conn), 'label', 'code');
}

/** The first line's price on a new quotation for this service type (0 if none is set). */
function service_type_default_price(mysqli $conn, string $code): float {
    foreach (service_types_all($conn) as $t) {
        if ($t['code'] === $code) return (float)$t['default_price'];
    }
    return 0.0;
}

/* =========================================================================
 * TESTIMONIALS (landing page)
 * ========================================================================= */

// Client testimonials on the landing page come from the `testimonials` table (panel revision: no hardcoded
// reviews). Rows are added and edited by hand in phpMyAdmin; is_visible = 0 hides one without deleting it.

/**
 * The visible testimonials, in display order, each with a 'meta' line such as "Google Review · 5 years ago".
 * Returns [] when the table is missing or the query fails, so the landing page still loads (the section is hidden).
 */
function load_testimonials(mysqli $conn): array {
    require_once __DIR__ . '/data_access.php';
    try {
        $rows = testimonials_rows($conn);
    } catch (mysqli_sql_exception $e) {
        error_log('Testimonials not loaded: ' . $e->getMessage());
        return [];
    }

    $current_year = (int)date('Y');
    $list = [];
    foreach ($rows as $row) {
        // the "X years ago" label is computed from the review's posting year, so it stays right as time passes
        $diff = max(0, $current_year - (int)$row['review_year']);
        $ago  = $diff === 0 ? 'This year' : ($diff === 1 ? '1 year ago' : $diff . ' years ago');
        $list[] = [
            'name'   => $row['client_name'],
            'quote'  => $row['quote'],
            'rating' => max(1, min(5, (int)$row['rating'])), // typed by hand, so keep it within 1-5 stars
            'meta'   => trim($row['source']) !== '' ? $row['source'] . ' · ' . $ago : $ago,
        ];
    }
    return $list;
}
