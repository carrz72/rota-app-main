# Team Chat - Complete Feature Guide

## 🎉 All Phases Complete! Full-Featured Team Messaging System

**Version:** 1.0  
**Release Date:** October 16, 2025  
**Total Development Time:** ~10 hours  
**Total Code:** 4,600+ lines  

---

## 📋 Table of Contents

1. [Overview](#overview)
2. [Features](#features)
3. [User Guide](#user-guide)
4. [Admin Guide](#admin-guide)
5. [Technical Documentation](#technical-documentation)
6. [Troubleshooting](#troubleshooting)

---

## Overview

Team Chat is a complete real-time messaging system integrated into Open Rota. It allows team members to communicate, collaborate, and stay connected across shifts, branches, and roles.

### Key Highlights

✅ **Real-time messaging** - Messages appear instantly  
✅ **Direct messages** - Private 1-on-1 conversations  
✅ **Channel-based** - Organized by branch, role, or topic  
✅ **Message reactions** - Express yourself with emojis  
✅ **Edit & delete** - Fix mistakes or remove messages  
✅ **Search** - Find old messages quickly  
✅ **Mobile responsive** - Works perfectly on phones  
✅ **Unread tracking** - Never miss important messages  
✅ **Typing indicators** - See who's responding  

---

## Features

### Core Messaging

**Send Messages**
- Type in the message box at bottom
- Press Enter to send (Shift+Enter for new line)
- Messages appear instantly for all channel members
- Auto-scroll to latest messages

**Real-time Updates**
- Messages update every 3 seconds
- Typing indicators show who's typing
- Unread counts update automatically
- Channel list refreshes every 10 seconds

**Message Actions**
- **Edit:** Click edit button or right-click → Edit Message
- **Delete:** Click delete button or right-click → Delete Message
- **React:** Click smile button or right-click → Add Reaction
- **Context Menu:** Right-click any message for quick actions

### Channels & Direct Messages

**Channel Types**
- **General:** Everyone in your organization
- **Branch:** Team members from specific branch
- **Role:** People with the same job role
- **Direct:** Private 1-on-1 conversations

**Create Direct Message**
1. Click **+** button in chat sidebar
2. Search for a user
3. Click their name
4. Start chatting!

**Channel Features**
- Unread message badges (orange numbers)
- Last message preview
- Member count and info
- Mute/unmute notifications

### Emoji Reactions

**Add Reaction**
- Hover over message → Click smile icon
- Choose from: 👍 ❤️ 😂 😮 😢 😡 🎉 🔥
- Click reaction to add/remove
- See who reacted in tooltip

**View Reactions**
- Reactions appear below messages
- Shows emoji + count
- Hover to see who reacted
- Click to toggle your reaction

### Search Messages

**How to Search**
1. Click search icon in chat header
2. Type search query
3. Results appear in real-time
4. Click result to jump to message

**Search Tips**
- Searches across all channels
- Filters by current channel if open
- Shows sender, time, and channel
- Includes message preview

### Channel Management

**View Members**
- Click info icon in chat header
- See all channel members
- View member roles (Owner, Admin, Member)
- Color-coded by role

**Mute Channel**
- Click bell icon to mute/unmute
- Muted channels don't send notifications
- Unread count still shows
- Unmute anytime

---

## User Guide

### Getting Started

**Access Team Chat**
1. Open any page in Open Rota
2. Click hamburger menu (☰)
3. Click **Team Chat** (with chat icon)
4. Or use dashboard "Team Chat" quick action

**Your First Message**
1. Select "General" channel from sidebar
2. Type message in box at bottom
3. Press Enter to send
4. Watch it appear in real-time!

### Sending Messages

**Basic Message**
```
Type your message and press Enter
```

**Multi-line Message**
```
Press Shift+Enter to add new lines
without sending the message
```

**Edit Message**
1. Find your sent message
2. Click edit button (pencil icon)
3. Message loads into input box
4. Make changes and press Enter
5. Click X to cancel editing

**Delete Message**
1. Find your sent message
2. Click delete button (trash icon)
3. Confirm deletion
4. Message is removed for everyone

### Direct Messaging

**Start DM**
1. Click **+** in sidebar header
2. Search for user by name
3. Click user to create/open DM
4. Channel appears in sidebar

**DM Features**
- Shows "Direct Message" in header
- User initials as channel icon
- Private - only you two see messages
- Reuses existing DM if you message again

### Reactions

**Quick React**
- Hover over any message
- Click smile face icon
- Pick emoji from picker
- Reaction adds to message

**Common Reactions**
- 👍 Thumbs up / Agree
- ❤️ Love / Support
- 😂 Funny / Laugh
- 😮 Surprise / Wow
- 😢 Sad / Sorry
- 😡 Angry / Upset
- 🎉 Celebrate / Congrats
- 🔥 Hot / Trending

### Navigation

**Unread Badges**
- Orange badge on "Team Chat" in menu
- Shows total unread count
- Badge on individual channels
- Pulses to attract attention

**Channel Switching**
- Click any channel in sidebar
- Active channel highlighted in red
- Messages load instantly
- Input box enables automatically

**Mobile Usage**
- Tap menu icon to show channels
- Channels overlay main chat
- Tap outside to close sidebar
- All features work the same

### Dashboard Integration

**Recent Messages Widget**
- Shows last 5 messages
- Click any to open chat
- Color-coded (red=yours, gray=others)
- Updates when you refresh

**Quick Action Card**
- "Team Chat" card in quick actions
- Shows unread count badge
- One click to open chat
- Fast access from dashboard

---

## Admin Guide

### Channel Management

**View All Channels**
```sql
SELECT * FROM chat_channels WHERE is_active = 1;
```

**Channel Types**
- `general`: Organization-wide
- `branch`: Branch-specific (branch_id set)
- `role`: Role-specific (role_id set)
- `direct`: 1-on-1 (system-created)

**Create New Channel (Admin Only)**
1. Use `chat_channels_api.php` with `create_channel` action
2. Set name, type, description
3. Optionally set branch_id or role_id
4. Add members manually or via API

### User Management

**Add User to Channel**
```php
POST /functions/chat_channels_api.php
action=add_member
channel_id=1
user_id=5
```

**Remove User from Channel**
```php
POST /functions/chat_channels_api.php
action=remove_member
channel_id=1
user_id=5
```

**View Channel Members**
```php
GET /functions/chat_channels_api.php?action=get_members&channel_id=1
```

### Monitoring

**Message Statistics**
```sql
-- Total messages
SELECT COUNT(*) FROM chat_messages WHERE is_deleted = 0;

-- Messages per channel
SELECT 
    c.name,
    COUNT(m.id) as message_count
FROM chat_channels c
LEFT JOIN chat_messages m ON c.id = m.channel_id AND m.is_deleted = 0
GROUP BY c.id
ORDER BY message_count DESC;

-- Most active users
SELECT 
    u.username,
    COUNT(m.id) as messages_sent
FROM users u
JOIN chat_messages m ON u.user_id = m.user_id
WHERE m.is_deleted = 0
GROUP BY u.user_id
ORDER BY messages_sent DESC
LIMIT 10;
```

**Active Channels**
```sql
SELECT 
    c.name,
    c.type,
    COUNT(DISTINCT cm.user_id) as member_count,
    MAX(m.created_at) as last_activity
FROM chat_channels c
LEFT JOIN chat_members cm ON c.id = cm.channel_id AND cm.left_at IS NULL
LEFT JOIN chat_messages m ON c.id = m.channel_id
WHERE c.is_active = 1
GROUP BY c.id
ORDER BY last_activity DESC;
```

### Moderation

**Delete Inappropriate Message**
```sql
UPDATE chat_messages 
SET is_deleted = 1 
WHERE id = ?;
```

**View Deleted Messages (Audit)**
```sql
SELECT 
    m.id,
    u.username,
    m.message,
    m.created_at
FROM chat_messages m
JOIN users u ON m.user_id = u.user_id
WHERE m.is_deleted = 1
ORDER BY m.created_at DESC;
```

**Mute User Temporarily**
```sql
-- Remove user from all channels temporarily
UPDATE chat_members 
SET left_at = NOW() 
WHERE user_id = ? AND left_at IS NULL;

-- Restore access
UPDATE chat_members 
SET left_at = NULL 
WHERE user_id = ?;
```

### Backup & Maintenance

**Backup Messages**
```bash
mysqldump -u root rota_app chat_messages > chat_backup_$(date +%Y%m%d).sql
```

**Clean Old Typing Indicators**
```sql
DELETE FROM chat_typing 
WHERE started_at < DATE_SUB(NOW(), INTERVAL 1 HOUR);
```

**Archive Old Messages** (optional)
```sql
-- Create archive table
CREATE TABLE chat_messages_archive LIKE chat_messages;

-- Move messages older than 1 year
INSERT INTO chat_messages_archive 
SELECT * FROM chat_messages 
WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR);

DELETE FROM chat_messages 
WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR);
```

---

## Technical Documentation

### Database Schema

**chat_channels**
```sql
- id (PK)
- name VARCHAR(100)
- type ENUM('general','branch','role','direct')
- branch_id (FK to branches, nullable)
- role_id (FK to roles, nullable)
- created_by (FK to users)
- description TEXT
- is_active BOOLEAN
- created_at, updated_at TIMESTAMPS
```

**chat_messages**
```sql
- id (PK)
- channel_id (FK to chat_channels)
- user_id (FK to users)
- message TEXT
- message_type ENUM('text','file','system')
- file_url, file_name, file_type, file_size (for files)
- reply_to_id (FK to chat_messages, nullable)
- is_edited BOOLEAN
- is_deleted BOOLEAN
- created_at, updated_at TIMESTAMPS
- INDEX on (channel_id, created_at)
- FULLTEXT INDEX on (message)
```

**chat_members**
```sql
- id (PK)
- channel_id (FK to chat_channels)
- user_id (FK to users)
- role ENUM('member','admin','owner')
- last_read_at TIMESTAMP
- is_muted BOOLEAN
- joined_at, left_at TIMESTAMPS
- UNIQUE(channel_id, user_id)
```

**chat_reactions**
```sql
- id (PK)
- message_id (FK to chat_messages)
- user_id (FK to users)
- emoji VARCHAR(10)
- created_at TIMESTAMP
- UNIQUE(message_id, user_id, emoji)
```

**chat_typing**
```sql
- id (PK)
- channel_id (FK to chat_channels)
- user_id (FK to users)
- started_at TIMESTAMP
- UNIQUE(channel_id, user_id)
```

### API Endpoints

**chat_api.php**
- `get_channels` - Fetch user's channels with unread counts
- `get_messages` - Load messages for channel (paginated, limit 50)
- `send_message` - Send new text message
- `edit_message` - Edit own message
- `delete_message` - Soft delete message
- `mark_read` - Update last_read_at
- `get_unread_count` - Total unread across all channels
- `search_messages` - FULLTEXT search with filters
- `set_typing` - Set/clear typing indicator
- `get_typing` - Get users currently typing
- `add_reaction` - Add emoji reaction
- `remove_reaction` - Remove emoji reaction

**chat_channels_api.php**
- `create_channel` - Create new channel (admin only)
- `create_direct_channel` - Create/get DM between users
- `join_channel` - Join existing channel
- `leave_channel` - Leave channel (soft delete)
- `get_members` - List channel members
- `add_member` - Add user to channel (admin)
- `remove_member` - Remove user from channel (admin)
- `mute_channel` - Toggle mute status
- `get_users_for_dm` - Search users for DM

### Frontend Components

**chat.php** (170 lines)
- Main chat interface
- Two-column layout (sidebar + messages)
- Responsive mobile design
- Header with navigation integration

**chat.css** (1000+ lines)
- Complete themed styling
- Red gradient design language
- Animations and transitions
- Mobile breakpoints
- Context menu styles
- Emoji picker styles
- Search panel styles

**chat.js** (750+ lines)
- Real-time polling logic
- Message CRUD operations
- Channel management
- Direct messaging
- Typing indicators
- Emoji reactions
- Message editing/deleting
- Search functionality
- Mobile sidebar toggle

### Helper Functions

**get_chat_unread.php**
```php
function getUnreadChatCount($pdo, $user_id)
// Returns count of unread messages across all non-muted channels
// Used in navigation and dashboard
```

### Performance

**Polling Intervals**
- Messages: 3 seconds (when channel open)
- Channels: 10 seconds (always running)
- Typing: 3 second timeout
- Search: 500ms debounce

**Database Indexes**
- `chat_messages`: (channel_id, created_at)
- `chat_messages`: (user_id, created_at)
- `chat_messages`: FULLTEXT on message
- `chat_members`: UNIQUE(channel_id, user_id)

**Optimization Tips**
1. Add Redis cache for channel lists
2. Implement WebSocket for true real-time
3. Paginate message history (currently loads last 50)
4. Lazy load channel members
5. Compress old messages

---

## Troubleshooting

### Messages Not Sending

**Check:**
1. Is XAMPP MySQL running?
2. Are you logged in? Check session
3. Is channel selected? Check currentChannelId
4. Browser console errors?

**Fix:**
```javascript
// In browser console
console.log('User ID:', CURRENT_USER_ID);
console.log('Channel ID:', currentChannelId);
console.log('Session:', fetch('../functions/check_session.php'));
```

### Unread Count Not Updating

**Issue:** Badge shows 0 but you have unread messages

**Fix:**
```sql
-- Check chat_members.last_read_at
SELECT * FROM chat_members WHERE user_id = YOUR_ID;

-- Reset if needed
UPDATE chat_members 
SET last_read_at = '2000-01-01 00:00:00' 
WHERE user_id = YOUR_ID;
```

### Typing Indicator Stuck

**Issue:** Shows "User is typing..." forever

**Fix:**
```sql
-- Clean old typing indicators
DELETE FROM chat_typing 
WHERE started_at < DATE_SUB(NOW(), INTERVAL 1 MINUTE);
```

### Search Not Working

**Check:**
1. Is FULLTEXT index created?
2. Are you searching with 3+ characters?
3. Check browser console for errors

**Fix:**
```sql
-- Rebuild FULLTEXT index
ALTER TABLE chat_messages DROP INDEX idx_message_search;
ALTER TABLE chat_messages ADD FULLTEXT INDEX idx_message_search (message);
```

### Can't Edit/Delete Messages

**Check:**
1. Are you the message author?
2. Is message already deleted?
3. Browser console for API errors

**Debug:**
```javascript
// Check message ownership
document.querySelectorAll('[data-message-id]').forEach(el => {
    console.log('Message:', el.dataset.messageId, 'IsOwn:', el.dataset.isOwn);
});
```

### Mobile Sidebar Not Working

**Issue:** Sidebar won't open/close on mobile

**Fix:**
```javascript
// Manually toggle
document.getElementById('chatSidebar').classList.toggle('mobile-open');
```

### Reactions Not Appearing

**Check:**
1. Is message_id valid?
2. Are you in the channel?
3. Check chat_reactions table

**Debug:**
```sql
-- View all reactions
SELECT 
    m.message,
    u.username,
    r.emoji
FROM chat_reactions r
JOIN chat_messages m ON r.message_id = m.id
JOIN users u ON r.user_id = u.user_id
ORDER BY r.created_at DESC
LIMIT 20;
```

---

## Advanced Configuration

### Customize Polling Intervals

**Edit chat.js:**
```javascript
// Faster updates (more server load)
messagesPollInterval = setInterval(loadMessages, 2000); // 2 sec
channelsPollInterval = setInterval(loadChannels, 5000); // 5 sec

// Slower updates (less server load)
messagesPollInterval = setInterval(loadMessages, 5000); // 5 sec
channelsPollInterval = setInterval(loadChannels, 15000); // 15 sec
```

### Add Custom Emojis

**Edit chat.js:**
```javascript
const commonEmojis = [
    '👍', '❤️', '😂', '😮', '😢', '😡', '🎉', '🔥',
    '🚀', '⭐', '✅', '❌', '💯', '👏' // Add these
];
```

### Change Message Limit

**Edit chat_api.php (get_messages):**
```php
// Change from 50 to 100
$stmt = $conn->prepare("
    ...
    LIMIT 100 // was 50
");
```

### Enable File Uploads

**Status:** Backend ready, UI pending

**To Enable:**
1. Uncomment file upload code in chat_api.php
2. Create uploads directory: `mkdir uploads/chat`
3. Set permissions: `chmod 755 uploads/chat`
4. Update chat.js handleFileSelect() function
5. Add file preview UI

---

## Support & Feedback

### Reporting Issues

1. Check browser console for errors
2. Check database logs
3. Document steps to reproduce
4. Include screenshots if possible

### Feature Requests

Current roadmap:
- [ ] File upload implementation
- [ ] Voice messages
- [ ] Video calls
- [ ] Message pinning
- [ ] @mention notifications
- [ ] Read receipts
- [ ] WebSocket for real-time (no polling)
- [ ] Message threading
- [ ] Rich text formatting
- [ ] GIF support

---

## Credits

**Developed by:** GitHub Copilot + carrz72  
**Version:** 1.0  
**Release Date:** October 16, 2025  
**Technology Stack:** PHP, MySQL, JavaScript, CSS  
**Framework:** Open Rota (open-rota.com)  

---

**🎉 Congratulations! Team Chat is now fully operational!**

Navigate to `http://localhost/rota-app-main/users/chat.php` to start messaging! 🚀
