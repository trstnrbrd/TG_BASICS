<?php
/* =========================================================================
 * Quotations, their line items, and the receipts (billing statements)
 * issued from them
 * ========================================================================= */
require_once __DIR__ . '/../pagination.php';

/** A quotation with its job, client, vehicle and receipt (if issued), for its page; or null. */
function quotation_find(mysqli $conn, int $quotation_id): ?array
{
    return dal_one($conn, "
        SELECT q.*,
               j.job_number, j.service_type, j.repair_date, j.status AS job_status,
               c.client_id, c.full_name, c.contact_number, c.email, c.address,
               v.plate_number, v.make, v.model, v.year_model, v.color,
               r.receipt_id, r.receipt_number, r.amount_paid, r.balance AS receipt_balance,
               r.payment_method, r.payment_status, r.issued_at
        FROM quotations q
        INNER JOIN repair_jobs j ON q.job_id = j.job_id
        INNER JOIN clients     c ON j.client_id  = c.client_id
        INNER JOIN vehicles    v ON j.vehicle_id = v.vehicle_id
        LEFT  JOIN receipts    r ON r.quotation_id = q.quotation_id
        WHERE q.quotation_id = ?", 'i', [$quotation_id]);
}

/** A quotation that is still a draft (all columns), or null. */
function quotation_find_draft(mysqli $conn, int $quotation_id): ?array
{
    return dal_one($conn, "SELECT * FROM quotations WHERE quotation_id = ? AND status = 'draft'", 'i', [$quotation_id]);
}

/** The quotation number, or null. */
function quotation_number(mysqli $conn, int $quotation_id): ?string
{
    $n = dal_value($conn, "SELECT quotation_number FROM quotations WHERE quotation_id = ?", 'i', [$quotation_id]);
    return $n === null ? null : (string)$n;
}

/** The quotation already made for a repair job (one per job): its id, or null. */
function quotation_id_for_job(mysqli $conn, int $job_id): ?int
{
    $id = dal_value($conn, "SELECT quotation_id FROM quotations WHERE job_id = ? LIMIT 1", 'i', [$job_id]);
    return $id === null ? null : (int)$id;
}

/** The line items of a quotation, in order. */
function quotation_items(mysqli $conn, int $quotation_id): array
{
    return dal_all($conn, "SELECT * FROM quotation_items WHERE quotation_id = ? ORDER BY sort_order ASC", 'i', [$quotation_id]);
}

/**
 * Creates a quotation with the given number (called inside
 * insert_with_sequential_number()) and returns its id.
 */
function quotation_create(mysqli $conn, string $quotation_number, int $job_id, string $status, float $subtotal, float $discount, float $total,
                          string $notes, int $created_by): int
{
    dal_exec($conn, "INSERT INTO quotations (job_id, quotation_number, status, subtotal, discount, total, notes, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)", 'issdddsi', [$job_id, $quotation_number, $status, $subtotal, $discount, $total, $notes, $created_by]);
    return (int)$conn->insert_id;
}

/** Saves an edited draft's amounts, notes and status. */
function quotation_update(mysqli $conn, int $quotation_id, string $status, float $subtotal, float $discount, float $total, string $notes): void
{
    dal_exec($conn, "UPDATE quotations SET status = ?, subtotal = ?, discount = ?, total = ?, notes = ? WHERE quotation_id = ?",
             'sdddsi', [$status, $subtotal, $discount, $total, $notes, $quotation_id]);
}

/**
 * Replaces all line items of a quotation. $items: [['desc', 'area', 'qty',
 * 'unit_price', 'subtotal', 'sort_order'], ...]. Call inside a transaction.
 */
function quotation_items_replace(mysqli $conn, int $quotation_id, array $items): void
{
    dal_exec($conn, "DELETE FROM quotation_items WHERE quotation_id = ?", 'i', [$quotation_id]);
    foreach ($items as $it) {
        dal_exec($conn, "INSERT INTO quotation_items (quotation_id, description, area, qty, unit_price, subtotal, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)",
                 'issdddi', [$quotation_id, $it['desc'], $it['area'], $it['qty'], $it['unit_price'], $it['subtotal'], $it['sort_order']]);
    }
}

/**
 * Moves a quotation to the next step: 'pending_approval', 'approved'
 * (stamps approved_at), 'converted' (stamps converted_at) or 'cancelled'.
 * The page decides which step is allowed from the current one.
 */
function quotation_set_status(mysqli $conn, int $quotation_id, string $status): void
{
    $stamp = ['approved' => ', approved_at = NOW()', 'converted' => ', converted_at = NOW()'][$status] ?? '';
    if (!in_array($status, ['pending_approval', 'approved', 'converted', 'cancelled'], true)) {
        throw new InvalidArgumentException("Unknown quotation step: $status");
    }
    dal_exec($conn, "UPDATE quotations SET status = ?$stamp WHERE quotation_id = ?", 'si', [$status, $quotation_id]);
}

/** Deletes a quotation (its items and receipt go with it, ON DELETE CASCADE). */
function quotation_delete(mysqli $conn, int $quotation_id): void
{
    dal_exec($conn, "DELETE FROM quotations WHERE quotation_id = ?", 'i', [$quotation_id]);
}

/**
 * Issues the receipt (billing statement) of a quotation with the given
 * number (called inside insert_with_sequential_number()).
 */
function receipt_create(mysqli $conn, string $receipt_number, int $quotation_id, float $amount_paid, float $balance, string $payment_method,
                        string $payment_status, string $notes, int $issued_by): void
{
    dal_exec($conn, "INSERT INTO receipts (quotation_id, receipt_number, amount_paid, balance, payment_method, payment_status, notes, issued_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)", 'isddsssi',
             [$quotation_id, $receipt_number, $amount_paid, $balance, $payment_method, $payment_status, $notes, $issued_by]);
}

/** Updates what was paid on a receipt. */
function receipt_set_payment(mysqli $conn, int $receipt_id, float $amount_paid, float $balance, string $payment_method, string $payment_status): void
{
    dal_exec($conn, "UPDATE receipts SET amount_paid = ?, balance = ?, payment_method = ?, payment_status = ? WHERE receipt_id = ?",
             'ddssi', [$amount_paid, $balance, $payment_method, $payment_status, $receipt_id]);
}

/** Everything the printed receipt shows (quotation, job, client, vehicle, receipt), or null when it has no receipt. */
function receipt_print_data(mysqli $conn, int $quotation_id): ?array
{
    return dal_one($conn, "
        SELECT q.quotation_number, q.total, q.discount, q.subtotal, q.notes AS qt_notes,
               j.job_number, j.service_type, j.repair_date,
               c.full_name, c.contact_number, c.email, c.address,
               v.plate_number, v.make, v.model, v.year_model, v.color,
               r.receipt_number, r.amount_paid, r.balance, r.payment_method,
               r.payment_status, r.issued_at, r.notes AS receipt_notes
        FROM quotations q
        INNER JOIN repair_jobs j ON q.job_id = j.job_id
        INNER JOIN clients     c ON j.client_id  = c.client_id
        INNER JOIN vehicles    v ON j.vehicle_id = v.vehicle_id
        INNER JOIN receipts    r ON r.quotation_id = q.quotation_id
        WHERE q.quotation_id = ?", 'i', [$quotation_id]);
}

/**
 * One page of Quotations & Receipts. $status a status code or 'all';
 * $search matches client, plate, quotation no. or job no.; $sort 'newest' /
 * 'oldest'. Returns [rows, pager].
 */
function quotations_list_page(mysqli $conn, string $status, string $search, string $sort): array
{
    $where = []; $types = ''; $params = [];
    if ($status !== 'all') { $where[] = 'q.status = ?'; $params[] = $status; $types .= 's'; }
    if ($search !== '') {
        $like    = "%$search%";
        $where[] = '(c.full_name LIKE ? OR v.plate_number LIKE ? OR q.quotation_number LIKE ? OR j.job_number LIKE ?)';
        array_push($params, $like, $like, $like, $like);
        $types  .= 'ssss';
    }
    [$result, $pg] = paginate_query($conn, "
        SELECT q.quotation_id, q.quotation_number, q.status, q.total, q.discount, q.created_at,
               j.job_id, j.job_number, j.service_type, c.full_name, c.contact_number,
               v.plate_number, v.make, v.model, v.year_model, r.receipt_number, r.payment_status
        FROM quotations q
        INNER JOIN repair_jobs j ON q.job_id = j.job_id
        INNER JOIN clients     c ON j.client_id  = c.client_id
        INNER JOIN vehicles    v ON j.vehicle_id = v.vehicle_id
        LEFT  JOIN receipts    r ON r.quotation_id = q.quotation_id
        " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
        ORDER BY q.created_at " . ($sort === 'oldest' ? 'ASC' : 'DESC'), $types, $params);
    return [$result->fetch_all(MYSQLI_ASSOC), $pg];
}
