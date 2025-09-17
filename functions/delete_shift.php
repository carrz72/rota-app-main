<?php
session_start();
include '../includes/db.php';
require_once __DIR__ . '/addNotification.php';

if (!isset($_SESSION['user_id'])) {
    die("Unauthorized access");
}

$shift_id = $_POST['shift_id'] ?? '';

if (empty($shift_id)) {
    addNotification($conn, $_SESSION['user_id'], "Shift ID missing.", "error");
    echo "Shift ID missing.";
    exit;
}

$stmt = $conn->prepare("DELETE FROM shifts WHERE id=? AND user_id=?");
if ($stmt->execute([$shift_id, $_SESSION['user_id']])) {
    if ($stmt->rowCount() > 0) {
        addNotification($conn, $_SESSION['user_id'], "Shift deleted successfully.", "success");
        // Audit: successful deletion
    try { require_once __DIR__ . '/../includes/audit_log.php'; log_audit($conn, $_SESSION['user_id'] ?? null, 'delete_shift', [], $shift_id, 'shift', session_id()); } catch (Exception $e) {}
        echo "Shift deleted!";
    } else {
        addNotification($conn, $_SESSION['user_id'], "Shift not found or already deleted.", "error");
        // Audit: attempted delete but not found
    try { require_once __DIR__ . '/../includes/audit_log.php'; log_audit($conn, $_SESSION['user_id'] ?? null, 'delete_shift_not_found', [], $shift_id, 'shift', session_id()); } catch (Exception $e) {}
        echo "Shift not found or already deleted.";
    }
} else {
    $errorInfo = $stmt->errorInfo();
    addNotification($conn, $_SESSION['user_id'], "Error deleting shift: " . $errorInfo[2], "error");
    // Audit: database error while deleting shift
    try { require_once __DIR__ . '/../includes/audit_log.php'; log_audit($conn, $_SESSION['user_id'] ?? null, 'delete_shift_error', ['error' => $errorInfo[2]], $shift_id, 'shift', session_id()); } catch (Exception $e) {}
    echo "Error: " . $errorInfo[2];
}

$stmt = null;
$conn = null;
?>
