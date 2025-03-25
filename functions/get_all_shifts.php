<?php
ob_start();
session_start();
require '../includes/db.php'; // Database connection file

// Ensure the user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

$user_id = $_SESSION['user_id'];

// Determine filtering period (default to week)
$period = $_GET['period'] ?? 'year';

$bindings = [];

if ($period === 'week') {
    if (isset($_GET['weekStart'])) {
        $weekStart = $_GET['weekStart'];
        $periodSql = "shift_date BETWEEN :weekStart AND DATE_ADD(:weekStart, INTERVAL 6 DAY)";
        $bindings[':weekStart'] = $weekStart;
    } else {
        $periodSql = "YEARWEEK(shift_date, 1) = YEARWEEK(CURDATE(), 1)";
    }
} elseif ($period === 'month') {
    if (isset($_GET['month']) && isset($_GET['year'])) {
        $month = $_GET['month'];
        $year = $_GET['year'];
        $periodSql = "MONTH(shift_date) = :month AND YEAR(shift_date) = :year";
        $bindings[':month'] = $month;
        $bindings[':year'] = $year;
    } else {
        $periodSql = "MONTH(shift_date) = MONTH(CURDATE()) AND YEAR(shift_date) = YEAR(CURDATE())";
    }
} elseif ($period === 'year') {
    if (isset($_GET['year'])) {
        $year = $_GET['year'];
        $periodSql = "YEAR(shift_date) = :year";
        $bindings[':year'] = $year;
    } else {
        $periodSql = "YEAR(shift_date) = YEAR(CURDATE())";
    }
} else {
    $periodSql = "1=1";
}

// Fetch shifts for the logged-in user with role names and additional fields
$query = "SELECT s.*, r.name as role, r.base_pay, r.has_night_pay, r.night_shift_pay, r.night_start_time, r.night_end_time 
          FROM shifts s 
          JOIN roles r ON s.role_id = r.id 
          WHERE s.user_id = :user_id AND $periodSql";

$stmt = $conn->prepare($query);
$stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);

// Bind additional parameters if any
foreach ($bindings as $param => $value) {
    $stmt->bindValue($param, $value, PDO::PARAM_STR);
}

$stmt->execute();
$shifts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Return JSON response
ob_end_clean();
header('Content-Type: application/json');
echo json_encode($shifts);