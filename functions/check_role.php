<?php
session_start();
include '../includes/db.php';

function is_admin() {
    global $conn;
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    $user_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $role = $stmt->fetchColumn();
    return $role === 'admin';
}
?>
