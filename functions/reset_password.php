<?php
require_once '../includes/session_starter.php';
require '../includes/db.php';

// Ensure the reset email is set in session
if (!isset($_SESSION['reset_email'])) {
    die("Unauthorized access.");
}

// Check if reset session has expired (30 minutes)
if (isset($_SESSION['reset_time']) && (time() - $_SESSION['reset_time']) > 1800) {
    unset($_SESSION['reset_email']);
    unset($_SESSION['reset_time']);
    die("Reset session has expired. Please start the password reset process again.");
}

$email = $_SESSION['reset_email'];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

    $stmt = $conn->prepare("UPDATE `users` SET `password` = ?, `reset_code` = NULL WHERE `email` = ?");
    $stmt->execute([$password, $email]);

    // Optionally, you can destroy the session variable
    unset($_SESSION['reset_email']);
    unset($_SESSION['reset_time']);

    // Redirect to login page
    header("Location: ../functions/login.php");
    exit();
}
?>
<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Open Rota">
    <link rel="icon" type="image/png" href="/rota-app-main/images/icon.jpg">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <title>Reset Password - Open Rota</title>
    <link rel="stylesheet" href="../css/forgot_password.css">
</head>

<body>
    <div class="auth-container">
        <!-- Logo Header -->
        <div class="logo-header">
            <div class="logo"><img src="../images/new logo.png" alt="Open Rota" style="height: 60px;"></div>
        </div>

        <div class="reset_password">
            <div class="forgot-header">
                <h1><span class="icon"><i class="fas fa-key"></i></span> Reset Password</h1>
                <p class="help-text">Enter your new password below</p>
            </div>

            <form method="POST" class="card__form form-grid" novalidate>
                <div class="form-group">
                    <label for="password">New Password</label>
                    <input type="password" id="password" name="password" class="form-control"
                        placeholder="Enter your new password" required minlength="8">
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <input type="password" id="confirm_password" class="form-control"
                        placeholder="Confirm your new password" required minlength="8">
                </div>

                <div class="actions">
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-save"></i> Reset Password
                    </button>
                </div>
            </form>

            <div class="text-center mt-20" style="margin-top:18px;">
                Remember your password? <a href="login.php" class="auth-link">Back to Login</a>
            </div>
        </div>
    </div>

    <script>
        // Simple password confirmation validation
        (function () {
            const form = document.querySelector('form');
            if (!form) return;

            form.addEventListener('submit', function (e) {
                const password = document.getElementById('password').value;
                const confirmPassword = document.getElementById('confirm_password').value;

                if (password !== confirmPassword) {
                    e.preventDefault();
                    alert('Passwords do not match. Please try again.');
                    return false;
                }
            });
        })();
    </script>
</body>

</html>