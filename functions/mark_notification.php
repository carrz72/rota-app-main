<?php
session_start();
require_once '../includes/db.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Unauthorized');
}

if (isset($_GET['id'])) {
    $id = intval($_GET['id']);
    // Mark notification as read for current user
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    if ($stmt->execute([$id, $_SESSION['user_id']])) {
        echo "success";
    } else {
        http_response_code(500);
        echo "failed";
    }
} else {
    http_response_code(400);
    echo "id required";
}
?>