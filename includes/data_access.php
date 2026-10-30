<?php
/**
 * Data Access Layer: every query of the system lives in includes/data/, one
 * file per module (audit.php, users.php, clients.php, policies.php,
 * billing.php, ...). Pages call these functions by name and never write SQL
 * themselves; this file loads them all, so a page needs only this one
 * require.
 *
 * Every query there is a prepared statement (values are bound, never joined
 * into the SQL), which is what protects against SQL injection. The host
 * (InfinityFree) does not allow CREATE PROCEDURE, so these functions stand in
 * for database stored procedures; if the system moves to a host that does,
 * only the body of each function needs to change to a CALL.
 *
 * The functions do not begin or commit transactions. A save that writes
 * more than one row still wraps its calls in db_transaction().
 */
require_once __DIR__ . '/transaction.php';
require_once __DIR__ . '/data/_core.php';
foreach (glob(__DIR__ . '/data/*.php') as $__dal_file) {
    require_once $__dal_file;
}
unset($__dal_file);
