<?php
session_start();
include '../includes/db.php';
require_once '../includes/notifications.php';

if (!isset($_SESSION['user_id'])) {
    die(json_encode(['error' => 'Unauthorized access']));
}

$data = json_decode(file_get_contents("php://input"), true);
if (!$data) {
    die(json_encode(['error' => 'Invalid data received']));
}

$id = $data['id'] ?? '';
$name = $data['name'] ?? '';
$base_pay = $data['base_pay'] ?? '';
$has_night_pay = $data['has_night_pay'] ?? 0;
$user_id = $_SESSION['user_id'];

// Basic validation
if (empty($id) || empty($name) || empty($base_pay)) {
    die(json_encode(['error' => 'Missing required fields']));
}

// Update basic role information
$stmt = $conn->prepare("UPDATE roles SET name = ?, base_pay = ?, has_night_pay = ? WHERE id = ?");
if ($stmt->execute([$name, $base_pay, $has_night_pay, $id])) {
    $message = "Role updated successfully!";
    $status = 'success';
} else {
    $message = "Error updating role!";
    $status = 'error';
}

// Update night shift settings if applicable
if ($has_night_pay && isset($data['night_shift_pay'], $data['night_start_time'], $data['night_end_time'])) {
    $night_shift_pay = $data['night_shift_pay'];
    $night_start_time = $data['night_start_time'];
    $night_end_time = $data['night_end_time'];

    $stmt = $conn->prepare("UPDATE roles SET night_shift_pay = ?, night_start_time = ?, night_end_time = ? WHERE id = ?");
    $stmt->execute([$night_shift_pay, $night_start_time, $night_end_time, $id]);
} else if (!$has_night_pay) {
    // Clear night shift data if it's disabled
    $stmt = $conn->prepare("UPDATE roles SET night_shift_pay = NULL, night_start_time = NULL, night_end_time = NULL WHERE id = ?");
    $stmt->execute([$id]);
}

// Add notification
addNotification($conn, $user_id, $message, $status);

// Set session message to display on the roles page after redirect
$_SESSION[$status] = $message;

// Return success message
echo json_encode(['message' => $message, 'status' => $status]);

$stmt = null;
$conn = null;
?>