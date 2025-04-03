<?php
session_start();
require_once '../includes/db.php';

if (!isset($_SESSION['user_id'])) {
    die("Unauthorized access");
}

$notifId = intval($_GET['id']);

// Delete the notification from the database
$stmt = $conn->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
if ($stmt->execute([$notifId, $_SESSION['user_id']])) {
    echo "success";
} else {
    echo "error";
}
?>