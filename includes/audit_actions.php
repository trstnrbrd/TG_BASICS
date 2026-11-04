<?php
/**
 * includes/audit_actions.php
 * How each Activity Log action looks: one tone and one icon per action. The Activity Log badge, its
 * Excel export row colour and the dashboard's Recent Activity dot all come from the tone, so the
 * three can no longer drift apart (they were three separate hand-typed lists until 2026-11-04).
 *
 * Kept in code, not a table: only the code writes these action names, so a new action always comes
 * with a code change anyway (same reason as THEMES / CLAIM_STATUS_FLOW). A new audit_log_add()
 * action belongs here; tests/server/verify_audit_actions.php fails when one is missing.
 * An action not listed (an old row) shows gray with the clipboard icon.
 */

/** action => [tone, icon name for icon()] */
const AUDIT_ACTIONS = [
    // Sign-in and accounts
    'LOGIN'                  => ['green',  'arrow-right-on-rectangle'],
    'LOGOUT'                 => ['gray',   'arrow-left-on-rectangle'],
    'ACCOUNT_CREATED'        => ['gold',   'user-plus'],
    'ACCOUNT_DELETED'        => ['red',    'trash'],
    'ACCOUNT_DEACTIVATED'    => ['gray',   'x-mark'],
    'ACCOUNT_REACTIVATED'    => ['green',  'arrow-path'],
    'PROFILE_UPDATED'        => ['yellow', 'pencil'],
    'PASSWORD_CHANGED'       => ['yellow', 'lock-closed'],
    'PASSWORD_RESET'         => ['yellow', 'lock-closed'],
    'EMAIL_VERIFIED'         => ['green',  'envelope'],
    'SECURITY_UPDATED'       => ['yellow', 'lock-closed'],
    'TOTP_ENABLED'           => ['green',  'shield-check'],
    'TOTP_DISABLED'          => ['gray',   'shield-check'],
    'SETTINGS_UPDATED'       => ['yellow', 'cog'],
    // Insurance companies (Settings)
    'INSURANCE_COMPANY_ADDED'       => ['gold',   'shield-check'],
    'INSURANCE_COMPANY_UPDATED'     => ['yellow', 'pencil'],
    'INSURANCE_COMPANY_ACTIVATED'   => ['green',  'check-circle'],
    'INSURANCE_COMPANY_DEACTIVATED' => ['gray',   'x-mark'],
    // Clients
    'CLIENT_ADDED'           => ['green',  'user-plus'],
    'CLIENT_UPDATED'         => ['yellow', 'pencil'],
    'CLIENT_DELETED'         => ['red',    'trash'],
    'CLIENT_RESTORED'        => ['green',  'arrow-path'],
    'CLIENT_PURGED'          => ['red',    'trash'],
    'CLIENT_IMPORTED'        => ['green',  'arrow-up-tray'],
    // Vehicles
    'VEHICLE_ADDED'          => ['green',  'plus'],
    'VEHICLE_UPDATED'        => ['yellow', 'pencil'],
    'VEHICLE_UNREGISTERED'   => ['red',    'trash'],
    // Policies
    'POLICY_CREATED'         => ['gold',   'shield-check'],
    'POLICY_RENEWED'         => ['green',  'shield-check'],
    'POLICY_UPDATED'         => ['yellow', 'pencil'],
    'POLICY_DELETED'         => ['red',    'trash'],
    'PAYMENT_RECORDED'       => ['green',  'banknotes'],
    'PAYMENT_UNDONE'         => ['red',    'arrow-path'],
    'RECEIPT_UPLOADED'       => ['yellow', 'arrow-up-tray'],
    // Claims
    'CLAIM_FILED'            => ['gold',   'clipboard-list'],
    'CLAIM_UPDATED'          => ['yellow', 'clipboard-list'],
    'CLAIM_DELETED'          => ['red',    'trash'],
    // Repairs
    'REPAIR_JOB_CREATED'     => ['gold',   'wrench'],
    'REPAIR_STATUS_UPDATED'  => ['yellow', 'wrench'],
    'REPAIR_SIGNED'          => ['green',  'check-circle'],
    'REPAIR_JOB_DELETED'     => ['red',    'trash'],
    // Billing
    'BILLING_CREATED'        => ['gold',   'receipt'],
    'BILLING_UPDATED'        => ['yellow', 'receipt'],
    'BILLING_STATUS_UPDATED' => ['yellow', 'receipt'],
    'BILLING_DOCS_UPDATED'   => ['yellow', 'document'],
    'BILLING_DELETED'        => ['red',    'trash'],
    // Quotations and receipts
    'QUOTATION_CREATED'      => ['gold',   'receipt'],
    'QUOTATION_UPDATED'      => ['yellow', 'receipt'],
    'QUOTATION_APPROVED'     => ['green',  'check-circle'],
    'QUOTATION_DELETED'      => ['red',    'trash'],
    'RECEIPT_CREATED'        => ['green',  'receipt'],
    // Backups and scheduled jobs (shown as "System": AUDIT_SYSTEM_ACTIONS in includes/data/audit.php)
    'DATABASE_BACKUP'        => ['blue',   'arrow-down-tray'],
    'RENEWAL_REMINDERS_SENT' => ['blue',   'envelope'],
    'OVERDUE_REMINDERS_SENT' => ['blue',   'envelope'],
    'CLIENTS_PURGED'         => ['gray',   'trash'],
    'QUOTATIONS_PURGED'      => ['gray',   'trash'],
    'SIGN_IN_LOGS_PURGED'    => ['gray',   'trash'],
];

/** tone => [badge class, Excel export row colour, dashboard dot colour] */
const AUDIT_TONES = [
    'green'  => ['badge-green',  '#e8f5e9', 'var(--success)'],
    'yellow' => ['badge-yellow', '#fff8e1', 'var(--warning)'],
    'gold'   => ['badge-gold',   '#fff8e1', 'var(--gold-bright)'],
    'red'    => ['badge-red',    '#ffebee', 'var(--danger)'],
    'gray'   => ['badge-gray',   '#f0f0f0', 'var(--text-muted)'],
    'blue'   => ['badge-blue',   '#e3f2fd', 'var(--info)'],
];

/**
 * How one action looks: ['badge' => class, 'icon' => name, 'row' => export colour or null,
 * 'dot' => dashboard dot colour]. An unknown action: gray badge, clipboard icon, no row colour
 * (the export stripes it), border-coloured dot.
 */
function audit_action_style(string $action): array
{
    if (!isset(AUDIT_ACTIONS[$action])) {
        return ['badge' => 'badge-gray', 'icon' => 'clipboard-list', 'row' => null, 'dot' => 'var(--border)'];
    }
    [$tone, $icon] = AUDIT_ACTIONS[$action];
    [$badge, $row, $dot] = AUDIT_TONES[$tone];
    return ['badge' => $badge, 'icon' => $icon, 'row' => $row, 'dot' => $dot];
}
