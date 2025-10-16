# 🚨 PHP Intl Extension Missing - Quick Fix

## The Problem
The `Normalizer` class is part of PHP's `intl` extension, which is not installed on your server.

---

## ✅ Solution: Install PHP Intl Extension

Run these commands on your **DigitalOcean server**:

```bash
# Check your PHP version first
php -v

# For PHP 8.2 (most common):
sudo apt update
sudo apt install php8.2-intl

# For PHP 8.1:
sudo apt install php8.1-intl

# For PHP 8.0:
sudo apt install php8.0-intl

# For PHP 7.4:
sudo apt install php7.4-intl
```

---

## 🔄 Restart Services

After installation:

```bash
# Restart Apache
sudo systemctl restart apache2

# Restart PHP-FPM (if using it)
sudo systemctl restart php8.2-fpm
```

---

## ✅ Verify Installation

Check if intl is now enabled:

```bash
php -m | grep intl
```

Should output: `intl`

Or check all extensions:
```bash
php -m
```

---

## 🚀 Now Try Composer Again

```bash
cd /var/www/rota-app

# Remove old broken vendor
rm -rf vendor/ composer.lock

# Install dependencies
composer install --no-dev --optimize-autoloader --ignore-platform-reqs
```

---

## 📦 Install Other Common Missing Extensions

While you're at it, install other commonly needed PHP extensions:

```bash
# For PHP 8.2 (adjust version if needed):
sudo apt install -y \
  php8.2-intl \
  php8.2-mbstring \
  php8.2-xml \
  php8.2-curl \
  php8.2-zip \
  php8.2-gd \
  php8.2-mysql \
  php8.2-bcmath

# Restart Apache
sudo systemctl restart apache2
```

---

## 🔍 Check What's Installed

View all installed PHP packages:
```bash
dpkg -l | grep php
```

View enabled PHP modules:
```bash
php -m
```

---

## ⚠️ If apt install Fails

If you get "package not found":

### 1. Check your PHP version:
```bash
php -v
```

### 2. Use the correct package name:
```bash
# If PHP 8.2:
sudo apt install php8.2-intl

# If PHP 8.1:
sudo apt install php8.1-intl

# If PHP 8.0:
sudo apt install php8.0-intl

# If PHP 7.4:
sudo apt install php7.4-intl
```

### 3. Update package list:
```bash
sudo apt update
sudo apt upgrade
```

---

## 📋 Complete Setup After Installing Intl

```bash
# 1. Install intl extension
sudo apt install php8.2-intl

# 2. Restart Apache
sudo systemctl restart apache2

# 3. Verify installation
php -m | grep intl

# 4. Navigate to app
cd /var/www/rota-app

# 5. Clean vendor folder
rm -rf vendor/ composer.lock

# 6. Install Composer dependencies
composer install --no-dev --optimize-autoloader --ignore-platform-reqs

# 7. Generate VAPID keys
php generate_vapid_keys.php

# 8. Edit config
nano includes/push_config.php
# Change: define('VAPID_SUBJECT', 'https://openrota.com');
# Save: Ctrl+O, Enter, Ctrl+X

# 9. Secure config
chmod 600 includes/push_config.php
chown www-data:www-data includes/push_config.php

# 10. Setup database
php setup_push_database.php

# 11. Restart Apache
sudo systemctl restart apache2
```

---

## 🎯 Alternative: Upload Vendor Folder Instead

If you can't install PHP extensions (shared hosting), upload the `vendor` folder from your local machine:

**On your LOCAL Windows machine:**
```powershell
cd C:\xampp\htdocs\rota-app-main

# Upload complete vendor folder
scp -r vendor carrz@openrota.com:/var/www/rota-app/
```

**On your server:**
```bash
# Set permissions
cd /var/www/rota-app
chown -R www-data:www-data vendor/
chmod -R 755 vendor/

# Test
php generate_vapid_keys.php
```

---

## 🔧 Troubleshooting

### Error: "E: Unable to locate package php8.2-intl"

Your repositories might not have PHP 8.2. Try:

```bash
# Check available PHP versions
apt-cache search php-intl

# Install available version
sudo apt install php-intl
```

Or add PHP repository:

```bash
# Add Ondrej's PHP repository
sudo apt install software-properties-common
sudo add-apt-repository ppa:ondrej/php
sudo apt update

# Now install
sudo apt install php8.2-intl
```

### Error: "Permission denied"

Use sudo:
```bash
sudo apt install php8.2-intl
```

### Check if extension loaded in Apache vs CLI

Sometimes extension is loaded in CLI but not Apache:

```bash
# CLI PHP version
php -v
php -m | grep intl

# Apache PHP version
php -i | grep "Loaded Configuration File"
```

If different, check Apache's php.ini:
```bash
sudo nano /etc/php/8.2/apache2/php.ini
```

Ensure this line is uncommented:
```
extension=intl
```

---

## ✅ Expected Output After Fix

After installing intl and running `composer install`:

```
Loading composer repositories with package information
Installing dependencies from lock file
Package operations: 15 installs, 0 updates, 0 removals
  - Installing ralouphie/getallheaders (3.0.3): Extracting archive
  - Installing psr/http-message (1.0.1): Extracting archive
  - Installing guzzlehttp/psr7 (2.4.3): Extracting archive
  - Installing minishlink/web-push (v9.0.2): Extracting archive
  ... (more packages)
Generating optimized autoload files
15 packages you are using are looking for funding.
Use the `composer fund` command to find out more!
```

Then:
```bash
php generate_vapid_keys.php
```

Should output:
```
✅ VAPID keys generated successfully!

Public Key: BMrynh06K7vNvRFfK9WHwJBpXmXSOj08-4T3FXdxGD2S3LrW0HHbxF0XtqOWwp3Vj3XLchLXvKJqS5K6kY6K-fU

✅ Configuration file created: includes/push_config.php

⚠️  IMPORTANT: Add includes/push_config.php to .gitignore!
```

---

## 📊 Summary

**Root Cause**: Missing PHP `intl` extension
**Fix**: `sudo apt install php8.2-intl`
**Time**: 1-2 minutes

---

## 💡 Pro Tip

Check all required PHP extensions for your app:

```bash
# List all modules
php -m

# Check specific ones you need
php -m | grep -E 'intl|mbstring|xml|curl|zip|gd|mysql'
```

Common extensions for PHP web apps:
- ✅ intl (internationalization)
- ✅ mbstring (multibyte strings)
- ✅ xml (XML parsing)
- ✅ curl (HTTP requests)
- ✅ zip (compression)
- ✅ gd (image manipulation)
- ✅ mysql/mysqli (database)
- ✅ bcmath (precision math)

---

## 📞 Next Steps

1. Install intl: `sudo apt install php8.2-intl`
2. Restart Apache: `sudo systemctl restart apache2`
3. Run composer: `composer install --no-dev --optimize-autoloader --ignore-platform-reqs`
4. Generate keys: `php generate_vapid_keys.php`

You're almost there! 🚀
