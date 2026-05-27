# Status Toggle - Debugging Instructions

## 🚀 Quick Start - Follow These Steps

### **Step 1: Run Diagnostic**
1. Go to: `http://localhost/caps-fi/Admin/test-diagnostic.php`
2. **Take a screenshot** of:
   - ✅ **Section 2** (Table structure) - Look for `status` column
   - ✅ **Section 3** (Data sample) - What status values do you see?
   - ✅ **Section 4** (Status count) - How many Active/Inactive/NULL?
   - ✅ **Section 5** (Test result) - Did the manual update work?

---

### **Step 2: Run Toggle Test (Isolated)**
1. Go to: `http://localhost/caps-fi/Admin/test-toggle.php`
2. Select a product from the dropdown
3. Click **"Toggle Status"** button
4. **What happened?**
   - ✅ Green message saying "SUCCESS"? → Toggle works!
   - ❌ Red message or error? → Report the error

---

### **Step 3: Test Full Manage-Product Page**
1. Go to: `http://localhost/caps-fi/Admin/Manage-Product.php`
2. Find the same product you tested above
3. Click **Archive** or **Restore** button
4. **What happened?**
   - ✅ Page refreshes, status changes? → Fixed!
   - ❌ Nothing happens? → Continue debugging

---

## 🔍 Possible Issues & Solutions

### **Issue #1: Status column is NULL or missing**

**In diagnostic page (section 2):**
- ❌ No `status` column listed?
- ✅ Column exists but type is `VARCHAR(20)`?

**Fix:**
Run this SQL in phpMyAdmin:
```sql
ALTER TABLE inventory ADD COLUMN status VARCHAR(20) DEFAULT 'Active' AFTER price;
UPDATE inventory SET status = 'Active' WHERE status IS NULL OR status = '';
```

---

### **Issue #2: Test toggle works, but Manage-Product doesn't**

**Possible causes:**
- Form not submitting properly
- Button not working
- JavaScript interference
- Cache issue

**Try:**
1. **Hard refresh:** `Ctrl + Shift + R` (Windows) or `Cmd + Shift + R` (Mac)
2. **Check browser console:** Press `F12` → **Console** tab
3. **Look for errors** (red text)
4. **Report any errors you see**

---

### **Issue #3: Test toggle doesn't work either**

**This means the database update itself is failing**

Possible causes:
- Database permissions issue
- Wrong database connection
- Data type mismatch

**Try:**
1. In phpMyAdmin, manually test:
   ```sql
   UPDATE inventory SET status = 'Inactive' WHERE id = 1;
   SELECT * FROM inventory WHERE id = 1;
   ```
2. Does the status change?
   - ✅ YES → Database works, PHP might have issues
   - ❌ NO → Database/permissions problem

---

## 📊 Report Template

**Please provide these details when you report the issue:**

```
DIAGNOSTIC RESULTS:
- Product count: ___
- Active products: ___
- Inactive products: ___
- NULL/Empty status: ___

TABLE STRUCTURE:
- Status column exists? (YES / NO)
- Status data type: ___
- Status default value: ___

TESTS:
- Diagnostic page loaded OK? (YES / NO)
- Test toggle page works? (YES / NO)
- Manage-Product page toggle works? (YES / NO)

ERRORS OBSERVED:
(Copy any error messages you see)

CURRENT BEHAVIOR:
(What happens when you click Archive/Restore?)
```

---

## 🛠️ Quick Fixes by Symptom

### "I click the button and nothing happens"
```
✓ Hard refresh page (Ctrl+Shift+R)
✓ Check F12 Console for JavaScript errors
✓ Try Test Toggle page to isolate issue
```

### "Success message shows but status doesn't change"
```
✓ Check if status column actually exists
✓ Run: ALTER TABLE inventory ADD COLUMN status VARCHAR(20) DEFAULT 'Active' AFTER price;
✓ Run diagnostic again
```

### "Test toggle works, Manage-Product doesn't"
```
✓ There's likely a form issue in Manage-Product
✓ Try clearing browser cache completely
✓ Try different browser (Firefox, Chrome, Safari)
✓ Open F12 Network tab and check if POST request is sent
```

### "Get database error"
```
✓ Copy the exact error message
✓ Check database permissions
✓ Verify database connection settings
✓ Run diagnostic to verify connection
```

---

## 🎯 Files Created for Testing

| File | Purpose | URL |
|------|---------|-----|
| `test-diagnostic.php` | Check database setup | `/Admin/test-diagnostic.php` |
| `test-toggle.php` | Test toggle in isolation | `/Admin/test-toggle.php` |
| `DEBUG_CHECKLIST.md` | Detailed checklist | This file |

---

## 💡 Key Things to Remember

1. **Always hard refresh after changes:** `Ctrl + Shift + R`
2. **Check the database directly:** Use phpMyAdmin to verify
3. **Use the test pages first:** They're isolated from the full app
4. **Look at the Network tab:** See what data is being sent
5. **Check the browser console:** JavaScript errors appear there

---

## 🆘 Still Not Working?

Please run these tests and report back with:

1. **Screenshot from `test-diagnostic.php`** showing:
   - Status column structure
   - Product count and status breakdown
   - Manual update result

2. **Result of `test-toggle.php`**:
   - Success or error message?

3. **Browser console errors** (F12 → Console):
   - Any red text?

4. **Database query result**:
   ```sql
   SELECT id, product_name, status FROM inventory LIMIT 5;
   ```
   - What do you see?

---

## 🔧 Quick Reference - SQL Commands

**Check if column exists:**
```sql
SHOW COLUMNS FROM inventory LIKE 'status';
```

**Add column if missing:**
```sql
ALTER TABLE inventory ADD COLUMN status VARCHAR(20) DEFAULT 'Active' AFTER price;
```

**Fix NULL values:**
```sql
UPDATE inventory SET status = 'Active' WHERE status IS NULL OR status = '';
```

**Check current data:**
```sql
SELECT id, product_name, status, COUNT(*) as total FROM inventory GROUP BY status;
```

**Manually toggle a product:**
```sql
UPDATE inventory SET status = IF(status = 'Active', 'Inactive', 'Active') WHERE id = 1;
```

---

## 📞 Debug Mode

To enable debug output in the code:

1. Open `Manage-Product.php`
2. Find: `$DEBUG_MODE = false;`
3. Change to: `$DEBUG_MODE = true;`
4. This will show what's happening during the toggle

But first, **try the test pages above** to narrow down the issue!
