<?php
require_once '../includes/auth.php';
requireLogin();

$time_remaining = getSessionTimeRemaining();
$formatted_time = formatTimeRemaining($time_remaining);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <?php $PAGE_TITLE = 'Session Timeout Test - Open Rota';
    require_once __DIR__ . '/admin_head.php'; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            background: #f5f5f5;
        }

        .container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .info-box {
            background: #e8f4f8;
            border-left: 4px solid #17a2b8;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
        }

        .warning-box {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
        }

        .btn {
            background: #fd2b2b;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            margin: 5px;
            text-decoration: none;
            display: inline-block;
        }

        .btn:hover {
            background: #e61919;
        }

        .btn-secondary {
            background: #6c757d;
        }

        .btn-secondary:hover {
            background: #5a6268;
        }

        #session-info {
            background: #d4edda;
            border-left: 4px solid #28a745;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
        }
    </style>
</head>

<body>
    <div class="container">
        <h1><i class="fas fa-clock"></i> Session Timeout Test Page</h1>

        <div class="info-box">
            <h3>Current Session Status</h3>
            <p><strong>User ID:</strong> <?php echo $_SESSION['user_id']; ?></p>
            <p><strong>Username:</strong> <?php echo $_SESSION['username']; ?></p>
            <p><strong>Login Time:</strong> <?php echo date('Y-m-d H:i:s', $_SESSION['login_time']); ?></p>
            <p><strong>Last Activity:</strong>
                <?php echo isset($_SESSION['last_activity']) ? date('Y-m-d H:i:s', $_SESSION['last_activity']) : 'Not set'; ?>
            </p>
            <p><strong>Session Duration:</strong>
                <?php echo isset($_SESSION['timeout_duration']) ? ($_SESSION['timeout_duration'] / 60) . ' minutes' : 'Not set'; ?>
            </p>
        </div>

        <div id="session-info">
            <h3>Time Remaining</h3>
            <p id="time-remaining"><strong><?php echo $formatted_time; ?></strong> (<?php echo $time_remaining; ?>
                seconds)</p>
        </div>

        <div class="warning-box">
            <h3>Test Instructions</h3>
            <ul>
                <li>Leave this page idle for 10 minutes to see the timeout warning</li>
                <li>The session will automatically timeout after 2 hours of inactivity</li>
                <li>Moving your mouse or clicking will extend the session</li>
                <li>Use the buttons below to test different scenarios</li>
            </ul>
        </div>

        <div style="margin: 20px 0;">
            <button onclick="refreshSessionInfo()" class="btn">
                <i class="fas fa-sync"></i> Refresh Session Info
            </button>

            <button onclick="extendSession()" class="btn btn-secondary">
                <i class="fas fa-plus"></i> Extend Session
            </button>

            <a href="../users/dashboard.php" class="btn btn-secondary">
                <i class="fas fa-home"></i> Back to Dashboard
            </a>

            <a href="../functions/logout.php" class="btn" style="background: #dc3545;">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>

        <div class="info-box">
            <h3>Activity Log</h3>
            <div id="activity-log">
                <p>Page loaded at <?php echo date('H:i:s'); ?></p>
            </div>
        </div>
    </div>

    <script src="../js/session-timeout.js"></script>
    <script>
        let activityLog = document.getElementById('activity-log');

        function logActivity(message) {
            const timestamp = new Date().toLocaleTimeString();
            const logEntry = document.createElement('p');
            logEntry.textContent = `${timestamp}: ${message}`;
            activityLog.appendChild(logEntry);

            // Keep only last 10 entries
            while (activityLog.children.length > 10) {
                activityLog.removeChild(activityLog.firstChild);
            }
        }

        function refreshSessionInfo() {
            fetch('../functions/extend_session.php', {
                method: 'POST',
                credentials: 'same-origin'
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('time-remaining').innerHTML =
                            `<strong>${data.formatted_time}</strong> (${data.time_remaining} seconds)`;
                        logActivity('Session info refreshed');
                    }
                })
                .catch(error => {
                    logActivity('Error refreshing session info: ' + error.message);
                });
        }

        function extendSession() {
            fetch('../functions/extend_session.php', {
                method: 'POST',
                credentials: 'same-origin'
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        logActivity('Session extended successfully');
                        refreshSessionInfo();
                    }
                })
                .catch(error => {
                    logActivity('Error extending session: ' + error.message);
                });
        }

        // Auto-refresh session info every 30 seconds
        setInterval(refreshSessionInfo, 30000);

        // Log user activity
        ['click', 'mousemove', 'keypress'].forEach(event => {
            document.addEventListener(event, function () {
                if (!this.lastLogTime || Date.now() - this.lastLogTime > 10000) {
                    logActivity(`User activity detected (${event})`);
                    this.lastLogTime = Date.now();
                }
            });
        });
    </script>
</body>

</html>