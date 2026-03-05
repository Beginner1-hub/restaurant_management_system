<?php
session_start();
include("../config/db.php");

if(!isset($_SESSION['user']) || $_SESSION['user']['role'] != 'cashier'){
    header("Location: ../auth/login.php");
    exit();
}
?>

<h2>Cashier Dashboard</h2>
<p>Welcome, <?php echo $_SESSION['user']['username']; ?></p>
<a href="../auth/logout.php" style="color:red;">Logout</a>
<hr>

<h3>Ready Orders</h3>

<?php
$orders = $conn->query("
    SELECT * FROM orders 
    WHERE status='ready'
    ORDER BY created_at ASC
");

if($orders->num_rows == 0){
    echo "No ready orders.";
}

while($order = $orders->fetch_assoc()){
    echo "Order #".$order['order_number']." - Table ".$order['table_id'];
    echo " | <a href='generate_bill.php?order_id=".$order['id']."'>Generate Bill</a>";
    echo "<br><br>";
}
?>
