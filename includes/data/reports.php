<?php
/* =========================================================================
 * Dashboard and Monthly Report: counts and chart figures
 * ========================================================================= */

/**
 * The stat cards: clients and vehicles (all and added this month) and
 * policies (current, i.e. not renewed, and of those the ones not expired).
 */
function dashboard_counts(mysqli $conn): array
{
    $month = "YEAR(%s) = YEAR(NOW()) AND MONTH(%s) = MONTH(NOW())";
    return [
        'total_clients'   => (int)dal_value($conn, "SELECT COUNT(*) FROM clients WHERE deleted_at IS NULL"),
        'total_vehicles'  => (int)dal_value($conn, "SELECT COUNT(*) FROM vehicles v INNER JOIN clients c ON v.client_id = c.client_id WHERE c.deleted_at IS NULL"),
        'recent_clients'  => (int)dal_value($conn, "SELECT COUNT(*) FROM clients WHERE deleted_at IS NULL AND " . sprintf($month, 'created_at', 'created_at')),
        'recent_vehicles' => (int)dal_value($conn, "SELECT COUNT(*) FROM vehicles v INNER JOIN clients c ON v.client_id = c.client_id
                                                    WHERE c.deleted_at IS NULL AND " . sprintf($month, 'v.created_at', 'v.created_at')),
        'total_policies'  => (int)dal_value($conn, "SELECT COUNT(*) FROM insurance_policies WHERE is_renewed = 0"),
        'active_policies' => (int)dal_value($conn, "SELECT COUNT(*) FROM insurance_policies WHERE is_renewed = 0 AND policy_end >= CURDATE()"),
    ];
}

/**
 * New clients of one month, split into insurance clients (with a policy) and
 * walk-ins (none). Stats rule: a deleted client still counts unless it was
 * deleted in the same month it was added. Returns [insurance, walkin].
 */
function report_client_types_in_month(mysqli $conn, int $year, int $month): array
{
    $kept = "(c.deleted_at IS NULL OR YEAR(c.deleted_at) != YEAR(c.created_at) OR MONTH(c.deleted_at) != MONTH(c.created_at))";
    $ins = (int)dal_value($conn, "SELECT COUNT(DISTINCT c.client_id) FROM clients c INNER JOIN insurance_policies ip ON ip.client_id = c.client_id
                                  WHERE YEAR(c.created_at) = ? AND MONTH(c.created_at) = ? AND $kept", 'ii', [$year, $month]);
    $wk  = (int)dal_value($conn, "SELECT COUNT(*) FROM clients c LEFT JOIN insurance_policies ip ON ip.client_id = c.client_id
                                  WHERE ip.policy_id IS NULL AND YEAR(c.created_at) = ? AND MONTH(c.created_at) = ? AND $kept", 'ii', [$year, $month]);
    return [$ins, $wk];
}

/** The year the first client was added, or null when there are none (start of the report's year list). */
function report_first_year(mysqli $conn): ?int
{
    $y = dal_value($conn, "SELECT MIN(YEAR(created_at)) FROM clients");
    return $y === null ? null : (int)$y;
}

/** Policies filed in one month. */
function report_policies_in_month(mysqli $conn, int $year, int $month): int
{
    return (int)dal_value($conn, "SELECT COUNT(*) FROM insurance_policies WHERE YEAR(created_at) = ? AND MONTH(created_at) = ?", 'ii', [$year, $month]);
}

/** Repair jobs opened in one month. */
function report_repairs_in_month(mysqli $conn, int $year, int $month): int
{
    return (int)dal_value($conn, "SELECT COUNT(*) FROM repair_jobs WHERE YEAR(created_at) = ? AND MONTH(created_at) = ?", 'ii', [$year, $month]);
}

/** How many policies have each payment status: ['Paid' => n, ...]. */
function report_payment_status_counts(mysqli $conn): array
{
    $out = [];
    foreach (dal_all($conn, "SELECT payment_status, COUNT(*) AS c FROM insurance_policies GROUP BY payment_status") as $r) {
        $out[$r['payment_status']] = (int)$r['c'];
    }
    return $out;
}
