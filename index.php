<?php
session_start();

if(isset($_SESSION['user'])){

    switch($_SESSION['user']['role']){
        case 'admin':
            header("Location: admin/dashboard.php");
            exit();
        case 'waiter':
            header("Location: waiter/dashboard.php");
            exit();
        case 'cashier':
            header("Location: cashier/dashboard.php");
            exit();
        case 'kitchen':
            header("Location: kitchen/dashboard.php");
            exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Restaurant Management System</title>

<link rel="stylesheet" href="css/style.css">

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<script>

function login(role){
window.location.href="auth/login.php?role="+role;
}

/* reveal animation */

function revealElements(){

document.querySelectorAll(".reveal").forEach(el=>{

let top = el.getBoundingClientRect().top;

if(top < window.innerHeight - 100){
el.classList.add("active");
}

});

}

window.addEventListener("scroll", revealElements);
window.addEventListener("load", revealElements);

</script>

</head>

<body>

<div class="particles"></div>

<header class="navbar">

<div class="logo">

<div class="steam"></div>

<i class="fa-solid fa-utensils"></i> RMS

</div>

<button class="login-btn" onclick="document.querySelector('.hero').scrollIntoView({behavior:'smooth'})">

Login

</button>

</header>


<section class="hero">

<div class="hero-left reveal">

<h1>Restaurant Management System</h1>

<p>
Manage orders, billing, kitchen workflow and restaurant analytics through one integrated platform.
</p>

<div class="role-buttons">

<button onclick="login('admin')">
<i class="fa-solid fa-chart-line"></i> Admin
</button>

<button onclick="login('waiter')">
<i class="fa-solid fa-user"></i> Waiter
</button>

<button onclick="login('cashier')">
<i class="fa-solid fa-cash-register"></i> Cashier
</button>

<button onclick="login('kitchen')">
<i class="fa-solid fa-kitchen-set"></i> Kitchen
</button>

<button onclick="window.location.href='book_table.php'">
<i class="fa-solid fa-calendar"></i> Book Table
</button>

</div>

</div>


<div class="hero-right reveal">

<div class="dashboard">

<div class="card">

<h3>Orders</h3>
<p>24 Active</p>

</div>

<div class="card delay">

<h3>Tables</h3>
<p>12 Occupied</p>

</div>

<div class="card">

<h3>Kitchen</h3>
<p>8 Preparing</p>

</div>

</div>

</div>

</section>


<section class="features reveal">

<h2>Platform Features</h2>

<div class="features-grid">

<div>

<i class="fa-solid fa-clipboard-list"></i>

<h3>Order Management</h3>

<p>Create and manage restaurant orders quickly.</p>

</div>

<div>

<i class="fa-solid fa-kitchen-set"></i>

<h3>Kitchen Workflow</h3>

<p>Track food preparation in real time.</p>

</div>

<div>

<i class="fa-solid fa-cash-register"></i>

<h3>Billing System</h3>

<p>Generate accurate bills and receipts instantly.</p>

</div>

<div>

<i class="fa-solid fa-chart-line"></i>

<h3>Analytics</h3>

<p>View restaurant sales and performance reports.</p>

</div>

</div>

</section>


<footer>

© 2026 Restaurant Management System

</footer>

</body>
</html>