<?php
ob_start();
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../functions/login.php');
    exit;
}

require_once '../includes/db.php';
require_once '../includes/notifications.php';

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

// Retrieve current user details (used for both settings & verification)
$stmt = $conn->prepare("SELECT username, email, email_verified FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    die("User not found.");
}

// Process email verification code submission (verification form)
if (isset($_POST['verify_email_submit'])) {
    $inputCode = trim($_POST['verification_code'] ?? '');

    $stmtCode = $conn->prepare("SELECT verification_code FROM email_verification WHERE email = ?");
    $stmtCode->execute([$user['email']]);
    if ($row = $stmtCode->fetch(PDO::FETCH_ASSOC)) {
        if ($row['verification_code'] == $inputCode) {
            $updateStmt = $conn->prepare("UPDATE users SET email_verified = 1 WHERE id = ?");
            if ($updateStmt->execute([$user_id])) {
                $success = "Email verified successfully.";
                // Remove verification code record
                $deleteStmt = $conn->prepare("DELETE FROM email_verification WHERE email = ?");
                $deleteStmt->execute([$user['email']]);
            } else {
                $error = "Failed to verify email.";
            }
        } else {
            $error = "Incorrect verification code.";
        }
    } else {
        $error = "No verification code found. Please resend the verification email.";
    }

    // If there is a message from verification, add a notification.
    if (!empty($error)) {
        addNotification($conn, $user_id, $error, 'error');
    } elseif (!empty($success)) {
        addNotification($conn, $user_id, $success, 'success');
    }

    header("Location: settings.php");
    exit;
}

// Process the form submission to update settings
if (isset($_POST['update_settings'])) {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);

    // Check if the new email is already in use by another account
    $stmtEmail = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $stmtEmail->execute([$email, $user_id]);

    if ($stmtEmail->rowCount() > 0) {
        $error = "Email address is already in use.";
        addNotification($conn, $user_id, $error, 'error');
    } else {
        $stmtUpdate = $conn->prepare("UPDATE users SET username = ?, email = ? WHERE id = ?");
        if ($stmtUpdate->execute([$username, $email, $user_id])) {
            $success = "Settings updated successfully.";
            $_SESSION['username'] = $username;
            $user['username'] = $username;
            $user['email'] = $email;
            addNotification($conn, $user_id, $success, 'success');
        } else {
            $error = "Failed to update settings.";
            addNotification($conn, $user_id, $error, 'error');
        }
    }

    // Redirect to prevent resubmission and duplicate notifications.
    header("Location: settings.php");
    exit;
}

// Handle resend verification code request
if (isset($_GET['resend_verification'])) {
    // Generate a new 6-digit verification code
    $verificationCode = random_int(100000, 999999);

    // Remove any existing verification code for the email
    $deleteStmt = $conn->prepare("DELETE FROM email_verification WHERE email = ?");
    $deleteStmt->execute([$user['email']]);

    // Insert the new verification code into the email_verification table
    $insertStmt = $conn->prepare("INSERT INTO email_verification (email, verification_code) VALUES (?, ?)");
    $insertStmt->execute([$user['email'], $verificationCode]);

    // Send the verification email
    require_once '../vendor/autoload.php';
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'openrotamail@gmail.com';
        $mail->Password = 'rtgd dbwl kkwn unjf';
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->setFrom('openrotamail@gmail.com', 'openrota..');
        $mail->addAddress($user['email']);

        $mail->isHTML(true);
        $mail->Subject = 'Your Email Verification Code';
        $mail->Body = "Your verification code is: <strong>$verificationCode</strong><br>Please enter this code below to verify your email.";
        $mail->send();

        $success = "Verification email sent. Please check your inbox and enter the code below.";
        // Set a flag to display the verification form
        $_SESSION['show_verification_form'] = true;
    } catch (Exception $e) {
        $error = "Verification email could not be sent. Mailer Error: " . $mail->ErrorInfo;
    }

    header("Location: settings.php");
    exit;
}

// Retrieve notification count for header
$notifications = [];
$notificationCount = 0;
if ($user_id) {
    $notifications = getNotifications($user_id);
    $notificationCount = count($notifications);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Open Rota">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no">
    <link rel="icon" type="image/png" href="/rota-app-main/images/icon.png">
    <link rel="manifest" href="/rota-app-main/manifest.json">
    <link rel="apple-touch-icon" href="/rota-app-main/images/icon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
    <title>Settings - Open Rota</title>
    <link rel="stylesheet" href="../css/settings.css">
</head>

<body>
    <?php include '../includes/header.php'; ?>

    <h1>Settings</h1>

    <div class="container">
        <div class="form-container">
            <?php if (!empty($success)): ?>
                <div class="success-message">
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="error-message">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <h2>Account Settings</h2>
            <form action="settings.php" method="POST">
                <div class="form-group">
                    <label for="username">Username:</label>
                    <input type="text" name="username" id="username" required
                        value="<?php echo htmlspecialchars($user['username']); ?>">
                </div>

                <div class="form-group">
                    <label for="email">Email:</label>
                    <input type="email" name="email" id="email" required
                        value="<?php echo htmlspecialchars($user['email']); ?>">
                </div>

                <button type="submit" name="update_settings" class="primary-btn">Update Settings</button>
            </form>

            <?php
            // Display verification notice and form if email is not verified, or if session flag is set.
            if (empty($user['email_verified']) || $user['email_verified'] == 0 || isset($_SESSION['show_verification_form'])) {
                echo '<div class="verification-section">';
                echo '<h2>Email Verification</h2>';
                echo '<p class="verification-notice">Your email is not verified. 
                     <a href="settings.php?resend_verification=1" class="secondary-btn">Resend Verification</a></p>';

                if (isset($_SESSION['show_verification_form'])) {
                    echo '<form action="settings.php" method="POST" class="verification-form">';
                    echo '<div class="form-group">';
                    echo '<label for="verification_code">Verification Code:</label>';
                    echo '<input type="text" name="verification_code" id="verification_code" required placeholder="Enter 6-digit code">';
                    echo '</div>';
                    echo '<button type="submit" name="verify_email_submit" class="primary-btn">Verify Email</button>';
                    echo '</form>';
                    unset($_SESSION['show_verification_form']);
                }
                echo '</div>';
            }
            ?>

            <div class="settings-actions">
                <a href="profile.php" class="btn">Edit Profile</a>
                <a href="change_password.php" class="btn">Change Password</a>
                <a href="dashboard.php" class="btn">Back to Dashboard</a>
            </div>
        </div>
    </div>

    <script src="/rota-app-main/js/menu.js"></script>
    <script src="/rota-app-main/js/pwa-debug.js"></script>
    <script src="/rota-app-main/js/links.js"></script>
</body>

</html>