<?php
session_start();
include '../includes/db.php';

$data = json_decode(file_get_contents("php://input"), true);
$id = $data['id'];
$user_id = $_SESSION['user_id'];

$stmt = $conn->prepare("DELETE FROM roles WHERE id = ? AND user_id = ?");
$result = $stmt->execute([$id, $user_id]);
if ($result) {
    echo "Role deleted!";
} else {
    echo "Error deleting role!";
}
?>
