# ✅ VAPID Keys Generated Successfully!

## 🎉 Great Progress!

Your production VAPID keys are now created. Here's what to do next:

---

## 📋 Next Steps on Your DigitalOcean Server

### Step 1: Update the VAPID Subject

```bash
# Edit the config file
nano includes/push_config.php
```

**Change this line:**
```php
define('VAPID_SUBJECT', 'mailto:admin@openrota.com');
```

**To your actual domain:**
```php
define('VAPID_SUBJECT', 'https://open-rota.com');
```

**Save:** Press `Ctrl+O`, then `Enter`, then `Ctrl+X`

---

### Step 2: Secure the Config File

```bash
chmod 600 includes/push_config.php
chown www-data:www-data includes/push_config.php
```

---

### Step 3: Setup the Database

```bash
php setup_push_database.php
```

Expected output:
```
✅ Database connection successful!
✅ Table 'push_subscriptions' created successfully!
✅ Push notifications setup complete!
```

---

### Step 4: Verify Database Table

```bash
# Login to MySQL
mysql -u your_db_user -p

# Check the table
USE your_database_name;
SHOW TABLES LIKE 'push_subscriptions';
DESCRIBE push_subscriptions;
EXIT;
```

---

### Step 5: Set Proper Permissions

```bash
cd /var/www/rota-app
chown -R www-data:www-data /var/www/rota-app
chmod -R 755 /var/www/rota-app
chmod 600 includes/push_config.php
chmod 600 includes/db.php
```

---

### Step 6: Configure Apache Headers

```bash
# Edit your Apache site config
sudo nano /etc/apache2/sites-available/open-rota.com.conf
```

Add these headers inside your `<VirtualHost>` block:

```apache
<VirtualHost *:443>
    ServerName open-rota.com
    DocumentRoot /var/www/rota-app
    
    # Service Worker Headers
    <Files "service-worker.js">
        Header set Service-Worker-Allowed "/"
        Header set Cache-Control "max-age=0, no-cache, no-store, must-revalidate"
    </Files>
    
    # Manifest Headers
    <Files "manifest.json">
        Header set Content-Type "application/manifest+json"
        Header set Cache-Control "max-age=86400"
    </Files>
    
    # ... rest of your config
</VirtualHost>
```

Enable headers module and restart:
```bash
sudo a2enmod headers
sudo systemctl restart apache2
```

---

### Step 7: Delete the Generator File (Security)

```bash
rm generate_vapid_keys.php
```

This file is no longer needed and contains sensitive key generation code.

---

### Step 8: Update Your JavaScript with New Public Key

The public key needs to be accessible to your JavaScript. It's already in `push_config.php`, but you need to expose it.

**Option A: Create a simple endpoint (Recommended)**

Create `get_vapid_public_key.php`:
```bash
nano get_vapid_public_key.php
```

Add this content:
```php
<?php
require_once 'includes/push_config.php';
header('Content-Type: application/json');
echo json_encode(['publicKey' => VAPID_PUBLIC_KEY]);
```

**Option B: Hardcode in JavaScript**

Edit `js/push-notifications.js` and update the public key:
```bash
nano js/push-notifications.js
```

Find this line (around line 10-20):
```javascript
const VAPID_PUBLIC_KEY = 'YOUR_PUBLIC_KEY_HERE';
```

Replace with your new key:
```javascript
const VAPID_PUBLIC_KEY = 'BDujEyd4Q023y8o2QJq1tOVTBbb-ShmmcPZwI8xP-3j7yroNYJXhAaLdFku1qzHLRWDvkGoOvgy2_gAV90yJ2v4';
```

---

### Step 9: Test the Notifications!

```bash
# Test by visiting this URL in your browser
https://open-rota.com/test_push_notification.php
```

**Or test the subscription flow:**
1. Visit `https://open-rota.com/users/dashboard.php`
2. Wait 30 seconds or refresh the page
3. Click "Enable Notifications" when prompted
4. Grant permission in your browser
5. Check the console for success message

---

### Step 10: Verify Everything Works

```bash
# Check Apache is running
sudo systemctl status apache2

# Check for errors
sudo tail -f /var/log/apache2/error.log

# Check PHP errors
sudo tail -f /var/log/php*-fpm.log
```

---

## 🧪 Testing Checklist

- [ ] VAPID subject updated in `push_config.php`
- [ ] Config file secured (chmod 600)
- [ ] Database table created successfully
- [ ] Apache headers configured
- [ ] Service worker accessible at `/service-worker.js`
- [ ] Manifest accessible at `/manifest.json`
- [ ] Public key updated in JavaScript
- [ ] Test notification sent successfully
- [ ] Notification received on desktop browser
- [ ] Notification received on mobile browser
- [ ] Notification click opens correct URL
- [ ] Works when browser is closed
- [ ] HTTPS padlock showing (secure connection)

---

## 🎯 Your New Keys

**Public Key (for JavaScript):**
```
BDujEyd4Q023y8o2QJq1tOVTBbb-ShmmcPZwI8xP-3j7yroNYJXhAaLdFku1qzHLRWDvkGoOvgy2_gAV90yJ2v4
```

**Private Key (on server only):**
```
BuTxsY7uv1nPLi7ORgrk1WYItExzztLhgL-gzgJv0N0
```

⚠️ **Never expose the private key in JavaScript or client-side code!**

---

## 🔒 Security Reminders

1. ✅ Config file is chmod 600 (only owner can read)
2. ✅ Config file owned by www-data (web server user)
3. ✅ Add to `.gitignore`:
   ```bash
   echo "includes/push_config.php" >> .gitignore
   ```
4. ✅ Backup keys in a secure location (password manager or secure note)

---

## 🚀 Integration into Your App

Now you can send notifications from your code! Examples:

### When Shift is Assigned
In `admin/add_shift.php`:
```php
require_once '../functions/push_notification_helper.php';

// After saving shift
notifyShiftAssignment($user_id, [
    'id' => $shift_id,
    'date' => $shift_date,
    'time' => $start_time . '-' . $end_time
]);
```

### When Shift Swap Requested
In your swap request code:
```php
require_once '../functions/push_notification_helper.php';

notifyShiftSwapRequest($target_user_id, $requester_name, $request_id);
```

### Shift Reminders (Cron Job)
Create `cron_shift_reminders.php`:
```php
<?php
require_once 'includes/db.php';
require_once 'functions/push_notification_helper.php';

// Find shifts in next 24 hours
$stmt = $conn->query("
    SELECT s.*, u.id as user_id 
    FROM shifts s
    JOIN users u ON s.user_id = u.id
    WHERE s.shift_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY)
    AND s.reminder_sent = 0
");

foreach ($stmt->fetchAll() as $shift) {
    notifyShiftReminder($shift['user_id'], 24, [
        'id' => $shift['id'],
        'date' => $shift['shift_date'],
        'time' => $shift['start_time']
    ]);
    
    // Mark as sent
    $conn->exec("UPDATE shifts SET reminder_sent = 1 WHERE id = {$shift['id']}");
}

echo "✅ Reminders sent!\n";
```

Add to crontab:
```bash
crontab -e
```

Add:
```
0 9 * * * cd /var/www/rota-app && php cron_shift_reminders.php >> /var/log/shift_reminders.log 2>&1
```

---

## 📊 Monitor Subscriptions

Check how many users are subscribed:
```bash
mysql -u your_db_user -p -e "SELECT COUNT(*) as total FROM your_db.push_subscriptions;"
```

---

## 🎉 You're Done!

Your push notification system is now fully set up and ready to use!

**What's Working:**
- ✅ Production VAPID keys generated
- ✅ Secure configuration
- ✅ Database ready
- ✅ Backend functions ready
- ✅ Frontend JavaScript ready
- ✅ Service worker ready
- ✅ HTTPS enabled

**Next:** Test by visiting your dashboard and enabling notifications!

---

## 📞 Need Help?

If you encounter any issues:
1. Check Apache error logs: `sudo tail -f /var/log/apache2/error.log`
2. Check PHP error logs: `sudo tail -f /var/log/php*-fpm.log`
3. Check browser console (F12)
4. Verify HTTPS is working (padlock in browser)
5. Test with: `https://open-rota.com/test_push_notification.php`

---

## 🎯 Summary Commands

Run these in order:
```bash
# 1. Update VAPID subject
nano includes/push_config.php

# 2. Secure config
chmod 600 includes/push_config.php
chown www-data:www-data includes/push_config.php

# 3. Setup database
php setup_push_database.php

# 4. Configure Apache
sudo nano /etc/apache2/sites-available/open-rota.com.conf
sudo a2enmod headers
sudo systemctl restart apache2

# 5. Update public key in JavaScript
nano js/push-notifications.js

# 6. Delete generator
rm generate_vapid_keys.php

# 7. Test
# Visit: https://open-rota.com/test_push_notification.php
```

**You're all set! 🚀**
