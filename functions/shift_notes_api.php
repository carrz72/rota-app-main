<?php
session_start();
require_once '../includes/db.php';
require_once 'send_shift_notification.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
header('Content-Type: application/json');

// Get action
$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'get_notes':
            // Get all notes for a specific shift
            $shift_id = isset($_GET['shift_id']) ? (int) $_GET['shift_id'] : null;

            if ($shift_id === null || $shift_id < 0) {
                throw new Exception('Invalid shift ID');
            }

            // Verify user has access to this shift (is assigned to it or is admin)
            $accessStmt = $conn->prepare("
                SELECT s.*, u.username, u.role as user_role
                FROM shifts s
                JOIN users u ON s.user_id = u.id
                WHERE s.id = ?
            ");
            $accessStmt->execute([$shift_id]);
            $shift = $accessStmt->fetch(PDO::FETCH_ASSOC);

            if (!$shift) {
                throw new Exception('Shift not found');
            }

            $is_admin = in_array($_SESSION['role'] ?? '', ['admin', 'super_admin']);
            $is_assigned = $shift['user_id'] == $user_id;

            if (!$is_admin && !$is_assigned) {
                throw new Exception('You do not have access to this shift');
            }

            // Get all notes for this shift
            $stmt = $conn->prepare("
                SELECT n.*, u.username as author_name
                FROM shift_notes n
                JOIN users u ON n.created_by = u.id
                WHERE n.shift_id = ?
                ORDER BY n.is_important DESC, n.created_at DESC
            ");
            $stmt->execute([$shift_id]);
            $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'notes' => $notes,
                'shift' => $shift,
                'can_edit' => $is_admin
            ]);
            break;

        case 'add_note':
            // Add a new note to a shift
            $shift_id = isset($_POST['shift_id']) ? (int) $_POST['shift_id'] : null;
            $note = trim($_POST['note'] ?? '');
            $is_important = (int) ($_POST['is_important'] ?? 0);

            if ($shift_id === null || $shift_id < 0) {
                throw new Exception('Invalid shift ID');
            }

            if (empty($note)) {
                throw new Exception('Note cannot be empty');
            }

            if (strlen($note) > 5000) {
                throw new Exception('Note is too long (max 5000 characters)');
            }

            // Verify shift exists and user has access
            $shiftStmt = $conn->prepare("SELECT user_id, shift_date, start_time, location FROM shifts WHERE id = ?");
            $shiftStmt->execute([$shift_id]);
            $shift = $shiftStmt->fetch(PDO::FETCH_ASSOC);

            if (!$shift) {
                throw new Exception('Shift not found');
            }

            $is_admin = in_array($_SESSION['role'] ?? '', ['admin', 'super_admin']);
            $is_assigned = $shift['user_id'] == $user_id;

            if (!$is_admin && !$is_assigned) {
                throw new Exception('You can only add notes to your own shifts');
            }

            // Insert note
            $stmt = $conn->prepare("
                INSERT INTO shift_notes (shift_id, created_by, note, is_important)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$shift_id, $user_id, $note, $is_important]);
            $note_id = $conn->lastInsertId();

            // Send notification to shift worker if different from note author
            if ($shift['user_id'] != $user_id && $shift['user_id']) {
                $author_name = $_SESSION['username'] ?? 'Someone';
                $shift_date = date('M j', strtotime($shift['shift_date']));
                $importance = $is_important ? ' (Important)' : '';

                notifyShiftNote(
                    $shift['user_id'],
                    [
                        'shift_id' => $shift_id,
                        'author_name' => $author_name,
                        'note_preview' => substr($note, 0, 100),
                        'shift_date' => $shift_date,
                        'is_important' => $is_important
                    ]
                );
            }

            echo json_encode([
                'success' => true,
                'message' => 'Note added successfully',
                'note_id' => $note_id
            ]);
            break;

        case 'toggle_important':
            // Toggle the importance flag of a note
            $note_id = (int) ($_POST['note_id'] ?? 0);

            if ($note_id <= 0) {
                throw new Exception('Invalid note ID');
            }

            // Get note details
            $noteStmt = $conn->prepare("SELECT created_by, is_important FROM shift_notes WHERE id = ?");
            $noteStmt->execute([$note_id]);
            $note = $noteStmt->fetch(PDO::FETCH_ASSOC);

            if (!$note) {
                throw new Exception('Note not found');
            }

            $is_admin = in_array($_SESSION['role'] ?? '', ['admin', 'super_admin']);
            $is_author = $note['created_by'] == $user_id;

            if (!$is_admin && !$is_author) {
                throw new Exception('You can only modify your own notes');
            }

            // Toggle importance
            $new_importance = $note['is_important'] ? 0 : 1;
            $stmt = $conn->prepare("UPDATE shift_notes SET is_important = ? WHERE id = ?");
            $stmt->execute([$new_importance, $note_id]);

            echo json_encode([
                'success' => true,
                'is_important' => $new_importance,
                'message' => $new_importance ? 'Marked as important' : 'Unmarked as important'
            ]);
            break;

        case 'delete_note':
            // Delete a note
            $note_id = (int) ($_POST['note_id'] ?? 0);

            if ($note_id <= 0) {
                throw new Exception('Invalid note ID');
            }

            // Get note details
            $noteStmt = $conn->prepare("SELECT created_by FROM shift_notes WHERE id = ?");
            $noteStmt->execute([$note_id]);
            $note = $noteStmt->fetch(PDO::FETCH_ASSOC);

            if (!$note) {
                throw new Exception('Note not found');
            }

            $is_admin = in_array($_SESSION['role'] ?? '', ['admin', 'super_admin']);
            $is_author = $note['created_by'] == $user_id;

            if (!$is_admin && !$is_author) {
                throw new Exception('You can only delete your own notes');
            }

            // Delete note
            $stmt = $conn->prepare("DELETE FROM shift_notes WHERE id = ?");
            $stmt->execute([$note_id]);

            echo json_encode([
                'success' => true,
                'message' => 'Note deleted successfully'
            ]);
            break;

        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>