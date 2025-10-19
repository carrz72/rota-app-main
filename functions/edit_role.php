<?php
session_start();
include '../includes/db.php';
require_once '../includes/notifications.php';

if (!isset($_SESSION['user_id'])) {
    die(json_encode(['error' => 'Unauthorized access']));
}

// Only admin users may edit roles
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die(json_encode(['error' => 'Only administrators can edit roles']));
}

// Parse input
$data = json_decode(file_get_contents("php://input"), true);
if (!$data || !isset($data['id'])) {
    header('Content-Type: application/json');
    die(json_encode(['error' => 'Invalid data received']));
}

$id = (int) $data['id'];
$user_id = $_SESSION['user_id'];

// Fetch existing role to allow partial updates and preserve values when fields are blank
$stmt = $conn->prepare("SELECT * FROM roles WHERE id = ?");
$stmt->execute([$id]);
$existing = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$existing) {
    header('Content-Type: application/json');
    die(json_encode(['error' => 'Role not found']));
}

$name = isset($data['name']) && $data['name'] !== '' ? trim($data['name']) : $existing['name'];
$employment_type = isset($data['employment_type']) && $data['employment_type'] !== '' ? $data['employment_type'] : $existing['employment_type'];
$has_night_pay = isset($data['has_night_pay']) ? (int)$data['has_night_pay'] : (int)$existing['has_night_pay'];

// Determine pay fields, prefer provided values, otherwise keep existing
if ($employment_type === 'hourly') {
    if (isset($data['base_pay']) && $data['base_pay'] !== '') {
        if (!is_numeric($data['base_pay']) || $data['base_pay'] < 0) {
            header('Content-Type: application/json');
            die(json_encode(['error' => 'Invalid hourly rate']));
        }
        $base_pay = (float)$data['base_pay'];
    } else {
        $base_pay = $existing['base_pay'];
    }
    $monthly_salary = null;
} else {
    // salaried
    if (isset($data['monthly_salary']) && $data['monthly_salary'] !== '') {
        if (!is_numeric($data['monthly_salary']) || $data['monthly_salary'] < 0) {
            header('Content-Type: application/json');
            die(json_encode(['error' => 'Invalid monthly salary']));
        }
        $monthly_salary = (float)$data['monthly_salary'];
    } else {
        $monthly_salary = $existing['monthly_salary'];
    }
    // ensure base_pay is numeric and non-null for DB constraints
    $base_pay = 0.0;
}

// Update basic role information
$stmt = $conn->prepare("UPDATE roles SET name = ?, employment_type = ?, base_pay = ?, monthly_salary = ?, has_night_pay = ? WHERE id = ?");
try {
    $ok = $stmt->execute([$name, $employment_type, $base_pay, $monthly_salary, $has_night_pay, $id]);
} catch (Exception $e) {
    header('Content-Type: application/json');
    die(json_encode(['error' => 'Database error: ' . $e->getMessage()]));
}

if ($ok) {
    $message = "Role updated successfully!";
    $status = 'success';
    // Audit: role updated
    try { require_once __DIR__ . '/../includes/audit_log.php'; log_audit($conn, $user_id ?? $_SESSION['user_id'] ?? null, 'edit_role', ['name' => $name], $id, 'role', session_id()); } catch (Exception $e) {}
} else {
    $message = "Error updating role!";
    $status = 'error';
    try { require_once __DIR__ . '/../includes/audit_log.php'; log_audit($conn, $user_id ?? $_SESSION['user_id'] ?? null, 'edit_role_error', [], $id, 'role', session_id()); } catch (Exception $e) {}
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
header('Content-Type: application/json');
echo json_encode(['message' => $message, 'status' => $status]);

$stmt = null;
$conn = null;
?>