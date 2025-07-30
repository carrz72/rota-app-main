<?php
require_once '../includes/auth.php';
require_once '../includes/SessionManager.php';
require_once '../includes/PermissionManager.php';
require_once '../includes/DatabaseManager.php';
require_once '../includes/Validator.php';
require_once '../functions/branch_functions.php';

// Initialize managers
SessionManager::requireAdmin();
$permissions = new PermissionManager($conn, SessionManager::getUserId());
$db = new DatabaseManager($conn);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_branch'])) {
        // Check permissions
        if (!$permissions->canCreateBranch()) {
            SessionManager::redirectWithError('branch_management.php', 'Only system administrators can create new branches.');
        }

        // Validate input
        $validator = Validator::validateBranch($_POST);

        if ($validator->fails()) {
            SessionManager::redirectWithError('branch_management.php', $validator->getFirstError());
        }

        // Insert branch
        $data = $validator->getSanitizedData();
        $data['code'] = strtoupper($data['code']);

        $result = $db->insert('branches', $data);

        if ($result['success']) {
            SessionManager::redirectWithSuccess('branch_management.php', "Branch '{$data['name']}' created successfully!");
        } else {
            $message = ($result['code'] == 23000)
                ? "Branch name or code already exists. Please choose different values."
                : "Error creating branch: " . $result['error'];
            SessionManager::redirectWithError('branch_management.php', $message);
        }
    }

    if (isset($_POST['update_branch'])) {
        $branchId = $_POST['branch_id'];

        // Check permissions
        if (!$permissions->canEditBranch($branchId)) {
            SessionManager::redirectWithError('branch_management.php', 'You can only edit your own branch.');
        }

        // Validate input
        $validator = Validator::validateBranch($_POST);

        if ($validator->fails()) {
            SessionManager::redirectWithError('branch_management.php', $validator->getFirstError());
        }

        // Update branch
        $data = $validator->getSanitizedData();
        unset($data['code']); // Don't allow code changes on update

        $result = $db->update('branches', $data, 'id = ?', [$branchId]);

        if ($result['success']) {
            SessionManager::redirectWithSuccess('branch_management.php', 'Branch updated successfully!');
        } else {
            SessionManager::redirectWithError('branch_management.php', 'Error updating branch: ' . $result['error']);
        }
    }
}// Get accessible branches and statistics
$branches = $permissions->getAccessibleBranches(true); // Include inactive for management

// Get branch statistics
$branch_stats = [];
foreach ($branches as $branch) {
    $stats = getCrossBranchStats($conn, $branch['id']);
    $branch_stats[$branch['id']] = $stats;
}

// Get messages
$messages = SessionManager::getAllMessages();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Branch Management - Rota System</title>
    <link rel="stylesheet" href="../css/admin_dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        .branch-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .branch-header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 15px;
        }

        .branch-status {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }

        .status-active {
            background: #d4edda;
            color: #155724;
        }

        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }

        .branch-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 10px;
            margin-top: 15px;
        }

        .stat-item {
            text-align: center;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 4px;
        }

        .stat-value {
            font-size: 24px;
            font-weight: bold;
            color: #007bff;
        }

        .stat-label {
            font-size: 12px;
            color: #666;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }

        .btn-primary {
            background: #007bff;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }

        .btn-primary:hover {
            background: #0056b3;
        }

        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
    </style>
</head>

<body>
    <div class="container">
        <h1><i class="fas fa-building"></i> Branch Management</h1>

        <?php if ($permissions->isSuperAdmin()): ?>
            <div class="alert" style="background: #fff3cd; color: #856404; border: 1px solid #ffeaa7;">
                <i class="fas fa-crown"></i> <strong>Super Administrator Mode:</strong> You can create new branches and edit
                all branches.
            </div>
        <?php elseif ($permissions->getUserBranch()): ?>
            <div class="alert" style="background: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb;">
                <i class="fas fa-info-circle"></i> <strong>Branch Manager Mode:</strong> You can view all branches but only
                edit your own branch.
            </div>
        <?php else: ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i> <strong>No Branch Assignment:</strong> You must be assigned to a
                branch or be a super administrator to fully use this system.
            </div>
        <?php endif; ?>

        <?php echo SessionManager::displayMessages(); ?>

        <?php if ($permissions->canCreateBranch()): ?>
            <!-- Create New Branch (Super Admin Only) -->
            <div class="branch-card">
                <h2><i class="fas fa-plus"></i> Create New Branch</h2>
                <form method="POST">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="name">Branch Name *</label>
                            <input type="text" id="name" name="name" required>
                        </div>
                        <div class="form-group">
                            <label for="code">Branch Code *</label>
                            <input type="text" id="code" name="code" required maxlength="10"
                                placeholder="e.g., MAIN, NORTH">
                        </div>
                        <div class="form-group">
                            <label for="phone">Phone</label>
                            <input type="tel" id="phone" name="phone">
                        </div>
                        <div class="form-group">
                            <label for="email">Email</label>
                            <input type="email" id="email" name="email">
                        </div>
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label for="address">Address</label>
                            <textarea id="address" name="address" rows="3"></textarea>
                        </div>
                    </div>
                    <button type="submit" name="create_branch" class="btn-primary">
                        <i class="fas fa-plus"></i> Create Branch
                    </button>
                </form>
            </div>
        <?php endif; ?>

        <!-- All Branches -->
        <h2><i class="fas fa-list"></i>
            <?php echo $permissions->isSuperAdmin() ? 'All Branches' : 'Branch Information'; ?>
            <?php if (!$permissions->isSuperAdmin() && $permissions->getUserBranch()): ?>
                <small style="font-weight: normal; color: #666;">(You can only edit your own branch)</small>
            <?php endif; ?>
        </h2>

        <?php foreach ($branches as $branch): ?>
            <div class="branch-card">
                <div class="branch-header">
                    <h3>
                        <i class="fas fa-building"></i>
                        <?php echo htmlspecialchars($branch['name']); ?>
                        <small>(<?php echo htmlspecialchars($branch['code']); ?>)</small>

                        <?php if ($branch['id'] == $permissions->getUserBranch()): ?>
                            <span
                                style="background: #28a745; color: white; padding: 2px 8px; border-radius: 12px; font-size: 10px; margin-left: 10px;">
                                YOUR BRANCH
                            </span>
                        <?php endif; ?>
                    </h3>
                    <span class="branch-status status-<?php echo $branch['status']; ?>">
                        <?php echo ucfirst($branch['status']); ?>
                    </span>
                </div>
                <div class="form-grid">
                    <div>
                        <strong>Address:</strong><br>
                        <?php echo htmlspecialchars($branch['address'] ?: 'Not specified'); ?>
                    </div>
                    <div>
                        <strong>Contact:</strong><br>
                        <?php if ($branch['phone']): ?>
                            <i class="fas fa-phone"></i> <?php echo htmlspecialchars($branch['phone']); ?><br>
                        <?php endif; ?>
                        <?php if ($branch['email']): ?>
                            <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($branch['email']); ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Branch Statistics -->
                <div class="branch-stats">
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $branch_stats[$branch['id']]['total_requests'] ?? 0; ?></div>
                        <div class="stat-label">Total Requests</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $branch_stats[$branch['id']]['fulfilled_requests'] ?? 0; ?></div>
                        <div class="stat-label">Fulfilled</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $branch_stats[$branch['id']]['pending_requests'] ?? 0; ?></div>
                        <div class="stat-label">Pending</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $branch_stats[$branch['id']]['declined_requests'] ?? 0; ?></div>
                        <div class="stat-label">Declined</div>
                    </div>
                </div>

                <!-- Edit Form (only for own branch or super admin) -->
                <?php if ($permissions->canEditBranch($branch['id'])): ?>
                    <details style="margin-top: 15px;">
                        <summary style="cursor: pointer; font-weight: bold;">
                            <i class="fas fa-edit"></i> Edit Branch
                        </summary>
                        <form method="POST" style="margin-top: 15px;">
                            <input type="hidden" name="branch_id" value="<?php echo $branch['id']; ?>">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label>Branch Name</label>
                                    <input type="text" name="name" value="<?php echo htmlspecialchars($branch['name']); ?>"
                                        required>
                                </div>
                                <div class="form-group">
                                    <label>Status</label>
                                    <select name="status">
                                        <option value="active" <?php echo $branch['status'] === 'active' ? 'selected' : ''; ?>>
                                            Active</option>
                                        <option value="inactive" <?php echo $branch['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Phone</label>
                                    <input type="tel" name="phone" value="<?php echo htmlspecialchars($branch['phone']); ?>">
                                </div>
                                <div class="form-group">
                                    <label>Email</label>
                                    <input type="email" name="email" value="<?php echo htmlspecialchars($branch['email']); ?>">
                                </div>
                                <div class="form-group" style="grid-column: 1 / -1;">
                                    <label>Address</label>
                                    <textarea name="address"
                                        rows="3"><?php echo htmlspecialchars($branch['address']); ?></textarea>
                                </div>
                            </div>
                            <button type="submit" name="update_branch" class="btn-primary">
                                <i class="fas fa-save"></i> Update Branch
                            </button>
                        </form>
                    </details>
                <?php else: ?>
                    <div
                        style="margin-top: 15px; padding: 10px; background: #f8f9fa; border-radius: 4px; color: #666; text-align: center;">
                        <i class="fas fa-lock"></i> You can only edit your own branch
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <div style="margin-top: 30px; text-align: center;">
            <a href="admin_dashboard.php" class="btn-primary">
                <i class="fas fa-arrow-left"></i> Back to Admin Dashboard
            </a>
        </div>
    </div>
</body>

</html>