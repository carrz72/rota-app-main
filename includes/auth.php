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

// Legacy compatibility functions
if (!function_exists('checkLogin')) {
    function checkLogin()
    {
        return validateSession(false, false);
    }
}
?>