<?php
session_start();
require_once '../includes/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
header('Content-Type: application/json');

// Handle different actions
$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'get':
            // Get all custom reminders for the user
            $stmt = $conn->prepare("SELECT * FROM shift_reminder_preferences WHERE user_id = ? ORDER BY reminder_type, reminder_value");
            $stmt->execute([$user_id]);
            $reminders = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'reminders' => $reminders]);
            break;

        case 'add':
            // Add a new custom reminder
            $type = $_POST['type'] ?? 'hours';
            $value = (int) ($_POST['value'] ?? 1);

            // Validate
            if (!in_array($type, ['minutes', 'hours', 'days'])) {
                throw new Exception('Invalid reminder type');
            }

            if ($value < 1) {
                throw new Exception('Reminder value must be at least 1');
            }

            // Check for duplicates
            $checkStmt = $conn->prepare("SELECT id FROM shift_reminder_preferences WHERE user_id = ? AND reminder_type = ? AND reminder_value = ?");
            $checkStmt->execute([$user_id, $type, $value]);
            if ($checkStmt->fetch()) {
                throw new Exception('This reminder already exists');
            }

            // Insert
            $stmt = $conn->prepare("INSERT INTO shift_reminder_preferences (user_id, reminder_type, reminder_value, enabled) VALUES (?, ?, ?, 1)");
            $stmt->execute([$user_id, $type, $value]);

            echo json_encode(['success' => true, 'message' => 'Reminder added successfully', 'id' => $conn->lastInsertId()]);
            break;

        case 'toggle':
            // Toggle reminder enabled/disabled
            $id = (int) ($_POST['id'] ?? 0);
            $enabled = (int) ($_POST['enabled'] ?? 0);

            $stmt = $conn->prepare("UPDATE shift_reminder_preferences SET enabled = ? WHERE id = ? AND user_id = ?");
            $stmt->execute([$enabled, $id, $user_id]);

            if ($stmt->rowCount() > 0) {
                echo json_encode(['success' => true, 'message' => 'Reminder updated']);
            } else {
                throw new Exception('Reminder not found');
            }
            break;

        case 'delete':
            // Delete a custom reminder
            $id = (int) ($_POST['id'] ?? 0);

            $stmt = $conn->prepare("DELETE FROM shift_reminder_preferences WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $user_id]);

            if ($stmt->rowCount() > 0) {
                echo json_encode(['success' => true, 'message' => 'Reminder deleted']);
            } else {
                throw new Exception('Reminder not found');
            }
            break;

        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>