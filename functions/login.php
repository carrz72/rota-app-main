<?php
if (isset($_SESSION['user_id'])) {
    header("Location: ../users/dashboard.php");
    exit;
}
require '../includes/auth.php';

$remember_email = '';
if (isset($_COOKIE['remember_email'])) {
    $remember_email = $_COOKIE['remember_email'];
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = $_POST['email'];
    $password = $_POST['password'];
    $remember = isset($_POST['remember']) ? $_POST['remember'] : '';

    // Set or delete remember me cookie
    if ($remember) {
        setcookie('remember_email', $email, time() + (86400 * 30), "/"); // 30 days
    } else {
        setcookie('remember_email', "", time() - 3600, "/"); // Delete cookie
    }

    $stmt = $conn->prepare("SELECT id, username, password, role FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];  // ✅ Store username in session
        $_SESSION['role'] = $user['role'];

        // Record login for history
        $ip = $_SERVER['REMOTE_ADDR'];
        $user_agent = $_SERVER['HTTP_USER_AGENT'];
        $stmt = $conn->prepare("INSERT INTO login_history (user_id, ip_address, user_agent) VALUES (?, ?, ?)");
        $stmt->execute([$user['id'], $ip, $user_agent]);

        header("Location: ../users/dashboard.php");
        exit;
    } else {
        $error = "Invalid email or password.";
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
    <link rel="apple-touch-icon" href="/rota-app-main/images/icon.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no">
    <link rel="manifest" href="/rota-app-main/manifest.json">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <title>Login - Open Rota</title>
    <link rel="stylesheet" href="../css/loginandregister.css">
    <style>
        /* Enhanced styles for login page */
        body {
            background-image: url(../images/backg3.jpg);
            background-size: cover;
            background-position: center;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
            font-family: "newFont", Arial, sans-serif;
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

        .app-logo {
            margin-bottom: 20px;
        }

        .app-logo img {
            width: 80px;
            height: 80px;
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
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: left;
            font-size: 0.9rem;
            border-left: 3px solid #e61919;
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

        .social-login {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }

        .social-login p {
            color: #666;
            margin-bottom: 15px;
            font-size: 0.9rem;
        }

        .social-login-buttons {
            display: flex;
            justify-content: center;
            gap: 15px;
        }

        .social-btn {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: none;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.3s;
        }

        .social-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .google-btn {
            background-color: #fff;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            color: #ea4335;
        }

        .facebook-btn {
            background-color: #3b5998;
            color: white;
        }

        .apple-btn {
            background-color: #000;
            color: white;
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
            <img src="/rota-app-main/images/icon.png" alt="Open Rota Logo">
        </div>
        <h2>Welcome Back</h2>

        <?php if (isset($error)): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <form id="loginForm" action="login.php" method="POST">
            <div class="form-group">
                <label for="email">Email Address</label>
                <div class="input-with-icon">
                    <i class="fas fa-envelope"></i>
                    <input type="email" id="email" name="email" class="form-control"
                        value="<?php echo htmlspecialchars($remember_email); ?>" placeholder="Enter your email"
                        required>
                </div>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <div class="input-with-icon">
                    <i class="fas fa-lock"></i>
                    <input type="password" id="password" name="password" class="form-control"
                        placeholder="Enter your password" required>
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

        <!-- Social Login Options (Frontend only - needs backend implementation) -->
        <div class="social-login">
            <p>Or log in with</p>
            <div class="social-login-buttons">
                <button class="social-btn google-btn" title="Login with Google">
                    <i class="fab fa-google"></i>
                </button>
                <button class="social-btn facebook-btn" title="Login with Facebook">
                    <i class="fab fa-facebook-f"></i>
                </button>
                <button class="social-btn apple-btn" title="Login with Apple">
                    <i class="fab fa-apple"></i>
                </button>
            </div>
        </div>
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

        // Form submission with loader
        document.getElementById('loginForm').addEventListener('submit', function () {
            document.getElementById('loginBtn').disabled = true;
            document.getElementById('loginLoader').style.display = 'block';
        });

        // Service Worker Registration
        if ("serviceWorker" in navigator) {
            navigator.serviceWorker.register("/rota-app-main/service-worker.js")
                .then(registration => {
                    console.log("Service Worker registered with scope:", registration.scope);
                })
                .catch(error => {
                    console.log("Service Worker registration failed:", error);
                });
        }
    </script>
    <script src="/rota-app-main/js/pwa-debug.js"></script>
    <script src="/rota-app-main/js/links.js"></script>
</body>

</html>