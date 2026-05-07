<?php
session_start();
include("../../config/db.php");

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    echo "error"; exit();
}

/* Resize logic placeholder — width → duration conversion handled client-side for now */
$id    = (int)($_POST['id']    ?? 0);
$width = (int)($_POST['width'] ?? 0);

/* No DB operation currently needed — respond OK */
echo "resized";
