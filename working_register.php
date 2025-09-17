<?php
// Simple working registration without EmailJS
// This will create accounts directly without email verification for now

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once 'includes/db.php';

    // The app normally exposes the PDO connection as $conn (or $GLOBALS['conn']).
    // Some standalone pages use $pdo; ensure $pdo references the available connection.
    if (!isset($pdo)) {
        if (isset($conn) && $conn instanceof PDO) {
            $pdo = $conn;
        } elseif (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof PDO) {
            $pdo = $GLOBALS['conn'];
        }
    }

    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception('Database connection not available');
    }

    header('Content-Type: application/json');

    try {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        $terms = isset($_POST['terms']);

        // Validation
        if (empty($username) || strlen($username) < 3) {
            throw new Exception('Username must be at least 3 characters long');
        }

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Please enter a valid email address');
        }

        if (empty($password) || strlen($password) < 8) {
            throw new Exception('Password must be at least 8 characters long');
        }

        if ($password !== $confirm_password) {
            throw new Exception('Passwords do not match');
        }

        if (!$terms) {
            throw new Exception('Please agree to the Terms & Conditions');
        }

        // Check if email already exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            throw new Exception('Email address is already registered');
        }

        // Check if username already exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            throw new Exception('Username is already taken');
        }

        // Create account
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (username, email, password, email_verified, created_at) VALUES (?, ?, ?, 1, NOW())");

        if ($stmt->execute([$username, $email, $hashed_password])) {
            echo json_encode([
                'success' => true,
                'message' => 'Account created successfully! You can now log in.',
                'redirect' => 'functions/login.php'
            ]);
        } else {
            throw new Exception('Failed to create account');
        }

    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Open Rota</title>
    <link rel="stylesheet" href="css/loginandregister.css">
        <link rel="stylesheet" href="css/dark_mode.css">
    <style>
        .working-form {
            max-width: 500px;
            margin: 50px auto;
            padding: 30px;
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }

        .form-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .form-header h2 {
            color: #ff0808;
            margin-bottom: 10px;
        }

        .form-header .subtitle {
            color: #666;
            font-size: 14px;
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

        .form-group input {
            width: 100%;
            padding: 15px;
            border: 2px solid #e2e8f0;
            border-radius: 15px;
            font-size: 16px;
            transition: border-color 0.3s ease;
            box-sizing: border-box;
        }

        .form-group input:focus {
            outline: none;
            border-color: #ff0808;
        }

        .checkbox-group {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin: 20px 0;
        }

        .checkbox-group input[type="checkbox"] {
            width: auto;
            margin-top: 2px;
        }

        .checkbox-group label {
            margin-bottom: 0;
            font-weight: normal;
            line-height: 1.4;
        }

        .submit-btn {
            width: 100%;
            padding: 15px;
            background: #ff0808;
            color: white;
            border: none;
            border-radius: 15px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }

        .submit-btn:hover {
            background: #e60707;
        }

        .submit-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
        }

        .alert {
            padding: 15px;
            margin: 15px 0;
            border-radius: 10px;
            font-size: 14px;
        }

        .alert.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .signup-link {
            text-align: center;
            margin-top: 20px;
        }

        .signup-link a {
            color: #ff0808;
            text-decoration: none;
        }

        .signup-link a:hover {
            text-decoration: underline;
        }

        .notice {
            background: #fff3cd;
            color: #856404;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            border: 1px solid #ffeaa7;
        }
    </style>
</head>

<body style="background: url('images/backg3.jpg') no-repeat center center fixed; background-size: cover;">
    <div class="register-container">
        <!-- Logo Header -->
        <div class="logo-header">
            <div class="logo">Open Rota</div>
        </div>
    <script>try{ if(localStorage.getItem('rota_theme')==='dark') document.documentElement.setAttribute('data-theme','dark'); }catch(e){}</script>

        <div class="working-form">
            <div class="form-header">
                <h2>🚀 Create Account</h2>
                <div class="subtitle">Join Open Rota and manage your shifts easily</div>
            </div>

            <div class="notice">
                <strong>📝 Note:</strong> This is a simplified registration that creates your account immediately.
                Email verification is temporarily disabled while we fix the email service.
            </div>

            <div id="alertContainer"></div>

            <form id="registrationForm">
                <div class="form-group">
                    <label for="username">Username *</label>
                    <input type="text" id="username" name="username" required placeholder="Enter your username"
                        pattern="[A-Za-z0-9\s]{3,50}"
                        title="Username should be 3-50 characters, letters, numbers, and spaces only">
                </div>

                <div class="form-group">
                    <label for="email">Email Address *</label>
                    <input type="email" id="email" name="email" required placeholder="Enter your email address">
                </div>

                <div class="form-group">
                    <label for="password">Password *</label>
                    <input type="password" id="password" name="password" required placeholder="Create a strong password"
                        minlength="8">
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm Password *</label>
                    <input type="password" id="confirm_password" name="confirm_password" required
                        placeholder="Confirm your password">
                </div>

                <div class="checkbox-group">
                    <input type="checkbox" id="terms" name="terms" required>
                    <label for="terms">
                        I agree to the <a href="#" style="color: #ff0808;">Terms & Conditions</a>
                        and <a href="#" style="color: #ff0808;">Privacy Policy</a>
                    </label>
                </div>

                <button type="submit" id="submitBtn" class="submit-btn">
                    <span id="submitText">Create Account</span>
                    <span id="submitLoader" style="display: none;">Creating...</span>
                </button>
            </form>

            <div class="signup-link">
                Already have an account? <a href="functions/login.php">Sign In</a>
            </div>
        </div>
    </div>

    <script>
        function showAlert(message, type = 'error') {
            const alertContainer = document.getElementById('alertContainer');
            alertContainer.innerHTML = `
                <div class="alert ${type}">
                    ${message}
                </div>
            `;

            // Auto-hide success alerts after 3 seconds
            if (type === 'success') {
                setTimeout(() => {
                    alertContainer.innerHTML = '';
                }, 3000);
            }
        }

        function setLoading(loading = true) {
            const submitBtn = document.getElementById('submitBtn');
            const submitText = document.getElementById('submitText');
            const submitLoader = document.getElementById('submitLoader');

            submitBtn.disabled = loading;
            submitText.style.display = loading ? 'none' : 'inline';
            submitLoader.style.display = loading ? 'inline' : 'none';
        }

        // Password confirmation validation
        document.getElementById('confirm_password').addEventListener('input', function () {
            const password = document.getElementById('password').value;
            const confirmPassword = this.value;

            if (confirmPassword && password !== confirmPassword) {
                this.setCustomValidity('Passwords do not match');
            } else {
                this.setCustomValidity('');
            }
        });

        // Form submission
        document.getElementById('registrationForm').addEventListener('submit', async function (event) {
            event.preventDefault();

            const formData = new FormData(this);

            // Client-side validation
            const username = formData.get('username');
            const email = formData.get('email');
            const password = formData.get('password');
            const confirmPassword = formData.get('confirm_password');
            const terms = formData.get('terms');

            if (!username || username.trim().length < 3) {
                showAlert('Username must be at least 3 characters long');
                return;
            }

            if (!email || !email.includes('@')) {
                showAlert('Please enter a valid email address');
                return;
            }

            if (!password || password.length < 8) {
                showAlert('Password must be at least 8 characters long');
                return;
            }

            if (password !== confirmPassword) {
                showAlert('Passwords do not match');
                return;
            }

            if (!terms) {
                showAlert('Please agree to the Terms & Conditions');
                return;
            }

            setLoading(true);

            try {
                const response = await fetch('working_register.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    showAlert(data.message, 'success');

                    // Redirect after a delay
                    setTimeout(() => {
                        window.location.href = data.redirect;
                    }, 2000);
                } else {
                    showAlert(data.message, 'error');
                    setLoading(false);
                }

            } catch (error) {
                showAlert('Registration failed. Please try again.', 'error');
                setLoading(false);
                console.error('Registration error:', error);
            }
        });
    </script>
</body>

</html>