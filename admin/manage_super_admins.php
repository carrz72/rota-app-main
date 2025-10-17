<?php
require_once '../includes/auth.php';
require_once '../includes/super_admin.php';
require_once '../includes/db.php';

// Only super admins can access this page
requireSuperAdmin();

// Handle promotion/demotion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_POST['user_id'])) {
    $action = $_POST['action'];
    $userId = (int) $_POST['user_id'];

    if ($action === 'promote') {
        $stmt = $conn->prepare("UPDATE users SET role = 'super_admin' WHERE id = ?");
        $stmt->execute([$userId]);
        $_SESSION['success_message'] = "User promoted to Super Admin.";
    } elseif ($action === 'demote') {
        // Demote back to regular admin (or blank)
        $stmt = $conn->prepare("UPDATE users SET role = 'admin' WHERE id = ?");
        $stmt->execute([$userId]);
        $_SESSION['success_message'] = "Super Admin revoked.";
    }

    header('Location: manage_super_admins.php');
    exit;
}

// Fetch users and their roles
$users = $conn->query("SELECT id, username, email, role, branch_id FROM users ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php $PAGE_TITLE = 'Manage Super Admins';
    require_once __DIR__ . '/admin_head.php'; ?>
    <link rel="stylesheet" href="../css/admin_dashboard.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
</head>

<body>
    <div class="admin-container">
        <div class="admin-header">
            <div class="admin-title">
                <h1><i class="fas fa-user-shield"></i> Manage Super Admins</h1>
            </div>
            <div class="admin-actions">
                <a href="admin_dashboard.php" class="admin-btn secondary"><i class="fas fa-arrow-left"></i> Back</a>
            </div>
        </div>

        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="success-message"><?php echo $_SESSION['success_message'];
            unset($_SESSION['success_message']); ?>
            </div>
        <?php endif; ?>

        <div class="admin-panel">
            <div class="admin-panel-header">
                <h2>Users</h2>
            </div>
            <div class="admin-panel-body">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Branch</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                            <tr>
                                <td><?php echo $u['id']; ?></td>
                                <td><?php echo htmlspecialchars($u['username']); ?></td>
                                <td><?php echo htmlspecialchars($u['email']); ?></td>
                                <td><?php echo htmlspecialchars($u['role']); ?></td>
                                <td><?php echo htmlspecialchars($u['branch_id']); ?></td>
                                <td>
                                    <?php if ($u['role'] !== 'super_admin'): ?>
                                        <form method="POST" style="display:inline">
                                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                            <input type="hidden" name="action" value="promote">
                                            <button class="admin-btn" type="submit">Promote to Super Admin</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" style="display:inline">
                                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                            <input type="hidden" name="action" value="demote">
                                            <button class="admin-btn secondary" type="submit">Revoke Super Admin</button>
                                        </form>
                                    <?php endif; ?>
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