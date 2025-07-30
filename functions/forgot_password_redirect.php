<?php
// filepath: c:\xampp\htdocs\rota-app-main\functions\forgot_password_redirect.php

// Configuration: Set which system to use
$use_emailjs = true; // Set to false to use the original PHPMailer system

if ($use_emailjs) {
    // Redirect to EmailJS OTP system
    header("Location: forgot_password_emailjs.php");
} else {
    // Redirect to original PHPMailer system
    header("Location: forgot_password.php");
}
exit();
?>