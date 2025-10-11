<?php
require '../includes/auth.php';
requireAdmin(); // Only admins can access
require_once '../includes/db.php';
require_once '../functions/branch_functions.php';

$shift_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

// Determine return URL - check if provided, otherwise try to detect from referrer
$return_url = isset($_GET['return']) ? $_GET['return'] : null;

if (!$return_url) {
    // Try to determine from HTTP_REFERER
    $referrer = $_SERVER['HTTP_REFERER'] ?? '';
    if (strpos($referrer, 'admin_dashboard.php') !== false) {
        $return_url = 'admin_dashboard.php';
    } else {
        $return_url = 'manage_shifts.php'; // Default fallback
    }
}

// Validate return URL to prevent open redirect
if (strpos($return_url, '../') === 0 || strpos($return_url, 'http') === 0) {
    $return_url = 'manage_shifts.php'; // Default if invalid
}

if (!$shift_id) {
    $_SESSION['error_message'] = "Invalid shift ID.";
    header("Location: $return_url");
    exit;
}

try {
    // Get shift details
    $stmt = $conn->prepare(
        "SELECT s.*, u.username 
         FROM shifts s
         JOIN users u ON s.user_id = u.id
         WHERE s.id = ?"
    );
    $stmt->execute([$shift_id]);
    $shift = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$shift) {
        throw new Exception("Shift not found.");
    }

    // Determine admin's branch to limit which users can be assigned
    $currentAdminId = $_SESSION['user_id'] ?? null;
    $adminBranchId = null;
    if ($currentAdminId) {
        $bstmt = $conn->prepare("SELECT branch_id FROM users WHERE id = ? LIMIT 1");
        $bstmt->execute([$currentAdminId]);
        $adminBranchId = $bstmt->fetchColumn();
    }

    // Get all users for dropdown limited to admin's branch. Ensure the current shift user is included.
    if ($adminBranchId) {
        $users_stmt = $conn->prepare("SELECT id, username FROM users WHERE branch_id = ? OR id = ? ORDER BY username");
        $users_stmt->execute([(int) $adminBranchId, (int) $shift['user_id']]);
    } else {
        $users_stmt = $conn->prepare("SELECT id, username FROM users ORDER BY username");
        $users_stmt->execute();
    }
    $users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get all roles for dropdown
    $roles_stmt = $conn->prepare("SELECT id, name FROM roles ORDER BY name");
    $roles_stmt->execute();
    $roles = $roles_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Load branches for branch picker
    $all_branches = getAllBranches($conn);

} catch (Exception $e) {
    $_SESSION['error_message'] = "Error loading shift: " . $e->getMessage();
    header("Location: $return_url");
    exit;
}
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
    <title>Edit Shift - Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        @font-face {
            font-family: 'newFont';
            src: url('../fonts/CooperHewitt-Book.otf');
            font-weight: normal;
            font-style: normal;
        }

        body {
            font-family: 'newFont', Arial, sans-serif;
            background: url('../images/backg3.jpg') no-repeat center center fixed;
            background-size: cover;
            margin: 0;
            padding: 20px;
            color: #333;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
            background-color: rgba(255, 255, 255, 0.95);
            border-radius: 12px;
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.1);
            padding: 30px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #f4f4f4;
            padding-bottom: 15px;
        }

        h1 {
            color: #fd2b2b;
            margin: 0;
            font-size: 1.8rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .action-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background-color: #555;
            color: white;
            text-decoration: none;
            padding: 8px 15px;
            border-radius: 5px;
            transition: background-color 0.3s, transform 0.2s;
        }

        .action-button:hover {
            background-color: #444;
            transform: translateY(-2px);
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: #444;
        }

        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
            font-family: 'newFont', Arial, sans-serif;
        }

        .form-control:focus {
            outline: none;
            border-color: #fd2b2b;
            box-shadow: 0 0 0 2px rgba(253, 43, 43, 0.1);
        }

        .form-buttons {
            grid-column: span 2;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 10px;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            font-weight: 600;
            cursor: pointer;
            font-family: 'newFont', Arial, sans-serif;
            font-size: 16px;
        }

        .btn-primary {
            background-color: #fd2b2b;
            color: white;
        }

        .btn-secondary {
            background-color: #f4f4f4;
            color: #333;
        }

        .btn:hover {
            transform: translateY(-2px);
        }

        .btn-primary:hover {
            background-color: #e61919;
        }

        .error-message {
            background-color: #f8d7da;
            color: #721c24;
            padding: 10px 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border-left: 4px solid #f5c6cb;
        }

        .success-message {
            background-color: #d4edda;
            color: #155724;
            padding: 10px 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border-left: 4px solid #c3e6cb;
        }

        .user-info {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .user-info p {
            margin: 0;
            font-size: 15px;
        }

        .user-info strong {
            color: #fd2b2b;
        }

        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }

            .form-buttons {
                grid-column: 1;
            }

            .container {
                padding: 20px;
            }

            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-edit"></i> Edit Shift</h1>
            <a href="<?php echo htmlspecialchars($return_url); ?>" class="action-button">
                <i class="fas fa-arrow-left"></i> Back
            </a>
        </div>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="error-message">
                <?php
                echo $_SESSION['error_message'];
                unset($_SESSION['error_message']);
                ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="success-message">
                <?php
                echo $_SESSION['success_message'];
                unset($_SESSION['success_message']);
                ?>
            </div>
        <?php endif; ?>

        <div class="user-info">
            <p>Editing shift for <strong><?php echo htmlspecialchars($shift['username']); ?></strong>
                on <strong><?php echo date('F j, Y', strtotime($shift['shift_date'])); ?></strong></p>
        </div>

        <form action="../functions/edit_shift.php" method="POST">
            <input type="hidden" name="shift_id" value="<?php echo $shift['id']; ?>">
            <input type="hidden" name="admin_mode" value="1">
            <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($return_url); ?>">

            <div class="form-grid">
                <div class="form-group">
                    <label for="user_id">Assign To User:</label>
                    <select name="user_id" id="user_id" class="form-control" required>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo $user['id']; ?>" <?php echo ($user['id'] == $shift['user_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($user['username']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="role_id">Role:</label>
                    <select name="role_id" id="role_id" class="form-control" required>
                        <?php foreach ($roles as $role): ?>
                            <option value="<?php echo $role['id']; ?>" <?php echo ($role['id'] == $shift['role_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($role['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="shift_date">Date:</label>
                    <input type="date" name="shift_date" id="shift_date" class="form-control"
                        value="<?php echo $shift['shift_date']; ?>" required>
                </div>

                <div class="form-group">
                    <label for="location">Location:</label>
                    <input type="text" name="location" id="location" class="form-control"
                        value="<?php echo htmlspecialchars($shift['location']); ?>" required>
                </div>

                <div class="form-group">
                    <label for="branch_id">Branch (optional):</label>
                    <select name="branch_id" id="branch_id" class="form-control">
                        <option value="">-- Default (no branch) --</option>
                        <?php foreach ($all_branches as $b): ?>
                            <option value="<?php echo $b['id']; ?>" <?php echo (isset($shift['branch_id']) && $shift['branch_id'] == $b['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($b['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="start_time">Start Time:</label>
                    <input type="time" name="start_time" id="start_time" class="form-control"
                        value="<?php echo $shift['start_time']; ?>" required>
                </div>

                <div class="form-group">
                    <label for="end_time">End Time:</label>
                    <input type="time" name="end_time" id="end_time" class="form-control"
                        value="<?php echo $shift['end_time']; ?>" required>
                </div>

                <div class="form-buttons">
                    <a href="<?php echo htmlspecialchars($return_url); ?>" class="btn btn-secondary"
                        style="text-decoration: none; display: inline-block; text-align: center;">Cancel</a>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </div>
        </form>
    </div>

    <script src="/rota-app-main/js/pwa-debug.js"></script>
    <script src="/rota-app-main/js/links.js"></script>
    <script>
        (function () {
            const branchSelect = document.getElementById('branch_id');
            const locationInput = document.getElementById('location');
            if (!branchSelect) return;

            branchSelect.addEventListener('change', function () {
                const id = this.value;
                if (!id) return;
                const opt = this.options[this.selectedIndex];
                if (opt && opt.text) {
                    // Only overwrite if location looks like a previous branch name (basic heuristic)
                    if (!locationInput.value || locationInput.value === '' || locationInput.value === '<?php echo addslashes($shift['location']); ?>') {
                        locationInput.value = opt.text;
                    }
                }
            });
        })();
    </script>
</body>

</html>