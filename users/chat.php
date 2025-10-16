<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireLogin();

// Authentication check
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Get user information
$stmt = $conn->prepare("SELECT username, role FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header("Location: ../index.php");
    exit();
}

require_once '../functions/get_chat_unread.php';
require_once '../includes/notifications.php';
$notifications = getNotifications($user_id);
$notificationCount = count($notifications);
$is_admin = in_array($user['role'] ?? '', ['admin', 'super_admin']);
$unreadChatCount = getUnreadChatCount($conn, $user_id);
$userInitial = strtoupper(substr($user['username'] ?? '', 0, 1));
if (!$userInitial) {
    $userInitial = 'U';
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Team Chat - Open Rota</title>

    <!-- CSS Files -->
    <link rel="stylesheet" href="../css/dashboard.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/navigation.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/chat.css?v=<?php echo time(); ?>">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
</head>

<body style="overflow-x: hidden; width: 100%; position: relative;">
    <!-- Header -->
    <header style="opacity: 1; transition: opacity 0.5s ease;">
        <div class="logo"><img src="../images/new logo.png" alt="Open Rota" style="height: 60px;"></div>
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
                    <li><a href="dashboard.php"><i class="fa fa-tachometer"></i> Dashboard</a></li>
                    <li><a href="shifts.php"><i class="fa fa-calendar"></i> My Shifts</a></li>
                    <li><a href="rota.php"><i class="fa fa-table"></i> Rota</a></li>
                    <li><a href="roles.php"><i class="fa fa-users"></i> Roles</a></li>
                    <li><a href="payroll.php"><i class="fa fa-money"></i> Payroll</a></li>
                    <li><a href="chat.php" class="active"><i class="fa fa-comments"></i> Team Chat</a></li>
                    <li><a href="settings.php"><i class="fa fa-cog"></i> Settings</a></li>
                    <?php if ($is_admin): ?>
                        <li><a href="../admin/admin_dashboard.php"><i class="fa fa-shield"></i> Admin</a></li>
                    <?php endif; ?>
                    <li><a href="../functions/logout.php"><i class="fa fa-sign-out"></i> Logout</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <!-- Chat Page Wrapper -->
    <div class="chat-page">
        <section class="chat-hero">
            <div class="chat-hero-left">
                <div class="chat-hero-avatar" aria-hidden="true"><?php echo htmlspecialchars($userInitial); ?></div>
                <div>
                    <p class="chat-hero-eyebrow">Stay connected</p>
                    <h1>Team Chat</h1>
                    <p class="chat-hero-sub">Coordinate shifts, share updates, and keep every branch aligned in real
                        time.</p>
                    <div class="chat-hero-actions">
                        <button type="button" class="chat-hero-btn" onclick="openNewChatModal()">
                            <i class="fa fa-plus"></i> Start conversation
                        </button>
                        <a class="chat-hero-link" href="#chatMessages">
                            Jump to messages <i class="fa fa-arrow-right"></i>
                        </a>
                    </div>
                </div>
            </div>
            <div class="chat-hero-stats">
                <div class="chat-stat-card">
                    <span class="chat-stat-label">Unread chats</span>
                    <span class="chat-stat-value" id="heroUnreadCount"
                        data-chat-unread><?php echo (int) $unreadChatCount; ?></span>
                    <span class="chat-stat-hint">Messages waiting for you</span>
                </div>
                <div class="chat-stat-card">
                    <span class="chat-stat-label">Alerts</span>
                    <span class="chat-stat-value" id="heroAlertCount"><?php echo (int) $notificationCount; ?></span>
                    <span class="chat-stat-hint">System notifications</span>
                </div>
                <div class="chat-stat-card">
                    <span class="chat-stat-label">Today</span>
                    <span class="chat-stat-value"><?php echo date('M j'); ?></span>
                    <span class="chat-stat-hint"><?php echo date('l'); ?></span>
                </div>
            </div>
        </section>

        <!-- Main Chat Container -->
        <div class="main-content">
            <div class="chat-container">
                <!-- Channels Sidebar -->
                <div class="chat-sidebar" id="chatSidebar">
                    <div class="chat-sidebar-header">
                        <h2><i class="fa fa-comments"></i> Channels</h2>
                        <div style="display: flex; gap: 8px; align-items: center;">
                            <button type="button" class="btn-new-chat" onclick="openNewChatModal()"
                                title="Start a direct message">
                                <i class="fa fa-plus"></i>
                            </button>
                            <?php if ($is_admin): ?>
                                <button type="button" class="btn-new-chat" onclick="openCreateChannelModal()"
                                    title="Create Channel">
                                    <i class="fa fa-plus-square"></i>
                                </button>
                            <?php endif; ?>
                            <button type="button" class="btn-close-sidebar" onclick="closeSidebar()"
                                title="Close sidebar">
                                <i class="fa fa-times"></i>
                            </button>
                        </div>
                    </div>

                    <div class="channels-search">
                        <input type="text" id="channelSearch" placeholder="Search channels..."
                            onkeyup="filterChannels()">
                    </div>

                    <div class="sidebar-overview">
                        <div class="sidebar-pill">
                            <i class="fa fa-inbox"></i>
                            <span><strong id="sidebarUnreadCount"><?php echo (int) $unreadChatCount; ?></strong>
                                unread</span>
                        </div>
                        <div class="sidebar-pill">
                            <i class="fa fa-bell"></i>
                            <span><strong id="sidebarAlertCount"><?php echo (int) $notificationCount; ?></strong>
                                alerts</span>
                        </div>
                    </div>

                    <div class="channels-list" id="channelsList">
                        <div class="loading">
                            <i class="fa fa-spinner fa-spin"></i>
                        </div>
                    </div>
                </div>

                <!-- Main Chat Area -->
                <div class="chat-main">
                    <!-- Chat Header -->
                    <div class="chat-header">
                        <button class="mobile-toggle" onclick="toggleSidebar()">
                            <i class="fa fa-bars"></i>
                        </button>
                        <div class="chat-header-info" id="chatHeaderInfo">
                            <h3>Select a channel to start chatting</h3>
                            <p>Choose from the list on the left</p>
                        </div>
                        <div class="chat-header-actions" id="chatHeaderActions" style="display: none;">
                            <button class="btn-icon" onclick="toggleSearch()" title="Search Messages">
                                <i class="fa fa-search"></i>
                            </button>
                            <button class="btn-icon" onclick="toggleChannelInfo()" title="Channel Info">
                                <i class="fa fa-info-circle"></i>
                            </button>
                            <button class="btn-icon" onclick="toggleMuteChannel()" title="Mute/Unmute" id="muteBtn">
                                <i class="fa fa-bell"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Search Panel -->
                    <div class="search-panel" id="searchPanel" style="display: none;">
                        <div class="search-input-wrapper">
                            <input type="text" id="searchInput" placeholder="Search messages..."
                                onkeyup="searchMessages()">
                            <button class="btn-close-search" onclick="toggleSearch()">
                                <i class="fa fa-times"></i>
                            </button>
                        </div>
                        <div id="searchResults" class="search-results"></div>
                    </div>

                    <!-- Messages Area -->
                    <div class="chat-messages" id="chatMessages">
                        <div class="empty-state">
                            <i class="fa fa-comments"></i>
                            <h3>Welcome to Team Chat!</h3>
                            <p>Select a channel or start a direct message to begin</p>
                        </div>
                    </div>

                    <!-- Typing Indicator -->
                    <div class="typing-indicator" id="typingIndicator" style="display: none;">
                        <span id="typingText"></span>
                        <span class="typing-dots">
                            <span>.</span><span>.</span><span>.</span>
                        </span>
                    </div>

                    <!-- Message Input -->
                    <div class="chat-input">
                        <div id="editingIndicator" class="editing-indicator"></div>
                        <div class="input-wrapper">
                            <div class="input-group">
                                <textarea id="messageInput" class="message-input" placeholder="Type a message..."
                                    rows="1" disabled onkeydown="handleKeyPress(event)"
                                    onkeyup="handleTyping()"></textarea>
                                <button class="btn-attach" onclick="attachFile()" title="Attach File" id="attachBtn"
                                    disabled>
                                    <i class="fa fa-paperclip"></i>
                                </button>
                                <input type="file" id="fileInput" style="display: none;"
                                    onchange="handleFileSelect(event)">
                            </div>
                            <button class="btn-send" onclick="sendMessage()" id="sendBtn" disabled>
                                <i class="fa fa-paper-plane"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- New Chat Modal -->
        <div class="modal-overlay" id="newChatModal" style="display: none;">
            <!-- Create Channel Modal (Admin only) -->
            <?php if ($is_admin): ?>
                <div class="modal-overlay" id="createChannelModal" style="display: none;">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h3><i class="fa fa-plus-square"></i> Create Channel</h3>
                            <button class="modal-close" onclick="closeCreateChannelModal()">
                                <i class="fa fa-times"></i>
                            </button>
                        </div>
                        <form id="createChannelForm">
                            <div class="form-group">
                                <label for="channelName">Channel Name</label>
                                <input type="text" id="channelName" name="channelName" required maxlength="40"
                                    placeholder="Enter channel name">
                            </div>
                            <div class="form-group">
                                <label for="channelType">Channel Type</label>
                                <select id="channelType" name="channelType" required>
                                    <option value="general">Team Channel</option>
                                    <option value="branch">Branch Huddle</option>
                                    <option value="role">Role Group</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <button type="submit" class="chat-hero-btn" style="width:100%"><i
                                        class="fa fa-plus-square"></i> Create Channel</button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
            <div class="modal-content">
                <div class="modal-header">
                    <h3><i class="fa fa-user-plus"></i> Start Direct Message</h3>
                    <button class="modal-close" onclick="closeNewChatModal()">
                        <i class="fa fa-times"></i>
                    </button>
                </div>
                <div class="channels-search">
                    <input type="text" id="userSearch" placeholder="Search users..." onkeyup="filterUsers()">
                </div>
                <div class="user-list" id="usersList">
                    <div class="loading">
                        <i class="fa fa-spinner fa-spin"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <!-- Edit Channel Modal (Admin/Owner only) -->
    <div class="modal-overlay" id="editChannelModal" style="display: none;">
        <div class="modal-content" id="editChannelModalContent">
            <!-- Content will be injected by JS -->
        </div>
    </div>

    <!-- JavaScript -->
    <script src="../js/chat.js?v=<?php echo time(); ?>"></script>

    <!-- Fallback: ensure toggleSidebar/closeSidebar exist even if chat.js failed to load -->
    <script>
        (function () {
            // Provide lightweight fallbacks so the UI works even if chat.js fails to load.
            if (typeof window.toggleSidebar !== 'function') {
                console.warn('chat.js failed to load or parse; providing fallback toggleSidebar/closeSidebar');
                window.toggleSidebar = function () {
                    const sidebar = document.getElementById('chatSidebar');
                    if (sidebar) {
                        sidebar.classList.toggle('mobile-open');
                        console.log('Fallback: toggled sidebar; classList:', sidebar.classList);
                    } else {
                        console.warn('Fallback: sidebar element not found');
                    }
                };
                window.closeSidebar = function () {
                    const sidebar = document.getElementById('chatSidebar');
                    if (sidebar) sidebar.classList.remove('mobile-open');
                };
            }

            // Modal fallbacks for the quick buttons on the page
            if (typeof window.openNewChatModal !== 'function') {
                window.openNewChatModal = function () {
                    const modal = document.getElementById('newChatModal');
                    if (modal) {
                        modal.style.display = 'flex';
                        console.log('Fallback: opened new chat modal');
                    } else {
                        console.warn('Fallback: newChatModal element not found');
                    }
                };
                window.closeNewChatModal = function () {
                    const modal = document.getElementById('newChatModal');
                    if (modal) modal.style.display = 'none';
                };
            }

            if (typeof window.openCreateChannelModal !== 'function') {
                window.openCreateChannelModal = function () {
                    const modal = document.getElementById('createChannelModal');
                    if (modal) {
                        modal.style.display = 'flex';
                        console.log('Fallback: opened create channel modal');
                    } else {
                        console.warn('Fallback: createChannelModal element not found or user not admin');
                    }
                };
                window.closeCreateChannelModal = function () {
                    const modal = document.getElementById('createChannelModal');
                    if (modal) modal.style.display = 'none';
                };
            }
        })();
    </script>

    <script>
        // Pass PHP variables to JavaScript
        const CURRENT_USER_ID = <?php echo $user_id; ?>;
        const CURRENT_USERNAME = "<?php echo htmlspecialchars($user['username']); ?>";
    </script>
    <script>
        (function () {
            const notificationEndpoint = '../functions/mark_notification.php';

            function sendNotificationRequest(payload) {
                return fetch(notificationEndpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                }).then(response => response.json());
            }

            function updateBadge(list, badge, markAllBtn) {
                if (!list) return;
                const remaining = list.querySelectorAll('.notification-item[data-id]');
                const count = remaining.length;

                if (badge) {
                    if (count > 0) {
                        badge.textContent = count;
                        badge.style.display = 'inline-flex';
                    } else {
                        badge.style.display = 'none';
                    }
                }

                if (markAllBtn) {
                    markAllBtn.disabled = count === 0;
                }

                if (count === 0) {
                    list.innerHTML = '<div class="notification-item"><p>No new notifications</p></div>';
                }
            }

            function bindNotificationButtons(list, badge, markAllBtn) {
                if (!list) return;
                list.querySelectorAll('.close-btn').forEach(button => {
                    button.addEventListener('click', event => {
                        event.stopPropagation();
                        const item = button.closest('.notification-item');
                        if (!item) return;
                        const notificationId = item.getAttribute('data-id');
                        if (!notificationId) return;

                        sendNotificationRequest({ id: notificationId })
                            .then(data => {
                                if (!data.success) {
                                    throw new Error(data.error || 'Failed to mark notification as read');
                                }
                                item.remove();
                                updateBadge(list, badge, markAllBtn);
                            })
                            .catch(error => console.error(error));
                    });
                });
            }

            document.addEventListener('DOMContentLoaded', function () {
                const menuToggle = document.getElementById('menuToggle');
                const navLinks = document.getElementById('navLinks');
                const notificationToggle = document.getElementById('notificationToggle');
                const notificationDropdown = document.getElementById('notificationDropdown');
                const notificationList = document.getElementById('notificationList');
                const notificationBadge = document.getElementById('notificationBadge');
                const markAllBtn = document.getElementById('markAllNotifications');

                if (menuToggle && navLinks) {
                    menuToggle.addEventListener('click', function (e) {
                        e.preventDefault();
                        e.stopPropagation();
                        const isOpen = navLinks.classList.toggle('show');
                        menuToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                    });

                    document.addEventListener('click', function (event) {
                        if (!navLinks.contains(event.target) && !menuToggle.contains(event.target)) {
                            navLinks.classList.remove('show');
                            menuToggle.setAttribute('aria-expanded', 'false');
                        }
                    });
                }

                if (notificationToggle && notificationDropdown) {
                    notificationDropdown.style.display = 'none';
                    notificationToggle.addEventListener('click', function (e) {
                        e.stopPropagation();
                        const isOpen = notificationDropdown.classList.toggle('show');
                        notificationDropdown.style.display = isOpen ? 'block' : 'none';
                        notificationToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                    });

                    document.addEventListener('click', function (event) {
                        if (!notificationDropdown.contains(event.target) && !notificationToggle.contains(event.target)) {
                            notificationDropdown.classList.remove('show');
                            notificationDropdown.style.display = 'none';
                            notificationToggle.setAttribute('aria-expanded', 'false');
                        }
                    });
                }

                if (markAllBtn && notificationList) {
                    markAllBtn.addEventListener('click', function () {
                        if (markAllBtn.disabled) {
                            return;
                        }
                        sendNotificationRequest({ id: 'all' })
                            .then(data => {
                                if (!data.success) {
                                    throw new Error(data.error || 'Failed to clear notifications');
                                }
                                notificationList.innerHTML = '<div class="notification-item"><p>No new notifications</p></div>';
                                updateBadge(notificationList, notificationBadge, markAllBtn);
                            })
                            .catch(error => console.error(error));
                    });
                }

                bindNotificationButtons(notificationList, notificationBadge, markAllBtn);
                updateBadge(notificationList, notificationBadge, markAllBtn);
            });
        })();
    </script>
</body>

</html>