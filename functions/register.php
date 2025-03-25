<?php
session_start();
require '../includes/db.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    // Check if passwords match
    if ($password !== $confirm_password) {
        $error = "Passwords do not match!";
    } else {
        // Check if email already exists in users table
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);

        if ($stmt->rowCount() > 0) {
            $error = "Email already registered!";
        } else {
            // Save the registration details in session for verification
            $_SESSION['pending_registration'] = [
                'username' => $username,
                'email' => $email,
                'password' => password_hash($password, PASSWORD_DEFAULT)
            ];
            // Set this email as the one to be verified
            $_SESSION['verification_email'] = $email;
            
            // Redirect to email verification page where the code will be sent
            header("Location: verify_email.php");
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
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
</body>
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
</html>