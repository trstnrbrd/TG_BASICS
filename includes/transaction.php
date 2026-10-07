<?php
/**
 * includes/transaction.php — run several writes as one unit.
 *
 * A save that changes more than one table (a job and its checklist, a claim and its audit entry) must either land
 * completely or not at all. If the second write fails after the first one was saved, the records are left half
 * written. db_transaction() wraps the writes: commit when $work() finishes, roll back and rethrow when it fails.
 *
 * Usage:
 *   $id = db_transaction($conn, function () use ($conn) {
 *       $conn->query("INSERT ...");
 *       $conn->query("INSERT INTO audit_logs ...");
 *       return $conn->insert_id;
 *   });
 * The exception is rethrown, so the caller's existing catch (mysqli_sql_exception $e) keeps working.
 */
function db_transaction(mysqli $conn, callable $work): mixed
{
    $conn->begin_transaction();
    try {
        $result = $work();
        $conn->commit();
        return $result;
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}
