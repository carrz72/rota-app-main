<?php
require '../includes/auth.php';
requireAdmin();
require include '../includes/db.php';

if (isset($_GET['id'])) {
    $id = $_GET['id'];
    $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$id]);

    header("Location: admin.php");
}
?>
