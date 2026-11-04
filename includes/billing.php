<?php
/**
 * includes/billing.php
 * Billing helpers: the three billing lists (statuses, required documents, signatories) and the one
 * check of a billing's own fields shared by Add Billing and Edit Details.
 * (Was billing_lists.php + billing_form.php until 2026-11-04.)
 */

/* =========================================================================
 * LISTS (statuses, required documents, signatories)
 * ========================================================================= */

// The three billing lists are data, not code (panel revision), all changed by hand in phpMyAdmin:
//   statuses (module 'billing') - Draft / Sent / Paid / Unpaid ... (billing.status stores the `code`)
//   billing_document_types - the Required Documents; which ones a billing has received is in billing_documents
//   billing_signatories    - who signs as "Authorized Representative / Cashier"; the chosen name and title are
//                            copied onto the billing (signed_by_name / signed_by_title) so an old statement never
//                            changes when the list does. is_default = 1 is pre-selected on a new billing.
// is_active = 0 stops an entry being offered for new choices; billings that already use it keep showing it.
// Nothing here falls back to a hardcoded list: until the SQL is run the lists are simply empty.
require_once __DIR__ . '/statuses.php';
// The one code with behaviour is BILLING_STATUS_SENT: moving a billing to it stamps sent_at.

const BILLING_STATUS_SENT = 'sent';

require_once __DIR__ . '/data_access.php';   // the queries: includes/data/lists.php ([] before the SQL is run)

// ── STATUSES ── (the shared `statuses` table, module 'billing'; includes/statuses.php)

/** Every billing status, active or not, in display order (rows carry 'code' and 'is_active'). */
function billing_statuses_all(mysqli $conn): array {
    return array_values(statuses_for('billing'));
}

/** The statuses a billing can be moved to. */
function billing_statuses_active(mysqli $conn): array {
    return array_values(statuses_active('billing'));
}

/** Label, badge class, icon and description of a stored status code. */
function billing_status_badge(mysqli $conn, string $code): array {
    return status_badge('billing', $code);
}

// ── REQUIRED DOCUMENTS ──

/** Every document type, active or not, in display order. Cached for the request. */
function billing_doc_types_all(mysqli $conn): array {
    static $cache = null;
    return $cache ??= billing_document_types_rows($conn);
}

/** The doc_type_ids a set of billings have received: [billing_id => [doc_type_id => true]]. */
function billing_docs_received(mysqli $conn, array $billing_ids): array {
    $out = [];
    foreach (billing_documents_rows($conn, $billing_ids) as $r) {
        $out[(int)$r['billing_id']][(int)$r['doc_type_id']] = true;
    }
    return $out;
}

/**
 * The documents to show for one billing: every active type, plus an inactive one it already received (so a
 * retired document is not silently dropped from an old record). Each row gets 'received' => bool.
 */
function billing_docs_for(mysqli $conn, array $received): array {
    $list = [];
    foreach (billing_doc_types_all($conn) as $t) {
        $got = isset($received[(int)$t['doc_type_id']]);
        if ((int)$t['is_active'] !== 1 && !$got) continue;
        $list[] = $t + ['received' => $got];
    }
    return $list;
}

// ── SIGNATORIES ──

/** The people who can sign as cashier, default first. */
function billing_signatories_active(mysqli $conn): array {
    static $cache = null;
    return $cache ??= billing_signatories_rows($conn);
}

/** The signatory picked in a form (by id), or null if the id is not an active one. */
function billing_signatory_pick(mysqli $conn, $id): ?array {
    foreach (billing_signatories_active($conn) as $s) {
        if ((int)$s['signatory_id'] === (int)$id) return $s;
    }
    return null;
}

/* =========================================================================
 * FORM CHECK (Add Billing and Edit Details)
 * ========================================================================= */

/**
 * The one check of a billing's own fields, shared by Add Billing and View Billing > Edit Details.
 * Until 2026-11-04 each page had its own copy and they had drifted: Edit let "Billed To" be saved blank,
 * and Add read and checked the Notes but never saved them.
 * (The claim link, the duplicate check and the signatory are the pages' own business: Add needs a claim,
 *  Edit keeps the one it has.)
 */
require_once __DIR__ . '/../config/validators.php';

/** The fallback signatory title, printed when a billing has none saved. */
const BILLING_SIGNER_TITLE = 'Authorized Representative / Cashier';

/**
 * Reads and checks the posted billing fields. Returns [values, errors]:
 * values = billed_to, incident_date / repair_date (Y-m-d or null), parts_cost, labor_cost, other_cost,
 * deductible (floats), notes; errors = messages, [] when everything is fine.
 */
function billing_form_check(array $post): array
{
    $errors = [];
    $billed = san_str($post['billed_to'] ?? '', 255);
    if ($billed === '') $errors[] = 'Billed To (insurance company) is required.';

    // Each amount checked by read_money(): a typo is an error, never a silent ₱0 (blank = 0)
    [$parts,  $e1] = read_money($post['parts_cost'] ?? '', 'Parts cost');
    [$labor,  $e2] = read_money($post['labor_cost'] ?? '', 'Labor cost');
    [$other,  $e3] = read_money($post['other_cost'] ?? '', 'Other cost');
    [$deduct, $e4] = read_money($post['deductible'] ?? '', 'Deductible');
    foreach ([$e1, $e2, $e3, $e4] as $e) if ($e !== '') $errors[] = $e;
    // the amount due is (parts + labor + other) - deductible: it must never go below zero
    if ($e1 . $e2 . $e3 . $e4 === '' && round($deduct, 2) > round($parts + $labor + $other, 2)) {
        $errors[] = 'The deductible cannot be more than the total cost of repair (₱' . number_format($parts + $labor + $other, 2) . ').';
    }

    $notes = san_str($post['notes'] ?? '', MAX_NOTE);
    if (text_too_long($post['notes'] ?? '', MAX_NOTE)) $errors[] = 'Notes are too long (max ' . MAX_NOTE . ' characters).';

    $inc = san_str($post['incident_date'] ?? '', 10);
    $rep = san_str($post['repair_date']   ?? '', 10);
    $inc_ok = $inc === '' || validate_date($inc);
    $rep_ok = $rep === '' || validate_date($rep);
    if (!$inc_ok) $errors[] = 'Incident date is not a valid date.';
    if (!$rep_ok) $errors[] = 'Repair date is not a valid date.';
    if ($inc_ok && $inc !== '' && $inc > date('Y-m-d')) $errors[] = 'Incident date cannot be in the future.';
    if ($inc_ok && $rep_ok && $inc !== '' && $rep !== '' && $rep < $inc) $errors[] = 'Repair date cannot be before the incident date.';

    return [[
        'billed_to' => $billed, 'incident_date' => $inc !== '' ? $inc : null, 'repair_date' => $rep !== '' ? $rep : null,
        'parts_cost' => $parts, 'labor_cost' => $labor, 'other_cost' => $other, 'deductible' => $deduct, 'notes' => $notes,
    ], $errors];
}
