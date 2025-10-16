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

// Initialize chat on page load
document.addEventListener('DOMContentLoaded', function() {
    loadChannels();
    startChannelsPolling();
    
    // Auto-resize textarea
    const messageInput = document.getElementById('messageInput');
    messageInput.addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = (this.scrollHeight) + 'px';
    });
});

/* =======================================
   CHANNELS
   ======================================= */

// Load all channels
function loadChannels() {
    fetch('../functions/chat_api.php?action=get_channels')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayChannels(data.channels);
            } else {
                console.error('Failed to load channels:', data.message);
            }
        })
        .catch(error => {
            console.error('Error loading channels:', error);
        });
}

// Display channels in sidebar
function displayChannels(channels) {
    const channelsList = document.getElementById('channelsList');
    
    if (channels.length === 0) {
        channelsList.innerHTML = `
            <div class="empty-state" style="padding: 20px;">
                <p style="font-size: 0.9rem; color: #6c757d;">No channels yet. Click + to start a direct message!</p>
            </div>
        `;
        return;
    }
    
    let html = '';
    channels.forEach(channel => {
        const isActive = currentChannelId === channel.id;
        const unreadBadge = channel.unread_count > 0 ? 
            `<span class="channel-badge">${channel.unread_count}</span>` : '';
        
        // Get channel icon
        let icon = 'fa-comments';
        if (channel.type === 'direct') icon = 'fa-user';
        else if (channel.type === 'branch') icon = 'fa-building';
        else if (channel.type === 'role') icon = 'fa-users';
        
        // Get channel initials for avatar
        const initials = channel.name.split(' ').map(word => word[0]).join('').substring(0, 2).toUpperCase();
        
        // Format last message
        let lastMessage = 'No messages yet';
        if (channel.last_message) {
            lastMessage = channel.last_message;
            if (lastMessage.length > 40) {
                lastMessage = lastMessage.substring(0, 40) + '...';
            }
        }
        
        html += `
            <div class="channel-item ${isActive ? 'active' : ''}" onclick="selectChannel(${channel.id}, '${channel.name}', '${channel.type}')">
                <div class="channel-icon">
                    ${channel.type === 'direct' ? initials : `<i class="fa ${icon}"></i>`}
                </div>
                <div class="channel-info">
                    <div class="channel-name">${escapeHtml(channel.name)}</div>
                    <div class="channel-last-message">${escapeHtml(lastMessage)}</div>
                </div>
                ${unreadBadge}
            </div>
        `;
    });
    
    channelsList.innerHTML = html;
}

// Select a channel
function selectChannel(channelId, channelName, channelType) {
    currentChannelId = channelId;
    currentChannelData = { name: channelName, type: channelType };
    
    // Update header
    let icon = 'fa-comments';
    if (channelType === 'direct') icon = 'fa-user';
    else if (channelType === 'branch') icon = 'fa-building';
    else if (channelType === 'role') icon = 'fa-users';
    
    let description = 'Team channel';
    if (channelType === 'direct') description = 'Direct message';
    else if (channelType === 'branch') description = 'Branch channel';
    else if (channelType === 'role') description = 'Role channel';
    
    document.getElementById('chatHeaderInfo').innerHTML = `
        <h3><i class="fa ${icon}"></i> ${escapeHtml(channelName)}</h3>
        <p>${description}</p>
    `;
    
    document.getElementById('chatHeaderActions').style.display = 'flex';
    
    // Enable input
    document.getElementById('messageInput').disabled = false;
    document.getElementById('messageInput').placeholder = `Message ${channelName}...`;
    document.getElementById('sendBtn').disabled = false;
    document.getElementById('attachBtn').disabled = false;
    
    // Load messages
    loadMessages();
    markAsRead();
    
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
    const searchTerm = document.getElementById('channelSearch').value.toLowerCase();
    const channelItems = document.querySelectorAll('.channel-item');
    
    channelItems.forEach(item => {
        const channelName = item.querySelector('.channel-name').textContent.toLowerCase();
        if (channelName.includes(searchTerm)) {
            item.style.display = 'flex';
        } else {
            item.style.display = 'none';
        }
    });
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
    if (!currentChannelId) return;
    
    fetch(`../functions/chat_api.php?action=get_messages&channel_id=${currentChannelId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayMessages(data.messages);
                checkTyping();
            }
        })
        .catch(error => {
            console.error('Error loading messages:', error);
        });
}

// Display messages
function displayMessages(messages) {
    const chatMessages = document.getElementById('chatMessages');
    
    if (messages.length === 0) {
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
        const initials = message.sender_name.split(' ').map(word => word[0]).join('').substring(0, 2).toUpperCase();
        
        // Format timestamp
        const messageDate = new Date(message.created_at);
        const timeStr = messageDate.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
        
        // Reactions
        let reactionsHtml = '';
        if (message.reactions && message.reactions.length > 0) {
            reactionsHtml = '<div class="message-reactions">';
            const reactionGroups = {};
            message.reactions.forEach(r => {
                if (!reactionGroups[r.emoji]) {
                    reactionGroups[r.emoji] = { count: 0, users: [] };
                }
                reactionGroups[r.emoji].count++;
                reactionGroups[r.emoji].users.push(r.username);
            });
            
            Object.keys(reactionGroups).forEach(emoji => {
                const group = reactionGroups[emoji];
                reactionsHtml += `
                    <span class="reaction-item" title="${group.users.join(', ')}">
                        ${emoji} ${group.count}
                    </span>
                `;
            });
            reactionsHtml += '</div>';
        }
        
        html += `
            <div class="message-group ${isOwn ? 'own' : ''}">
                ${!isOwn ? `<div class="message-avatar">${initials}</div>` : ''}
                <div class="message-content">
                    ${!isOwn ? `<div class="message-sender">${escapeHtml(message.sender_name)}</div>` : ''}
                    <div class="message-bubble">
                        <p class="message-text">${escapeHtml(message.message)}</p>
                        <div class="message-time">
                            ${timeStr}
                            ${message.is_edited ? '<span class="message-edited">(edited)</span>' : ''}
                        </div>
                    </div>
                    ${reactionsHtml}
                </div>
            </div>
        `;
    });
    
    chatMessages.innerHTML = html;
    
    // Scroll to bottom if needed
    if (shouldScroll || lastMessageId !== messages[messages.length - 1]?.id) {
        chatMessages.scrollTop = chatMessages.scrollHeight;
        lastMessageId = messages[messages.length - 1]?.id;
    }
}

// Send message
function sendMessage() {
    const input = document.getElementById('messageInput');
    const message = input.value.trim();
    
    if (!message || !currentChannelId) return;
    
    fetch('../functions/chat_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=send_message&channel_id=${currentChannelId}&message=${encodeURIComponent(message)}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            input.value = '';
            input.style.height = 'auto';
            loadMessages();
            loadChannels();
        } else {
            alert('Failed to send message: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error sending message:', error);
        alert('Error sending message');
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

// Check who is typing
function checkTyping() {
    if (!currentChannelId) return;
    
    fetch(`../functions/chat_api.php?action=get_typing&channel_id=${currentChannelId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.typing.length > 0) {
                const typingText = data.typing.length === 1 
                    ? `${data.typing[0]} is typing` 
                    : `${data.typing.join(', ')} are typing`;
                
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
    loadUsersForDM();
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
   UTILITY FUNCTIONS
   ======================================= */

// Toggle mobile sidebar
function toggleSidebar() {
    document.getElementById('chatSidebar').classList.toggle('mobile-open');
}

// Mute/unmute channel
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
            loadChannels();
        }
    })
    .catch(error => console.error('Error toggling mute:', error));
}

// Toggle channel info
function toggleChannelInfo() {
    alert('Channel info coming soon!');
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

// Cleanup on page unload
window.addEventListener('beforeunload', function() {
    if (messagesPollInterval) clearInterval(messagesPollInterval);
    if (channelsPollInterval) clearInterval(channelsPollInterval);
    if (typingTimeout) clearTimeout(typingTimeout);
});
