<?php
/**
 * Test Push Notification
 * Send a test notification to yourself
 */

session_start();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/functions/send_shift_notification.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    die('Please log in first to test push notifications at <a href="functions/login.php">Login</a>');
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'User';

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Push Notification</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            max-width: 600px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }

        .container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        h1 {
            color: #333;
        }

        .info {
            background: #e3f2fd;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
            border-left: 4px solid #2196F3;
        }

        .success {
            background: #e8f5e9;
            border-left-color: #4CAF50;
            color: #2e7d32;
        }

        .error {
            background: #ffebee;
            border-left-color: #f44336;
            color: #c62828;
        }

        .warning {
            background: #fff3e0;
            border-left-color: #ff9800;
            color: #e65100;
        }

        a {
            color: #2196F3;
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
        }
    </style>
</head>

<body>
    <div class="container">
        <h1>🔔 Push Notification Test</h1>
        <p>Logged in as: <strong><?php echo htmlspecialchars($username); ?></strong> (ID: <?php echo $user_id; ?>)</p>

        <div class="info">
            <strong>📱 Instructions for iPhone:</strong><br>
            1. Make sure the PWA is installed on your home screen<br>
            2. Grant notification permissions when prompted<br>
            3. Keep this page open to see the result
        </div>

        <?php
        // Check if subscriptions exist
        try {
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM push_subscriptions WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            echo "<div class='info'>";
            echo "<strong>Subscriptions:</strong> " . $result['count'];
            echo "</div>";

            if ($result['count'] == 0) {
                echo "<div class='warning'>";
                echo "<strong>⚠️ No push subscriptions found!</strong><br>";
                echo "Please enable notifications:<br>";
                echo "1. Go to <a href='users/dashboard.php'>Dashboard</a><br>";
                echo "2. Grant notification permission when prompted<br>";
                echo "3. Come back here to test";
                echo "</div>";
            } else {
                // Check user preferences
                $prefStmt = $conn->prepare("SELECT push_notifications_enabled FROM users WHERE id = ?");
                $prefStmt->execute([$user_id]);
                $prefs = $prefStmt->fetch(PDO::FETCH_ASSOC);

                if ($prefs && $prefs['push_notifications_enabled']) {
                    echo "<div class='success'>";
                    echo "<strong>✅ Push notifications are ENABLED</strong>";
                    echo "</div>";

                    // Send test notification
                    echo "<h2>Sending Test Notification...</h2>";

                    $success = sendPushNotification(
                        $user_id,
                        "🎉 Test Notification",
                        "This is a test push notification from Open Rota! If you see this, everything is working perfectly!",
                        ['url' => '/users/dashboard.php', 'test' => true]
                    );

                    if ($success) {
                        echo "<div class='success'>";
                        echo "<strong>✅ Notification sent successfully!</strong><br>";
                        echo "Check your iPhone - you should see a notification appear!<br>";
                        echo "<small>If using the PWA, you'll see it even if the app is in the background.</small>";
                        echo "</div>";
                    } else {
                        echo "<div class='error'>";
                        echo "<strong>❌ Failed to send notification</strong><br>";
                        echo "Check the Apache error log for details.";
                        echo "</div>";
                    }
                } else {
                    echo "<div class='warning'>";
                    echo "<strong>⚠️ Push notifications are DISABLED in your settings</strong><br>";
                    echo "Go to <a href='users/settings.php'>Settings</a> and enable them.";
                    echo "</div>";
                }
            }
        } catch (Exception $e) {
            echo "<div class='error'>";
            echo "<strong>Error:</strong> " . htmlspecialchars($e->getMessage());
            echo "</div>";
        }
        ?>

        <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd;">
            <p><a href="users/dashboard.php">← Back to Dashboard</a></p>
            <p><a href="users/settings.php">⚙️ Notification Settings</a></p>
            <p><a href="test_push_notification.php">🔄 Test Again</a></p>
        </div>
    </div>
</body>

</html>