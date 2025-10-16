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
                        <div class="combined-dropdown" style="position: relative; display: inline-block;">
                            <button type="button" class="chat-hero-btn" id="heroCombinedBtn" onclick="toggleCombinedMenu('heroMenu')" aria-haspopup="true" aria-expanded="false">
                                <i class="fa fa-plus"></i> New <i class="fa fa-caret-down" style="margin-left:8px;"></i>
                            </button>
                            <div class="combined-menu" id="heroMenu" style="display: none; position: absolute; right: 0; top: 100%; background: #fff; box-shadow: 0 4px 10px rgba(0,0,0,0.08); z-index: 1000; min-width: 200px; border-radius: 6px; overflow: hidden;">
                                <button type="button" class="combined-menu-item" style="display:block; width:100%; padding:10px 12px; border:none; background:none; text-align:left;" data-action="start-dm" tabindex="0">
                                    <i class="fa fa-user-plus" style="margin-right:8px"></i> Start Direct Message
                                </button>
                                <?php if ($is_admin): ?>
                                    <button type="button" class="combined-menu-item" style="display:block; width:100%; padding:10px 12px; border:none; background:none; text-align:left;" data-action="create-channel" tabindex="0">
                                        <i class="fa fa-plus-square" style="margin-right:8px"></i> Create Channel
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
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
                            <div class="combined-dropdown" style="position: relative;">
                                <button type="button" class="btn-new-chat" id="sidebarCombinedBtn" onclick="toggleCombinedMenu('sidebarMenu')" title="New" aria-haspopup="true" aria-expanded="false">
                                    <i class="fa fa-plus"></i>
                                </button>
                                <div class="combined-menu" id="sidebarMenu" style="display: none; position: absolute; left: 0; top: 100%; background: #fff; box-shadow: 0 4px 10px rgba(0,0,0,0.08); z-index: 1000; min-width: 200px; border-radius: 6px; overflow: hidden;">
                                    <button type="button" class="combined-menu-item" style="display:block; width:100%; padding:10px 12px; border:none; background:none; text-align:left;" data-action="start-dm" tabindex="0" title="Start a direct message">
                                        <i class="fa fa-user-plus" style="margin-right:8px"></i> Start Direct Message
                                    </button>
                                    <?php if ($is_admin): ?>
                                        <button type="button" class="combined-menu-item" style="display:block; width:100%; padding:10px 12px; border:none; background:none; text-align:left;" data-action="create-channel" tabindex="0" title="Create Channel">
                                            <i class="fa fa-plus-square" style="margin-right:8px"></i> Create Channel
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <button type="button" class="btn-close-sidebar" onclick="closeSidebar()" title="Close sidebar">
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

    <!-- NOTE: Removed previous JS fallbacks now that chat.js loads correctly. If chat.js fails to load, menu items will briefly not work. -->

    <script>
        // Pass PHP variables to JavaScript
        const CURRENT_USER_ID = <?php echo $user_id; ?>;
        const CURRENT_USERNAME = "<?php echo htmlspecialchars($user['username']); ?>";
    </script>
    <script>
        // Combined menu toggle for hero/sidebar combined New button
        function toggleCombinedMenu(id) {
            const el = document.getElementById(id);
            if (!el) return;
            el.style.display = el.style.display === 'block' ? 'none' : 'block';
        }

        // Close combined menus when clicking outside
        document.addEventListener('click', function (e) {
            ['heroMenu', 'sidebarMenu'].forEach(id => {
                const menu = document.getElementById(id);
                const btn = document.getElementById(id === 'heroMenu' ? 'heroCombinedBtn' : 'sidebarCombinedBtn');
                if (menu && btn) {
                    if (!menu.contains(e.target) && !btn.contains(e.target)) {
                        menu.style.display = 'none';
                    }
                }
            });
        });
        // Keyboard and ARIA support
        function setAriaExpanded(btnId, expanded) {
            const b = document.getElementById(btnId);
            if (b) b.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        }

        function toggleCombinedMenuWithAria(id) {
            const el = document.getElementById(id);
            if (!el) return;
            const isOpen = el.style.display === 'block';
            el.style.display = isOpen ? 'none' : 'block';
            const btnId = id === 'heroMenu' ? 'heroCombinedBtn' : 'sidebarCombinedBtn';
            setAriaExpanded(btnId, !isOpen);
            // If opening, focus the first menu item for accessibility
            if (!isOpen) {
                const first = el.querySelector('.combined-menu-item');
                if (first && typeof first.focus === 'function') {
                    first.focus();
                }
            }
        }

        // Replace existing call sites to use ARIA-aware toggler if present
        window.toggleCombinedMenu = function (id) { toggleCombinedMenuWithAria(id); };

        // Keyboard support: open with Enter/Space when focused, close with Esc
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                ['heroMenu', 'sidebarMenu'].forEach(id => {
                    const menu = document.getElementById(id);
                    if (menu && menu.style.display === 'block') menu.style.display = 'none';
                    const btnId = id === 'heroMenu' ? 'heroCombinedBtn' : 'sidebarCombinedBtn';
                    setAriaExpanded(btnId, false);
                });
            }
        });

        ['heroCombinedBtn', 'sidebarCombinedBtn'].forEach(btnId => {
            const btn = document.getElementById(btnId);
            if (!btn) return;
            btn.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    const menuId = btnId === 'heroCombinedBtn' ? 'heroMenu' : 'sidebarMenu';
                    toggleCombinedMenuWithAria(menuId);
                }
            });
        });

        // Delegate clicks on combined-menu items by data-action
        document.addEventListener('click', function (e) {
            const actionEl = e.target.closest && e.target.closest('.combined-menu-item');
            if (actionEl) {
                const action = actionEl.getAttribute('data-action');
                if (action === 'start-dm') {
                    openNewChatModal();
                } else if (action === 'create-channel') {
                    openCreateChannelModal();
                }
                // close all combined menus
                ['heroMenu', 'sidebarMenu'].forEach(id => { const m = document.getElementById(id); if (m) m.style.display = 'none'; });
                ['heroCombinedBtn','sidebarCombinedBtn'].forEach(bid => setAriaExpanded(bid, false));
            }
        });

        // Mobile FAB: add a floating button to trigger actions on mobile
        (function addMobileFab() {
            const fab = document.createElement('div');
            fab.id = 'mobileFab';
            fab.innerHTML = `
                <button id="mobileFabBtn" aria-haspopup="true" aria-expanded="false" title="New">
                    <i class="fa fa-plus"></i>
                </button>
                <div id="mobileFabMenu" style="display:none">
                    <button class="combined-menu-item" data-action="start-dm">Start Direct Message</button>
                    <?php if ($is_admin): ?>
                        <button class="combined-menu-item" data-action="create-channel">Create Channel</button>
                    <?php endif; ?>
                </div>
            `;
            fab.className = 'mobile-fab';
            document.body.appendChild(fab);

            const btn = document.getElementById('mobileFabBtn');
            const menu = document.getElementById('mobileFabMenu');
            if (btn && menu) {
                btn.addEventListener('click', function () {
                    const open = menu.style.display === 'block';
                    menu.style.display = open ? 'none' : 'block';
                    btn.setAttribute('aria-expanded', (!open).toString());
                });
                document.addEventListener('click', function (ev) {
                    if (!fab.contains(ev.target)) {
                        menu.style.display = 'none';
                        btn.setAttribute('aria-expanded', 'false');
                    }
                });
            }
        })();
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