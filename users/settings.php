<?php
require_once '../includes/auth.php';
requireLogin();
require_once '../includes/db.php';
include_once '../includes/header.php';

$user_id = $_SESSION['user_id'];

// Fetch user data
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Define available themes
$availableThemes = [
    'default' => 'Default (Red)',
    'dark' => 'Dark Mode',
    'blue' => 'Blue Theme',
    'green' => 'Green Theme',
    'purple' => 'Purple Theme'
];

// Get user's selected theme
$userTheme = $user['theme'] ?? 'default';

// Handle form submissions
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Profile update form
    if (isset($_POST['update_profile'])) {
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone'] ?? '');

        // Validate input
        if (empty($username) || empty($email)) {
            $error = "Username and email are required.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
        } else {
            // Check if email is already in use by another user
            $checkStmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $checkStmt->execute([$email, $user_id]);
            if ($checkStmt->rowCount() > 0) {
                $error = "This email is already in use by another account.";
            } else {
                // Update user profile
                $updateStmt = $conn->prepare("UPDATE users SET username = ?, email = ?, phone = ? WHERE id = ?");
                if ($updateStmt->execute([$username, $email, $phone, $user_id])) {
                    $_SESSION['username'] = $username; // Update session
                    $message = "Profile updated successfully.";

                    // Refresh user data
                    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
                    $stmt->execute([$user_id]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                } else {
                    $error = "Failed to update profile.";
                }
            }
        }
    }

    // Password update form
    if (isset($_POST['update_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];

        // Validate input
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $error = "All password fields are required.";
        } elseif ($new_password !== $confirm_password) {
            $error = "New passwords do not match.";
        } elseif (strlen($new_password) < 8) {
            $error = "Password must be at least 8 characters long.";
        } elseif (!password_verify($current_password, $user['password'])) {
            $error = "Current password is incorrect.";
        } else {
            // Update password
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $updateStmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            if ($updateStmt->execute([$hashed_password, $user_id])) {
                $message = "Password updated successfully.";
            } else {
                $error = "Failed to update password.";
            }
        }
    }

    // Preferences update
    if (isset($_POST['update_preferences'])) {
        $theme = $_POST['theme'];
        $notifications_enabled = isset($_POST['notifications_enabled']) ? 1 : 0;
        $email_notifications = isset($_POST['email_notifications']) ? 1 : 0;

        // Update preferences
        $updateStmt = $conn->prepare("UPDATE users SET theme = ?, notifications_enabled = ?, email_notifications = ? WHERE id = ?");
        if ($updateStmt->execute([$theme, $notifications_enabled, $email_notifications, $user_id])) {
            $userTheme = $theme; // Update current theme
            $message = "Preferences updated successfully.";

            // Refresh user data
            $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $error = "Failed to update preferences.";
        }
    }

    // Profile picture upload
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $filename = $_FILES['profile_picture']['name'];
        $filetype = $_FILES['profile_picture']['type'];
        $filesize = $_FILES['profile_picture']['size'];

        $ext = pathinfo($filename, PATHINFO_EXTENSION);

        if (!in_array(strtolower($ext), $allowed)) {
            $error = "Error: Please upload an image file (JPG, JPEG, PNG, GIF).";
        } elseif ($filesize > 5000000) { // 5MB max
            $error = "Error: File size exceeds the limit of 5MB.";
        } else {
            $new_filename = 'user_' . $user_id . '_' . time() . '.' . $ext;
            $upload_path = '../uploads/profile_pictures/' . $new_filename;

            // Create directory if it doesn't exist
            if (!is_dir('../uploads/profile_pictures/')) {
                mkdir('../uploads/profile_pictures/', 0777, true);
            }

            if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $upload_path)) {
                // Update database with the new profile picture
                $updateStmt = $conn->prepare("UPDATE users SET profile_picture = ? WHERE id = ?");
                if ($updateStmt->execute([$new_filename, $user_id])) {
                    $message = "Profile picture updated successfully.";

                    // Refresh user data
                    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
                    $stmt->execute([$user_id]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                } else {
                    $error = "Failed to update profile picture.";
                }
            } else {
                $error = "Failed to upload image.";
            }
        }
    }
}

// Get login history
$historyStmt = $conn->prepare("
    SELECT ip_address, login_time, user_agent 
    FROM login_history 
    WHERE user_id = ? 
    ORDER BY login_time DESC
    LIMIT 5
");
$historyStmt->execute([$user_id]);
$loginHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Open Rota">
    <link rel="icon" type="image/png" href="/rota-app-main/images/icon.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - Open Rota</title>
    <link rel="stylesheet" href="../css/settings.css">
    <style>
        /* Enhanced Settings Page Styling */
        .settings-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .page-title {
            margin-bottom: 30px;
            text-align: center;
            color: #333;
        }

        .settings-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 30px;
        }

        @media (min-width: 992px) {
            .settings-grid {
                grid-template-columns: 250px 1fr;
            }
        }

        .settings-sidebar {
            background-color: #f8f8f8;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .settings-sidebar ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .settings-sidebar li {
            margin-bottom: 10px;
        }

        .settings-sidebar a {
            display: block;
            padding: 10px 15px;
            border-radius: 5px;
            color: #333;
            text-decoration: none;
            transition: all 0.2s;
        }

        .settings-sidebar a:hover,
        .settings-sidebar a.active {
            background-color: #fd2b2b;
            color: white;
        }

        .settings-sidebar a i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }

        .settings-content {
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .settings-section {
            display: none;
            padding: 25px;
        }

        .settings-section.active {
            display: block;
        }

        .settings-header {
            margin-top: 0;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
        }

        .settings-header i {
            color: #fd2b2b;
            margin-right: 10px;
            font-size: 1.5rem;
        }

        .profile-picture-container {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
        }

        .profile-picture {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            overflow: hidden;
            background-color: #f0f0f0;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 20px;
        }

        .profile-picture img {
            width: 100%;
            height: auto;
            object-fit: cover;
        }

        .profile-picture-default {
            font-size: 60px;
            color: #ccc;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #555;
        }

        .form-control {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
            transition: border-color 0.2s;
        }

        .form-control:focus {
            border-color: #fd2b2b;
            outline: none;
            box-shadow: 0 0 0 2px rgba(253, 43, 43, 0.1);
        }

        .btn {
            background-color: #fd2b2b;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn:hover {
            background-color: #e61919;
            transform: translateY(-2px);
        }

        .btn-secondary {
            background-color: #6c757d;
        }

        .btn-secondary:hover {
            background-color: #5a6268;
        }

        .theme-selector {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-top: 15px;
        }

        .theme-option {
            position: relative;
            width: 100px;
            height: 70px;
            border-radius: 5px;
            overflow: hidden;
            cursor: pointer;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .theme-option input[type="radio"] {
            position: absolute;
            opacity: 0;
        }

        .theme-option-label {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 12px;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.5);
        }

        .theme-option input[type="radio"]:checked+.theme-option-label {
            border: 3px solid #fd2b2b;
        }

        /* Theme colors */
        .theme-default {
            background: linear-gradient(to bottom right, #fd2b2b, #c82333);
        }

        .theme-dark {
            background: linear-gradient(to bottom right, #333333, #121212);
        }

        .theme-blue {
            background: linear-gradient(to bottom right, #2b88fd, #2337c8);
        }

        .theme-green {
            background: linear-gradient(to bottom right, #2bfd7e, #23c852);
        }

        .theme-purple {
            background: linear-gradient(to bottom right, #8a2bfd, #6023c8);
        }

        .alert {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 60px;
            height: 30px;
            margin-top: 5px;
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
            border-radius: 34px;
        }

        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 22px;
            width: 22px;
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
            transform: translateX(30px);
        }

        .login-history {
            margin-top: 20px;
        }

        .login-history h4 {
            margin-bottom: 15px;
        }

        .history-item {
            background-color: #f9f9f9;
            padding: 12px 15px;
            border-radius: 5px;
            margin-bottom: 10px;
        }

        .history-item p {
            margin: 5px 0;
            font-size: 14px;
        }

        .history-item .time {
            color: #666;
            font-style: italic;
        }

        .history-item .ip {
            font-weight: 600;
        }

        .notification-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid #f0f0f0;
        }

        .notification-row:last-child {
            border-bottom: none;
        }

        .notification-label {
            font-weight: 500;
        }

        .notification-description {
            color: #666;
            font-size: 14px;
            margin-top: 3px;
        }

        /* Responsive fix for mobile */
        @media (max-width: 768px) {
            .settings-sidebar {
                margin-bottom: 20px;
            }

            .profile-picture-container {
                flex-direction: column;
                align-items: flex-start;
            }

            .profile-picture {
                margin-right: 0;
                margin-bottom: 15px;
            }

            .settings-section {
                padding: 15px;
            }
        }

        /* Safari specific fixes */
        @supports (-webkit-touch-callout: none) {
            .btn {
                -webkit-appearance: none;
            }

            .form-control {
                -webkit-appearance: none;
                border-radius: 5px;
            }
        }
    </style>
</head>

<body>
    <div class="settings-container">
        <h1 class="page-title">Settings</h1>

        <?php if (!empty($message)): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="settings-grid">
            <div class="settings-sidebar">
                <ul>
                    <li><a href="#profile" class="nav-link active" data-section="profile"><i class="fa fa-user"></i>
                            Profile</a></li>
                    <li><a href="#security" class="nav-link" data-section="security"><i class="fa fa-lock"></i>
                            Security</a></li>
                    <li><a href="#notifications" class="nav-link" data-section="notifications"><i
                                class="fa fa-bell"></i> Notifications</a></li>
                    <li><a href="#appearance" class="nav-link" data-section="appearance"><i
                                class="fa fa-paint-brush"></i> Appearance</a></li>
                    <li><a href="#account" class="nav-link" data-section="account"><i class="fa fa-cog"></i> Account</a>
                    </li>
                </ul>
            </div>

            <div class="settings-content">
                <!-- Profile Section -->
                <section id="profile" class="settings-section active">
                    <h2 class="settings-header"><i class="fa fa-user"></i> Profile Settings</h2>

                    <form method="POST" enctype="multipart/form-data">
                        <div class="profile-picture-container">
                            <div class="profile-picture">
                                <?php if (!empty($user['profile_picture'])): ?>
                                    <img src="../uploads/profile_pictures/<?php echo htmlspecialchars($user['profile_picture']); ?>"
                                        alt="Profile Picture">
                                <?php else: ?>
                                    <span class="profile-picture-default"><i class="fa fa-user"></i></span>
                                <?php endif; ?>
                            </div>

                            <div>
                                <label for="profile_picture" class="btn btn-secondary">
                                    <i class="fa fa-upload"></i> Upload Picture
                                </label>
                                <input type="file" id="profile_picture" name="profile_picture" accept="image/*"
                                    style="display:none;">
                                <p>Accepted formats: JPG, PNG, GIF (max 5MB)</p>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="username">Username</label>
                            <input type="text" class="form-control" id="username" name="username"
                                value="<?php echo htmlspecialchars($user['username'] ?? ''); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <input type="email" class="form-control" id="email" name="email"
                                value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="phone">Phone Number (optional)</label>
                            <input type="tel" class="form-control" id="phone" name="phone"
                                value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                        </div>

                        <button type="submit" name="update_profile" class="btn">Save Changes</button>
                    </form>
                </section>

                <!-- Security Section -->
                <section id="security" class="settings-section">
                    <h2 class="settings-header"><i class="fa fa-lock"></i> Security Settings</h2>

                    <form method="POST">
                        <h3>Change Password</h3>

                        <div class="form-group">
                            <label for="current_password">Current Password</label>
                            <input type="password" class="form-control" id="current_password" name="current_password"
                                required>
                        </div>

                        <div class="form-group">
                            <label for="new_password">New Password</label>
                            <input type="password" class="form-control" id="new_password" name="new_password" required>
                            <small>Minimum 8 characters</small>
                        </div>

                        <div class="form-group">
                            <label for="confirm_password">Confirm New Password</label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password"
                                required>
                        </div>

                        <button type="submit" name="update_password" class="btn">Update Password</button>
                    </form>

                    <div class="login-history">
                        <h4>Recent Login Activity</h4>

                        <?php if (!empty($loginHistory)): ?>
                            <?php foreach ($loginHistory as $login): ?>
                                <div class="history-item">
                                    <p class="ip">IP: <?php echo htmlspecialchars($login['ip_address']); ?></p>
                                    <p class="time"><?php echo date('F j, Y, g:i a', strtotime($login['login_time'])); ?></p>
                                    <p class="device"><?php echo htmlspecialchars(substr($login['user_agent'], 0, 100)); ?></p>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p>No recent login activity.</p>
                        <?php endif; ?>
                    </div>
                </section>

                <!-- Notifications Section -->
                <section id="notifications" class="settings-section">
                    <h2 class="settings-header"><i class="fa fa-bell"></i> Notification Settings</h2>

                    <form method="POST">
                        <div class="notification-row">
                            <div>
                                <div class="notification-label">Enable All Notifications</div>
                                <div class="notification-description">Get in-app notifications about important updates
                                </div>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="notifications_enabled" <?php echo (!empty($user['notifications_enabled'])) ? 'checked' : ''; ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>

                        <div class="notification-row">
                            <div>
                                <div class="notification-label">Email Notifications</div>
                                <div class="notification-description">Receive email updates about shifts and system
                                    changes</div>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="email_notifications" <?php echo (!empty($user['email_notifications'])) ? 'checked' : ''; ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>

                        <button type="submit" name="update_preferences" class="btn">Save Notification Settings</button>
                    </form>
                </section>

                <!-- Appearance Section -->
                <section id="appearance" class="settings-section">
                    <h2 class="settings-header"><i class="fa fa-paint-brush"></i> Appearance Settings</h2>

                    <form method="POST">
                        <div class="form-group">
                            <label>Select Theme</label>

                            <div class="theme-selector">
                                <?php foreach ($availableThemes as $themeKey => $themeName): ?>
                                    <div class="theme-option">
                                        <input type="radio" name="theme" id="theme-<?php echo $themeKey; ?>"
                                            value="<?php echo $themeKey; ?>" <?php echo ($userTheme === $themeKey) ? 'checked' : ''; ?>>
                                        <label for="theme-<?php echo $themeKey; ?>"
                                            class="theme-option-label theme-<?php echo $themeKey; ?>">
                                            <?php echo $themeName; ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <button type="submit" name="update_preferences" class="btn">Save Appearance Settings</button>
                    </form>
                </section>

                <!-- Account Section -->
                <section id="account" class="settings-section">
                    <h2 class="settings-header"><i class="fa fa-cog"></i> Account Settings</h2>

                    <h3>Data Export</h3>
                    <p>Download all your data, including shifts, earnings and profile information.</p>
                    <button type="button" class="btn btn-secondary" onclick="exportUserData()">
                        <i class="fa fa-download"></i> Export My Data
                    </button>

                    <h3 style="margin-top: 30px; color: #dc3545;">Danger Zone</h3>
                    <p>Deleting your account is permanent and cannot be undone. All your data will be lost.</p>
                    <button type="button" class="btn" style="background-color: #dc3545;" onclick="confirmDelete()">
                        <i class="fa fa-trash"></i> Delete Account
                    </button>
                </section>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Navigation between settings sections
            const navLinks = document.querySelectorAll('.nav-link');
            const sections = document.querySelectorAll('.settings-section');

            navLinks.forEach(link => {
                link.addEventListener('click', function (e) {
                    e.preventDefault();

                    // Remove active class from all links and sections
                    navLinks.forEach(l => l.classList.remove('active'));
                    sections.forEach(s => s.classList.remove('active'));

                    // Add active class to clicked link
                    this.classList.add('active');

                    // Show corresponding section
                    const sectionId = this.getAttribute('data-section');
                    document.getElementById(sectionId).classList.add('active');
                });
            });

            // Profile picture preview
            const profilePicInput = document.getElementById('profile_picture');
            if (profilePicInput) {
                profilePicInput.addEventListener('change', function () {
                    if (this.files && this.files[0]) {
                        const reader = new FileReader();
                        reader.onload = function (e) {
                            const profilePicContainer = document.querySelector('.profile-picture');
                            if (profilePicContainer.querySelector('img')) {
                                profilePicContainer.querySelector('img').src = e.target.result;
                            } else {
                                const img = document.createElement('img');
                                img.src = e.target.result;
                                profilePicContainer.innerHTML = '';
                                profilePicContainer.appendChild(img);
                            }
                        }
                        reader.readAsDataURL(this.files[0]);
                    }
                });
            }
        });

        function confirmDelete() {
            if (confirm("Warning: This will permanently delete your account and all associated data. This action cannot be undone. Are you sure you want to proceed?")) {
                window.location.href = "../functions/delete_account.php";
            }
        }

        function exportUserData() {
            window.location.href = "../functions/export_user_data.php";
        }
    </script>

    <script src="/rota-app-main/js/menu.js"></script>
    <script src="/rota-app-main/js/pwa-debug.js"></script>
    <script src="/rota-app-main/js/links.js"></script>
</body>

</html>