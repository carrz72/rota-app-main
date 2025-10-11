# Coverage Page Digital Ocean Deployment Issues - Checklist

## Common Issues and Solutions

### 1. File Path Issues (FIXED)
- ✅ Replaced `__DIR__ . '/../functions/coverage_pay_helper.php'` with relative path `../functions/coverage_pay_helper.php`

### 2. Error Reporting (ADDED)
- ✅ Added comprehensive error reporting to coverage_requests.php
- ✅ Added try-catch blocks for file includes

### 3. File Permissions
Check these files have correct permissions (755 for directories, 644 for files):
- users/coverage_requests.php
- functions/coverage_pay_helper.php
- functions/branch_functions.php
- includes/auth.php
- includes/db.php

### 4. Database Connection
- Ensure database credentials in .env or config are correct for production
- Check database server is accessible from Digital Ocean
- Verify MySQL timezone settings

### 5. PHP Settings
Check php.ini settings:
- memory_limit (should be at least 128M)
- max_execution_time (at least 30 seconds)
- date.timezone set correctly

### 6. Required Database Tables
Ensure these tables exist:
- cross_branch_shift_requests
- branches
- users
- roles
- shifts
- shift_swaps

### 7. Dependencies
- All required files are uploaded
- Composer dependencies installed if any
- CSS/JS files uploaded

### 8. Debug Files Created
- debug_coverage.php - Comprehensive diagnostics
- test_coverage_minimal.php - Basic functionality test

## Testing Steps
1. Access test_coverage_minimal.php first
2. If that works, try debug_coverage.php
3. If both work, try the actual coverage_requests.php
4. Check server error logs for specific errors

## Server Error Log Locations
- Apache: /var/log/apache2/error.log
- Nginx: /var/log/nginx/error.log
- PHP: /var/log/php_errors.log