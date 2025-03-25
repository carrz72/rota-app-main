<?php
require '../includes/auth.php';
requireLogin();
require '../includes/db.php';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $user_id = $_SESSION['user_id'];
    $new_password = password_hash($_POST['new_password'], PASSWORD_DEFAULT);

    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
    $stmt->execute([$new_password, $user_id]);

    echo "Password changed successfully.";
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Change Password</title>
</head>
<body>
    <h1>Change Password</h1>
    <form method="POST">
        <input type="password" name="new_password" placeholder="New Password" required>
        <button type="submit">Change Password</button>
    </form>
</body>
</html>
