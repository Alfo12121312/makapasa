# Status Toggle Debugging Checklist

## 🔍 Step-by-Step Debugging Guide

### 1. **Database Column Check**
- [ ] Open your database management tool (phpMyAdmin)
- [ ] Go to database: `agrivet_db`
- [ ] Select table: `inventory`
- [ ] **Look for the `status` column**
  - ✅ Should exist after the `price` column
  - ✅ Data type should be: `VARCHAR(20)`
  - ✅ Default value should be: `Active`

**How to fix if missing:**
```sql
ALTER TABLE inventory ADD COLUMN status VARCHAR(20) DEFAULT 'Active' AFTER price;
```

**Update existing products to Active:**
```sql
UPDATE inventory SET status = 'Active' WHERE status IS NULL OR status = '';
```

---

### 2. **Form Submission Check**
- [ ] Open browser Developer Tools (F12)
- [ ] Go to **Network** tab
- [ ] Click the **Archive** button on a product
- [ ] Look for a POST request in the Network tab
  - ✅ Should see a request to `Manage-Product.php`
  - ✅ Check the **Payload** tab to see the data being sent
  - Check if `product_id` and `toggle_status` are in the payload

---

### 3. **Database Update Check**
- [ ] In phpMyAdmin, refresh the inventory table
- [ ] Look at one product's `status` column
- [ ] Click Archive button on that product
- [ ] Refresh the database immediately
- [ ] **Did the status change?**
  - ✅ If YES → Issue is in page refresh/display
  - ❌ If NO → Database update isn't working

---

### 4. **Page Cache Issue**
- [ ] Hard refresh the page: **Ctrl + Shift + R** (Windows) or **Cmd + Shift + R** (Mac)
- [ ] Clear browser cache:
  - Chrome: Settings → Privacy → Clear browsing data
  - Firefox: Settings → Privacy → Clear history
- [ ] Try toggling again after clearing cache

---

### 5. **Session Issue**
- [ ] Open Developer Tools (F12)
- [ ] Go to **Application** tab → **Cookies**
- [ ] Look for `PHPSESSID` cookie
  - ✅ Should exist
  - ✅ Should have a value
- [ ] If missing, sessions might not be working

---

### 6. **PHP Error Logs**
- [ ] Open XAMPP Control Panel
- [ ] Click **Logs** button for Apache
- [ ] Look for any PHP errors
- [ ] Check bottom of the logs for recent errors

**Alternative - Check XAMPP Log Directory:**
```
C:\xampp\apache\logs\error.log
```

---

### 7. **Direct Database Test**
- [ ] In phpMyAdmin, run this query for a product ID (e.g., ID = 1):

```sql
SELECT id, product_name, status FROM inventory WHERE id = 1;
```

- [ ] Note the current status
- [ ] Run an update:

```sql
UPDATE inventory SET status = 'Inactive' WHERE id = 1;
```

- [ ] Run the SELECT query again
- [ ] Did the status change?
  - ✅ If YES → Database works fine, issue is in PHP/form
  - ❌ If NO → Database permissions issue

---

## 📋 Quick Test: Manual Database Update

### To test if the database works at all:

1. Open **phpMyAdmin**
2. Select your `inventory` table
3. Find a product and click **Edit**
4. Change `status` to `Inactive`
5. Click **Save**
6. Refresh `Manage-Product.php` page
7. **Did the Status column update?**
   - ✅ YES → Display works, toggle button isn't working
   - ❌ NO → The page isn't fetching fresh data

---

## 🎯 Most Common Issues & Fixes

### Issue: Button click does nothing
**Solution:**
- [ ] Check form `name` attribute (must be `toggle_status`)
- [ ] Check hidden input `name="product_id"` exists
- [ ] Make sure button is `type="submit"`

### Issue: Database doesn't update
**Solution:**
- [ ] Column doesn't exist → Run ALTER TABLE command above
- [ ] Wrong data type → Check column is VARCHAR(20)
- [ ] User permissions → Check database user has UPDATE permission

### Issue: Page shows old status after toggle
**Solution:**
- [ ] Hard refresh page: **Ctrl + Shift + R**
- [ ] Check if redirect is working (Location header)
- [ ] Clear browser cache completely

### Issue: Success message appears but status doesn't change
**Solution:**
- [ ] Redirect might not be working
- [ ] Session message might show but data doesn't persist
- [ ] Add `header("Cache-Control: no-cache, no-store, must-revalidate");` to prevent caching

---

## 🛠️ Enable Debug Mode

### To see detailed debugging info:

1. Open `Manage-Product.php`
2. Find this line: `$DEBUG_MODE = false;`
3. Change to: `$DEBUG_MODE = true;`
4. The debug log will show what's happening

---

## 📝 Report These Details

Please provide:
1. Database table structure (SHOW CREATE TABLE inventory)
2. Browser console errors (F12 → Console)
3. Network request payload (F12 → Network → toggle request)
4. PHP error log content
5. One product ID that you tested with
6. What status you expect vs what you see
