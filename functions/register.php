<?php
session_start();
require '../includes/db.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    // Check if passwords match.
    if ($password !== $confirm_password) {
        $error = "Passwords do not match!";
    } else {
        // Check if the email already exists.
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);

        if ($stmt->rowCount() > 0) {
            $error = "Email already registered!";
        } else {
            // Hash the password and insert the new user with email_verified set to 1.
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (username, email, password, role, email_verified) VALUES (?, ?, ?, 'user', 1)");
            if ($stmt->execute([$username, $email, $hashedPassword])) {
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
<link rel="icon" type="image/png" href="/rota-app-main/images/icon.jpg">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Rota App</title>
    <link rel="stylesheet" href="../css/loginandregister.css">
</head>
<body>
    <div class="register-container">
        <h2>Register</h2>
        <?php if (isset($error)) echo "<p class='error'>$error</p>"; ?>
        <form action="register.php" method="POST">
            <input type="text" name="username" placeholder="Username" required>
            <input type="email" id="email" name="email" placeholder="Email" required>
            <span id="email-status"></span>
            <input type="password" name="password" placeholder="Password" required>
            <input type="password" name="confirm_password" placeholder="Confirm Password" required>
            <button type="submit">Register</button>
        </form>
        <p>Already have an account? <a href="login.php">Login</a></p>
    </div>
    <script>
        document.getElementById("email").addEventListener("keyup", function() {
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
</body>
</html>