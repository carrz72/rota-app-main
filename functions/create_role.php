<?php
session_start();

require_once '../includes/db.php';
require_once '../includes/PermissionManager.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    $_SESSION['error'] = "Unauthorized access.";
    header("Location: ../users/roles.php");
    exit;
}

$permissions = new PermissionManager($conn, $_SESSION['user_id']);
if (!$permissions->canManageRoles()) {
    $_SESSION['error'] = "Only admins or super admins can manage roles.";
    header("Location: ../users/roles.php");
    exit;
}

// Validate required input

$employment_type = $_POST['employment_type'] ?? 'hourly';
$name = trim($_POST['name'] ?? '');
if (empty($name)) {
    $_SESSION['error'] = "Invalid role name.";
    header("Location: ../users/roles.php");
    exit;
}
if ($employment_type === 'hourly') {
    if (!isset($_POST['base_pay']) || !is_numeric($_POST['base_pay']) || $_POST['base_pay'] < 0) {
        $_SESSION['error'] = "Invalid hourly rate for hourly role.";
        header("Location: ../users/roles.php");
        exit;
    }
} elseif ($employment_type === 'salaried') {
    if (!isset($_POST['monthly_salary']) || !is_numeric($_POST['monthly_salary']) || $_POST['monthly_salary'] < 0) {
        $_SESSION['error'] = "Invalid monthly salary for salaried role.";
        header("Location: ../users/roles.php");
        exit;
    }
}

$user_id = $_SESSION['user_id'];
$name = trim($_POST['name']);
$employment_type = $_POST['employment_type'] ?? 'hourly';
$base_pay = $employment_type === 'hourly' ? (float) $_POST['base_pay'] : 0;
$monthly_salary = $employment_type === 'salaried' ? (float) $_POST['monthly_salary'] : null;
$has_night_pay = isset($_POST['has_night_pay']) ? 1 : 0;

// Validate based on employment type
if ($employment_type === 'hourly') {
    if (!isset($base_pay) || !is_numeric($base_pay) || $base_pay < 0) {
        $_SESSION['error'] = "Invalid hourly rate for hourly role.";
        header("Location: ../users/roles.php");
        exit;
    }
} else {
    if (!isset($monthly_salary) || !is_numeric($monthly_salary) || $monthly_salary < 0) {
        $_SESSION['error'] = "Invalid annual salary for salaried role.";
        header("Location: ../users/roles.php");
        exit;
    }
}

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
    INSERT INTO roles (user_id, name, employment_type, base_pay, monthly_salary, has_night_pay, night_shift_pay, night_start_time, night_end_time)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
");

try {
    $success = $stmt->execute([
        $user_id,
        $name,
        $employment_type,
        $base_pay,
        $monthly_salary,
        $has_night_pay,
        $night_shift_pay,
        $night_start_time,
        $night_end_time
    ]);

    if ($success) {
        $_SESSION['success'] = "Role successfully created.";
    try { require_once __DIR__ . '/../includes/audit_log.php'; log_audit($conn, $_SESSION['user_id'], 'create_role', ['role' => $name], $conn->lastInsertId() ?: null, 'role', session_id()); } catch (Exception $e) {}
    } else {
        $_SESSION['error'] = "An error occurred: " . implode(' - ', $stmt->errorInfo());
    }
} catch (PDOException $e) {
    // Common cause: roles.id not AUTO_INCREMENT or duplicate primary key values
    $_SESSION['error'] = "Database error while creating role: " . $e->getMessage() . ".\nIf this mentions duplicate entry '0' or PRIMARY key, run the migration 'migrations/fix_roles_autoinc.sql' to make 'roles.id' AUTO_INCREMENT and ensure the primary key is correctly set.";
}

$conn = null;
header("Location: ../users/roles.php");
exit;
?>