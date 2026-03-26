<?php
session_start();
include("../config/db.php");

$error = "";
$prefill_role = isset($_GET['role']) ? $_GET['role'] : '';

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
                case 'admin':   header("Location: ../admin/dashboard.php");   break;
                case 'waiter':  header("Location: ../waiter/dashboard.php");  break;
                case 'cashier': header("Location: ../cashier/dashboard.php"); break;
                case 'kitchen': header("Location: ../kitchen/dashboard.php"); break;
            }
            exit();
        } else {
            $error = "Incorrect password. Please try again.";
        }
    } else {
        $error = "No account found with that username.";
    }
}

$role_labels = [
    'admin'   => ['label'=>'Admin Portal',   'icon'=>'fa-chart-line',     'color'=>'#c9a227'],
    'waiter'  => ['label'=>'Waiter Portal',  'icon'=>'fa-clipboard-list', 'color'=>'#00c896'],
    'cashier' => ['label'=>'Cashier Portal', 'icon'=>'fa-cash-register',  'color'=>'#6ab4ff'],
    'kitchen' => ['label'=>'Kitchen Portal', 'icon'=>'fa-kitchen-set',    'color'=>'#ff8060'],
];
$role_info = isset($role_labels[$prefill_role]) ? $role_labels[$prefill_role] : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Staff Login — RestaurantMS</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --gold:  #c9a227;
  --gold2: #e8c060;
  --dark:  #0a0b0f;
  --dark2: #111218;
  --border: rgba(255,255,255,0.09);
  --muted:  rgba(255,255,255,0.42);
  --text:   #ecedf5;
}

html, body { height: 100%; }

body {
  font-family: 'Inter', sans-serif;
  background: var(--dark);
  color: var(--text);
  display: flex;
  min-height: 100vh;
}

/* ── LEFT PANEL ── */
.panel-left {
  flex: 1;
  position: relative;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  justify-content: flex-end;
  padding: 56px;
  min-height: 100vh;
}

.panel-left .bg {
  position: absolute; inset: 0;
  background: url('../images/hm.jpg') center/cover no-repeat;
  filter: brightness(0.5) saturate(0.8);
  transform: scale(1.03);
  transition: transform 8s ease;
}

.panel-left .bg-grad {
  position: absolute; inset: 0;
  background: linear-gradient(
    to top,
    rgba(10,11,15,0.97) 0%,
    rgba(10,11,15,0.55) 45%,
    rgba(10,11,15,0.15) 100%
  );
}

.panel-left-content { position: relative; z-index: 2; }

.brand {
  display: flex; align-items: center; gap: 12px;
  position: absolute; top: 48px; left: 56px; z-index: 2;
  text-decoration: none;
}
.brand-icon {
  width: 40px; height: 40px; border-radius: 10px;
  background: linear-gradient(135deg, var(--gold), #9a7a14);
  display: flex; align-items: center; justify-content: center;
  font-size: 16px; color: #fff;
  box-shadow: 0 4px 16px rgba(201,162,39,0.4);
}
.brand-name {
  font-family: 'Playfair Display', serif;
  font-size: 20px; font-weight: 600; color: var(--gold);
}

.panel-tagline {
  font-size: 11px; font-weight: 600; color: var(--gold);
  letter-spacing: 2.5px; text-transform: uppercase;
  margin-bottom: 18px; display: flex; align-items: center; gap: 10px;
}
.panel-tagline::before { content: ''; width: 28px; height: 1px; background: var(--gold); }

.panel-left h2 {
  font-family: 'Playfair Display', serif;
  font-size: clamp(28px, 3.5vw, 40px);
  font-weight: 700; line-height: 1.2;
  color: #fff; margin-bottom: 18px;
}
.panel-left h2 span {
  background: linear-gradient(135deg, var(--gold), var(--gold2));
  -webkit-background-clip: text; -webkit-text-fill-color: transparent;
  background-clip: text;
}

.panel-left p {
  font-size: 14px; color: var(--muted); line-height: 1.75;
  max-width: 380px; margin-bottom: 36px;
}

/* feature chips */
.chips { display: flex; gap: 10px; flex-wrap: wrap; }
.chip {
  display: inline-flex; align-items: center; gap: 7px;
  background: rgba(255,255,255,0.06);
  border: 1px solid rgba(255,255,255,0.1);
  border-radius: 20px; padding: 6px 14px;
  font-size: 12px; font-weight: 500; color: rgba(255,255,255,0.7);
}
.chip i { color: var(--gold); font-size: 11px; }

/* ── RIGHT PANEL ── */
.panel-right {
  width: 480px;
  flex-shrink: 0;
  background: var(--dark2);
  border-left: 1px solid var(--border);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 60px 52px;
  position: relative;
}

/* decorative top line */
.panel-right::before {
  content: '';
  position: absolute; top: 0; left: 0; right: 0; height: 2px;
  background: linear-gradient(90deg, transparent, var(--gold), transparent);
}

.form-wrap { width: 100%; }

/* header */
.form-header { margin-bottom: 36px; }

.form-eyebrow {
  font-size: 11px; font-weight: 600; letter-spacing: 2px;
  text-transform: uppercase; color: var(--muted); margin-bottom: 12px;
}

.form-title {
  font-family: 'Playfair Display', serif;
  font-size: 30px; font-weight: 700; color: #fff; line-height: 1.2;
}
.form-title span {
  background: linear-gradient(135deg, var(--gold), var(--gold2));
  -webkit-background-clip: text; -webkit-text-fill-color: transparent;
  background-clip: text;
}

/* role badge */
.role-badge {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 8px 16px; border-radius: 8px; margin-top: 18px;
  font-size: 13px; font-weight: 600;
  background: rgba(255,255,255,0.05);
  border: 1px solid rgba(255,255,255,0.1);
}

/* error */
.form-error {
  display: flex; align-items: center; gap: 10px;
  background: rgba(220,60,60,0.12);
  border: 1px solid rgba(220,60,60,0.3);
  border-radius: 8px; padding: 12px 16px;
  font-size: 13px; color: #ff7070; margin-bottom: 22px;
}
.form-error i { font-size: 14px; flex-shrink: 0; }

/* input fields */
.field { margin-bottom: 18px; }

.field label {
  display: block; font-size: 12px; font-weight: 600;
  color: var(--muted); letter-spacing: .5px;
  text-transform: uppercase; margin-bottom: 8px;
}

.input-wrap {
  position: relative;
  display: flex; align-items: center;
}
.input-wrap .i-icon {
  position: absolute; left: 16px;
  color: var(--muted); font-size: 14px;
  pointer-events: none; transition: color .2s;
}
.input-wrap input {
  width: 100%; padding: 13px 16px 13px 44px;
  background: rgba(255,255,255,0.04);
  border: 1px solid var(--border);
  border-radius: 10px; color: var(--text);
  font-size: 14px; font-family: inherit;
  outline: none; transition: border-color .2s, background .2s, box-shadow .2s;
}
.input-wrap input::placeholder { color: rgba(255,255,255,0.25); }
.input-wrap input:focus {
  border-color: rgba(201,162,39,0.55);
  background: rgba(255,255,255,0.06);
  box-shadow: 0 0 0 3px rgba(201,162,39,0.1);
}
.input-wrap input:focus + .i-icon,
.input-wrap:focus-within .i-icon { color: var(--gold); }

.toggle-pw {
  position: absolute; right: 14px;
  background: none; border: none; cursor: pointer;
  color: var(--muted); font-size: 14px;
  padding: 4px; transition: color .2s;
}
.toggle-pw:hover { color: var(--text); }

/* submit */
.btn-login {
  width: 100%; padding: 14px;
  background: linear-gradient(135deg, var(--gold), #a07a14);
  color: #0a0b0f; border: none; border-radius: 10px;
  font-size: 15px; font-weight: 700;
  font-family: inherit; cursor: pointer;
  transition: .25s; margin-top: 8px;
  display: flex; align-items: center; justify-content: center; gap: 9px;
  box-shadow: 0 6px 22px rgba(201,162,39,0.3);
  letter-spacing: .2px;
}
.btn-login:hover {
  transform: translateY(-2px);
  box-shadow: 0 10px 30px rgba(201,162,39,0.45);
}
.btn-login:active { transform: translateY(0); }

/* back link */
.back-link {
  display: flex; align-items: center; justify-content: center; gap: 8px;
  margin-top: 26px; font-size: 13px; color: var(--muted);
  text-decoration: none; transition: color .2s;
}
.back-link:hover { color: var(--gold); }
.back-link i { font-size: 12px; }

/* divider */
.divider {
  display: flex; align-items: center; gap: 12px;
  margin: 24px 0; color: var(--muted); font-size: 12px;
}
.divider::before, .divider::after {
  content: ''; flex: 1; height: 1px; background: var(--border);
}

/* role switcher */
.role-switcher {
  display: grid; grid-template-columns: 1fr 1fr; gap: 8px;
}
.role-btn {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 14px;
  background: rgba(255,255,255,0.03);
  border: 1px solid var(--border);
  border-radius: 8px; color: var(--muted);
  font-size: 12px; font-weight: 500;
  text-decoration: none; cursor: pointer;
  transition: .2s; font-family: inherit;
}
.role-btn:hover,
.role-btn.active {
  background: rgba(255,255,255,0.07);
  border-color: rgba(255,255,255,0.18);
  color: var(--text);
}
.role-btn i { font-size: 13px; }
.role-btn.r-admin   i { color: var(--gold); }
.role-btn.r-waiter  i { color: #00c896; }
.role-btn.r-cashier i { color: #6ab4ff; }
.role-btn.r-kitchen i { color: #ff8060; }

/* ── RESPONSIVE ── */
@media (max-width: 900px) {
  .panel-left { display: none; }
  .panel-right { width: 100%; border-left: none; }
}
@media (max-width: 480px) {
  .panel-right { padding: 40px 28px; }
}

/* entrance animation */
.form-wrap {
  opacity: 0; transform: translateY(20px);
  animation: formIn .6s .1s cubic-bezier(.16,1,.3,1) forwards;
}
@keyframes formIn { to { opacity: 1; transform: translateY(0); } }
</style>
</head>
<body>

<!-- ── LEFT PANEL ── -->
<div class="panel-left">
  <div class="bg" id="panelBg"></div>
  <div class="bg-grad"></div>

  <a href="../index.php" class="brand">
    <div class="brand-icon"><i class="fa-solid fa-utensils"></i></div>
    <span class="brand-name">RestaurantMS</span>
  </a>

  <div class="panel-left-content">
    <div class="panel-tagline">Staff Access Portal</div>
    <h2>Manage Every Corner<br>of Your <span>Restaurant</span></h2>
    <p>
      A complete management platform for your entire team —
      from orders and billing to reservations and kitchen workflow.
    </p>
    <div class="chips">
      <span class="chip"><i class="fa-solid fa-bolt"></i> Real-time Orders</span>
      <span class="chip"><i class="fa-solid fa-calendar-check"></i> Reservations</span>
      <span class="chip"><i class="fa-solid fa-chart-bar"></i> Analytics</span>
      <span class="chip"><i class="fa-solid fa-kitchen-set"></i> Kitchen Queue</span>
    </div>
  </div>
</div>

<!-- ── RIGHT PANEL ── -->
<div class="panel-right">
  <div class="form-wrap">

    <div class="form-header">
      <div class="form-eyebrow">Staff Login</div>
      <div class="form-title">Welcome<br><span>Back</span></div>

      <?php if($role_info): ?>
      <div class="role-badge" style="color:<?php echo $role_info['color']; ?>;border-color:<?php echo $role_info['color']; ?>33;background:<?php echo $role_info['color']; ?>12;">
        <i class="fa-solid <?php echo $role_info['icon']; ?>"></i>
        <?php echo $role_info['label']; ?>
      </div>
      <?php endif; ?>
    </div>

    <?php if($error): ?>
    <div class="form-error">
      <i class="fa-solid fa-circle-exclamation"></i>
      <?php echo htmlspecialchars($error); ?>
    </div>
    <?php endif; ?>

    <form method="POST" id="loginForm">

      <div class="field">
        <label for="username">Username</label>
        <div class="input-wrap">
          <i class="fa-solid fa-user i-icon"></i>
          <input type="text" id="username" name="username"
                 placeholder="Enter your username" required
                 value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
        </div>
      </div>

      <div class="field">
        <label for="password">Password</label>
        <div class="input-wrap">
          <i class="fa-solid fa-lock i-icon"></i>
          <input type="password" id="password" name="password"
                 placeholder="Enter your password" required>
          <button type="button" class="toggle-pw" id="togglePw" title="Toggle password">
            <i class="fa-solid fa-eye" id="eyeIcon"></i>
          </button>
        </div>
      </div>

      <button type="submit" name="login" class="btn-login">
        <i class="fa-solid fa-right-to-bracket"></i>
        Sign In
      </button>

    </form>

    <div class="divider">or select your role</div>

    <div class="role-switcher">
      <a href="login.php?role=admin" class="role-btn r-admin <?php echo $prefill_role==='admin'?'active':''; ?>">
        <i class="fa-solid fa-chart-line"></i> Admin
      </a>
      <a href="login.php?role=waiter" class="role-btn r-waiter <?php echo $prefill_role==='waiter'?'active':''; ?>">
        <i class="fa-solid fa-clipboard-list"></i> Waiter
      </a>
      <a href="login.php?role=cashier" class="role-btn r-cashier <?php echo $prefill_role==='cashier'?'active':''; ?>">
        <i class="fa-solid fa-cash-register"></i> Cashier
      </a>
      <a href="login.php?role=kitchen" class="role-btn r-kitchen <?php echo $prefill_role==='kitchen'?'active':''; ?>">
        <i class="fa-solid fa-kitchen-set"></i> Kitchen
      </a>
    </div>

    <a href="../index.php" class="back-link">
      <i class="fa-solid fa-arrow-left"></i> Back to Home
    </a>

  </div>
</div>

<script>
/* password toggle */
document.getElementById('togglePw').addEventListener('click', function(){
  const pw  = document.getElementById('password');
  const ico = document.getElementById('eyeIcon');
  if(pw.type === 'password'){
    pw.type = 'text';
    ico.className = 'fa-solid fa-eye-slash';
  } else {
    pw.type = 'password';
    ico.className = 'fa-solid fa-eye';
  }
});

/* subtle parallax on left panel bg */
const bg = document.getElementById('panelBg');
if(bg){
  document.addEventListener('mousemove', e => {
    const x = (e.clientX / window.innerWidth  - 0.5) * 12;
    const y = (e.clientY / window.innerHeight - 0.5) * 12;
    bg.style.transform = `scale(1.06) translate(${x}px,${y}px)`;
  });
}
</script>
</body>
</html>
