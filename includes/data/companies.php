<?php
/* =========================================================================
 * Insurance companies (Settings > Insurance Companies). The rules and the
 * cached lists stay in includes/insurance_companies.php.
 * ========================================================================= */

/** Every company, active or not, in display order ([] while the table does not exist yet). */
function insurance_companies_rows(mysqli $conn): array
{
    return dal_all_or_empty($conn, "SELECT company_id, name, short_name, billing_name, is_active, sort_order FROM insurance_companies ORDER BY sort_order, name");
}

/** The company names that have at least one policy. */
function insurance_company_names_in_use(mysqli $conn): array
{
    return array_column(dal_all_or_empty($conn, "SELECT DISTINCT insurance_company FROM insurance_policies"), 'insurance_company');
}

/** Whether the insurance_companies table exists on this server. */
function insurance_companies_table_exists(mysqli $conn): bool
{
    try {
        return dal_value($conn, "SHOW TABLES LIKE 'insurance_companies'") !== null;
    } catch (mysqli_sql_exception $e) {
        return false;
    }
}

/** A company's name and active flag, locked until the transaction ends; or null. */
function insurance_company_lock(mysqli $conn, int $company_id): ?array
{
    return dal_one($conn, "SELECT name, is_active FROM insurance_companies WHERE company_id = ? FOR UPDATE", 'i', [$company_id]);
}

/** Whether another company already has this name. */
function insurance_company_name_taken(mysqli $conn, string $name, int $except_company_id = 0): bool
{
    return dal_value($conn, "SELECT 1 FROM insurance_companies WHERE name = ? AND company_id <> ? LIMIT 1", 'si', [$name, $except_company_id]) !== null;
}

/** The sort order that puts a new company last. */
function insurance_company_next_sort(mysqli $conn): int
{
    return (int)dal_value($conn, "SELECT COALESCE(MAX(sort_order), 0) + 1 FROM insurance_companies");
}

/** Adds a company (active). */
function insurance_company_insert(mysqli $conn, string $name, string $short_name, string $billing_name, int $sort_order): void
{
    dal_exec($conn, "INSERT INTO insurance_companies (name, short_name, billing_name, sort_order) VALUES (?, ?, ?, ?)",
             'sssi', [$name, $short_name, $billing_name, $sort_order]);
}

/** Edits a company; a null $sort_order keeps the current one. */
function insurance_company_update(mysqli $conn, int $company_id, string $name, string $short_name, string $billing_name, ?int $sort_order): void
{
    dal_exec($conn, "UPDATE insurance_companies SET name = ?, short_name = ?, billing_name = ?, sort_order = COALESCE(?, sort_order) WHERE company_id = ?",
             'sssii', [$name, $short_name, $billing_name, $sort_order, $company_id]);
}

/** How many other companies are active (locked, so two people cannot turn off the last two at once). */
function insurance_companies_other_active(mysqli $conn, int $company_id): int
{
    return (int)dal_value($conn, "SELECT COUNT(*) FROM insurance_companies WHERE is_active = 1 AND company_id <> ? FOR UPDATE", 'i', [$company_id]);
}

/** Turns a company on or off for new policies. */
function insurance_company_set_flag(mysqli $conn, int $company_id, bool $active): void
{
    dal_exec($conn, "UPDATE insurance_companies SET is_active = ? WHERE company_id = ?", 'ii', [$active ? 1 : 0, $company_id]);
}
