<?php
session_start();
include '../includes/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    $_SESSION['error'] = "Unauthorized access.";
    header("Location: ../users/roles.php");
    exit;
}

// Validate required input
if (empty($_POST['name']) || !isset($_POST['base_pay']) || !is_numeric($_POST['base_pay']) || $_POST['base_pay'] < 0) {
    $_SESSION['error'] = "Invalid role name or base pay.";
    header("Location: ../users/roles.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$name = trim($_POST['name']);
$base_pay = (float) $_POST['base_pay'];
$has_night_pay = isset($_POST['has_night_pay']) ? 1 : 0;

// Initialize optional night shift fields
$night_shift_pay = null;
$night_start_time = null;
$night_end_time = null;

if ($has_night_pay) {
    if (
        !isset($_POST['night_shift_pay'], $_POST['night_start_time'], $_POST['night_end_time']) ||
        !is_numeric($_POST['night_shift_pay']) ||
        empty($_POST['night_start_time']) ||
        empty($_POST['night_end_time'])
    ) {
        $_SESSION['error'] = "Please provide all night shift details.";
        header("Location: ../users/roles.php");
        exit;
    }

    $night_shift_pay = (float) $_POST['night_shift_pay'];
    $night_start_time = trim($_POST['night_start_time']);
    $night_end_time = trim($_POST['night_end_time']);
}

// Check for existing role name (case-insensitive)
$stmt = $conn->prepare("SELECT COUNT(*) FROM roles WHERE user_id = ? AND LOWER(name) = LOWER(?)");
$stmt->execute([$user_id, $name]);
if ($stmt->fetchColumn() > 0) {
    $_SESSION['error'] = "Role already exists. Please choose a different name.";
    header("Location: ../users/roles.php");
    exit;
}

// Insert role
$stmt = $conn->prepare("
    INSERT INTO roles (user_id, name, base_pay, has_night_pay, night_shift_pay, night_start_time, night_end_time)
    VALUES (?, ?, ?, ?, ?, ?, ?)
");

$success = $stmt->execute([
    $user_id,
    $name,
    $base_pay,
    $has_night_pay,
    $night_shift_pay,
    $night_start_time,
    $night_end_time
]);

if ($success) {
    $_SESSION['success'] = "Role successfully created.";
} else {
    $_SESSION['error'] = "An error occurred: " . implode(' - ', $stmt->errorInfo());
}

$conn = null;
header("Location: ../users/roles.php");
exit;
?>