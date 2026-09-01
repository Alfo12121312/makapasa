# Full Recovery and Setup Guide

This guide helps you recover the Agrivet database and place the project files so the PHP application can run correctly with XAMPP or a similar local web server.

## 1. Put the Project Files in the Web Root
For XAMPP on Windows, place the project folder inside the web server folder:
- C:\xampp\htdocs\makapasa

If the project is currently in another location such as Downloads, Desktop, or a different folder, copy or move it into the XAMPP web root.

After that, open the app in your browser using a URL like:
- http://localhost/makapasa/Login.php
- http://localhost/makapasa/Admin/Dashboard-Admin.php

If the folder is not in the web root, the PHP files may not load properly.

## 2. Start the Local Server
1. Open the XAMPP Control Panel.
2. Start Apache.
3. Start MySQL.
4. Open phpMyAdmin at:
   - http://localhost/phpmyadmin/

## 3. Create or Select the Database
Create a database named `agrivet_db` if it does not exist yet.

You can also use an existing database, but the project expects the database name `agrivet_db` by default.

## 4. Import the Recovery SQL
1. Select the database `agrivet_db`.
2. Click the Import tab.
3. Choose File.
4. Select [database_recovery.sql](database_recovery.sql).
5. Click Go.

## 5. Confirm the Database Structure
After the import finishes, confirm that these tables exist:
- users
- system_settings
- employees
- attendance
- cashier_sessions
- expenses
- discount_rules
- product_categories
- product_suppliers
- inventory
- stock_movements
- sales
- layaways
- layaway_items
- layaway_payments
- stock_reservations
- customers
- purchase_orders

## 6. Make the Database and PHP Code Match
The project is configured to use the following default connection values:
- host: `localhost`
- username: `root`
- password: empty
- database: `agrivet_db`
- port: `3306`

If your local setup uses different MySQL credentials, update the database connection settings in the PHP files accordingly.

## 7. If You Move the Project Folder
When moving the project to another location:
- Keep the folder structure intact.
- Do not rename important folders such as `Admin`, `Cashier`, `Owner`, `includes`, or `api` unless you also update the code references.
- Make sure the database is already created and imported.
- Open the app again from the new web root location.

## 8. Recovery Checklist
1. Copy or move the project into `C:\xampp\htdocs\makapasa`.
2. Start Apache and MySQL.
3. Create the database `agrivet_db`.
4. Import [database_recovery.sql](database_recovery.sql).
5. Open the app in the browser.
6. If you see a database error, check the database name, username, password, and port.

## 9. Troubleshooting
- If you see an import error, check that the database name is correct.
- If MySQL is not running, start it from XAMPP.
- If the app cannot connect to the database, verify the connection settings in the PHP files.
- If the app does not open, confirm that the project folder is inside the XAMPP web root and that the URL points to the correct folder.
- If you use another local server such as WAMP or Laragon, place the project in that server's web root and use the same database name and credentials.
