<?php
/**
 * Transaction PIN check, done on the server (not just in the browser).
 * After a correct PIN, the user has 2 minutes to do ONE protected action (delete, deactivate, ...).
 *
 *   ajax/verify_pin.php       correct PIN -> pin_grant()
 *   delete / deactivate page  if (!pin_consume($conn)) refuse with PIN_REQUIRED_MSG
 */

require_once __DIR__ . '/../includes/data_access.php';

const PIN_GRANT_SECONDS = 120;
const PIN_REQUIRED_MSG  = 'Please enter your Transaction PIN to confirm this action.';

/** Correct PIN was entered: allow the next action. */
function pin_grant(): void
{
    $_SESSION['pin_ok_until'] = time() + PIN_GRANT_SECONDS;
}

/** Can the user do a PIN-protected action now? True if no PIN is set, or the PIN was just entered. Uses up the grant. */
function pin_consume(mysqli $conn): bool
{
    $ok = (int)($_SESSION['pin_ok_until'] ?? 0) >= time();
    unset($_SESSION['pin_ok_until']);

    return account_pin_hash($conn, (int)($_SESSION['user_id'] ?? 0)) === null || $ok;   // no PIN set = nothing to check
}
