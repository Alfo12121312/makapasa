# Demonteverde Agrivet

Store operations system for an agrivet shop: point of sale, inventory, shifts, lay-away, HR, and owner reports.

Built as a PHP + MySQL web app for XAMPP. Staff sign in by role and only see the screens they need.

**Live app:** [https://demonteverdeargivet.com/9c282ddb052d82aae66cb3f60e024f26](https://demonteverdeargivet.com/9c282ddb052d82aae66cb3f60e024f26)

**Local login:** [http://localhost/makapasa/Login.php](http://localhost/makapasa/Login.php)

---

## What it does

| Area | Capabilities |
| --- | --- |
| **Point of sale** | Open/close shifts, barcode search, kg-by-peso sales, retail/wholesale, promotions, cash and cashless (GCash, Maya, card, bank), receipts |
| **Inventory** | Products, categories, stock movements, FIFO / expiry batches, purchasing, optional sack→kg auto-convert |
| **Customers** | Customer records, lay-away accounts, down payments, reserved stock |
| **Staff** | Employees, attendance, payroll (including statutory contributions), cash advances |
| **Money** | Expenses, discounts, shift cash in/out, profit & loss, sales reports |
| **Access** | Admin, cashier, and owner roles; CSRF on checkout and most write forms; session timeout |

---

## Who uses it

| Role | After login | Typical work |
| --- | --- | --- |
| **Admin** | Operations dashboard | Products, stock, users, payroll, purchasing, settings |
| **Cashier** | POS | Sell, print receipts, collect lay-away, clock in, close the drawer |
| **Owner** | Owner dashboard | Sales, inventory snapshot, HR summary, P&amp;L — read-focused |

In-app conduct rules: [User-Guidelines.php](User-Guidelines.php).

---

## Stack

- PHP (mysqli, sessions)
- MySQL / MariaDB (`agrivet_db`)
- HTML / CSS / vanilla JS (`style.css`, `pos.css`, `script.js`, `pos.js`)
- XAMPP (Apache + MySQL) on Windows

No Node build step. Open the folder in the browser after Apache and MySQL are running.

---

## Quick start

1. Copy the project to the web root, for example `C:\xampp\htdocs\makapasa`.
2. Start **Apache** and **MySQL** in the XAMPP Control Panel.
3. Create a database named `agrivet_db` in [phpMyAdmin](http://localhost/phpmyadmin/).
4. Import [`database_recovery.sql`](database_recovery.sql).
5. Confirm `includes/config.php` matches your MySQL login (defaults below).
6. Open [http://localhost/makapasa/Login.php](http://localhost/makapasa/Login.php).

Default connection (edit in `includes/config.php`):

| Setting | Default |
| --- | --- |
| Host | `localhost` |
| User | `root` |
| Password | *(empty)* |
| Database | `agrivet_db` |
| Port | `3306` |

If the `users` table is empty, the app can seed demo accounts on first login. Change those passwords before any real use.

| Username | Password | Role |
| --- | --- | --- |
| `admin` | `sysadmin123` | Admin |
| `manager` | `manager123` | Admin |
| `cashier` | `cashier123` | Cashier |
| `owner` | `owner123` | Owner |

A longer restore walkthrough is in [DATABASE_RECOVERY_XAMPP.md](DATABASE_RECOVERY_XAMPP.md).

---

## Project layout

```
makapasa/
├── Login.php / logout.php / User-Guidelines.php
├── Admin/          Store operations (products, stock, HR, settings)
├── Cashier/        POS, receipts, shifts, attendance
├── Owner/          Dashboards and reports
├── api/            JSON endpoints (sales, inventory snapshot, session ping)
├── includes/       Config, auth, DB helpers, shared header/footer
├── assets/         Logo, favicons, payment logos
├── database_recovery.sql
├── style.css / script.js
└── pos.css / pos.js
```

How a typical page is built:

1. Load `includes/app.php`.
2. Check the role with `require_roles(...)`.
3. Handle POST (validate, CSRF, then redirect).
4. Render through `includes/header.php` and `includes/footer.php` (POS uses its own layout).

---

## Configuration

`includes/config.php` holds the store name and database settings.

Optional access limits (off by default):

```php
'enforce_access_limits' => false,  // one device per role when true
'max_active_devices' => 3,
'session_idle_minutes' => 10,
'inactivity_logout_minutes' => 30,
'seed_demo_users' => true,
```

Turn `seed_demo_users` off (and change default passwords) if this copy will run in a shop.

---

## Development notes

- Keep POS-only UI in `pos.js` / `pos.css`. Shared cart and discount logic lives in `script.js`.
- Use prepared statements for SQL values; escape output in HTML.
- Do not rename `Admin`, `Cashier`, `Owner`, `includes`, or `api` without updating paths.
- After editing PHP: `C:\xampp\php\php.exe -l path\to\file.php`

---

## License

Private / unpublished academic and store-operations project unless the repository owner states otherwise.
