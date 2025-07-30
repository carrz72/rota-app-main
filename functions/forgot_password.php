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

function sendResetCode($conn, $email, $numericCode)
{
    // Update database with new code and reset attempts
    $stmt = $conn->prepare("UPDATE users SET reset_code = ?, attempts = 0 WHERE email = ?");
    $stmt->execute([$numericCode, $email]);

    $mail = new PHPMailer(true);
    $mail->SMTPDebug = 2;
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'openrotamail@gmail.com';
        $mail->Password = 'rtgd dbwl kkwn unjf';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->setFrom('openrotamail@gmail.com', 'openrota..');
        $mail->addAddress($email);

        $mail->isHTML(true);
        $mail->Subject = 'Password Reset Code';
        $mail->Body = "Your password reset code is: <strong>$numericCode</strong><br>Please enter this code to reset your password.";
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
    <title>Forgot Password - Rota App</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        @font-face {
            font-family: "newFont";
            src: url("../fonts/CooperHewitt-Book.otf");
            font-weight: normal;
            font-style: normal;
        }

        body {
            font-family: "newFont", Arial, sans-serif;
            background: url("../images/backg3.jpg") no-repeat center center fixed;
            background-size: cover;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
            color: #333;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .forgot-container {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            padding: 40px;
            width: 100%;
            max-width: 500px;
            text-align: center;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .forgot-container:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
        }

        .forgot-header {
            margin-bottom: 30px;
        }

        .forgot-header h1 {
            color: #fd2b2b;
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .forgot-header p {
            color: #666;
            font-size: 1rem;
            margin: 0;
        }

        .form-group {
            margin-bottom: 20px;
            text-align: left;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 600;
        }

        .form-control {
            width: 100%;
            padding: 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 1rem;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
            box-sizing: border-box;
        }

        .form-control:focus {
            outline: none;
            border-color: #fd2b2b;
            box-shadow: 0 0 0 3px rgba(253, 43, 43, 0.1);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 15px 25px;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s ease;
            width: 100%;
        }

        .btn-primary {
            background: #fd2b2b;
            color: white;
        }

        .btn-primary:hover {
            background: #c82333;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(253, 43, 43, 0.3);
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
            margin-top: 15px;
        }

        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-2px);
        }

        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f1aeb5;
        }

        .text-center {
            text-align: center;
        }

        .login-link {
            color: #fd2b2b;
            text-decoration: none;
            font-weight: 600;
        }

        .login-link:hover {
            text-decoration: underline;
        }

        /* Responsive design */
        @media (max-width: 768px) {
            .forgot-container {
                margin: 10px;
                padding: 30px 20px;
            }

            .forgot-header h1 {
                font-size: 1.5rem;
            }
        }
    </style>
</head>

<body>
    <div class="auth-container">
        <div class="forgot-header">
            <h1><i class="fas fa-unlock-alt"></i> Forgot Password</h1>
            <?php if (!empty($_SESSION['reset_email'])): ?>
                <p>Enter the verification code sent to your email</p>
            <?php else: ?>
                <p>Enter your email address to receive a reset code</p>
            <?php endif; ?>
        </div>

        <?php
        if ($error) {
            echo "<div class='alert alert-error'><i class='fas fa-exclamation-circle'></i> $error</div>";
        } elseif ($message) {
            echo "<div class='alert alert-success'><i class='fas fa-check-circle'></i> $message</div>";
        }
        ?>

        <form method="POST" class="card__form">
            <?php if (!empty($_SESSION['reset_email'])): ?>
                <!-- If a code was already sent, show the verification form -->
                <input type="hidden" name="email" value="<?php echo htmlentities($_SESSION['reset_email']); ?>">
                <div class="form-group">
                    <label for="verification_code">Verification Code</label>
                    <input type="text" id="verification_code" name="verification_code" class="form-control"
                        placeholder="Enter the 6-digit code" required maxlength="6">
                </div>
                <button type="submit" class="btn-primary">
                    <i class="fas fa-check"></i> Verify Code
                </button>
            <?php else: ?>
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" class="form-control" placeholder="Enter your email address"
                        value="<?php echo htmlentities($email); ?>" required>
                </div>
                <button type="submit" class="btn-primary">
                    <i class="fas fa-paper-plane"></i> Send Reset Code
                </button>
            <?php endif; ?>
        </form>

        <button type="button" class="btn-secondary" onclick="window.location.href='login.php';">
            <i class="fas fa-arrow-left"></i> Back to Login
        </button>
    </div>
</body>

</html>