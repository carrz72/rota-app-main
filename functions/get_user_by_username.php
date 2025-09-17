<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';

$q = isset($_GET['username']) && strlen(trim($_GET['username'])) ? trim($_GET['username']) : null;
if (!$q) {
    echo json_encode([]);
    exit;
}

try {
    // try exact match first
    $stmt = $conn->prepare('SELECT id, username FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$q]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($r) {
        echo json_encode([$r]);
        exit;
    }

    // fallback to LIKE search
    $stmt2 = $conn->prepare('SELECT id, username FROM users WHERE username LIKE ? ORDER BY username LIMIT 10');
    $stmt2->execute(["%" . $q . "%"]);
    $rows = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($rows ?: []);
} catch (Exception $e) {
    echo json_encode([]);
}

?>
