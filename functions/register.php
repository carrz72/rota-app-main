<?php
session_start();
require '../includes/db.php';

// Initialize error variable
$error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    // Check if passwords match
    if ($password !== $confirm_password) {
        $error = "Passwords do not match!";
    } else {
        // Check if email already exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);

        if ($stmt->rowCount() > 0) {
            $error = "Email already registered!";
        } else {
            // Hash password and insert the new user with email_verified set to 1
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (username, email, password, role, email_verified) VALUES (?, ?, ?, 'user', 1)");

            if ($stmt->execute([$username, $email, $hashedPassword])) {
                // Simple redirect to login page after successful registration
                header("Location: login.php");
                exit;
            } else {
                $error = "An error occurred while registering. Please try again.";
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
        /* Basic styling from original version */
        .register-container {
            background-color: rgba(255, 255, 255, 0.95);
            border-radius: 15px;
            padding: 30px;
            width: 100%;
            max-width: 400px;
            text-align: center;
            margin: 20px auto;
        }

        .error {
            color: red;
            margin-bottom: 15px;
        }

        input {
            width: 100%;
            padding: 10px;
            margin-bottom: 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }

        button {
            background-color: #fd2b2b;
            color: white;
            border: none;
            padding: 12px;
            width: 100%;
            border-radius: 5px;
            cursor: pointer;
        }

        #email-status {
            display: block;
            font-size: 14px;
            margin: -10px 0 10px;
            text-align: left;
        }

        .back-link {
            display: block;
            margin-bottom: 15px;
            text-align: left;
        }
    </style>
</head>

<body>
    <div class="register-container">
        <a href="login.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Login</a>
        <h2>Create Account</h2>

        <?php if (!empty($error)): ?>
            <p class="error"><?php echo htmlspecialchars($error); ?></p>
        <?php endif; ?>

        <form id="registerForm" action="register.php" method="POST">
            <input type="text" name="username" placeholder="Username" required
                value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">

            <input type="email" id="email" name="email" placeholder="Email" required
                value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
            <span id="email-status"></span>

            <input type="password" id="password" name="password" placeholder="Password" required>

            <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm Password"
                required>

            <button type="submit" id="registerBtn">Register</button>
        </form>

        <p>Already have an account? <a href="login.php">Login</a></p>
    </div>

    <script>
        // Email availability checker
        document.getElementById("email").addEventListener("keyup", function () {
            let email = this.value;
            let status = document.getElementById("email-status");

            if (email.length > 3) {
                let xhr = new XMLHttpRequest();
                xhr.open("POST", "check_email.php", true);
                xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
                xhr.onreadystatechange = function () {
                    if (xhr.readyState === 4 && xhr.status === 200) {
                        if (xhr.responseText === "taken") {
                            status.textContent = "Email is already in use.";
                            status.style.color = "red";
                        } else {
                            status.textContent = "Email is available.";
                            status.style.color = "green";
                        }
                    }
                };
                xhr.send("email=" + encodeURIComponent(email));
            }
        });
    </script>

    <script src="/rota-app-main/js/pwa-debug.js"></script>
    <script src="/rota-app-main/js/links.js"></script>
</body>

</html>