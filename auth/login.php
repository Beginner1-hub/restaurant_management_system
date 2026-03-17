<?php
session_start();
include("../config/db.php");

$error = "";

if(isset($_POST['login'])){

    $username = $_POST['username'];
    $password = $_POST['password'];

    $stmt = $conn->prepare("SELECT * FROM users WHERE username=?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if($result->num_rows === 1){
        $user = $result->fetch_assoc();

        if(password_verify($password, $user['password']) || md5($password) === $user['password']){

            $_SESSION['user'] = $user;

            switch($user['role']){
                case 'admin':
                    header("Location: ../admin/dashboard.php");
                    break;
                case 'waiter':
                    header("Location: ../waiter/dashboard.php");
                    break;
                case 'cashier':
                    header("Location: ../cashier/dashboard.php");
                    break;
                case 'kitchen':
                    header("Location: ../kitchen/dashboard.php");
                    break;
            }
            exit();
        } 
        else{
            $error = "Invalid password";
        }

    } else {
        $error = "User not found";
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Restaurant Login</title>

<link rel="stylesheet" href="../css/style.css">

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">

<script>

function togglePassword(){

let pass=document.getElementById("password");

if(pass.type==="password"){
pass.type="text";
}else{
pass.type="password";
}

}

</script>

</head>

<body class="login-page">

<div class="login-container">


<div class="login-card">

<div class="login-logo">

<div class="steam"></div>

<i class="fa-solid fa-utensils"></i>

<h2>Restaurant System</h2>

</div>


<?php if($error): ?>

<div class="login-error">

<i class="fa-solid fa-circle-exclamation"></i>

<?php echo $error; ?>

</div>

<?php endif; ?>


<form method="POST">

<div class="input-group">

<i class="fa-solid fa-user"></i>

<input type="text" name="username" placeholder="Username" required>

</div>


<div class="input-group">

<i class="fa-solid fa-lock"></i>

<input type="password" id="password" name="password" placeholder="Password" required>

<span onclick="togglePassword()">

<i class="fa-solid fa-eye"></i>

</span>

</div>


<button class="login-submit" type="submit" name="login">
Login
</button>

</form>


<a href="/restaurant_management_system/index.php" class="back-home">
<i class="fa-solid fa-arrow-left"></i> Back to Home
</a>


</div>

</div>

</body>
</html>