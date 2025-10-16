<?php
session_start();
require_once '../includes/db.php';

// Set JSON header for all responses
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit;
}

$notifId = null;
$markAll = false;

// Handle both GET and POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle JSON POST request
    $input = json_decode(file_get_contents('php://input'), true);
    if (isset($input['id'])) {
        if ($input['id'] === 'all') {
            $markAll = true;
        } elseif (is_numeric($input['id'])) {
            $notifId = (int) $input['id'];
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Handle GET request
    if (isset($_GET['id'])) {
        if ($_GET['id'] === 'all') {
            $markAll = true;
        } elseif (is_numeric($_GET['id'])) {
            $notifId = (int) $_GET['id'];
        }
    }
}

if ($markAll) {
    $stmt = $conn->prepare("DELETE FROM notifications WHERE user_id = ?");
    if ($stmt->execute([$_SESSION['user_id']])) {
        echo json_encode(['success' => true, 'message' => 'All notifications cleared']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to clear notifications']);
    }
    exit;
}

if (!$notifId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing notification ID']);
    exit;
}

// Delete the notification from the database
$stmt = $conn->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
if ($stmt->execute([$notifId, $_SESSION['user_id']])) {
    echo json_encode(['success' => true, 'message' => 'Notification marked as read']);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to mark notification as read']);
}
?>