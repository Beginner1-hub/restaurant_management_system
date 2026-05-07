================================================================================
  RESTAURANT MANAGEMENT SYSTEM - README
================================================================================
  A full-featured, role-based restaurant operations platform covering
  reservations, order management, kitchen workflow, billing, and analytics.
================================================================================

TABLE OF CONTENTS
-----------------
  1. Requirements
  2. Installation & Setup
  3. Running the Application
  4. Default Login Credentials
  5. User Roles & What They Can Do
  6. System Workflow (Step-by-Step)
  7. Public Reservation (Customer-Facing)
  8. Email Notifications Setup
  9. File & Folder Structure
 10. Common Troubleshooting
 11. Technology Stack


================================================================================
1. REQUIREMENTS
================================================================================

  - XAMPP (Apache + MySQL) — https://www.apachefriends.org/download.html
  - PHP 7.4 or higher (included with XAMPP)
  - MySQL 5.7+ or MariaDB (included with XAMPP)
  - A modern web browser (Chrome, Firefox, Edge)
  - Composer (optional, only needed if vendor/ folder is missing)
      Download: https://getcomposer.org/

  Tested on: Windows 10/11, XAMPP 8.x


================================================================================
2. INSTALLATION & SETUP
================================================================================

STEP 1 — Copy the Project
  Place the entire "restaurant_management_system" folder into:
    C:\xampp\htdocs\

  Final path should be:
    C:\xampp\htdocs\restaurant_management_system\

--

STEP 2 — Start XAMPP
  Open XAMPP Control Panel and click "Start" for both:
    - Apache
    - MySQL

--

STEP 3 — Create the Database
  a) Open your browser and go to:
       http://localhost/phpmyadmin

  b) Click "New" in the left sidebar.

  c) Name the database:  restaurant_db
     Set collation to:   utf8mb4_unicode_ci
     Click "Create".

--

STEP 4 — Import the Database Tables
  If you have an SQL dump file (e.g., restaurant_db.sql):
    a) Click on "restaurant_db" in phpMyAdmin.
    b) Click the "Import" tab.
    c) Choose the .sql file and click "Go".

  If you do NOT have a dump file, the tables are created automatically.
  Skip to Step 5 and the migration script will handle it.

--

STEP 5 — Run the Migration Script
  Open your browser and visit:
    http://localhost/restaurant_management_system/run_migration.php

  This script is safe to run multiple times. It will:
    - Add any missing database columns
    - Create analytics and settings tables
    - NOT delete or overwrite existing data

  You should see a list of "OK" or "already exists" messages. If you see
  errors, check Step 3 was completed correctly.

--

STEP 6 — Add Staff Accounts
  Staff accounts must be added directly in the database using phpMyAdmin.
  The password must be hashed with PHP's password_hash() function.

  Quick way to generate a hashed password:
    a) Create a temporary file at:
         C:\xampp\htdocs\hash.php
    b) Paste this into it:
         <?php echo password_hash('YourPassword123', PASSWORD_DEFAULT); ?>
    c) Visit: http://localhost/hash.php
    d) Copy the long hash that appears, then delete hash.php.

  Then in phpMyAdmin, run this SQL (replace values as needed):
    INSERT INTO users (username, password, role) VALUES
      ('admin1',   '<hashed_password>', 'admin'),
      ('waiter1',  '<hashed_password>', 'waiter'),
      ('kitchen1', '<hashed_password>', 'kitchen'),
      ('cashier1', '<hashed_password>', 'cashier');

  Roles must be exactly one of: admin | waiter | kitchen | cashier

--

STEP 7 — Add Tables and Menu Items
  Log in as admin, then use phpMyAdmin to insert records, OR insert via SQL:

  Tables example:
    INSERT INTO tables (table_number, capacity, status) VALUES
      (1, 2, 'available'),
      (2, 4, 'available'),
      (3, 6, 'available');

  Menu items example:
    INSERT INTO menu_items (name, category, description, price, prep_time_minutes) VALUES
      ('Grilled Chicken', 'Main', 'Herb-seasoned grilled chicken breast', 12.99, 15),
      ('Caesar Salad',    'Starter', 'Romaine, croutons, parmesan',         7.50,  8),
      ('Chocolate Cake',  'Dessert', 'Rich dark chocolate layer cake',       5.99,  5);


================================================================================
3. RUNNING THE APPLICATION
================================================================================

  Public page (customer reservations):
    http://localhost/restaurant_management_system/

  Staff login:
    http://localhost/restaurant_management_system/auth/login.php

  After logging in, each role is automatically redirected to their dashboard:
    Admin    -> /admin/dashboard.php
    Waiter   -> /waiter/dashboard.php
    Kitchen  -> /kitchen/dashboard.php
    Cashier  -> /cashier/dashboard.php


================================================================================
4. DEFAULT LOGIN CREDENTIALS
================================================================================

  There are NO built-in default accounts. You must create them as described
  in Step 6 of the Installation section above.

  Recommended usernames for first setup:
    admin1 / waiter1 / kitchen1 / cashier1

  Choose strong passwords and hash them with password_hash() before inserting.


================================================================================
5. USER ROLES & WHAT THEY CAN DO
================================================================================

  ADMIN
  -----
  The administrator has access to everything and oversees the entire system.
    - View the main KPI dashboard (revenue, orders, occupancy, reservations)
    - Manage all reservations (approve, cancel, drag-and-drop, walk-in add)
    - View and analyze daily and monthly sales reports
    - Export PDF reports for any date range
    - View popular menu items and analytics
    - Access the Waste Reduction Dashboard (demand analysis, risk flagging)
    - Configure menu item prep times
    - Monitor smart alerts (kitchen load, capacity, revenue trends)

  WAITER
  ------
  Handles customer-facing operations on the floor.
    - View all tables (occupied, available, reserved)
    - Create new orders (from menu, linked to table or reservation)
    - Add items to existing orders mid-service
    - Add order notes for allergies or special requests
    - Register walk-in customers
    - Preview the bill for any table
    - Collect payment (cash, card, or QR) and submit to cashier

  KITCHEN
  -------
  Works the food preparation queue — no access to billing or reservations.
    - View all pending and in-progress orders sorted by time
    - See individual items per order with status (pending / preparing / ready)
    - Mark items as preparing or ready
    - Receive a bell notification when an order is marked fully ready
    - Track how long cooking has been in progress

  CASHIER
  -------
  Handles the payment desk.
    - View a list of all orders with unpaid bills
    - Look up a specific order
    - Enter payment details (amount, method: cash / card / QR)
    - Finalize and close out a transaction
    - View payment history


================================================================================
6. SYSTEM WORKFLOW (STEP-BY-STEP)
================================================================================

  FULL DINE-IN ORDER FLOW:

  1. Customer makes a reservation online (or calls ahead — admin adds it).
  2. Customer arrives. Admin/Waiter sees their reservation and seats them.
      -> Admin uses "Reservations" to mark booking as "Seated".
  3. Waiter creates a new order:
      -> Goes to /waiter/dashboard.php
      -> Clicks "Create Order" for the table
      -> Selects menu items and quantities, adds any notes
      -> Submits the order
  4. Kitchen receives the order:
      -> /kitchen/dashboard.php shows the order automatically
      -> Kitchen marks each item "Preparing", then "Ready" as it's cooked
  5. Waiter delivers food. When all items are ready, a bell alert fires.
  6. When the customer is done, the Waiter previews the bill:
      -> Bill is calculated: subtotal + 10% tax - any discount
      -> Waiter selects payment method and submits
  7. Cashier finalizes:
      -> /cashier/dashboard.php shows the pending bill
      -> Cashier confirms payment and closes the transaction
  8. Table is marked "Available" again. Admin dashboard updates in real time.

  WALK-IN FLOW:
  Same as above but skip Steps 1–2. Waiter registers the walk-in directly
  when creating the order (enters guest count instead of reservation link).


================================================================================
7. PUBLIC RESERVATION (CUSTOMER-FACING)
================================================================================

  Customers can book a table without logging in at:
    http://localhost/restaurant_management_system/

  They enter:
    - Name, email, phone
    - Preferred date and time
    - Number of guests

  The system will:
    - Find the smallest available table that fits the party
    - Block 90 minutes around the booking to prevent double-booking
    - Send a confirmation email with a unique cancellation link

  Customers can cancel their own booking by clicking the link in the email.
  No account or login is required for the customer.


================================================================================
8. EMAIL NOTIFICATIONS SETUP
================================================================================

  Email is used for:
    - Reservation confirmation (sent to the customer)
    - Cancellation confirmation

  The system uses PHPMailer with Brevo SMTP (300 free emails/day).

  To configure email:
    a) Sign up free at https://www.brevo.com
    b) Go to SMTP & API settings and copy your SMTP key.
    c) Open: config/mail.php
    d) Update these values:
         $smtpUser     = 'your_brevo_login_email';
         $smtpPassword = 'your_brevo_smtp_key';
         $fromEmail    = 'your_verified_sender@yourdomain.com';
         $fromName     = 'Your Restaurant Name';

  If email is not configured, the reservation system still works — customers
  just won't receive confirmation or cancellation emails.


================================================================================
9. FILE & FOLDER STRUCTURE
================================================================================

  restaurant_management_system/
  |
  |-- index.php                  Public landing page / reservation entry
  |-- reserve.php                Reservation form (customer-facing)
  |-- submit_reservation.php     Processes new reservations
  |-- booking_confirmed.php      Confirmation page shown after booking
  |-- cancel_booking.php         Handles cancellation via email token link
  |-- run_migration.php          Database setup / migration (run once after install)
  |-- composer.json              PHP dependency list
  |
  |-- config/
  |   |-- db.php                 Database connection settings
  |   `-- mail.php               SMTP / email settings
  |
  |-- auth/
  |   |-- login.php              Staff login page
  |   `-- logout.php             Session logout
  |
  |-- admin/
  |   |-- dashboard.php          Admin KPI dashboard
  |   |-- reservations.php       Reservation calendar & management
  |   |-- analytics.php          Sales analytics (date range)
  |   |-- daily_sales.php        Daily sales report & chart
  |   |-- monthly_sales.php      Monthly revenue overview
  |   |-- popular_items.php      Top menu items by orders
  |   |-- waste_dashboard.php    Demand analysis & waste risk
  |   |-- menu_prep_times.php    Set prep times per menu item
  |   |-- export_pdf.php         PDF report generation
  |   `-- includes/
  |       |-- sidebar.php        Admin navigation sidebar
  |       `-- admin_styles.php   Shared admin CSS
  |
  |-- waiter/
  |   |-- dashboard.php          Table & order management
  |   |-- create_order.php       Create a new order
  |   `-- edit_order.php         Modify existing order
  |
  |-- kitchen/
  |   |-- dashboard.php          Order queue & item status
  |   `-- update_item.php        Mark item status (preparing / ready)
  |
  |-- cashier/
  |   |-- dashboard.php          Bill payment processing
  |   `-- generate_bill.php      Bill calculation
  |
  |-- css/
  |   `-- style.css              Global styles
  |
  |-- images/                    Menu item images and photos
  |-- vendor/                    Composer packages (PHPMailer)
  `-- tcpdf/                     PDF generation library


================================================================================
10. COMMON TROUBLESHOOTING
================================================================================

  PROBLEM: Blank white page or PHP errors
  SOLUTION: Make sure Apache is running in XAMPP. Check for typos in
            config/db.php (database name must be: restaurant_db).

  PROBLEM: "Access denied" MySQL error
  SOLUTION: Open config/db.php. Default settings are:
              host     = localhost
              user     = root
              password = (empty string)
              database = restaurant_db
            Match these to your XAMPP MySQL settings.

  PROBLEM: run_migration.php shows errors
  SOLUTION: The database "restaurant_db" may not exist. Go to phpMyAdmin
            and create it first, then run the migration again.

  PROBLEM: Emails not sending
  SOLUTION: Check config/mail.php has valid Brevo SMTP credentials.
            The system still works without email — just no confirmations sent.

  PROBLEM: Login fails even with correct password
  SOLUTION: Make sure the password in the database is a bcrypt hash
            (generated with PHP's password_hash()), not plain text.

  PROBLEM: No tables or menu items appear
  SOLUTION: Insert records into the "tables" and "menu_items" tables.
            See Step 7 of Installation for example SQL.

  PROBLEM: PDF export does not work
  SOLUTION: The tcpdf/ folder must be present in the project root.
            Check it exists: C:\xampp\htdocs\restaurant_management_system\tcpdf\

  PROBLEM: Reservation calendar shows no time slots
  SOLUTION: Tables must exist in the database. Add at least one table
            as shown in Step 7 of Installation.


================================================================================
11. TECHNOLOGY STACK
================================================================================

  Backend        PHP 7.4+ (procedural, session-based auth, prepared statements)
  Database       MySQL / MariaDB  (via XAMPP)
  Frontend       HTML5, CSS3, Vanilla JavaScript
  Charts         Chart.js v4.4.0 (loaded from CDN)
  Date Picker    Flatpickr (loaded from CDN)
  Icons          Font Awesome 6.5.0 (loaded from CDN)
  PDF Reports    TCPDF (included in /tcpdf)
  Email          PHPMailer 7.x + Brevo SMTP (included in /vendor)
  UI Style       Glassmorphism dark theme with gold accents


================================================================================
  END OF README
================================================================================
