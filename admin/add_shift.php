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
            <h1><i class="fas fa-plus-circle"></i> Add New Shift</h1>
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

        <form action="../functions/add_shift.php" method="POST">
            <input type="hidden" name="admin_mode" value="1">
            <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($return_url); ?>">
            
            <div class="form-grid">
                <div class="form-group">
                    <label for="user_id">Assign To User:</label>
                    <select name="user_id" id="user_id" class="form-control" required>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo $user['id']; ?>" 
                                <?php echo (isset($user_id) && $user_id == $user['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($user['username']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="role_id">Role:</label>
                    <select name="role_id" id="role_id" class="form-control" required>
                        <?php foreach ($roles as $role): ?>
                            <option value="<?php echo $role['id']; ?>">
                                <?php echo htmlspecialchars($role['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="shift_date">Date:</label>
                    <input type="date" name="shift_date" id="shift_date" class="form-control" 
                           value="<?php echo $default_date; ?>" required>
                </div>

                <div class="form-group">
                    <label for="location">Location:</label>
                    <input type="text" name="location" id="location" class="form-control" 
                           value="<?php echo $default_location; ?>" required>
                </div>

                <div class="form-group">
                    <label for="start_time">Start Time:</label>
                    <input type="time" name="start_time" id="start_time" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="end_time">End Time:</label>
                    <input type="time" name="end_time" id="end_time" class="form-control" required>
                </div>

                <div class="form-buttons">
                    <button type="button" class="btn btn-secondary" 
                            onclick="location.href='<?php echo htmlspecialchars($return_url); ?>'">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Shift</button>
                </div>
            </div>
        </form>
    </div>

    <script src="/rota-app-main/js/pwa-debug.js"></script>
    <script src="/rota-app-main/js/links.js"></script>
</body>
</html>
