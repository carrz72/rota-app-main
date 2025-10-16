/* =======================================
   TEAM CHAT - JAVASCRIPT
   Real-time messaging interface
   ======================================= */

// Global variables
let currentChannelId = null;
let currentChannelData = null;
let messagesPollInterval = null;
let channelsPollInterval = null;
let typingTimeout = null;
let lastMessageId = null;
let contextMenuMessage = null;
let editingMessageId = null;
let pendingScrollMessageId = null;
const channelsCache = new Map();

// Initialize chat on page load
document.addEventListener('DOMContentLoaded', function () {
    loadChannels();
    startChannelsPolling();

    // Auto-resize textarea
    const messageInput = document.getElementById('messageInput');
    messageInput.addEventListener('input', function () {
        this.style.height = 'auto';
        this.style.height = (this.scrollHeight) + 'px';
    });

    const createChannelForm = document.getElementById('createChannelForm');
    if (createChannelForm) {
        createChannelForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const name = document.getElementById('channelName').value.trim();
            const type = document.getElementById('channelType').value;
            if (!name || !type) return;
            fetch('../functions/chat_channels_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=create_channel&name=${encodeURIComponent(name)}&type=${encodeURIComponent(type)}`
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        closeCreateChannelModal();
                        loadChannels();
                    } else {
                        alert('Failed to create channel: ' + (data.message || 'Unknown error'));
                    }
                })
                .catch(error => {
                    alert('Error creating channel');
                    console.error(error);
                });
        });
    }
});

/* =======================================
   CHANNELS
   ======================================= */

// Load all channels
function loadChannels() {
    const channelsList = document.getElementById('channelsList');

    fetch('../functions/chat_api.php?action=get_channels')
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                displayChannels(data.channels);
            } else {
                console.error('Failed to load channels:', data.message);
                if (channelsList) {
                    channelsList.innerHTML = `
                        <div class="empty-state" style="padding: 28px 16px; color: #dc3545;">
                            <i class="fa fa-exclamation-triangle"></i>
                            <p>Error loading channels</p>
                            <small>${data.message || 'Unknown error'}</small>
                        </div>
                    `;
                }
            }
        })
        .catch(error => {
            console.error('Error loading channels:', error);
            if (channelsList) {
                channelsList.innerHTML = `
                    <div class="empty-state" style="padding: 28px 16px; color: #dc3545;">
                        <i class="fa fa-exclamation-triangle"></i>
                        <p>Connection Error</p>
                        <small>${error.message}</small>
                        <button onclick="loadChannels()" style="margin-top: 12px; padding: 8px 16px; border: 1px solid #dc3545; background: white; color: #dc3545; border-radius: 8px; cursor: pointer;">
                            Retry
                        </button>
                    </div>
                `;
            }
        });
// ...existing code...

// Display channels in sidebar
function displayChannels(channels) {
    const channelsList = document.getElementById('channelsList');
    if (!channelsList) {
        return;
    }

    if (!Array.isArray(channels) || channels.length === 0) {
        channelsCache.clear();
        channelsList.innerHTML = `
            <div class="empty-state" style="padding: 28px 16px;">
                <i class="fa fa-comments"></i>
                <p>No chat activity yet</p>
                <small>Start a direct message to begin the conversation.</small>
            </div>
        `;
        updateUnreadBadges(0);
        return;
    }

    channelsCache.clear();

    const groups = {
        direct: [],
        general: [],
        branch: [],
        role: [],
        other: []
    };

    const totalUnread = channels.reduce((sum, channel) => sum + (Number(channel.unread_count) || 0), 0);
    updateUnreadBadges(totalUnread);

    channels.forEach(channel => {
        const safeType = (channel.type || 'other').toLowerCase();
        const enrichedChannel = { ...channel, type: safeType };
        channelsCache.set(channel.id, enrichedChannel);

        if (groups[safeType]) {
            groups[safeType].push(enrichedChannel);
        } else {
            groups.other.push(enrichedChannel);
        }
    });

    const sections = [
        { key: 'direct', label: 'Direct Messages', icon: 'fa-user-friends' },
        { key: 'general', label: 'Team Channels', icon: 'fa-hashtag' },
        { key: 'branch', label: 'Branch Huddles', icon: 'fa-building' },
        { key: 'role', label: 'Role Groups', icon: 'fa-users' },
        { key: 'other', label: 'Other', icon: 'fa-sitemap' }
    ];

    const iconByType = {
        general: 'fa-hashtag',
        branch: 'fa-building',
        role: 'fa-users',
        other: 'fa-comments'
    };

    let html = '';

    sections.forEach(section => {
        const items = groups[section.key];
        if (!items || items.length === 0) {
            return;
        }

        html += `
            <div class="channel-section" data-section="${section.key}">
                <div class="channel-section-header">
                    <i class="fa ${section.icon}"></i>
                    <span>${section.label}</span>
                </div>
                <div class="channel-section-list">
        `;

        items.forEach(channel => {
            const isActive = currentChannelId === channel.id;
            const unreadCount = Number(channel.unread_count) || 0;
            const classes = ['channel-item'];
            if (isActive) classes.push('active');
            if (unreadCount) classes.push('has-unread');

            const safeName = escapeHtml(channel.name || 'Channel');
            const safeAttrName = escapeAttribute(channel.name || 'Channel');
            const initials = getInitials(channel.name);
            const previewSender = channel.last_sender ? `${channel.last_sender}: ` : '';
            let previewText = channel.last_message ? `${previewSender}${channel.last_message}` : 'No messages yet';
            previewText = previewText.replace(/\s+/g, ' ').trim();
            if (previewText.length > 70) {
                previewText = `${previewText.substring(0, 67)}...`;
            }
            const previewHtml = escapeHtml(previewText);
            const timeLabel = formatRelativeTime(channel.last_activity);

            const avatarContent = channel.type === 'direct'
                ? initials
                : `<i class="fa ${iconByType[channel.type] || iconByType.other}"></i>`;

            const unreadHtml = unreadCount
                ? `<span class="channel-pill">${unreadCount > 99 ? '99+' : unreadCount}</span>`
                : '';

            html += `
                <div class="${classes.join(' ')}" data-channel-id="${channel.id}" data-channel-name="${safeAttrName}" data-channel-type="${channel.type}">
                    <div class="channel-avatar">${avatarContent}</div>
                    <div class="channel-details">
                        <div class="channel-heading">
                            <span class="channel-name">${safeName}</span>
                            ${timeLabel ? `<span class="channel-time">${timeLabel}</span>` : ''}
                        </div>
                        <div class="channel-preview">${previewHtml}</div>
                    </div>
                    ${unreadHtml}
                </div>
            `;
        });

        html += `
                </div>
            </div>
        `;
    });

    channelsList.innerHTML = html || `
        <div class="empty-state" style="padding: 28px 16px;">
            <i class="fa fa-comments"></i>
            <p>No chat channels yet</p>
            <small>Start a direct message to begin the conversation.</small>
        </div>
    `;

    channelsList.querySelectorAll('.channel-item').forEach(item => {
        item.addEventListener('click', () => {
            const channelId = parseInt(item.dataset.channelId, 10);
            if (!channelId) return;
            const cached = channelsCache.get(channelId) || {};
            const datasetName = item.dataset.channelName ? decodeHtml(item.dataset.channelName) : null;
            const channelName = cached.name || datasetName || 'Channel';
            const channelType = cached.type || item.dataset.channelType || 'general';
            selectChannel(channelId, channelName, channelType);
        });
    });

    updateChannelSectionVisibility();

    const searchValue = document.getElementById('channelSearch')?.value || '';
    if (searchValue.trim()) {
        filterChannels();
    }
}

// Select a channel
function selectChannel(channelId, channelName, channelType, options = {}) {
    const cachedChannel = channelsCache.get(channelId) || {};
    const decodedName = channelName !== undefined ? decodeHtml(channelName) : null;
    const resolvedName = decodedName || cachedChannel.name || 'Channel';
    const resolvedType = channelType || cachedChannel.type || 'general';

    currentChannelId = channelId;
    currentChannelData = { name: resolvedName, type: resolvedType };
    pendingScrollMessageId = options.focusMessageId || null;

    // Update header
    let icon = 'fa-comments';
    if (resolvedType === 'direct') icon = 'fa-user';
    else if (resolvedType === 'branch') icon = 'fa-building';
    else if (resolvedType === 'role') icon = 'fa-users';

    let description = 'Team channel';
    if (resolvedType === 'direct') description = 'Direct message';
    else if (resolvedType === 'branch') description = 'Branch channel';
    else if (resolvedType === 'role') description = 'Role channel';

    document.getElementById('chatHeaderInfo').innerHTML = `
        <h3><i class="fa ${icon}"></i> ${escapeHtml(resolvedName)}</h3>
        <p>${description}</p>
    `;

    document.getElementById('chatHeaderActions').style.display = 'flex';

    // Enable input
    document.getElementById('messageInput').disabled = false;
    document.getElementById('messageInput').placeholder = `Message ${resolvedName}...`;
    document.getElementById('sendBtn').disabled = false;
    document.getElementById('attachBtn').disabled = false;

    const muteBtn = document.getElementById('muteBtn');
    if (muteBtn) {
        const isMuted = Boolean(Number(cachedChannel.is_muted));
        const muteBtnIcon = muteBtn.querySelector('i');
        if (muteBtnIcon) {
            muteBtnIcon.className = isMuted ? 'fa fa-bell-slash' : 'fa fa-bell';
        }
        muteBtn.setAttribute('title', isMuted ? 'Unmute channel' : 'Mute channel');
    }

    // Load messages
    loadMessages();
    markAsRead();

    // Close sidebar on mobile after selecting channel
    if (window.innerWidth <= 768) {
        closeSidebar();
    }

    // Start polling for new messages
    if (messagesPollInterval) {
        clearInterval(messagesPollInterval);
    }
    messagesPollInterval = setInterval(loadMessages, 3000);

    // Update active state
    loadChannels();

    // Close sidebar on mobile
    if (window.innerWidth <= 768) {
        document.getElementById('chatSidebar').classList.remove('mobile-open');
    }
}

// Filter channels by search
function filterChannels() {
    const searchInput = document.getElementById('channelSearch');
    if (!searchInput) {
        return;
    }

    const searchTerm = searchInput.value.trim().toLowerCase();
    const channelItems = document.querySelectorAll('.channel-item');

    channelItems.forEach(item => {
        const nameText = item.querySelector('.channel-name')?.textContent.toLowerCase() || '';
        const previewText = item.querySelector('.channel-preview')?.textContent.toLowerCase() || '';
        if (!searchTerm || nameText.includes(searchTerm) || previewText.includes(searchTerm)) {
            item.style.display = 'flex';
        } else {
            item.style.display = 'none';
        }
    });

    updateChannelSectionVisibility();
}

// Start polling for channel updates
function startChannelsPolling() {
    channelsPollInterval = setInterval(loadChannels, 10000); // Every 10 seconds
}

/* =======================================
   MESSAGES
   ======================================= */

// Load messages for current channel
function loadMessages() {
    if (!currentChannelId) {
        console.log('loadMessages: No current channel ID');
        return;
    }

    console.log('loadMessages: Loading messages for channel', currentChannelId);

    fetch(`../functions/chat_api.php?action=get_messages&channel_id=${currentChannelId}`)
        .then(response => response.json())
        .then(data => {
            console.log('loadMessages: Response received', data);
            if (data.success) {
                console.log('loadMessages: Displaying', data.messages.length, 'messages');
                displayMessages(data.messages);
                checkTyping();
            } else {
                console.error('loadMessages: Failed', data.message);
            }
        })
        .catch(error => {
            console.error('Error loading messages:', error);
        });
}

// Display messages
function displayMessages(messages) {
    const chatMessages = document.getElementById('chatMessages');
    // Store messages globally for emoji picker access
    window.lastLoadedMessages = messages;
    console.log('displayMessages: Called with', messages.length, 'messages');
    console.log('displayMessages: chatMessages element:', chatMessages);

    if (messages.length === 0) {
        console.log('displayMessages: No messages, showing empty state');
        chatMessages.innerHTML = `
            <div class="empty-state">
                <i class="fa fa-comment-o"></i>
                <h3>No messages yet</h3>
                <p>Be the first to send a message!</p>
            </div>
        `;
        return;
    }

    // Check if we should scroll to bottom (if user is near bottom or new message)
    const shouldScroll = chatMessages.scrollHeight - chatMessages.scrollTop <= chatMessages.clientHeight + 100;

    let html = '';
    messages.forEach(message => {
        const isOwn = message.user_id === CURRENT_USER_ID;
        const senderName = message.sender_name || 'User';
        const initials = senderName.split(' ').map(word => word[0]).join('').substring(0, 2).toUpperCase();

        // Format timestamp
        const messageDate = new Date(message.created_at);
        const timeStr = messageDate.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });

        // Reactions
        let reactionsHtml = '';
        const reactionSummary = new Map();
        const reactionUsers = new Map();
        const reactionArray = Array.isArray(message.reactions) ? message.reactions : [];

        if (reactionArray.length > 0) {
            reactionArray.forEach(reaction => {
                const emoji = reaction.emoji;
                if (!emoji) return;
                reactionSummary.set(emoji, (reactionSummary.get(emoji) || 0) + 1);
                if (reaction.username) {
                    const list = reactionUsers.get(emoji) || [];
                    list.push(reaction.username);
                    reactionUsers.set(emoji, list);
                }
            });
        } else if (message.reaction_summary && typeof message.reaction_summary === 'object') {
            Object.entries(message.reaction_summary).forEach(([emoji, count]) => {
                if (!emoji) return;
                reactionSummary.set(emoji, count);
            });
        }

        if (reactionSummary.size > 0) {
            reactionsHtml = '<div class="message-reactions">';
            reactionSummary.forEach((count, emoji) => {
                const users = reactionUsers.get(emoji) || [];
                const tooltip = users.length > 0 ? `${users.join(', ')}` : `${count} reaction${count === 1 ? '' : 's'}`;
                // If the current user has reacted, allow them to remove their reaction by clicking or tapping
                const isOwnReaction = typeof CURRENT_USERNAME !== 'undefined' && users.includes(CURRENT_USERNAME);
                const removeHandler = isOwnReaction ? `onclick=\"removeReaction(${message.id}, '${emoji}')\" ontouchstart=\"removeReaction(${message.id}, '${emoji}')\"` : '';
                reactionsHtml += `
                    <span class="reaction-item${isOwnReaction ? ' own-reaction' : ''}" title="${escapeAttribute(tooltip)}" ${removeHandler}>
                        ${emoji} ${count}
                    </span>
                `;
            });
            reactionsHtml += '</div>';
        }

        const messageHtml = escapeHtml(message.message || '').replace(/\n/g, '<br>');
        const dataMessageText = escapeAttribute(message.message || '');

        html += `
            <div class="message-group ${isOwn ? 'own' : ''}" data-message-id="${message.id}" data-is-own="${isOwn}" oncontextmenu="showMessageMenu(event, ${message.id}, ${isOwn})">
                ${!isOwn ? `<div class="message-avatar">${initials}</div>` : ''}
                <div class="message-content">
                    ${!isOwn ? `<div class="message-sender">${escapeHtml(senderName)}</div>` : ''}
                    <div class="message-bubble" data-message-text="${dataMessageText}">
                        <p class="message-text">${messageHtml}</p>
                        <div class="message-time">
                            ${timeStr}
                            ${message.is_edited ? '<span class="message-edited">(edited)</span>' : ''}
                        </div>
                        ${isOwn ? `
                            <div class="message-actions">
                                <button class="action-btn" onclick="startEditMessage(${message.id}); event.stopPropagation();" title="Edit">
                                    <i class="fa fa-edit"></i>
                                </button>
                                <button class="action-btn" onclick="deleteMessage(${message.id}); event.stopPropagation();" title="Delete">
                                    <i class="fa fa-trash"></i>
                                </button>
                                <button class="action-btn" onclick="showReactionPicker(${message.id}); event.stopPropagation();" title="React">
                                    <i class="fa fa-smile-o"></i>
                                </button>
                            </div>
                        ` : `
                            <div class="message-actions">
                                <button class="action-btn" onclick="showReactionPicker(${message.id}); event.stopPropagation();" title="React">
                                    <i class="fa fa-smile-o"></i>
                                </button>
                            </div>
                        `}
                    </div>
                    ${reactionsHtml}
                </div>
            </div>
        `;
    });

    chatMessages.innerHTML = html;

    const newestId = messages[messages.length - 1]?.id;
    const hasNewMessage = newestId && newestId !== lastMessageId;

    if (pendingScrollMessageId) {
        scrollToMessage(pendingScrollMessageId);
        pendingScrollMessageId = null;
    } else if (shouldScroll || hasNewMessage) {
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }

    if (newestId) {
        lastMessageId = newestId;
    }
}

// Send message
function postNewMessage(message) {
    if (!message || !currentChannelId) {
        return Promise.resolve();
    }

    return fetch('../functions/chat_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=send_message&channel_id=${currentChannelId}&message=${encodeURIComponent(message)}`
    })
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                throw new Error(data.message || 'Failed to send message');
            }
            loadMessages();
            loadChannels();
        });
}

// Handle Enter key to send
function handleKeyPress(event) {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        sendMessage();
    }
}

// Mark channel as read
function markAsRead() {
    if (!currentChannelId) return;

    fetch('../functions/chat_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=mark_read&channel_id=${currentChannelId}`
    })
        .then(() => loadChannels())
        .catch(error => console.error('Error marking as read:', error));
}

/* =======================================
   TYPING INDICATOR
   ======================================= */

// Handle typing
function handleTyping() {
    if (!currentChannelId) return;

    // Clear existing timeout
    if (typingTimeout) {
        clearTimeout(typingTimeout);
    }

    // Set typing
    fetch('../functions/chat_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=set_typing&channel_id=${currentChannelId}&is_typing=1`
    });

    // Clear typing after 3 seconds
    typingTimeout = setTimeout(() => {
        fetch('../functions/chat_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=set_typing&channel_id=${currentChannelId}&is_typing=0`
        });
    }, 3000);
}

function clearTypingState() {
    if (!currentChannelId) return;

    if (typingTimeout) {
        clearTimeout(typingTimeout);
        typingTimeout = null;
    }

    fetch('../functions/chat_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=set_typing&channel_id=${currentChannelId}&is_typing=0`
    }).catch(() => {
        // Ignore typing clear errors silently
    });
}

// Check who is typing
function checkTyping() {
    if (!currentChannelId) return;

    fetch(`../functions/chat_api.php?action=get_typing&channel_id=${currentChannelId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && Array.isArray(data.typing_users) && data.typing_users.length > 0) {
                const typingUsers = data.typing_users;
                const typingText = typingUsers.length === 1
                    ? `${typingUsers[0]} is typing`
                    : `${typingUsers.join(', ')} are typing`;

                document.getElementById('typingText').textContent = typingText;
                document.getElementById('typingIndicator').style.display = 'block';
            } else {
                document.getElementById('typingIndicator').style.display = 'none';
            }
        })
        .catch(error => console.error('Error checking typing:', error));
}

/* =======================================
   NEW CHAT / DIRECT MESSAGES
   ======================================= */

// Open new chat modal
function openNewChatModal() {
    document.getElementById('newChatModal').style.display = 'flex';
}

// Close new chat modal
function closeNewChatModal() {
    document.getElementById('newChatModal').style.display = 'none';
}

// Load users for direct message
function loadUsersForDM() {
    fetch('../functions/chat_channels_api.php?action=get_users_for_dm')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayUsers(data.users);
            }
        })
        .catch(error => {
            console.error('Error loading users:', error);
        });
}

// Display users in modal
function displayUsers(users) {
    const usersList = document.getElementById('usersList');

    if (users.length === 0) {
        usersList.innerHTML = '<p style="text-align: center; color: #6c757d;">No other users found</p>';
        return;
    }

    let html = '';
    users.forEach(user => {
        const initials = user.username.split(' ').map(word => word[0]).join('').substring(0, 2).toUpperCase();

        html += `
            <div class="user-item" onclick="createDirectMessage(${user.user_id}, '${escapeHtml(user.username)}')">
                <div class="user-avatar">${initials}</div>
                <div class="user-info">
                    <div class="user-name">${escapeHtml(user.username)}</div>
                    <div class="user-role">${escapeHtml(user.role || 'User')}</div>
                </div>
            </div>
        `;
    });

    usersList.innerHTML = html;
}

// Filter users by search
function filterUsers() {
    const searchTerm = document.getElementById('userSearch').value.toLowerCase();
    const userItems = document.querySelectorAll('.user-item');

    userItems.forEach(item => {
        const userName = item.querySelector('.user-name').textContent.toLowerCase();
        if (userName.includes(searchTerm)) {
            item.style.display = 'flex';
        } else {
            item.style.display = 'none';
        }
    });
}

// Create direct message channel
function createDirectMessage(userId, username) {
    fetch('../functions/chat_channels_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=create_direct_channel&other_user_id=${userId}`
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                closeNewChatModal();
                loadChannels();
                // Select the new/existing channel
                setTimeout(() => {
                    selectChannel(data.channel_id, username, 'direct');
                }, 500);
            } else {
                alert('Failed to create direct message: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error creating direct message:', error);
            alert('Error creating direct message');
        });
}

/* =======================================
   CREATE CHANNEL
   ======================================= */

// Open create channel modal
function openCreateChannelModal() {
    document.getElementById('createChannelModal').style.display = 'flex';
}

// Close create channel modal
function closeCreateChannelModal() {
    document.getElementById('createChannelModal').style.display = 'none';
}

/* =======================================
   UTILITY FUNCTIONS
   ======================================= */

// Toggle mobile sidebar
function toggleSidebar() {
    const sidebar = document.getElementById('chatSidebar');
    sidebar.classList.toggle('mobile-open');
}

// Close mobile sidebar
function closeSidebar() {
    const sidebar = document.getElementById('chatSidebar');
    if (sidebar) {
        sidebar.classList.remove('mobile-open');
    }
}// Mute/unmute channel
function toggleMuteChannel() {
    if (!currentChannelId) return;

    fetch('../functions/chat_channels_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=mute_channel&channel_id=${currentChannelId}`
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const icon = document.querySelector('#muteBtn i');
                icon.className = data.is_muted ? 'fa fa-bell-slash' : 'fa fa-bell';
                const cached = channelsCache.get(currentChannelId);
                if (cached) {
                    cached.is_muted = data.is_muted;
                }
                loadChannels();
            }
        })
        .catch(error => console.error('Error toggling mute:', error));
}

// Toggle channel info
function toggleChannelInfo() {
    showChannelMembers();
}

// Show channel members
function showChannelMembers() {
    if (!currentChannelId) return;

    fetch(`../functions/chat_api.php?action=get_members&channel_id=${currentChannelId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayChannelMembers(data.members);
            }
        })
        .catch(error => console.error('Error loading members:', error));
}

// Display channel members in modal
function displayChannelMembers(members) {
    const modal = document.createElement('div');
    modal.className = 'modal-overlay';
    modal.id = 'channelInfoModal';

    let membersHtml = '';
    members.forEach(member => {
        const initials = member.username.split(' ').map(w => w[0]).join('').substring(0, 2).toUpperCase();
        const roleColor = member.role === 'owner' ? '#fd2b2b' : member.role === 'admin' ? '#ff9800' : '#6c757d';

        membersHtml += `
            <div class="member-item">
                <div class="user-avatar" style="background: linear-gradient(135deg, ${roleColor} 0%, ${roleColor}cc 100%);">${initials}</div>
                <div class="user-info">
                    <div class="user-name">${escapeHtml(member.username)}</div>
                    <div class="user-role" style="color: ${roleColor};">
                        <i class="fa fa-shield"></i> ${member.role.charAt(0).toUpperCase() + member.role.slice(1)}
                    </div>
                </div>
            </div>
        `;
    });

    modal.innerHTML = `
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fa fa-users"></i> Channel Members (${members.length})</h3>
                <button class="modal-close" onclick="closeChannelInfo()">
                    <i class="fa fa-times"></i>
                </button>
            </div>
            <div class="member-list">
                ${membersHtml}
            </div>
        </div>
    `;

    document.body.appendChild(modal);
}

// Close channel info modal
function closeChannelInfo() {
    const modal = document.getElementById('channelInfoModal');
    if (modal) modal.remove();
}

/* =======================================
   MESSAGE SEARCH
   ======================================= */

let searchTimeout = null;

// Toggle search panel
function toggleSearch() {
    const panel = document.getElementById('searchPanel');
    const input = document.getElementById('searchInput');

    if (panel.style.display === 'none') {
        panel.style.display = 'block';
        input.focus();
    } else {
        panel.style.display = 'none';
        input.value = '';
        document.getElementById('searchResults').innerHTML = '';
    }
}

// Search messages
function searchMessages() {
    const query = document.getElementById('searchInput').value.trim();
    const resultsDiv = document.getElementById('searchResults');

    if (!query) {
        resultsDiv.innerHTML = '';
        return;
    }

    // Debounce search
    if (searchTimeout) clearTimeout(searchTimeout);

    searchTimeout = setTimeout(() => {
        resultsDiv.innerHTML = '<div class="loading"><i class="fa fa-spinner fa-spin"></i> Searching...</div>';

        const channelParam = currentChannelId ? `&channel_id=${currentChannelId}` : '';
        fetch(`../functions/chat_api.php?action=search_messages&search=${encodeURIComponent(query)}${channelParam}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displaySearchResults(data.results || []);
                } else {
                    resultsDiv.innerHTML = '<div class="no-results">No messages found</div>';
                }
            })
            .catch(error => {
                console.error('Search error:', error);
                resultsDiv.innerHTML = '<div class="error">Search failed</div>';
            });
    }, 500);
}

// Display search results
function displaySearchResults(messages) {
    const resultsDiv = document.getElementById('searchResults');

    if (messages.length === 0) {
        resultsDiv.innerHTML = '<div class="no-results"><i class="fa fa-search"></i> No messages found</div>';
        return;
    }

    let html = '';
    messages.forEach(msg => {
        const date = new Date(msg.created_at);
        const timeStr = date.toLocaleDateString() + ' ' + date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
        const channelNameAttr = escapeAttribute(msg.channel_name || 'Channel');
        const channelTypeAttr = escapeAttribute(msg.channel_type || 'general');

        html += `
            <div class="search-result-item" data-channel-id="${msg.channel_id}" data-channel-name="${channelNameAttr}" data-channel-type="${channelTypeAttr}" data-message-id="${msg.id}">
                <div class="result-header">
                    <strong>${escapeHtml(msg.sender_name)}</strong>
                    <span class="result-time">${timeStr}</span>
                </div>
                <div class="result-message">${escapeHtml(msg.message)}</div>
                <div class="result-channel">
                    <i class="fa fa-comments"></i> ${escapeHtml(msg.channel_name || 'Unknown')}
                </div>
            </div>
        `;
    });

    resultsDiv.innerHTML = html;

    resultsDiv.querySelectorAll('.search-result-item').forEach(item => {
        item.addEventListener('click', () => {
            const channelId = parseInt(item.dataset.channelId, 10);
            const messageId = parseInt(item.dataset.messageId, 10);
            const channelName = item.dataset.channelName ? decodeHtml(item.dataset.channelName) : 'Channel';
            const channelType = item.dataset.channelType || 'general';
            jumpToMessage(channelId, channelName, channelType, messageId);
        });
    });
}

// Jump to a message (open channel and scroll to message)
function jumpToMessage(channelId, channelName, channelType, messageId) {
    if (!channelId || !messageId) {
        return;
    }

    if (channelId === currentChannelId) {
        const chatMessages = document.getElementById('chatMessages');
        const existing = chatMessages.querySelector(`.message-group[data-message-id="${messageId}"]`);
        if (existing) {
            scrollToMessage(messageId);
            toggleSearch();
            return;
        }
        pendingScrollMessageId = messageId;
        loadMessages();
        toggleSearch();
        return;
    }

    pendingScrollMessageId = messageId;
    selectChannel(channelId, channelName, channelType, { focusMessageId: messageId });
    toggleSearch();
}

// Attach file
function attachFile() {
    document.getElementById('fileInput').click();
}

// Handle file selection
function handleFileSelect(event) {
    const file = event.target.files[0];
    if (!file) return;

    // File upload coming in Phase 4
    alert('File upload feature coming soon!');
}

// Escape HTML to prevent XSS
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function escapeAttribute(text) {
    if (text === null || text === undefined) {
        return '';
    }
    return String(text)
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

function decodeHtml(text) {
    if (text === null || text === undefined) {
        return '';
    }
    const div = document.createElement('div');
    div.innerHTML = text;
    return div.textContent || div.innerText || '';
}

function getInitials(name) {
    if (!name) {
        return 'CH';
    }
    const parts = name.trim().split(/\s+/).filter(Boolean);
    if (parts.length === 0) {
        return 'CH';
    }
    if (parts.length === 1) {
        return parts[0].substring(0, 2).toUpperCase();
    }
    return (parts[0][0] + parts[1][0]).toUpperCase();
}

function formatRelativeTime(timestamp) {
    if (!timestamp) {
        return '';
    }
    const date = new Date(timestamp);
    if (Number.isNaN(date.getTime())) {
        return '';
    }

    const now = new Date();
    const diffMs = now - date;

    if (diffMs < 60000) {
        return 'Just now';
    }
    if (diffMs < 3600000) {
        const mins = Math.round(diffMs / 60000);
        return `${mins}m ago`;
    }
    if (diffMs < 86400000) {
        const hours = Math.round(diffMs / 3600000);
        return `${hours}h ago`;
    }
    if (diffMs < 172800000) {
        return 'Yesterday';
    }
    if (diffMs < 604800000) {
        return date.toLocaleDateString('en-GB', { weekday: 'short' });
    }
    return date.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
}

function updateUnreadBadges(total) {
    const heroUnread = document.getElementById('heroUnreadCount');
    if (heroUnread) {
        heroUnread.textContent = total;
    }
    const sidebarUnread = document.getElementById('sidebarUnreadCount');
    if (sidebarUnread) {
        sidebarUnread.textContent = total;
    }
}

function updateChannelSectionVisibility() {
    document.querySelectorAll('.channel-section').forEach(section => {
        const visible = Array.from(section.querySelectorAll('.channel-item')).some(item => item.style.display !== 'none');
        section.style.display = visible ? '' : 'none';
    });
}

/* =======================================
   MESSAGE ACTIONS
   ======================================= */

// Show context menu for message
function showMessageMenu(event, messageId, isOwn) {
    event.preventDefault();
    contextMenuMessage = messageId;

    // Remove existing menu if any
    const existingMenu = document.getElementById('messageContextMenu');
    if (existingMenu) existingMenu.remove();

    const menu = document.createElement('div');
    menu.id = 'messageContextMenu';
    menu.className = 'context-menu';
    menu.style.left = event.pageX + 'px';
    menu.style.top = event.pageY + 'px';

    let menuItems = '';
    if (isOwn) {
        menuItems = `
            <div class="context-menu-item" onclick="startEditMessage(${messageId}); closeContextMenu();">
                <i class="fa fa-edit"></i> Edit Message
            </div>
            <div class="context-menu-item" onclick="deleteMessage(${messageId}); closeContextMenu();">
                <i class="fa fa-trash"></i> Delete Message
            </div>
        `;
    }
    menuItems += `
        <div class="context-menu-item" onclick="showReactionPicker(${messageId}); closeContextMenu();">
            <i class="fa fa-smile-o"></i> Add Reaction
        </div>
    `;

    menu.innerHTML = menuItems;
    document.body.appendChild(menu);

    // Close menu when clicking elsewhere
    setTimeout(() => {
        document.addEventListener('click', closeContextMenu);
    }, 100);

    return false;
}

// Close context menu
function closeContextMenu() {
    const menu = document.getElementById('messageContextMenu');
    if (menu) menu.remove();
    document.removeEventListener('click', closeContextMenu);
}

function scrollToMessage(messageId) {
    const chatMessages = document.getElementById('chatMessages');
    const target = chatMessages.querySelector(`.message-group[data-message-id="${messageId}"]`);
    if (!target) {
        return;
    }

    target.classList.add('highlight');
    target.scrollIntoView({ behavior: 'smooth', block: 'center' });

    setTimeout(() => {
        target.classList.remove('highlight');
    }, 2000);
}

// Start editing a message
function startEditMessage(messageId) {
    const messageBubble = document.querySelector(`[data-message-id="${messageId}"] .message-bubble`);
    if (!messageBubble) {
        return;
    }

    editingMessageId = messageId;
    const input = document.getElementById('messageInput');
    const sendBtn = document.getElementById('sendBtn');
    const cancelBtnExisting = document.getElementById('cancelEditBtn');
    const editingIndicator = document.getElementById('editingIndicator');

    const rawText = messageBubble.getAttribute('data-message-text') || '';
    input.value = rawText;
    input.disabled = false;
    input.classList.add('editing');
    input.focus();
    input.setSelectionRange(input.value.length, input.value.length);

    if (editingIndicator) {
        editingIndicator.innerHTML = '<i class="fa fa-edit"></i> Editing message';
        editingIndicator.style.display = 'flex';
    }

    sendBtn.innerHTML = '<i class="fa fa-check"></i>';
    sendBtn.style.background = 'linear-gradient(135deg, #28a745 0%, #20c997 100%)';

    if (!cancelBtnExisting) {
        const cancelBtn = document.createElement('button');
        cancelBtn.id = 'cancelEditBtn';
        cancelBtn.className = 'btn-send';
        cancelBtn.style.background = '#6c757d';
        cancelBtn.innerHTML = '<i class="fa fa-times"></i>';
        cancelBtn.addEventListener('click', cancelEditMessage);
        sendBtn.parentNode.insertBefore(cancelBtn, sendBtn);
    }
}

// Cancel editing
function cancelEditMessage() {
    editingMessageId = null;
    const input = document.getElementById('messageInput');
    const sendBtn = document.getElementById('sendBtn');
    const cancelBtn = document.getElementById('cancelEditBtn');
    const editingIndicator = document.getElementById('editingIndicator');

    input.value = '';
    input.classList.remove('editing');
    input.style.height = 'auto';
    sendBtn.innerHTML = '<i class="fa fa-paper-plane"></i>';
    sendBtn.style.background = 'linear-gradient(135deg, #fd2b2b 0%, #c82333 100%)';

    if (currentChannelData && currentChannelData.name) {
        input.placeholder = `Message ${currentChannelData.name}...`;
    }

    if (cancelBtn) cancelBtn.remove();
    if (editingIndicator) {
        editingIndicator.style.display = 'none';
        editingIndicator.innerHTML = '';
    }
}

// Edit message (called from sendMessage when editing)
function submitEditedMessage(messageId, newText) {
    return fetch('../functions/chat_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=edit_message&message_id=${messageId}&message=${encodeURIComponent(newText)}`
    })
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                throw new Error(data.message || 'Failed to edit message');
            }
            loadMessages();
        });
}

// Delete message with confirmation
function deleteMessage(messageId) {
    if (!confirm('Delete this message? This cannot be undone.')) return;

    fetch('../functions/chat_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=delete_message&message_id=${messageId}`
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                loadMessages();
                loadChannels();
            } else {
                alert('Failed to delete message: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error deleting message:', error);
            alert('Error deleting message');
        });
}

/* =======================================
   EMOJI REACTIONS
   ======================================= */

const commonEmojis = ['👍', '❤️', '😂', '😮', '😢', '😡', '🎉', '🔥'];

// Show emoji reaction picker
function showReactionPicker(messageId) {
    // Remove existing picker if any
    const existingPicker = document.getElementById('reactionPicker');
    if (existingPicker) existingPicker.remove();

    const picker = document.createElement('div');
    picker.id = 'reactionPicker';
    picker.className = 'emoji-picker';

    const messageBubble = document.querySelector(`[data-message-id="${messageId}"] .message-bubble`);
    if (!messageBubble) return;

    const rect = messageBubble.getBoundingClientRect();
    picker.style.left = rect.left + 'px';
    picker.style.top = (rect.bottom + 5) + 'px';

    let html = '<div class="emoji-grid">';
    // Find the user's reaction for this message (for mobile)
    const messageObj = window.lastLoadedMessages?.find(m => m.id === messageId);
    let userReactionEmoji = null;
    if (window.innerWidth <= 600 && messageObj && Array.isArray(messageObj.reactions)) {
        const userReaction = messageObj.reactions.find(r => r.username === CURRENT_USERNAME);
        if (userReaction) {
            userReactionEmoji = userReaction.emoji;
        }
    }
    commonEmojis.forEach(emoji => {
        if (userReactionEmoji === emoji) {
            html += `<span class="emoji-option user-reacted">${emoji}<span class='emoji-delete-icon' onclick="removeReaction(${messageId}, '${emoji}')" title='Delete'><i class='fa fa-trash'></i></span></span>`;
        } else {
            html += `<span class="emoji-option" onclick="addReaction(${messageId}, '${emoji}')">${emoji}</span>`;
        }
    });
    html += '</div>';

    picker.innerHTML = html;
    document.body.appendChild(picker);

    // Close picker when clicking elsewhere
    setTimeout(() => {
        document.addEventListener('click', closeReactionPicker);
    }, 100);
}

// Close reaction picker
function closeReactionPicker() {
    const picker = document.getElementById('reactionPicker');
    if (picker) picker.remove();
    document.removeEventListener('click', closeReactionPicker);
}

// Add reaction to message
function addReaction(messageId, emoji) {
    closeReactionPicker();
    fetch('../functions/chat_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=add_reaction&message_id=${messageId}&emoji=${encodeURIComponent(emoji)}`
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                loadMessages();
            } else {
                console.error('Failed to add reaction:', data.message);
            }
        })
        .catch(error => {
            console.error('Error adding reaction:', error);
        });
}

// Remove reaction from message
function removeReaction(messageId, emoji) {
    console.log('removeReaction called', { messageId, emoji });
    fetch('../functions/chat_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=remove_reaction&message_id=${messageId}&emoji=${encodeURIComponent(emoji)}`
    })
        .then(response => response.json())
        .then(data => {
            console.log('removeReaction response', data);
            if (data.success) {
                loadMessages();
            } else {
                console.error('Failed to remove reaction:', data.message);
            }
        })
        .catch(error => {
            console.error('Error removing reaction:', error);
        });
}

function sendMessage() {
    const input = document.getElementById('messageInput');
    const messageText = input.value.trim();

    if (!messageText || !currentChannelId) {
        return;
    }

    const isEditing = Boolean(editingMessageId);
    const request = isEditing
        ? submitEditedMessage(editingMessageId, messageText)
        : postNewMessage(messageText);

    request
        .then(() => {
            clearTypingState();
            if (isEditing) {
                cancelEditMessage();
            } else {
                input.value = '';
                input.style.height = 'auto';
            }
        })
        .catch(error => {
            console.error('Error sending message:', error);
            alert(error.message || 'Error sending message');
        })
        .finally(() => {
            if (!isEditing) {
                input.placeholder = currentChannelData && currentChannelData.name
                    ? `Message ${currentChannelData.name}...`
                    : 'Type a message...';
            }
            input.focus();
        });
}

// Cleanup on page unload
window.addEventListener('beforeunload', function () {
    if (messagesPollInterval) clearInterval(messagesPollInterval);
    if (channelsPollInterval) clearInterval(channelsPollInterval);
    if (typingTimeout) clearTimeout(typingTimeout);
});

/* =======================================
   NAVIGATION & NOTIFICATIONS
   ======================================= */

// Toggle notification dropdown
const notificationIcon = document.getElementById('notification-icon');
const notificationDropdown = document.getElementById('notification-dropdown');
const menuToggle = document.getElementById('menu-toggle');
const navLinks = document.getElementById('nav-links');

if (notificationIcon && notificationDropdown) {
    notificationIcon.addEventListener('click', function (e) {
        e.stopPropagation();
        notificationDropdown.style.display =
            notificationDropdown.style.display === 'block' ? 'none' : 'block';
    });

    document.addEventListener('click', function (e) {
        if (!notificationDropdown.contains(e.target) && e.target !== notificationIcon) {
            notificationDropdown.style.display = 'none';
        }
    });
}

// Toggle mobile menu
if (menuToggle && navLinks) {
    menuToggle.addEventListener('click', function (e) {
        e.stopPropagation();
        navLinks.classList.toggle('show');
    });

    document.addEventListener('click', function (e) {
        if (!navLinks.contains(e.target) && e.target !== menuToggle) {
            navLinks.classList.remove('show');
        }
    });
}

// Mark notification as read
function markNotificationAsRead(element) {
    const notificationId = element.getAttribute('data-id');

    fetch('../functions/mark_notification.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'notification_id=' + notificationId
    })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                element.remove();
                updateNotificationBadge();
            }
        })
        .catch(error => console.error('Error marking notification as read:', error));
}

// Update notification badge
function updateNotificationBadge() {
    const dropdown = document.getElementById('notification-dropdown');
    if (!dropdown) return;

    const items = dropdown.querySelectorAll('.notification-item:not(.no-notifications)');
    const badge = document.querySelector('.notification-badge');

    if (items.length === 0) {
        dropdown.innerHTML = '<div class="notification-item"><p>No notifications</p></div>';
        if (badge) badge.remove();
    } else if (badge) {
        badge.textContent = items.length;
    }
}

// Admin: Edit/Delete Channel (UI logic placeholder)
function openEditChannelModal(channelId) {
    // Fetch channel info and members
    const channel = channelsCache.get(channelId);
    if (!channel) return;
    fetch(`../functions/chat_channels_api.php?action=get_members&channel_id=${channelId}`)
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                alert('Failed to load channel members');
                return;
            }
            showEditChannelModal(channel, data.members);
        });
}

function showEditChannelModal(channel, members) {
    const modal = document.getElementById('editChannelModal');
    const content = document.getElementById('editChannelModalContent');
    if (!modal || !content) return;

    // Build member list UI
    let membersHtml = '';
    members.forEach(member => {
        membersHtml += `
            <div class="member-item">
                <div class="user-avatar">${getInitials(member.username)}</div>
                <div class="user-info">
                    <div class="user-name">${escapeHtml(member.username)}</div>
                    <div class="user-role">${escapeHtml(member.channel_role)}</div>
                </div>
                ${member.channel_role !== 'owner' ? `<button class="btn-icon" title="Remove" onclick="removeChannelMember(${channel.id}, ${member.id}, this)"><i class="fa fa-user-times"></i></button>` : ''}
            </div>
        `;
    });

    // Modal HTML
    content.innerHTML = `
        <div class="modal-header">
            <h3><i class="fa fa-edit"></i> Edit Channel</h3>
            <button class="modal-close" onclick="closeEditChannelModal()"><i class="fa fa-times"></i></button>
        </div>
        <form id="editChannelForm" onsubmit="submitEditChannel(event, ${channel.id})">
            <div class="form-group">
                <label for="editChannelName">Channel Name</label>
                <input type="text" id="editChannelName" name="editChannelName" required maxlength="40" value="${escapeAttribute(channel.name)}">
            </div>
            <div class="form-group">
                <label for="editChannelDescription">Description</label>
                <input type="text" id="editChannelDescription" name="editChannelDescription" maxlength="100" value="${escapeAttribute(channel.description || '')}">
            </div>
            <div class="form-group">
                <button type="submit" class="chat-hero-btn" style="width:100%"><i class="fa fa-save"></i> Save Changes</button>
            </div>
        </form>
        <hr>
        <h4>Members</h4>
        <div class="member-list">${membersHtml}</div>
        <div class="form-group">
            <button class="chat-hero-btn" style="background:#fd2b2b;color:#fff;width:100%" onclick="deleteChannel(${channel.id})"><i class="fa fa-trash"></i> Delete Channel</button>
        </div>
        <div class="form-group">
            <label for="addMemberInput">Add Member (branch only)</label>
            <input type="text" id="addMemberInput" placeholder="Type username..." onkeyup="searchAddMemberByBranch(event, ${channel.id}, ${channel.branch_id})">
            <div id="addMemberResults"></div>
        </div>
    `;
    modal.style.display = 'flex';
}

// Only show users from the same branch for add member
function searchAddMemberByBranch(e, channelId, branchId) {
    const q = e.target.value.trim();
    const resultsDiv = document.getElementById('addMemberResults');
    if (!q || q.length < 2) {
        resultsDiv.innerHTML = '';
        return;
    }
    fetch(`../functions/get_users_by_branch.php?branch_id=${branchId}`)
        .then(response => response.json())
        .then(users => {
            const filtered = users.filter(u => u.username.toLowerCase().includes(q.toLowerCase()));
            if (!Array.isArray(filtered) || filtered.length === 0) {
                resultsDiv.innerHTML = '<div style="padding:8px;color:#888">No users found</div>';
                return;
            }
            resultsDiv.innerHTML = filtered.map(u => `<div class="user-item" onclick="addChannelMember(${channelId}, ${u.id}, this)">${escapeHtml(u.username)}</div>`).join('');
        });
}
function closeEditChannelModal() {
    const modal = document.getElementById('editChannelModal');
    if (modal) modal.style.display = 'none';
}
function deleteChannel(channelId) {
    if (!confirm('Are you sure you want to delete this channel?')) return;
    fetch('../functions/chat_channels_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=delete_channel&channel_id=${encodeURIComponent(channelId)}`
    })
        .then(response => response.json())
        .then data => {
            if (data.success) {
                loadChannels();
            } else {
                alert('Failed to delete channel: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(error => {
            alert('Error deleting channel');
            console.error(error);
        });
}
