<?php
session_start();

// If user is logged in, redirect based on role
if(isset($_SESSION['user'])){

    switch($_SESSION['user']['role']){
        case 'admin':
            header("Location: admin/dashboard.php");
            break;
        case 'waiter':
            header("Location: waiter/dashboard.php");
            break;
        case 'cashier':
            header("Location: cashier/dashboard.php");
            break;
        case 'kitchen':
            header("Location: kitchen/dashboard.php");
            break;
        default:
            header("Location: auth/login.php");
    }
    exit();
}

// If not logged in, redirect to login
header("Location: auth/login.php");
exit();
?>
