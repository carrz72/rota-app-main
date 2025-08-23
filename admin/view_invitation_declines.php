<?php
require '../includes/auth.php';
requireAdmin();
require_once '../includes/db.php';

$invitation_id = isset($_GET['invitation_id']) ? (int)$_GET['invitation_id'] : 0;
if (!$invitation_id) {
    die('Invalid invitation id');
}

// Verify the admin can view this invitation (similar branch rules as elsewhere)
$currentAdminId = $_SESSION['user_id'] ?? null;
$adminRole = $_SESSION['role'] ?? '';
$adminBranchId = null;
if ($currentAdminId) {
    $bstmt = $conn->prepare("SELECT branch_id FROM users WHERE id = ? LIMIT 1");
    $bstmt->execute([$currentAdminId]);
    $adminBranchId = $bstmt->fetchColumn();
}

// Get invitation
$stmt = $conn->prepare("SELECT si.*, a.branch_id AS admin_branch_id FROM shift_invitations si JOIN users a ON si.admin_id = a.id WHERE si.id = ? LIMIT 1");
$stmt->execute([$invitation_id]);
$inv = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$inv) {
    die('Invitation not found');
}
if ($adminRole !== 'super_admin') {
    $invAdminBranch = $inv['admin_branch_id'];
    if ($invAdminBranch !== $adminBranchId) {
        die('Not authorized to view this invitation');
    }
}

// Get declines
$declStmt = $conn->prepare("SELECT dr.*, u.username, u.email FROM decline_responses dr JOIN users u ON dr.user_id = u.id WHERE dr.invitation_id = ? ORDER BY dr.responded_at DESC");
$declStmt->execute([$invitation_id]);
$declines = $declStmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Invitation Declines</title>
    <link rel="stylesheet" href="../css/admin_dashboard.css?v=<?php echo time(); ?>">
</head>
<body>
    <div class="admin-container">
        <div class="admin-header">
            <div class="admin-title">
                <h1><i class="fas fa-user-times"></i> Declined Responses for Invitation #<?php echo (int)$invitation_id; ?></h1>
            </div>
            <div class="admin-actions">
                <a href="track_invitations.php" class="admin-btn secondary"><i class="fas fa-arrow-left"></i> Back</a>
            </div>
        </div>

        <div class="admin-panel">
            <div class="admin-panel-body">
                <?php if (empty($declines)): ?>
                    <p>No declines recorded.</p>
                <?php else: ?>
                    <table class="data-table" style="width:100%;border-collapse:collapse;">
                        <thead>
                            <tr><th>User</th><th>Email</th><th>Responded At</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($declines as $d): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($d['username']); ?></td>
                                    <td><?php echo htmlspecialchars($d['email']); ?></td>
                                    <td><?php echo htmlspecialchars($d['responded_at']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
