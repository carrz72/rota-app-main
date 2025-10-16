<?php
/**
 * Team Chat - Main Interface
 */
session_start();
require_once '../includes/auth.php';
requireLogin();
require_once '../includes/db.php';

$page_title = "Team Chat";
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'employee';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title><?php echo $page_title; ?> - Open Rota</title>

    <link rel="stylesheet" href="../css/navigation.css">
    <link rel="stylesheet" href="../css/chat.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>

<body>
    <!-- Navigation -->
    <header class="navigation">
        <div class="nav-container">
            <button class="menu-toggle" id="menuToggle">
                <i class="fa fa-bars"></i>
            </button>
            <div class="logo">
                <img src="../images/logo.png" alt="Open Rota Logo">
            </div>
            <nav class="nav-menu" id="navMenu">
                <ul>
                    <li><a href="dashboard.php"><i class="fa fa-tachometer"></i> Dashboard</a></li>
                    <li><a href="shifts.php"><i class="fa fa-calendar"></i> My Shifts</a></li>
                    <li><a href="rota.php"><i class="fa fa-table"></i> Rota</a></li>
                    <li><a href="chat.php" class="active"><i class="fa fa-comments"></i> Chat</a></li>
                    <li><a href="settings.php"><i class="fa fa-cog"></i> Settings</a></li>
                    <?php if (in_array($user_role, ['admin', 'super_admin'])): ?>
                        <li><a href="../admin/admin_dashboard.php"><i class="fa fa-shield"></i> Admin</a></li>
                    <?php endif; ?>
                    <li><a href="../functions/logout.php"><i class="fa fa-sign-out"></i> Logout</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <!-- Chat Container -->
    <div class="chat-container">
        <!-- Sidebar - Channels List -->
        <div class="chat-sidebar" id="chatSidebar">
            <div class="chat-sidebar-header">
                <h2><i class="fas fa-comments"></i> Chats</h2>
                <?php if (in_array($user_role, ['admin', 'super_admin'])): ?>
                    <button class="btn-new-chat" id="btnNewChannel" title="Create Channel">
                        <i class="fas fa-plus"></i>
                    </button>
                <?php endif; ?>
            </div>

            <div class="chat-search">
                <input type="text" id="channelSearch" placeholder="Search channels..." />
            </div>

            <div class="chat-channels-list" id="channelsList">
                <div class="chat-loading">
                    <div class="spinner"></div>
                </div>
            </div>
        </div>

        <!-- Main Chat Area -->
        <div class="chat-main">
            <!-- No Channel Selected State -->
            <div class="chat-empty-state" id="emptyState">
                <i class="fas fa-comments"></i>
                <h3>Select a channel to start chatting</h3>
                <p>Choose a conversation from the sidebar or start a new one</p>
            </div>

            <!-- Active Chat (hidden by default) -->
            <div id="activeChat" style="display: none; height: 100%; display: flex; flex-direction: column;">
                <!-- Chat Header -->
                <div class="chat-header">
                    <div class="chat-header-info">
                        <h3 id="chatChannelName">Channel Name</h3>
                        <div class="chat-header-meta">
                            <span id="chatChannelMeta">Loading...</span>
                        </div>
                    </div>
                    <div class="chat-header-actions">
                        <button class="btn-chat-action" id="btnChannelInfo" title="Channel Info">
                            <i class="fas fa-info-circle"></i>
                        </button>
                        <button class="btn-chat-action" id="btnMuteChannel" title="Mute Notifications">
                            <i class="fas fa-bell"></i>
                        </button>
                        <button class="btn-chat-action" id="btnMoreOptions" title="More Options">
                            <i class="fas fa-ellipsis-v"></i>
                        </button>
                    </div>
                </div>

                <!-- Messages -->
                <div class="chat-messages" id="chatMessages">
                    <div class="chat-loading">
                        <div class="spinner"></div>
                    </div>
                </div>

                <!-- Typing Indicator -->
                <div class="typing-indicator" id="typingIndicator" style="display: none;">
                    <div class="typing-dots">
                        <div class="typing-dot"></div>
                        <div class="typing-dot"></div>
                        <div class="typing-dot"></div>
                    </div>
                    <span id="typingText">Someone is typing...</span>
                </div>

                <!-- Message Input -->
                <div class="chat-input">
                    <div class="chat-input-wrapper">
                        <div class="chat-input-field">
                            <!-- Reply Preview -->
                            <div class="chat-input-reply" id="replyPreview" style="display: none;">
                                <div>
                                    <strong>Replying to:</strong>
                                    <span id="replyText">Message text...</span>
                                </div>
                                <button class="btn-cancel-reply" id="btnCancelReply">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>

                            <!-- Input Box -->
                            <div class="chat-input-box">
                                <button class="btn-attach-file" id="btnAttachFile" title="Attach File">
                                    <i class="fas fa-paperclip"></i>
                                </button>
                                <input type="file" id="fileInput" style="display: none;"
                                    accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv">
                                <textarea id="messageInput" rows="1" placeholder="Type a message..."
                                    maxlength="5000"></textarea>
                                <button class="btn-emoji" id="btnEmoji" title="Emoji">
                                    <i class="fas fa-smile"></i>
                                </button>
                            </div>
                        </div>
                        <button class="btn-send-message" id="btnSendMessage" disabled>
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="../js/menu.js"></script>
    <script src="../js/session-timeout.js"></script>
    <script>
        // Initialize chat application
        const ChatApp = {
            currentChannelId: null,
            currentUser: <?php echo $user_id; ?>,
            username: '<?php echo $_SESSION['username'] ?? 'User'; ?>',
            replyToId: null,
            refreshInterval: null,

            init() {
                this.loadChannels();
                this.setupEventListeners();
                this.startAutoRefresh();

                // Check for channel ID in URL
                const urlParams = new URLSearchParams(window.location.search);
                const channelId = urlParams.get('channel');
                if (channelId) {
                    this.selectChannel(parseInt(channelId));
                }
            },

            setupEventListeners() {
                // Send message
                document.getElementById('btnSendMessage').addEventListener('click', () => this.sendMessage());
                document.getElementById('messageInput').addEventListener('keypress', (e) => {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        this.sendMessage();
                    }
                });

                // Auto-resize textarea
                document.getElementById('messageInput').addEventListener('input', function () {
                    this.style.height = 'auto';
                    this.style.height = Math.min(this.scrollHeight, 120) + 'px';

                    // Enable/disable send button
                    const btnSend = document.getElementById('btnSendMessage');
                    btnSend.disabled = this.value.trim().length === 0;
                });

                // File attachment
                document.getElementById('btnAttachFile').addEventListener('click', () => {
                    document.getElementById('fileInput').click();
                });

                document.getElementById('fileInput').addEventListener('change', (e) => {
                    if (e.target.files.length > 0) {
                        this.uploadFile(e.target.files[0]);
                    }
                });

                // Cancel reply
                document.getElementById('btnCancelReply').addEventListener('click', () => {
                    this.replyToId = null;
                    document.getElementById('replyPreview').style.display = 'none';
                });

                // Mute channel
                document.getElementById('btnMuteChannel').addEventListener('click', () => {
                    this.toggleMute();
                });

                // Channel search
                document.getElementById('channelSearch').addEventListener('input', (e) => {
                    this.searchChannels(e.target.value);
                });
            },

            async loadChannels() {
                try {
                    const response = await fetch('../functions/chat_channels_api.php?action=get_channels');
                    const data = await response.json();

                    if (data.success) {
                        this.displayChannels(data.channels);
                    } else {
                        console.error('Failed to load channels:', data.message);
                    }
                } catch (error) {
                    console.error('Error loading channels:', error);
                }
            },

            displayChannels(channels) {
                const list = document.getElementById('channelsList');

                if (channels.length === 0) {
                    list.innerHTML = '<div style="padding: 20px; text-align: center; color: #6c757d;">No channels yet</div>';
                    return;
                }

                list.innerHTML = channels.map(channel => `
                    <div class="channel-item ${channel.id == this.currentChannelId ? 'active' : ''}" 
                         data-channel-id="${channel.id}" onclick="ChatApp.selectChannel(${channel.id})">
                        <div class="channel-icon ${channel.type}">
                            <i class="fas fa-${this.getChannelIcon(channel.type)}"></i>
                        </div>
                        <div class="channel-info">
                            <div class="channel-name">
                                ${channel.name}
                                ${channel.is_muted ? '<i class="fas fa-bell-slash channel-muted"></i>' : ''}
                            </div>
                            ${channel.last_message ? `
                                <div class="channel-last-message">${channel.last_message.substring(0, 40)}${channel.last_message.length > 40 ? '...' : ''}</div>
                            ` : ''}
                        </div>
                        ${channel.unread_count > 0 ? `<div class="channel-unread">${channel.unread_count}</div>` : ''}
                    </div>
                `).join('');
            },

            getChannelIcon(type) {
                const icons = {
                    'general': 'comments',
                    'branch': 'building',
                    'role': 'users',
                    'direct': 'user'
                };
                return icons[type] || 'comments';
            },

            async selectChannel(channelId) {
                this.currentChannelId = channelId;

                // Update UI
                document.getElementById('emptyState').style.display = 'none';
                document.getElementById('activeChat').style.display = 'flex';

                // Update active state in sidebar
                document.querySelectorAll('.channel-item').forEach(item => {
                    item.classList.remove('active');
                    if (item.dataset.channelId == channelId) {
                        item.classList.add('active');
                    }
                });

                // Load messages
                await this.loadMessages(channelId);

                // Mark as read
                this.markAsRead(channelId);
            },

            async loadMessages(channelId) {
                const container = document.getElementById('chatMessages');
                container.innerHTML = '<div class="chat-loading"><div class="spinner"></div></div>';

                try {
                    const response = await fetch(`../functions/chat_api.php?action=get_messages&channel_id=${channelId}&limit=50`);
                    const data = await response.json();

                    if (data.success) {
                        this.displayMessages(data.messages);
                        this.scrollToBottom();
                    } else {
                        container.innerHTML = `<div style="text-align: center; color: #6c757d; padding: 40px;">${data.message}</div>`;
                    }
                } catch (error) {
                    container.innerHTML = `<div style="text-align: center; color: #dc3545; padding: 40px;">Error loading messages</div>`;
                    console.error('Error loading messages:', error);
                }
            },

            displayMessages(messages) {
                const container = document.getElementById('chatMessages');

                if (messages.length === 0) {
                    container.innerHTML = '<div class="chat-empty-state"><i class="fas fa-comment-slash"></i><h3>No messages yet</h3><p>Be the first to send a message!</p></div>';
                    return;
                }

                container.innerHTML = messages.map(msg => {
                    const isOwn = msg.user_id == this.currentUser;
                    const avatar = msg.username.charAt(0).toUpperCase();
                    const time = new Date(msg.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

                    return `
                        <div class="message-group ${isOwn ? 'own' : ''}">
                            <div class="message-avatar">${avatar}</div>
                            <div class="message-content">
                                ${!isOwn ? `<div class="message-author">${msg.username}</div>` : ''}
                                ${msg.reply_to_id ? `
                                    <div class="message-reply">
                                        <div class="message-reply-author">${msg.reply_username || 'User'}</div>
                                        <div>${msg.reply_message || 'Message'}</div>
                                    </div>
                                ` : ''}
                                <div class="message-bubble">
                                    <p class="message-text">${this.escapeHtml(msg.message)}</p>
                                </div>
                                <div class="message-meta">
                                    <span>${time}</span>
                                    ${msg.is_edited ? '<span class="message-edited">edited</span>' : ''}
                                </div>
                            </div>
                        </div>
                    `;
                }).join('');
            },

            async sendMessage() {
                const input = document.getElementById('messageInput');
                const message = input.value.trim();

                if (!message || !this.currentChannelId) return;

                const formData = new FormData();
                formData.append('action', 'send_message');
                formData.append('channel_id', this.currentChannelId);
                formData.append('message', message);
                if (this.replyToId) {
                    formData.append('reply_to_id', this.replyToId);
                }

                try {
                    const response = await fetch('../functions/chat_api.php', {
                        method: 'POST',
                        body: formData
                    });

                    const data = await response.json();

                    if (data.success) {
                        input.value = '';
                        input.style.height = 'auto';
                        this.replyToId = null;
                        document.getElementById('replyPreview').style.display = 'none';
                        document.getElementById('btnSendMessage').disabled = true;

                        // Reload messages
                        await this.loadMessages(this.currentChannelId);
                    } else {
                        alert('Failed to send message: ' + data.message);
                    }
                } catch (error) {
                    console.error('Error sending message:', error);
                    alert('Failed to send message');
                }
            },

            async markAsRead(channelId) {
                const formData = new FormData();
                formData.append('action', 'mark_read');
                formData.append('channel_id', channelId);

                try {
                    await fetch('../functions/chat_api.php', {
                        method: 'POST',
                        body: formData
                    });
                    this.loadChannels(); // Refresh to update unread counts
                } catch (error) {
                    console.error('Error marking as read:', error);
                }
            },

            startAutoRefresh() {
                // Refresh messages every 3 seconds if a channel is selected
                this.refreshInterval = setInterval(() => {
                    if (this.currentChannelId) {
                        this.loadMessages(this.currentChannelId);
                    }
                    this.loadChannels(); // Refresh channel list
                }, 3000);
            },

            scrollToBottom() {
                const container = document.getElementById('chatMessages');
                container.scrollTop = container.scrollHeight;
            },

            escapeHtml(text) {
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            },

            searchChannels(query) {
                const items = document.querySelectorAll('.channel-item');
                query = query.toLowerCase();

                items.forEach(item => {
                    const name = item.querySelector('.channel-name').textContent.toLowerCase();
                    item.style.display = name.includes(query) ? 'flex' : 'none';
                });
            }
        };

        // Initialize when DOM is ready
        document.addEventListener('DOMContentLoaded', () => {
            ChatApp.init();
        });
    </script>
</body>

</html>