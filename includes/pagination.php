<?php
// Page-by-page lists (owner's staff expect ~1,000 clients) — Client Records, Renewal Tracking, Claims, Billing,
// Repair Jobs and Quotations. Same pager look as the Activity Log. The page number travels in ?page=; a list's
// filter form doesn't carry it, so changing a filter or searching starts again from page 1.

const TG_PER_PAGE = 25;

/**
 * Runs a list query one page at a time. $sql is the page's full query (ORDER BY included, no LIMIT) and
 * $types / $params its bindings; the total is counted from that same query, so the count always matches.
 * Returns [mysqli_result for this page, pager array].
 */
function paginate_query(mysqli $conn, string $sql, string $types = '', array $params = [], int $per = TG_PER_PAGE): array
{
    $cnt = $conn->prepare("SELECT COUNT(*) FROM ($sql) AS pg_rows");
    if ($params) $cnt->bind_param($types, ...$params);
    $cnt->execute();
    $pg = paginate((int)$cnt->get_result()->fetch_row()[0], $per);

    $stmt = $conn->prepare($sql . ' LIMIT ' . (int)$pg['per'] . ' OFFSET ' . (int)$pg['offset']);
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    return [$stmt->get_result(), $pg];
}

/** Page numbers for $total rows. A page number past the end (e.g. after deleting) shows the last page. */
function paginate(int $total, int $per = TG_PER_PAGE): array
{
    $pages = max(1, (int)ceil($total / $per));
    $raw   = $_GET['page'] ?? 1;
    $page  = (is_scalar($raw) && ctype_digit((string)$raw)) ? (int)$raw : 1;
    $page  = min(max(1, $page), $pages);
    $offset = ($page - 1) * $per;
    return ['page' => $page, 'pages' => $pages, 'per' => $per, 'offset' => $offset, 'total' => $total,
            'from' => $total ? $offset + 1 : 0, 'to' => min($total, $offset + $per)];
}

/** "1,000 records" on one page, or "26–50 of 1,000 records" when there are several. */
function paginate_summary(array $pg, string $noun = 'record'): string
{
    $label = number_format($pg['total']) . ' ' . $noun . ($pg['total'] === 1 ? '' : 's');
    return $pg['pages'] > 1 ? number_format($pg['from']) . '–' . number_format($pg['to']) . ' of ' . $label : $label;
}

/** Link to another page of the same list, keeping its filters (one-time success / error messages dropped). */
function paginate_href(int $page): string
{
    $qs = array_diff_key($_GET, ['page' => 1, 'success' => 1, 'error' => 1, 'msg' => 1]);
    if ($page > 1) $qs['page'] = $page;
    return '?' . http_build_query($qs);
}

/** The pager bar under a list; prints nothing when everything fits on one page. */
function render_pagination(array $pg): void
{
    if ($pg['pages'] <= 1) return;
    $page = $pg['page']; $last = $pg['pages'];
    $btn  = 'padding:0.4rem 0.6rem;font-size:0.75rem;min-width:32px;justify-content:center;';
    $gap  = '<span style="color:var(--text-muted);padding:0.4rem 0.2rem;font-size:0.75rem;">&hellip;</span>';
    $link = fn(int $n, string $text, bool $on = false, string $extra = '') =>
        '<a href="' . htmlspecialchars(paginate_href($n)) . '" class="' . ($on ? 'btn-primary' : 'btn-ghost') . '" style="' . $btn . $extra . '"'
        . ($on ? ' aria-current="page"' : '') . '>' . $text . '</a>';

    echo '<nav class="tg-pager" aria-label="Pages" style="padding:1rem 1.25rem;border-top:1px solid var(--border);display:flex;justify-content:center;align-items:center;gap:0.35rem;flex-wrap:wrap;">';
    if ($page > 1) echo $link($page - 1, icon('chevron-left', 12) . ' Prev', false, 'padding:0.4rem 0.7rem;');
    $start = max(1, $page - 2); $end = min($last, $page + 2);
    if ($start > 1) { echo $link(1, '1'); if ($start > 2) echo $gap; }
    for ($i = $start; $i <= $end; $i++) echo $link($i, (string)$i, $i === $page);
    if ($end < $last) { if ($end < $last - 1) echo $gap; echo $link($last, (string)$last); }
    if ($page < $last) echo $link($page + 1, 'Next ' . icon('chevron-right', 12), false, 'padding:0.4rem 0.7rem;');
    echo '<span style="width:100%;text-align:center;font-size:0.7rem;color:var(--text-muted);">Page ' . $page . ' of ' . $last . '</span>';
    echo '</nav>';
}
