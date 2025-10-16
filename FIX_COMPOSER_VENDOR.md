# 🚨 Composer Dependencies Issue - Quick Fix

## The Problem
Your `vendor` folder on the server is incomplete or corrupted. The `ralouphie/getallheaders` package is missing.

---

## ✅ Solution: Reinstall Composer Dependencies

Run these commands on your **DigitalOcean server**:

```bash
# Navigate to your app
cd /var/www/rota-app

# Remove the broken vendor folder
rm -rf vendor/

# Remove composer.lock to get fresh dependencies
rm -f composer.lock

# Reinstall everything
composer install --no-dev --optimize-autoloader --ignore-platform-reqs

# If that fails, try:
composer update --no-dev --optimize-autoloader --ignore-platform-reqs
```

---

## 🔍 If Composer Not Installed

Check if composer is installed:
```bash
composer --version
```

If not found, install it:
```bash
# Install Composer
curl -sS https://getcomposer.org/installer | sudo php -- --install-dir=/usr/local/bin --filename=composer

# Verify installation
composer --version
```

---

## 📦 Alternative: Upload Vendor Folder from Local Machine

If Composer installation fails, upload your complete `vendor` folder from Windows:

### Using SCP (from your LOCAL Windows PowerShell):

```powershell
cd C:\xampp\htdocs\rota-app-main

# This will take a few minutes (large folder)
scp -r vendor carrz@openrota.com:/var/www/rota-app/
```

### Or using FileZilla:
1. Open FileZilla
2. Connect to your server
3. Navigate to `/var/www/rota-app/`
4. Delete the broken `vendor` folder on the server
5. Upload your local `vendor` folder (this will take 5-10 minutes)

---

## 🚀 Complete Setup After Fixing Vendor

Once vendor folder is fixed:

```bash
# 1. Set proper permissions
cd /var/www/rota-app
chown -R www-data:www-data vendor/
chmod -R 755 vendor/

# 2. Generate VAPID keys
php generate_vapid_keys.php

# 3. Edit config file
nano includes/push_config.php
# Change: define('VAPID_SUBJECT', 'https://openrota.com');
# Save: Ctrl+O, Enter, Ctrl+X

# 4. Secure config
chmod 600 includes/push_config.php
chown www-data:www-data includes/push_config.php

# 5. Setup database
php setup_push_database.php

# 6. Restart Apache
sudo systemctl restart apache2
```

---

## 🎯 Recommended Solution

**Best option:** Clean install of dependencies on the server:

```bash
cd /var/www/rota-app

# Clean everything
rm -rf vendor/
rm composer.lock

# Fresh install
composer install --no-dev --optimize-autoloader --ignore-platform-reqs

# If you get memory errors:
php -d memory_limit=-1 /usr/local/bin/composer install --no-dev --optimize-autoloader --ignore-platform-reqs
```

---

## ⚠️ What Went Wrong

Possible causes:
1. **Incomplete upload** - Vendor folder wasn't fully uploaded
2. **Git ignored vendor** - `.gitignore` has `vendor/` in it (common practice)
3. **Corrupted during transfer** - File transfer was interrupted
4. **Wrong permissions** - Server can't access the files

---

## 📝 Expected Output After Fix

When `composer install` succeeds, you should see:

```
Loading composer repositories with package information
Installing dependencies from lock file
Package operations: 15 installs, 0 updates, 0 removals
  - Installing ralouphie/getallheaders (3.0.3)
  - Installing psr/http-message (1.0.1)
  - Installing guzzlehttp/psr7 (2.4.3)
  - Installing minishlink/web-push (v9.0.2)
  ... (and more packages)
Generating optimized autoload files
```

Then:
```bash
php generate_vapid_keys.php
```

Should output:
```
✅ VAPID keys generated successfully!

Public Key: [long string]

✅ Configuration file created: includes/push_config.php
```

---

## 🔧 Troubleshooting Other Errors

### Error: "composer: command not found"
```bash
# Install Composer
curl -sS https://getcomposer.org/installer | sudo php -- --install-dir=/usr/local/bin --filename=composer
```

### Error: "PHP Fatal error: Allowed memory size exhausted"
```bash
# Increase memory limit temporarily
php -d memory_limit=-1 /usr/local/bin/composer install --no-dev --optimize-autoloader --ignore-platform-reqs
```

### Error: "Your requirements could not be resolved"
```bash
# Use ignore-platform-reqs flag
composer install --no-dev --optimize-autoloader --ignore-platform-reqs
```

### Error: "Permission denied"
```bash
# Fix permissions
sudo chown -R $USER:$USER /var/www/rota-app
```

---

## ✅ Quick Verification

After running composer install, verify packages:

```bash
# Check if web-push is installed
ls -la vendor/minishlink/web-push/

# Check if ralouphie package is there
ls -la vendor/ralouphie/getallheaders/

# List all installed packages
composer show
```

You should see:
- `minishlink/web-push`
- `ralouphie/getallheaders`
- `guzzlehttp/guzzle`
- And 10+ other dependencies

---

## 💡 Pro Tip: .gitignore for Vendor

If using Git, **vendor/** should be in `.gitignore` (it's best practice).

Then on the server, ALWAYS run:
```bash
composer install
```

After pulling from Git.

This ensures dependencies match exactly what's in `composer.lock`.

---

## 📋 Summary

**Run these 3 commands on your server:**

```bash
cd /var/www/rota-app
rm -rf vendor/ composer.lock
composer install --no-dev --optimize-autoloader --ignore-platform-reqs
```

Then try `php generate_vapid_keys.php` again!

---

## 📞 Still Getting Errors?

Share the output of:
```bash
composer diagnose
php -v
ls -la vendor/
```

I'll help you fix it! 🚀
