<?php
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
    try {
        $res = $conn->query("SELECT code, label, group_label, default_price, is_active FROM service_types ORDER BY sort_order, label");
        $cache = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    } catch (mysqli_sql_exception $e) {
        $cache = [];
    }
    return $cache;
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
