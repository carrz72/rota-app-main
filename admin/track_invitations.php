<?php
require '../includes/auth.php';
requireAdmin();
require_once '../includes/db.php';

$currentAdminId = $_SESSION['user_id'] ?? null;
$adminBranchId = null;
$adminRole = $_SESSION['role'] ?? '';
if ($currentAdminId) {
    $bstmt = $conn->prepare("SELECT branch_id FROM users WHERE id = ? LIMIT 1");
    $bstmt->execute([$currentAdminId]);
    $adminBranchId = $bstmt->fetchColumn();
}

// Build invitations query. Non-super admins see only invitations from their branch (or their own invites).
$query = "SELECT si.*, r.name AS role_name, a.username AS admin_username, a.branch_id AS admin_branch_id, t.username AS target_username
          FROM shift_invitations si
          JOIN users a ON si.admin_id = a.id
          LEFT JOIN roles r ON si.role_id = r.id
          LEFT JOIN users t ON si.user_id = t.id";
if ($adminRole !== 'super_admin') {
    $query .= " WHERE (a.branch_id = :branch OR (a.branch_id IS NULL AND :branch_is_null = 1) OR si.admin_id = :me)";
}
$query .= " ORDER BY si.created_at DESC";

$stmt = $conn->prepare($query);
if ($adminRole !== 'super_admin') {
    $stmt->execute([':branch' => $adminBranchId, ':branch_is_null' => (is_null($adminBranchId) ? 1 : 0), ':me' => $currentAdminId]);
} else {
    $stmt->execute();
}
$invitations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper to count declines
function count_declines($conn, $invitation_id)
{
    $s = $conn->prepare("SELECT COUNT(*) FROM decline_responses WHERE invitation_id = ?");
    $s->execute([$invitation_id]);
    return (int) $s->fetchColumn();
}

// Handle clearing (deleting) an invitation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_invitation'])) {
    $inv_id = (int) ($_POST['invitation_id'] ?? 0);

    // fetch invitation and its admin branch for authorization
    $check = $conn->prepare("SELECT si.id, a.branch_id AS admin_branch FROM shift_invitations si JOIN users a ON si.admin_id = a.id WHERE si.id = ? LIMIT 1");
    $check->execute([$inv_id]);
    $invRow = $check->fetch(PDO::FETCH_ASSOC);
    if (!$invRow) {
        $_SESSION['error_message'] = 'Invitation not found.';
        header('Location: track_invitations.php');
        exit;
    }

    if ($adminRole !== 'super_admin') {
        // Only allow clearing invitations sent by admins in your branch
        if ($invRow['admin_branch'] !== $adminBranchId) {
            $_SESSION['error_message'] = 'Not authorized to clear this invitation.';
            header('Location: track_invitations.php');
            exit;
        }
    }

    try {
        // Remove decline responses, related notifications, then the invitation itself
        $d1 = $conn->prepare("DELETE FROM decline_responses WHERE invitation_id = ?");
        $d1->execute([$inv_id]);

        $d2 = $conn->prepare("DELETE FROM notifications WHERE related_id = ? AND type = 'shift-invite'");
        $d2->execute([$inv_id]);

        $d3 = $conn->prepare("DELETE FROM shift_invitations WHERE id = ?");
        $ok = $d3->execute([$inv_id]);

        if ($ok) {
            $_SESSION['success_message'] = 'Invitation cleared successfully.';
        } else {
            $_SESSION['error_message'] = 'Failed to clear invitation.';
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = 'Error clearing invitation: ' . $e->getMessage();
    }

    header('Location: track_invitations.php');
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php $PAGE_TITLE = 'Track Shift Invitations';
    require_once __DIR__ . '/admin_head.php'; ?>
    <link rel="stylesheet" href="../css/admin_dashboard.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
</head>

<body>
    <div class="admin-container">
        <div class="admin-header">
            <div class="admin-title">
                <h1><i class="fas fa-search"></i> Track Shift Invitations</h1>
            </div>
            <div class="admin-actions">
                <a href="admin_dashboard.php" class="admin-btn secondary"><i class="fas fa-arrow-left"></i> Back</a>
            </div>
        </div>

        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="success-message">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success_message']); ?>
            </div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>
        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['error_message']); ?>
            </div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>

        <div class="admin-panel">
            <div class="admin-panel-header">
                <h2>Invitations</h2>
            </div>
            <div class="admin-panel-body">
                <table class="data-table" style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Role</th>
                            <th>Location</th>
                            <th>Sent By</th>
                            <th>Target</th>
                            <th>Status</th>
                            <th>Declines</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($invitations as $inv): ?>
                            <tr>
                                <td><?php echo (int) $inv['id']; ?></td>
                                <td><?php echo htmlspecialchars($inv['shift_date']); ?></td>
                                <td><?php echo htmlspecialchars($inv['start_time'] . ' - ' . $inv['end_time']); ?></td>
                                <td><?php echo htmlspecialchars($inv['role_name'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($inv['location']); ?></td>
                                <td><?php echo htmlspecialchars($inv['admin_username']); ?></td>
                                <td><?php echo $inv['user_id'] ? htmlspecialchars($inv['target_username']) : '<em>Everyone</em>'; ?>
                                </td>
                                <td><?php echo htmlspecialchars($inv['status']); ?></td>
                                <td><?php echo $inv['user_id'] ? '-' : count_declines($conn, $inv['id']); ?></td>
                                <td>
                                    <?php if (is_null($inv['user_id'])): ?>
                                        <a href="view_invitation_declines.php?invitation_id=<?php echo (int) $inv['id']; ?>">View
                                            declines</a>
                                    <?php else: ?>
                                        &nbsp;
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="POST"
                                        onsubmit="return confirm('Clear this invitation and remove associated responses/notifications?');"
                                        style="display:inline;">
                                        <input type="hidden" name="invitation_id" value="<?php echo (int) $inv['id']; ?>">
                                        <button type="submit" name="clear_invitation"
                                            class="admin-btn small danger">Clear</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>

</html>