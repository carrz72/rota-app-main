<?php
// Simple audit logger
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

/**
 * Log an audit event.
 *
 * This function is backward-compatible: it will detect whether the
 * `related_id` and `event_type` columns exist in the database and include
 * them in the INSERT when available.
 *
 * @param PDO    $conn        PDO connection
 * @param int    $user_id     ID of the acting user (nullable for anonymous)
 * @param string $action      Short action name
 * @param mixed  $meta        Optional metadata (array/object will be json_encoded)
 * @param int    $related_id  Optional related record id (shift id, user id, etc.)
 * @param string $event_type  Optional event type/category
 * @param string $session_id  Optional session id to store in its own column
 */
function log_audit($conn, $user_id, $action, $meta = null, $related_id = null, $event_type = null, $session_id = null)
{
    try {
        // Respect runtime toggle for audit logging. Priority: ENV var AUDIT_LOG_ENABLED (if set) then app_settings table key 'AUDIT_LOG_ENABLED'.
        $enabled = true;
        try {
            $env = getenv('AUDIT_LOG_ENABLED');
            if ($env !== false) {
                $envv = strtolower(trim($env));
                $enabled = in_array($envv, ['1', 'true', 'on'], true);
            } else {
                // app_settings may not exist on older installs; wrap in try/catch
                $s = $conn->prepare("SELECT v FROM app_settings WHERE `k` = ? LIMIT 1");
                $s->execute(['AUDIT_LOG_ENABLED']);
                $v = $s->fetchColumn();
                if ($v !== false && $v !== null) {
                    $vv = strtolower(trim((string)$v));
                    $enabled = in_array($vv, ['1', 'true', 'on'], true);
                }
            }
        } catch (Exception $e) {
            // ignore - if we couldn't read app_settings, default to enabled
            $enabled = true;
        }

        // If disabled, short-circuit and treat as success.
        if (!$enabled) {
            return true;
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;

        if (is_array($meta) || is_object($meta)) {
            $meta_json = json_encode($meta);
        } else {
            $meta_json = $meta;
        }

        // Ensure meta is valid JSON when table has JSON CHECK; if it's a string that
        // isn't valid JSON, wrap it so the CHECK(json_valid(meta)) passes.
        $meta_is_valid_json = false;
        if ($meta_json === null) {
            $meta_is_valid_json = false;
        } else {
            // json_decode accepts both objects and scalars; use json_last_error check
            json_decode($meta_json);
            $meta_is_valid_json = (json_last_error() === JSON_ERROR_NONE);
        }
        if (!$meta_is_valid_json) {
            // Safe fallback: store meta as a JSON object with the raw value under 'value'
            $meta_json = json_encode(['value' => (string)$meta]);
        }

    // Detect optional columns once per call. Use SHOW COLUMNS which is portable.
        $has_related = false;
        $has_event_type = false;
    $has_session_id_col = false;

        try {
            $r = $conn->query("SHOW COLUMNS FROM `audit_log` LIKE 'related_id'");
            if ($r && $r->rowCount() > 0) {
                $has_related = true;
            }
        } catch (Exception $e) {
            // ignore - table may not exist yet in some environments
        }

        try {
            $r2 = $conn->query("SHOW COLUMNS FROM `audit_log` LIKE 'event_type'");
            if ($r2 && $r2->rowCount() > 0) {
                $has_event_type = true;
            }
        } catch (Exception $e) {
            // ignore
        }

        try {
            $r3 = $conn->query("SHOW COLUMNS FROM `audit_log` LIKE 'session_id'");
            if ($r3 && $r3->rowCount() > 0) {
                $has_session_id_col = true;
            }
        } catch (Exception $e) {
            // ignore
        }

        // Build INSERT dynamically so any combination of optional columns is supported,
        // including the case where only session_id exists.
        $cols = ['user_id', 'action', 'meta', 'ip_address', 'user_agent'];
        $placeholders = ['?', '?', '?', '?', '?'];
        $values = [$user_id, $action, $meta_json, $ip, $ua];

        if ($has_related) {
            $cols[] = 'related_id';
            $placeholders[] = '?';
            $values[] = $related_id;
        }
        if ($has_event_type) {
            $cols[] = 'event_type';
            $placeholders[] = '?';
            $values[] = $event_type;
        }
        if ($has_session_id_col) {
            $cols[] = 'session_id';
            $placeholders[] = '?';
            $values[] = $session_id;
        }

        $sql = 'INSERT INTO audit_log (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $stmt = $conn->prepare($sql);
        try {
            $stmt->execute($values);
            return true;
        } catch (Exception $ex) {
            // Write detailed debug info to temp file to help diagnose DB errors
            $debugPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'audit_log_debug.txt';
            $payload = [
                'time' => date('c'),
                'sql' => $sql,
                'values' => $values,
                'exception' => $ex->getMessage(),
                'trace' => $ex->getTraceAsString(),
                'sapi' => PHP_SAPI,
            ];
            @file_put_contents($debugPath, print_r($payload, true) . "\n---\n", FILE_APPEND | LOCK_EX);
            // Re-throw so the outer catch logs as well
            throw $ex;
        }
    } catch (Exception $e) {
        // Include context to help debug missing audit records
        $context = sprintf("action=%s user_id=%s related_id=%s", (string)$action, (string)$user_id, (string)$related_id);
    error_log('audit_log error: ' . $e->getMessage() . ' | ' . $context . ' | meta=' . substr((string)$meta_json, 0, 400));
    return false;
    }
}
