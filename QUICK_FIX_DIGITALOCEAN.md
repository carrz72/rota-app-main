# 🚨 Quick Fix for Your Error

## The Problem
You typed: `bash php generate_vapid_keys.php`

This tries to run `php` as a bash script, which fails.

## ✅ The Solution

You're already in bash, so just run:

```bash
php generate_vapid_keys.php
```

(Remove the word `bash` at the beginning!)

---

## 📋 Complete DigitalOcean Setup Commands

Copy and paste these one by one:

```bash
# 1. Navigate to your app directory
cd /var/www/rota-app

# 2. Generate VAPID keys (REMOVE 'bash' from your command!)
php generate_vapid_keys.php

# 3. Update the VAPID subject
nano includes/push_config.php
# Change this line:
# define('VAPID_SUBJECT', 'mailto:admin@openrota.com');
# To your domain:
# define('VAPID_SUBJECT', 'https://openrota.com');
# Save: Ctrl+O, Enter, then Ctrl+X

# 4. Secure the config file
chmod 600 includes/push_config.php
chown www-data:www-data includes/push_config.php

# 5. Run database setup
php setup_push_database.php

# 6. Verify database table was created
mysql -u your_db_user -p -e "SHOW TABLES LIKE 'push_subscriptions';" your_database_name

# 7. Install Composer dependencies (if not already done)
composer install --no-dev --optimize-autoloader

# 8. Restart Apache
sudo systemctl restart apache2

# 9. Test the setup
# Visit in browser: https://openrota.com/test_push_notification.php
```

---

## 🔍 If You Get Other Errors

### Error: "php: command not found"
```bash
# Check PHP version
php -v

# If not found, check which PHP you have
which php
which php8.2
which php8.1

# Use the full path:
/usr/bin/php generate_vapid_keys.php
# OR
php8.2 generate_vapid_keys.php
```

### Error: "Cannot find file generate_vapid_keys.php"
```bash
# Check you're in the right directory
pwd
# Should show: /var/www/rota-app

# List files to verify
ls -la generate_vapid_keys.php

# If not there, you may need to upload it from your local machine
```

### Error: "Permission denied"
```bash
# Add execute permission to the file
chmod +x generate_vapid_keys.php

# Or run with explicit PHP interpreter
/usr/bin/php generate_vapid_keys.php
```

---

## 📤 If Files Are Missing on Server

If you don't have the push notification files on DigitalOcean yet, you need to upload them:

### Option 1: Using Git (Recommended)
```bash
cd /var/www/rota-app
git pull origin master
```

### Option 2: Using SCP from your local machine
```bash
# Run this on your LOCAL Windows machine (PowerShell)
cd C:\xampp\htdocs\rota-app-main

# Upload the files
scp generate_vapid_keys.php carrz@your-server-ip:/var/www/rota-app/
scp setup_push_database.php carrz@your-server-ip:/var/www/rota-app/
scp -r functions/save_push_subscription.php carrz@your-server-ip:/var/www/rota-app/functions/
scp -r functions/delete_push_subscription.php carrz@your-server-ip:/var/www/rota-app/functions/
scp -r functions/push_notification_helper.php carrz@your-server-ip:/var/www/rota-app/functions/
scp -r js/push-notifications.js carrz@your-server-ip:/var/www/rota-app/js/
scp test_push_notification.php carrz@your-server-ip:/var/www/rota-app/
scp service-worker.js carrz@your-server-ip:/var/www/rota-app/
```

### Option 3: Using SFTP/FileZilla
1. Open FileZilla
2. Connect to your DigitalOcean server
3. Upload these files:
   - `generate_vapid_keys.php`
   - `setup_push_database.php`
   - `test_push_notification.php`
   - `functions/save_push_subscription.php`
   - `functions/delete_push_subscription.php`
   - `functions/push_notification_helper.php`
   - `js/push-notifications.js`
   - `service-worker.js` (updated version)
   - `includes/header.php` (updated version)
   - `css/navigation.css` (updated version)

---

## ✅ After Files Are Uploaded

Then run the setup commands from the top of this file!

---

## 🎯 Expected Output

When you run `php generate_vapid_keys.php` correctly, you should see:

```
✅ VAPID keys generated successfully!

Public Key: BMrynh06K7vNvRFfK9WHwJBpXmXSOj08-4T3FXdxGD2S3LrW0HHbxF0XtqOWwp3Vj3XLchLXvKJqS5K6kY6K-fU

✅ Configuration file created: includes/push_config.php

⚠️  IMPORTANT: Add includes/push_config.php to .gitignore!
```

---

## 📞 Still Having Issues?

Share the **exact error message** and I'll help you fix it!

Common things to check:
- ✅ Are you in the correct directory? (`/var/www/rota-app`)
- ✅ Do the files exist? (`ls -la generate_vapid_keys.php`)
- ✅ Is PHP installed? (`php -v`)
- ✅ Are you logged in as the right user? (`whoami`)
