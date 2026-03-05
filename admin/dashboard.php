<?php
session_start();
include("../config/db.php");

if(!isset($_SESSION['user']) || $_SESSION['user']['role'] != 'admin'){
    header("Location: ../auth/login.php");
    exit();
}
?>

<h2>Admin Dashboard</h2>
<p>Welcome, <?php echo $_SESSION['user']['username']; ?></p>
<a href="../auth/logout.php" style="color:red;">Logout</a>

<hr>

<h3>Reports & Analytics</h3>

<ul>
    <li><a href="daily_sales.php">Daily Sales Report</a></li>
    <li><a href="monthly_sales.php">Monthly Sales Summary</a></li>
    <li><a href="popular_items.php">Most Popular Dishes</a></li>
    <li><a href="analytics.php">Analytics Dashboard (Charts)</a></li>

</ul>
