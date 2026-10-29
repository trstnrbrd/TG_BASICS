<?php
// Client testimonials on the landing page come from the `testimonials` table (panel revision: no hardcoded
// reviews). Rows are added and edited by hand in phpMyAdmin; is_visible = 0 hides one without deleting it.

/**
 * The visible testimonials, in display order, each with a 'meta' line such as "Google Review · 5 years ago".
 * Returns [] when the table is missing or the query fails, so the landing page still loads (the section is hidden).
 */
function load_testimonials(mysqli $conn): array {
    try {
        $res = $conn->query("
            SELECT client_name, source, review_year, rating, quote
            FROM testimonials
            WHERE is_visible = 1
            ORDER BY sort_order, testimonial_id
        ");
    } catch (mysqli_sql_exception $e) {
        error_log('Testimonials not loaded: ' . $e->getMessage());
        return [];
    }
    if (!$res) return [];

    $current_year = (int)date('Y');
    $list = [];
    foreach ($res->fetch_all(MYSQLI_ASSOC) as $row) {
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
