<?php
session_start();
include '../includes/db.php';

// Only allow POST/JSON and admin users
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Only administrators can delete roles']);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);
if (!$data || !isset($data['id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request']);
    exit;
}

$id = (int) $data['id'];

$stmt = $conn->prepare("DELETE FROM roles WHERE id = ?");
$result = $stmt->execute([$id]);
if ($result) {
    echo json_encode(['success' => true, 'message' => 'Role deleted']);
    try { require_once __DIR__ . '/../includes/audit_log.php'; log_audit($conn, $_SESSION['user_id'] ?? null, 'delete_role', [], $id, 'role', session_id()); } catch (Exception $e) {}
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error deleting role']);
}
?>
