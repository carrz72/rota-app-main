<?php
session_start();
require '../includes/db.php';

// Basic error variable
$error = '';

// Only process if form was submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get and sanitize form inputs
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    // Very basic validation
    if ($password !== $confirm_password) {
        $error = "Passwords do not match!";
    } elseif (strlen($username) < 3) {
        $error = "Username must be at least 3 characters long.";
    } elseif (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        try {
            // Check if email already exists
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);

            if ($stmt->rowCount() > 0) {
                $error = "Email already registered!";
            } else {
                // Insert new user with minimal fields
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO users (username, email, password, role, email_verified) VALUES (?, ?, ?, 'user', 1)");

                if ($stmt->execute([$username, $email, $hashedPassword])) {
                    // Success - redirect to login
                    header("Location: login.php");
                    exit;
                } else {
                    $error = "Registration failed. Please try again.";
                }
            }
        } catch (Exception $e) {
            $error = "An error occurred. Please try again later.";
            error_log("Registration error: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Open Rota">
    <link rel="apple-touch-icon" href="/rota-app-main/images/logo.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no">
    <link rel="manifest" href="/rota-app-main/manifest.json">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <title>Register - Open Rota</title>
    <style>
        @font-face {
            font-family: "newFont";
            src: url("../fonts/CooperHewitt-Book.otf");
            font-weight: normal;
            font-style: normal;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: "newFont", Arial, sans-serif;
            background-image: url(../images/backg3.jpg);
            background-size: cover;
            background-position: center;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
        }

        .register-container {
            background-color: rgba(255, 255, 255, 0.95);
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            padding: 40px 30px;
            width: 100%;
            max-width: 450px;
            text-align: center;
            position: relative;
        }

        .app-logo {
            margin-bottom: 20px;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .app-logo img {
            width: 80px;
            height: 80px;
            border-radius: 15px;
            object-fit: contain;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .back-link {
            position: absolute;
            top: 20px;
            left: 20px;
            color: #888;
            display: flex;
            align-items: center;
            gap: 5px;
            text-decoration: none;
            transition: color 0.3s;
        }

        .back-link:hover {
            color: #fd2b2b;
            text-decoration: none;
        }

        h2 {
            color: #333;
            font-size: 2rem;
            margin-bottom: 30px;
            font-weight: 600;
            position: relative;
        }

        h2:after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 50%;
            transform: translateX(-50%);
            width: 50px;
            height: 3px;
            background-color: #fd2b2b;
            border-radius: 3px;
        }

        .error {
            background-color: rgba(255, 0, 0, 0.1);
            color: #e61919;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: left;
            font-size: 0.9rem;
            border-left: 4px solid #e61919;
        }

        .form-group {
            margin-bottom: 20px;
            position: relative;
            text-align: left;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #444;
            font-size: 0.9rem;
        }

        .input-with-icon {
            position: relative;
        }

        .input-with-icon i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #888;
        }

        input {
            width: 100%;
            padding: 12px 15px 12px 45px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.3s, box-shadow 0.3s;
        }

        input:focus {
            border-color: #fd2b2b;
            box-shadow: 0 0 0 3px rgba(253, 43, 43, 0.1);
            outline: none;
        }

        button {
            background-color: #fd2b2b;
            color: white;
            border: none;
            padding: 12px 0;
            width: 100%;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.3s, transform 0.2s;
            margin-top: 20px;
        }

        button:hover {
            background-color: #e61919;
            transform: translateY(-2px);
        }

        button:active {
            transform: translateY(0);
        }

        .login-link {
            margin-top: 25px;
            font-size: 0.95rem;
            color: #666;
        }

        .login-link a {
            color: #fd2b2b;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s;
        }

        .login-link a:hover {
            color: #c82333;
            text-decoration: underline;
        }

        @media (max-width: 480px) {
            .register-container {
                padding: 30px 20px;
            }

            h2 {
                font-size: 1.8rem;
            }

            input {
                padding: 10px 15px 10px 40px;
            }
        }

        /* Safari-specific fixes */
        @supports (-webkit-touch-callout: none) {

            input,
            button {
                -webkit-appearance: none;
                border-radius: 8px;
            }
        }
    </style>
</head>

<body>
    <div class="register-container">
        <a href="login.php" class="back-link">
            <i class="fas fa-arrow-left"></i> Back to Login
        </a>

        <div class="app-logo">
            <img src="../images/logo.png" alt="Open Rota Logo"
                onerror="this.src='../images/icon.png'; this.onerror='';">
        </div>
        <h2>Create Account</h2>

        <?php if (!empty($error)): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form action="register.php" method="POST">
            <div class="form-group">
                <label for="username">Username</label>
                <div class="input-with-icon">
                    <i class="fas fa-user"></i>
                    <input type="text" id="username" name="username" placeholder="Choose a username" required
                        value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                </div>
            </div>

            <div class="form-group">
                <label for="email">Email Address</label>
                <div class="input-with-icon">
                    <i class="fas fa-envelope"></i>
                    <input type="email" id="email" name="email" placeholder="Enter your email" required
                        value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                </div>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <div class="input-with-icon">
                    <i class="fas fa-lock"></i>
                    <input type="password" id="password" name="password" placeholder="Create a password" required>
                </div>
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirm Password</label>
                <div class="input-with-icon">
                    <i class="fas fa-lock"></i>
                    <input type="password" id="confirm_password" name="confirm_password"
                        placeholder="Confirm your password" required>
                </div>
            </div>

            <button type="submit">Create Account</button>
        </form>

        <p class="login-link">Already have an account? <a href="login.php">Login</a></p>
    </div>

    <script src="/rota-app-main/js/pwa-debug.js"></script>
    <script src="/rota-app-main/js/links.js"></script>
</body>

</html>