<?php
require_once __DIR__ . '/../includes/db.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
if (!$user_id) {
    echo json_encode(['shifts' => []]);
    exit;
}

try {
    $stmt = $conn->prepare("SELECT id, shift_date, start_time, end_time, location FROM shifts WHERE user_id = ? AND shift_date >= CURDATE() ORDER BY shift_date ASC, start_time ASC");
    $stmt->execute([$user_id]);
    $shifts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['shifts' => $shifts]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB error']);
}
exit;

?>
