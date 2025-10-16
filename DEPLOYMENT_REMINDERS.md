# Push Notification Preferences & Shift Reminders - Deployment Guide

## 🎉 New Features Added

### 1. Push Notification Preferences UI
Users can now customize their push notification settings from the Settings page:
- Master toggle for all push notifications
- Individual toggles for each notification type:
  - New shift assigned
  - Shift updated
  - Shift deleted
  - Shift invitations
  - Shift swaps

### 2. Shift Reminder System
Automated reminders before shifts start:
- ⏰ 24 hours before shift (default: ON)
- ⏰ 1 hour before shift (default: OFF)
- Users can enable/disable each reminder type

## 📋 Deployment Steps

### Step 1: Pull Latest Code
```bash
cd /var/www/rota-app
sudo git pull
```

### Step 2: Update Database Schema
```bash
# Run the SQL script to add new columns
sudo mysql -u root -p rota_app < setup_notification_preferences.sql
```

**Or manually:**
```sql
ALTER TABLE users 
ADD COLUMN push_notifications_enabled TINYINT(1) DEFAULT 1 AFTER theme,
ADD COLUMN notify_shift_assigned TINYINT(1) DEFAULT 1,
ADD COLUMN notify_shift_updated TINYINT(1) DEFAULT 1,
ADD COLUMN notify_shift_deleted TINYINT(1) DEFAULT 1,
ADD COLUMN notify_shift_invitation TINYINT(1) DEFAULT 1,
ADD COLUMN notify_shift_swap TINYINT(1) DEFAULT 1,
ADD COLUMN shift_reminder_24h TINYINT(1) DEFAULT 1,
ADD COLUMN shift_reminder_1h TINYINT(1) DEFAULT 0;

CREATE TABLE IF NOT EXISTS shift_reminders_sent (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    shift_id INT NOT NULL,
    reminder_type ENUM('24h', '1h') NOT NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_reminder (user_id, shift_id, reminder_type),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (shift_id) REFERENCES shifts(id) ON DELETE CASCADE,
    INDEX idx_sent_at (sent_at)
);
```

### Step 3: Set Up Cron Job for Shift Reminders
```bash
# Edit crontab
sudo crontab -e

# Add this line (runs every 15 minutes):
*/15 * * * * php /var/www/rota-app/cron_shift_reminders.php >> /var/log/shift-reminders.log 2>&1
```

**Alternative: Run every hour at specific minutes:**
```bash
# At 15 and 45 minutes past every hour
15,45 * * * * php /var/www/rota-app/cron_shift_reminders.php >> /var/log/shift-reminders.log 2>&1
```

### Step 4: Create Log Directory
```bash
sudo touch /var/log/shift-reminders.log
sudo chown www-data:www-data /var/log/shift-reminders.log
sudo chmod 664 /var/log/shift-reminders.log
```

### Step 5: Test the Cron Job Manually
```bash
# Test if the cron script works
sudo php /var/www/rota-app/cron_shift_reminders.php

# Check the output
tail -50 /var/log/shift-reminders.log
```

### Step 6: Restart Apache
```bash
sudo systemctl restart apache2
```

## ✅ Verification

### 1. Check Database Updates
```bash
sudo mysql -u root -p -e "USE rota_app; DESCRIBE users;" | grep -E "push_notifications|notify_|shift_reminder"
```

**Expected output:**
```
push_notifications_enabled | tinyint(1) | YES | | 1
notify_shift_assigned | tinyint(1) | YES | | 1
notify_shift_updated | tinyint(1) | YES | | 1
notify_shift_deleted | tinyint(1) | YES | | 1
notify_shift_invitation | tinyint(1) | YES | | 1
notify_shift_swap | tinyint(1) | YES | | 1
shift_reminder_24h | tinyint(1) | YES | | 1
shift_reminder_1h | tinyint(1) | YES | | 1
```

### 2. Test Settings Page
1. Visit: https://open-rota.com/users/settings.php
2. Look for the new "Push Notifications" section
3. Toggle settings and click "Save Push Notification Settings"
4. Verify success message appears

### 3. Test Reminder System
```bash
# Create a test shift starting in ~24 hours
# Then run the cron manually
sudo php /var/www/rota-app/cron_shift_reminders.php

# Check for sent reminders
sudo mysql -u root -p -e "USE rota_app; SELECT * FROM shift_reminders_sent ORDER BY sent_at DESC LIMIT 5;"
```

### 4. Monitor Cron Execution
```bash
# Watch the log file
tail -f /var/log/shift-reminders.log

# Check cron is running
sudo crontab -l | grep shift_reminders
```

## 🎯 How It Works

### Shift Reminder Logic

**24-Hour Reminder:**
- Checks for shifts starting between 23h 45m and 24h 15m from now
- Sends notification: "Shift Reminder - Tomorrow"
- Only if user has `shift_reminder_24h = 1`

**1-Hour Reminder:**
- Checks for shifts starting between 50 mins and 1h 10m from now
- Sends notification: "Shift Starting Soon!"
- Only if user has `shift_reminder_1h = 1`

### Preference Checking

Before sending ANY push notification, the system checks:
1. Is `push_notifications_enabled = 1`?
2. Is the specific notification type enabled?
   - `notify_shift_assigned` for new shift assignments
   - `notify_shift_updated` for shift edits
   - `notify_shift_deleted` for shift removals
   - `notify_shift_invitation` for shift invites
   - `notify_shift_swap` for swap requests/approvals

If either check fails, the notification is not sent.

### Duplicate Prevention

The `shift_reminders_sent` table tracks sent reminders:
- Unique constraint on `(user_id, shift_id, reminder_type)`
- Prevents duplicate reminders
- Auto-cleanup of records older than 7 days

## 🔧 Customization

### Change Reminder Timing

Edit `cron_shift_reminders.php`:

**For 2-hour reminder instead of 1-hour:**
```php
$in1hour = clone $now;
$in1hour->modify('+2 hours'); // Changed from +1 hour

$windowStart1h = clone $in1hour;
$windowStart1h->modify('-20 minutes'); // Wider window
```

**For 48-hour reminder instead of 24-hour:**
```php
$in24hours = clone $now;
$in24hours->modify('+48 hours'); // Changed from +24 hours
```

### Add More Reminder Options

1. Add column to users table:
```sql
ALTER TABLE users ADD COLUMN shift_reminder_2h TINYINT(1) DEFAULT 0;
```

2. Add checkbox in `users/settings.php`

3. Add logic in `cron_shift_reminders.php`

## 📊 Monitoring & Maintenance

### Check Reminder Stats
```bash
# Count reminders sent today
sudo mysql -u root -p -e "USE rota_app; 
SELECT reminder_type, COUNT(*) as count 
FROM shift_reminders_sent 
WHERE DATE(sent_at) = CURDATE() 
GROUP BY reminder_type;"
```

### View Recent Reminders
```bash
sudo mysql -u root -p -e "USE rota_app;
SELECT srs.*, u.username, s.shift_date, s.start_time
FROM shift_reminders_sent srs
JOIN users u ON srs.user_id = u.id
JOIN shifts s ON srs.shift_id = s.id
ORDER BY srs.sent_at DESC LIMIT 20;"
```

### Clean Old Reminder Records
The cron job automatically deletes records older than 7 days. To manually clean:
```sql
DELETE FROM shift_reminders_sent WHERE sent_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
```

### Check Cron Job Errors
```bash
# View cron errors
grep -i "shift_reminders" /var/log/syslog

# Check PHP errors
sudo tail -100 /var/log/apache2/error.log | grep -i "shift_reminder"
```

## 🚨 Troubleshooting

### Reminders Not Sending

**1. Check cron is running:**
```bash
sudo crontab -l
```

**2. Check log file:**
```bash
tail -100 /var/log/shift-reminders.log
```

**3. Test manually:**
```bash
sudo php /var/www/rota-app/cron_shift_reminders.php
```

**4. Check user preferences:**
```sql
SELECT id, username, push_notifications_enabled, shift_reminder_24h, shift_reminder_1h 
FROM users WHERE id = <USER_ID>;
```

**5. Check subscriptions:**
```sql
SELECT * FROM push_subscriptions WHERE user_id = <USER_ID>;
```

### Settings Page Not Showing New Options

**1. Clear browser cache**

**2. Check database columns exist:**
```bash
sudo mysql -u root -p -e "USE rota_app; SHOW COLUMNS FROM users LIKE 'push_%';"
```

**3. Check Apache error log:**
```bash
sudo tail -50 /var/log/apache2/error.log
```

### Cron Job Running But No Notifications

**1. Check shifts exist in timeframe:**
```sql
SELECT * FROM shifts 
WHERE shift_date >= CURDATE() 
AND CONCAT(shift_date, ' ', start_time) BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 25 HOUR);
```

**2. Check if reminders already sent:**
```sql
SELECT * FROM shift_reminders_sent WHERE shift_id = <SHIFT_ID>;
```

**3. Run cron with verbose output:**
```bash
sudo php /var/www/rota-app/cron_shift_reminders.php 2>&1 | tee /tmp/cron-debug.log
```

## 📝 Summary

✅ **New Features:**
- Push notification preferences UI in settings
- Granular control over notification types
- 24h and 1h shift reminders
- Automatic reminder system via cron

✅ **User Benefits:**
- Control which notifications they receive
- Never miss a shift with automatic reminders
- Choose reminder timing (24h, 1h, or both)

✅ **Admin Benefits:**
- Respect user preferences automatically
- Automated reminder system reduces no-shows
- Full logging and monitoring

---

**Last Updated:** October 16, 2025  
**Version:** 2.0  
**Status:** ✅ Ready for Deployment
