<?php
// The banks / financing companies in the "Mortgagee / Financed By" dropdown (Add Policy, Edit Policy) come from
// the `mortgagees` table, not a hardcoded list (panel revision). Rows are added and changed by hand in
// phpMyAdmin; is_active = 0 hides one from the dropdown without touching the policies that already name it.
// "Other" (type any name) is not a row: the pages always add it last.

/** The active mortgagee names, in display order. */
function mortgagees_active(mysqli $conn): array {
    try {
        $res = $conn->query("SELECT name FROM mortgagees WHERE is_active = 1 ORDER BY sort_order, name");
        if ($res) return array_column($res->fetch_all(), 0);
    } catch (mysqli_sql_exception $e) {
    }
    return [];   // table not set up yet: only "None / Cash" and "Other" until the SQL is run
}
