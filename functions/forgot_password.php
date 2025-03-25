<?php
// filepath: c:\xampp\htdocs\rota-app\functions\forgot_password.php

require '../vendor/autoload.php';
require '../includes/auth.php';
require '../includes/db.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$error = "";
$message = "";
$email = "";

function sendResetCode($conn, $email, $numericCode) {
    // Update database with new code and reset attempts
    $stmt = $conn->prepare("UPDATE users SET reset_code = ?, attempts = 0 WHERE email = ?");
    $stmt->execute([$numericCode, $email]);
    
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'openrotamail@gmail.com';
        $mail->Password   = 'rtgd dbwl kkwn unjf';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('openrotamail@gmail.com', 'openrota..');
        $mail->addAddress($email);

        $mail->isHTML(true);
        $mail->Subject = 'Password Reset Code';
        $mail->Body    = "Your password reset code is: <strong>$numericCode</strong><br>Please enter this code to reset your password.";
        $mail->send();

        // Store the email in session for later use
        $_SESSION['reset_email'] = $email;
        return true;
    } catch (Exception $e) {
        // Return the error message from PHPMailer
        return "Message could not be sent. Mailer Error: " . $mail->ErrorInfo;
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Always sanitize the email input
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);

    // If the verification code is not being submitted, send the reset code
    if (!isset($_POST['verification_code'])) {
        // Check if the email exists in the DB
        $stmt = $conn->prepare("SELECT id, reset_code, attempts FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $userData = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($userData) {
            if ($userData['attempts'] >= 4) {
                $error = "Too many failed attempts. Please request a new code.";
            } else {
                $numericCode = random_int(100000, 999999);
                $result = sendResetCode($conn, $email, $numericCode);
                if ($result === true) {
                    $message = "Check your email for the password reset code.";
                    $_SESSION['reset_email'] = $email;
                } else {
                    $error = $result;
                }
            }
        } else {
            $error = "No user found with that email.";
        }
    } else {
        // Process verification code submission
        $enteredCode = trim($_POST['verification_code']);
        $stmt = $conn->prepare("SELECT reset_code, attempts FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $userData = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($userData && $enteredCode === $userData['reset_code']) {
            // Reset attempts and redirect to reset_password.php
            $updateStmt = $conn->prepare("UPDATE users SET attempts = 0 WHERE email = ?");
            $updateStmt->execute([$email]);
            header("Location: ../functions/reset_password.php");
            exit;
        } else {
            // Update attempts count and set error message
            if ($userData) {
                $newAttempts = $userData['attempts'] + 1;
                $failStmt = $conn->prepare("UPDATE users SET attempts = ? WHERE email = ?");
                $failStmt->execute([$newAttempts, $email]);
            }
            $error = "Invalid code. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password</title>
    <link rel="stylesheet" href="../css/forgot_password.css">
</head>
<body>
    <section class="forgot-password-container">
        <div class="card">
            <div class="card__form">
                <h1>Forgot Password</h1>
                <?php
                if ($error) {
                    echo "<p style='color: red;'>$error</p>";
                } elseif ($message) {
                    echo "<p style='color: green;'>$message</p>";
                }
                ?>
                <form method="POST">
                    <?php if (!empty($_SESSION['reset_email'])): ?>
                        <!-- If a code was already sent, show the verification form -->
                        <input type="hidden" name="email" value="<?php echo htmlentities($_SESSION['reset_email']); ?>">
                        <input type="text" name="verification_code" placeholder="Enter Code" required>
                        <button type="submit">Verify Code</button>
                    <?php else: ?>
                        <input type="email" name="email" placeholder="Enter Email..." value="<?php echo htmlentities($email); ?>" required>
                        <button id="send"  type="submit">Send Code</button>
                    <?php endif; ?>
                </form>
                <button class="back-btn" type="button" onclick="window.location.href='../functions/login.php';">Back</button>
            </div>
        </div>
    </section>
</body>
</html>