<?php
session_start();
include '../includes/db.php';
include '../includes/notifications.php';

if (!isset($_SESSION['user_id'])) {
    die("Unauthorized access");
}

$user_id    = $_SESSION['user_id'];
$shift_date = $_POST['shift_date'];
$start_time = $_POST['start_time'];
$end_time   = $_POST['end_time'];
$role_id    = $_POST['role_id'];
$location   = $_POST['location'];

// Include location in the INSERT if needed by your table
$stmt = $conn->prepare("INSERT INTO shifts (user_id, shift_date, start_time, end_time, role_id, location) VALUES (?, ?, ?, ?, ?, ?)");
if ($stmt->execute([$user_id, $shift_date, $start_time, $end_time, $role_id, $location])) {
    addNotification($conn, $user_id, "Shift added successfully!", "success");
} else {
    $errorInfo = $stmt->errorInfo();
    addNotification($conn, $user_id, "Error adding shift: " . $errorInfo[2], "error");
}


header("Location: ../users/shifts.php");
exit();
?>
