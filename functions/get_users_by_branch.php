<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';

$branch_id = isset($_GET['branch_id']) && is_numeric($_GET['branch_id']) ? intval($_GET['branch_id']) : null;
if (!$branch_id) {
    echo json_encode([]);
    exit;
}

try {
    $stmt = $conn->prepare('SELECT id, username FROM users WHERE branch_id = ? ORDER BY username');
    $stmt->execute([$branch_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($rows);
} catch (Exception $e) {
    echo json_encode([]);
}

?>
