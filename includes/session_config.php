<?php
/**
 * Session Configuration for Rota Application
 * Centralized session timeout and security settings
 */

// Session timeout settings (in seconds)
define('SESSION_TIMEOUT_DURATION', 7200); // 2 hours
define('SESSION_WARNING_TIME', 600); // 10 minutes before timeout
define('SESSION_CHECK_INTERVAL', 60); // Check every minute on client side

// Session security settings
define('SESSION_REGENERATE_INTERVAL', 1800); // Regenerate ID every 30 minutes
define('SESSION_COOKIE_LIFETIME', SESSION_TIMEOUT_DURATION);
define('SESSION_COOKIE_SECURE', false); // Set to true for HTTPS only
define('SESSION_COOKIE_HTTPONLY', true);
define('SESSION_COOKIE_SAMESITE', 'Lax');

// Error handling settings
define('SHOW_DETAILED_ERRORS', false); // Set to true for debugging
define('LOG_SESSION_EVENTS', true);

// Redirect URLs
define('LOGIN_URL', '../functions/login.php');
define('USER_DASHBOARD_URL', '../users/dashboard.php');
define('ADMIN_DASHBOARD_URL', '../admin/admin_dashboard.php');

// Database session storage (optional - currently using PHP sessions)
define('USE_DATABASE_SESSIONS', false);

/**
 * Start session with proper configuration
 * This should be used instead of calling session_start() directly
 */
function startConfiguredSession()
{
    // Configure session before starting if not already configured
    if (session_status() === PHP_SESSION_NONE) {
        initializeSessionConfig();
        session_start();

        if (LOG_SESSION_EVENTS) {
            error_log("Session started with configuration");
        }
    } else if (session_status() === PHP_SESSION_ACTIVE) {
        if (LOG_SESSION_EVENTS) {
            error_log("Session already active");
        }
    }

    return session_status() === PHP_SESSION_ACTIVE;
}

/**
 * Initialize session configuration
 * Only sets configuration if session is not already active
 */
function initializeSessionConfig()
{
    // Only configure session if it hasn't been started yet
    if (session_status() === PHP_SESSION_NONE) {
        // Set session cookie parameters
        session_set_cookie_params([
            'lifetime' => SESSION_COOKIE_LIFETIME,
            'path' => '/',
            'domain' => '',
            'secure' => SESSION_COOKIE_SECURE,
            'httponly' => SESSION_COOKIE_HTTPONLY,
            'samesite' => SESSION_COOKIE_SAMESITE
        ]);

        // Set PHP session configuration
        ini_set('session.gc_maxlifetime', SESSION_TIMEOUT_DURATION);
        ini_set('session.cookie_lifetime', SESSION_COOKIE_LIFETIME);
        ini_set('session.use_strict_mode', 1);
        ini_set('session.use_only_cookies', 1);

        // Log session configuration if enabled
        if (LOG_SESSION_EVENTS) {
            error_log("Session configuration initialized: timeout=" . SESSION_TIMEOUT_DURATION . "s, warning=" . SESSION_WARNING_TIME . "s");
        }
    } else {
        // Log that session was already active
        if (LOG_SESSION_EVENTS) {
            error_log("Session configuration skipped - session already active");
        }
    }
}

/**
 * Get JavaScript configuration for client-side session management
 */
function getClientSessionConfig()
{
    return [
        'timeoutDuration' => SESSION_TIMEOUT_DURATION * 1000, // Convert to milliseconds
        'warningTime' => SESSION_WARNING_TIME * 1000,
        'checkInterval' => SESSION_CHECK_INTERVAL * 1000,
        'loginUrl' => LOGIN_URL,
        'showDetailedErrors' => SHOW_DETAILED_ERRORS
    ];
}

/**
 * Check if session features are properly configured
 */
function validateSessionConfig()
{
    $issues = [];

    if (SESSION_WARNING_TIME >= SESSION_TIMEOUT_DURATION) {
        $issues[] = "Warning time must be less than timeout duration";
    }

    if (SESSION_CHECK_INTERVAL > SESSION_WARNING_TIME) {
        $issues[] = "Check interval should be less than warning time";
    }

    if (SESSION_COOKIE_SECURE && !isset($_SERVER['HTTPS'])) {
        $issues[] = "Secure cookies enabled but HTTPS not detected";
    }

    return empty($issues) ? true : $issues;
}

// Session configuration is loaded but not auto-initialized
// Use startConfiguredSession() or initializeSessionConfig() explicitly
?>