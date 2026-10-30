<?php
/* =========================================================================
 * Audit log
 * ========================================================================= */

/**
 * Records one entry in the activity log.
 * $user_id is null for entries written by a scheduled job (no one signed in).
 */
function audit_log_add(mysqli $conn, ?int $user_id, string $action, string $description): bool
{
    $stmt = $conn->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, ?, ?)");
    $stmt->bind_param('iss', $user_id, $action, $description);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/** The newest activity id (0 when none): live updates poll it, a bigger number means something was saved. */
function audit_log_latest_id(mysqli $conn): int
{
    return (int)dal_value($conn, "SELECT COALESCE(MAX(log_id), 0) FROM audit_logs");
}

/** SQL condition that hides the developer account's entries (they are kept, just never listed). */
const AUDIT_HIDE_DEVELOPER_SQL = "(a.user_id IS NULL OR a.user_id NOT IN (SELECT user_id FROM users WHERE is_hidden = 1))";

/**
 * WHERE parts for the Activity Log filters (developer's entries always
 * hidden). $f: user (id, 0 = all), action ('' = all), search ('' = none),
 * from / to (Y-m-d, '' = open). Returns [where list, types, params].
 */
function audit_log_where(array $f): array
{
    $where = [AUDIT_HIDE_DEVELOPER_SQL]; $types = ''; $params = [];
    if (($f['user'] ?? 0) > 0)       { $where[] = 'a.user_id = ?';                          $types .= 'i';  $params[] = (int)$f['user']; }
    if (($f['action'] ?? '') !== '') { $where[] = 'a.action = ?';                           $types .= 's';  $params[] = $f['action']; }
    if (($f['search'] ?? '') !== '') { $where[] = '(a.description LIKE ? OR a.action LIKE ?)'; $types .= 'ss'; array_push($params, "%{$f['search']}%", "%{$f['search']}%"); }
    if (($f['from'] ?? '') !== '')   { $where[] = 'a.created_at >= ?';                      $types .= 's';  $params[] = $f['from'] . ' 00:00:00'; }
    if (($f['to'] ?? '') !== '')     { $where[] = 'a.created_at <= ?';                      $types .= 's';  $params[] = $f['to'] . ' 23:59:59'; }
    return [$where, $types, $params];
}

/**
 * One page of the Activity Log: the last 7 days only (older entries go
 * through the export), newest first, with the filters of audit_log_where().
 * Returns [rows, total matching].
 */
function audit_log_page(mysqli $conn, array $f, int $page, int $per_page): array
{
    [$where, $types, $params] = audit_log_where($f);
    array_unshift($where, 'a.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)');
    $where_sql = 'WHERE ' . implode(' AND ', $where);
    $total = (int)dal_value($conn, "SELECT COUNT(*) FROM audit_logs a $where_sql", $types, $params);
    $rows  = dal_all($conn, "
        SELECT a.log_id, a.user_id, a.action, a.description, a.created_at,
               CASE WHEN u.is_hidden = 1 THEN 'Developer' ELSE u.full_name END AS full_name,
               CASE WHEN u.is_hidden = 1 THEN NULL ELSE u.profile_photo END AS profile_photo
        FROM audit_logs a
        LEFT JOIN users u ON a.user_id = u.user_id
        $where_sql
        ORDER BY a.created_at DESC
        LIMIT ? OFFSET ?", $types . 'ii', array_merge($params, [$per_page, ($page - 1) * $per_page]));
    return [$rows, $total];
}

/** Every entry for the Excel export, oldest first: the filters of audit_log_where(), with from / to required. */
function audit_log_export(mysqli $conn, array $f): array
{
    [$where, $types, $params] = audit_log_where($f);
    return dal_all($conn, "
        SELECT a.log_id, CASE WHEN u.is_hidden = 1 THEN 'Developer' ELSE u.full_name END AS full_name,
               a.action, a.description, a.created_at
        FROM audit_logs a
        LEFT JOIN users u ON a.user_id = u.user_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY a.created_at ASC", $types, $params);
}

/** Every action name in the log (for the filter dropdown). */
function audit_log_actions(mysqli $conn): array
{
    return array_column(dal_all($conn, "SELECT DISTINCT action FROM audit_logs ORDER BY action ASC"), 'action');
}

/** The newest $limit activity entries (developer's hidden), with the actor's display name. */
function audit_log_recent(mysqli $conn, int $limit): array
{
    return dal_all($conn, "
        SELECT a.action, a.description, a.created_at,
               CASE WHEN u.is_hidden = 1 THEN 'Developer' ELSE u.full_name END AS actor
        FROM audit_logs a
        LEFT JOIN users u ON a.user_id = u.user_id
        WHERE " . AUDIT_HIDE_DEVELOPER_SQL . "
        ORDER BY a.created_at DESC
        LIMIT ?", 'i', [$limit]);
}
