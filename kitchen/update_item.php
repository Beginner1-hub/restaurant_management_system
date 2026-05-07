<?php
session_start();
include("../config/db.php");

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'kitchen') {
    header("Location: ../auth/login.php"); exit();
}

$item_id = (int)($_GET['id'] ?? 0);
if ($item_id < 1) {
    header("Location: dashboard.php"); exit();
}

/* Stamp ready_at if column exists */
$has_ready_at = $conn->query("SHOW COLUMNS FROM order_items LIKE 'ready_at'")->num_rows > 0;
if ($has_ready_at) {
    $stmt = $conn->prepare("UPDATE order_items SET item_status='ready', ready_at=NOW() WHERE id=?");
} else {
    $stmt = $conn->prepare("UPDATE order_items SET item_status='ready' WHERE id=?");
}
$stmt->bind_param("i", $item_id);
$stmt->execute();
$stmt->close();

/* Get order id */
$stmt2 = $conn->prepare("SELECT order_id FROM order_items WHERE id=?");
$stmt2->bind_param("i", $item_id);
$stmt2->execute();
$row = $stmt2->get_result()->fetch_assoc();
$stmt2->close();

if ($row) {
    $order_id = (int)$row['order_id'];

    /* Check if all items are ready */
    $stmt3 = $conn->prepare("
        SELECT COUNT(*) AS remaining
        FROM order_items
        WHERE order_id=? AND item_status != 'ready'
    ");
    $stmt3->bind_param("i", $order_id);
    $stmt3->execute();
    $check = $stmt3->get_result()->fetch_assoc();
    $stmt3->close();

    if ((int)$check['remaining'] === 0) {
        $stmt4 = $conn->prepare("UPDATE orders SET status='ready' WHERE id=?");
        $stmt4->bind_param("i", $order_id);
        $stmt4->execute();
        $stmt4->close();
    }
}

header("Location: dashboard.php"); exit();
