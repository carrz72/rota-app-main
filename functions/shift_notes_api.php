<?php
/**
 * Shift Notes API - Manage shift handover notes
 * Handles: adding notes, viewing notes, marking important, deleting
 */

session_start();
require_once '../includes/db.php';

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

header('Content-Type: application/json');

try {
    switch ($action) {
        
        // Get notes for a shift
        case 'get_notes':
            $shift_id = (int)($_GET['shift_id'] ?? 0);
            
            if (!$shift_id) {
                throw new Exception('Shift ID required');
            }
            
            // Get shift info first
            $shiftStmt = $conn->prepare("
                SELECT s.*, u.username, r.name as role_name
                FROM shifts s
                INNER JOIN users u ON s.user_id = u.id
                INNER JOIN roles r ON s.role_id = r.id
                WHERE s.id = ?
            ");
            $shiftStmt->execute([$shift_id]);
            $shift = $shiftStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$shift) {
                throw new Exception('Shift not found');
            }
            
            // Get notes
            $notesStmt = $conn->prepare("
                SELECT 
                    sn.*,
                    u.username as author_name,
                    u.profile_picture as author_picture
                FROM shift_notes sn
                INNER JOIN users u ON sn.created_by = u.id
                WHERE sn.shift_id = ?
                ORDER BY sn.is_important DESC, sn.created_at DESC
            ");
            $notesStmt->execute([$shift_id]);
            $notes = $notesStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Decode JSON attachments
            foreach ($notes as &$note) {
                if ($note['attachments']) {
                    $note['attachments'] = json_decode($note['attachments'], true);
                }
            }
            
            echo json_encode(['success' => true, 'shift' => $shift, 'notes' => $notes]);
            break;
            
        // Get notes for user's upcoming shifts
        case 'get_my_shift_notes':
            $limit = min((int)($_GET['limit'] ?? 10), 50);
            
            $query = "
                SELECT 
                    s.id as shift_id, s.shift_date, s.start_time, s.end_time, s.location,
                    r.name as role_name,
                    COUNT(sn.id) as note_count,
                    SUM(sn.is_important) as important_count,
                    MAX(sn.created_at) as latest_note_at
                FROM shifts s
                INNER JOIN roles r ON s.role_id = r.id
                LEFT JOIN shift_notes sn ON sn.shift_id = s.id
                WHERE s.user_id = ? 
                AND s.shift_date >= CURDATE()
                GROUP BY s.id
                HAVING note_count > 0
                ORDER BY s.shift_date ASC, s.start_time ASC
                LIMIT ?
            ";
            
            $stmt = $conn->prepare($query);
            $stmt->execute([$user_id, $limit]);
            $shifts_with_notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'shifts' => $shifts_with_notes]);
            break;
            
        // Add a new note
        case 'add_note':
            $shift_id = (int)($_POST['shift_id'] ?? 0);
            $note = trim($_POST['note'] ?? '');
            $is_important = (int)($_POST['is_important'] ?? 0);
            $is_private = (int)($_POST['is_private'] ?? 0);
            
            if (!$shift_id) {
                throw new Exception('Shift ID required');
            }
            
            if (empty($note)) {
                throw new Exception('Note cannot be empty');
            }
            
            if (strlen($note) > 5000) {
                throw new Exception('Note too long (max 5000 characters)');
            }
            
            // Verify shift exists
            $shiftStmt = $conn->prepare("SELECT user_id FROM shifts WHERE id = ?");
            $shiftStmt->execute([$shift_id]);
            $shift = $shiftStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$shift) {
                throw new Exception('Shift not found');
            }
            
            // Insert note
            $insertStmt = $conn->prepare("
                INSERT INTO shift_notes (shift_id, created_by, note, is_important, is_private)
                VALUES (?, ?, ?, ?, ?)
            ");
            $insertStmt->execute([$shift_id, $user_id, $note, $is_important, $is_private]);
            $note_id = $conn->lastInsertId();
            
            // Send notification to shift owner if different from creator
            if ($shift['user_id'] != $user_id && !$is_private) {
                require_once 'addNotification.php';
                require_once 'send_shift_notification.php';
                
                $author_name = $_SESSION['username'] ?? 'Someone';
                $message = "$author_name added a note to your shift";
                if ($is_important) {
                    $message .= " (marked important)";
                }
                
                addNotification($conn, $shift['user_id'], $message, 'shift_note', $shift_id);
                
                // Send push notification
                $shiftInfoStmt = $conn->prepare("
                    SELECT DATE_FORMAT(shift_date, '%M %d') as date_str, 
                           DATE_FORMAT(start_time, '%h:%i %p') as time_str
                    FROM shifts WHERE id = ?
                ");
                $shiftInfoStmt->execute([$shift_id]);
                $shiftInfo = $shiftInfoStmt->fetch(PDO::FETCH_ASSOC);
                
                sendPushNotification(
                    $shift['user_id'],
                    "New shift note" . ($is_important ? " ⭐" : ""),
                    "{$author_name}: " . substr($note, 0, 50) . (strlen($note) > 50 ? '...' : ''),
                    ['url' => '/users/shift_notes.php?shift_id=' . $shift_id]
                );
            }
            
            // Get the complete note data
            $noteStmt = $conn->prepare("
                SELECT sn.*, u.username as author_name, u.profile_picture as author_picture
                FROM shift_notes sn
                INNER JOIN users u ON sn.created_by = u.id
                WHERE sn.id = ?
            ");
            $noteStmt->execute([$note_id]);
            $newNote = $noteStmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'note' => $newNote, 'note_id' => $note_id]);
            break;
            
        // Toggle important status
        case 'toggle_important':
            $note_id = (int)($_POST['note_id'] ?? 0);
            $is_important = (int)($_POST['is_important'] ?? 0);
            
            if (!$note_id) {
                throw new Exception('Note ID required');
            }
            
            // Verify ownership or admin
            $is_admin = in_array($_SESSION['role'] ?? '', ['admin', 'super_admin']);
            
            $checkStmt = $conn->prepare("SELECT created_by FROM shift_notes WHERE id = ?");
            $checkStmt->execute([$note_id]);
            $note = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$note) {
                throw new Exception('Note not found');
            }
            
            if ($note['created_by'] != $user_id && !$is_admin) {
                throw new Exception('Access denied');
            }
            
            // Update
            $updateStmt = $conn->prepare("UPDATE shift_notes SET is_important = ? WHERE id = ?");
            $updateStmt->execute([$is_important, $note_id]);
            
            echo json_encode(['success' => true, 'message' => 'Note updated']);
            break;
            
        // Delete a note
        case 'delete_note':
            $note_id = (int)($_POST['note_id'] ?? 0);
            
            if (!$note_id) {
                throw new Exception('Note ID required');
            }
            
            // Verify ownership or admin
            $is_admin = in_array($_SESSION['role'] ?? '', ['admin', 'super_admin']);
            
            $checkStmt = $conn->prepare("SELECT created_by FROM shift_notes WHERE id = ?");
            $checkStmt->execute([$note_id]);
            $note = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$note) {
                throw new Exception('Note not found');
            }
            
            if ($note['created_by'] != $user_id && !$is_admin) {
                throw new Exception('Access denied');
            }
            
            // Delete
            $deleteStmt = $conn->prepare("DELETE FROM shift_notes WHERE id = ?");
            $deleteStmt->execute([$note_id]);
            
            echo json_encode(['success' => true, 'message' => 'Note deleted']);
            break;
            
        // Get statistics
        case 'get_stats':
            $stats = [];
            
            // Total notes count
            $totalStmt = $conn->query("SELECT COUNT(*) FROM shift_notes");
            $stats['total_notes'] = $totalStmt->fetchColumn();
            
            // Important notes count
            $importantStmt = $conn->query("SELECT COUNT(*) FROM shift_notes WHERE is_important = 1");
            $stats['important_notes'] = $importantStmt->fetchColumn();
            
            // Notes this week
            $weekStmt = $conn->query("
                SELECT COUNT(*) FROM shift_notes 
                WHERE YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)
            ");
            $stats['notes_this_week'] = $weekStmt->fetchColumn();
            
            // My notes
            $myNotesStmt = $conn->prepare("SELECT COUNT(*) FROM shift_notes WHERE created_by = ?");
            $myNotesStmt->execute([$user_id]);
            $stats['my_notes'] = $myNotesStmt->fetchColumn();
            
            echo json_encode(['success' => true, 'stats' => $stats]);
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
