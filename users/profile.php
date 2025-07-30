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
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Open Rota">
    <link rel="icon" type="image/png" href="/rota-app-main/images/icon.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Profile</title>
    <link rel="stylesheet" href="../css/profile.css">
</head>

<body>
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
</body>

</html>