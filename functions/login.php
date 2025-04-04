<?php
if (isset($_SESSION['user_id'])) {
    header("Location: ../users/dashboard.php");
    exit; 
}
require '../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = $_POST['email'];
    $password = $_POST['password'];
    
    $stmt = $conn->prepare("SELECT id, username, password, role FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];  // ✅ Store username in session
        $_SESSION['role'] = $user['role'];
        header("Location: ../users/dashboard.php");
        exit;
    } else {
        $error = "Invalid login credentials.";
    }
}
?>



<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="../images/logo.png">
    <link rel="apple-touch-icon" href="../images/logo.png">
    <link rel="manifest" href="/rota-app-main/manifest.json">
    <title>Login - Rota App</title>
    <link rel="stylesheet" href="../css/loginandregister.css">
    
</head>
<body>
    <div class="login-container">
        <h2>Login</h2>
        <?php if (isset($error)) echo "<p class='error'>$error</p>"; ?>
        <form action="login.php" method="POST">
            <input type="email" name="email" placeholder="Email" required>
            <input type="password" name="password" placeholder="Password" required>
            <div class="btn">
            <button class="button1" type="submit">Login</button>
            <a class="button2" href="../functions/register.php">Sign up</a>
            </div>
        <a class="button3" href="../functions/forgot_password.php">Reset Password</a>
        </form>
        
    </div>
</body>
</html>
