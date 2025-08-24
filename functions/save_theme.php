<?php
session_start();
header('Content-Type: application/json');
require_once '../includes/db.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['theme'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid payload']);
    exit;
}

$theme = $input['theme'] === 'dark' ? 'dark' : 'light';
$userId = $_SESSION['user_id'];

try {
    // Ensure column exists (best-effort)
    $conn->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS theme VARCHAR(16) DEFAULT NULL");
} catch (Exception $e) {
    // older MySQL may not support IF NOT EXISTS for ADD COLUMN; ignore
}

try {
    // Update user's theme preference
    $stmt = $conn->prepare("UPDATE users SET theme = ? WHERE id = ?");
    $stmt->execute([$theme, $userId]);
    echo json_encode(['success' => true, 'theme' => $theme]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

?>
