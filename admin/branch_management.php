<?php
require '../includes/auth.php';
requireAdmin(); // Only admins can access
require_once '../includes/db.php';

// Check if user is super admin
$currentUserId = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT role, branch_id FROM users WHERE id = ?");
$stmt->execute([$currentUserId]);
$adminUser = $stmt->fetch(PDO::FETCH_ASSOC);
$adminRole = $adminUser['role'] ?? 'user';
$adminBranchId = $adminUser['branch_id'] ?? null;
$isSuperAdmin = ($adminRole === 'super_admin');

// Handle form submissions
$successMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_branch'])) {
        // Only super admins can create branches
        if (!$isSuperAdmin) {
            $errorMessage = "Only super administrators can create new branches.";
        } else {
            $name = trim($_POST['name'] ?? '');
            $code = strtoupper(trim($_POST['code'] ?? ''));
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $address = trim($_POST['address'] ?? '');

            // Validate required fields
            if (empty($name) || empty($code)) {
                $errorMessage = "Branch name and code are required.";
            } else {
                try {
                    // Check if code already exists
                    $checkStmt = $conn->prepare("SELECT id FROM branches WHERE code = ?");
                    $checkStmt->execute([$code]);
                    if ($checkStmt->fetch()) {
                        $errorMessage = "Branch code already exists. Please choose a different code.";
                    } else {
                        // Insert new branch
                        $insertStmt = $conn->prepare("INSERT INTO branches (name, code, phone, email, address, status) VALUES (?, ?, ?, ?, ?, 'active')");
                        if ($insertStmt->execute([$name, $code, $phone, $email, $address])) {
                            $successMessage = "Branch '$name' created successfully!";
                        } else {
                            $errorMessage = "Error creating branch. Please try again.";
                        }
                    }
                } catch (PDOException $e) {
                    $errorMessage = "Database error: " . $e->getMessage();
                }
            }
        }
    }

    if (isset($_POST['update_branch'])) {
        $branchId = (int) $_POST['branch_id'];
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $status = $_POST['status'] ?? 'active';

        // Check permissions
        $canEdit = $isSuperAdmin || ($adminBranchId == $branchId);

        if (!$canEdit) {
            $errorMessage = "You can only edit your own branch.";
        } elseif (empty($name)) {
            $errorMessage = "Branch name is required.";
        } else {
            try {
                $updateStmt = $conn->prepare("UPDATE branches SET name = ?, phone = ?, email = ?, address = ?, status = ? WHERE id = ?");
                if ($updateStmt->execute([$name, $phone, $email, $address, $status, $branchId])) {
                    $successMessage = "Branch updated successfully!";
                } else {
                    $errorMessage = "Error updating branch. Please try again.";
                }
            } catch (PDOException $e) {
                $errorMessage = "Database error: " . $e->getMessage();
            }
        }
    }
}

// Get all branches (super admin sees all, regular admin sees only their own)
if ($isSuperAdmin) {
    $branchesStmt = $conn->prepare("SELECT * FROM branches ORDER BY name");
    $branchesStmt->execute();
} else {
    $branchesStmt = $conn->prepare("SELECT * FROM branches WHERE id = ? ORDER BY name");
    $branchesStmt->execute([$adminBranchId]);
}
$branches = $branchesStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Open Rota">
    <link rel="icon" type="image/png" href="/rota-app-main/images/icon.png">
    <link rel="manifest" href="/rota-app-main/manifest.json">
    <link rel="apple-touch-icon" href="/rota-app-main/images/icon.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no">
    <title>Branch Management - Admin</title>
    <link rel="stylesheet" href="../css/admin_dashboard.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
</head>

<body>
    <div class="admin-container">
        <div class="admin-header">
            <div class="admin-title">
                <h1><i class="fas fa-building"></i> Branch Management</h1>
                <div class="admin-subtitle">
                    <?php if ($isSuperAdmin): ?>
                        <span class="super-admin-badge"><i class="fas fa-crown"></i> Super Admin - Full Access</span>
                    <?php else: ?>
                        <span class="branch-info">Branch Manager - Limited Access</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="admin-actions">
                <a href="admin_dashboard.php" class="admin-btn secondary">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>

        <?php if ($successMessage): ?>
            <div class="success-message">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($successMessage); ?>
            </div>
        <?php endif; ?>

        <?php if ($errorMessage): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($errorMessage); ?>
            </div>
        <?php endif; ?>

        <?php if ($isSuperAdmin): ?>
            <div class="admin-panel">
                <div class="admin-panel-header">
                    <h2><i class="fas fa-plus"></i> Create New Branch</h2>
                </div>
                <div class="admin-panel-body">
                    <form method="POST" class="admin-form">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="name">
                                    <i class="fas fa-building"></i> Branch Name *
                                </label>
                                <input type="text" id="name" name="name" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label for="code">
                                    <i class="fas fa-tag"></i> Branch Code *
                                </label>
                                <input type="text" id="code" name="code" class="form-control" required maxlength="10"
                                    placeholder="e.g., MAIN, NORTH">
                            </div>
                            <div class="form-group">
                                <label for="phone">
                                    <i class="fas fa-phone"></i> Phone
                                </label>
                                <input type="tel" id="phone" name="phone" class="form-control">
                            </div>
                            <div class="form-group">
                                <label for="email">
                                    <i class="fas fa-envelope"></i> Email
                                </label>
                                <input type="email" id="email" name="email" class="form-control">
                            </div>
                            <div class="form-group" style="grid-column: 1 / -1;">
                                <label for="address">
                                    <i class="fas fa-map-marker-alt"></i> Address
                                </label>
                                <textarea id="address" name="address" class="form-control" rows="3"></textarea>
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" name="create_branch" class="admin-btn primary">
                                <i class="fas fa-plus"></i> Create Branch
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <div class="admin-panel">
            <div class="admin-panel-header">
                <h2>
                    <i class="fas fa-list"></i>
                    <?php echo $isSuperAdmin ? 'All Branches' : 'Your Branch'; ?>
                </h2>
            </div>
            <div class="admin-panel-body">
                <?php if (empty($branches)): ?>
                    <div class="empty-state">
                        <i class="fas fa-building"></i>
                        <h3>No Branches Found</h3>
                        <p>
                            <?php if ($isSuperAdmin): ?>
                                No branches exist yet. Create your first branch above.
                            <?php else: ?>
                                You are not assigned to any branch. Contact a super administrator.
                            <?php endif; ?>
                        </p>
                    </div>
                <?php else: ?>
                    <?php foreach ($branches as $branch): ?>
                        <div class="branch-card"
                            style="margin-bottom: 20px; border: 1px solid #e0e0e0; border-radius: 8px; padding: 20px;">
                            <div class="branch-header"
                                style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                                <h3 style="margin: 0;">
                                    <i class="fas fa-building"></i>
                                    <?php echo htmlspecialchars($branch['name']); ?>
                                    <small style="color: #666;">(<?php echo htmlspecialchars($branch['code']); ?>)</small>
                                    <?php if ($branch['id'] == $adminBranchId): ?>
                                        <span class="badge success"
                                            style="background: #d4edda; color: #155724; padding: 2px 8px; border-radius: 4px; font-size: 12px;">YOUR
                                            BRANCH</span>
                                    <?php endif; ?>
                                </h3>
                                <span class="badge <?php echo $branch['status'] === 'active' ? 'success' : 'danger'; ?>"
                                    style="padding: 4px 8px; border-radius: 4px; font-size: 12px; <?php echo $branch['status'] === 'active' ? 'background: #d4edda; color: #155724;' : 'background: #f8d7da; color: #721c24;'; ?>">
                                    <?php echo ucfirst($branch['status']); ?>
                                </span>
                            </div>

                            <div class="branch-info"
                                style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                                <div>
                                    <strong><i class="fas fa-map-marker-alt"></i> Address:</strong><br>
                                    <?php echo $branch['address'] ? htmlspecialchars($branch['address']) : '<em>Not specified</em>'; ?>
                                </div>
                                <div>
                                    <strong><i class="fas fa-phone"></i> Contact:</strong><br>
                                    <?php if ($branch['phone']): ?>
                                        <span><i class="fas fa-phone"></i>
                                            <?php echo htmlspecialchars($branch['phone']); ?></span><br>
                                    <?php endif; ?>
                                    <?php if ($branch['email']): ?>
                                        <span><i class="fas fa-envelope"></i>
                                            <?php echo htmlspecialchars($branch['email']); ?></span>
                                    <?php endif; ?>
                                    <?php if (!$branch['phone'] && !$branch['email']): ?>
                                        <em>Not specified</em>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if ($isSuperAdmin || ($adminBranchId == $branch['id'])): ?>
                                <details style="margin-top: 15px;">
                                    <summary class="admin-btn secondary"
                                        style="cursor: pointer; display: inline-flex; align-items: center; gap: 8px; margin-bottom: 15px;">
                                        <i class="fas fa-edit"></i> Edit Branch
                                    </summary>
                                    <div style="padding: 20px; background: #f8f9fa; border-radius: 8px;">
                                        <form method="POST" class="admin-form">
                                            <input type="hidden" name="branch_id" value="<?php echo $branch['id']; ?>">
                                            <div class="form-grid">
                                                <div class="form-group">
                                                    <label>
                                                        <i class="fas fa-building"></i> Branch Name *
                                                    </label>
                                                    <input type="text" name="name" class="form-control"
                                                        value="<?php echo htmlspecialchars($branch['name']); ?>" required>
                                                </div>
                                                <?php if ($isSuperAdmin): ?>
                                                    <div class="form-group">
                                                        <label>
                                                            <i class="fas fa-toggle-on"></i> Status
                                                        </label>
                                                        <select name="status" class="form-control">
                                                            <option value="active" <?php echo $branch['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                                            <option value="inactive" <?php echo $branch['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                                        </select>
                                                    </div>
                                                <?php else: ?>
                                                    <input type="hidden" name="status" value="<?php echo $branch['status']; ?>">
                                                <?php endif; ?>
                                                <div class="form-group">
                                                    <label>
                                                        <i class="fas fa-phone"></i> Phone
                                                    </label>
                                                    <input type="tel" name="phone" class="form-control"
                                                        value="<?php echo htmlspecialchars($branch['phone']); ?>">
                                                </div>
                                                <div class="form-group">
                                                    <label>
                                                        <i class="fas fa-envelope"></i> Email
                                                    </label>
                                                    <input type="email" name="email" class="form-control"
                                                        value="<?php echo htmlspecialchars($branch['email']); ?>">
                                                </div>
                                                <div class="form-group" style="grid-column: 1 / -1;">
                                                    <label>
                                                        <i class="fas fa-map-marker-alt"></i> Address
                                                    </label>
                                                    <textarea name="address" class="form-control"
                                                        rows="3"><?php echo htmlspecialchars($branch['address']); ?></textarea>
                                                </div>
                                            </div>
                                            <div class="form-actions">
                                                <button type="submit" name="update_branch" class="admin-btn primary">
                                                    <i class="fas fa-save"></i> Update Branch
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </details>
                            <?php else: ?>
                                <div class="info-message"
                                    style="margin-top: 15px; padding: 10px; background: #d1ecf1; color: #0c5460; border-radius: 4px;">
                                    <i class="fas fa-lock"></i> You can only edit your own branch
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <style>
        /* Additional form styling to ensure proper display */
        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: #333;
        }

        .form-control {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
            box-sizing: border-box;
        }

        .form-control:focus {
            outline: none;
            border-color: #fd2b2b;
            box-shadow: 0 0 0 2px rgba(253, 43, 43, 0.1);
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .form-actions {
            margin-top: 20px;
            text-align: right;
        }

        .branch-card {
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #666;
        }

        .empty-state i {
            font-size: 3rem;
            color: #ddd;
            margin-bottom: 20px;
        }

        .badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
        }

        .badge.success {
            background: #d4edda;
            color: #155724;
        }

        .badge.danger {
            background: #f8d7da;
            color: #721c24;
        }

        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }

            .branch-info {
                grid-template-columns: 1fr !important;
            }
        }
    </style>

    <script src="/rota-app-main/js/pwa-debug.js"></script>
    <script src="/rota-app-main/js/links.js"></script>
</body>

</html>