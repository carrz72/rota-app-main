<?php
// Start output buffering early to prevent any whitespace issues
ob_start();
session_start();

// Clear any previous session data on login page access
if (!isset($_POST['email'])) {
    // Only clear when not submitting the form (first page load)
    $_SESSION = array();
}

// Include DB connection with error handling
try {
    require_once '../includes/db.php';
} catch (Exception $e) {
    error_log("Database connection error: " . $e->getMessage());
    $error = "Unable to connect to database. Please try again later.";
}

// If already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: ../users/dashboard.php");
    exit;
}

$remember_email = '';
if (isset($_COOKIE['remember_email'])) {
    $remember_email = $_COOKIE['remember_email'];
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']) ? $_POST['remember'] : '';

    try {
        // Set or delete remember me cookie
        if ($remember) {
            setcookie('remember_email', $email, time() + (86400 * 30), "/"); // 30 days
        } else {
            setcookie('remember_email', "", time() - 3600, "/"); // Delete cookie
        }

        // Verify connection is still active
        if (!$conn || !($conn instanceof PDO)) {
            throw new Exception("Database connection lost");
        }

        $stmt = $conn->prepare("SELECT id, username, password, role FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {
            // Clear and recreate session to prevent session fixation
            session_regenerate_id(true);

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['login_time'] = time();

            try {
                // Record login for history - in a separate try block to avoid login failure
                $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
                $stmt = $conn->prepare("INSERT INTO login_history (user_id, ip_address, user_agent) VALUES (?, ?, ?)");
                $stmt->execute([$user['id'], $ip, $user_agent]);
            } catch (Exception $logError) {
                // Just log it, don't prevent login
                error_log("Login history recording error: " . $logError->getMessage());
            }

            // Make sure nothing has been output yet
            if (!headers_sent()) {
                header("Location: ../users/dashboard.php");
                exit;
            } else {
                echo "<script>window.location.href = '../users/dashboard.php';</script>";
                echo "If you are not redirected, <a href='../users/dashboard.php'>click here</a>";
                exit;
            }
        } else {
            $error = "Invalid email or password.";
        }
    } catch (Exception $e) {
        error_log("Login process error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        $error = "A system error occurred. Please try again later.";
    }
}

// Start output buffering to ensure no whitespace before headers
ob_start();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Open Rota">
    <link rel="apple-touch-icon" href="/rota-app-main/images/icon.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no">
    <link rel="manifest" href="/rota-app-main/manifest.json">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <title>Login - Open Rota</title>
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

        .login-container {
            background-color: rgba(255, 255, 255, 0.95);
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            padding: 40px 30px;
            width: 100%;
            max-width: 400px;
            text-align: center;
            position: relative;
            overflow: hidden;
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
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #888;
        }

        .form-control {
            width: 100%;
            padding: 12px 15px 12px 40px;
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

        .remember-forgot {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            font-size: 0.9rem;
        }

        .remember-me {
            display: flex;
            align-items: center;
        }

        .remember-me input {
            margin-right: 5px;
        }

        .forgot-password {
            color: #fd2b2b;
            text-decoration: none;
            transition: color 0.3s;
        }

        .forgot-password:hover {
            color: #c82333;
            text-decoration: underline;
        }

        .btn-container {
            margin-top: 30px;
        }

        .login-btn {
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

        .login-btn:hover {
            background-color: #e61919;
            transform: translateY(-2px);
        }

        .login-btn:active {
            transform: translateY(0);
        }

        .register-link {
            margin-top: 25px;
            font-size: 0.95rem;
            color: #666;
        }

        .register-link a {
            color: #fd2b2b;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s;
        }

        .register-link a:hover {
            color: #c82333;
            text-decoration: underline;
        }

        .error-message {
            background-color: rgba(255, 0, 0, 0.1);
            color: #e61919;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: left;
            font-size: 0.9rem;
            border-left: 4px solid #e61919;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .error-message i {
            font-size: 20px;
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

            .remember-me input[type="checkbox"] {
                -webkit-appearance: checkbox;
                width: 16px;
                height: 16px;
            }
        }

        /* Responsive adjustments */
        @media (max-width: 480px) {
            .login-container {
                padding: 30px 20px;
            }

            h2 {
                font-size: 1.8rem;
            }

            .form-control {
                padding: 10px 15px 10px 35px;
            }
        }
    </style>
</head>

<body>
    <div class="login-container">
        <div class="app-logo">
            <img src="../images/logo.png" alt="Open Rota Logo"
                onerror="this.src='../images/icon.png'; this.onerror='';">
        </div>
        <h2>Welcome Back</h2>

        <?php if (!empty($error)): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form id="loginForm" action="login.php" method="POST">
            <div class="form-group">
                <label for="email">Email Address</label>
                <div class="input-with-icon">
                    <i class="fas fa-envelope"></i>
                    <input type="email" id="email" name="email" class="form-control"
                        value="<?php echo htmlspecialchars($remember_email); ?>" placeholder="Enter your email" required
                        autocomplete="email">
                </div>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <div class="input-with-icon">
                    <i class="fas fa-lock"></i>
                    <input type="password" id="password" name="password" class="form-control"
                        placeholder="Enter your password" required autocomplete="current-password">
                    <span class="password-toggle" onclick="togglePassword()">
                        <i class="fas fa-eye"></i>
                    </span>
                </div>
            </div>

            <div class="remember-forgot">
                <div class="remember-me">
                    <input type="checkbox" id="remember" name="remember" <?php echo $remember_email ? 'checked' : ''; ?>>
                    <label for="remember">Remember me</label>
                </div>
                <a href="forgot_password.php" class="forgot-password">Forgot Password?</a>
            </div>

            <div class="btn-container">
                <div class="submit-btn-container">
                    <button type="submit" id="loginBtn" class="login-btn">
                        Log In
                        <span class="loader" id="loginLoader"></span>
                    </button>
                </div>
            </div>
        </form>

        <p class="register-link">Don't have an account? <a href="register.php">Sign Up</a></p>
    </div>

    <script>
        // Toggle password visibility
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.querySelector('.password-toggle i');

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

        // Fixed form submission with loader
        document.getElementById('loginForm').addEventListener('submit', function (event) {
            // Don't prevent default - let the form submit naturally
            // Just show the loader and disable the button
            document.getElementById('loginBtn').disabled = true;
            document.getElementById('loginLoader').style.display = 'block';

            // Make sure the form submission continues
            return true;
        });
    </script>

    <script src="../js/pwa-debug.js"></script>
</body>

</html>
<?php
// End output buffering and send all output to browser
ob_end_flush();
?>