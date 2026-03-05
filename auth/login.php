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

        // Check password (hashed for admin, md5 for others based on your DB)
        if(password_verify($password, $user['password']) || 
           md5($password) === $user['password']){

            $_SESSION['user'] = $user;

            // Redirect by role
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
        } else {
            $error = "Invalid password";
        }
    } else {
        $error = "User not found";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Login</title>
</head>
<body>

<h2>Restaurant Management Login</h2>

<?php if($error) echo "<p style='color:red;'>$error</p>"; ?>

<form method="POST">
    Username: <input type="text" name="username" required><br><br>
    Password: <input type="password" name="password" required><br><br>
    <button type="submit" name="login">Login</button>
</form>

</body>
</html>
