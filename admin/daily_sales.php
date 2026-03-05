<?php
session_start();
include("../config/db.php");

if($_SESSION['user']['role'] != 'admin'){
    header("Location: ../auth/login.php");
    exit();
}
?>

<h2>Daily Sales Report</h2>
<a href="dashboard.php">Back</a>
<hr>

<?php
$result = $conn->query("
    SELECT DATE(billing_date) as sale_date,
           SUM(total) as total_sales,
           COUNT(id) as total_bills
    FROM billing
    WHERE payment_status='completed'
    GROUP BY DATE(billing_date)
    ORDER BY sale_date DESC
");

if($result->num_rows == 0){
    echo "No sales data available.";
}

while($row = $result->fetch_assoc()){
    echo "<strong>Date:</strong> ".$row['sale_date']."<br>";
    echo "Total Sales: $".number_format($row['total_sales'],2)."<br>";
    echo "Total Bills: ".$row['total_bills']."<br>";
    echo "<hr>";
}
?>
