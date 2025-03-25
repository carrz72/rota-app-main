<?php
session_start();
include '../includes/db.php';

$data = json_decode(file_get_contents("php://input"), true);
$id = $data['id'];
$name = $data['name'];
$base_pay = $data['base_pay'];
$user_id = $_SESSION['user_id'];

$stmt = $conn->prepare("UPDATE roles SET name = ?, base_pay = ? WHERE id = ? AND user_id = ?");
if ($stmt->execute([$name, $base_pay, $id, $user_id])) {
    echo "Role updated successfully!";
} else {
    echo "Error updating role!";
}

if (isset($data['night_shift_pay'], $data['night_start_time'], $data['night_end_time'])) {
    $night_shift_pay = $data['night_shift_pay'];
    $night_start_time = $data['night_start_time'];
    $night_end_time = $data['night_end_time'];

    $stmt = $conn->prepare("UPDATE roles SET night_shift_pay = ?, night_start_time = ?, night_end_time = ? WHERE id = ? AND user_id = ?");
    $stmt->execute([$night_shift_pay, $night_start_time, $night_end_time, $id, $user_id]);
    $stmt = null;
}

$stmt = null;
$conn = null;
?>
