<?php
session_start();
include '../includes/db.php';

if (!isset($_SESSION['user_id'])) {
    die("Unauthorized access");
}

$shift_id   = $_POST['shift_id'];
$shift_date = $_POST['shift_date'];
$start_time = $_POST['start_time'];
$end_time   = $_POST['end_time'];
$location   = $_POST['location'];
$role_id    = $_POST['role_id'];

$stmt = $conn->prepare("UPDATE shifts SET shift_date=?, start_time=?, end_time=?, location=?, role_id=? WHERE id=? AND user_id=?");
$stmt->execute([$shift_date, $start_time, $end_time, $location, $role_id, $shift_id, $_SESSION['user_id']]);

$stmt = null;
$conn = null;

header("Location: ../users/shifts.php");
exit();
?>
