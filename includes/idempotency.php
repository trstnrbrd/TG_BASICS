<?php
/**
 * The problem: a double click, a slow connection that sends the form again, or Back + Submit after a save would
 * file the same claim / repair job / photos a second time. Forms with a natural key (plate number, policy number,
 * one billing per claim, one quotation per job) already refuse the copy, but then show "already exists" for a save
 * that actually worked; forms without one (a claim, a repair job, an upload) just saved it twice.
 *
 * How it works:
 *   - the form carries a one-time random key:        <?= csrf_field() ?><?= idem_field() ?>
 *   - right after csrf_verify(), the handler calls:   idem_replay();
 *   - on success, instead of header('Location: ...'): idem_redirect($url);
 * idem_redirect() remembers, in the session, the key + a fingerprint of what was sent + where the save went.
 * The same key sent again with the same data is not saved again: idem_replay() sends the user to that same page
 * (with the same "saved" message), exactly as if this were the first reply. Same key but different data (Back,
 * the form was changed, Submit) is a new request and is saved normally.
 *
 * No table is needed: PHP's default session handler (files) locks the session for the whole request, so two
 * requests from one browser run one after the other and the second one sees the key the first one stored.
 * Two different people saving at the same moment are not a "double submit"; that case is covered by the
 * duplicate checks and locks on the natural keys.
 */

const IDEM_FIELD = '_idem';
const IDEM_KEEP  = 30;       // keys remembered per session; the oldest are dropped
const IDEM_TTL   = 86400;    // and none older than a day

/** Hidden input with a fresh one-time key. Put it next to csrf_field() in a form that creates something. */
function idem_field(): string
{
    return '<input type="hidden" name="' . IDEM_FIELD . '" value="' . bin2hex(random_bytes(16)) . '"/>';
}

/** The key this request carries, or null (none sent, or not a valid key). */
function idem_key(): ?string
{
    $k = $_POST[IDEM_FIELD] ?? null;
    return is_string($k) && preg_match('/^[a-f0-9]{32}$/', $k) ? $k : null;
}

/**
 * A fingerprint of what was sent: the page, the fields (minus the tokens) and the uploaded files' names. Names
 * only, not sizes: photos are shrunk in the browser before upload (image_compress.js), and shrinking the same
 * photo a second time can give a slightly different size.
 */
function idem_fingerprint(): string
{
    $post = $_POST;
    unset($post[IDEM_FIELD], $post['csrf_token'], $post['_live_tab']);
    $files = [];
    foreach ($_FILES as $field => $f) $files[$field] = $f['name'] ?? null;
    $sort = function ($v) use (&$sort) {
        if (!is_array($v)) return $v;
        ksort($v);
        return array_map($sort, $v);
    };
    return hash('sha256', ($_SERVER['SCRIPT_NAME'] ?? '') . "\0" . json_encode($sort($post)) . "\0" . json_encode($sort($files)));
}

/** Drops expired keys and keeps only the newest IDEM_KEEP. */
function idem_prune(): void
{
    if (!isset($_SESSION['_idem']) || !is_array($_SESSION['_idem'])) { $_SESSION['_idem'] = []; return; }
    $now = time();
    $_SESSION['_idem'] = array_filter($_SESSION['_idem'], fn($d) => is_array($d) && ($d['t'] ?? 0) > $now - IDEM_TTL);
    if (count($_SESSION['_idem']) > IDEM_KEEP) {
        uasort($_SESSION['_idem'], fn($a, $b) => $a['t'] <=> $b['t']);
        $_SESSION['_idem'] = array_slice($_SESSION['_idem'], -IDEM_KEEP, null, true);
    }
}

/**
 * Call right after csrf_verify(). When this exact request (same key, same data) was already saved, sends the
 * user to the page the first save went to and stops; otherwise does nothing.
 */
function idem_replay(): void
{
    $k = idem_key();
    if ($k === null) return;
    idem_prune();
    $done = $_SESSION['_idem'][$k] ?? null;
    if ($done && hash_equals($done['h'], idem_fingerprint())) {
        $GLOBALS['tg_idem_replay'] = true;   // nothing new was saved: no live-update signal (includes/realtime.php)
        header('Location: ' . $done['u']);
        exit;
    }
}

/** Use after a successful save instead of header('Location: ...'); exit; — remembers the key, then redirects. */
function idem_redirect(string $url): void
{
    $k = idem_key();
    if ($k !== null) {
        idem_prune();
        $_SESSION['_idem'][$k] = ['h' => idem_fingerprint(), 'u' => $url, 't' => time()];
    }
    header('Location: ' . $url);
    exit;
}
