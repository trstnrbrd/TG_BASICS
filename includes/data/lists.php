<?php
/* =========================================================================
 * Lists kept as data (changed in phpMyAdmin): statuses, claim types,
 * service types, mortgagees, testimonials, billing documents and
 * signatories. Each returns [] while its table does not exist yet, so a
 * server where the SQL has not been run simply shows empty lists. The
 * helpers in includes/*.php cache them per request and clean the values.
 * ========================================================================= */

/** The rows of one module's statuses ('billing', 'claim', 'repair', 'quotation', 'receipt'), in order. */
function statuses_rows(mysqli $conn, string $module): array
{
    return dal_all_or_empty($conn, "SELECT * FROM statuses WHERE module = ? ORDER BY sort_order, label", 's', [$module]);
}

/** Every claim type, active or not, in order. */
function claim_types_rows(mysqli $conn): array
{
    return dal_all_or_empty($conn, "SELECT code, label, badge_class, is_active FROM claim_types ORDER BY sort_order, label");
}

/** Every repair service type, active or not, in order. */
function service_types_rows(mysqli $conn): array
{
    return dal_all_or_empty($conn, "SELECT code, label, group_label, default_price, is_active FROM service_types ORDER BY sort_order, label");
}

/** The active mortgagee (bank) names, in order. */
function mortgagees_rows(mysqli $conn): array
{
    return array_column(dal_all_or_empty($conn, "SELECT name FROM mortgagees WHERE is_active = 1 ORDER BY sort_order, name"), 'name');
}

/** The visible testimonials for the landing page, in order. Throws if the table is missing (the caller logs it). */
function testimonials_rows(mysqli $conn): array
{
    return dal_all($conn, "SELECT client_name, source, review_year, rating, quote FROM testimonials
                           WHERE is_visible = 1 ORDER BY sort_order, testimonial_id");
}

/** Every billing Required Document type, active or not, in order. */
function billing_document_types_rows(mysqli $conn): array
{
    return dal_all_or_empty($conn, "SELECT doc_type_id, name, hint, is_active FROM billing_document_types ORDER BY sort_order, name");
}

/** The [billing_id, doc_type_id] pairs received by the given billings. */
function billing_documents_rows(mysqli $conn, array $billing_ids): array
{
    $ids = array_values(array_map('intval', $billing_ids));
    if (!$ids) return [];
    return dal_all_or_empty($conn, "SELECT billing_id, doc_type_id FROM billing_documents WHERE billing_id IN (" . dal_in($ids) . ")",
                            str_repeat('i', count($ids)), $ids);
}

/** The active billing signatories, the default first. */
function billing_signatories_rows(mysqli $conn): array
{
    return dal_all_or_empty($conn, "SELECT signatory_id, name, title, is_default FROM billing_signatories
                                    WHERE is_active = 1 ORDER BY is_default DESC, sort_order, name");
}

/** Users who are the insurance agent of at least one active client [user_id, name], by name (developer's masked). */
function agents_holding_clients(mysqli $conn): array
{
    return dal_all($conn, "SELECT DISTINCT u.user_id, CASE WHEN u.is_hidden = 1 THEN 'Developer' ELSE u.full_name END AS name
                           FROM clients c INNER JOIN users u ON u.user_id = c.agent_id
                           WHERE c.deleted_at IS NULL ORDER BY name");
}
