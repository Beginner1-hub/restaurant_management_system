<?php
session_start();
include("../config/db.php");

if(!isset($_SESSION['user']) || $_SESSION['user']['role'] != 'kitchen'){
    header("Location: ../auth/login.php");
    exit();
}

$item_id = $_GET['id'];

// Update item status to ready
$conn->query("UPDATE order_items SET item_status='ready' WHERE id=$item_id");

// Get order id
$orderData = $conn->query("SELECT order_id FROM order_items WHERE id=$item_id")->fetch_assoc();
$order_id = $orderData['order_id'];

// Check if all items are ready
$check = $conn->query("
    SELECT COUNT(*) as remaining 
    FROM order_items 
    WHERE order_id=$order_id 
    AND item_status != 'ready'
")->fetch_assoc();

if($check['remaining'] == 0){
    $conn->query("UPDATE orders SET status='ready' WHERE id=$order_id");
}

header("Location: dashboard.php");
exit();
?>
