<?php
require_once '../includes/error_handler.php';
require_once '../includes/auth.php';

// Require login using the enhanced session management
requireLogin();

require_once '../includes/db.php';

$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT username, email FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    handleApplicationError('404', "User account not found.");
}
?>
<!DOCTYPE html>
<html>

<head>
    <script>
        try {
            if (!document.documentElement.getAttribute('data-theme')) {
                var saved = localStorage.getItem('rota_theme');
                if (saved === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
            }
        } catch (e) {}
    </script>
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Open Rota">
    <link rel="icon" type="image/png" href="../images/icon.png">
    <link rel="manifest" href="../manifest.json">
    <link rel="apple-touch-icon" href="../images/icon.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Profile - Open Rota</title>
    <link rel="stylesheet" href="../css/navigation.css">
    <link rel="stylesheet" href="../css/profile.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
    <link rel="stylesheet" href="../css/dark_mode.css">
    <?php
    if (isset($_SESSION['user_id'])) {
        try {
            $stmtTheme = $conn->prepare('SELECT theme FROM users WHERE id = ? LIMIT 1');
            $stmtTheme->execute([$_SESSION['user_id']]);
            $row = $stmtTheme->fetch(PDO::FETCH_ASSOC);
            $userTheme = $row && !empty($row['theme']) ? $row['theme'] : null;
            if ($userTheme === 'dark') {
                echo "<script>document.documentElement.setAttribute('data-theme','dark');</script>\n";
            }
        } catch (Exception $e) {}
    }
    ?>
</head>

<body>
    <!-- Navigation Header -->
    <div class="logo">
        <img src="../images/logo.png" alt="Open Rota Logo">
        <span>Open Rota</span>
    </div>
    
    <div class="nav-group">
        <div class="notification-icon" id="notification-icon">
            <i class="fa fa-bell"></i>
        </div>
        
        <div class="menu-toggle" id="menu-toggle">
            ☰
        </div>
    </div>
    
    <!-- Navigation Menu -->
    <nav class="nav-links" id="nav-links">
        <ul>
            <li><a href="dashboard.php"><i class="fa fa-tachometer-alt"></i> Dashboard</a></li>
            <li><a href="shifts.php"><i class="fa fa-calendar-alt"></i> My Shifts</a></li>
            <li><a href="rota.php"><i class="fa fa-calendar"></i> Rota</a></li>
            <li><a href="roles.php"><i class="fa fa-briefcase"></i> Roles</a></li>
            <li><a href="profile.php"><i class="fa fa-user"></i> Profile</a></li>
            <li><a href="settings.php"><i class="fa fa-cogs"></i> Settings</a></li>
            <li><a href="../functions/logout.php"><i class="fa fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </nav>

    <div class="container">
        <h1>Your Profile</h1>
        <form action="../functions/update_profile.php" method="POST">
            <div>
                <label for="username">Username:</label>
                <input type="text" id="username" name="username"
                    value="<?php echo htmlspecialchars($user['username']); ?>" required>
            </div>
            <div>
                <label for="email">Email:</label>
                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>"
                    required>
            </div>
            <button type="submit">Update Profile</button>
        </form>
        <a href="change_password.php">Change Password</a><br>
        <a href="dashboard.php">Back to Dashboard</a>
    </div>
    <script src="../js/darkmode.js"></script>
    <script src="../js/menu.js"></script>
</body>

</html>