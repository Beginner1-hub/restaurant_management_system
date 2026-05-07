<?php
/**
 * Migration — ensures the bookings table has all required columns.
 * Safe to run multiple times (idempotent).
 */
include("config/db.php");

$migrations = [
    /* bookings */
    'bookings_email' => [
        'check' => "SHOW COLUMNS FROM bookings LIKE 'email'",
        'sql'   => "ALTER TABLE bookings ADD COLUMN email VARCHAR(255) NULL AFTER customer_name",
        'label' => 'bookings.email',
    ],
    'bookings_cancel_token' => [
        'check' => "SHOW COLUMNS FROM bookings LIKE 'cancel_token'",
        'sql'   => "ALTER TABLE bookings ADD COLUMN cancel_token VARCHAR(64) NULL UNIQUE AFTER status",
        'label' => 'bookings.cancel_token',
    ],
    /* orders */
    'orders_order_type' => [
        'check' => "SHOW COLUMNS FROM orders LIKE 'order_type'",
        'sql'   => "ALTER TABLE orders ADD COLUMN order_type VARCHAR(20) NOT NULL DEFAULT 'walk_in' AFTER status",
        'label' => 'orders.order_type',
    ],
    'orders_walk_in_guests' => [
        'check' => "SHOW COLUMNS FROM orders LIKE 'walk_in_guests'",
        'sql'   => "ALTER TABLE orders ADD COLUMN walk_in_guests INT NULL AFTER order_type",
        'label' => 'orders.walk_in_guests',
    ],
    'orders_reservation_id' => [
        'check' => "SHOW COLUMNS FROM orders LIKE 'reservation_id'",
        'sql'   => "ALTER TABLE orders ADD COLUMN reservation_id INT NULL AFTER walk_in_guests",
        'label' => 'orders.reservation_id',
    ],
    'orders_cooking_started_at' => [
        'check' => "SHOW COLUMNS FROM orders LIKE 'cooking_started_at'",
        'sql'   => "ALTER TABLE orders ADD COLUMN cooking_started_at DATETIME NULL AFTER reservation_id",
        'label' => 'orders.cooking_started_at',
    ],
    /* order items */
    'order_items_item_status' => [
        'check' => "SHOW COLUMNS FROM order_items LIKE 'item_status'",
        'sql'   => "ALTER TABLE order_items ADD COLUMN item_status VARCHAR(20) NOT NULL DEFAULT 'pending'",
        'label' => 'order_items.item_status',
    ],
    'order_items_unit_price' => [
        'check' => "SHOW COLUMNS FROM order_items LIKE 'unit_price'",
        'sql'   => "ALTER TABLE order_items ADD COLUMN unit_price DECIMAL(10,2) NULL",
        'label' => 'order_items.unit_price',
    ],
    /* menu items */
    'menu_items_image' => [
        'check' => "SHOW COLUMNS FROM menu_items LIKE 'image'",
        'sql'   => "ALTER TABLE menu_items ADD COLUMN image VARCHAR(255) NULL AFTER description",
        'label' => 'menu_items.image',
    ],
    'menu_items_prep_time_minutes' => [
        'check' => "SHOW COLUMNS FROM menu_items LIKE 'prep_time_minutes'",
        'sql'   => "ALTER TABLE menu_items ADD COLUMN prep_time_minutes INT NOT NULL DEFAULT 0 AFTER price",
        'label' => 'menu_items.prep_time_minutes',
    ],
    /* orders: special instructions / allergy notes from waiter */
    'orders_notes' => [
        'check' => "SHOW COLUMNS FROM orders LIKE 'notes'",
        'sql'   => "ALTER TABLE orders ADD COLUMN notes TEXT NULL AFTER total_amount",
        'label' => 'orders.notes',
    ],
    /* kitchen notification: track when items were last added to an order */
    'orders_last_item_added_at' => [
        'check' => "SHOW COLUMNS FROM orders LIKE 'last_item_added_at'",
        'sql'   => "ALTER TABLE orders ADD COLUMN last_item_added_at DATETIME NULL AFTER cooking_started_at",
        'label' => 'orders.last_item_added_at',
    ],
    /* kitchen → waiter bell alert */
    'orders_bell_rung_at' => [
        'check' => "SHOW COLUMNS FROM orders LIKE 'bell_rung_at'",
        'sql'   => "ALTER TABLE orders ADD COLUMN bell_rung_at DATETIME NULL AFTER last_item_added_at",
        'label' => 'orders.bell_rung_at',
    ],
];

echo "<pre style='font-family:monospace;font-size:14px;'>";

foreach ($migrations as $key => $m) {
    $exists = $conn->query($m['check'])->num_rows > 0;
    if (!$exists) {
        $ok = $conn->query($m['sql']);
        if ($ok) {
            echo "<span style='color:green'>✓ Column <strong>{$m['label']}</strong> added successfully.</span>\n";
        } else {
            echo "<span style='color:red'>✗ Failed to add <strong>{$m['label']}</strong>: " . $conn->error . "</span>\n";
        }
    } else {
        echo "<span style='color:#888'>ℹ Column <strong>{$m['label']}</strong> already exists. Skipped.</span>\n";
    }
}
echo "</pre>";

/* ── Waste Reduction tables ── */
echo "<h3 style='font-family:monospace;margin-top:20px'>Waste Reduction Tables</h3>";
echo "<pre style='font-family:monospace;font-size:14px;'>";

$waste_tables = [
    'menu_item_analytics' => "
        CREATE TABLE IF NOT EXISTS menu_item_analytics (
            analytics_id    INT AUTO_INCREMENT PRIMARY KEY,
            item_id         INT NOT NULL,
            period_start    DATE NOT NULL,
            period_end      DATE NOT NULL,
            total_orders    INT NOT NULL DEFAULT 0,
            rank_position   INT NOT NULL DEFAULT 0,
            demand_level    ENUM('high','medium','low') NOT NULL DEFAULT 'low',
            waste_risk_flag TINYINT NOT NULL DEFAULT 0,
            suggested_action VARCHAR(255) NULL,
            generated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_item (item_id),
            INDEX idx_period (period_start, period_end)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ",
    'app_settings' => "
        CREATE TABLE IF NOT EXISTS app_settings (
            setting_key   VARCHAR(80) PRIMARY KEY,
            setting_value VARCHAR(255) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ",
];

foreach ($waste_tables as $tname => $sql) {
    if ($conn->query($sql)) {
        echo "<span style='color:green'>✓ Table <strong>$tname</strong> OK.</span>\n";
    } else {
        echo "<span style='color:red'>✗ Failed <strong>$tname</strong>: " . $conn->error . "</span>\n";
    }
}
echo "</pre>";

echo "<p style='font-family:monospace'>Migration complete.</p>";
echo "<p><a href='reserve.php'>Go to Reservation Page</a></p>";
