<?php
// The three billing lists are data, not code (panel revision), all changed by hand in phpMyAdmin:
//   billing_statuses       - Draft / Sent / Paid / Unpaid ...  (billing.status stores the `code`)
//   billing_document_types - the Required Documents; which ones a billing has received is in billing_documents
//   billing_signatories    - who signs as "Authorized Representative / Cashier"; the chosen name and title are
//                            copied onto the billing (signed_by_name / signed_by_title) so an old statement never
//                            changes when the list does. is_default = 1 is pre-selected on a new billing.
// is_active = 0 stops an entry being offered for new choices; billings that already use it keep showing it.
// Nothing here falls back to a hardcoded list: until the SQL is run the lists are simply empty.
// The one code with behaviour is BILLING_STATUS_SENT: moving a billing to it stamps sent_at.

const BILLING_STATUS_SENT = 'sent';

/** Runs a read-only query and returns all rows, or [] when the table is not there yet. */
function billing_list_query(mysqli $conn, string $sql): array {
    try {
        $res = $conn->query($sql);
        return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    } catch (mysqli_sql_exception $e) {
        return [];
    }
}

// ── STATUSES ──

/** Every billing status, active or not, in display order. Cached for the request. */
function billing_statuses_all(mysqli $conn): array {
    static $cache = null;
    return $cache ??= billing_list_query($conn, "SELECT code, label, badge_class, icon, description, is_active
                                                 FROM billing_statuses ORDER BY sort_order, label");
}

/** The statuses a billing can be moved to. */
function billing_statuses_active(mysqli $conn): array {
    return array_values(array_filter(billing_statuses_all($conn), fn($s) => (int)$s['is_active'] === 1));
}

/**
 * Label, badge class, icon and description of a stored status code. badge_class and icon are typed in phpMyAdmin,
 * so only a plain "badge-<word>" class and a [a-z-] icon name are used; anything else falls back to a neutral one.
 */
function billing_status_badge(mysqli $conn, string $code): array {
    foreach (billing_statuses_all($conn) as $s) {
        if ($s['code'] !== $code) continue;
        return [
            'label' => $s['label'],
            'class' => preg_match('/^badge-[a-z]+$/', $s['badge_class']) ? $s['badge_class'] : 'badge-gray',
            'icon'  => preg_match('/^[a-z-]+$/', $s['icon']) ? $s['icon'] : 'document-text',
            'desc'  => (string)$s['description'],
        ];
    }
    return ['label' => ucfirst($code), 'class' => 'badge-gray', 'icon' => 'document-text', 'desc' => ''];
}

// ── REQUIRED DOCUMENTS ──

/** Every document type, active or not, in display order. Cached for the request. */
function billing_doc_types_all(mysqli $conn): array {
    static $cache = null;
    return $cache ??= billing_list_query($conn, "SELECT doc_type_id, name, hint, is_active
                                                 FROM billing_document_types ORDER BY sort_order, name");
}

/** The doc_type_ids a set of billings have received: [billing_id => [doc_type_id => true]]. */
function billing_docs_received(mysqli $conn, array $billing_ids): array {
    $ids = implode(',', array_map('intval', $billing_ids)) ?: '0';
    $out = [];
    foreach (billing_list_query($conn, "SELECT billing_id, doc_type_id FROM billing_documents WHERE billing_id IN ($ids)") as $r) {
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
    return $cache ??= billing_list_query($conn, "SELECT signatory_id, name, title, is_default FROM billing_signatories
                                                 WHERE is_active = 1 ORDER BY is_default DESC, sort_order, name");
}

/** The signatory picked in a form (by id), or null if the id is not an active one. */
function billing_signatory_pick(mysqli $conn, $id): ?array {
    foreach (billing_signatories_active($conn) as $s) {
        if ((int)$s['signatory_id'] === (int)$id) return $s;
    }
    return null;
}
