<?php
require_once __DIR__ . '/session_manager.php';
require_once __DIR__ . '/db.php';

if (!function_exists('isAdmin')) {
    function isAdmin()
    {
        return isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'super_admin']);
    }
}

if (!function_exists('requireLogin')) {
    function requireLogin()
    {
        validateSession(true, false);
    }
}

if (!function_exists('requireAdmin')) {
    function requireAdmin()
    {
        validateSession(true, true);
    }
}

if (!function_exists('isSuperAdminUser')) {
    // Convenience helper: checks session role first, falls back to DB check via includes/super_admin.php
    function isSuperAdminUser($conn = null)
    {
        if (isset($_SESSION['role']) && $_SESSION['role'] === 'super_admin') {
            return true;
        }
        // Try DB-backed check if connection available
        if ($conn === null && isset($GLOBALS['conn'])) {
            $conn = $GLOBALS['conn'];
        }
        if ($conn instanceof PDO) {
            require_once __DIR__ . '/super_admin.php';
            return isSuperAdmin($_SESSION['user_id'] ?? null, $conn);
        }
        return false;
    }
}

// Legacy compatibility functions
if (!function_exists('checkLogin')) {
    function checkLogin()
    {
        return validateSession(false, false);
    }
}
?>