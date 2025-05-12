<?php
// Assuming $conn is available here (or include db.php if needed)
require_once '../includes/db.php';
require_once '../includes/notifications.php';

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
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Open Rota">
    <link rel="icon" type="image/png" href="/rota-app-main/images/icon.jpg">
    <link rel="manifest" href="/rota-app-main/manifest.json">
    <link rel="stylesheet" href="/rota-app-main/css/navigation.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
    <link rel="apple-touch-icon" href="/rota-app-main/images/icon.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>

<body>
    <header>
        <div class="logo">Open Rota.</div>
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
                            <div class="notification-item notification-<?php echo $notif['type']; ?>"
                                data-id="<?php echo $notif['id']; ?>">
                                <span class="close-btn" onclick="markAsRead(this.parentElement);">&times;</span>
                                <?php if ($notif['type'] === 'shift-invite' && !empty($notif['related_id'])): ?>
                                    <a class="shit-invt"
                                        href="../functions/pending_shift_invitations.php?invitation_id=<?php echo $notif['related_id']; ?>&notif_id=<?php echo $notif['id']; ?>">
                                        <p><?php echo htmlspecialchars($notif['message']); ?></p>
                                    </a>
                                <?php else: ?>
                                    <p><?php echo htmlspecialchars($notif['message']); ?></p>
                                <?php endif; ?>
                            </div>
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
                    <li><a href="/rota-app-main/users/dashboard.php">Dashboard</a></li>
                    <li><a href="/rota-app-main/users/shifts.php">My Shifts</a></li>
                    <li><a href="/rota-app-main/users/rota.php">Rota</a></li>
                    <li><a href="/rota-app-main/users/roles.php">Roles</a></li>
                    <li><a href="/rota-app-main/users/settings.php">Settings</a></li>
                    <li><a href="/rota-app-main/functions/logout.php">Logout</a></li>
                </ul>
            </nav>

        </div>

    </header>
    <script src="/rota-app-main/js/menu.js"></script>
    <script>
        // Notification handling
        document.addEventListener('DOMContentLoaded', function () {
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

            // Special fix for Safari - make sure nav links have the right color
            const isSafari = /^((?!chrome|android).)*safari/i.test(navigator.userAgent);
            if (isSafari) {
                const navLinks = document.querySelectorAll('.nav-links ul li a');
                navLinks.forEach(link => {
                    link.style.backgroundColor = '#fd2b2b';
                    link.style.color = '#ffffff';
                });
            }
        });

        function markAsRead(notificationElem) {
            var notifId = notificationElem.getAttribute('data-id');
            if (!notifId) return;
            fetch('../functions/mark_notification.php?id=' + notifId)
                .then(response => response.text())
                .then(data => {
                    if (data.trim() === "success") {
                        // Remove notification element from DOM.
                        notificationElem.remove();

                        // Update the badge count.
                        var dropdown = document.getElementById('notification-dropdown');
                        var remainingNotifications = dropdown.querySelectorAll('.notification-item[data-id]');
                        var badge = document.querySelector('.notification-badge');

                        if (remainingNotifications.length === 0) {
                            // If none left, replace with "No notifications".
                            dropdown.innerHTML = '<div class="notification-item"><p>No notifications</p></div>';
                            if (badge) {
                                badge.remove();
                            }
                        } else {
                            // Update badge with the new count.
                            if (badge) {
                                badge.textContent = remainingNotifications.length;
                            }
                        }
                    } else {
                        console.error('Failed to mark notification as read:', data);
                    }
                })
                .catch(error => console.error('Error:', error));
        }
    </script>
    <script>
        function isStandalone() {
            return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone;
        }

        document.addEventListener('DOMContentLoaded', () => {
            if (isStandalone()) {
                const links = document.querySelectorAll('a');
                links.forEach(link => {
                    link.addEventListener('click', (event) => {
                        event.preventDefault();
                        window.open(link.href, '_blank');
                    });
                });
            }
        });
    </script>
</body>

</html>