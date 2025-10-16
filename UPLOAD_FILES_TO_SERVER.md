# 🚨 Files Not on Server Yet!

## The Problem
The push notification files are on your **local machine** (Windows), but NOT on your **DigitalOcean server** yet!

---

## ✅ Solution: Upload Files to DigitalOcean

### **Method 1: Using Git (Easiest & Recommended)**

If you're using Git:

```bash
# On your LOCAL Windows machine (PowerShell):
cd C:\xampp\htdocs\rota-app-main

# Commit the new files
git add .
git commit -m "Add push notification system"
git push origin master

# Then on your DigitalOcean server:
cd /var/www/rota-app
git pull origin master
```

---

### **Method 2: Using SCP (If Git Not Setup)**

Run these commands on your **LOCAL Windows PowerShell**:

```powershell
# Navigate to your project
cd C:\xampp\htdocs\rota-app-main

# Upload all push notification files
scp generate_vapid_keys.php carrz@openrota.com:/var/www/rota-app/
scp setup_push_database.php carrz@openrota.com:/var/www/rota-app/
scp test_push_notification.php carrz@openrota.com:/var/www/rota-app/
scp push_subscriptions_table.sql carrz@openrota.com:/var/www/rota-app/

# Upload function files
scp functions/save_push_subscription.php carrz@openrota.com:/var/www/rota-app/functions/
scp functions/delete_push_subscription.php carrz@openrota.com:/var/www/rota-app/functions/
scp functions/push_notification_helper.php carrz@openrota.com:/var/www/rota-app/functions/

# Upload JavaScript
scp js/push-notifications.js carrz@openrota.com:/var/www/rota-app/js/

# Upload updated files
scp service-worker.js carrz@openrota.com:/var/www/rota-app/
scp includes/header.php carrz@openrota.com:/var/www/rota-app/includes/
scp css/navigation.css carrz@openrota.com:/var/www/rota-app/css/

# Upload vendor folder (if composer packages not on server)
scp -r vendor carrz@openrota.com:/var/www/rota-app/
```

**Note:** Replace `openrota.com` with your server IP if DNS not setup yet: `carrz@your.server.ip.address`

---

### **Method 3: Using FileZilla/WinSCP (Visual GUI)**

1. **Download FileZilla Client**: https://filezilla-project.org/download.php?type=client

2. **Connect to your server:**
   - Host: `sftp://openrota.com` (or your server IP)
   - Username: `carrz`
   - Password: Your SSH password
   - Port: `22`

3. **Upload these files from left (local) to right (server):**

   **Root directory files:**
   - `generate_vapid_keys.php`
   - `setup_push_database.php`
   - `test_push_notification.php`
   - `push_subscriptions_table.sql`
   - `service-worker.js` ⬅️ (UPDATED)
   - `composer.json` ⬅️ (If updated)
   - `composer.lock` ⬅️ (If updated)

   **functions/ folder:**
   - `save_push_subscription.php` ⬅️ (NEW)
   - `delete_push_subscription.php` ⬅️ (NEW)
   - `push_notification_helper.php` ⬅️ (NEW)

   **js/ folder:**
   - `push-notifications.js` ⬅️ (NEW)

   **includes/ folder:**
   - `header.php` ⬅️ (UPDATED)

   **css/ folder:**
   - `navigation.css` ⬅️ (UPDATED)

   **vendor/ folder:**
   - Upload entire `vendor/` folder if not already on server

---

## 🚀 After Files Are Uploaded

Run these commands on your **DigitalOcean server**:

```bash
# 1. Navigate to your app
cd /var/www/rota-app

# 2. Verify files are there
ls -la generate_vapid_keys.php
ls -la functions/push_notification_helper.php
ls -la js/push-notifications.js

# 3. Install Composer dependencies (if vendor folder not uploaded)
composer install --no-dev --optimize-autoloader --ignore-platform-reqs

# 4. Generate VAPID keys
php generate_vapid_keys.php

# 5. Edit the config file
nano includes/push_config.php
# Change: define('VAPID_SUBJECT', 'https://openrota.com');
# Save: Ctrl+O, Enter, Ctrl+X

# 6. Secure the config
chmod 600 includes/push_config.php
chown www-data:www-data includes/push_config.php

# 7. Setup database
php setup_push_database.php

# 8. Set proper permissions
chown -R www-data:www-data /var/www/rota-app
chmod -R 755 /var/www/rota-app
chmod 600 includes/push_config.php

# 9. Restart Apache
sudo systemctl restart apache2

# 10. Test!
# Visit: https://openrota.com/test_push_notification.php
```

---

## 📦 Alternative: Install Composer Packages on Server

If you don't want to upload the `vendor/` folder (it's large), install on server:

```bash
cd /var/www/rota-app

# Install the web-push library
composer require minishlink/web-push --ignore-platform-reqs
```

---

## 🔍 Troubleshooting

### Check if files exist on server:
```bash
cd /var/www/rota-app
ls -la *.php
ls -la functions/*.php
ls -la js/*.js
```

### Check vendor folder:
```bash
ls -la vendor/minishlink/
```

### If composer not installed on server:
```bash
# Install Composer
curl -sS https://getcomposer.org/installer | sudo php -- --install-dir=/usr/local/bin --filename=composer

# Verify
composer --version
```

---

## ✅ Quick Checklist

Before running commands on server:

- [ ] `generate_vapid_keys.php` exists on server
- [ ] `setup_push_database.php` exists on server
- [ ] `functions/push_notification_helper.php` exists on server
- [ ] `js/push-notifications.js` exists on server
- [ ] `vendor/minishlink/web-push/` folder exists on server
- [ ] `service-worker.js` updated on server
- [ ] `includes/header.php` updated on server
- [ ] `css/navigation.css` updated on server

---

## 🎯 Recommended: Use Git for Deployment

This is the easiest long-term solution:

```bash
# On your LOCAL machine:
cd C:\xampp\htdocs\rota-app-main
git add .
git commit -m "Add push notifications"
git push

# On your SERVER:
cd /var/www/rota-app
git pull
composer install --no-dev --optimize-autoloader
```

---

## 💡 Pro Tip

Create a deployment script! Save this as `deploy_push_notifications.sh` on your server:

```bash
#!/bin/bash
cd /var/www/rota-app
git pull origin master
composer install --no-dev --optimize-autoloader
chown -R www-data:www-data /var/www/rota-app
chmod -R 755 /var/www/rota-app
chmod 600 includes/push_config.php
sudo systemctl restart apache2
echo "✅ Deployment complete!"
```

Make it executable:
```bash
chmod +x deploy_push_notifications.sh
```

Then just run:
```bash
./deploy_push_notifications.sh
```

---

## 📞 Still Stuck?

Let me know which method you prefer:
1. **Git** (easiest)
2. **SCP** (command line)
3. **FileZilla** (visual)

I'll give you the exact steps!
