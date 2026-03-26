<?php
/**
 * One-time migration — adds cancel_token column to bookings table.
 * Run this file once in the browser, then delete it (or leave it; it's idempotent).
 */
include("config/db.php");

/* Check if column already exists */
$check = $conn->query("SHOW COLUMNS FROM bookings LIKE 'cancel_token'");

if ($check->num_rows === 0) {
    $conn->query("ALTER TABLE bookings ADD COLUMN cancel_token VARCHAR(64) NULL UNIQUE AFTER status");
    echo "<p style='color:green;font-family:monospace'>✓ Column <strong>cancel_token</strong> added to <strong>bookings</strong> table successfully.</p>";
} else {
    echo "<p style='color:#888;font-family:monospace'>ℹ Column <strong>cancel_token</strong> already exists. Nothing to do.</p>";
}

echo "<p style='font-family:monospace'>Migration complete. You may delete this file.</p>";
echo "<p><a href='reserve.php'>Go to Reservation Page</a></p>";
?>
