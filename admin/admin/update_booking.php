<?php
include("../config/db.php");

$id=$_POST['id'];
$table=$_POST['table'];

$conn->query("UPDATE bookings SET assigned_table=$table WHERE id=$id");

echo "ok";