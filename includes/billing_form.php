<?php
/**
 * includes/billing_form.php
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
