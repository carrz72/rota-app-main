<?php
session_start();
include '../includes/db.php';

if (!isset($_SESSION['user_id'])) {
    die("Unauthorized access");
}

$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT id, name, base_pay FROM roles WHERE user_id = ?");
$stmt->bindValue(1, $user_id, PDO::PARAM_INT);
$stmt->execute();
$roles = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($roles);
?>
