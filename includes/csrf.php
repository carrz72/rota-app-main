<?php
// Simple CSRF token helpers (single-use tokens stored in session)
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

function generate_csrf_token()
{
    if (empty($_SESSION['csrf_tokens']) || !is_array($_SESSION['csrf_tokens'])) {
        $_SESSION['csrf_tokens'] = [];
    }
    // prune tokens older than 1 hour
    $now = time();
    foreach ($_SESSION['csrf_tokens'] as $k => $t) {
        if ($t + 3600 < $now) {
            unset($_SESSION['csrf_tokens'][$k]);
        }
    }

    $token = bin2hex(random_bytes(32));
    $_SESSION['csrf_tokens'][$token] = $now;
    return $token;
}

function verify_csrf_token($token)
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    if (empty($token) || empty($_SESSION['csrf_tokens'][$token])) {
        return false;
    }
    // single-use
    unset($_SESSION['csrf_tokens'][$token]);
    return true;
}

function csrf_input_field()
{
    $t = generate_csrf_token();
    return '<input type="hidden" name="csrf_token" value="' . htmlentities($t) . '">';
}
