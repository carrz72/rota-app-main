<?php
require '../includes/auth.php';
requireLogin();
require '../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit_log.php';

$error = '';
$success = '';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf_token)) {
        $error = 'Invalid request (CSRF token).';
    }
    $user_id = $_SESSION['user_id'];
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Validate inputs
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $error = "All fields are required.";
    } elseif (strlen($new_password) < 8) {
        $error = "New password must be at least 8 characters long.";
    } elseif ($new_password !== $confirm_password) {
        $error = "New passwords do not match.";
    } else {
        // Verify current password
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();

        if (!password_verify($current_password, $user['password'])) {
            $error = "Current password is incorrect.";
        } else {
            // Update password
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hashed_password, $user_id]);

            // Rotate session id to prevent session fixation attacks
            if (session_status() !== PHP_SESSION_ACTIVE) {
                @session_start();
            }
            // regenerate id and delete old session (when handler supports it)
            session_regenerate_id(true);

            // Optionally log out other devices if user requested
            if (!empty($_POST['logout_other_devices'])) {
                try {
                    if (isset($conn) && $conn instanceof PDO) {
                        $currentSid = session_id();
                        $stmt = $conn->prepare("DELETE FROM user_sessions WHERE user_id = ? AND session_id != ?");
                        $stmt->execute([$user_id, $currentSid]);
                    }
                } catch (Exception $e) {
                    error_log("Failed to delete other user sessions: " . $e->getMessage());
                }
            }

            // Update the user_sessions row with the new/regenerated session id
            try {
                if (isset($conn) && $conn instanceof PDO) {
                    $currentSid = session_id();
                    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
                    $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
                    $stmt = $conn->prepare("REPLACE INTO user_sessions (session_id, user_id, ip_address, user_agent) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$currentSid, $user_id, $ip, $ua]);
                }
            } catch (Exception $e) {
                error_log("user_sessions upsert error after password change: " . $e->getMessage());
            }

            $success = "Password changed successfully!";
            // Audit
            try {
                log_audit($conn, $user_id, 'change_password', ['logout_other_devices' => !empty($_POST['logout_other_devices'])], $user_id, 'user_security', session_id());
            } catch (Exception $e) {
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password - Open Rota</title>
    <link rel="stylesheet" href="../css/loginandregister.css">
    <link rel="stylesheet" href="../css/change_password.css">
    <link rel="stylesheet" href="../css/dark_mode.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Unified style: Use only the main app font and background from loginandregister.css -->
</head>

<body class="change-password-page">
    <script>try { if (localStorage.getItem('rota_theme') === 'dark') document.documentElement.setAttribute('data-theme', 'dark'); } catch (e) { }
    </script>
    <div class="auth-container">
        <!-- Logo Header -->
        <div class="logo-header">
            <div class="logo"><img src="../images/new logo.png" alt="Open Rota" style="height: 60px;"></div>
        </div>

        <div class="forgot-header">
            <h1><i class="fas fa-key"></i> Change Password</h1>
            <p>Update your account password</p>
        </div>

        <?php if ($error): ?>
            <div class="alert-error">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlentities($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert-success">
                <i class="fas fa-check-circle"></i> <?php echo htmlentities($success); ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="card__form">
            <?php echo csrf_input_field(); ?>
            <div class="form-group">
                <label for="current_password">Current Password</label>
                <input type="password" id="current_password" name="current_password" class="form-control"
                    placeholder="Enter your current password" required>
            </div>

            <div class="form-group">
                <label for="new_password">New Password</label>
                <input type="password" id="new_password" name="new_password" class="form-control"
                    placeholder="Enter your new password" required minlength="8">
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirm New Password</label>
                <input type="password" id="confirm_password" name="confirm_password" class="form-control"
                    placeholder="Confirm your new password" required minlength="8">
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> Change Password
            </button>
            <div class="form-group-checkbox" style="margin-top:12px;">
                <label style="font-weight:600;">
                    <input type="checkbox" name="logout_other_devices" value="1"> Log out of other devices
                </label>
            </div>
        </form>

        <div class="text-center mt-20">
            <a href="../users/dashboard.php" class="auth-link">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>
    </div>

    <script>
        // Password confirmation validation
        document.getElementById('confirm_password').addEventListener('input', function () {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = this.value;

            if (newPassword !== confirmPassword) {
                this.setCustomValidity('Passwords do not match');
            } else {
                this.setCustomValidity('');
            }
        });

        // Show success message and redirect after delay
        <?php if ($success): ?>
            setTimeout(function () {
                window.location.href = '../users/dashboard.php';
            }, 2000);
        <?php endif; ?>
    </script>
    <?php include __DIR__ . '/../includes/privacy_footer.php'; ?>
    <script src="../js/darkmode.js"></script>
</body>

</html>