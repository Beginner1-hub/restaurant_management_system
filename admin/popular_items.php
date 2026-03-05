<?php
session_start();
include("../config/db.php");

if($_SESSION['user']['role'] != 'admin'){
    header("Location: ../auth/login.php");
    exit();
}
?>

<h2>Most Popular Dishes</h2>
<a href="dashboard.php">Back</a>
<hr>

<?php
$result = $conn->query("
    SELECT menu_items.name,
           SUM(order_items.quantity) as total_sold
    FROM order_items
    JOIN menu_items ON order_items.menu_item_id = menu_items.id
    JOIN orders ON order_items.order_id = orders.id
    WHERE orders.status='completed'
    GROUP BY menu_items.name
    ORDER BY total_sold DESC
");

if($result->num_rows == 0){
    echo "No sales data available.";
}

while($row = $result->fetch_assoc()){
    echo $row['name']." - Sold: ".$row['total_sold']."<br>";
}
?>
