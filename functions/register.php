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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Open Rota</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            padding: 20px;
            margin: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }

        .register-container {
            background-color: white;
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 400px;
        }

        h2 {
            text-align: center;
            color: #333;
        }

        .error {
            color: red;
            margin-bottom: 15px;
            padding: 10px;
            background-color: rgba(255, 0, 0, 0.1);
            border-radius: 5px;
        }

        input {
            width: 90%;
            padding: 10px;
            margin-bottom: 15px;
            box-sizing: border-box;
            border: 1px solid #ddd;
            border-radius: 4px;
        }

        button {
            background-color: #fd2b2b;
            color: white;
            border: none;
            padding: 12px;
            width: 100%;
            cursor: pointer;
            border-radius: 4px;
            font-size: 16px;
        }

        .back-link {
            display: block;
            margin-bottom: 15px;
        }

        p {
            text-align: center;
            margin-top: 20px;
        }

        a {
            color: #fd2b2b;
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
        }
    </style>
</head>

<body>
    <div class="register-container">
        <a href="login.php" class="back-link">← Back to Login</a>
        <h2>Create Account</h2>

        <?php if (!empty($error)): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form action="register.php" method="POST">
            <input type="text" name="username" placeholder="Username" required
                value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">

            <input type="email" name="email" placeholder="Email" required
                value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">

            <input type="password" name="password" placeholder="Password" required>

            <input type="password" name="confirm_password" placeholder="Confirm Password" required>

            <button type="submit">Register</button>
        </form>

        <p>Already have an account? <a href="login.php">Login</a></p>
    </div>
</body>

</html>