# Discount System - Fixed & Working

## Summary of Fixes

The discount system has been fully integrated to work end-to-end. Here's what was fixed:

### 1. **JavaScript (script.js)** - Now Passes Selected Discount to API
   - Added `selected_discount_id` to the API payload in `confirmTransaction()`
   - The selected discount ID from the radio button is now sent to `api/process_sale.php`

### 2. **API Endpoint (api/process_sale.php)** - Now Calculates & Applies Discounts
   - Fetches the selected discount rule from the database
   - Validates discount date ranges (start_at, end_at)
   - Calculates discount based on type:
     - **Percentage**: Discount = Unit Price × (discount_value / 100)
     - **Fixed Amount**: Discount = Fixed amount
   - Handles discount scope:
     - **Order-wide**: Applies to all items in cart
     - **Product-specific**: Only applies to specified product
   - Checks minimum quantity requirements
   - Stores applied discount amount in the `sales` table

### 3. **App Functions (includes/app.php)** - Added Missing Helper
   - Added `cashier_can_apply_discounts()` function to check system settings

---

## How to Create Discounts (Admin)

### Access Admin Discounts Page
1. Login as Admin/Manager
2. Navigate to **Admin → Discounts and Promotions**

### Create a Discount
Fill in the form with:
- **Promotion Name**: e.g., "Senior Citizen Discount", "PWD Discount", "Holiday Sale"
- **Discount Type**: 
  - **Percentage**: e.g., 10% off
  - **Fixed Amount**: e.g., PHP 50 off
- **Scope**: 
  - **Order-wide**: Applied to entire order
  - **Specific Product**: Applied to one product
- **Product**: (if product-specific)
- **Value**: Discount value (percentage or amount)
- **Minimum Qty**: Minimum quantity required to apply discount
- **Start Date**: When discount becomes active
- **End Date**: When discount expires
- **Active**: Check to enable immediately
- **Cashier Can Select**: Check if cashiers should see this in POS (radio button)

### Example Discounts to Create

```
1. Senior Citizen
   - Type: Percentage
   - Scope: Order-wide
   - Value: 20%
   - Cashier Can Select: ✓

2. PWD (Persons with Disability)
   - Type: Percentage
   - Scope: Order-wide
   - Value: 20%
   - Cashier Can Select: ✓

3. Student
   - Type: Percentage
   - Scope: Order-wide
   - Value: 10%
   - Cashier Can Select: ✓

4. Bulk Buy Promotion (e.g., Feed 50kg)
   - Type: Fixed Amount
   - Scope: Specific Product
   - Product: Select Feed 50kg
   - Value: 500 (PHP)
   - Min Qty: 5
   - Cashier Can Select: ✓

5. Holiday Flash Sale
   - Type: Percentage
   - Scope: Order-wide
   - Value: 15%
   - Start Date: Dec 1, 2024
   - End Date: Dec 31, 2024
   - Cashier Can Select: ✓
```

---

## How to Use Discounts (Cashier / POS)

### Applying Discounts at Checkout

1. **Add products to cart** as normal
2. **View active promotions** banner at top of products section
3. **At checkout**, in the "Apply Discount" section:
   - **No Discount**: (default, radio button checked)
   - **Senior Citizen**: (if available)
   - **PWD**: (if available)
   - **Student**: (if available)
   - etc.
4. **Select the appropriate discount** via radio button
5. **Cart recalculates** showing applied discount
6. **Review discount amount** in "Discount" line
7. **Proceed with "Pay"** button

### Automatic Discounts

The system also applies automatic discounts:
- **Wholesale Sale Type**: Automatically applies 10% discount
- **Promotion Rules**: If a promotion is set to auto-apply (not cashier-selectable)

---

## Technical Details

### Discount Flow

```
Admin Creates Discount
       ↓
Discount stored in discount_rules table
       ↓
POS fetches cashier-selectable discounts
       ↓
Cashier selects discount via radio button
       ↓
JavaScript passes selected_discount_id to API
       ↓
API fetches discount rule & validates dates
       ↓
API calculates discount based on:
   - Type (percentage vs fixed)
   - Scope (order-wide vs product-specific)
   - Min quantity requirement
       ↓
API applies discount to cart items
       ↓
Discounted total stored in sales table
       ↓
Receipt shows discount applied
```

### Database Schema

**discount_rules** table:
- `id`: Discount rule ID
- `name`: Discount name
- `discount_type`: 'percentage' or 'fixed'
- `scope`: 'order' or 'product'
- `product_id`: NULL for order-wide, product ID for product-specific
- `discount_value`: Percentage (0-100) or fixed amount
- `min_qty`: Minimum quantity to qualify
- `start_at`: Date/time when discount starts
- `end_at`: Date/time when discount ends
- `is_active`: 1 = active, 0 = inactive
- `cashier_selectable`: 1 = shows in POS radio buttons, 0 = auto-apply only

**sales** table includes:
- `discount`: Discount amount applied per item
- `total_price`: Final price after discount

---

## Testing the System

### Test Case 1: Senior Citizen Discount (20% Percentage)
1. Create "Senior Citizen" discount: 20% off, order-wide
2. Add product to cart (e.g., PHP 500 item)
3. Select "Senior Citizen" at checkout
4. Expected: Discount = PHP 100, Total = PHP 400

### Test Case 2: PWD Discount (PHP 100 Fixed)
1. Create "PWD" discount: PHP 100 fixed, order-wide
2. Add product to cart (e.g., PHP 1000 item)
3. Select "PWD" at checkout
4. Expected: Discount = PHP 100, Total = PHP 900

### Test Case 3: Bulk Buy (PHP 50 off if qty ≥ 5)
1. Create "Bulk Buy" discount: PHP 50 fixed, specific product, min qty 5
2. Add 4 items to cart
3. Expected: No discount shown
4. Add 1 more item (qty = 5)
5. Expected: Discount = PHP 50

### Test Case 4: Expired Discount
1. Create "Expired Promo" with end date = yesterday
2. Try to select it at checkout
3. Expected: API error "Discount has expired"

---

## Troubleshooting

| Issue | Solution |
|-------|----------|
| Discount doesn't show in POS | Check `cashier_selectable` = 1 and `is_active` = 1 |
| Discount calculation is wrong | Verify discount type (percentage vs fixed) and scope |
| "Discount has expired" error | Check start_at and end_at dates |
| Discount not applied to specific product | Check scope = "product" and verify product_id matches |
| Manual discounts not working | Check `cashier_can_apply_discounts` system setting |

---

## Configuration

### Enable/Disable Manual Discounts for Cashiers
- Admin → System Settings → Toggle `cashier_can_apply_discounts`
- When disabled: Cashiers can only use predefined discounts
- When enabled: Cashiers can manually enter discount amounts

---

## Next Steps

The discount system is now fully functional! You can:
1. Create discounts for Senior Citizens, PWD, Students, etc.
2. Set automatic or cashier-selectable discounts
3. Configure date ranges for time-limited promotions
4. Track applied discounts in sales records
