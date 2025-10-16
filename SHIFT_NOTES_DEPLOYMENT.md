# Shift Notes Feature - Deployment Guide

## 🎉 Feature Overview

The **Shift Notes** feature allows employees to leave handover notes for shifts, improving communication between shifts and ensuring important information is passed along.

### Key Features:
- ✅ Add notes to any shift
- ✅ Mark notes as important (⭐)
- ✅ View all notes for a shift in chronological order
- ✅ Push notifications when new notes are added
- ✅ Filter notes (All / Important only)
- ✅ Edit and delete own notes (or admin can edit any)
- ✅ Mobile-responsive design
- ✅ Integrated into existing shift management

---

## 📁 Files Created/Modified

### New Files:
1. ✅ `setup_shift_notes.sql` - Database schema
2. ✅ `functions/shift_notes_api.php` - Backend API (CRUD operations)
3. ✅ `users/shift_notes.php` - Frontend UI page
4. ✅ `css/shift_notes.css` - Styling
5. ✅ `SHIFT_NOTES_DEPLOYMENT.md` - This file

### Modified Files:
1. ✅ `functions/send_shift_notification.php` - Added `notifyShiftNote()` function
2. ✅ `users/shifts.php` - Added "Notes" button to each shift
3. ✅ `admin/manage_shifts.php` - Added "Notes" button for admins

---

## 🚀 Deployment Steps

### Step 1: Update Code on Server
```bash
cd /var/www/rota-app
sudo git pull
```

### Step 2: Create Database Table
```bash
sudo mysql -u root -p rota_app < setup_shift_notes.sql
```

Enter MySQL password when prompted: `Musicman1!`

### Step 3: Verify Table Created
```bash
sudo mysql -u root -p rota_app -e "DESCRIBE shift_notes;"
```

**Expected output:**
```
+-------------+--------------+------+-----+-------------------+
| Field       | Type         | Null | Key | Default           |
+-------------+--------------+------+-----+-------------------+
| id          | int          | NO   | PRI | NULL              |
| shift_id    | int          | NO   | MUL | NULL              |
| created_by  | int          | NO   | MUL | NULL              |
| note        | text         | NO   |     | NULL              |
| is_important| tinyint(1)   | YES  | MUL | 0                 |
| created_at  | timestamp    | YES  | MUL | CURRENT_TIMESTAMP |
| updated_at  | timestamp    | YES  |     | CURRENT_TIMESTAMP |
+-------------+--------------+------+-----+-------------------+
```

### Step 4: Set Permissions
```bash
sudo chown -R www-data:www-data /var/www/rota-app/functions/shift_notes_api.php
sudo chown -R www-data:www-data /var/www/rota-app/users/shift_notes.php
sudo chown -R www-data:www-data /var/www/rota-app/css/shift_notes.css
```

### Step 5: Clear Browser Cache
Users may need to hard refresh (Ctrl+Shift+R or Cmd+Shift+R) to see the new "Notes" button.

---

## ✅ Testing Checklist

### Test 1: Access Shift Notes Page
1. Go to https://open-rota.com/users/shifts.php
2. Find any shift
3. Click the orange "📝 Notes" button
4. Should open the shift notes page

### Test 2: Add a Note
1. On shift notes page, enter text in the textarea
2. Optionally check "Mark as Important"
3. Click "Save Note"
4. Should see success toast message
5. Note should appear in the list below

### Test 3: Important Notes Filter
1. Add at least 2 notes (one important, one normal)
2. Click "Important" filter button
3. Should only show important notes
4. Click "All" to see all notes again

### Test 4: Toggle Importance
1. Click the ⭐ star icon on any note
2. Should toggle between important/normal
3. Note background should change color
4. Filter should update accordingly

### Test 5: Delete Note
1. Click the 🗑️ trash icon on any note
2. Confirm deletion
3. Note should disappear
4. Success toast should appear

### Test 6: Push Notifications
1. User A logs in and enables push notifications
2. Admin adds a note to User A's shift
3. User A should receive push notification:
   - Title: "New Shift Note" (or "⭐ Important: New Shift Note")
   - Body: Shows who added it and preview
   - Click notification → opens shift notes page

### Test 7: Mobile Responsiveness
1. Open on mobile device or resize browser to <768px
2. UI should stack vertically
3. All buttons should be touch-friendly
4. Filters should be full-width
5. No horizontal scrolling

### Test 8: Admin Access
1. Admin goes to admin/manage_shifts.php
2. Click orange note icon next to any shift
3. Can view and add notes
4. Can toggle/delete any notes (not just own)

### Test 9: Permissions
1. Try to access shift_notes.php for a shift not assigned to you
2. Should get "You do not have access" error
3. Should redirect to shifts page
4. Admins should have access to all shifts

### Test 10: Character Limit
1. Try to add a note with 5001+ characters
2. Textarea should stop at 5000
3. Character counter should show 5000/5000
4. Save should work without error

---

## 📊 Database Structure

### `shift_notes` Table:
```sql
CREATE TABLE shift_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shift_id INT NOT NULL,              -- FK to shifts table
    created_by INT NOT NULL,            -- FK to users table (author)
    note TEXT NOT NULL,                 -- Note content (max 5000 chars)
    is_important TINYINT(1) DEFAULT 0,  -- 0=normal, 1=important
    created_at TIMESTAMP,               -- When note was created
    updated_at TIMESTAMP,               -- When note was last modified
    
    FOREIGN KEY (shift_id) REFERENCES shifts(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    
    INDEX idx_shift_id (shift_id),
    INDEX idx_created_by (created_by),
    INDEX idx_important (is_important),
    INDEX idx_created_at (created_at)
);
```

### Relationships:
- One shift can have many notes
- One user can create many notes
- If shift is deleted → all notes deleted (CASCADE)
- If user is deleted → their notes are deleted (CASCADE)

---

## 🔌 API Endpoints

### `functions/shift_notes_api.php`

#### 1. Get Notes
```
GET /functions/shift_notes_api.php?action=get_notes&shift_id=123

Response:
{
    "success": true,
    "notes": [
        {
            "id": 1,
            "shift_id": 123,
            "created_by": 5,
            "author_name": "John Smith",
            "note": "Remember to check inventory",
            "is_important": 1,
            "created_at": "2025-10-16 14:30:00",
            "updated_at": "2025-10-16 14:30:00"
        }
    ],
    "shift": {...},
    "can_edit": true
}
```

#### 2. Add Note
```
POST /functions/shift_notes_api.php
Body:
    action=add_note
    shift_id=123
    note=Note text here
    is_important=0

Response:
{
    "success": true,
    "message": "Note added successfully",
    "note_id": 45
}
```

#### 3. Toggle Important
```
POST /functions/shift_notes_api.php
Body:
    action=toggle_important
    note_id=45

Response:
{
    "success": true,
    "is_important": 1,
    "message": "Marked as important"
}
```

#### 4. Delete Note
```
POST /functions/shift_notes_api.php
Body:
    action=delete_note
    note_id=45

Response:
{
    "success": true,
    "message": "Note deleted successfully"
}
```

---

## 🎨 UI Components

### Shift Info Card (Top)
- Shows shift date, time, role, location
- Shows assigned employee
- Purple gradient background

### Add Note Form
- Large textarea (4 rows, expands as needed)
- Character counter (0/5000)
- "Mark as Important" checkbox with star icon
- "Save Note" button

### Notes List
- Filter buttons: All | Important
- Each note card shows:
  - Author name with icon
  - Note content (preserves line breaks)
  - Timestamp
  - Important badge (if applicable)
  - Action buttons (star to toggle, trash to delete)
- Important notes have yellow/orange background
- Sorted by importance then date (newest first)

### Empty State
- Shows icon and message when no notes
- Encourages adding first note

---

## 🔔 Push Notifications

### When Triggered:
- When someone adds a note to a shift
- Only if note author is different from shift assignee

### Notification Content:
```
Title: "New Shift Note" or "⭐ Important: New Shift Note"
Body: "[Author] left a note for your shift on [Date]: [Preview]"
Click Action: Opens shift_notes.php?shift_id=X
```

### Function Added:
```php
// In functions/send_shift_notification.php
function notifyShiftNote($user_id, $note_details)
```

---

## 🐛 Troubleshooting

### Issue: "Notes" button not appearing
**Solutions:**
1. Clear browser cache (Ctrl+Shift+R)
2. Check if latest code is pulled: `git log --oneline -1`
3. Verify user has permission to view shifts

### Issue: "Shift not found" error
**Solutions:**
1. Check shift_id in URL is valid
2. Verify shift exists: `SELECT * FROM shifts WHERE id = X;`
3. Check user has access (is assigned or is admin)

### Issue: Can't add notes
**Solutions:**
1. Check shift_notes table exists: `SHOW TABLES LIKE 'shift_notes';`
2. Verify permissions: Can current user access this shift?
3. Check browser console for JavaScript errors
4. Verify API endpoint: curl test `shift_notes_api.php`

### Issue: Push notifications not received
**Solutions:**
1. Verify push notifications are enabled in settings
2. Check push_subscriptions table has active subscription
3. Test with send_test_notification.php
4. Check shift assignee is different from note author

### Issue: CSS not loading correctly
**Solutions:**
1. Hard refresh browser (Ctrl+Shift+R)
2. Check file permissions: `ls -l css/shift_notes.css`
3. Verify file exists on server
4. Check browser console for 404 errors

### Issue: Database errors
**Solutions:**
```bash
# Check table structure
sudo mysql -u root -p rota_app -e "DESCRIBE shift_notes;"

# Check foreign keys
sudo mysql -u root -p rota_app -e "
    SELECT * FROM information_schema.KEY_COLUMN_USAGE 
    WHERE TABLE_NAME = 'shift_notes' AND TABLE_SCHEMA = 'rota_app';
"

# Check indexes
sudo mysql -u root -p rota_app -e "SHOW INDEX FROM shift_notes;"
```

---

## 📈 Usage Metrics

### To Track Adoption:
```sql
-- Total notes created
SELECT COUNT(*) as total_notes FROM shift_notes;

-- Notes per shift average
SELECT AVG(note_count) as avg_notes_per_shift
FROM (
    SELECT shift_id, COUNT(*) as note_count 
    FROM shift_notes 
    GROUP BY shift_id
) as subquery;

-- Most active note creators
SELECT u.username, COUNT(*) as notes_created
FROM shift_notes sn
JOIN users u ON sn.created_by = u.id
GROUP BY u.username
ORDER BY notes_created DESC
LIMIT 10;

-- Important notes ratio
SELECT 
    SUM(CASE WHEN is_important = 1 THEN 1 ELSE 0 END) as important_notes,
    COUNT(*) as total_notes,
    ROUND((SUM(CASE WHEN is_important = 1 THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) as percentage
FROM shift_notes;

-- Notes added in last 7 days
SELECT DATE(created_at) as date, COUNT(*) as notes_added
FROM shift_notes
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY DATE(created_at)
ORDER BY date;
```

---

## 🎯 Success Criteria

✅ **Feature Complete** when:
- [ ] Database table created successfully
- [ ] API endpoints respond correctly
- [ ] UI loads without errors
- [ ] Can add, view, edit, delete notes
- [ ] Push notifications work
- [ ] Mobile responsive
- [ ] Integrated into shifts pages
- [ ] No security vulnerabilities

✅ **Adoption Success** when:
- [ ] >50% of shifts have at least one note
- [ ] >10 notes created per day
- [ ] <5 support tickets about the feature
- [ ] Positive user feedback

---

## 🚀 Next Steps (Future Enhancements)

### Phase 2 Features:
1. **File Attachments** - Add photos/PDFs to notes
2. **Note Templates** - Common notes as quick-add buttons
3. **@Mentions** - Notify specific users
4. **Note Reactions** - 👍 ❤️ for acknowledgment
5. **Note Search** - Full-text search across all notes
6. **Note Exports** - Download notes as PDF
7. **Voice Notes** - Record audio instead of typing

### Integration Ideas:
- Auto-add note when shift is swapped/covered
- Daily summary email of shift notes
- Print view for physical handover sheets
- Integration with incident reporting
- Mobile app push notifications

---

## 📞 Support

### If you encounter issues:
1. Check this deployment guide first
2. Review troubleshooting section
3. Check server logs: `/var/log/apache2/error.log`
4. Check browser console for JavaScript errors
5. Test API endpoints directly with curl/Postman

### Rollback Instructions:
If feature causes issues, rollback:
```bash
cd /var/www/rota-app
sudo git log --oneline -5  # Find commit before shift notes
sudo git reset --hard COMMIT_HASH
sudo systemctl restart apache2

# Optionally remove database table
sudo mysql -u root -p rota_app -e "DROP TABLE IF EXISTS shift_notes;"
```

---

## 🎉 Congratulations!

You now have a fully functional shift notes system that:
- ✅ Improves shift handover communication
- ✅ Reduces information loss between shifts
- ✅ Provides audit trail of shift activities
- ✅ Integrates seamlessly with existing system
- ✅ Works on all devices

**Time to Implement**: 1 day  
**User Value**: HIGH - Immediate improvement to shift handover  
**Maintenance**: LOW - Simple CRUD operations

Enjoy the improved communication! 🚀
