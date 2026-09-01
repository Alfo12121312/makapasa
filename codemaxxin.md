CodeMaxxin Audit - makapasa workspace

Summary:
This checklist captures repetitive code, consistency issues, and recommended improvements across the makapasa PHP workspace. Use this as a prioritized TODO for refactoring and hardening.

Findings & Recommendations:

1) Centralize DB connection
- Issue: Multiple files instantiate `new mysqli(...)` with differing hosts/ports and credentials.
- Files (examples): Admin/Categories2.php, Admin/Customers.php, Admin/Employees.php, Admin/Attendance.php, Admin/Manage-Product.php, Admin/Reports.php, Admin/Purchasing.php, Admin/Inventory_new.php, Admin/Transactions.php, Cashier/Inventory.php, Owner/*.php, Login.php
- Recommendation: Replace direct `new mysqli` in page files with `app_connect()` from `includes/app.php`. Consolidate credentials and port into a single config and use a single connection factory.

2) Duplicate schema creation & migrations in multiple places
- Issue: Table creation / schema changes are sprinkled across `includes/app.php`, `Login.php`, and possibly others.
- Files: includes/app.php (major schema), Login.php (creates users table), database_recovery.sql
- Recommendation: Consolidate schema creation into a single migration or initialization script (keep `includes/app.php` as canonical). Remove ad-hoc CREATE TABLE from pages like `Login.php`.

3) Repeated session_start() usage
- Issue: `session_start()` appears in multiple files (`includes/auth.php` already starts the session). Some pages call it again.
- Files: includes/auth.php, Login.php, logout.php, Admin/Manage-Product.php, test_runner.php, test-diagnostic.php
- Recommendation: Ensure session is started once in a central include (e.g., `includes/auth.php` or `includes/app.php`) and remove duplicate calls.

4) Inconsistent use of prepared statements and SQL concatenation
- Issue: Many places use prepared statements (good). Scan for any raw concatenation before allowing further review.
- Recommendation: Enforce prepared statements or use a DB abstraction (PDO) to uniformly parameterize queries. Add a check to detect any queries built via string concatenation.

5) Centralize permissions & role logic
- Issue: There were hard-coded role checks and mixed normalization (e.g., 'System Admin', 'Manager' -> Admin). Some files rely on role strings directly.
- Files: includes/auth.php, Admin/Users.php, Login.php, sidebar.php
- Recommendation: Keep permissions mapping in `system_settings` (we added this). Refactor pages to consult permission-based helpers (e.g., `has_permission($conn, 'manage_store')`) instead of role string comparisons.

6) Missing CSRF protection and input validation
- Issue: Many POST forms accept input without CSRF tokens and rely on basic trimming/casting only.
- Files: Most admin forms (Inventory, Manage-Product, Employees, Discounts, Users, etc.)
- Recommendation: Add token-based CSRF protection for all state-changing forms and centralize input validation/sanitization functions.

7) Repeated HTML header/footer and CSS/JS includes
- Issue: Pages manually include `style.css`, `script.js`, and output similar header markup repeatedly.
- Recommendation: Create a simple template include (header.php / footer.php) or use a light templating approach to reduce duplication.

8) Password policies and user seeding
- Issue: `Login.php` seeds default users with weak passwords and stores them directly in code.
- Recommendation: Remove hard-coded passwords; provide a secure seed process or document how to create initial admin. Enforce password strength on creation.

9) Lack of permission whitelist for role-permissions UI
- Issue: The role-permissions UI accepts arbitrary comma-separated permissions.
- Recommendation: Maintain a whitelist of valid permission keys and validate inputs before saving.

10) Misc: Error handling and logging
- Issue: Some places `die()` on DB errors or echo raw DB errors to users.
- Recommendation: Implement centralized error handling and logging. Do not display raw DB errors to end users.

Next steps (prioritized):
- Replace direct `new mysqli` with `app_connect()` across workspace.
- Remove ad-hoc schema creation from page files; consolidate into `includes/app.php` or migration scripts.
- Add CSRF tokens and a small `validate_input()` helper library.
- Implement permission whitelist and stricter validation in `System-Settings.php` role editor.
- Create header/footer includes to DRY HTML.

References (examples found by quick grep):
- Direct connections: Admin/* files, Owner/* files, Cashier/* files (search term: `new mysqli(`)
- session_start occurrences: include/auth.php, Login.php, logout.php, Admin/Manage-Product.php, test_runner.php
- Role-related files: includes/auth.php, Admin/Users.php, Login.php, Admin/sidebar.php

If you'd like, I can now:
- Automatically apply a set of small refactors (e.g., replace `new mysqli` with `app_connect()` in pages), or
- Generate a detailed tracked TODO file in the repo (as a file) with one-line actionable items per file.
