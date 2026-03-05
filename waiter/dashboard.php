<?php
session_start();
include("../config/db.php");

if(!isset($_SESSION['user']) || $_SESSION['user']['role'] != 'waiter'){
    header("Location: ../auth/login.php");
    exit();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Waiter Dashboard</title>
    <style>
        body { font-family: Arial; }
        .table-box {
            width: 200px;
            padding: 15px;
            margin: 10px;
            display: inline-block;
            border-radius: 8px;
            text-align: center;
            color: white;
        }
        .available { background-color: green; }
        .occupied { background-color: red; }
        .reserved { background-color: orange; }
        a { color: white; text-decoration: none; font-weight: bold; }
    </style>
</head>
<body>

<h2>Waiter Dashboard</h2>
<p>Welcome, <?php echo $_SESSION['user']['username']; ?></p>
<a href="../auth/logout.php" style="color:red; font-weight:bold;">Logout</a>
<hr>

<h3>Restaurant Tables</h3>

<?php
$result = $conn->query("SELECT * FROM tables ORDER BY table_number ASC");

while($row = $result->fetch_assoc()){

    $statusClass = $row['status'];

    echo "<div class='table-box $statusClass'>";
    echo "<h3>Table ".$row['table_number']."</h3>";
    echo "<p>Capacity: ".$row['capacity']."</p>";
    echo "<p>Status: ".$row['status']."</p>";

$orderCheck = $conn->query("
    SELECT id FROM orders 
    WHERE table_id = ".$row['id']." 
    AND status IN ('pending','confirmed','preparing','ready')
    LIMIT 1
");

if($row['status'] == 'available'){
    echo "<a href='create_order.php?table_id=".$row['id']."'>Take Order</a>";
}
elseif($row['status'] == 'occupied' && $orderCheck->num_rows > 0){
    $existingOrder = $orderCheck->fetch_assoc();
    echo "<a href='edit_order.php?order_id=".$existingOrder['id']."'>Add Items</a>";
}


    echo "</div>";
}
?>

</body>
</html>
