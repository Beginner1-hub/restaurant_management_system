<?php
/**
 * One-time cleanup: removes all Service Quality tables and SQ-only columns.
 * Run once, then delete this file.
 */
include("config/db.php");

echo "<pre style='font-family:monospace;font-size:14px;'>";

$drops = [
    /* Drop SQ tables (order matters for FK safety) */
    "DROP TABLE IF EXISTS recovery_log",
    "DROP TABLE IF EXISTS service_alerts",
    "DROP TABLE IF EXISTS recovery_actions",
    "DROP TABLE IF EXISTS table_satisfaction",
    /* Drop SQ-only columns added to core tables */
    "ALTER TABLE orders      DROP COLUMN IF EXISTS bill_requested_at",
    "ALTER TABLE order_items DROP COLUMN IF EXISTS is_recovery",
    "ALTER TABLE order_items DROP COLUMN IF EXISTS started_preparing_at",
    "ALTER TABLE order_items DROP COLUMN IF EXISTS ready_at",
    "ALTER TABLE order_items DROP COLUMN IF EXISTS was_preparing_when_modified",
    "ALTER TABLE order_items DROP COLUMN IF EXISTS modification_timestamp",
    "ALTER TABLE order_items DROP COLUMN IF EXISTS served_at",
    "ALTER TABLE menu_items  DROP COLUMN IF EXISTS prep_time_minutes",
];

foreach ($drops as $sql) {
    if ($conn->query($sql)) {
        echo "<span style='color:green'>&#10003; OK: $sql</span>\n";
    } else {
        echo "<span style='color:red'>&#10007; FAIL: $sql — " . $conn->error . "</span>\n";
    }
}

echo "</pre><p style='font-family:monospace'>Cleanup complete. Delete this file now.</p>";
