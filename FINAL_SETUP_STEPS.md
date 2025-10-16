# 🚀 Push Notifications - Final Setup Steps

## ✅ What You've Done So Far:
- ✅ Generated VAPID keys
- ✅ Fixed push_config.php

---

## 📋 Remaining Steps (Do These Now):

### Step 1: Secure the Config File
```bash
chmod 600 includes/push_config.php
chown www-data:www-data includes/push_config.php
```

---

### Step 2: Setup the Database
```bash
php setup_push_database.php
```

**Expected output:**
```
✅ Database connection successful!
✅ Table 'push_subscriptions' created successfully!
✅ Push notifications setup complete!
```

---

### Step 3: Update JavaScript with Public Key

**Option A: Fetch from Server (Recommended)**

Create a new file to expose the public key:
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
Save: `Ctrl+O`, `Enter`, `Ctrl+X`

Then update `js/push-notifications.js` to fetch it:
```bash
nano js/push-notifications.js
```

Find the line with `const VAPID_PUBLIC_KEY` and replace that section with:
```javascript
// Fetch VAPID public key from server
let VAPID_PUBLIC_KEY = null;

async function getPublicKey() {
    if (!VAPID_PUBLIC_KEY) {
        const response = await fetch('/get_vapid_public_key.php');
        const data = await response.json();
        VAPID_PUBLIC_KEY = data.publicKey;
    }
    return VAPID_PUBLIC_KEY;
}
```

**Option B: Hardcode in JavaScript (Simpler)**

```bash
nano js/push-notifications.js
```

Find this line (around line 10-20):
```javascript
const VAPID_PUBLIC_KEY = 'YOUR_PUBLIC_KEY_HERE';
```

Replace with:
```javascript
const VAPID_PUBLIC_KEY = 'BDujEyd4Q023y8o2QJq1tOVTBbb-ShmmcPZwI8xP-3j7yroNYJXhAaLdFku1qzHLRWDvkGoOvgy2_gAV90yJ2v4';
```

Save: `Ctrl+O`, `Enter`, `Ctrl+X`

---

### Step 4: Configure Apache Headers (Optional but Recommended)

```bash
sudo nano /etc/apache2/sites-available/open-rota.com.conf
```

Find your `<VirtualHost *:443>` section and add these headers:

```apache
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
```

Enable headers module and restart:
```bash
sudo a2enmod headers
sudo systemctl restart apache2
```

---

### Step 5: Set Proper Permissions
```bash
cd /var/www/rota-app
chown -R www-data:www-data /var/www/rota-app
chmod -R 755 /var/www/rota-app
chmod 600 includes/push_config.php
```

---

### Step 6: Delete Generator File (Security)
```bash
rm generate_vapid_keys.php
```

---

### Step 7: Add to .gitignore
```bash
echo "includes/push_config.php" >> .gitignore
```

---

## 🧪 Test Your Setup!

### Test 1: Check Service Worker
Visit in browser: `https://open-rota.com/service-worker.js`
- Should show the service worker code

### Test 2: Check Manifest
Visit: `https://open-rota.com/manifest.json`
- Should show JSON with app info

### Test 3: Enable Notifications on Dashboard
1. Visit: `https://open-rota.com/users/dashboard.php`
2. Wait 30 seconds (or refresh page)
3. Permission prompt should appear
4. Click "Enable Notifications"
5. Grant permission in browser
6. Check browser console (F12) - should see "Subscribed successfully"

### Test 4: Send Test Notification
Visit: `https://open-rota.com/test_push_notification.php`
- Should see success message
- Should receive notification on your device

---

## 🔍 Troubleshooting

### Check Database Table
```bash
mysql -u your_db_user -p -e "DESCRIBE your_database.push_subscriptions;"
```

### Check for Subscriptions
```bash
mysql -u your_db_user -p -e "SELECT * FROM your_database.push_subscriptions;"
```

### Check Logs
```bash
# Apache errors
sudo tail -20 /var/log/apache2/error.log

# PHP errors  
sudo tail -20 /var/log/php*-fpm.log
```

### Browser Console (F12)
Check for errors in:
1. Console tab
2. Application tab → Service Workers
3. Application tab → Manifest

---

## ✅ Quick Command Summary

Run these in order:
```bash
# 1. Secure config
chmod 600 includes/push_config.php
chown www-data:www-data includes/push_config.php

# 2. Setup database
php setup_push_database.php

# 3. Update JavaScript (Option B - hardcode)
nano js/push-notifications.js
# Find: const VAPID_PUBLIC_KEY = 'YOUR_PUBLIC_KEY_HERE';
# Replace with: const VAPID_PUBLIC_KEY = 'BDujEyd4Q023y8o2QJq1tOVTBbb-ShmmcPZwI8xP-3j7yroNYJXhAaLdFku1qzHLRWDvkGoOvgy2_gAV90yJ2v4';
# Save: Ctrl+O, Enter, Ctrl+X

# 4. Set permissions
cd /var/www/rota-app
chown -R www-data:www-data /var/www/rota-app
chmod -R 755 /var/www/rota-app
chmod 600 includes/push_config.php

# 5. Delete generator
rm generate_vapid_keys.php

# 6. Configure Apache (optional)
sudo nano /etc/apache2/sites-available/open-rota.com.conf
# Add service worker headers
sudo a2enmod headers
sudo systemctl restart apache2

# 7. Test!
# Visit: https://open-rota.com/test_push_notification.php
```

---

## 🎉 When Complete, You'll Have:

- ✅ Push notifications enabled
- ✅ Users can subscribe from any page
- ✅ You can send notifications from PHP code
- ✅ Notifications work even when browser is closed
- ✅ Works on desktop and mobile
- ✅ Secure and production-ready

---

## 🚀 Next: Integrate Into Your App

After testing works, integrate notifications into your features:

### Example: Notify on Shift Assignment
In `admin/add_shift.php`:
```php
require_once '../functions/push_notification_helper.php';

// After assigning shift
notifyShiftAssignment($user_id, [
    'id' => $shift_id,
    'date' => $shift_date,
    'time' => $start_time . '-' . $end_time
]);
```

### Example: Notify on Shift Swap Request
```php
require_once '../functions/push_notification_helper.php';

notifyShiftSwapRequest($target_user_id, $requester_name, $request_id);
```

---

## 📞 Need Help?

If something doesn't work:
1. Share the error message
2. Check browser console (F12)
3. Check Apache/PHP logs
4. Verify HTTPS is working (padlock in URL bar)

---

**You're almost done! Just run those commands and test! 🎉**
