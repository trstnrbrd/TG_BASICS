<?php
/**
 * Saves several rows as one unit: all of them are saved, or none.
 * If any query fails, everything is rolled back.
 *
 *   db_transaction($conn, function () use ($conn) {
 *       // INSERT the record, INSERT the audit log ...
 *   });
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
