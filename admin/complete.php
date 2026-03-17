<?php
include("../config/db.php");

$id=$_GET['id'];

$conn->query("UPDATE bookings SET status='completed' WHERE id=$id");

header("Location: reservations.php");