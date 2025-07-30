<?php
/**
 * Session Starter - Initialize session with proper configuration
 * Include this file BEFORE any session operations
 */

// Prevent multiple includes
if (defined('SESSION_INITIALIZED')) {
    return;
}

// Only configure if session hasn't started yet
if (session_status() === PHP_SESSION_NONE) {
    // Load configuration constants
    require_once __DIR__ . '/session_config.php';

    // Use the configured session startup
    startConfiguredSession();
}

define('SESSION_INITIALIZED', true);
?>