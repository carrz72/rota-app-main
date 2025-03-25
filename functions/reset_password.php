<?php
session_start();
require '../includes/db.php';

// Ensure the reset email is set in session
if (!isset($_SESSION['reset_email'])) {
    die("Unauthorized access.");
}
$email = $_SESSION['reset_email'];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

    $stmt = $conn->prepare("UPDATE `users` SET `password` = ?, `reset_code` = NULL WHERE `email` = ?");
    $stmt->execute([$password, $email]);

    // Optionally, you can destroy the session variable
    unset($_SESSION['reset_email']);

    // Redirect to login page
    header("Location: ../functions/login.php");
    exit();
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="../css/forgot_password.css">
    <title>Reset Password</title>
</head>
<body>
    <div class="reset_password">
    <h1>Reset Password</h1>
    <form method="POST">
        <input type="password" name="password" placeholder="New Password" required>
        <button type="submit">Reset Password</button>
    </form>
    </div>
</body>
</html>