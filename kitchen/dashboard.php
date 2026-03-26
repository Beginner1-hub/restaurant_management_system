<?php
session_start();
include("../config/db.php");

if(!isset($_SESSION['user']) || $_SESSION['user']['role'] != 'kitchen'){
    header("Location: ../auth/login.php");
    exit();
}
?>

<h2>Kitchen Dashboard</h2>
<a href="../admin/reservations.php">&#128197; View Reservations</a> &nbsp;|&nbsp;
<a href="../auth/logout.php">Logout</a>
<hr>

<?php
$orders = $conn->query("
    SELECT * FROM orders 
    WHERE status IN ('pending','confirmed','preparing','ready')
    ORDER BY created_at ASC
");

if($orders->num_rows == 0){
    echo "No active orders.";
}

while($order = $orders->fetch_assoc()){

    echo "<div style='border:1px solid #000; padding:10px; margin-bottom:15px;'>";
    echo "<h3>Order #".$order['order_number']." (Table ".$order['table_id'].")</h3>";
    echo "<p>Status: ".$order['status']."</p>";

    // Fetch items
    $items = $conn->query("
        SELECT order_items.*, menu_items.name 
        FROM order_items
        JOIN menu_items ON order_items.menu_item_id = menu_items.id
        WHERE order_items.order_id = ".$order['id']."
    ");

    while($item = $items->fetch_assoc()){
        echo "<div style='margin-left:20px;'>";
        echo $item['name']." x ".$item['quantity'];
        echo " - Status: ".$item['item_status']." ";

        if($item['item_status'] != 'ready'){
            echo "<a href='update_item.php?id=".$item['id']."'>Mark Ready</a>";
        }

        echo "</div>";
    }

    echo "</div>";
}
?>
