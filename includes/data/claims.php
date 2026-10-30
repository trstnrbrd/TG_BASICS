<?php
/* =========================================================================
 * Claims
 * ========================================================================= */

/** The status code of a claim, or null if there is no such claim. */
function claim_status(mysqli $conn, int $claim_id): ?string
{
    $s = dal_value($conn, "SELECT status FROM claims WHERE claim_id = ?", 'i', [$claim_id]);
    return $s === null ? null : (string)$s;
}
