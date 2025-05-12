<?php
session_start();
include '../includes/db.php';

if (!isset($_SESSION['user_id'])) {
    die(json_encode(['error' => 'Unauthorized access']));
}

if (!isset($_GET['id'])) {
    die(json_encode(['error' => 'No role ID provided']));
}

$role_id = $_GET['id'];
$user_id = $_SESSION['user_id'];

$stmt = $conn->prepare("SELECT * FROM roles WHERE id = ?");
$stmt->execute([$role_id]);
$role = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$role) {
    die(json_encode(['error' => 'Role not found']));
}

// Return the role data as JSON
header('Content-Type: application/json');
echo json_encode($role);
?>