<?php
session_start();
include("../config/db.php");

if(!isset($_SESSION['user']) || $_SESSION['user']['role'] != 'waiter'){
    header("Location: ../auth/login.php");
    exit();
}

if(!isset($_GET['table_id'])){
    header("Location: dashboard.php");
    exit();
}

$table_id = $_GET['table_id'];
$waiter_id = $_SESSION['user']['id'];

// Generate unique order number
$order_number = "ORD" . time();

if(isset($_POST['place_order'])){

    // Insert order
    $stmt = $conn->prepare("INSERT INTO orders (order_number, table_id, waiter_id, status) VALUES (?, ?, ?, 'pending')");
    $stmt->bind_param("sii", $order_number, $table_id, $waiter_id);
    $stmt->execute();

    $order_id = $stmt->insert_id;

    $total_amount = 0;

    // Insert order items
    foreach($_POST['quantity'] as $menu_id => $qty){

        if($qty > 0){

            $menuQuery = $conn->query("SELECT price FROM menu_items WHERE id = $menu_id");
            $menuData = $menuQuery->fetch_assoc();
            $price = $menuData['price'];

            $subtotal = $price * $qty;
            $total_amount += $subtotal;

            $stmtItem = $conn->prepare("INSERT INTO order_items (order_id, menu_item_id, quantity, price) VALUES (?, ?, ?, ?)");
            $stmtItem->bind_param("iiid", $order_id, $menu_id, $qty, $price);
            $stmtItem->execute();
        }
    }

    // Update total amount in orders table
    $conn->query("UPDATE orders SET total_amount = $total_amount WHERE id = $order_id");

    // Update table status to occupied
    $conn->query("UPDATE tables SET status='occupied' WHERE id=$table_id");

    header("Location: dashboard.php");
    exit();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Create Order</title>
</head>
<body>

<h2>Create Order - Table <?php echo $table_id; ?></h2>
<a href="dashboard.php">Back</a>

<form method="POST">
    <h3>Menu Items</h3>

    <?php
    $menu = $conn->query("SELECT * FROM menu_items WHERE availability=1");

    while($item = $menu->fetch_assoc()){
        echo "<div>";
        echo "<strong>".$item['name']."</strong> ($".$item['price'].") ";
        echo "Qty: <input type='number' name='quantity[".$item['id']."]' value='0' min='0'>";
        echo "</div><br>";
    }
    ?>

    <button type="submit" name="place_order">Place Order</button>
</form>

</body>
</html>
