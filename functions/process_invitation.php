<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../functions/login.php");
    exit;
}
require_once '../includes/db.php';

$user_id = $_SESSION['user_id'];
$invitation_id = $_POST['invitation_id'] ?? 0;
$action = $_POST['action'] ?? '';
if (!$invitation_id || !in_array($action, ['accept', 'decline'])) {
    die("Invalid request.");
}
$status = ($action === 'accept') ? 'accepted' : 'declined';

$stmt = $conn->prepare("UPDATE shift_invitations SET status = ? WHERE id = ? AND user_id = ?");
if($stmt->execute([$status, $invitation_id, $user_id])){
    // Optionally, you could send a notification back or update other tables.
    header("Location: ../users/shifts.php");
    exit;
} else {
    die("Failed to update invitation.");
}
?>