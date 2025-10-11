# Database Schema Fixes for Digital Ocean Deployment

## Issue Fixed: Column not found errors

### Root Cause:
The `coverage_requests.php` file was using incorrect column names that don't match the actual database schema.

### Table Schema (shift_swaps):
```sql
CREATE TABLE IF NOT EXISTS shift_swaps (
  id INT AUTO_INCREMENT PRIMARY KEY,
  from_user_id INT NOT NULL,           -- NOT proposer_user_id
  to_user_id INT NOT NULL,
  from_shift_id INT NOT NULL,          -- NOT offered_shift_id  
  to_shift_id INT DEFAULT NULL,
  request_id INT DEFAULT NULL,
  status ENUM('proposed','accepted','declined','cancelled') DEFAULT 'proposed',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
```

### Changes Made:

#### 1. Fixed JOIN conditions:
```sql
-- OLD (incorrect):
JOIN users u ON ss.proposer_user_id = u.id
JOIN shifts s ON ss.offered_shift_id = s.id

-- NEW (correct):
JOIN users u ON ss.from_user_id = u.id  
JOIN shifts s ON ss.from_shift_id = s.id
```

#### 2. Fixed WHERE clauses:
```sql
-- OLD (incorrect):
WHERE cbr.requested_by_user_id = ? OR ss.proposer_user_id = ?

-- NEW (correct):  
WHERE cbr.requested_by_user_id = ? OR ss.from_user_id = ?
```

#### 3. Fixed INSERT statements:
```sql
-- OLD (incorrect):
INSERT INTO shift_swaps (request_id, offered_shift_id, proposer_user_id, status, created_at)

-- NEW (correct):
INSERT INTO shift_swaps (request_id, from_shift_id, from_user_id, status, created_at)
```

#### 4. Fixed PHP references:
```php
// OLD (incorrect):
if ($swap['status'] === 'pending' && $swap['proposer_user_id'] != $user_id)

// NEW (correct):
if ($swap['status'] === 'pending' && $swap['from_user_id'] != $user_id)
```

### Testing:
After these fixes, the coverage_requests.php page should load without SQL errors on Digital Ocean.

### Note:
The SELECT aliases like `s.id AS offered_shift_id` are still used in the result processing, which maintains compatibility with the rest of the code while fixing the underlying database schema mismatch.