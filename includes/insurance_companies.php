<?php
// The insurance companies the brokerage files policies under (panel revision: not hardcoded, so the owner can add
// one in Settings). They live in the `insurance_companies` table; a policy stores the company's `name`
// (insurance_policies.insurance_company), so that is the key everywhere. short_name labels the sidebar and the
// renewal switch, billing_name pre-fills "Billed To". A deactivated company is not offered for new policies but
// its existing policies still show and can still be tracked.
require_once __DIR__ . '/transaction.php';

// Used only until the table exists (servers where the SQL has not been run yet), so nothing breaks in between.
const INSURANCE_COMPANIES_FALLBACK = [
    ['company_id' => 0, 'name' => 'PhilBritish', 'short_name' => 'PhilBritish', 'billing_name' => 'PhilBritish Insurance Corp.', 'is_active' => 1, 'sort_order' => 1],
    ['company_id' => 0, 'name' => 'Alpha Insurance & Surety Company Inc.', 'short_name' => 'Alpha Insurance', 'billing_name' => 'Alpha Insurance & Surety Company Inc.', 'is_active' => 1, 'sort_order' => 2],
];

/** Every company, active or not, in display order. Cached for the request. */
function insurance_companies_all(mysqli $conn): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $res = $conn->query("SELECT company_id, name, short_name, billing_name, is_active, sort_order
                             FROM insurance_companies ORDER BY sort_order, name");
        $cache = $res ? $res->fetch_all(MYSQLI_ASSOC) : INSURANCE_COMPANIES_FALLBACK;
    } catch (mysqli_sql_exception $e) {
        $cache = INSURANCE_COMPANIES_FALLBACK;
    }
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
    $with_policies = [];
    try {
        $res = $conn->query("SELECT DISTINCT insurance_company FROM insurance_policies");
        if ($res) foreach ($res->fetch_all() as $r) $with_policies[$r[0]] = true;
    } catch (mysqli_sql_exception $e) {
    }
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
    try {
        return (bool)$conn->query("SHOW TABLES LIKE 'insurance_companies'")->num_rows;
    } catch (mysqli_sql_exception $e) {
        return false;
    }
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
                $st = $conn->prepare("SELECT name FROM insurance_companies WHERE company_id = ? FOR UPDATE");
                $st->bind_param('i', $id);
                $st->execute();
                $old = $st->get_result()->fetch_assoc();
                if (!$old) return 'That company no longer exists. Refresh the page.';
            }
            $dup = $conn->prepare("SELECT 1 FROM insurance_companies WHERE name = ? AND company_id <> ?");
            $dup->bind_param('si', $name, $id);
            $dup->execute();
            if ($dup->get_result()->num_rows) return 'There is already a company named "' . $name . '".';

            if ($id === 0) {
                $sort = $order !== '' ? (int)$order
                      : (int)$conn->query("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM insurance_companies")->fetch_row()[0];
                $ins = $conn->prepare("INSERT INTO insurance_companies (name, short_name, billing_name, sort_order) VALUES (?, ?, ?, ?)");
                $ins->bind_param('sssi', $name, $short, $billing, $sort);
                $ins->execute();
                $log = "Added insurance company: $name";
                $action = 'INSURANCE_COMPANY_ADDED';
            } else {
                $sort = $order !== '' ? (int)$order : null;
                $up = $conn->prepare("UPDATE insurance_companies SET name = ?, short_name = ?, billing_name = ?, sort_order = COALESCE(?, sort_order) WHERE company_id = ?");
                $up->bind_param('sssii', $name, $short, $billing, $sort, $id);
                $up->execute();
                $log = "Updated insurance company: $name";
                if ($old['name'] !== $name) {
                    $mv = $conn->prepare("UPDATE insurance_policies SET insurance_company = ? WHERE insurance_company = ?");
                    $mv->bind_param('ss', $name, $old['name']);
                    $mv->execute();
                    $log = "Renamed insurance company: {$old['name']} -> $name ({$mv->affected_rows} policies moved)";
                }
                $action = 'INSURANCE_COMPANY_UPDATED';
            }
            $al = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, ?, ?)");
            $al->bind_param('iss', $user_id, $action, $log);
            $al->execute();
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
            $st = $conn->prepare("SELECT name, is_active FROM insurance_companies WHERE company_id = ? FOR UPDATE");
            $st->bind_param('i', $id);
            $st->execute();
            $co = $st->get_result()->fetch_assoc();
            if (!$co) return 'That company no longer exists. Refresh the page.';
            if ((int)$co['is_active'] === (int)$active) return '';
            if (!$active) {
                $others = (int)$conn->query("SELECT COUNT(*) FROM insurance_companies WHERE is_active = 1 AND company_id <> " . (int)$id . " FOR UPDATE")->fetch_row()[0];
                if ($others === 0) return 'At least one insurance company must stay active.';
            }
            $flag = $active ? 1 : 0;
            $up = $conn->prepare("UPDATE insurance_companies SET is_active = ? WHERE company_id = ?");
            $up->bind_param('ii', $flag, $id);
            $up->execute();
            $action = $active ? 'INSURANCE_COMPANY_ACTIVATED' : 'INSURANCE_COMPANY_DEACTIVATED';
            $log = ($active ? 'Activated' : 'Deactivated') . ' insurance company: ' . $co['name'];
            $al = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, ?, ?)");
            $al->bind_param('iss', $user_id, $action, $log);
            $al->execute();
            return '';
        });
    } catch (mysqli_sql_exception $e) {
        error_log('insurance_company_set_active: ' . $e->getMessage());
        return 'Could not update the company. Please try again.';
    }
}
