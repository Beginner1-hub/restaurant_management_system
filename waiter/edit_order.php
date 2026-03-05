<?php
session_start();
include("../config/db.php");

if(!isset($_SESSION['user']) || $_SESSION['user']['role'] != 'waiter'){
    header("Location: ../auth/login.php");
    exit();
}

$order_id = $_GET['order_id'];

if(isset($_POST['add_items'])){

    $total_amount = 0;

    // Get current total
    $orderData = $conn->query("SELECT total_amount FROM orders WHERE id=$order_id")->fetch_assoc();
    $total_amount = $orderData['total_amount'];

    foreach($_POST['quantity'] as $menu_id => $qty){

        if($qty > 0){

            $menuData = $conn->query("SELECT price FROM menu_items WHERE id=$menu_id")->fetch_assoc();
            $price = $menuData['price'];
            $subtotal = $price * $qty;

            $total_amount += $subtotal;

            $stmt = $conn->prepare("INSERT INTO order_items (order_id, menu_item_id, quantity, price) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("iiid", $order_id, $menu_id, $qty, $price);
            $stmt->execute();
        }
    }

    $conn->query("UPDATE orders SET total_amount=$total_amount WHERE id=$order_id");

    header("Location: dashboard.php");
    exit();
}
?>

<h2>Add Items to Order #<?php echo $order_id; ?></h2>
<a href="dashboard.php">Back</a>

<form method="POST">

<?php
$menu = $conn->query("SELECT * FROM menu_items WHERE availability=1");

while($item = $menu->fetch_assoc()){
    echo "<div>";
    echo $item['name']." ($".$item['price'].") ";
    echo "Qty: <input type='number' name='quantity[".$item['id']."]' value='0' min='0'>";
    echo "</div><br>";
}
?>

<button type="submit" name="add_items">Add Items</button>
</form>
