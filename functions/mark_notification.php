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
    // Try JSON body first (modern clients)
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
    if (is_array($input) && isset($input['id'])) {
        if ($input['id'] === 'all') {
            $markAll = true;
        } elseif (is_numeric($input['id'])) {
            $notifId = (int) $input['id'];
        }
    } else {
        // Fallback for form-encoded clients (legacy pages)
        if (isset($_POST['notification_id'])) {
            if ($_POST['notification_id'] === 'all') {
                $markAll = true;
            } elseif (is_numeric($_POST['notification_id'])) {
                $notifId = (int) $_POST['notification_id'];
            }
        } elseif (isset($_POST['id'])) {
            if ($_POST['id'] === 'all') {
                $markAll = true;
            } elseif (is_numeric($_POST['id'])) {
                $notifId = (int) $_POST['id'];
            }
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