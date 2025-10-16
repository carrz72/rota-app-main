# Push Notifications Setup Guide

## ✅ Completed Setup

Your push notification system is **fully configured and operational**!

### System Requirements Met
- ✅ HTTPS enabled (open-rota.com)
- ✅ Service Worker registered
- ✅ VAPID keys generated and configured
- ✅ Database table created (`push_subscriptions`)
- ✅ PHP extensions installed (curl, gmp)
- ✅ Composer dependencies installed (minishlink/web-push)

---

## Configuration Files

### 1. Server-Side Configuration
**File:** `/includes/push_config.php`
```php
<?php
define('VAPID_PUBLIC_KEY', 'BLtlc-WTpjlQnicf80q-XLQ_H9tas0LMpL0IGKEd7Fk6-fBMX-ru1UOfeh-DxQkJ7ctez0Ro_Xs3UBR2YrGgtbg');
define('VAPID_PRIVATE_KEY', 'Aj76M8t465YcCH4wPMWM-IKuta70I_Aq1rEzN-lYgBo');
define('VAPID_SUBJECT', 'https://open-rota.com');
```
⚠️ **IMPORTANT:** Never commit this file to version control! Keep it secure.

### 2. Client-Side Configuration
**File:** `/js/push-notifications.js`
- Handles permission requests
- Manages subscriptions
- Communicates with service worker
- Auto-requests permission 30 seconds after dashboard load

### 3. Service Worker
**File:** `/service-worker.js`
- Handles push events
- Displays notifications
- Manages notification clicks
- Currently on version 14

---

## Database Schema

### Table: `push_subscriptions`
```sql
CREATE TABLE push_subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    endpoint VARCHAR(500) NOT NULL,
    p256dh_key VARCHAR(255) NOT NULL,
    auth_token VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id)
);
```

---

## Integration Points

Push notifications are now integrated into the following features:

### 1. Shift Invitations
**File:** `/functions/shift_invitation_sender.php`
- Sends notification when admin invites user to a shift
- Works for both individual and broadcast invitations
- Function: `notifyShiftInvitation($user_id, $shift_details)`

### 2. Direct Shift Assignment
**File:** `/functions/add_shift.php`
- Sends notification when admin assigns a shift directly
- Only notifies if assigning to someone else (not yourself)
- Function: `notifyShiftAssigned($user_id, $shift_details)`

### 3. Shift Swaps
**File:** `/functions/shift_swap.php`
- Sends notification when a shift swap is accepted
- Notifies both parties involved in the swap
- Function: `notifyCoverageApproved($user_id, $shift_details)`

### 4. Coverage Requests
- Same functionality as shift swaps
- Uses `notifyCoverageApproved()`

---

## Notification Helper Functions

**File:** `/functions/send_shift_notification.php`

### Core Function
```php
sendPushNotification($user_id, $title, $body, $data = [])
```
- Sends push notification to all devices subscribed by a user
- Automatically removes expired subscriptions
- Returns true if at least one notification sent successfully

### Specialized Functions

#### 1. Shift Assignment Notification
```php
notifyShiftAssigned($user_id, $shift_details)
```
**Parameters:**
```php
$shift_details = [
    'shift_id' => 123,
    'shift_date' => '2025-10-20',
    'start_time' => '09:00:00',
    'role_name' => 'Barista'
];
```

#### 2. Shift Invitation Notification
```php
notifyShiftInvitation($user_id, $shift_details)
```
**Parameters:**
```php
$shift_details = [
    'invitation_id' => 456,
    'shift_date' => '2025-10-20',
    'start_time' => '09:00:00',
    'role_name' => 'Server'
];
```

#### 3. Shift Swap Request Notification
```php
notifyShiftSwapRequest($user_id, $shift_details)
```
**Parameters:**
```php
$shift_details = [
    'swap_id' => 789,
    'shift_date' => '2025-10-20',
    'requester_name' => 'John Doe',
    'role_name' => 'Chef'
];
```

#### 4. Coverage Approved Notification
```php
notifyCoverageApproved($user_id, $shift_details)
```
**Parameters:**
```php
$shift_details = [
    'shift_date' => '2025-10-20',
    'role_name' => 'Manager'
];
```

---

## Testing

### Manual Test Script
**File:** `/test_send_notification.php`

**Usage:**
```bash
sudo php /var/www/rota-app/test_send_notification.php <user_id>
```

**Example:**
```bash
sudo php /var/www/rota-app/test_send_notification.php 3
```

This sends a test notification to the specified user.

### Verify Subscriptions
```bash
sudo mysql -u root -p -e "USE rota_app; SELECT id, user_id, endpoint, created_at FROM push_subscriptions ORDER BY created_at DESC LIMIT 10;"
```

---

## iOS Support

### Requirements
- **iOS Version:** 16.4 or later (You have 18.6.2 ✅)
- **Installation:** App must be added to Home Screen
- **Access:** Must open from Home Screen icon (not Safari browser)

### iOS-Specific Setup
1. Visit https://open-rota.com in Safari
2. Tap Share button (square with arrow)
3. Tap "Add to Home Screen"
4. Open the app from Home Screen
5. Wait 30 seconds for permission prompt
6. Tap "Allow" when prompted
7. Check: Settings → Open Rota → Notifications → Ensure "Allow Notifications" is ON

### Apple Push Service
- iOS uses Apple Push Notification service (APNs)
- Endpoint format: `https://web.push.apple.com/...`
- Fully compatible with Web Push API standard

---

## Android Support

### Requirements
- **Chrome/Edge:** Version 50+
- **Firefox:** Version 44+
- Any browser with Web Push API support

### Android Setup
1. Visit https://open-rota.com
2. Login to dashboard
3. Wait 30 seconds for permission prompt
4. Tap "Allow"
5. Notifications will work immediately

---

## Troubleshooting

### User Not Receiving Notifications

**1. Check Subscription**
```bash
sudo mysql -u root -p -e "USE rota_app; SELECT * FROM push_subscriptions WHERE user_id = <USER_ID>;"
```

**2. Check Browser Permission**
- Desktop: Click lock icon in address bar → Site settings → Notifications → Allow
- iOS: Settings → Open Rota → Notifications → Allow Notifications ON
- Android: Settings → Apps → Chrome → Notifications → open-rota.com → Allow

**3. Test Manually**
```bash
sudo php /var/www/rota-app/test_send_notification.php <USER_ID>
```

**4. Check Service Worker**
- Open DevTools (F12)
- Go to Application tab → Service Workers
- Should show "service-worker.js" as "activated and running"

**5. Check Errors**
```bash
sudo tail -100 /var/log/apache2/error.log | grep -i "push\|notification"
```

### Subscription Expired Error
This is normal! The system automatically removes expired subscriptions.
User needs to:
1. Delete PWA and clear site data
2. Re-add to Home Screen
3. Grant permission again

### VAPID Key Mismatch
If you regenerate VAPID keys:
1. Update `/includes/push_config.php`
2. Update `/js/push-notifications.js`
3. All users must re-subscribe (delete and re-add PWA)

---

## Maintenance

### Regenerate VAPID Keys (if needed)
```bash
cd /var/www/rota-app
sudo php -r "require 'vendor/autoload.php'; \$keys = \Minishlink\WebPush\VAPID::createVapidKeys(); echo 'Public Key: ' . \$keys['publicKey'] . PHP_EOL; echo 'Private Key: ' . \$keys['privateKey'] . PHP_EOL;"
```

### Clean Up Old Subscriptions
```sql
DELETE FROM push_subscriptions 
WHERE updated_at < DATE_SUB(NOW(), INTERVAL 90 DAY);
```

### Monitor Subscription Count
```bash
sudo mysql -u root -p -e "USE rota_app; SELECT COUNT(*) as total_subscriptions, COUNT(DISTINCT user_id) as unique_users FROM push_subscriptions;"
```

---

## Security Best Practices

1. ✅ **Never expose private VAPID key**
   - Keep in server-side config only
   - Never commit to version control
   - Set file permissions: `chmod 600 push_config.php`

2. ✅ **Validate user permissions**
   - Only send notifications to authorized users
   - Verify user_id matches session

3. ✅ **Rate limiting** (recommended)
   - Limit notifications per user per hour
   - Prevent spam/abuse

4. ✅ **HTTPS required**
   - Service workers require HTTPS
   - You have this configured ✅

---

## Performance

### Current Setup
- **Library:** minishlink/web-push v9.0.2
- **Transport:** Curl with HTTP/2
- **Caching:** Service worker cache version 14

### Optimization Tips
1. **Batch notifications:** Send multiple at once when possible
2. **Queue system:** For large broadcasts, use job queue
3. **Database indexes:** Already added on `user_id`

---

## Future Enhancements

### Possible Additions
1. **Notification preferences**
   - Let users choose which notifications to receive
   - Add to user settings page

2. **Notification history**
   - Store sent notifications in database
   - Show history in user dashboard

3. **Rich notifications**
   - Add action buttons
   - Include images
   - Sound customization

4. **Admin dashboard**
   - View subscription statistics
   - Send broadcast announcements
   - Monitor delivery rates

---

## Support

### Key Files Reference
- Service Worker: `/service-worker.js`
- Push Config: `/includes/push_config.php`
- Push Script: `/js/push-notifications.js`
- Helper Functions: `/functions/send_shift_notification.php`
- Database Setup: `/setup_push_database.php`
- Test Script: `/test_send_notification.php`

### Useful Commands
```bash
# Check PHP extensions
php -m | grep -E "curl|gmp"

# Restart Apache
sudo systemctl restart apache2

# View error logs
sudo tail -100 /var/log/apache2/error.log

# Check service worker cache version
head -1 /var/www/rota-app/service-worker.js

# Test notification
sudo php /var/www/rota-app/test_send_notification.php 3
```

---

## Summary

✅ **System Status: FULLY OPERATIONAL**

Your push notification system is now:
- ✅ Configured and tested on iOS 18.6.2
- ✅ Integrated into shift invitations
- ✅ Integrated into shift assignments
- ✅ Integrated into shift swaps
- ✅ Ready for production use

**Next Steps:**
1. Deploy changes: `cd /var/www/rota-app && sudo git pull && sudo systemctl restart apache2`
2. Test with real users
3. Monitor subscription growth
4. Consider adding notification preferences

---

**Last Updated:** October 16, 2025  
**Version:** 1.0  
**Status:** ✅ Production Ready
