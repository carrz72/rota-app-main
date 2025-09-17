<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit_log.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('HTTP/1.1 405 Method Not Allowed');
    exit;
}

$token = $_POST['csrf_token'] ?? '';
if (!verify_csrf_token($token)) {
    header('HTTP/1.1 400 Bad Request');
    echo "Invalid CSRF token";
    exit;
}

$user_id = $_SESSION['user_id'];
$data = [];
try {
    $stmt = $conn->prepare("SELECT id, username, email, role, created_at FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $data['user'] = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $data['user'] = null;
}

// Attempt to gather related data. These tables may or may not exist in every deployment.
$related = ['shifts', 'payroll', 'login_history', 'user_sessions'];
foreach ($related as $tbl) {
    try {
        $stmt = $conn->prepare("SELECT * FROM {$tbl} WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $data[$tbl] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // skip if table missing
        $data[$tbl] = null;
    }
}

// Log audit
try {
    log_audit($conn, $user_id, 'export_data', ['tables' => array_keys($data)], null, 'data_export', session_id());
} catch (Exception $e) {
    // ignore
}

$ts = date('Ymd_His');
$filename = "open_rota_export_{$user_id}_{$ts}.json";
header('Content-Type: application/json');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo json_encode($data, JSON_PRETTY_PRINT);
if (!defined('UNIT_TEST')) exit;
