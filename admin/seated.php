<?php
include("../config/db.php");

$id=$_GET['id'];

$conn->query("UPDATE bookings SET status='seated' WHERE id=$id");

header("Location: reservations.php");