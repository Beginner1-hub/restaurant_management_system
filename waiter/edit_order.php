<?php
session_start();
include("../config/db.php");

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'waiter') {
    header("Location: ../auth/login.php"); exit();
}
$uid = (int)$_SESSION['user']['id'];
if (!isset($_GET['order_id'])) {
    header("Location: dashboard.php"); exit();
}

$order_id = (int)$_GET['order_id'];

/* ── Fetch order — must belong to this waiter ── */
$has_notes_col = $conn->query("SHOW COLUMNS FROM orders LIKE 'notes'")->num_rows > 0;
$stmt_o = $conn->prepare("
    SELECT o.*, t.table_number, t.capacity
    FROM orders o
    LEFT JOIN `tables` t ON o.table_id = t.id
    WHERE o.id = ? AND o.waiter_id = ?
");
$stmt_o->bind_param("ii", $order_id, $uid);
$stmt_o->execute();
$order = $stmt_o->get_result()->fetch_assoc();
$stmt_o->close();

if (!$order) { header("Location: dashboard.php"); exit(); }

/* ── Handle add items ── */
$err = '';
if (isset($_POST['add_items'])) {
    $items = $_POST['quantity'] ?? [];
    $has_items = false;
    foreach ($items as $qty) { if ((int)$qty > 0) { $has_items = true; break; } }

    if (!$has_items) {
        $err = 'Please select at least one item to add.';
    } else {
        $extra_total = 0;
        $has_unit_price = $conn->query("SHOW COLUMNS FROM order_items LIKE 'unit_price'")->num_rows > 0;
        foreach ($items as $mid => $qty) {
            $qty = (int)$qty; $mid = (int)$mid;
            if ($qty <= 0) continue;
            $m = $conn->query("SELECT price FROM menu_items WHERE id=$mid")->fetch_assoc();
            if (!$m) continue;
            $price = (float)$m['price'];
            $extra_total += $price * $qty;
            if ($has_unit_price) {
                $si = $conn->prepare("INSERT INTO order_items (order_id,menu_item_id,quantity,price,unit_price,item_status) VALUES (?,?,?,?,?,'pending')");
                $si->bind_param("iiidd", $order_id, $mid, $qty, $price, $price);
            } else {
                $si = $conn->prepare("INSERT INTO order_items (order_id,menu_item_id,quantity,price,item_status) VALUES (?,?,?,?,'pending')");
                $si->bind_param("iiid", $order_id, $mid, $qty, $price);
            }
            $si->execute();
        }
        /* Update order total, reset status to pending, stamp last_item_added_at */
        $has_lia = $conn->query("SHOW COLUMNS FROM orders LIKE 'last_item_added_at'")->num_rows > 0;

        /* Build the UPDATE using a prepared statement */
        $lia_col   = $has_lia      ? ", last_item_added_at=NOW()" : "";
        $notes_col = $has_notes_col ? ", notes=?"                 : "";
        $upd = $conn->prepare(
            "UPDATE orders
             SET total_amount = total_amount + ?, status='pending'$lia_col$notes_col
             WHERE id=? AND waiter_id=? AND status NOT IN ('completed','cancelled')"
        );
        if ($has_notes_col) {
            $notes_val = trim($_POST['notes'] ?? '');
            $upd->bind_param("dsii", $extra_total, $notes_val, $order_id, $uid);
        } else {
            $upd->bind_param("dii", $extra_total, $order_id, $uid);
        }
        $upd->execute();
        $upd->close();

        /* If the order was already being prepared, flag newly-added items so SQ can penalise */
        $was_preparing = ($order['status'] === 'preparing');
        $has_wpm_col   = $conn->query("SHOW COLUMNS FROM order_items LIKE 'was_preparing_when_modified'")->num_rows > 0;
        $has_mts_col   = $conn->query("SHOW COLUMNS FROM order_items LIKE 'modification_timestamp'")->num_rows > 0;
        if ($was_preparing && ($has_wpm_col || $has_mts_col)) {
            $wpm_set = $has_wpm_col ? "was_preparing_when_modified=1" : "";
            $mts_set = $has_mts_col ? "modification_timestamp=NOW()"  : "";
            $sets    = implode(', ', array_filter([$wpm_set, $mts_set]));
            if ($sets) {
                $fl = $conn->prepare("UPDATE order_items SET $sets WHERE order_id=? AND item_status='pending' AND (modification_timestamp IS NULL OR modification_timestamp >= NOW() - INTERVAL 5 SECOND)");
                if ($fl) { $fl->bind_param("i", $order_id); $fl->execute(); $fl->close(); }
            }
        }

        /* Reset newly-added items to pending so kitchen sees them fresh */
        $rst = $conn->prepare("UPDATE order_items SET item_status='pending' WHERE order_id=? AND item_status NOT IN ('ready')");
        $rst->bind_param("i", $order_id);
        $rst->execute();
        $rst->close();
        header("Location: dashboard.php?added=1"); exit();
    }
}

/* ── Existing items ── */
$ei_stmt = $conn->prepare("
    SELECT oi.*, mi.name
    FROM order_items oi
    JOIN menu_items mi ON oi.menu_item_id = mi.id
    WHERE oi.order_id = ?
    ORDER BY oi.id ASC
");
$ei_stmt->bind_param("i", $order_id);
$ei_stmt->execute();
$existing = $ei_stmt->get_result();
$ei_stmt->close();
$existing_rows = [];
while ($row = $existing->fetch_assoc()) $existing_rows[] = $row;

/* ── Menu ── */
$has_cat  = $conn->query("SHOW COLUMNS FROM menu_items LIKE 'category'")->num_rows > 0;
$has_desc = $conn->query("SHOW COLUMNS FROM menu_items LIKE 'description'")->num_rows > 0;
$sel      = "id,name,price".($has_cat?",category":"").($has_desc?",description":"");
$menu     = $conn->query("SELECT $sel FROM menu_items WHERE availability=1 ORDER BY name ASC");
$menu_items=[]; while ($r=$menu->fetch_assoc()) $menu_items[]=$r;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Edit Order #<?php echo htmlspecialchars($order['order_number']); ?></title>
<?php $role_accent='#60a5fa'; $role_accent2='#93c5fd'; include("../config/staff_head.php"); ?>
<style>
/* ── Layout ── */
body { overflow-x: hidden; }
.order-layout {
  display: grid;
  grid-template-columns: 1fr 340px;
  height: calc(100vh - var(--topbar-h));
}
.menu-side  { overflow-y: auto; padding: 20px; border-right: 1px solid var(--border); }
.cart-side  { display: flex; flex-direction: column; overflow: hidden; }

/* ── Search ── */
.search-bar { position:relative; margin-bottom:14px; }
.search-bar input { width:100%; padding:10px 14px 10px 38px; background:var(--surface2); border:1px solid var(--border); border-radius:9px; color:var(--text); font-family:inherit; font-size:13.5px; outline:none; transition:.2s; }
.search-bar i { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--muted); font-size:13px; pointer-events:none; }

/* ── Menu grid ── */
.menu-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:10px; }
.menu-card { background:var(--surface2); border:1px solid var(--border); border-radius:11px; padding:12px; transition:.18s; cursor:pointer; }
.menu-card:hover { border-color:rgba(96,165,250,.35); }
.menu-card.has-qty { border-color:rgba(96,165,250,.5); background:rgba(96,165,250,.06); }
.menu-card.hidden { display:none; }
.mc-name { font-size:12.5px; font-weight:600; margin-bottom:4px; line-height:1.3; }
.mc-price { font-size:13px; font-weight:700; color:var(--gold); margin-bottom:10px; }
.qty-ctrl { display:flex; align-items:center; gap:7px; }
.qty-btn { width:26px; height:26px; border-radius:7px; border:1px solid var(--border); background:rgba(255,255,255,.06); color:var(--text); font-size:13px; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:.15s; }
.qty-btn:hover { background:rgba(96,165,250,.15); border-color:rgba(96,165,250,.4); color:var(--blue); }
.qty-input { width:36px; text-align:center; background:none; border:none; color:var(--text); font-size:13px; font-weight:700; font-family:inherit; outline:none; }

/* ── Cart ── */
.cart-hd  { padding:16px 18px 12px; border-bottom:1px solid var(--border); }
.cart-body { flex:1; overflow-y:auto; padding:12px 18px; }
.cart-ft  { padding:12px 18px; border-top:1px solid var(--border); }
.cart-item { display:flex; align-items:center; gap:10px; padding:8px 0; border-bottom:1px solid rgba(255,255,255,.04); }
.cart-item:last-child { border-bottom:none; }
.ci-del { background:none; border:none; cursor:pointer; color:var(--muted); font-size:12px; padding:3px; border-radius:4px; }
.ci-del:hover { color:var(--red); }

/* ── Existing items ── */
.existing-item { display:flex; align-items:center; justify-content:space-between; padding:8px 10px; background:var(--surface2); border-radius:8px; margin-bottom:6px; }
.ei-status { font-size:10px; font-weight:700; text-transform:capitalize; padding:2px 8px; border-radius:10px; }
.ei-status.pending   { background:var(--orange-d); color:var(--orange); }
.ei-status.preparing { background:rgba(167,139,250,.13); color:var(--purple); }
.ei-status.ready     { background:var(--green-d); color:var(--green); }

/* ── Mobile floating cart toggle ── */
.mobile-cart-toggle {
  display: none;
  position: fixed;
  bottom: 20px;
  right: 16px;
  z-index: 200;
  background: var(--blue);
  color: #fff;
  border: none;
  border-radius: 50px;
  padding: 13px 20px;
  font-size: 14px;
  font-weight: 700;
  font-family: inherit;
  cursor: pointer;
  box-shadow: 0 4px 20px rgba(96,165,250,.45);
  align-items: center;
  gap: 8px;
  transition: .2s;
}
.mobile-cart-toggle:active { transform: scale(.96); }
.mct-badge {
  background: #fff;
  color: var(--blue);
  border-radius: 20px;
  padding: 1px 8px;
  font-size: 11px;
  font-weight: 800;
}

/* ── Mobile: overlay cart drawer ── */
.cart-drawer-overlay {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,.55);
  z-index: 300;
  backdrop-filter: blur(3px);
}
.cart-drawer {
  position: fixed;
  bottom: 0;
  left: 0;
  right: 0;
  background: var(--surface);
  border-top: 1px solid var(--border);
  border-radius: 18px 18px 0 0;
  z-index: 301;
  display: flex;
  flex-direction: column;
  max-height: 75vh;
  transform: translateY(100%);
  transition: transform .3s cubic-bezier(.2,.8,.3,1);
}
.cart-drawer.open { transform: translateY(0); }
.cart-drawer-overlay.open { display: block; }
.cd-drag { width:40px; height:4px; background:var(--border2); border-radius:4px; margin:12px auto 0; }

/* ── RESPONSIVE: mobile ≤ 768px ── */
@media (max-width: 768px) {
  body { overflow-y: auto; }

  .order-layout {
    display: flex;
    flex-direction: column;
    height: auto;
    min-height: calc(100vh - var(--topbar-h));
  }

  /* Hide desktop cart sidebar */
  .cart-side { display: none; }

  /* Show floating cart button */
  .mobile-cart-toggle { display: flex; }

  .menu-side {
    border-right: none;
    padding: 14px;
    overflow-y: visible;
    flex: 1;
  }

  .menu-grid {
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: 8px;
  }

  .mc-name { font-size: 12px; }
  .mc-price { font-size: 12.5px; margin-bottom: 8px; }

  /* Bigger touch targets for qty buttons */
  .qty-btn { width: 32px; height: 32px; font-size: 14px; }
  .qty-input { width: 32px; font-size: 14px; }

  /* Topbar on mobile */
  .topbar { padding: 0 10px; gap: 6px; }
  .topbar span[style*="font-size:12px"] { display: none; }
}
</style>
</head>
<body>

<div class="topbar">
  <a href="dashboard.php" class="tb-btn"><i class="fa-solid fa-arrow-left"></i> Back</a>
  <div style="display:flex;align-items:center;gap:10px;margin-left:8px;">
    <div style="width:8px;height:8px;border-radius:50%;background:var(--blue);"></div>
    <span style="font-size:15px;font-weight:700;"><?php echo htmlspecialchars($order['order_number']); ?></span>
    <span style="font-size:12px;color:var(--muted);">Table <?php echo $order['table_number']; ?> &bull; <?php echo $order['capacity']; ?> seats</span>
    <span class="pill pill-<?php echo $order['status']; ?>"><?php echo $order['status']; ?></span>
  </div>
  <?php if ($err): ?>
  <div style="margin-left:16px;background:var(--red-d);border:1px solid rgba(239,68,68,.25);color:var(--red);padding:6px 14px;border-radius:8px;font-size:12.5px;">
    <i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($err); ?>
  </div>
  <?php endif; ?>
  <div class="tb-right"><span class="tb-clock" id="clock"></span></div>
</div>

<form method="POST" id="editForm">
<div class="order-layout">

  <!-- MENU -->
  <div class="menu-side">
    <div style="margin-bottom:16px;">
      <div style="font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;font-weight:700;margin-bottom:10px;">Current Items</div>
      <?php if (!empty($existing_rows)): ?>
        <?php foreach ($existing_rows as $ei): ?>
        <div class="existing-item">
          <div>
            <div style="font-size:13px;font-weight:600;"><?php echo htmlspecialchars($ei['name']); ?></div>
            <div style="font-size:11px;color:var(--muted);">×<?php echo $ei['quantity']; ?> &bull; DKK&nbsp;<?php echo number_format($ei['price']*$ei['quantity'],2); ?></div>
          </div>
          <span class="ei-status <?php echo $ei['item_status']??'pending'; ?>"><?php echo $ei['item_status']??'pending'; ?></span>
        </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div style="font-size:12.5px;color:var(--muted);padding:8px 0;">No items yet.</div>
      <?php endif; ?>
    </div>

    <div style="border-top:1px solid var(--border);padding-top:16px;margin-bottom:14px;">
      <div style="font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;font-weight:700;margin-bottom:12px;">Add More Items</div>
      <div class="search-bar">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="text" id="menuSearch" placeholder="Search menu..." autocomplete="off">
      </div>
    </div>

    <div class="menu-grid" id="menuGrid">
      <?php foreach ($menu_items as $item): ?>
      <div class="menu-card" data-id="<?php echo $item['id']; ?>" data-name="<?php echo htmlspecialchars($item['name']); ?>" data-price="<?php echo $item['price']; ?>">
        <div class="mc-name"><?php echo htmlspecialchars($item['name']); ?></div>
        <div class="mc-price">DKK&nbsp;<?php echo number_format($item['price'],2); ?></div>
        <div class="qty-ctrl">
          <button type="button" class="qty-btn" onclick="changeQty(<?php echo $item['id']; ?>,-1)"><i class="fa-solid fa-minus"></i></button>
          <input type="number" class="qty-input" id="qty-<?php echo $item['id']; ?>"
                 name="quantity[<?php echo $item['id']; ?>]" value="0" min="0" max="99"
                 data-price="<?php echo $item['price']; ?>"
                 data-name="<?php echo htmlspecialchars($item['name']); ?>"
                 onchange="syncQty(<?php echo $item['id']; ?>,this.value)">
          <button type="button" class="qty-btn" onclick="changeQty(<?php echo $item['id']; ?>,1)"><i class="fa-solid fa-plus"></i></button>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- CART -->
  <div class="cart-side">
    <div class="cart-hd">
      <h3 style="font-size:14px;font-weight:700;display:flex;align-items:center;gap:8px;">
        <i class="fa-solid fa-plus" style="color:var(--blue);"></i> Items to Add
        <span id="cart-count" class="badge badge-blue" style="display:none;">0</span>
      </h3>
      <div style="font-size:12px;color:var(--muted);margin-top:4px;">Order: <?php echo htmlspecialchars($order['order_number']); ?></div>
    </div>

    <div class="cart-body" id="cartBody">
      <div id="cartEmpty" style="text-align:center;padding:32px 0;color:var(--muted);font-size:13px;">
        <i class="fa-solid fa-plus" style="font-size:26px;display:block;margin-bottom:8px;opacity:.2;"></i>
        Select items to add
      </div>
    </div>

    <?php if ($has_notes_col): ?>
    <div style="padding:10px 18px;border-top:1px solid var(--border);">
      <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.7px;font-weight:700;margin-bottom:7px;">
        <i class="fa-solid fa-note-sticky" style="color:var(--gold);margin-right:4px;"></i> Special Instructions
      </div>
      <div style="display:flex;flex-wrap:wrap;gap:5px;margin-bottom:7px;">
        <button type="button" onclick="addNoteTag('Nut Allergy')" style="font-size:10.5px;padding:3px 9px;background:var(--surface2);border:1px solid var(--border);border-radius:20px;color:var(--muted);cursor:pointer;">🌰 Nut Allergy</button>
        <button type="button" onclick="addNoteTag('Lactose Free')" style="font-size:10.5px;padding:3px 9px;background:var(--surface2);border:1px solid var(--border);border-radius:20px;color:var(--muted);cursor:pointer;">🥛 Lactose Free</button>
        <button type="button" onclick="addNoteTag('Gluten Free')" style="font-size:10.5px;padding:3px 9px;background:var(--surface2);border:1px solid var(--border);border-radius:20px;color:var(--muted);cursor:pointer;">🌾 Gluten Free</button>
        <button type="button" onclick="addNoteTag('Vegan')" style="font-size:10.5px;padding:3px 9px;background:var(--surface2);border:1px solid var(--border);border-radius:20px;color:var(--muted);cursor:pointer;">🌿 Vegan</button>
      </div>
      <textarea name="notes" id="orderNotes" rows="3"
        style="width:100%;padding:9px 12px;background:var(--surface2);border:1px solid var(--border);border-radius:9px;color:var(--text);font-family:inherit;font-size:12.5px;resize:vertical;outline:none;transition:.2s;box-sizing:border-box;"
        placeholder="Allergies, special requests..."><?php echo htmlspecialchars($order['notes'] ?? ''); ?></textarea>
    </div>
    <?php endif; ?>
    <div class="cart-ft">
      <div class="cart-total" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
        <span style="font-size:12px;color:var(--muted);">Additional Total</span>
        <span style="font-size:18px;font-weight:700;font-family:'Playfair Display',serif;color:var(--gold);">DKK&nbsp;<span id="cartTotal">0</span></span>
      </div>
      <button type="submit" name="add_items" class="btn btn-primary" id="addBtn" style="width:100%;justify-content:center;" disabled>
        <i class="fa-solid fa-plus"></i> Add to Order
      </button>
    </div>
  </div>

</div>
</form>

<!-- Mobile cart drawer -->
<button class="mobile-cart-toggle" id="mobileCartBtn" onclick="openCartDrawer()">
  <i class="fa-solid fa-cart-shopping"></i> Cart
  <span class="mct-badge" id="mct-count">0</span>
</button>

<div class="cart-drawer-overlay" id="cartDrawerOverlay" onclick="closeCartDrawer()"></div>
<div class="cart-drawer" id="cartDrawer">
  <div class="cd-drag"></div>
  <div class="cart-hd" style="display:flex;align-items:center;justify-content:space-between;">
    <h3 style="font-size:14px;font-weight:700;display:flex;align-items:center;gap:8px;">
      <i class="fa-solid fa-plus" style="color:var(--blue);"></i> Items to Add
    </h3>
    <button onclick="closeCartDrawer()" style="background:none;border:none;color:var(--muted);font-size:16px;cursor:pointer;padding:4px 8px;">
      <i class="fa-solid fa-xmark"></i>
    </button>
  </div>
  <div class="cart-body" id="cartBodyMobile" style="flex:1;overflow-y:auto;padding:12px 18px;">
    <div id="cartEmptyMobile" style="text-align:center;padding:24px 0;color:var(--muted);font-size:13px;">
      <i class="fa-solid fa-plus" style="font-size:24px;display:block;margin-bottom:8px;opacity:.2;"></i>Select items to add
    </div>
  </div>
  <?php if ($has_notes_col): ?>
  <div style="padding:10px 18px;border-top:1px solid var(--border);">
    <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.7px;font-weight:700;margin-bottom:7px;">
      <i class="fa-solid fa-note-sticky" style="color:var(--gold);margin-right:4px;"></i> Special Instructions
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:5px;margin-bottom:7px;">
      <button type="button" onclick="addNoteTag('Nut Allergy')" style="font-size:11px;padding:4px 10px;background:var(--surface2);border:1px solid var(--border);border-radius:20px;color:var(--muted);cursor:pointer;">🌰 Nut</button>
      <button type="button" onclick="addNoteTag('Lactose Free')" style="font-size:11px;padding:4px 10px;background:var(--surface2);border:1px solid var(--border);border-radius:20px;color:var(--muted);cursor:pointer;">🥛 Lactose Free</button>
      <button type="button" onclick="addNoteTag('Vegan')" style="font-size:11px;padding:4px 10px;background:var(--surface2);border:1px solid var(--border);border-radius:20px;color:var(--muted);cursor:pointer;">🌿 Vegan</button>
    </div>
    <textarea id="orderNotesMobile" rows="2"
      style="width:100%;padding:9px 12px;background:var(--surface2);border:1px solid var(--border);border-radius:9px;color:var(--text);font-family:inherit;font-size:13px;resize:none;outline:none;box-sizing:border-box;"
      placeholder="Allergies, special requests..." oninput="syncMobileNotes(this.value)"><?php echo htmlspecialchars($order['notes'] ?? ''); ?></textarea>
  </div>
  <?php endif; ?>
  <div class="cart-ft" style="padding:14px 18px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
      <span style="font-size:12px;color:var(--muted);">Additional Total</span>
      <span style="font-size:18px;font-weight:700;font-family:'Playfair Display',serif;color:var(--gold);">DKK&nbsp;<span id="cartTotalMobile">0</span></span>
    </div>
    <button type="button" class="btn btn-primary" id="addBtnMobile" style="width:100%;justify-content:center;" disabled onclick="submitMobileCart()">
      <i class="fa-solid fa-plus"></i> Add to Order
    </button>
  </div>
</div>

<div id="toast-wrap"></div>
<script>
(function tick(){const n=new Date(),p=v=>String(v).padStart(2,'0');document.getElementById('clock').textContent=p(n.getHours())+':'+p(n.getMinutes())+':'+p(n.getSeconds());setTimeout(tick,1000);})();

let cart={};

function changeQty(id,delta){
  const inp=document.getElementById('qty-'+id);
  let v=Math.max(0,Math.min(99,(parseInt(inp.value)||0)+delta));
  inp.value=v;syncQty(id,v);
}

function syncQty(id,v){
  v=Math.max(0,Math.min(99,parseInt(v)||0));
  document.getElementById('qty-'+id).value=v;
  const card=document.querySelector(`.menu-card[data-id="${id}"]`);
  const price=parseFloat(card.dataset.price);
  const name=card.dataset.name;
  if(v>0){cart[id]={name,price,qty:v};card.classList.add('has-qty');}
  else{delete cart[id];card.classList.remove('has-qty');}
  updateCart();
}

function updateCart(){
  /* ── desktop cart ── */
  const body=document.getElementById('cartBody');
  const empty=document.getElementById('cartEmpty');
  const count=document.getElementById('cart-count');
  const btn=document.getElementById('addBtn');
  const items=Object.entries(cart);
  let total=0;
  if(!items.length){
    empty.style.display='block';
    body.querySelectorAll('.cart-item').forEach(e=>e.remove());
    count.style.display='none';
    document.getElementById('cartTotal').textContent='0';
    if(btn) btn.disabled=true;
  } else {
    empty.style.display='none';
    count.style.display='flex';
    count.textContent=items.length;
    if(btn) btn.disabled=false;
    body.querySelectorAll('.cart-item').forEach(e=>e.remove());
    items.forEach(([id,item])=>{
      total+=item.price*item.qty;
      const div=document.createElement('div');
      div.className='cart-item';
      div.innerHTML=`<div style="flex:1;font-size:12.5px;font-weight:500;">${item.name}</div><div style="font-size:12px;color:var(--muted);">×${item.qty}</div><div style="font-size:12.5px;font-weight:600;color:var(--gold);">DKK&nbsp;${(item.price*item.qty).toFixed(0)}</div><button type="button" class="ci-del" onclick="changeQty(${id},-999)"><i class="fa-solid fa-xmark"></i></button>`;
      body.appendChild(div);
    });
    document.getElementById('cartTotal').textContent=total.toFixed(0);
  }

  /* ── mobile floating button ── */
  const mctCount=document.getElementById('mct-count');
  if(mctCount) mctCount.textContent=items.length;

  /* ── sync mobile drawer cart ── */
  const mBody=document.getElementById('cartBodyMobile');
  const mEmpty=document.getElementById('cartEmptyMobile');
  const mBtn=document.getElementById('addBtnMobile');
  if(mBody){
    mBody.querySelectorAll('.cart-item').forEach(e=>e.remove());
    if(!items.length){
      mEmpty.style.display='block';
      if(mBtn) mBtn.disabled=true;
      document.getElementById('cartTotalMobile').textContent='0';
    } else {
      mEmpty.style.display='none';
      if(mBtn) mBtn.disabled=false;
      let mTotal=0;
      items.forEach(([id,item])=>{
        mTotal+=item.price*item.qty;
        const div=document.createElement('div');
        div.className='cart-item';
        div.innerHTML=`<div style="flex:1;font-size:13px;font-weight:500;">${item.name}</div><div style="font-size:12px;color:var(--muted);">×${item.qty}</div><div style="font-size:13px;font-weight:600;color:var(--gold);">DKK&nbsp;${(item.price*item.qty).toFixed(0)}</div><button type="button" class="ci-del" onclick="changeQty(${id},-999)"><i class="fa-solid fa-xmark"></i></button>`;
        mBody.insertBefore(div,mEmpty);
      });
      document.getElementById('cartTotalMobile').textContent=mTotal.toFixed(0);
    }
  }
}

/* ── Mobile drawer ── */
function openCartDrawer(){
  document.getElementById('cartDrawer').classList.add('open');
  document.getElementById('cartDrawerOverlay').classList.add('open');
  document.body.style.overflow='hidden';
}
function closeCartDrawer(){
  document.getElementById('cartDrawer').classList.remove('open');
  document.getElementById('cartDrawerOverlay').classList.remove('open');
  document.body.style.overflow='';
}

/* ── Submit from mobile drawer ── */
function submitMobileCart(){
  // Add hidden input so PHP sees add_items in POST
  const inp = document.createElement('input');
  inp.type  = 'hidden';
  inp.name  = 'add_items';
  inp.value = '1';
  document.getElementById('editForm').appendChild(inp);
  document.getElementById('editForm').submit();
}

/* ── Allergen tag helper ── */
function addNoteTag(tag) {
    /* Update both desktop and mobile textarea */
    ['orderNotes', 'orderNotesMobile'].forEach(id => {
        const ta = document.getElementById(id);
        if (ta) ta.value = ta.value ? ta.value.trim() + ', ' + tag : tag;
    });
    /* Focus whichever is visible */
    const ta = document.getElementById('orderNotes') || document.getElementById('orderNotesMobile');
    if (ta) ta.focus();
}

/* ── Keep desktop + mobile notes in sync ── */
function syncMobileNotes(val) {
    const d = document.getElementById('orderNotes');
    if (d) d.value = val;
}
const desktopNotes = document.getElementById('orderNotes');
if (desktopNotes) desktopNotes.addEventListener('input', function() {
    const m = document.getElementById('orderNotesMobile');
    if (m) m.value = this.value;
});

/* ── Search ── */
document.getElementById('menuSearch').addEventListener('input',function(){
  const q=this.value.toLowerCase();
  document.querySelectorAll('.menu-card').forEach(c=>c.classList.toggle('hidden',!c.dataset.name.toLowerCase().includes(q)));
});
</script>
</body>
</html>