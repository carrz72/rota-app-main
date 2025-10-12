<?php
// Assuming $conn is available here (or include db.php if needed)
require_once '../includes/db.php';
require_once '../includes/notifications.php';
require_once '../includes/session_config.php';

// Retrieve the notification count from the database
$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;
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
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Open Rota">
    <link rel="icon" type="image/png" href="../images/icon.jpg">
    <link rel="manifest" href="../manifest.json">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
    <link rel="stylesheet" href="../css/navigation.css">
    <link rel="stylesheet" href="../css/dark_mode.css">
    <?php
    // If user is logged in, attempt to load their saved theme and inline it so the page loads in the right theme
    if (isset($_SESSION['user_id'])) {
        try {
            $stmtTheme = $conn->prepare('SELECT theme FROM users WHERE id = ? LIMIT 1');
            $stmtTheme->execute([$_SESSION['user_id']]);
            $row = $stmtTheme->fetch(PDO::FETCH_ASSOC);
            $userTheme = $row && !empty($row['theme']) ? $row['theme'] : null;
            if ($userTheme === 'dark') {
                echo "<script>document.documentElement.setAttribute('data-theme','dark');</script>\n";
            }
        } catch (Exception $e) {
            // ignore theme fetch errors
        }
    }
    ?>
    <link rel="apple-touch-icon" href="../images/icon.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>

<body>
    <header style="opacity: 0; transition: opacity 0.5s ease;">
        <div class="logo"><img src="images/new logo.png" alt="Open Rota" style="height: 60px;"></div>
        <div class="nav-group">

            <div class="notification-container">
                <!-- Bell Icon -->
                <i class="fa fa-bell notification-icon" id="notification-icon"></i>
                <?php if ($notificationCount > 0): ?>
                    <span class="notification-badge"><?php echo $notificationCount; ?></span>
                <?php endif; ?>

                <!-- Notifications Dropdown -->
                <div class="notification-dropdown" id="notification-dropdown">
                    <?php if ($notificationCount > 0): ?>
                        <?php foreach ($notifications as $notif): ?>
                            <?php if ($notif['type'] === 'shift-invite' && !empty($notif['related_id'])): ?>
                                <a class="notification-item shit-invt notification-<?php echo $notif['type']; ?>"
                                    data-id="<?php echo $notif['id']; ?>"
                                    href="../functions/pending_shift_invitations.php?invitation_id=<?php echo $notif['related_id']; ?>&notif_id=<?php echo $notif['id']; ?>">
                                    <span class="close-btn" onclick="markAsRead(this.parentElement);">&times;</span>
                                    <p><?php echo htmlspecialchars($notif['message']); ?></p>
                                </a>
                            <?php elseif ($notif['type'] === 'shift-swap' && !empty($notif['related_id'])): ?>
                                <a class="notification-item shit-invt notification-<?php echo $notif['type']; ?>"
                                    data-id="<?php echo $notif['id']; ?>"
                                    href="../functions/pending_shift_swaps.php?swap_id=<?php echo $notif['related_id']; ?>&notif_id=<?php echo $notif['id']; ?>">
                                    <span class="close-btn" onclick="markAsRead(this.parentElement);">&times;</span>
                                    <p><?php echo htmlspecialchars($notif['message']); ?></p>
                                </a>
                            <?php else: ?>
                                <div class="notification-item notification-<?php echo $notif['type']; ?>"
                                    data-id="<?php echo $notif['id']; ?>">
                                    <span class="close-btn" onclick="markAsRead(this.parentElement);">&times;</span>
                                    <p><?php echo htmlspecialchars($notif['message']); ?></p>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="notification-item">
                            <p>No notifications</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="menu-toggle" id="menu-toggle">
                ☰
            </div>
            <nav class="nav-links" id="nav-links">
                <ul>
                    <li><a href="../users/dashboard.php">Dashboard</a></li>
                    <li><a href="shifts.php">My Shifts</a></li>
                    <li><a href="rota.php">Rota</a></li>
                    <li><a href="roles.php">Roles</a></li>
                    <li><a href="payroll.php">Payroll</a></li>
                    <li><a href="../users/settings.php">Settings</a></li>
                    <li><a href="../functions/logout.php">Logout</a></li>
                </ul>
            </nav>

        </div>

    </header>
    <script>
        // Remove malformed notifications (no positive numeric data-id) on render
        function stripMalformedNotifications() {
            const dropdown = document.getElementById('notification-dropdown');
            if (!dropdown) return;
            const items = Array.from(dropdown.querySelectorAll('.notification-item'));
            items.forEach(it => {
                const raw = it.getAttribute('data-id');
                const id = raw ? parseInt(raw, 10) : 0;
                if (!id || id <= 0) {
                    it.parentNode && it.parentNode.removeChild(it);
                }
            });
            const remaining = dropdown.querySelectorAll('.notification-item[data-id]');
            const badge = document.querySelector('.notification-badge');
            if (!remaining || remaining.length === 0) {
                dropdown.innerHTML = '<div class="notification-item"><p>No notifications</p></div>';
                if (badge) badge.style.display = 'none';
            } else if (badge) {
                badge.textContent = remaining.length;
                badge.style.display = 'flex';
            }
        }

        // Ensure the DOM is fully loaded before attaching the event listener
        document.addEventListener('DOMContentLoaded', function () {
            stripMalformedNotifications();
            var notificationIcon = document.getElementById('notification-icon');
            var dropdown = document.getElementById('notification-dropdown');

            if (notificationIcon && dropdown) {
                notificationIcon.addEventListener('click', function (e) {
                    e.stopPropagation(); // prevent the click from bubbling up
                    // Toggle dropdown visibility
                    dropdown.style.display = dropdown.style.display === "block" ? "none" : "block";
                });
            }

            // When clicking anywhere in the document outside the notification container, hide the dropdown.
            document.addEventListener('click', function (e) {
                // If dropdown is open and the click is not within notificationIcon or dropdown, then hide dropdown.
                if (dropdown.style.display === "block" &&
                    !notificationIcon.contains(e.target) &&
                    !dropdown.contains(e.target)) {
                    dropdown.style.display = "none";
                }
            });

            // Make notification items (especially shift-invite) clickable: clicking the item
            // (but not the close button or the anchor itself) will navigate to the anchor href.
            if (dropdown) {
                dropdown.addEventListener('click', function (e) {
                    // If user clicked the close button, let that handler run
                    if (e.target.closest && e.target.closest('.close-btn')) return;

                    // If the click was directly on an anchor, allow normal navigation
                    if (e.target.closest && e.target.closest('a')) return;

                    // Find the nearest notification-item and its anchor
                    const item = e.target.closest ? e.target.closest('.notification-item') : null;
                    if (!item) return;
                    const anchor = item.querySelector && item.querySelector('a.shit-invt');
                    if (!anchor) return;

                    // Navigate to the invitation page
                    e.preventDefault();
                    window.location.href = anchor.getAttribute('href');
                });
            }
        });
    </script>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelector('header').style.opacity = "1";
        });
    </script>
    <script>
        function markAsRead(notificationElem) {
            const notifId = notificationElem && notificationElem.getAttribute && notificationElem.getAttribute('data-id');
            if (!notifId) return;

            fetch('../functions/mark_notification.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: notifId })
            })
                .then(res => res.json())
                .then(data => {
                    if (data && data.success) {
                        // Hide/remove the notification element
                        if (notificationElem && notificationElem.parentNode) {
                            notificationElem.parentNode.removeChild(notificationElem);
                        }

                        // Recalculate remaining visible notifications
                        const dropdown = document.getElementById('notification-dropdown');
                        const remaining = dropdown ? dropdown.querySelectorAll('.notification-item[data-id]') : [];
                        const badge = document.querySelector('.notification-badge');

                        if (!remaining || remaining.length === 0) {
                            if (dropdown) dropdown.innerHTML = '<div class="notification-item"><p>No notifications</p></div>';
                            if (badge) badge.style.display = 'none';
                        } else if (badge) {
                            badge.textContent = remaining.length;
                            badge.style.display = 'flex';
                        }
                    } else {
                        console.error('Failed to mark notification as read:', data && data.error ? data.error : data);
                    }
                })
                .catch(err => console.error('Error marking notification as read:', err));
        }
    </script>
    <!-- Session Management Scripts -->
    <script src="../js/session-timeout.js"></script>
    <script src="../js/session-protection.js"></script>
    <script src="../js/menu.js"></script>
    <script src="../js/darkmode.js"></script>

    <script>
        // Initialize session configuration
        const sessionConfig = {
            timeoutDuration: <?php echo (SESSION_TIMEOUT_DURATION ?? 7200) * 1000; ?>, // Convert to milliseconds
            warningTime: <?php echo (SESSION_WARNING_TIME ?? 600) * 1000; ?>,
            checkInterval: <?php echo (SESSION_CHECK_INTERVAL ?? 60) * 1000; ?>,
            loginUrl: '../functions/login.php',
            showDetailedErrors: <?php echo SHOW_DETAILED_ERRORS ? 'true' : 'false'; ?>
        };

        // Note: Navigation handling is now managed by links.js for PWA compatibility
    </script>
</body>

</html>