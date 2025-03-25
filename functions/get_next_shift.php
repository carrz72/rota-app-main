<?php
session_start();
include '../includes/db.php';

if (!isset($_SESSION['user_id'])) {
    die("Unauthorized access");
}

$user_id = $_SESSION['user_id'];

$sql = "SELECT * FROM shifts WHERE user_id=? AND shift_date >= CURDATE() ORDER BY shift_date ASC LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->execute([$user_id]);
$shift = $stmt->fetch(PDO::FETCH_ASSOC);

echo json_encode($shift);

$stmt = null;
$conn = null;
?>
