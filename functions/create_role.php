<?php
session_start();
include '../includes/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    $_SESSION['error'] = "Unauthorized access";
    header("Location: ../users/roles.php");
    exit;
}

// Validate input
if (empty($_POST['name']) || !is_numeric($_POST['base_pay']) || $_POST['base_pay'] < 0) {
    $_SESSION['error'] = "Invalid input data.";
    header("Location: ../users/roles.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$name = trim($_POST['name']);
$base_pay = $_POST['base_pay'];
$has_night_pay = isset($_POST['has_night_pay']) ? 1 : 0;
$night_shift_pay = $_POST['night_shift_pay'] ?? NULL;
$night_start_time = $_POST['night_start_time'] ?? NULL;
$night_end_time = $_POST['night_end_time'] ?? NULL;

// Check if role with the same name already exists (case insensitive)
$stmt = $conn->prepare("SELECT COUNT(*) FROM roles WHERE user_id = ? AND LOWER(name) = LOWER(?)");
$stmt->execute([$user_id, $name]);
if ($stmt->fetchColumn() > 0) {
    $_SESSION['error'] = "Role already exists. Please choose a different name.";
    header("Location: ../users/roles.php");
    exit;
}

// Insert data into the database
$stmt = $conn->prepare("INSERT INTO roles (user_id, name, base_pay, has_night_pay, night_shift_pay, night_start_time, night_end_time) VALUES (?, ?, ?, ?, ?, ?, ?)");
if ($stmt->execute([$user_id, $name, $base_pay, $has_night_pay, $night_shift_pay, $night_start_time, $night_end_time])) {
    $_SESSION['success'] = "Role successfully created.";
} else {
    $errorInfo = $stmt->errorInfo();
    $_SESSION['error'] = "An error occurred. Please try again later.";
}

$conn = null;
header("Location: ../users/roles.php");
exit;
?>