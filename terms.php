<?php
// Minimal Terms & Conditions page
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Terms & Conditions - Open Rota</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
          body { font-family: Arial, Helvetica, sans-serif; background: url("images/backg3.jpg") no-repeat center center fixed; background-size: cover; margin:0; padding:20px; }
        .terms-container { max-width:920px; margin:40px auto; padding:28px; background:#fff; border-radius:10px; box-shadow:0 8px 30px rgba(0,0,0,0.06); }
        .terms-container h1 { color:#222; margin-bottom:8px; }
        .terms-container h2 { color:#333; margin-top:18px; }
        .terms-container p, .terms-container li { color:#444; line-height:1.5; }
        .terms-actions { margin-top:22px; display:flex; gap:12px; align-items:center; }
        .btn-ghost { display:inline-block; padding:10px 14px; border-radius:8px; background:transparent; color:#fd2b2b; border:1px solid #fd2b2b; text-decoration:none; font-weight:600; }
    </style>
</head>
<body>
    <?php if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); } ?>
    <div class="terms-container">
        <h1>Terms & Conditions</h1>
        <p>These terms govern your access to and use of the Open Rota application. By registering and using the service you agree to these terms.</p>
        <h2>1. Use of the Service</h2>
        <p>The service is provided to authorised employees for the purpose of scheduling, shift management and related administrative tasks. You must only access data and functionality that you are authorised to use.</p>
        <h2>2. Account Responsibility</h2>
        <p>You are responsible for maintaining the confidentiality of your account credentials and for all activity that occurs under your account.</p>
        <h2>3. Data</h2>
        <p>Personal data processed by this service is handled according to the Privacy Policy. Please review the <a href="privacy.php" class="login-link">Privacy Policy</a> for details.</p>
        <h2>4. Acceptance</h2>
        <p>By creating an account you confirm that you have read, understood and accept these Terms & Conditions and the Privacy Policy.</p>

        <div class="terms-actions">
            <a href="functions/login.php" class="btn-ghost">Back to Login</a>
        </div>
    </div>
</body>
</html>
