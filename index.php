<?php
session_start();

// Redirect if user is already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: users/dashboard.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rota App - Manage Your Work Schedule</title>
    <link rel="stylesheet" href="css/styles.css">
</head>
<body>
   

    <main class="landing-container">
        <section class="hero-section">
            <h1>Welcome to Open Rota</h1>
            <p>The easiest way to manage your work schedule and shifts</p>
            
            <div class="cta-buttons">
                <a href="functions/login.php" class="btn">Log In</a>
                <a href="functions/register.php" class="btn btn-secondary">Sign Up</a>
            </div>
        </section>
        
        <div class="features">
            <div class="feature-card">
                <h3>Shift Management</h3>
                <p>Easily view and manage your upcoming shifts. Get notified about schedule changes instantly.</p>
            </div>
            
            <div class="feature-card">
                <h3>Role Settings</h3>
                <p>Configure your work roles with custom pay rates, night shift settings, and more.</p>
            </div>
            
            <div class="feature-card">
                <h3>Earnings Tracker</h3>
                <p>Keep track of your earnings with our intuitive dashboard that calculates your income based on shifts.</p>
            </div>
        </div>
    </main>

   


</body>
</html>