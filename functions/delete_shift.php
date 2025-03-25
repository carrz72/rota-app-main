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
        echo "Shift deleted!";
    } else {
        addNotification($conn, $_SESSION['user_id'], "Shift not found or already deleted.", "error");
        echo "Shift not found or already deleted.";
    }
} else {
    $errorInfo = $stmt->errorInfo();
    addNotification($conn, $_SESSION['user_id'], "Error deleting shift: " . $errorInfo[2], "error");
    echo "Error: " . $errorInfo[2];
}

$stmt = null;
$conn = null;
?>
