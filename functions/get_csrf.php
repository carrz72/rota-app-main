<?php
// Returns a fresh CSRF token in JSON. Requires session to be active.
header('Content-Type: application/json; charset=utf-8');
if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
require_once __DIR__ . '/../includes/csrf.php';
try {
    $t = generate_csrf_token();
    echo json_encode(['ok' => true, 'token' => $t]);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}
