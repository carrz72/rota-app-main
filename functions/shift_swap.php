<?php
// Minimal shift swap propose/accept handler for tests.
// This is intentionally simple and not wired into UI; used by tests only.
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../functions/addNotification.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit('Unauthorized');
}

$action = $_POST['action'] ?? '';

// Ensure swap table exists (for tests, if missing in schema)
$conn->exec("CREATE TABLE IF NOT EXISTS shift_swaps (
  id INT AUTO_INCREMENT PRIMARY KEY,
  from_user_id INT NOT NULL,
  to_user_id INT NOT NULL,
    from_shift_id INT NOT NULL,
    to_shift_id INT DEFAULT NULL,
    request_id INT DEFAULT NULL,
  status ENUM('proposed','accepted','declined','cancelled') DEFAULT 'proposed',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if ($action === 'propose') {
    $from_user = (int)$_SESSION['user_id'];
    $to_user = (int)($_POST['to_user_id'] ?? 0);
    $from_shift = (int)($_POST['from_shift_id'] ?? 0);
    $to_shift = isset($_POST['to_shift_id']) ? (int)$_POST['to_shift_id'] : 0;

    // Basic validation: require to_user and from_shift; to_shift optional (recipient chooses later)
    if (!$to_user || !$from_shift) {
        http_response_code(400);
        echo 'invalid';
        exit;
    }

    // Ensure the from_shift actually belongs to the proposer
    $stmt = $conn->prepare('SELECT user_id FROM shifts WHERE id=?');
    $stmt->execute([$from_shift]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int)$row['user_id'] !== $from_user) {
        http_response_code(403);
        echo 'not_owner';
        exit;
    }

    $stmt = $conn->prepare('INSERT INTO shift_swaps (from_user_id, to_user_id, from_shift_id, to_shift_id) VALUES (?,?,?,?)');
    $stmt->execute([$from_user, $to_user, $from_shift, $to_shift ? $to_shift : null]);
    // Get the inserted swap id and include it as related_id on the notification so the user can open the swap
    $swapId = (int)$conn->lastInsertId();
    // Audit: new swap proposed
    try { require_once __DIR__ . '/../includes/audit_log.php'; log_audit($conn, $_SESSION['user_id'] ?? null, 'shift_swap_proposed', ['from_user' => $from_user, 'to_user' => $to_user, 'from_shift' => $from_shift, 'to_shift' => $to_shift], $swapId, 'shift_swap', session_id()); } catch (Exception $e) {}
    addNotification($conn, $to_user, 'You have a new shift swap proposal.', 'shift-swap', $swapId);
    echo 'proposed';
    exit;
}

if ($action === 'accept') {
    $swap_id = (int)($_POST['swap_id'] ?? 0);

    $stmt = $conn->prepare('SELECT * FROM shift_swaps WHERE id=?');
    $stmt->execute([$swap_id]);
    $swap = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$swap) { echo 'not_found'; exit; }
    // Validate current user is the recipient
    $current = (int)$_SESSION['user_id'];
    if ($current !== (int)$swap['to_user_id']) {
        http_response_code(403);
        echo 'not_allowed';
        exit;
    }

    // Load shifts details
    $s1 = $conn->prepare('SELECT id, user_id, shift_date, start_time, end_time, location FROM shifts WHERE id=?');
    $s1->execute([$swap['from_shift_id']]);
    $fromShift = $s1->fetch(PDO::FETCH_ASSOC);

    // Determine which 'to' shift to use: either the one stored, or provided by acceptor now
    $to_shift_id = $swap['to_shift_id'] ? (int)$swap['to_shift_id'] : (int)($_POST['to_shift_id'] ?? 0);

    if (!$to_shift_id) { echo 'shift_missing'; exit; }

    $s2 = $conn->prepare('SELECT id, user_id, shift_date, start_time, end_time, location FROM shifts WHERE id=?');
    $s2->execute([$to_shift_id]);
    $toShift = $s2->fetch(PDO::FETCH_ASSOC);

    if (!$fromShift || !$toShift) { echo 'shift_missing'; exit; }

    // Ensure the toShift belongs to the accepting user
    if ((int)$toShift['user_id'] !== $current) {
        http_response_code(403);
        echo 'not_owner_to_shift';
        exit;
    }

    // If swap row did not have a to_shift, store the chosen one
    if (empty($swap['to_shift_id'])) {
        $conn->prepare('UPDATE shift_swaps SET to_shift_id = ?, updated_at = NOW() WHERE id = ?')->execute([$to_shift_id, $swap_id]);
        // refresh swap var
        $swap['to_shift_id'] = $to_shift_id;
    }

    // Check recipient doesn't already have a conflicting shift for the date/time of the incoming shift
    $check = $conn->prepare("SELECT COUNT(*) as cnt FROM shifts WHERE user_id = ? AND shift_date = ? AND ((start_time BETWEEN ? AND ?) OR (end_time BETWEEN ? AND ?) OR (? BETWEEN start_time AND end_time)) AND id != ?");
    $check->execute([(int)$swap['to_user_id'], $fromShift['shift_date'], $fromShift['start_time'], $fromShift['end_time'], $fromShift['start_time'], $fromShift['end_time'], $fromShift['start_time'], $swap['to_shift_id']]);
    $conflict = $check->fetch(PDO::FETCH_ASSOC);
    if ($conflict && $conflict['cnt'] > 0) {
        echo 'conflict';
        exit;
    }

    // Also check the other direction: proposer doesn't already have a conflict for the toShift
    $check2 = $conn->prepare("SELECT COUNT(*) as cnt FROM shifts WHERE user_id = ? AND shift_date = ? AND ((start_time BETWEEN ? AND ?) OR (end_time BETWEEN ? AND ?) OR (? BETWEEN start_time AND end_time)) AND id != ?");
    $check2->execute([(int)$swap['from_user_id'], $toShift['shift_date'], $toShift['start_time'], $toShift['end_time'], $toShift['start_time'], $toShift['end_time'], $toShift['start_time'], $swap['from_shift_id']]);
    $conflict2 = $check2->fetch(PDO::FETCH_ASSOC);
    if ($conflict2 && $conflict2['cnt'] > 0) {
        echo 'conflict';
        exit;
    }

    // Perform swap: reassign shift user_ids
    $conn->beginTransaction();
    try {
        $conn->prepare('UPDATE shifts SET user_id=? WHERE id=?')->execute([$swap['to_user_id'], $swap['from_shift_id']]);
        $conn->prepare('UPDATE shifts SET user_id=? WHERE id=?')->execute([$swap['from_user_id'], $swap['to_shift_id']]);
        $conn->prepare("UPDATE shift_swaps SET status='accepted', updated_at=NOW() WHERE id=?")->execute([$swap_id]);
        $conn->commit();
    // Audit: swap accepted and shifts reassigned
    try { require_once __DIR__ . '/../includes/audit_log.php'; log_audit($conn, $_SESSION['user_id'] ?? null, 'shift_swap_accepted', ['from_shift' => $swap['from_shift_id'], 'to_shift' => $swap['to_shift_id'], 'from_user' => $swap['from_user_id'], 'to_user' => $swap['to_user_id']], $swap_id, 'shift_swap', session_id()); } catch (Exception $e) {}
    } catch (Throwable $e) {
        $conn->rollBack();
        throw $e;
    }
    // Build human readable messages for both users
    $toShiftDate = date('M j, Y', strtotime($toShift['shift_date']));
    $toShiftStart = date('g:i A', strtotime($toShift['start_time']));
    $toShiftEnd = date('g:i A', strtotime($toShift['end_time']));
    $toLocation = $toShift['location'] ?? '';

    $fromShiftDate = date('M j, Y', strtotime($fromShift['shift_date']));
    $fromShiftStart = date('g:i A', strtotime($fromShift['start_time']));
    $fromShiftEnd = date('g:i A', strtotime($fromShift['end_time']));
    $fromLocation = $fromShift['location'] ?? '';

    // Proposer (from_user) now has the toShift
    $msgProposer = "Swap accepted: You now have a shift on $toShiftDate ($toShiftStart - $toShiftEnd)";
    if ($toLocation) $msgProposer .= " at $toLocation."; else $msgProposer .= '.';

    // Recipient (to_user) now has the fromShift
    $msgRecipient = "Swap accepted: You now have a shift on $fromShiftDate ($fromShiftStart - $fromShiftEnd)";
    if ($fromLocation) $msgRecipient .= " at $fromLocation."; else $msgRecipient .= '.';

    addNotification($conn, (int)$swap['from_user_id'], $msgProposer, 'shift_update');
    addNotification($conn, (int)$swap['to_user_id'], $msgRecipient, 'shift_update');
    // If notif_id passed, mark that notification as read
    if (!empty($_POST['notif_id'])) {
        $nid = (int)$_POST['notif_id'];
        $conn->prepare('UPDATE notifications SET is_read = 1 WHERE id = ?')->execute([$nid]);
    }
    echo 'accepted';
    exit;
}

if ($action === 'decline') {
    $swap_id = (int)($_POST['swap_id'] ?? 0);
    // mark swap as declined
    $conn->prepare("UPDATE shift_swaps SET status='declined', updated_at=NOW() WHERE id=? AND to_user_id=?")->execute([$swap_id, (int)$_SESSION['user_id']]);
    // Notify proposer
    $row = $conn->prepare('SELECT from_user_id FROM shift_swaps WHERE id=?');
    $row->execute([$swap_id]);
    $r = $row->fetch(PDO::FETCH_ASSOC);
    if ($r) {
        addNotification($conn, (int)$r['from_user_id'], 'Your swap proposal was declined.', 'shift_update');
    }
    // Audit: swap declined
    try { require_once __DIR__ . '/../includes/audit_log.php'; log_audit($conn, $_SESSION['user_id'] ?? null, 'shift_swap_declined', ['to_user' => $_SESSION['user_id']], $swap_id, 'shift_swap', session_id()); } catch (Exception $e) {}
    if (!empty($_POST['notif_id'])) {
        $nid = (int)$_POST['notif_id'];
        $conn->prepare('UPDATE notifications SET is_read = 1 WHERE id = ?')->execute([$nid]);
    }
    echo 'declined';
    exit;
}

echo 'noop';
