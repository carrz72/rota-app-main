<?php
/**
 * Enhanced Session Management System
 * Provides better session timeout handling and prevents 404 errors
 */

/**
 * Enhanced Session Management System
 * Provides better session timeout handling and prevents 404 errors
 */

require_once __DIR__ . '/session_config.php';

if (!function_exists('initializeSessionTimeout')) {
    /**
     * Initialize session with timeout configuration
     */
    function initializeSessionTimeout($timeout_minutes = null)
    {
        if (session_status() === PHP_SESSION_NONE) {
            // Use the configured session startup
            startConfiguredSession();

            // Set session timeout if not already set
            $timeout_seconds = $timeout_minutes ? $timeout_minutes * 60 : SESSION_TIMEOUT_DURATION;
            if (!isset($_SESSION['timeout_duration'])) {
                $_SESSION['timeout_duration'] = $timeout_seconds;
            }

            // Log session initialization if enabled
            if (LOG_SESSION_EVENTS && isset($_SESSION['user_id'])) {
                error_log("Session initialized for user " . $_SESSION['user_id'] . " with timeout " . $timeout_seconds . "s");
            }
        }
    }
}

if (!function_exists('checkSessionTimeout')) {
    /**
     * Check if session has timed out and handle gracefully
     */
    function checkSessionTimeout()
    {
        // If no user session exists, it's already handled by requireLogin
        if (!isset($_SESSION['user_id'])) {
            return false;
        }

        $timeout_duration = $_SESSION['timeout_duration'] ?? 7200; // 2 hours default
        $last_activity = $_SESSION['last_activity'] ?? $_SESSION['login_time'] ?? time();

        // Check if session has timed out
        if (time() - $last_activity > $timeout_duration) {
            return true; // Session has timed out
        }

        // Update last activity time
        $_SESSION['last_activity'] = time();
        return false; // Session is still valid
    }
}

if (!function_exists('handleSessionTimeout')) {
    /**
     * Handle session timeout gracefully with user-friendly messaging
     */
    function handleSessionTimeout($redirect_url = null)
    {
        // Store timeout message
        $timeout_message = "Your session has expired due to inactivity. Please log in again.";

        // Clear session data but preserve timeout message temporarily
        session_destroy();
        startConfiguredSession();
        $_SESSION['timeout_message'] = $timeout_message;
        $_SESSION['expired'] = true;

        // Determine redirect URL based on current context
        if (!$redirect_url) {
            $current_path = $_SERVER['REQUEST_URI'] ?? '';
            $script_name = $_SERVER['SCRIPT_NAME'] ?? '';

            // Determine if we're in admin area
            $is_admin_area = (strpos($current_path, '/admin/') !== false) ||
                (strpos($script_name, '/admin/') !== false);

            // Build appropriate login URL
            if ($is_admin_area) {
                $redirect_url = '../functions/login.php?expired=1&return=' . urlencode($current_path);
            } else {
                $redirect_url = '../functions/login.php?expired=1';

                // If not in admin area but has return path, include it
                if ($current_path && $current_path !== '/') {
                    $redirect_url .= '&return=' . urlencode($current_path);
                }
            }
        }

        // For AJAX requests, return JSON response instead of redirect
        if (
            !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest'
        ) {
            header('Content-Type: application/json');
            http_response_code(401);
            echo json_encode([
                'expired' => true,
                'message' => $timeout_message,
                'redirect_url' => $redirect_url
            ]);
            exit;
        }

        // For regular requests, redirect to login
        header("Location: $redirect_url");
        exit;
    }
}

if (!function_exists('validateSession')) {
    /**
     * Complete session validation with timeout handling and 404 prevention
     */
    function validateSession($require_login = true, $require_admin = false)
    {
        initializeSessionTimeout();

        // Check for session timeout first
        if (checkSessionTimeout()) {
            handleSessionTimeout();
            return false;
        }

        // Regular login check
        if ($require_login && !isset($_SESSION['user_id'])) {
            $current_path = $_SERVER['REQUEST_URI'] ?? '';
            $script_name = $_SERVER['SCRIPT_NAME'] ?? '';

            // Build appropriate redirect URL
            $redirect_url = '../functions/login.php';

            // Determine if we're in admin area
            $is_admin_area = (strpos($current_path, '/admin/') !== false) ||
                (strpos($script_name, '/admin/') !== false);

            if ($current_path && $current_path !== '/') {
                $redirect_url .= '?return=' . urlencode($current_path);
            }

            // For AJAX requests, return JSON instead of redirect
            if (
                !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest'
            ) {
                header('Content-Type: application/json');
                http_response_code(401);
                echo json_encode([
                    'authenticated' => false,
                    'message' => 'Authentication required',
                    'redirect_url' => $redirect_url
                ]);
                exit;
            }

            header("Location: $redirect_url");
            exit;
        }

        // Admin check
        if ($require_admin && !isAdminUser()) {
            // Redirect non-admin users to user dashboard
            $redirect_url = '../users/dashboard.php';

            // For AJAX requests
            if (
                !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest'
            ) {
                header('Content-Type: application/json');
                http_response_code(403);
                echo json_encode([
                    'authorized' => false,
                    'message' => 'Admin access required',
                    'redirect_url' => $redirect_url
                ]);
                exit;
            }

            header("Location: $redirect_url");
            exit;
        }

        // Optional: if user_sessions table is used, ensure this session hasn't been invalidated server-side
        if (isset($_SESSION['user_id']) && function_exists('getUserSessionValidation') === false) {
            // keep backward compatibility; nothing to do
        } else {
            // We'll attempt a DB check if available
            try {
                if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof PDO && isset($_SESSION['user_id'])) {
                    $stmt = $GLOBALS['conn']->prepare("SELECT session_id FROM user_sessions WHERE session_id = ? AND user_id = ?");
                    $stmt->execute([session_id(), $_SESSION['user_id']]);
                    $found = $stmt->fetchColumn();
                    if (!$found) {
                        // Session was invalidated elsewhere
                        handleSessionTimeout();
                        return false;
                    }
                }
            } catch (Exception $e) {
                // If any DB error occurs, ignore and continue (feature is optional)
                error_log("user_sessions validation error: " . $e->getMessage());
            }
        }

        return true;
    }
}

if (!function_exists('isAdminUser')) {
    /**
     * Check if user has admin privileges
     */
    function isAdminUser()
    {
        return isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'super_admin']);
    }
}

if (!function_exists('extendSession')) {
    /**
     * Extend session when user is active
     */
    function extendSession()
    {
        if (isset($_SESSION['user_id'])) {
            $_SESSION['last_activity'] = time();
        }
    }
}

if (!function_exists('getSessionTimeRemaining')) {
    /**
     * Get remaining session time in seconds
     */
    function getSessionTimeRemaining()
    {
        if (!isset($_SESSION['user_id'])) {
            return 0;
        }

        $timeout_duration = $_SESSION['timeout_duration'] ?? 7200;
        $last_activity = $_SESSION['last_activity'] ?? $_SESSION['login_time'] ?? time();
        $elapsed = time() - $last_activity;

        return max(0, $timeout_duration - $elapsed);
    }
}

if (!function_exists('formatTimeRemaining')) {
    /**
     * Format remaining time in human readable format
     */
    function formatTimeRemaining($seconds)
    {
        if ($seconds <= 0) {
            return "Expired";
        }

        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        if ($hours > 0) {
            return "{$hours}h {$minutes}m";
        }

        return "{$minutes}m";
    }
}

// Auto-initialize session for included files
if (!isset($_SESSION) || session_status() !== PHP_SESSION_ACTIVE) {
    initializeSessionTimeout();
}
?>