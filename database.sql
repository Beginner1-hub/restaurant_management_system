-- ============================================================
--  RESTAURANT MANAGEMENT SYSTEM — Full Database Schema
--  Database : restaurant_db
--  Charset  : utf8mb4 / utf8mb4_unicode_ci
--  Engine   : InnoDB
--
--  HOW TO USE:
--    1. Open phpMyAdmin -> http://localhost/phpmyadmin
--    2. Click "Import" and select this file, OR
--       run: mysql -u root restaurant_db < database.sql
--    3. After importing, visit:
--       http://localhost/restaurant_management_system/run_migration.php
--       (safe to run even after this import)
-- ============================================================

CREATE DATABASE IF NOT EXISTS restaurant_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE restaurant_db;

-- ============================================================
--  TABLE: users
--  Staff accounts. Passwords are bcrypt hashes (password_hash).
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    id         INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username   VARCHAR(100) NOT NULL UNIQUE,
    password   VARCHAR(255) NOT NULL,                        -- bcrypt hash
    role       ENUM('admin','waiter','kitchen','cashier') NOT NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
--  TABLE: tables
--  Physical dining tables in the restaurant.
-- ============================================================
CREATE TABLE IF NOT EXISTS `tables` (
    id           INT         NOT NULL AUTO_INCREMENT PRIMARY KEY,
    table_number INT         NOT NULL UNIQUE,
    capacity     INT         NOT NULL DEFAULT 4,
    status       ENUM('available','occupied','reserved') NOT NULL DEFAULT 'available'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
--  TABLE: menu_items
--  The restaurant menu catalog.
-- ============================================================
CREATE TABLE IF NOT EXISTS menu_items (
    id                INT            NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name              VARCHAR(150)   NOT NULL,
    category          VARCHAR(100)   NOT NULL DEFAULT '',
    description       TEXT           NULL,
    image             VARCHAR(255)   NULL,
    price             DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
    prep_time_minutes INT            NOT NULL DEFAULT 0,
    created_at        TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
--  TABLE: bookings
--  Customer reservations (online or admin-created walk-ins).
-- ============================================================
CREATE TABLE IF NOT EXISTS bookings (
    id             INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    customer_name  VARCHAR(150) NOT NULL,
    email          VARCHAR(255) NULL,
    phone          VARCHAR(30)  NULL,
    booking_date   DATE         NOT NULL,
    booking_time   TIME         NOT NULL,
    num_guests     INT          NOT NULL DEFAULT 1,
    assigned_table INT          NULL,                        -- FK -> tables.id
    status         ENUM('pending','confirmed','seated','completed','cancelled') NOT NULL DEFAULT 'pending',
    cancel_token   VARCHAR(64)  NULL UNIQUE,                 -- secure email cancellation token
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_booking_table FOREIGN KEY (assigned_table) REFERENCES `tables` (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
--  TABLE: orders
--  A single order per table session (walk-in or reservation).
-- ============================================================
CREATE TABLE IF NOT EXISTS orders (
    id                  INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_number        VARCHAR(30)  NOT NULL UNIQUE,        -- e.g. ORD-260506-A1B2C3
    table_id            INT          NULL,                   -- FK -> tables.id
    waiter_id           INT          NULL,                   -- FK -> users.id
    status              ENUM('pending','preparing','ready','completed','cancelled') NOT NULL DEFAULT 'pending',
    order_type          VARCHAR(20)  NOT NULL DEFAULT 'walk_in',  -- walk_in | reservation
    walk_in_guests      INT          NULL,
    reservation_id      INT          NULL,                   -- FK -> bookings.id
    total_amount        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    notes               TEXT         NULL,                   -- allergy / special requests
    cooking_started_at  DATETIME     NULL,
    last_item_added_at  DATETIME     NULL,
    bell_rung_at        DATETIME     NULL,
    created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_order_table       FOREIGN KEY (table_id)       REFERENCES `tables`  (id) ON DELETE SET NULL,
    CONSTRAINT fk_order_waiter      FOREIGN KEY (waiter_id)      REFERENCES users      (id) ON DELETE SET NULL,
    CONSTRAINT fk_order_reservation FOREIGN KEY (reservation_id) REFERENCES bookings   (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
--  TABLE: order_items
--  Individual menu items belonging to an order.
-- ============================================================
CREATE TABLE IF NOT EXISTS order_items (
    id           INT           NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id     INT           NOT NULL,                     -- FK -> orders.id
    menu_item_id INT           NOT NULL,                     -- FK -> menu_items.id
    quantity     INT           NOT NULL DEFAULT 1,
    price        DECIMAL(10,2) NOT NULL DEFAULT 0.00,        -- total line price
    unit_price   DECIMAL(10,2) NULL,                         -- price per unit at time of order
    item_status  VARCHAR(20)   NOT NULL DEFAULT 'pending',   -- pending | preparing | ready
    CONSTRAINT fk_oi_order     FOREIGN KEY (order_id)     REFERENCES orders     (id) ON DELETE CASCADE,
    CONSTRAINT fk_oi_menuitem  FOREIGN KEY (menu_item_id) REFERENCES menu_items (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
--  TABLE: billing
--  One billing record per completed order.
-- ============================================================
CREATE TABLE IF NOT EXISTS billing (
    id             INT           NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id       INT           NOT NULL,                   -- FK -> orders.id
    subtotal       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    tax            DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    discount       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    payment_method ENUM('cash','card','qr') NOT NULL,
    payment_status ENUM('pending','completed') NOT NULL DEFAULT 'pending',
    cashier_id     INT           NULL,                       -- FK -> users.id
    billing_date   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_billing_order   FOREIGN KEY (order_id)   REFERENCES orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_billing_cashier FOREIGN KEY (cashier_id) REFERENCES users  (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
--  TABLE: transactions
--  Individual payment transaction log per billing record.
-- ============================================================
CREATE TABLE IF NOT EXISTS transactions (
    id             INT           NOT NULL AUTO_INCREMENT PRIMARY KEY,
    billing_id     INT           NOT NULL,                   -- FK -> billing.id
    amount         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    payment_method ENUM('cash','card','qr') NOT NULL,
    status         ENUM('pending','completed','failed') NOT NULL DEFAULT 'completed',
    created_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_txn_billing FOREIGN KEY (billing_id) REFERENCES billing (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
--  TABLE: menu_item_analytics
--  Demand analysis records generated by the Waste Dashboard.
-- ============================================================
CREATE TABLE IF NOT EXISTS menu_item_analytics (
    analytics_id     INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    item_id          INT          NOT NULL,                  -- FK -> menu_items.id
    period_start     DATE         NOT NULL,
    period_end       DATE         NOT NULL,
    total_orders     INT          NOT NULL DEFAULT 0,
    rank_position    INT          NOT NULL DEFAULT 0,
    demand_level     ENUM('high','medium','low') NOT NULL DEFAULT 'low',
    waste_risk_flag  TINYINT      NOT NULL DEFAULT 0,        -- 1 = at risk
    suggested_action VARCHAR(255) NULL,
    generated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_item   (item_id),
    INDEX idx_period (period_start, period_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
--  TABLE: app_settings
--  Key-value store for admin-configurable settings.
--  e.g. waste_threshold = 3
-- ============================================================
CREATE TABLE IF NOT EXISTS app_settings (
    setting_key   VARCHAR(80)  NOT NULL PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
--  DEFAULT SETTINGS
-- ============================================================
INSERT IGNORE INTO app_settings (setting_key, setting_value)
VALUES ('waste_threshold', '3');

-- ============================================================
--  SAMPLE DATA — Tables
--  Edit capacity and numbers to match your restaurant layout.
-- ============================================================
INSERT IGNORE INTO `tables` (table_number, capacity, status) VALUES
    (1,  2, 'available'),
    (2,  2, 'available'),
    (3,  4, 'available'),
    (4,  4, 'available'),
    (5,  4, 'available'),
    (6,  6, 'available'),
    (7,  6, 'available'),
    (8,  8, 'available'),
    (9,  8, 'available'),
    (10, 10, 'available');

-- ============================================================
--  SAMPLE DATA — Menu Items
--  Replace with your actual menu. Images go in /images/.
-- ============================================================
INSERT IGNORE INTO menu_items (name, category, description, price, prep_time_minutes) VALUES
    -- Starters
    ('Spring Rolls',        'Starter',  'Crispy vegetable spring rolls with sweet chili dip',    5.99,  8),
    ('Caesar Salad',        'Starter',  'Romaine lettuce, croutons, parmesan, Caesar dressing',  7.50,  7),
    ('Chicken Soup',        'Starter',  'Hearty chicken broth with vegetables and noodles',      6.50, 10),
    ('Garlic Bread',        'Starter',  'Toasted baguette with garlic butter and herbs',         4.00,  5),

    -- Mains
    ('Grilled Chicken',     'Main',     'Herb-marinated chicken breast with seasonal vegetables', 13.99, 18),
    ('Beef Steak',          'Main',     '200g sirloin steak with fries and mixed salad',          18.99, 22),
    ('Pasta Carbonara',     'Main',     'Spaghetti, pancetta, egg, pecorino, black pepper',       11.50, 14),
    ('Margherita Pizza',    'Main',     'Tomato base, mozzarella, fresh basil',                   12.00, 16),
    ('Salmon Fillet',       'Main',     'Pan-seared salmon with lemon butter sauce and rice',     16.99, 20),
    ('Veggie Burger',       'Main',     'Plant-based patty, lettuce, tomato, special sauce',      10.50, 12),

    -- Sides
    ('French Fries',        'Side',     'Crispy golden fries with sea salt',                      3.50,  8),
    ('Steamed Rice',        'Side',     'Jasmine rice, lightly seasoned',                         2.50,  8),
    ('Coleslaw',            'Side',     'Creamy homemade coleslaw',                               2.99,  3),

    -- Desserts
    ('Chocolate Lava Cake', 'Dessert',  'Warm chocolate cake with molten center and ice cream',   6.99, 12),
    ('Cheesecake',          'Dessert',  'New York-style cheesecake with berry coulis',             5.99,  3),
    ('Ice Cream',           'Dessert',  'Two scoops, choice of vanilla, chocolate, or strawberry', 4.50,  3),

    -- Drinks
    ('Fresh Lemonade',      'Drink',    'House-squeezed lemonade with mint',                      3.00,  3),
    ('Soft Drink',          'Drink',    'Coke, Sprite, Fanta — 330ml can',                        2.50,  1),
    ('Mineral Water',       'Drink',    'Still or sparkling, 500ml',                              2.00,  1),
    ('Coffee',              'Drink',    'Espresso, Americano, Latte, or Cappuccino',               3.50,  5);

-- ============================================================
--  SAMPLE DATA — Staff Accounts
--
--  ALL passwords below are:  Password123
--  Hash generated with: password_hash('Password123', PASSWORD_DEFAULT)
--
--  !! CHANGE THESE PASSWORDS BEFORE GOING LIVE !!
--
--  To generate a new hash:
--    1. Create C:\xampp\htdocs\hash.php containing:
--         <?php echo password_hash('YourNewPassword', PASSWORD_DEFAULT); ?>
--    2. Visit: http://localhost/hash.php
--    3. Copy the output, delete hash.php, then UPDATE users SET password='...'
-- ============================================================
INSERT IGNORE INTO users (username, password, role) VALUES
    ('admin1',
     '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
     'admin'),

    ('waiter1',
     '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
     'waiter'),

    ('waiter2',
     '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
     'waiter'),

    ('kitchen1',
     '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
     'kitchen'),

    ('cashier1',
     '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
     'cashier');

-- ============================================================
--  END OF SCHEMA
-- ============================================================
