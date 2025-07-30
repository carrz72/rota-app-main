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
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Open Rota">
    <link rel="icon" type="image/png" href="/rota-app-main/images/icon.jpg">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <title>Reset Password - Open Rota</title>
    <style>
        @font-face {
            font-family: "newFont";
            src: url("../fonts/CooperHewitt-Book.otf");
            font-weight: normal;
            font-style: normal;
        }

        body {
            font-family: "newFont", Arial, sans-serif;
            background: url("../images/backg3.jpg") no-repeat center center fixed;
            background-size: cover;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
            color: #333;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .reset-container {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            padding: 40px;
            width: 100%;
            max-width: 450px;
            text-align: center;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .reset-container:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
        }

        .reset-header {
            margin-bottom: 30px;
        }

        .reset-header h1 {
            color: #fd2b2b;
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .reset-header p {
            color: #666;
            font-size: 1rem;
            margin: 0;
        }

        .form-group {
            margin-bottom: 20px;
            text-align: left;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 600;
        }

        .form-control {
            width: 100%;
            padding: 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 1rem;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
            box-sizing: border-box;
        }

        .form-control:focus {
            outline: none;
            border-color: #fd2b2b;
            box-shadow: 0 0 0 3px rgba(253, 43, 43, 0.1);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 15px 25px;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s ease;
            width: 100%;
        }

        .btn-primary {
            background: #fd2b2b;
            color: white;
        }

        .btn-primary:hover {
            background: #c82333;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(253, 43, 43, 0.3);
        }

        .login-link {
            color: #fd2b2b;
            text-decoration: none;
            font-weight: 600;
        }

        .login-link:hover {
            text-decoration: underline;
        }

        /* Responsive design */
        @media (max-width: 768px) {
            .reset-container {
                margin: 10px;
                padding: 30px 20px;
            }

            .reset-header h1 {
                font-size: 1.5rem;
            }
        }
    </style>
</head>

<body>
    <div class="auth-container">
        <!-- Logo Header -->
        <div class="logo-header">
            <div class="logo">Open Rota</div>
        </div>

        <div class="forgot-header">
            <h1><i class="fas fa-key"></i> Reset Password</h1>
            <p>Enter your new password below</p>
        </div>
        <form method="POST" class="card__form">
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

            <button type="submit" class="btn-primary">
                <i class="fas fa-save"></i> Reset Password
            </button>
        </form>

        <div class="text-center mt-20">
            Remember your password? <a href="login.php" class="auth-link">Back to Login</a>
        </div>
    </div>

    <script>
        // Simple password confirmation validation
        document.querySelector('form').addEventListener('submit', function (e) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;

            if (password !== confirmPassword) {
                e.preventDefault();
                alert('Passwords do not match. Please try again.');
                return false;
            }
        });
    </script>
</body>

</html>