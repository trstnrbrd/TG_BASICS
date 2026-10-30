<?php
// The insurance companies the brokerage files policies under (panel revision: not hardcoded, so the owner can add
// one in Settings). They live in the `insurance_companies` table; a policy stores the company's `name`
// (insurance_policies.insurance_company), so that is the key everywhere. short_name labels the sidebar and the
// renewal switch, billing_name pre-fills "Billed To". A deactivated company is not offered for new policies but
// its existing policies still show and can still be tracked.
require_once __DIR__ . '/transaction.php';
require_once __DIR__ . '/data_access.php';

/** Every company, active or not, in display order. Cached for the request. */
function insurance_companies_all(mysqli $conn): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = insurance_companies_rows($conn);   // table not set up yet: [] (no companies until the SQL is run)
    foreach ($cache as &$co) {
        $co['company_id'] = (int)$co['company_id'];
        $co['is_active']  = (int)$co['is_active'];
    }
    unset($co);
    return $cache;
}

/** The companies a new policy can be filed under. */
function insurance_companies_active(mysqli $conn): array {
    return array_values(array_filter(insurance_companies_all($conn), fn($co) => $co['is_active'] === 1));
}

/**
 * The companies with a renewal list: the active ones, plus a deactivated one that still has policies to track.
 * Used by the renewal switch, the sidebar, the mobile menu and the dashboard shortcuts.
 */
function insurance_companies_tracked(mysqli $conn): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $with_policies = array_fill_keys(insurance_company_names_in_use($conn), true);
    return $cache = array_values(array_filter(insurance_companies_all($conn),
        fn($co) => $co['is_active'] === 1 || isset($with_policies[$co['name']])));
}

/** The company row for a stored name, or null. */
function insurance_company_find(mysqli $conn, string $name): ?array {
    foreach (insurance_companies_all($conn) as $co) {
        if ($co['name'] === $name) return $co;
    }
    return null;
}

/** The short label for a stored name ("Alpha Insurance"); the name itself for a company no longer on the list. */
function insurance_company_label(mysqli $conn, string $name): string {
    return insurance_company_find($conn, $name)['short_name'] ?? $name;
}

/** The "Billed To" name for a stored name ("PhilBritish Insurance Corp."). */
function insurance_company_billing_name(mysqli $conn, string $name): string {
    return insurance_company_find($conn, $name)['billing_name'] ?? $name;
}

/**
 * The active companies as one phrase for page text: "PhilBritish and Alpha Insurance",
 * "A, B and C", or with $joiner 'or'. $field is 'short_name' or 'billing_name'.
 */
function insurance_companies_phrase(mysqli $conn, string $field = 'short_name', string $joiner = 'and'): string {
    $names = array_column(insurance_companies_active($conn), $field);
    if (count($names) <= 1) return $names[0] ?? '';
    $last = array_pop($names);
    return implode(', ', $names) . " $joiner " . $last;
}

// ── MANAGING THE LIST (Settings > Insurance Companies, super admin only) ─────────────

const MAX_COMPANY_NAME    = 100;   // insurance_companies.name / insurance_policies.insurance_company
const MAX_COMPANY_SHORT   = 40;
const MAX_COMPANY_BILLING = 150;

/** True once the insurance_companies table exists on this server. */
function insurance_companies_table_ready(mysqli $conn): bool {
    return insurance_companies_table_exists($conn);
}

/**
 * Adds a company ($id 0) or edits one. Renaming also moves that company's existing policies to the new name,
 * in the same transaction, so none of them is left under a name that is no longer on the list.
 * Returns '' on success, or the error to show.
 */
function insurance_company_save(mysqli $conn, int $user_id, int $id, array $in): string {
    if (!insurance_companies_table_ready($conn)) return 'The insurance companies table is not set up on this server yet.';
    $name    = trim(preg_replace('/\s+/', ' ', (string)($in['name'] ?? '')));
    $short   = trim(preg_replace('/\s+/', ' ', (string)($in['short_name'] ?? '')));
    $billing = trim(preg_replace('/\s+/', ' ', (string)($in['billing_name'] ?? '')));
    $order   = (string)($in['sort_order'] ?? '');
    if ($short === '')   $short = $name;
    if ($billing === '') $billing = $name;

    if ($name === '')                                return 'Company name is required.';
    if (mb_strlen($name) > MAX_COMPANY_NAME)        return 'Company name is too long (max ' . MAX_COMPANY_NAME . ' characters).';
    if (mb_strlen($short) > MAX_COMPANY_SHORT)      return 'Short label is too long (max ' . MAX_COMPANY_SHORT . ' characters).';
    if (mb_strlen($billing) > MAX_COMPANY_BILLING)  return 'Billing name is too long (max ' . MAX_COMPANY_BILLING . ' characters).';
    if ($order !== '' && !preg_match('/^\d{1,3}$/', $order)) return 'Order must be a whole number from 0 to 999.';

    try {
        return db_transaction($conn, function () use ($conn, $user_id, $id, $name, $short, $billing, $order) {
            $old = null;
            if ($id > 0) {
                $old = insurance_company_lock($conn, $id);
                if (!$old) return 'That company no longer exists. Refresh the page.';
            }
            if (insurance_company_name_taken($conn, $name, $id)) return 'There is already a company named "' . $name . '".';

            if ($id === 0) {
                insurance_company_insert($conn, $name, $short, $billing, $order !== '' ? (int)$order : insurance_company_next_sort($conn));
                $log = "Added insurance company: $name";
                $action = 'INSURANCE_COMPANY_ADDED';
            } else {
                insurance_company_update($conn, $id, $name, $short, $billing, $order !== '' ? (int)$order : null);
                $log = "Updated insurance company: $name";
                if ($old['name'] !== $name) {
                    $moved = policies_rename_company($conn, $old['name'], $name);
                    $log = "Renamed insurance company: {$old['name']} -> $name ($moved policies moved)";
                }
                $action = 'INSURANCE_COMPANY_UPDATED';
            }
            audit_log_add($conn, $user_id, $action, $log);
            return '';
        });
    } catch (mysqli_sql_exception $e) {
        error_log('insurance_company_save: ' . $e->getMessage());
        return str_contains($e->getMessage(), 'Duplicate entry') ? 'There is already a company named "' . $name . '".' : 'Could not save the company. Please try again.';
    }
}

/** Turns a company on or off for new policies. The last active company cannot be turned off. '' on success. */
function insurance_company_set_active(mysqli $conn, int $user_id, int $id, bool $active): string {
    if (!insurance_companies_table_ready($conn)) return 'The insurance companies table is not set up on this server yet.';
    try {
        return db_transaction($conn, function () use ($conn, $user_id, $id, $active) {
            $co = insurance_company_lock($conn, $id);
            if (!$co) return 'That company no longer exists. Refresh the page.';
            if ((int)$co['is_active'] === (int)$active) return '';
            if (!$active && insurance_companies_other_active($conn, $id) === 0) return 'At least one insurance company must stay active.';
            insurance_company_set_flag($conn, $id, $active);
            $action = $active ? 'INSURANCE_COMPANY_ACTIVATED' : 'INSURANCE_COMPANY_DEACTIVATED';
            $log = ($active ? 'Activated' : 'Deactivated') . ' insurance company: ' . $co['name'];
            audit_log_add($conn, $user_id, $action, $log);
            return '';
        });
    } catch (mysqli_sql_exception $e) {
        error_log('insurance_company_set_active: ' . $e->getMessage());
        return 'Could not update the company. Please try again.';
    }
}
