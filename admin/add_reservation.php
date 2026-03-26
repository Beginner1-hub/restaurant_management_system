<?php
include("../config/db.php");

$name=$_POST['name'];
$phone=$_POST['phone'];
$date=$_POST['date'];
$time=$_POST['time'];
$guests=$_POST['guests'];

/* AUTO ASSIGN TABLE */

$sql="SELECT * FROM tables WHERE capacity >= $guests LIMIT 1";
$result=$conn->query($sql);

$table=$result->fetch_assoc()['id'];

$conn->query("
INSERT INTO bookings
(customer_name,phone,booking_date,booking_time,num_guests,assigned_table,status)
VALUES('$name','$phone','$date','$time',$guests,$table,'confirmed')
");

header("Location: reservations.php?date=$date");