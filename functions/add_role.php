<?php
include '../includes/db.php';
include 'check_role.php';

if (!is_admin()) {
    die("Unauthorized access");
}

$name = $_POST['name'];
$base_pay = $_POST['base_pay'];
$has_night_pay = isset($_POST['has_night_pay']) ? 1 : 0;
$night_shift_pay = $_POST['night_shift_pay'] ?? NULL;
$night_start_time = $_POST['night_start_time'] ?? NULL;
$night_end_time = $_POST['night_end_time'] ?? NULL;

$stmt = $conn->prepare("INSERT INTO roles (name, base_pay, has_night_pay, night_shift_pay, night_start_time, night_end_time) VALUES (?, ?, ?, ?, ?, ?)");
if ($stmt->execute([$name, $base_pay, $has_night_pay, $night_shift_pay, $night_start_time, $night_end_time])) {
    echo "Role added successfully!";
} else {
    $errorInfo = $stmt->errorInfo();
    echo "Error: " . $errorInfo[2];
}
?>
