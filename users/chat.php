<?php
session_start();
require_once '../includes/db.php';
require_once '../functions/check_session.php';

// Authentication check
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Get user information
$stmt = $pdo->prepare("SELECT username, role FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Get unread notification count for header
$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->execute([$user_id]);
$unread_notifications = $stmt->fetchColumn();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Team Chat - Open Rota</title>
    
    <!-- CSS Files -->
    <link rel="stylesheet" href="../css/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/navigation.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../css/chat.css?v=<?php echo time(); ?>">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
</head>
<body>
    <!-- Header with Navigation -->
    <div class="header">
        <div class="logo">
            <img src="../images/logo.png" alt="Open Rota Logo">
        </div>
        
        <div class="notification-container">
            <i class="fa fa-bell notification-icon" onclick="toggleNotificationDropdown()"></i>
            <?php if ($unread_notifications > 0): ?>
                <span class="notification-badge"><?php echo $unread_notifications; ?></span>
            <?php endif; ?>
            
            <div class="notification-dropdown" id="notificationDropdown">
                <div class="notification-header">
                    <h3>Notifications</h3>
                    <button onclick="markAllAsRead()" class="mark-read-btn">Mark all as read</button>
                </div>
                <div id="notificationList" class="notification-list">
                    <div class="loading">Loading notifications...</div>
                </div>
            </div>
        </div>
        
        <button class="menu-toggle" onclick="toggleMenu()">
            <i class="fa fa-bars"></i>
        </button>
    </div>

    <!-- Navigation Menu -->
    <nav class="nav-links" id="navLinks">
        <a href="dashboard.php"><i class="fa fa-home"></i> Dashboard</a>
        <a href="shifts.php"><i class="fa fa-calendar"></i> My Shifts</a>
        <a href="rota.php"><i class="fa fa-table"></i> Rota</a>
        <a href="payroll.php"><i class="fa fa-money"></i> Payroll</a>
        <a href="requests.php"><i class="fa fa-exchange"></i> Requests</a>
        <a href="chat.php" class="active"><i class="fa fa-comments"></i> Team Chat</a>
        <a href="settings.php"><i class="fa fa-cog"></i> Settings</a>
        <a href="../functions/logout.php"><i class="fa fa-sign-out"></i> Logout</a>
    </nav>

    <!-- Main Chat Container -->
    <div class="main-content">
        <div class="chat-container">
            <!-- Channels Sidebar -->
            <div class="chat-sidebar" id="chatSidebar">
                <div class="chat-sidebar-header">
                    <h2><i class="fa fa-comments"></i> Channels</h2>
                    <button class="btn-new-chat" onclick="openNewChatModal()" title="New Direct Message">
                        <i class="fa fa-plus"></i>
                    </button>
                </div>
                
                <div class="channels-search">
                    <input type="text" id="channelSearch" placeholder="Search channels..." onkeyup="filterChannels()">
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
                        <button class="btn-icon" onclick="toggleChannelInfo()" title="Channel Info">
                            <i class="fa fa-info-circle"></i>
                        </button>
                        <button class="btn-icon" onclick="toggleMuteChannel()" title="Mute/Unmute" id="muteBtn">
                            <i class="fa fa-bell"></i>
                        </button>
                    </div>
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
                    <div class="input-wrapper">
                        <div class="input-group">
                            <textarea 
                                id="messageInput" 
                                class="message-input" 
                                placeholder="Type a message..."
                                rows="1"
                                disabled
                                onkeydown="handleKeyPress(event)"
                                onkeyup="handleTyping()"
                            ></textarea>
                            <button class="btn-attach" onclick="attachFile()" title="Attach File" id="attachBtn" disabled>
                                <i class="fa fa-paperclip"></i>
                            </button>
                            <input type="file" id="fileInput" style="display: none;" onchange="handleFileSelect(event)">
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

    <!-- JavaScript -->
    <script src="../js/navigation.js"></script>
    <script src="../js/chat.js?v=<?php echo time(); ?>"></script>
    
    <script>
        // Pass PHP variables to JavaScript
        const CURRENT_USER_ID = <?php echo $user_id; ?>;
        const CURRENT_USERNAME = "<?php echo htmlspecialchars($user['username']); ?>";
    </script>
</body>
</html>
