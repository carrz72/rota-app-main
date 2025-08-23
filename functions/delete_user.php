<?php
require_once '../includes/auth.php';
requireAdmin();
require_once '../includes/db.php';

if (isset($_GET['id'])) {
    $id = $_GET['id'];

    // Don't allow deletion of the current user (admin)
    if ($id == $_SESSION['user_id']) {
        $_SESSION['error_message'] = "You cannot delete your own account.";
        header("Location: ../admin/admin_dashboard.php");
        exit();
    }

    // Check if user exists
    $checkStmt = $conn->prepare("SELECT id, username FROM users WHERE id = ?");
    $checkStmt->execute([$id]);
    $user = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        $_SESSION['error_message'] = "User not found.";
        header("Location: ../admin/admin_dashboard.php");
        exit();
    }

    try {
        // Start transaction
        $conn->beginTransaction();

        // Delete user's shifts first (to maintain referential integrity)
        $deleteShiftsStmt = $conn->prepare("DELETE FROM shifts WHERE user_id = ?");
        $deleteShiftsStmt->execute([$id]);

        // Delete user's payroll calculations
        $deletePayrollStmt = $conn->prepare("DELETE FROM payroll_calculations WHERE user_id = ?");
        $deletePayrollStmt->execute([$id]);

        // Delete the user
        $deleteUserStmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $deleteUserStmt->execute([$id]);

        // Commit transaction
        $conn->commit();

        $_SESSION['success_message'] = "User '" . htmlspecialchars($user['username']) . "' has been deleted successfully.";

    // Audit deletion
    try { require_once __DIR__ . '/../includes/audit_log.php'; log_audit($conn, $_SESSION['user_id'], 'delete_user', ['username' => $user['username']], $id, 'user', session_id()); } catch (Exception $e) {}

    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        $_SESSION['error_message'] = "Error deleting user: " . $e->getMessage();
    }

    header("Location: ../admin/admin_dashboard.php");
    exit();
} else {
    $_SESSION['error_message'] = "No user ID specified.";
    header("Location: ../admin/admin_dashboard.php");
    exit();
}
?>