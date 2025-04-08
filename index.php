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
    <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="default">
  <meta name="apple-mobile-web-app-title" content="Open Rota">
  <link rel="icon" type="image/png" href="/rota-app-main/images/icon.jpg">
  <link rel="manifest" href="/rota-app-main/manifest.json">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
  <link rel="apple-touch-icon" href="/rota-app-main/images/logo.png">
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
<script>
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
</html>