<?php
session_start();
include '../includes/db.php';

if (!isset($_SESSION['user_id'])) {
    die("Unauthorized access");
}

$user_id = $_POST['user_id'];
$role_id = $_POST['role_id'];

$stmt = $conn->prepare("UPDATE users SET role_id = ? WHERE id = ?");
if ($stmt->execute([$role_id, $user_id])) {
    echo "Role assigned!";
    try { require_once __DIR__ . '/../includes/audit_log.php'; log_audit($conn, $_SESSION['user_id'] ?? null, 'assign_role', [], $user_id, 'role_assignment', session_id()); } catch (Exception $e) {}
} else {
    $error = $stmt->errorInfo();
    echo "Error: " . $error[2];
}

$stmt = null;
$conn = null;
?>
