<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/notifications.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../functions/login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
if (!isset($_POST['invitation_id']) || !isset($_POST['action'])) {
    die("Invalid request.");
}

$invitation_id = intval($_POST['invitation_id']);
$action = $_POST['action'];

if (!in_array($action, ['accept', 'decline'])) {
    die("Invalid action.");
}

// Retrieve the invitation (targeted or broadcast)
$stmt = $conn->prepare("SELECT * FROM shift_invitations WHERE id = ? AND (user_id = ? OR user_id IS NULL)");
$stmt->execute([$invitation_id, $user_id]);
$invitation = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$invitation) {
    die("Invitation not found.");
}
if ($action === 'accept') {
    $stmtUpdate = $conn->prepare("UPDATE shift_invitations SET status = 'accepted' WHERE id = ?");
    $stmtUpdate->execute([$invitation_id]);

    $stmtInsert = $conn->prepare("INSERT INTO shifts (user_id, shift_date, start_time, end_time, role_id, location) VALUES (?, ?, ?, ?, ?, ?)");
    $stmtInsert->execute([
        $user_id,
        $invitation['shift_date'],
        $invitation['start_time'],
        $invitation['end_time'],
        $invitation['role_id'],
        $invitation['location']
    ]);
    // Audit: invitation accepted and shift added
    try { require_once __DIR__ . '/../includes/audit_log.php'; log_audit($conn, $user_id, 'shift_invitation_accepted', ['created_shift_id' => $conn->lastInsertId()], $invitation_id, 'shift_invitation', session_id()); } catch (Exception $e) {}

    addNotification($conn, $user_id, "Shift accepted and added to your schedule.", "success");

} else { // Decline action
    if (is_null($invitation['user_id'])) {
        // For broadcast invitations, record that the user declined this particular shift.
        $stmtDecline = $conn->prepare("INSERT INTO decline_responses (invitation_id, user_id) VALUES (?, ?)");
        $stmtDecline->execute([$invitation_id, $user_id]);
        addNotification($conn, $user_id, "Shift invitation declined.", "info");
    // Audit: broadcast invitation declined by user
    try { require_once __DIR__ . '/../includes/audit_log.php'; log_audit($conn, $user_id, 'shift_invitation_declined', [], $invitation_id, 'shift_invitation', session_id()); } catch (Exception $e) {}
        // Note: we're not marking the notification as read, so if other shifts
        // are available in the broadcast, the notification remains clickable.
    } else {
        // For targeted invitations, update status to declined.
        $stmtUpdate = $conn->prepare("UPDATE shift_invitations SET status = 'declined' WHERE id = ?");
        $stmtUpdate->execute([$invitation_id]);
        addNotification($conn, $user_id, "Shift invitation declined.", "info");
    // Audit: targeted invitation declined
    try { require_once __DIR__ . '/../includes/audit_log.php'; log_audit($conn, $user_id, 'shift_invitation_declined', [], $invitation_id, 'shift_invitation', session_id()); } catch (Exception $e) {}
        // Mark notification as read since this targeted invitation is now declined.
        if (isset($_POST['notif_id'])) {
            $notif_id = intval($_POST['notif_id']);
            $stmtNotif = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?");
            $stmtNotif->execute([$notif_id]);
        }
    }
}

header("Location: ../users/shifts.php");
exit;
?>