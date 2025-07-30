<?php
require '../includes/auth.php';
requireAdmin(); // Only admins can access
require_once '../includes/db.php';

// Get user_id from query string if provided
$user_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : null;
$return_url = isset($_GET['return']) ? $_GET['return'] : 'manage_shifts.php';

// Validate return URL to prevent open redirect
if (strpos($return_url, '../') === 0 || strpos($return_url, 'http') === 0) {
    $return_url = 'manage_shifts.php'; // Default if invalid
}

// Get all users for dropdown
$users_stmt = $conn->prepare("SELECT id, username FROM users ORDER BY username");
$users_stmt->execute();
$users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all roles for dropdown
$roles_stmt = $conn->prepare("SELECT id, name FROM roles ORDER BY name");
$roles_stmt->execute();
$roles = $roles_stmt->fetchAll(PDO::FETCH_ASSOC);

// Default values
$default_date = date('Y-m-d');
$default_location = 'Main Office';
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
    <title>Add Shift - Admin</title>
    <link rel="stylesheet" href="../css/admin_dashboard.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">

</head>

<body>
    <div class="admin-container">
        <div class="admin-header">
            <div class="admin-title">
                <h1><i class="fas fa-plus-circle"></i> Add New Shift</h1>
            </div>
            <div class="admin-actions">
                <a href="<?php echo htmlspecialchars($return_url); ?>" class="admin-btn secondary">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
            </div>
        </div>

        <div class="admin-panel">
            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?php
                    echo $_SESSION['error_message'];
                    unset($_SESSION['error_message']);
                    ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php
                    echo $_SESSION['success_message'];
                    unset($_SESSION['success_message']);
                    ?>
                </div>
            <?php endif; ?>

            <form action="../functions/add_shift.php" method="POST" class="admin-form">
                <input type="hidden" name="admin_mode" value="1">
                <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($return_url); ?>">

                <div class="form-grid">
                    <div class="form-group">
                        <label for="user_id">
                            <i class="fas fa-user"></i>
                            Assign To User:
                        </label>
                        <select name="user_id" id="user_id" class="form-control" required>
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['id']; ?>" <?php echo (isset($user_id) && $user_id == $user['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($user['username']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="role_id">
                            <i class="fas fa-user-tag"></i>
                            Role:
                        </label>
                        <select name="role_id" id="role_id" class="form-control" required>
                            <?php foreach ($roles as $role): ?>
                                <option value="<?php echo $role['id']; ?>">
                                    <?php echo htmlspecialchars($role['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="shift_date">
                            <i class="fas fa-calendar-alt"></i>
                            Date:
                        </label>
                        <input type="date" name="shift_date" id="shift_date" class="form-control"
                            value="<?php echo $default_date; ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="location">
                            <i class="fas fa-map-marker-alt"></i>
                            Location:
                        </label>
                        <input type="text" name="location" id="location" class="form-control"
                            value="<?php echo $default_location; ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="start_time">
                            <i class="fas fa-clock"></i>
                            Start Time:
                        </label>
                        <input type="time" name="start_time" id="start_time" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label for="end_time">
                            <i class="fas fa-clock"></i>
                            End Time:
                        </label>
                        <input type="time" name="end_time" id="end_time" class="form-control" required>
                    </div>
                </div>

                <div class="form-buttons">
                    <button type="button" class="admin-btn secondary"
                        onclick="location.href='<?php echo htmlspecialchars($return_url); ?>'">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="admin-btn primary">
                        <i class="fas fa-plus"></i> Add Shift
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="/rota-app-main/js/pwa-debug.js"></script>
    <script src="/rota-app-main/js/links.js"></script>
</body>

</html>