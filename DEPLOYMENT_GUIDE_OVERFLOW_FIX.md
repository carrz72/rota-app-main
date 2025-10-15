# Dashboard Overflow Fix - Deployment Guide

## Issue
Dashboard sections (Next Shift, Hours & Earnings, Upcoming Shifts) are overflowing when populated with data.

## What Was Fixed

### CSS Changes (commit f74fc4f)
All overflow fixes are in `css/dashboard.css`:

1. **Next Shift Section** - Added max-width, word-wrap, and overflow handling
2. **Earnings Section** - Added proper text wrapping and min-width constraints
3. **Upcoming Shifts Table** - Added horizontal scroll and responsive font sizing
4. **Responsive Breakpoints** - Enhanced mobile display at 768px and 480px

### Analytics Fixes (commit 3d4ebfa)
Fixed overnight shift calculations across:
- `admin/admin_dashboard.php`
- `users/dashboard.php`
- `users/settings.php`
- `functions/payroll_functions.php`

## Deployment Steps for DigitalOcean

### Step 1: SSH into Your DigitalOcean Server
```bash
ssh your_username@your_server_ip
```

### Step 2: Navigate to Application Directory
```bash
cd /path/to/rota-app
# Example: cd /var/www/html/rota-app
```

### Step 3: Pull Latest Changes from GitHub
```bash
git pull origin master
```

Expected output:
```
Updating f74fc4f..3d4ebfa
Fast-forward
 admin/admin_dashboard.php       | XX insertions(+), XX deletions(-)
 css/dashboard.css              | 55 insertions(+), 1 deletion(-)
 users/dashboard.php            | X insertions(+), X deletions(-)
 users/settings.php             | X insertions(+), X deletions(-)
 functions/payroll_functions.php| X insertions(+), X deletions(-)
```

### Step 4: Restart Services
```bash
# Restart PHP-FPM (choose the appropriate version)
sudo systemctl restart php8.1-fpm  # or php8.0-fpm, php7.4-fpm

# Restart Web Server
# For Apache:
sudo systemctl restart apache2

# For Nginx:
sudo systemctl restart nginx
```

### Step 5: Clear Server Cache (If Applicable)
```bash
# Clear Apache cache (if mod_cache is enabled)
sudo rm -rf /var/cache/apache2/*

# Clear PHP OpCache
sudo systemctl restart php8.1-fpm
```

### Step 6: Verify File Permissions
```bash
# Ensure CSS file is readable
chmod 644 css/dashboard.css

# Ensure directories are accessible
chmod 755 css/ users/ admin/ functions/
```

## User Instructions

### For End Users - Force Browser Refresh

**Windows/Linux:**
- Chrome/Edge/Firefox: `Ctrl + Shift + R` or `Ctrl + F5`
- Alternative: `Ctrl + Shift + Delete` → Clear cache → Refresh

**Mac:**
- Chrome/Safari/Firefox: `Cmd + Shift + R`
- Alternative: `Cmd + Option + E` (clear cache) → Refresh

### Mobile Devices:

**iOS Safari:**
1. Settings → Safari → Clear History and Website Data
2. Return to app and refresh

**Android Chrome:**
1. Menu → Settings → Privacy → Clear browsing data
2. Return to app and refresh

## Verification Steps

### 1. Check CSS File Version
Open browser developer tools (F12) → Network tab → Filter for CSS:
```
dashboard.css?v=1729087234567
```
The `?v=` timestamp should be current (within the last few minutes).

### 2. Inspect Element
Right-click on the overflowing section → Inspect:
- Check `.next-shift-details` has `overflow: hidden` and `max-width: 100%`
- Check `.upcoming-shifts-table` has `max-width: 100%`
- Check `.earnings-stats` has proper constraints

### 3. Test with Data
Navigate to dashboard and verify:
- [ ] Next shift section displays without horizontal scroll
- [ ] Long location names wrap properly
- [ ] Multiple colleague names display in wrapped list
- [ ] Upcoming shifts table fits within container (or scrolls horizontally on mobile)
- [ ] Earnings boxes don't overflow

### 4. Test Responsive
Use browser DevTools responsive mode to test:
- Desktop (1200px+): All content visible, no overflow
- Tablet (768px): Table responsive, touch-scrolling works
- Mobile (480px): Content stacks properly, no horizontal page scroll

## Troubleshooting

### Issue: CSS Not Updating After Pull

**Solution 1: Verify Cache-Busting is Active**
Check `users/dashboard.php` line 238:
```php
<link rel="stylesheet" href="../css/dashboard.css?v=<?php echo time(); ?>">
```

**Solution 2: Manually Increment Version**
If `time()` isn't working, use a fixed version number:
```php
<link rel="stylesheet" href="../css/dashboard.css?v=2.0.1">
```
Increment the version each time you update CSS.

**Solution 3: Clear Browser Cache Completely**
```bash
# Chrome/Chromium on Linux server (if accessing via browser on server)
rm -rf ~/.cache/google-chrome/
rm -rf ~/.config/google-chrome/
```

### Issue: Still Seeing Overflow on Mobile

**Check 1: Verify Mobile Viewport Meta Tag**
Ensure `users/dashboard.php` has:
```html
<meta name="viewport" content="width=device-width, initial-scale=1.0">
```

**Check 2: Test Specific Breakpoints**
Use DevTools responsive mode:
- 768px → Should trigger tablet styles
- 480px → Should trigger mobile styles

**Check 3: Inspect Computed Styles**
In DevTools, check which CSS rules are actually applied:
```css
.upcoming-shifts-table {
  max-width: 100%; /* Should be present */
  overflow-x: auto; /* Should be present at 768px */
}
```

### Issue: Analytics Showing Negative Hours

This was fixed in commit `3d4ebfa`. Verify fix:
```bash
grep -n "CASE WHEN end_time < start_time" admin/admin_dashboard.php
```

Should show multiple matches with overnight shift handling.

### Issue: Permissions Denied After Pull

Fix file permissions:
```bash
cd /path/to/rota-app
sudo chown -R www-data:www-data .
sudo chmod -R 755 .
sudo chmod -R 644 *.php css/*.css
```

## Cache-Busting Explanation

The dashboard uses PHP `time()` function to generate unique CSS URLs:
```php
href="../css/dashboard.css?v=1729087234567"
```

Each page load generates a new timestamp, forcing browsers to treat it as a new resource. This bypasses:
- Browser cache
- CDN cache
- Proxy cache

**Advantages:**
- Automatic - no manual version updates needed
- Effective - guaranteed fresh CSS every time
- Simple - one-line implementation

**Trade-off:**
- Prevents browser caching (slightly slower subsequent loads)
- For production, consider switching to semantic versioning after deployment stabilizes

## Alternative: Semantic Versioning

For production environments with heavy traffic, use fixed versions:

```php
<!-- Define version constant in config -->
<?php define('CSS_VERSION', '2.0.1'); ?>

<!-- Use in link tags -->
<link rel="stylesheet" href="../css/dashboard.css?v=<?php echo CSS_VERSION; ?>">
```

Update version number only when CSS changes. This allows browser caching while still having control.

## Rollback Plan

If issues occur after deployment:

```bash
# Revert to previous commit
git log --oneline  # Find commit hash before changes
git revert 3d4ebfa  # Revert analytics fix
git revert f74fc4f  # Revert overflow fix
git push origin master

# Or reset to specific commit
git reset --hard <previous_commit_hash>
git push origin master --force  # Use with caution!
```

## Support

If issues persist:
1. Check server error logs: `tail -f /var/log/apache2/error.log`
2. Check PHP error logs: `tail -f /var/log/php8.1-fpm.log`
3. Enable PHP error display (temporarily):
   ```php
   ini_set('display_errors', 1);
   error_reporting(E_ALL);
   ```
4. Check browser console for JavaScript errors (F12 → Console)

## Commits Included

- **f74fc4f**: Fix overflow issues in dashboard sections when populated with data
- **3d4ebfa**: CRITICAL FIX: Correct negative hours for overnight shifts in analytics

## Files Modified

### CSS Changes:
- `css/dashboard.css` (55 new lines for overflow handling)

### PHP Changes:
- `admin/admin_dashboard.php` (5 TIMESTAMPDIFF fixes)
- `users/dashboard.php` (1 TIMESTAMPDIFF fix)
- `users/settings.php` (1 TIMESTAMPDIFF fix)
- `functions/payroll_functions.php` (2 TIMESTAMPDIFF fixes)

## Testing Checklist

After deployment, verify:
- [ ] SSH access working
- [ ] Git pull successful
- [ ] Services restarted
- [ ] Dashboard loads without errors
- [ ] Next shift section: no overflow with populated data
- [ ] Hours & Earnings: displays correctly
- [ ] Upcoming Shifts table: responsive or scrollable
- [ ] Mobile view: no horizontal scrolling
- [ ] Admin analytics: no negative hour values
- [ ] Overnight shifts: calculate correctly
- [ ] CSS version parameter in URL is current

---

**Last Updated:** October 16, 2025  
**Tested Environments:** XAMPP (local), DigitalOcean (production)  
**Browser Compatibility:** Chrome 90+, Firefox 88+, Safari 14+, Edge 90+
