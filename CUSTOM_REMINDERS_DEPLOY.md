# Custom Reminders Deployment Guide

## What's New
Users can now create custom shift reminders at any time interval (minutes/hours/days) before their shifts, in addition to the standard 24h and 1h reminders.

## Files Created/Modified

### New Files
1. `setup_custom_reminders.sql` - Database schema for shift_reminder_preferences table
2. `functions/manage_custom_reminders.php` - REST API for CRUD operations
3. `CUSTOM_REMINDERS_DEPLOY.md` - This deployment guide

### Modified Files
1. `users/settings.php` - Added custom reminders UI with JavaScript
2. `cron_shift_reminders.php` - Integrated custom reminder processing

## Deployment Steps

### Step 1: Update Code on Server
```bash
cd /var/www/rota-app
sudo git pull
```

### Step 2: Create Database Table
```bash
sudo mysql -u root -p rota_app < setup_custom_reminders.sql
```
Enter password when prompted.

### Step 3: Verify Database
```bash
sudo mysql -u root -p rota_app -e "DESCRIBE shift_reminder_preferences;"
```
Should show columns: id, user_id, reminder_type, reminder_value, enabled, created_at

### Step 4: Set Permissions
```bash
sudo chown www-data:www-data /var/www/rota-app/functions/manage_custom_reminders.php
sudo chmod 644 /var/www/rota-app/functions/manage_custom_reminders.php
```

### Step 5: Restart Services (if needed)
```bash
sudo systemctl restart apache2
```

### Step 6: Verify Cron Job
```bash
crontab -l | grep cron_shift_reminders.php
```
Should show: `*/5 * * * * /usr/bin/php /var/www/rota-app/cron_shift_reminders.php >> /var/www/rota-app/logs/cron_reminders.log 2>&1`

## Testing

### Test 1: Create Custom Reminder
1. Go to https://open-rota.com/users/settings.php
2. Scroll to "Custom Shift Reminders" section
3. Enter "15" and select "minutes"
4. Click "Add Reminder"
5. Should see confirmation message and reminder appear in list

### Test 2: Verify Database
```bash
sudo mysql -u root -p rota_app -e "SELECT * FROM shift_reminder_preferences WHERE user_id = YOUR_USER_ID;"
```

### Test 3: Test Notification (Quick)
1. Create a test shift starting in 20 minutes
2. Create a reminder for "15 minutes"
3. Wait for cron to run (runs every 5 minutes)
4. Check cron logs:
```bash
tail -f /var/www/rota-app/logs/cron_reminders.log
```

### Test 4: Verify Notification Sent
```bash
sudo mysql -u root -p rota_app -e "SELECT * FROM shift_reminders_sent WHERE reminder_type LIKE 'custom_%' ORDER BY sent_at DESC LIMIT 5;"
```

## Features

### Reminder Types
- **Minutes**: 5-minute window (e.g., "30 minutes before")
- **Hours**: 10-minute window (e.g., "2 hours before")
- **Days**: 15-minute window (e.g., "3 days before")

### API Actions
- `GET` with action=get - List all user's reminders
- `POST` with action=add - Create new reminder
- `POST` with action=toggle - Enable/disable reminder
- `POST` with action=delete - Remove reminder

### Database Schema
```sql
shift_reminder_preferences (
  id INT PRIMARY KEY AUTO_INCREMENT,
  user_id INT FK to users,
  reminder_type ENUM('minutes', 'hours', 'days'),
  reminder_value INT (minimum 1),
  enabled TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)
```

### Cron Processing
- Runs every 5 minutes
- Checks each enabled custom reminder preference
- Calculates time windows based on reminder type
- Sends notifications to users with push enabled
- Tracks sent reminders with unique identifier: `custom_{preference_id}`
- Prevents duplicate notifications

## Troubleshooting

### Custom reminders not appearing in UI
```bash
# Check Apache error log
sudo tail -f /var/log/apache2/error.log

# Test API directly
curl -X POST https://open-rota.com/functions/manage_custom_reminders.php \
  -H "Cookie: PHPSESSID=your_session_id" \
  -d "action=get"
```

### Custom notifications not sending
```bash
# Check cron is running
sudo systemctl status cron

# Check cron logs for errors
tail -50 /var/www/rota-app/logs/cron_reminders.log

# Verify push subscriptions
sudo mysql -u root -p rota_app -e "SELECT COUNT(*) FROM push_subscriptions WHERE user_id = YOUR_USER_ID;"
```

### Database query test
```bash
# Test custom reminder query
sudo mysql -u root -p rota_app -e "
SELECT 
  srp.id as pref_id,
  srp.reminder_type,
  srp.reminder_value,
  u.username,
  COUNT(s.id) as upcoming_shifts
FROM shift_reminder_preferences srp
JOIN users u ON srp.user_id = u.id
LEFT JOIN shifts s ON s.user_id = u.id AND s.shift_date >= CURDATE()
WHERE srp.enabled = 1
GROUP BY srp.id;
"
```

## Rollback (if needed)

```bash
cd /var/www/rota-app
sudo git log --oneline -10  # Find commit before custom reminders
sudo git reset --hard COMMIT_HASH
sudo mysql -u root -p rota_app -e "DROP TABLE IF EXISTS shift_reminder_preferences;"
sudo systemctl restart apache2
```

## Success Indicators
✅ Table `shift_reminder_preferences` exists
✅ Settings page shows "Custom Shift Reminders" section
✅ Can add/toggle/delete custom reminders via UI
✅ Cron log shows "Checking for custom reminders..."
✅ Push notifications received at custom intervals

## Notes
- Custom reminders work independently from 24h/1h fixed reminders
- Users can have multiple custom reminders (e.g., 1 day, 2 hours, 30 minutes)
- Reminders are sent only if user has push_notifications_enabled = 1
- Cron uses adaptive windows: 5min for minutes, 10min for hours, 15min for days
- Each custom reminder preference gets unique tracking ID: `custom_{preference_id}`
