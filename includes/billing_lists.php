<?php
// The three billing lists are data, not code (panel revision), all changed by hand in phpMyAdmin:
//   statuses (module 'billing') - Draft / Sent / Paid / Unpaid ... (billing.status stores the `code`)
//   billing_document_types - the Required Documents; which ones a billing has received is in billing_documents
//   billing_signatories    - who signs as "Authorized Representative / Cashier"; the chosen name and title are
//                            copied onto the billing (signed_by_name / signed_by_title) so an old statement never
//                            changes when the list does. is_default = 1 is pre-selected on a new billing.
// is_active = 0 stops an entry being offered for new choices; billings that already use it keep showing it.
// Nothing here falls back to a hardcoded list: until the SQL is run the lists are simply empty.
require_once __DIR__ . '/statuses.php';
// The one code with behaviour is BILLING_STATUS_SENT: moving a billing to it stamps sent_at.

const BILLING_STATUS_SENT = 'sent';

require_once __DIR__ . '/data_access.php';   // the queries: includes/data/lists.php ([] before the SQL is run)

// ── STATUSES ── (the shared `statuses` table, module 'billing'; includes/statuses.php)

/** Every billing status, active or not, in display order (rows carry 'code' and 'is_active'). */
function billing_statuses_all(mysqli $conn): array {
    return array_values(statuses_for('billing'));
}

/** The statuses a billing can be moved to. */
function billing_statuses_active(mysqli $conn): array {
    return array_values(statuses_active('billing'));
}

/** Label, badge class, icon and description of a stored status code. */
function billing_status_badge(mysqli $conn, string $code): array {
    return status_badge('billing', $code);
}

// ── REQUIRED DOCUMENTS ──

/** Every document type, active or not, in display order. Cached for the request. */
function billing_doc_types_all(mysqli $conn): array {
    static $cache = null;
    return $cache ??= billing_document_types_rows($conn);
}

/** The doc_type_ids a set of billings have received: [billing_id => [doc_type_id => true]]. */
function billing_docs_received(mysqli $conn, array $billing_ids): array {
    $out = [];
    foreach (billing_documents_rows($conn, $billing_ids) as $r) {
        $out[(int)$r['billing_id']][(int)$r['doc_type_id']] = true;
    }
    return $out;
}

/**
 * The documents to show for one billing: every active type, plus an inactive one it already received (so a
 * retired document is not silently dropped from an old record). Each row gets 'received' => bool.
 */
function billing_docs_for(mysqli $conn, array $received): array {
    $list = [];
    foreach (billing_doc_types_all($conn) as $t) {
        $got = isset($received[(int)$t['doc_type_id']]);
        if ((int)$t['is_active'] !== 1 && !$got) continue;
        $list[] = $t + ['received' => $got];
    }
    return $list;
}

// ── SIGNATORIES ──

/** The people who can sign as cashier, default first. */
function billing_signatories_active(mysqli $conn): array {
    static $cache = null;
    return $cache ??= billing_signatories_rows($conn);
}

/** The signatory picked in a form (by id), or null if the id is not an active one. */
function billing_signatory_pick(mysqli $conn, $id): ?array {
    foreach (billing_signatories_active($conn) as $s) {
        if ((int)$s['signatory_id'] === (int)$id) return $s;
    }
    return null;
}
