<?php
// Admin endpoint to anonymise or delete audit_log rows according to criteria.
// Accessible via POST only. Requires admin session (uses functions/check_role.php).

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/check_role.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../includes/crypto.php';
require_once __DIR__ . '/../includes/csrf.php';
// optional logger for debugging
$loggerPath = __DIR__ . '/../includes/logger.php'; if (file_exists($loggerPath)) require_once $loggerPath;

// Return JSON for all responses and convert PHP errors to JSON-friendly output so fetch() receives valid JSON
@ini_set('display_errors', '0');
error_reporting(E_ALL);
// Convert warnings/notices to exceptions so they are caught by our try/catch
set_error_handler(function($severity, $message, $file, $line) {
    // Respect error_reporting level
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
set_exception_handler(function($e){
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Exception: ' . $e->getMessage()]);
    exit;
});
register_shutdown_function(function(){
    $err = error_get_last();
    if ($err !== null) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Fatal error: ' . ($err['message'] ?? 'unknown')]);
        exit;
    }
});

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// check admin OR super_admin (admin UI is restricted to super_admin)
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
if (!is_admin() && !isset($_SESSION['role']) || (isset($_SESSION['role']) && $_SESSION['role'] !== 'super_admin' && !is_admin())) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

// Expect JSON body with: { criteria: { ids: [..] } | { older_than: 'YYYY-MM-DD' } | other }, dry_run: bool, note: string
$raw = file_get_contents('php://input');
if (empty($raw)) $raw = '{}';
$body = json_decode($raw, true);
// debug log incoming request body
if (function_exists('rota_log_rotate')) {
    try { rota_log_rotate(__DIR__ . '/../secure/logs', 'debug_erase_audit', 'Raw input: ' . substr($raw,0,2000)); } catch (Exception $e) {}
}
if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON body: ' . json_last_error_msg()]);
    exit;
}
if (!is_array($body)) $body = [];
$criteria = $body['criteria'] ?? [];
$dry = !empty($body['dry_run']);
$mode = $body['mode'] ?? 'anonymise'; // 'anonymise' or 'archive_delete'
$note = isset($body['note']) ? substr((string)$body['note'], 0, 255) : null;
$adminId = $_SESSION['user_id'] ?? null;
$allowAll = !empty($body['allow_all']);
// debug log computed allow_all and criteria keys
if (function_exists('rota_log_rotate')) {
    try { rota_log_rotate(__DIR__ . '/../secure/logs', 'debug_erase_audit', 'allow_all: ' . ($allowAll ? 'true' : 'false') . ' criteria keys: ' . implode(',', array_keys($body['criteria'] ?? []))); } catch (Exception $e) {}
}
// Checklist acknowledgements (booleans) sent from the client
$ack_backup = !empty($body['acknowledged_backup']) ? 1 : 0;
$ack_irrev = !empty($body['acknowledged_irrevocable']) ? 1 : 0;

// CSRF handling: we allow a CLI-trusted secret for local automation. For web requests
// we skip the session-backed single-use CSRF verification but enforce that the caller
// is an authenticated admin and that the request appears same-origin (Origin/Referer).
// This reduces cross-site risk while allowing some flexibility for automation.
$csrf = $body['csrf_token'] ?? ($_POST['csrf_token'] ?? '');
$cliSecret = getenv('SCHEDULE_CLI_SECRET') ?: ($_ENV['SCHEDULE_CLI_SECRET'] ?? null);
$isCliTrusted = false;
if ($cliSecret && !empty($body['cli_secret']) && hash_equals($cliSecret, $body['cli_secret'])) {
    // Only allow when request appears to originate from localhost
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($ip === '127.0.0.1' || $ip === '::1' || $ip === '') {
        $isCliTrusted = true;
    }
}

if (!$isCliTrusted) {
    // Require an authenticated admin session (either admin or super_admin)
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    $isAdminSession = false;
    if (function_exists('is_admin') && is_admin()) $isAdminSession = true;
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'super_admin') $isAdminSession = true;
    if (!$isAdminSession) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }

    // Soft same-origin check: ensure Origin or Referer host matches server host
    $origin = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
    $sameOrigin = false;
    if ($origin) {
        $originHost = parse_url($origin, PHP_URL_HOST);
        $serverHost = $_SERVER['SERVER_NAME'] ?? ($_SERVER['HTTP_HOST'] ?? '');
        if ($originHost && $serverHost && ($originHost === $serverHost)) {
            $sameOrigin = true;
        }
    }
    if (!$sameOrigin) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid request origin']);
        exit;
    }

    // Note: intentionally skipping verify_csrf_token() here. This file now relies on
    // authenticated admin session + same-origin checks to mitigate CSRF. This is less
    // strict than single-use session tokens; keep this comment as a security reminder.
}

// Build where clause from criteria (support ids array, older_than date, user_id)
$where = '1=1';
$params = [];
// Support criteria.user as id or partial username
if (!empty($criteria['user'])) {
    $u = (string)$criteria['user'];
    if (preg_match('/^\d+$/', $u)) {
        $where = "user_id = ?";
        $params = [intval($u)];
    } else {
        // find matching user ids by username partial match
        $like = '%' . $u . '%';
        $us = $conn->prepare("SELECT id FROM users WHERE username LIKE ?");
        $us->execute([$like]);
        $ids = $us->fetchAll(PDO::FETCH_COLUMN);
        if (empty($ids)) {
            // no matching users -> nothing to do (use 0=1 for count)
            $where = '0=1';
            $params = [];
        } else {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $where = "user_id IN ($placeholders)";
            $params = $ids;
        }
    }
} elseif (!empty($criteria['ids']) && is_array($criteria['ids'])) {
    // sanitize ints
    $ids = array_values(array_filter(array_map('intval', $criteria['ids']), function($v){ return $v>0; }));
    if (empty($ids)) {
        echo json_encode(['ok'=>false,'error'=>'No valid ids provided']); exit;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $where = "id IN ($placeholders)";
    $params = $ids;
} elseif (!empty($criteria['older_than'])) {
    $d = $criteria['older_than'];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        echo json_encode(['ok'=>false,'error'=>'Invalid date format']); exit;
    }
    $where = "created_at < ?";
    $params = [$d . ' 00:00:00'];
} elseif (!empty($criteria['user_id'])) {
    $uid = intval($criteria['user_id']);
    if ($uid <= 0) { echo json_encode(['ok'=>false,'error'=>'Invalid user_id']); exit; }
    $where = "user_id = ?";
    $params = [$uid];
} else {
    if (!$allowAll) {
        echo json_encode(['ok'=>false,'error'=>'No criteria provided']); exit;
    }
    // when allowAll is true, use a where that matches all rows
    $where = '1=1';
    $params = [];
}

try {
    // dry-run count
    $countSql = "SELECT COUNT(*) FROM audit_log WHERE $where";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($params);
    $count = (int)$countStmt->fetchColumn();

    if ($dry) {
        echo json_encode(['ok' => true, 'dry_run' => true, 'affected' => $count]);
        exit;
    }

    // perform anonymisation: set PII columns to NULL / remove sensitive keys from meta
    // Only start a transaction here for the anonymise path. The archive_delete path
    // uses archive_and_delete_by_criteria() which manages its own transaction.
    if ($mode !== 'archive_delete') {
        $conn->beginTransaction();
    }

    if ($mode === 'archive_delete') {
        require_once __DIR__ . '/../includes/audit_maintenance.php';
        // Call reusable function which handles dry-run check earlier; we already handled dry-run above
        $entered_code = isset($body['erase_code']) ? trim((string)$body['erase_code']) : '';
        $entered_pw = isset($body['admin_password']) ? $body['admin_password'] : '';
        $adminId = $_SESSION['user_id'] ?? null;
        // validate admin as before (reuse existing checks)
    if (empty($adminId)) { if ($conn->inTransaction()) $conn->rollBack(); echo json_encode(['ok'=>false,'error'=>'Admin session not found']); exit; }
        // Attempt erase_code auth first
        $okAuth = false;
            if ($entered_code !== '') {
            if (!preg_match('/^\d{6}$/', $entered_code)) { if ($conn->inTransaction()) $conn->rollBack(); echo json_encode(['ok'=>false,'error'=>'Erase code must be 6 digits']); exit; }
            $ecStmt = $conn->prepare('SELECT erase_code_hash FROM users WHERE id = ?'); $ecStmt->execute([$adminId]); $ecHash = $ecStmt->fetchColumn(); if (!empty($ecHash) && password_verify($entered_code, $ecHash)) $okAuth = true;
        }
            if (!$okAuth) {
            if (empty($entered_pw)) { if ($conn->inTransaction()) $conn->rollBack(); echo json_encode(['ok'=>false,'error'=>'Admin password or erase code required for irreversible archive/delete']); exit; }
            $pwStmt = $conn->prepare('SELECT password FROM users WHERE id = ?'); $pwStmt->execute([$adminId]); $hash = $pwStmt->fetchColumn(); if (empty($hash) || !password_verify($entered_pw, $hash)) { if ($conn->inTransaction()) $conn->rollBack(); echo json_encode(['ok'=>false,'error'=>'Invalid admin password']); exit; }
        }
    $res = archive_and_delete_by_criteria($conn, $criteria, $note, $adminId, $ack_backup, $ack_irrev, false, $allowAll);
        if (empty($res['ok'])) { echo json_encode($res); exit; }
        echo json_encode($res); exit;
    }

    // Update meta: attempt to remove common PII keys from JSON meta (email, phone) if meta is JSON, else replace with '{}' object
    // We will try JSON_REMOVE for keys, but fallback to setting meta to '{}' if JSON functions fail for a row.

    // Basic anonymisation update - user_id, ip_address, user_agent, meta
    $updSql = "UPDATE audit_log SET user_id = NULL, ip_address = NULL, user_agent = NULL, erased_at = NOW(), erased_by_admin_id = ?, erasure_note = ? WHERE $where";
    $updStmt = $conn->prepare($updSql);
    $updParams = array_merge([$adminId, $note], $params);
    $updStmt->execute($updParams);
    $affected = $updStmt->rowCount();

    // Try to scrub meta JSON fields if possible (best-effort). We'll update rows where meta appears to be JSON.
    $selectSql = "SELECT id, meta FROM audit_log WHERE $where";
    $selectStmt = $conn->prepare($selectSql);
    $selectStmt->execute($params);
    $rows = $selectStmt->fetchAll(PDO::FETCH_ASSOC);
    $metaUpd = $conn->prepare("UPDATE audit_log SET meta = ?, erased_at = NOW(), erased_by_admin_id = ?, erasure_note = ? WHERE id = ?");
    foreach ($rows as $r) {
        $id = $r['id'];
        $meta = $r['meta'];
        $newMeta = null;
        if ($meta === null || $meta === '') {
            $newMeta = json_encode(new stdClass());
        } else {
            $decoded = json_decode($meta, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                // remove common PII keys
                unset($decoded['email'], $decoded['phone'], $decoded['telephone'], $decoded['mobile']);
                // If there are nested keys, you can extend this.
                $newMeta = json_encode($decoded);
            } else {
                // Not JSON: replace with empty object
                $newMeta = json_encode(new stdClass());
            }
        }
        $metaUpd->execute([$newMeta, $adminId, $note, $id]);
    }

    // record admin action
    $ins = $conn->prepare("INSERT INTO audit_admin_actions (admin_user_id, action, criteria, affected_count, note, acknowledged_backup, acknowledged_irrevocable) VALUES (?, 'anonymise', ?, ?, ?, ?, ?)");
    $ins->execute([$adminId, json_encode($criteria), $affected, $note, $ack_backup, $ack_irrev]);

    $conn->commit();

    // Also create a small audit_log entry describing this admin action (without subject PII)
    try {
        log_audit($conn, $adminId, 'audit_anonymise', ['criteria' => $criteria, 'affected' => $affected, 'note' => $note], null, 'admin_action', session_id());
    } catch (Exception $e) {
        // ignore auditing failure for admin action
    }

    echo json_encode(['ok' => true, 'affected' => $affected]);
    exit;

} catch (Exception $e) {
    try { $conn->rollBack(); } catch (Exception $e2) {}
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}
