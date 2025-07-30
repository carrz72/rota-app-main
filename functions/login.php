<?php
// Start output buffering early to prevent any whitespace issues
ob_start();

// Include session manager first
require_once '../includes/session_manager.php';
initializeSessionTimeout();

// Clear any previous session data on login page access
if (!isset($_POST['email'])) {
    // Only clear when not submitting the form (first page load)
    $preserved_messages = [];
    if (isset($_SESSION['timeout_message'])) {
        $preserved_messages['timeout_message'] = $_SESSION['timeout_message'];
    }
    if (isset($_SESSION['expired'])) {
        $preserved_messages['expired'] = $_SESSION['expired'];
    }

    $_SESSION = array();

    // Restore preserved messages
    foreach ($preserved_messages as $key => $value) {
        $_SESSION[$key] = $value;
    }
}

// Include DB connection with error handling
try {
    require_once '../includes/db.php';
} catch (Exception $e) {
    error_log("Database connection error: " . $e->getMessage());
    $error = "Unable to connect to database. Please try again later.";
}

// If already logged in, redirect to dashboard
if (isset($_SESSION['user_id']) && !checkSessionTimeout()) {
    header("Location: ../users/dashboard.php");
    exit;
}

$remember_email = '';
if (isset($_COOKIE['remember_email'])) {
    $remember_email = $_COOKIE['remember_email'];
}

$error = '';
$show_timeout_message = false;

// Check for timeout message
if (isset($_SESSION['timeout_message'])) {
    $error = $_SESSION['timeout_message'];
    $show_timeout_message = true;
    unset($_SESSION['timeout_message']);
}

// Check for expired session parameter
if (isset($_GET['expired']) && $_GET['expired'] == '1') {
    $error = "Your session has expired due to inactivity. Please log in again.";
    $show_timeout_message = true;
}

// Get return URL for redirect after login
$return_url = $_GET['return'] ?? '';

// Validate and sanitize return URL
function validateReturnUrl($url)
{
    if (empty($url))
        return false;

    // Parse URL
    $parsed = parse_url($url);

    // Reject if external domain
    if (isset($parsed['host']))
        return false;

    // Reject if contains suspicious patterns
    if (strpos($url, '..') !== false)
        return false;
    if (strpos($url, 'javascript:') !== false)
        return false;
    if (strpos($url, 'data:') !== false)
        return false;

    // Must start with / or be relative path
    if (!empty($url) && $url[0] !== '/' && strpos($url, '../') !== 0)
        return false;

    return true;
}

$safe_return_url = validateReturnUrl($return_url) ? $return_url : '';

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
            $_SESSION['last_activity'] = time();
            $_SESSION['timeout_duration'] = 7200; // 2 hours

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

            // Determine redirect URL based on role and return URL
            $redirect_to = '../users/dashboard.php'; // Default for regular users

            // Admin users go to admin dashboard by default
            if (in_array($user['role'], ['admin', 'super_admin'])) {
                $redirect_to = '../admin/admin_dashboard.php';
            }

            // Use return URL if provided and safe
            if (!empty($safe_return_url)) {
                $redirect_to = $safe_return_url;
            }

            // Additional validation for admin pages
            if (strpos($redirect_to, '/admin/') !== false && !in_array($user['role'], ['admin', 'super_admin'])) {
                $redirect_to = '../users/dashboard.php'; // Regular users can't access admin pages
            }

            // Make sure nothing has been output yet
            if (!headers_sent()) {
                header("Location: $redirect_to");
                exit;
            } else {
                echo "<script>window.location.href = '$redirect_to';</script>";
                echo "If you are not redirected, <a href='$redirect_to'>click here</a>";
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <title>Login - Open Rota</title>
    <style>
        @font-face {
            font-family: "newFont";
            src: url("../fonts/CooperHewitt-Book.otf");
            font-weight: normal;
            font-style: normal;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
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

        .login-container {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            padding: 40px;
            width: 100%;
            max-width: 450px;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .login-container:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
        }

        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .login-header h2 {
            color: #fd2b2b;
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .login-header p {
            color: #666;
            font-size: 1rem;
            margin: 0;
        }

        .form-group {
            margin-bottom: 20px;
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

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 20px 0;
        }

        .checkbox-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: #fd2b2b;
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

        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f1aeb5;
        }

        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }

        .text-center {
            text-align: center;
        }

        .login-link {
            color: #fd2b2b;
            text-decoration: none;
            font-weight: 600;
        }

        .login-link:hover {
            text-decoration: underline;
        }

        .forgot-password-link {
            color: #6c757d;
            text-decoration: none;
            font-size: 0.875rem;
        }

        .forgot-password-link:hover {
            color: #fd2b2b;
        }

        /* Responsive design */
        @media (max-width: 768px) {
            .login-container {
                margin: 10px;
                padding: 30px 20px;
            }

            .login-header h2 {
                font-size: 1.5rem;
            }
        }
    </style>
</head>

<body>
    </style>
    </head>

    <body>
        <div class="login-container">
            <div class="login-header">
                <h2><i class="fas fa-sign-in-alt"></i> Welcome Back</h2>
                <p>Sign in to manage your shifts and schedule</p>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert <?php echo $show_timeout_message ? 'alert-warning' : 'alert-error'; ?>">
                    <i class="fas fa-<?php echo $show_timeout_message ? 'clock' : 'exclamation-circle'; ?>"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form id="loginForm" action="login.php" method="POST">
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" class="form-control"
                        value="<?php echo htmlspecialchars($remember_email); ?>" placeholder="Enter your email" required
                        autocomplete="email">
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" class="form-control"
                        placeholder="Enter your password" required autocomplete="current-password">
                </div>

                <div class="checkbox-group">
                    <input type="checkbox" id="remember" name="remember" <?php echo $remember_email ? 'checked' : ''; ?>>
                    <label for="remember">Remember me</label>
                </div>

                <button type="submit" id="loginBtn" class="btn btn-primary">
                    <i class="fas fa-sign-in-alt"></i> Log In
                </button>
            </form>

            <div class="text-center" style="margin-top: 20px;">
                <a href="forgot_password_emailjs.php" class="forgot-password-link">Forgot Password?</a>
            </div>

            <div class="text-center" style="margin-top: 25px; color: #666;">
                Don't have an account? <a href="../register_with_otp.php" class="login-link">Sign Up</a>
            </div>
        </div>

        <script>
            // Simple form submission handler
            document.getElementById('loginForm').addEventListener('submit', function () {
                const btn = document.getElementById('loginBtn');
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Signing In...';
            });
        </script>

        <script src="../js/pwa-debug.js"></script>
    </body>

</html>
<?php
// End output buffering and send all output to browser
ob_end_flush();
?>