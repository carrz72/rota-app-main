# 🚀 DigitalOcean Push Notifications Deployment Guide

## ⚠️ Critical Changes Needed for Production

### 1. 🔐 Generate NEW Production VAPID Keys

**IMPORTANT**: Your current keys are DEVELOPMENT ONLY!

#### Step 1: SSH into your DigitalOcean server
```bash
ssh root@your-droplet-ip
```

#### Step 2: Navigate to your app
```bash
cd /var/www/html/rota-app-main  # Or wherever your app is
```

#### Step 3: Generate NEW keys
```bash
php generate_vapid_keys.php
```

This will create `includes/push_config.php` with **production keys**.

#### Step 4: Secure the config file
```bash
chmod 600 includes/push_config.php
chown www-data:www-data includes/push_config.php
```

---

### 2. 🌐 Update VAPID Subject

Edit `includes/push_config.php` on your server:

```php
// Change from:
define('VAPID_SUBJECT', 'mailto:admin@openrota.com');

// To your actual domain:
define('VAPID_SUBJECT', 'https://yourdomain.com');
// OR your actual email:
define('VAPID_SUBJECT', 'mailto:your-actual-email@yourdomain.com');
```

---

### 3. ✅ SSL/HTTPS Verification

Push notifications **REQUIRE HTTPS** in production!

#### Check if you have SSL:
```bash
ls -la /etc/letsencrypt/live/yourdomain.com/
```

#### If NO SSL certificate:

**Option A: Using Certbot (Recommended)**
```bash
# Install Certbot
sudo apt update
sudo apt install certbot python3-certbot-apache

# Get certificate
sudo certbot --apache -d yourdomain.com -d www.yourdomain.com

# Auto-renewal (should be automatic)
sudo certbot renew --dry-run
```

**Option B: Using DigitalOcean Load Balancer**
- Go to DigitalOcean Dashboard
- Create Load Balancer
- Enable "HTTPS" with Let's Encrypt certificate
- Point to your droplet

---

### 4. 📁 Update Service Worker Paths

Your `service-worker.js` uses relative paths. Update for production:

#### Before deployment, update these URLs in `service-worker.js`:

```javascript
// Change icon/badge paths to absolute URLs
icon: data.icon || 'https://yourdomain.com/images/icon.png',
badge: data.badge || 'https://yourdomain.com/images/icon.png',

// In the push event listener default data:
let data = {
    title: 'Open Rota',
    body: 'You have a new notification',
    icon: 'https://yourdomain.com/images/icon.png',
    badge: 'https://yourdomain.com/images/icon.png',
    url: '/users/dashboard.php'
};
```

**OR** keep relative paths (should work if properly configured).

---

### 5. 🔒 Secure Your Config File

#### Create/Update `.gitignore`:

```bash
# On your DigitalOcean server
cd /var/www/html/rota-app-main
nano .gitignore
```

Add these lines:
```
includes/push_config.php
vendor/
.env
```

#### Backup your VAPID keys somewhere safe:
```bash
# Copy to secure location
sudo cp includes/push_config.php /root/backup/push_config.php.backup
```

---

### 6. 📦 Install Composer Dependencies

If you haven't already deployed the vendor folder:

```bash
cd /var/www/html/rota-app-main
composer install --no-dev --optimize-autoloader
```

If you get platform requirement errors:
```bash
composer install --no-dev --optimize-autoloader --ignore-platform-reqs
```

---

### 7. 🗄️ Setup Production Database

#### Run the database setup:
```bash
php setup_push_database.php
```

#### Verify table was created:
```bash
mysql -u your_db_user -p
```

```sql
USE your_database_name;
SHOW TABLES LIKE 'push_subscriptions';
DESCRIBE push_subscriptions;
SELECT COUNT(*) FROM push_subscriptions;
EXIT;
```

---

### 8. ⚙️ Apache Configuration

#### Ensure mod_rewrite and headers are enabled:
```bash
sudo a2enmod rewrite
sudo a2enmod headers
sudo systemctl restart apache2
```

#### Update Apache VirtualHost for service worker:

Edit your site config:
```bash
sudo nano /etc/apache2/sites-available/yourdomain.com.conf
```

Add these headers:
```apache
<VirtualHost *:443>
    ServerName yourdomain.com
    DocumentRoot /var/www/html/rota-app-main
    
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
    
    # Enable CORS for push notifications (if needed)
    Header set Access-Control-Allow-Origin "https://yourdomain.com"
    Header set Access-Control-Allow-Credentials "true"
    
    # ... rest of your config
</VirtualHost>
```

Restart Apache:
```bash
sudo systemctl restart apache2
```

---

### 9. 🔥 Firewall Configuration

Ensure HTTPS is allowed:
```bash
sudo ufw status
sudo ufw allow 443/tcp
sudo ufw allow 80/tcp
sudo ufw reload
```

---

### 10. 📱 Test Push Notifications

#### A. Enable notifications on production:
1. Visit `https://yourdomain.com/users/dashboard.php`
2. Grant permission when prompted
3. Check browser console for subscription

#### B. Send test notification:
Visit `https://yourdomain.com/test_push_notification.php`

#### C. Check logs if issues:
```bash
# Apache error logs
sudo tail -f /var/log/apache2/error.log

# PHP error logs
sudo tail -f /var/log/php8.2-fpm.log  # or your PHP version
```

---

## 🔧 Environment-Specific Configuration

### Create Config File for Environment Detection

Create `includes/config.php`:

```php
<?php
// Detect environment
if ($_SERVER['HTTP_HOST'] === 'localhost' || 
    $_SERVER['HTTP_HOST'] === '127.0.0.1' ||
    strpos($_SERVER['HTTP_HOST'], 'localhost:') === 0) {
    
    // DEVELOPMENT
    define('ENVIRONMENT', 'development');
    define('BASE_URL', 'http://localhost/rota-app-main');
    define('SECURE_COOKIES', false);
    
} else {
    // PRODUCTION
    define('ENVIRONMENT', 'production');
    define('BASE_URL', 'https://yourdomain.com');
    define('SECURE_COOKIES', true);
    
    // Force HTTPS
    if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
        header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
        exit;
    }
}

// Include push config
require_once __DIR__ . '/push_config.php';
```

Then update your files to use `BASE_URL`:

```php
// In your notification helper
$icon = BASE_URL . '/images/icon.png';
```

---

## 📊 Monitoring & Maintenance

### 1. Monitor Subscription Count
```bash
mysql -u your_db_user -p -e "SELECT COUNT(*) as total_subscriptions FROM your_database.push_subscriptions;"
```

### 2. Clean Expired Subscriptions

Create `cron_cleanup_subscriptions.php`:
```php
<?php
require_once 'includes/db.php';

// Delete subscriptions older than 90 days with no activity
$stmt = $conn->prepare("
    DELETE FROM push_subscriptions 
    WHERE updated_at < DATE_SUB(NOW(), INTERVAL 90 DAY)
");
$stmt->execute();

echo "Cleaned up old subscriptions: " . $stmt->rowCount() . "\n";
```

Add to crontab:
```bash
crontab -e
```

Add line:
```
0 3 * * 0 cd /var/www/html/rota-app-main && php cron_cleanup_subscriptions.php >> /var/log/cleanup_subscriptions.log 2>&1
```

### 3. Set Up Error Logging

Create `logs` directory:
```bash
mkdir -p /var/www/html/rota-app-main/logs
chmod 755 /var/www/html/rota-app-main/logs
chown www-data:www-data /var/www/html/rota-app-main/logs
```

Update `functions/push_notification_helper.php` to log errors:
```php
// Add at top of file
define('PUSH_LOG_FILE', __DIR__ . '/../logs/push_notifications.log');

// In sendPushNotification function, add logging:
if (!$report->isSuccess()) {
    error_log(date('Y-m-d H:i:s') . " - Failed to send to user $user_id: " . 
              $report->getReason() . "\n", 3, PUSH_LOG_FILE);
}
```

---

## 🧪 Testing Checklist for DigitalOcean

- [ ] SSH access working
- [ ] HTTPS enabled and working
- [ ] New VAPID keys generated (production)
- [ ] VAPID subject updated
- [ ] Composer dependencies installed
- [ ] Database table created
- [ ] Apache headers configured
- [ ] Service worker accessible at `/service-worker.js`
- [ ] Manifest accessible at `/manifest.json`
- [ ] Test notification sent successfully
- [ ] Notification received on desktop
- [ ] Notification received on mobile
- [ ] Notification click opens correct URL
- [ ] Firewall rules allow HTTPS
- [ ] Config file secured (chmod 600)
- [ ] Config file in .gitignore
- [ ] Error logging configured
- [ ] Cleanup cron job added (optional)

---

## 🚨 Common Issues & Solutions

### Issue: "Failed to subscribe"
**Solution**: 
- Check browser console for errors
- Verify HTTPS is working
- Ensure service worker is registered
- Check VAPID public key matches in both files

### Issue: "No subscriptions found"
**Solution**:
- Run database setup: `php setup_push_database.php`
- Check MySQL is running: `sudo systemctl status mysql`
- Verify database credentials in `includes/db.php`

### Issue: "Invalid VAPID keys"
**Solution**:
- Generate NEW keys: `php generate_vapid_keys.php`
- Must be different from development keys
- Ensure both public and private keys are correct

### Issue: Service worker not updating
**Solution**:
- Change version in service-worker.js: `v7` → `v8`
- Clear browser cache
- Unregister old service worker in browser DevTools

### Issue: Notifications not appearing
**Solution**:
- Check browser notification settings
- Verify HTTPS (not HTTP)
- Check service worker console for errors
- Test with different browser

---

## 📈 Performance Optimization

### 1. Enable PHP OPcache

Edit PHP config:
```bash
sudo nano /etc/php/8.2/apache2/php.ini
```

Enable:
```ini
opcache.enable=1
opcache.memory_consumption=128
opcache.max_accelerated_files=10000
opcache.revalidate_freq=60
```

Restart:
```bash
sudo systemctl restart apache2
```

### 2. Optimize Composer Autoloader
```bash
composer dump-autoload --optimize --no-dev
```

### 3. Enable Gzip Compression

Add to Apache config:
```apache
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css text/javascript application/javascript application/json
</IfModule>
```

---

## 🎯 Final Deployment Steps

```bash
# 1. Connect to server
ssh root@your-droplet-ip

# 2. Navigate to app
cd /var/www/html/rota-app-main

# 3. Generate production keys
php generate_vapid_keys.php

# 4. Update VAPID subject
nano includes/push_config.php
# Change to: https://yourdomain.com

# 5. Secure config
chmod 600 includes/push_config.php

# 6. Setup database
php setup_push_database.php

# 7. Install dependencies (if needed)
composer install --no-dev --optimize-autoloader

# 8. Configure Apache headers
sudo nano /etc/apache2/sites-available/yourdomain.com.conf
# Add service worker headers (see section 8)

# 9. Restart Apache
sudo systemctl restart apache2

# 10. Test!
# Visit: https://yourdomain.com/test_push_notification.php
```

---

## 🎉 Success Indicators

✅ **You're all set when:**
- HTTPS padlock shows in browser
- Service worker registers without errors
- Notification permission prompt appears
- Test notification received
- Notification click opens correct page
- Works on mobile devices
- Works when browser is closed

---

## 📞 Need Help?

If you encounter issues:
1. Check Apache error logs: `sudo tail -f /var/log/apache2/error.log`
2. Check PHP logs: `sudo tail -f /var/log/php*-fpm.log`
3. Check browser console (F12)
4. Verify all checklist items above

**Most common fix**: Regenerate VAPID keys and ensure HTTPS is working!
