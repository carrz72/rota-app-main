<?php
session_start();
require '../includes/db.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $agree_terms = isset($_POST['agree_terms']) ? $_POST['agree_terms'] : '';

    // Validation
    $errors = [];

    // Check if terms are accepted
    if (!$agree_terms) {
        $errors['terms'] = "You must agree to the terms and conditions.";
    }

    // Check if passwords match
    if ($password !== $confirm_password) {
        $errors['password'] = "Passwords do not match!";
    }

    // Check password strength
    if (strlen($password) < 8) {
        $errors['password'] = "Password must be at least 8 characters long.";
    } elseif (!preg_match("#[0-9]+#", $password)) {
        $errors['password'] = "Password must include at least one number.";
    } elseif (!preg_match("#[A-Z]+#", $password)) {
        $errors['password'] = "Password must include at least one uppercase letter.";
    }

    // Check username length
    if (strlen($username) < 3) {
        $errors['username'] = "Username must be at least 3 characters long.";
    }

    // Check if email is valid
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = "Please enter a valid email address.";
    }

    // If no errors, proceed with registration
    if (empty($errors)) {
        // Check if the email already exists.
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);

        if ($stmt->rowCount() > 0) {
            $errors['email'] = "Email already registered!";
        } else {
            // Hash the password and insert the new user with email_verified set to 1.
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (username, email, password, role, email_verified) VALUES (?, ?, ?, 'user', 1)");
            if ($stmt->execute([$username, $email, $hashedPassword])) {
                // Get the new user's ID
                $user_id = $conn->lastInsertId();

                // Auto-login the user
                $_SESSION['user_id'] = $user_id;
                $_SESSION['username'] = $username;
                $_SESSION['role'] = 'user';

                // Record initial login
                $ip = $_SERVER['REMOTE_ADDR'];
                $user_agent = $_SERVER['HTTP_USER_AGENT'];
                $stmt = $conn->prepare("INSERT INTO login_history (user_id, ip_address, user_agent) VALUES (?, ?, ?)");
                $stmt->execute([$user_id, $ip, $user_agent]);

                header("Location: ../users/dashboard.php");
                exit;
            } else {
                $errors['general'] = "An error occurred while registering. Please try again.";
            }
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
    <link rel="stylesheet" href="../css/loginandregister.css">
    <style>
        /* Enhanced styles for register page */
        body {
            background-image: url(../images/backg3.jpg);
            background-size: cover;
            background-position: center;
            display: block;
            /* Changed from flex to block for scrollable layout */
            padding: 40px 20px;
            margin: 0;
            font-family: "newFont", Arial, sans-serif;
            min-height: 100vh;
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
            overflow: hidden;
            margin: 20px auto;
            /* Center horizontally with auto margins */
        }

        /* Fixed logo styling */
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
            /* Fix for logo aspect ratio */
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
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

        /* Back button styling */
        .back-btn {
            position: absolute;
            top: 20px;
            left: 20px;
            background-color: transparent;
            border: none;
            color: #888;
            font-size: 1.2rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: color 0.3s;
        }

        .back-btn:hover {
            color: #fd2b2b;
        }

        .back-btn i {
            font-size: 1rem;
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
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #888;
            width: 16px;
            /* Fixed width for icon */
            text-align: center;
        }

        .form-control {
            width: 100%;
            padding: 12px 15px 12px 45px;
            /* Increased left padding from 40px to 45px */
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.3s, box-shadow 0.3s;
            box-sizing: border-box;
        }

        .form-control:focus {
            border-color: #fd2b2b;
            box-shadow: 0 0 0 3px rgba(253, 43, 43, 0.1);
            outline: none;
        }

        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #888;
        }

        .feedback {
            font-size: 0.85rem;
            margin-top: 5px;
            display: none;
        }

        .valid-feedback {
            color: #28a745;
        }

        .invalid-feedback {
            color: #dc3545;
        }

        .password-strength {
            height: 5px;
            background-color: #eee;
            border-radius: 2px;
            margin-top: 10px;
            overflow: hidden;
        }

        .strength-meter {
            height: 100%;
            width: 0;
            border-radius: 2px;
            transition: width 0.3s, background-color 0.3s;
        }

        .strength-weak {
            background-color: #dc3545;
            width: 33.33%;
        }

        .strength-medium {
            background-color: #ffc107;
            width: 66.66%;
        }

        .strength-strong {
            background-color: #28a745;
            width: 100%;
        }

        .terms-checkbox {
            display: flex;
            align-items: flex-start;
            margin-top: 25px;
        }

        .terms-checkbox input {
            margin-top: 5px;
            margin-right: 10px;
        }

        .terms-checkbox label {
            font-size: 0.9rem;
            color: #666;
            line-height: 1.4;
        }

        .terms-checkbox a {
            color: #fd2b2b;
            text-decoration: none;
        }

        .terms-checkbox a:hover {
            text-decoration: underline;
        }

        .btn-container {
            margin-top: 30px;
        }

        .register-btn {
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
        }

        .register-btn:hover {
            background-color: #e61919;
            transform: translateY(-2px);
        }

        .register-btn:active {
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

        .error-message {
            background-color: rgba(255, 0, 0, 0.1);
            color: #e61919;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: left;
            font-size: 0.9rem;
            border-left: 3px solid #e61919;
        }

        .error-list {
            margin: 0;
            padding-left: 20px;
        }

        .loader {
            display: none;
            width: 20px;
            height: 20px;
            border: 2px solid #fff;
            border-radius: 50%;
            border-top-color: transparent;
            animation: spin 0.8s linear infinite;
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
        }

        @keyframes spin {
            to {
                transform: translateY(-50%) rotate(360deg);
            }
        }

        .submit-btn-container {
            position: relative;
        }

        /* Safari-specific fixes */
        @supports (-webkit-touch-callout: none) {

            input,
            button {
                -webkit-appearance: none;
                border-radius: 8px;
            }
        }

        /* Responsive adjustments */
        @media (max-width: 480px) {
            body {
                padding: 20px 10px;
            }

            .register-container {
                padding: 30px 20px;
                margin: 10px auto;
            }

            h2 {
                font-size: 1.8rem;
            }

            .form-control {
                padding: 10px 15px 10px 40px;
                /* Adjusted padding for smaller screens */
            }

            .back-btn {
                top: 15px;
                left: 15px;
                font-size: 1rem;
            }
        }
    </style>
</head>

<body>
    <div class="register-container">
        <!-- Back button to login page -->
        <a href="login.php" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back to Login
        </a>

        <div class="app-logo">
            <img src="../images/logo.png" alt="Open Rota Logo"
                onerror="this.src='../images/icon.png'; this.onerror='';">
        </div>
        <h2>Create Account</h2>

        <?php if (isset($errors) && !empty($errors)): ?>
            <div class="error-message">
                <ul class="error-list">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo $error; ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form id="registerForm" action="register.php" method="POST">
            <div class="form-group">
                <label for="username">Username</label>
                <div class="input-with-icon">
                    <i class="fas fa-user"></i>
                    <input type="text" id="username" name="username" class="form-control"
                        placeholder="Choose a username" required minlength="3"
                        value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                </div>
                <div id="username-feedback" class="feedback"></div>
            </div>

            <div class="form-group">
                <label for="email">Email Address</label>
                <div class="input-with-icon">
                    <i class="fas fa-envelope"></i>
                    <input type="email" id="email" name="email" class="form-control" placeholder="Enter your email"
                        required value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                </div>
                <div id="email-feedback" class="feedback"></div>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <div class="input-with-icon">
                    <i class="fas fa-lock"></i>
                    <input type="password" id="password" name="password" class="form-control"
                        placeholder="Create a password" required minlength="8">
                    <span class="password-toggle" onclick="togglePassword('password')">
                        <i class="fas fa-eye"></i>
                    </span>
                </div>
                <div class="password-strength">
                    <div class="strength-meter" id="strength-meter"></div>
                </div>
                <div id="password-feedback" class="feedback"></div>
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirm Password</label>
                <div class="input-with-icon">
                    <i class="fas fa-lock"></i>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control"
                        placeholder="Confirm your password" required minlength="8">
                    <span class="password-toggle" onclick="togglePassword('confirm_password')">
                        <i class="fas fa-eye"></i>
                    </span>
                </div>
                <div id="confirm-password-feedback" class="feedback"></div>
            </div>

            <div class="form-group">
                <div class="terms-checkbox">
                    <input type="checkbox" id="agree_terms" name="agree_terms" required>
                    <label for="agree_terms">I agree to the <a href="#">Terms and Conditions</a> and <a href="#">Privacy
                            Policy</a></label>
                </div>
            </div>

            <div class="btn-container">
                <div class="submit-btn-container">
                    <button type="submit" id="registerBtn" class="register-btn">
                        Create Account
                        <span class="loader" id="registerLoader"></span>
                    </button>
                </div>
            </div>
        </form>

        <p class="login-link">Already have an account? <a href="login.php">Sign In</a></p>
    </div>

    <script>
        // Toggle password visibility
        function togglePassword(inputId) {
            const passwordInput = document.getElementById(inputId);
            const toggleIcon = passwordInput.nextElementSibling.querySelector('i');

            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }

        // Password strength checker
        document.getElementById('password').addEventListener('input', function () {
            const password = this.value;
            const meter = document.getElementById('strength-meter');
            const feedback = document.getElementById('password-feedback');

            // Remove any existing classes
            meter.className = 'strength-meter';

            if (password.length === 0) {
                meter.style.width = '0';
                feedback.style.display = 'none';
                return;
            }

            // Check strength
            let strength = 0;

            // Length check
            if (password.length >= 8) strength += 1;

            // Complexity checks
            if (/[A-Z]/.test(password)) strength += 1;
            if (/[0-9]/.test(password)) strength += 1;
            if (/[^A-Za-z0-9]/.test(password)) strength += 1;

            // Display feedback
            feedback.style.display = 'block';

            if (strength < 2) {
                meter.classList.add('strength-weak');
                feedback.textContent = 'Weak password';
                feedback.className = 'feedback invalid-feedback';
            } else if (strength < 4) {
                meter.classList.add('strength-medium');
                feedback.textContent = 'Medium strength password';
                feedback.className = 'feedback invalid-feedback';
            } else {
                meter.classList.add('strength-strong');
                feedback.textContent = 'Strong password';
                feedback.className = 'feedback valid-feedback';
            }
        });

        // Form submission handling
        document.getElementById('registerForm').addEventListener('submit', function () {
            // Check if passwords match
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;

            if (password !== confirmPassword) {
                document.getElementById('confirm-password-feedback').textContent = 'Passwords do not match';
                document.getElementById('confirm-password-feedback').style.display = 'block';
                document.getElementById('confirm-password-feedback').className = 'feedback invalid-feedback';
                event.preventDefault();
                return false;
            }

            // Show loading indicator
            document.getElementById('registerBtn').disabled = true;
            document.getElementById('registerLoader').style.display = 'block';
        });
    </script>

    <script src="/rota-app-main/js/pwa-debug.js"></script>
</body>

</html>