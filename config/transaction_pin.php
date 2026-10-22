<?php
/**
 * config/transaction_pin.php — the server half of the Transaction PIN.
 *
 * The PIN prompt itself is in the browser (requirePin() in assets/js/shared/layout.js). On its own that only
 * stops someone using the page normally: a request sent straight to the delete handler never sees the prompt.
 * So a correct PIN (ajax/verify_pin.php) leaves a short-lived grant in the session, and every action behind the
 * prompt calls pin_consume() before doing anything. One grant covers one action.
 *
 *   ajax/verify_pin.php       correct PIN -> pin_grant()
 *   delete / deactivate page  if (!pin_consume($conn)) refuse with PIN_REQUIRED_MSG
 */

const PIN_GRANT_SECONDS = 120;   // time between entering the PIN and the form reaching the server
const PIN_REQUIRED_MSG  = 'Please enter your Transaction PIN to confirm this action.';

/** A correct PIN was just entered: the next PIN-protected action of this session may go through. */
function pin_grant(): void
{
    $_SESSION['pin_ok_until'] = time() + PIN_GRANT_SECONDS;
}

/**
 * May the signed-in user do a PIN-protected action now? True when the account has no PIN set, or when the PIN
 * was entered within the last PIN_GRANT_SECONDS. The grant is used up either way, so it covers one action only.
 */
function pin_consume(mysqli $conn): bool
{
    $ok = (int)($_SESSION['pin_ok_until'] ?? 0) >= time();
    unset($_SESSION['pin_ok_until']);

    $uid = (int)($_SESSION['user_id'] ?? 0);
    $st  = $conn->prepare("SELECT transaction_pin FROM users WHERE user_id = ?");
    $st->bind_param('i', $uid);
    $st->execute();
    $hash = $st->get_result()->fetch_row()[0] ?? null;

    return empty($hash) || $ok;
}
