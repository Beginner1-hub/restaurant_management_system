<?php
include("../config/db.php");

$id=$_POST['booking_id'];
$table=$_POST['table_id'];

$conn->query("UPDATE bookings SET assigned_table=$table WHERE id=$id");

header("Location: reservations.php");