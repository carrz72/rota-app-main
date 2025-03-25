<?php
session_start();
require_once '../vendor/autoload.php';
require_once '../includes/db.php';
require_once '../includes/notifications.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Ensure that either pending_registration or verifying_from_settings is set.
if (!isset($_SESSION['pending_registration']) && !isset($_SESSION['verifying_from_settings'])) {
    die("No pending email verification data.");
}

// Determine the email to verify.
if (isset($_SESSION['pending_registration']) && isset($_SESSION['verification_email'])) {
    $pending_registration = $_SESSION['pending_registration'];
    $user_email = $_SESSION['verification_email'];
} elseif (isset($_SESSION['verifying_from_settings'])) {
    // For email verification from settings, you should have set a session variable with the user’s email.
    // For example, in settings.php you might set: $_SESSION['user_email'] = $user['email'];
    if (!isset($_SESSION['user_email'])) {
        die("User email not found.");
    }
    $user_email = $_SESSION['user_email'];
} else {
    die("Email not provided.");
}

$error = '';
$message = '';

// Process form submission.
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['verification_code'])) {
    $enteredCode = trim($_POST['verification_code']);
    
    // Retrieve the code stored in the database.
    $stmt = $conn->prepare("SELECT verification_code FROM email_verification WHERE email = ?");
    $stmt->execute([$user_email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($row && $enteredCode === $row['verification_code']) {
        if (isset($_SESSION['verifying_from_settings']) && $_SESSION['verifying_from_settings'] === true) {
            // Verification from settings: Update the existing user.
            $stmt = $conn->prepare("UPDATE users SET email_verified = 1 WHERE email = ?");
            $stmt->execute([$user_email]);
            
            // Remove the verification record.
            $deleteStmt = $conn->prepare("DELETE FROM email_verification WHERE email = ?");
            $deleteStmt->execute([$user_email]);
            
            // Clear the flag.
            unset($_SESSION['verifying_from_settings']);
            header("Location: ../users/settings.php");
            exit;
        } else {
            // New registration: Insert the new user with email_verified = 1.
            $stmt = $conn->prepare("INSERT INTO users (username, email, password, role, email_verified) VALUES (?, ?, ?, 'user', 1)");
            $stmt->execute([
                $pending_registration['username'],
                $pending_registration['email'],
                $pending_registration['password']
            ]);
            
            // Remove the verification record.
            $deleteStmt = $conn->prepare("DELETE FROM email_verification WHERE email = ?");
            $deleteStmt->execute([$user_email]);
            
            // Clear registration session variables.
            unset($_SESSION['pending_registration'], $_SESSION['verification_email']);
            header("Location: ../functions/login.php");
            exit;
        }
    } else {
        $error = "Invalid verification code. Please try again.";
    }
} elseif ($_SERVER["REQUEST_METHOD"] === "POST") {
    // In case POST is made without verification_code.
    $error = "Invalid verification code. Please try again.";
} else {
    // GET request (or no POST code submitted): Generate and send a new verification code.
    
    // Remove any existing verification code for this email.
    $deleteStmt = $conn->prepare("DELETE FROM email_verification WHERE email = ?");
    $deleteStmt->execute([$user_email]);
    
    // Generate a new 6-digit code.
    $verificationCode = strval(random_int(100000, 999999));
    
    // Insert the new code into the database.
    $stmt = $conn->prepare("INSERT INTO email_verification (email, verification_code) VALUES (?, ?)");
    $stmt->execute([$user_email, $verificationCode]);
    
    // Setup and send the verification email.
    $mail = new PHPMailer(true);
    try {
        // Server settings.
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'openrotamail@gmail.com';
        $mail->Password   = 'rtgd dbwl kkwn unjf';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
    
        // Recipients.
        $mail->setFrom('openrotamail@gmail.com', 'openrota..');
        $mail->addAddress($user_email);
    
        // Email content.
        $mail->isHTML(true);
        $mail->Subject = 'Verify Your Email Address';
        $mail->Body    = "Hello,<br><br>Your email verification code is: <strong>{$verificationCode}</strong>.<br>Please enter this code on the verification page.<br><br>Thank you!";
        $mail->send();
        $message = "A verification code has been sent to your email.";
    } catch (Exception $e) {
        $error = "Failed to send verification email. Mailer Error: " . $mail->ErrorInfo;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Email</title>
    <link rel="stylesheet" href="../css/verify_email.css">
</head>
<body>
    <h1>Email Verification</h1>
    <?php
        if (!empty($error)) {
            echo "<p style='color:red;'>{$error}</p>";
        } elseif (!empty($message)) {
            echo "<p style='color:green;'>{$message}</p>";
        }
    ?>
    <form method="POST">
        <input type="text" name="verification_code" placeholder="Enter Verification Code" required>
        <button type="submit">Verify Email</button>
    </form>
</body>
</html>