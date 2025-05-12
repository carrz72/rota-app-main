<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../functions/login.php");
    exit;
}

require_once '../includes/db.php';
require '../includes/auth.php';
require_once '../includes/notifications.php';

// Fetch all roles globally for every user
$stmt = $conn->query("SELECT * FROM roles ORDER BY name ASC");
$roles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get user-specific data for header
$user_id = $_SESSION['user_id'];
$notifications = [];
$notificationCount = 0;
if ($user_id) {
    $notifications = getNotifications($user_id);
    $notificationCount = count($notifications);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Open Rota">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no">
    <link rel="icon" type="image/png" href="/rota-app-main/images/icon.png">
    <link rel="manifest" href="/rota-app-main/manifest.json">
    <link rel="apple-touch-icon" href="/rota-app-main/images/icon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
    <title>Manage Roles - Open Rota</title>
    <link rel="stylesheet" href="../css/role.css">
    <style>
        .role-card {
            background-color: white;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .role-details {
            flex-grow: 1;
        }

        .role-name {
            font-weight: bold;
            color: #fd2b2b;
            font-size: 1.1em;
            margin-bottom: 5px;
        }

        .role-pay {
            color: #333;
        }

        .role-night {
            color: #555;
            font-style: italic;
            margin-top: 5px;
            font-size: 0.9em;
        }

        .role-actions {
            display: flex;
            gap: 10px;
        }

        .roles-list {
            margin-bottom: 30px;
        }

        .form-card {
            background-color: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .night-pay-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .form-full-width {
            grid-column: span 2;
        }

        .form-footer {
            margin-top: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .toggle-container {
            display: flex;
            align-items: center;
        }

        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
            margin-left: 10px;
        }

        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 24px;
        }

        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }

        input:checked+.toggle-slider {
            background-color: #fd2b2b;
        }

        input:checked+.toggle-slider:before {
            transform: translateX(26px);
        }

        .success-message,
        .error-message {
            padding: 10px 15px;
            margin-bottom: 20px;
            border-radius: 5px;
        }

        .success-message {
            background-color: #ddffdd;
            color: #090;
            border: 1px solid #c2f5c2;
        }

        .error-message {
            background-color: #ffdddd;
            color: #900;
            border: 1px solid #f5c2c2;
        }

        @media (max-width: 768px) {

            .form-grid,
            .night-pay-grid {
                grid-template-columns: 1fr;
            }

            .form-full-width {
                grid-column: span 1;
            }

            .role-card {
                flex-direction: column;
                align-items: flex-start;
            }

            .role-actions {
                margin-top: 10px;
                width: 100%;
                justify-content: flex-end;
            }
        }
    </style>
</head>

<body>
    <?php include '../includes/header.php'; ?>

    <div class="container">
        <h1>Role Management</h1>

        <!-- Display notifications -->
        <?php if (isset($_SESSION['success'])): ?>
            <div class="success-message">
                <?php
                echo $_SESSION['success'];
                unset($_SESSION['success']);
                ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="error-message">
                <?php
                echo $_SESSION['error'];
                unset($_SESSION['error']);
                ?>
            </div>
        <?php endif; ?>

        <!-- List of Existing Roles -->
        <section class="roles-list">
            <h2><i class="fa fa-briefcase"></i> Your Role Library</h2>

            <?php if (count($roles) > 0): ?>
                <?php foreach ($roles as $role): ?>
                    <div class="role-card">
                        <div class="role-details">
                            <div class="role-name"><?php echo htmlspecialchars($role['name']); ?></div>
                            <div class="role-pay">Base Pay: £<?php echo number_format($role['base_pay'], 2); ?> per hour</div>

                            <?php if ($role['has_night_pay']): ?>
                                <div class="role-night">
                                    Night Pay: £<?php echo number_format($role['night_shift_pay'], 2); ?> per hour
                                    (<?php echo date("g:i A", strtotime($role['night_start_time'])); ?> -
                                    <?php echo date("g:i A", strtotime($role['night_end_time'])); ?>)
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="role-actions">
                            <button class="action-btn edit-btn" onclick="editRole(<?php echo $role['id']; ?>)">
                                <i class="fa fa-pencil"></i> Edit
                            </button>
                            <button class="action-btn delete-btn"
                                onclick="confirmDelete(<?php echo $role['id']; ?>, '<?php echo htmlspecialchars($role['name']); ?>')">
                                <i class="fa fa-trash"></i> Delete
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p>No roles defined yet. Create your first role below.</p>
            <?php endif; ?>
        </section>

        <!-- Add New Role Form -->
        <section class="form-card">
            <h2><i class="fa fa-plus-circle"></i> Add New Role</h2>
            <form action="../functions/create_role.php" method="POST" id="roleForm">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="name">Role Name:</label>
                        <input type="text" id="name" name="name" required placeholder="e.g. Manager, Server, Barista">
                    </div>

                    <div class="form-group">
                        <label for="base_pay">Base Pay Rate (£):</label>
                        <input type="number" step="0.01" min="0" id="base_pay" name="base_pay" required
                            placeholder="e.g. 10.50">
                    </div>

                    <div class="form-full-width">
                        <div class="toggle-container">
                            <label for="has_night_pay">Enable Night Shift Pay:</label>
                            <label class="toggle-switch">
                                <input type="checkbox" id="has_night_pay" name="has_night_pay"
                                    onclick="toggleNightPayFields()">
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                    </div>
                </div>

                <div id="night_pay_fields" style="display: none; margin-top: 20px;">
                    <h3>Night Shift Settings</h3>
                    <div class="night-pay-grid">
                        <div class="form-group">
                            <label for="night_shift_pay">Night Shift Pay Rate (£):</label>
                            <input type="number" step="0.01" min="0" id="night_shift_pay" name="night_shift_pay"
                                placeholder="e.g. 12.50">
                        </div>

                        <div class="form-group">
                            <label for="night_start_time">Night Shift Starts At:</label>
                            <input type="time" id="night_start_time" name="night_start_time">
                        </div>

                        <div class="form-group">
                            <label for="night_end_time">Night Shift Ends At:</label>
                            <input type="time" id="night_end_time" name="night_end_time">
                        </div>
                    </div>
                </div>

                <div class="form-footer">
                    <button type="button" class="action-btn" onclick="resetForm()">Reset</button>
                    <button type="submit" class="action-btn">Create Role</button>
                </div>
            </form>
        </section>

        <div style="margin-top: 20px; text-align: center;">
            <a href="dashboard.php">Back to Dashboard</a>
        </div>
    </div>

    <script>
        // Function to toggle night pay fields
        function toggleNightPayFields() {
            const checkbox = document.getElementById("has_night_pay");
            const container = document.getElementById("night_pay_fields");
            container.style.display = checkbox.checked ? "block" : "none";

            // Make fields required or not based on checkbox
            const nightFields = ['night_shift_pay', 'night_start_time', 'night_end_time'];
            nightFields.forEach(id => {
                document.getElementById(id).required = checkbox.checked;
            });
        }

        // Reset form function
        function resetForm() {
            document.getElementById("roleForm").reset();
            toggleNightPayFields();
        }

        // Edit role function (placeholder - implement as needed)
        function editRole(roleId) {
            // Redirect to edit page or show modal
            alert("Edit functionality will be implemented here for role ID: " + roleId);
        }

        // Delete confirmation
        function confirmDelete(roleId, roleName) {
            if (confirm(`Are you sure you want to delete the role "${roleName}"?`)) {
                // Submit to delete endpoint
                window.location.href = `../functions/delete_role.php?id=${roleId}`;
            }
        }

        // Ensure the correct display on page load
        document.addEventListener("DOMContentLoaded", function () {
            toggleNightPayFields();
        });
    </script>

    <script src="/rota-app-main/js/menu.js"></script>
    <script src="/rota-app-main/js/pwa-debug.js"></script>
    <script src="/rota-app-main/js/links.js"></script>
</body>

</html>