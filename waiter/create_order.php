<?php
ob_start();
session_start();
include("../config/db.php");

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'waiter') {
    ob_end_clean();
    header("Location: ../auth/login.php"); exit();
}

$uid   = (int)$_SESSION['user']['id'];
$uname = $_SESSION['user']['username'];

/* ── URL params ── */
$table_id = isset($_GET['table_id']) ? (int)$_GET['table_id'] : 0;
if (!$table_id) { header("Location: dashboard.php"); exit(); }

$order_type     = in_array($_GET['order_type'] ?? '', ['walk_in','reservation']) ? $_GET['order_type'] : 'walk_in';
$reservation_id = isset($_GET['reservation_id']) ? (int)$_GET['reservation_id'] : null;
$walk_in_guests = isset($_GET['guests']) ? (int)$_GET['guests'] : null;

/* ── Verify reservation if given ── */
$reservation_info = null;
if ($reservation_id) {
    $rv = $conn->query("
        SELECT b.*, b.customer_name, b.num_guests, b.booking_time
        FROM bookings b
        WHERE b.id = $reservation_id
          AND b.assigned_table = $table_id
          AND b.status IN ('confirmed','pending','seated')
    ")->fetch_assoc();
    if (!$rv) {
        $reservation_id = null;
        $order_type     = 'walk_in';
    } else {
        $reservation_info = $rv;
    }
}

/* ── Fetch table info ── */
$t = $conn->query("SELECT * FROM `tables` WHERE id=$table_id")->fetch_assoc();
if (!$t) { header("Location: dashboard.php"); exit(); }

/* ── Handle POST ── */
$err = '';
if (isset($_POST['submit_order'])) {
    $items = $_POST['quantity'] ?? [];
    $has_items = false;
    foreach ($items as $qty) { if ((int)$qty > 0) { $has_items = true; break; } }

    if (!$has_items) {
        $err = 'Please add at least one item to the order.';
    } else {
        /* Generate a collision-resistant order number.
           Uses date prefix + random hex so duplicates are practically impossible. */
        do {
            $order_number = 'ORD-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $exists = $conn->query("SELECT id FROM orders WHERE order_number='$order_number' LIMIT 1")->num_rows;
        } while ($exists > 0);

        /* ── Defensive column checks ── */
        $has_notes   = $conn->query("SHOW COLUMNS FROM orders LIKE 'notes'")->num_rows > 0;
        $has_otype   = $conn->query("SHOW COLUMNS FROM orders LIKE 'order_type'")->num_rows > 0;
        $has_wguests = $conn->query("SHOW COLUMNS FROM orders LIKE 'walk_in_guests'")->num_rows > 0;
        $has_resid   = $conn->query("SHOW COLUMNS FROM orders LIKE 'reservation_id'")->num_rows > 0;

        /* ── Build INSERT dynamically ── */
        $cols_list = "order_number, table_id, waiter_id, status, total_amount";
        $vals_list = "?, ?, ?, 'pending', 0";
        $types     = "sii";
        $params    = [$order_number, $table_id, $uid];

        if ($has_notes && !empty($_POST['notes'])) {
            $cols_list .= ", notes";
            $vals_list .= ", ?";
            $types     .= "s";
            $params[]   = trim($_POST['notes']);
        }
        if ($has_otype) {
            $cols_list .= ", order_type";
            $vals_list .= ", ?";
            $types     .= "s";
            $params[]   = $order_type;
        }
        if ($has_wguests && $walk_in_guests) {
            $cols_list .= ", walk_in_guests";
            $vals_list .= ", ?";
            $types     .= "i";
            $params[]   = $walk_in_guests;
        }
        if ($has_resid && $reservation_id) {
            $cols_list .= ", reservation_id";
            $vals_list .= ", ?";
            $types     .= "i";
            $params[]   = $reservation_id;
        }

        $stmt = $conn->prepare("INSERT INTO orders ($cols_list) VALUES ($vals_list)");
        if (!$stmt) {
            $err = 'DB error creating order: ' . $conn->error;
        } else {
            $stmt->bind_param($types, ...$params);
            if (!$stmt->execute()) {
                $err = 'Order save failed: ' . $stmt->error;
            }
        }
        $order_id = $err ? 0 : (int)$conn->insert_id;

        if ($order_id) {
            /* ── Insert order items & compute total ── */
            $total  = 0;
            $has_up = $conn->query("SHOW COLUMNS FROM order_items LIKE 'unit_price'")->num_rows > 0;
            foreach ($items as $mid => $qty) {
                $qty = (int)$qty;
                if ($qty <= 0) continue;
                $mid = (int)$mid;
                $m   = $conn->query("SELECT price FROM menu_items WHERE id=$mid")->fetch_assoc();
                if (!$m) continue;
                $price    = (float)$m['price'];
                $total   += $price * $qty;

                if ($has_up) {
                    $si = $conn->prepare("INSERT INTO order_items (order_id, menu_item_id, quantity, unit_price, item_status) VALUES (?, ?, ?, ?, 'pending')");
                } else {
                    $si = $conn->prepare("INSERT INTO order_items (order_id, menu_item_id, quantity, price, item_status) VALUES (?, ?, ?, ?, 'pending')");
                }
                if ($si) { $si->bind_param("iiid", $order_id, $mid, $qty, $price); $si->execute(); $si->close(); }
            }

            $conn->query("UPDATE orders SET total_amount=$total WHERE id=$order_id");
            $conn->query("UPDATE `tables` SET status='occupied' WHERE id=$table_id");

            if ($reservation_id) {
                $conn->query("UPDATE bookings SET status='seated' WHERE id=$reservation_id");
            }

            ob_end_clean();
            header("Location: dashboard.php?created=1"); exit();
        }
    }
}

/* ── Fetch menu items ── */
$has_cat  = $conn->query("SHOW COLUMNS FROM menu_items LIKE 'category'")->num_rows > 0;
$has_desc = $conn->query("SHOW COLUMNS FROM menu_items LIKE 'description'")->num_rows > 0;
$has_img  = $conn->query("SHOW COLUMNS FROM menu_items LIKE 'image'")->num_rows > 0;

$sel  = "id, name, price"
      . ($has_cat  ? ", category"    : "")
      . ($has_desc ? ", description" : "")
      . ($has_img  ? ", image"       : "");

$menu_res   = $conn->query("SELECT $sel FROM menu_items WHERE availability=1 ORDER BY name ASC");
$menu_items = [];
$categories = ['All'];
while ($row = $menu_res->fetch_assoc()) {
    $menu_items[] = $row;
    if ($has_cat && !empty($row['category']) && !in_array($row['category'], $categories)) {
        $categories[] = $row['category'];
    }
}

/* ── Page setup ── */
$role_accent  = '#60a5fa';
$role_accent2 = '#93c5fd';
$page_title   = 'Create Order — Table ' . htmlspecialchars($t['table_number'] ?? $table_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include("../config/staff_head.php"); ?>
<title><?php echo $page_title; ?></title>
<style>
/* ── Layout ── */
body { overflow: hidden; }

.order-layout {
    display: grid;
    grid-template-columns: 1fr 360px;
    height: calc(100vh - var(--topbar-h));
    overflow: hidden;
}

/* ── Menu side ── */
.menu-side {
    display: flex;
    flex-direction: column;
    overflow: hidden;
    border-right: 1px solid var(--border);
}
.menu-side-inner {
    flex: 1;
    overflow-y: auto;
    padding: 16px;
}

/* ── Info banner ── */
.info-banner {
    margin-bottom: 14px;
    border-radius: 10px;
    padding: 12px 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 13px;
}
.info-banner.reservation-banner {
    background: rgba(96,165,250,0.1);
    border: 1px solid rgba(96,165,250,0.25);
    color: #93c5fd;
}
.info-banner.walkin-banner {
    background: rgba(201,162,39,0.08);
    border: 1px solid rgba(201,162,39,0.2);
    color: var(--gold2);
}
.info-banner i { font-size: 18px; flex-shrink: 0; }
.info-banner-body { flex: 1; }
.info-banner-title { font-weight: 600; font-size: 13px; }
.info-banner-sub { font-size: 11px; opacity: 0.75; margin-top: 2px; }
.order-type-badge {
    font-size: 10px; font-weight: 700; text-transform: uppercase;
    letter-spacing: 0.8px; padding: 2px 8px; border-radius: 20px;
}
.badge-reservation {
    background: rgba(96,165,250,0.15); color: #60a5fa;
    border: 1px solid rgba(96,165,250,0.3);
}
.badge-walkin {
    background: rgba(201,162,39,0.15); color: var(--gold);
    border: 1px solid rgba(201,162,39,0.3);
}

/* ── Search ── */
.search-bar {
    display: flex;
    align-items: center;
    gap: 8px;
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: 9px;
    padding: 8px 12px;
    margin-bottom: 12px;
}
.search-bar i { color: var(--muted); font-size: 13px; }
.search-bar input {
    flex: 1;
    background: none;
    border: none;
    outline: none;
    color: var(--text);
    font-family: inherit;
    font-size: 13px;
}
.search-bar input::placeholder { color: var(--muted); }

/* ── Category pills ── */
.cat-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-bottom: 14px;
}
.cat-pill {
    padding: 4px 13px;
    border-radius: 20px;
    font-size: 11.5px;
    font-weight: 600;
    cursor: pointer;
    border: 1px solid var(--border);
    background: var(--surface2);
    color: var(--muted);
    transition: .15s;
    font-family: inherit;
}
.cat-pill:hover { color: var(--text); border-color: var(--border2); }
.cat-pill.active {
    background: var(--accent);
    color: #000;
    border-color: var(--accent);
}

/* ── Menu grid ── */
.menu-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 10px;
}

/* ── Menu card ── */
.menu-card {
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 12px;
    cursor: pointer;
    transition: .18s;
    position: relative;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.menu-card:hover { border-color: var(--border2); transform: translateY(-1px); }
.menu-card.has-qty {
    border-color: rgba(96,165,250,0.4);
    background: rgba(96,165,250,0.05);
}
.menu-card.hidden { display: none; }

.card-img {
    width: 100%;
    height: 80px;
    border-radius: 7px;
    overflow: hidden;
    background: var(--surface3);
    margin-bottom: 2px;
    flex-shrink: 0;
}
.card-img img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
.card-name {
    font-size: 12.5px;
    font-weight: 600;
    color: var(--text);
    line-height: 1.3;
}
.card-price {
    font-size: 13px;
    font-weight: 700;
    color: var(--gold);
}
.card-desc {
    font-size: 10.5px;
    color: var(--muted);
    line-height: 1.35;
    overflow: hidden;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
}

/* ── Qty controls ── */
.qty-controls {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-top: auto;
}
.qty-btn {
    width: 26px;
    height: 26px;
    border-radius: 6px;
    border: 1px solid var(--border);
    background: var(--surface3);
    color: var(--text);
    font-size: 14px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: .12s;
    font-family: inherit;
    flex-shrink: 0;
}
.qty-btn:hover { background: var(--accent); border-color: var(--accent); color: #000; }
.qty-input {
    width: 40px;
    text-align: center;
    background: var(--surface3);
    border: 1px solid var(--border);
    border-radius: 6px;
    color: var(--text);
    font-family: inherit;
    font-size: 13px;
    padding: 4px 0;
}
.qty-input:focus { outline: none; border-color: var(--accent); }

/* ── Cart side ── */
.cart-side {
    display: flex;
    flex-direction: column;
    background: var(--surface);
    height: 100%;
    overflow: hidden;
}
.cart-header {
    padding: 16px 18px;
    border-bottom: 1px solid var(--border);
    flex-shrink: 0;
}
.cart-header h3 {
    font-size: 14px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
}
.cart-header h3 i { color: var(--accent); }
.cart-order-info {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.cart-table-label {
    font-size: 12px;
    color: var(--muted);
}
.cart-table-label strong { color: var(--text); }

.cart-body {
    flex: 1;
    overflow-y: auto;
    padding: 14px;
}
.cart-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    height: 160px;
    color: var(--muted);
    gap: 10px;
    font-size: 13px;
}
.cart-empty i { font-size: 36px; opacity: 0.3; }

/* ── Cart items ── */
.cart-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 0;
    border-bottom: 1px solid rgba(255,255,255,0.04);
}
.cart-item:last-child { border-bottom: none; }
.ci-name { flex: 1; font-size: 12.5px; font-weight: 500; }
.ci-qty  { font-size: 11px; color: var(--muted); margin-top: 2px; }
.ci-price { font-size: 13px; font-weight: 600; color: var(--gold); white-space: nowrap; }

/* ── Cart footer ── */
.cart-footer {
    border-top: 1px solid var(--border);
    padding: 14px;
    flex-shrink: 0;
}
.cart-total-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}
.cart-total-label { font-size: 13px; font-weight: 600; color: var(--muted); }
.cart-total-val   { font-size: 20px; font-weight: 700; color: var(--gold); font-family: 'Playfair Display', serif; }

.tag-btn {
    padding: 3px 8px;
    border-radius: 6px;
    border: 1px solid var(--border);
    background: var(--surface2);
    color: var(--text);
    font-size: 11px;
    cursor: pointer;
    font-family: inherit;
    transition: .12s;
}
.tag-btn:hover { border-color: var(--border2); background: var(--surface3); }

.notes-label {
    font-size: 10.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--muted);
    margin-bottom: 6px;
}
.notes-textarea {
    width: 100%;
    background: rgba(255,255,255,0.04);
    border: 1px solid var(--border);
    border-radius: 9px;
    color: var(--text);
    font-family: inherit;
    font-size: 12.5px;
    padding: 9px 12px;
    outline: none;
    resize: vertical;
    transition: .2s;
    min-height: 56px;
}
.notes-textarea:focus { border-color: rgba(96,165,250,0.4); background: rgba(255,255,255,0.06); }
.notes-textarea::placeholder { color: var(--muted); }

.submit-btn {
    width: 100%;
    padding: 12px;
    border-radius: 9px;
    border: none;
    background: var(--accent);
    color: #000;
    font-family: inherit;
    font-size: 13.5px;
    font-weight: 700;
    cursor: pointer;
    transition: .18s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    margin-top: 10px;
}
.submit-btn:hover { filter: brightness(1.12); transform: translateY(-1px); }
.submit-btn:disabled { opacity: 0.4; cursor: not-allowed; pointer-events: none; }

/* ── Error message ── */
.err-msg {
    background: var(--red-d);
    border: 1px solid rgba(239,68,68,0.25);
    color: var(--red);
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 12.5px;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
}

/* ── Toast ── */
#toast-wrap { position:fixed; bottom:24px; right:24px; z-index:900; display:flex; flex-direction:column; gap:8px; pointer-events:none; }
.toast { background:var(--surface3); border:1px solid var(--border2); border-radius:10px; padding:11px 16px; display:flex; align-items:center; gap:10px; font-size:12.5px; min-width:220px; box-shadow:0 8px 30px rgba(0,0,0,.5); animation:tIn .3s cubic-bezier(.16,1,.3,1); pointer-events:all; }
@keyframes tIn { from{opacity:0;transform:translateY(12px);} to{opacity:1;transform:none;} }
.toast i { font-size:14px; }
.toast.success i { color:var(--green); }
.toast.error   i { color:var(--red); }
.toast.info    i { color:var(--blue); }

/* ═══════════════════════════════════════════
   CREATE ORDER RESPONSIVE
═══════════════════════════════════════════ */
@media (max-width: 900px) {
  body { overflow-y: auto; }
  .order-layout {
    display: flex;
    flex-direction: column;
    height: auto;
    min-height: calc(100vh - var(--topbar-h));
    overflow: visible;
  }
  .menu-side {
    overflow: visible;
    border-right: none;
    border-bottom: 1px solid var(--border);
    height: auto;
  }
  .menu-side-inner { overflow: visible; height: auto; }
  .cart-side {
    height: auto;
    overflow: visible;
  }
}

@media (max-width: 640px) {
  .order-layout { flex-direction: column; }
  .menu-side-inner { padding: 12px; }
  .cart-header { padding: 12px 14px; }
  /* Cart body scrollable at fixed height on small */
  .cart-items-wrap { max-height: 40vh; overflow-y: auto; }
}
</style>
</head>
<body>

<!-- TOPBAR -->
<div class="topbar">
    <a href="dashboard.php" class="tb-brand">
        <div class="tb-icon"><i class="fa-solid fa-utensils"></i></div>
        <span class="tb-title">RestaurantMS</span>
    </a>
    <span style="color:var(--border2);font-size:16px;">|</span>
    <span style="font-size:13px;color:var(--muted);">New Order</span>
    <span style="color:var(--border2);font-size:16px;">—</span>
    <span style="font-size:13px;font-weight:600;color:var(--text);">
        Table <?php echo htmlspecialchars($t['table_number'] ?? $table_id); ?>
    </span>
    <div class="tb-right">
        <div class="tb-user">
            <div class="tb-avatar"><?php echo strtoupper(substr($uname, 0, 1)); ?></div>
            <div>
                <div class="tb-name"><?php echo htmlspecialchars($uname); ?></div>
                <div class="tb-role">Waiter</div>
            </div>
        </div>
        <a href="dashboard.php" class="tb-btn">
            <i class="fa-solid fa-arrow-left"></i> Dashboard
        </a>
    </div>
</div>

<!-- MAIN -->
<form method="POST" id="orderForm">
<div class="order-layout">

    <!-- ══ LEFT: MENU SIDE ══ -->
    <div class="menu-side">
        <div class="menu-side-inner">

            <!-- Info Banner -->
            <?php if ($order_type === 'reservation' && $reservation_info): ?>
            <div class="info-banner reservation-banner">
                <i class="fa-solid fa-calendar-check"></i>
                <div class="info-banner-body">
                    <div class="info-banner-title">
                        <?php echo htmlspecialchars($reservation_info['customer_name']); ?>
                        &nbsp;<span class="order-type-badge badge-reservation">Reservation #<?php echo $reservation_id; ?></span>
                    </div>
                    <div class="info-banner-sub">
                        <?php echo (int)$reservation_info['num_guests']; ?> guests
                        &bull; <?php echo date('H:i', strtotime($reservation_info['booking_time'])); ?>
                        <?php if (!empty($reservation_info['booking_date'])): ?>
                          &bull; <?php echo date('d M Y', strtotime($reservation_info['booking_date'])); ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php elseif ($order_type === 'walk_in'): ?>
            <div class="info-banner walkin-banner">
                <i class="fa-solid fa-person-walking"></i>
                <div class="info-banner-body">
                    <div class="info-banner-title">
                        Walk-In &nbsp;<span class="order-type-badge badge-walkin">Walk-In</span>
                    </div>
                    <div class="info-banner-sub">
                        <?php if ($walk_in_guests): ?>
                            <?php echo $walk_in_guests; ?> guest<?php echo $walk_in_guests != 1 ? 's' : ''; ?>
                        <?php else: ?>
                            Table <?php echo htmlspecialchars($t['table_number'] ?? $table_id); ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Search -->
            <div class="search-bar">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="menuSearch" placeholder="Search menu items..." autocomplete="off">
            </div>

            <!-- Category Pills -->
            <?php if ($has_cat && count($categories) > 1): ?>
            <div class="cat-pills">
                <?php foreach ($categories as $cat): ?>
                <button type="button" class="cat-pill<?php echo $cat === 'All' ? ' active' : ''; ?>"
                        data-cat="<?php echo htmlspecialchars($cat); ?>"
                        onclick="filterCategory(this)">
                    <?php echo htmlspecialchars($cat); ?>
                </button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Error -->
            <?php if ($err): ?>
            <div class="err-msg"><i class="fa-solid fa-circle-exclamation"></i><?php echo htmlspecialchars($err); ?></div>
            <?php endif; ?>

            <!-- Menu Grid -->
            <div class="menu-grid" id="menuGrid">
                <?php foreach ($menu_items as $item): ?>
                <?php
                    $cat_val = $has_cat ? ($item['category'] ?? '') : '';
                ?>
                <div class="menu-card"
                     data-id="<?php echo (int)$item['id']; ?>"
                     data-name="<?php echo htmlspecialchars($item['name']); ?>"
                     data-price="<?php echo (float)$item['price']; ?>"
                     data-cat="<?php echo htmlspecialchars($cat_val); ?>">

                    <?php if ($has_img && !empty($item['image'])): ?>
                    <div class="card-img">
                        <img src="<?php echo htmlspecialchars($item['image']); ?>"
                             alt="<?php echo htmlspecialchars($item['name']); ?>"
                             onerror="this.parentElement.style.display='none'">
                    </div>
                    <?php endif; ?>

                    <div class="card-name"><?php echo htmlspecialchars($item['name']); ?></div>

                    <?php if ($has_desc && !empty($item['description'])): ?>
                    <div class="card-desc"><?php echo htmlspecialchars($item['description']); ?></div>
                    <?php endif; ?>

                    <div class="card-price">DKK <?php echo number_format((float)$item['price'], 2); ?></div>

                    <div class="qty-controls" onclick="event.stopPropagation()">
                        <button type="button" class="qty-btn"
                                onclick="changeQty(<?php echo (int)$item['id']; ?>, -1)">−</button>
                        <input type="number"
                               class="qty-input"
                               name="quantity[<?php echo (int)$item['id']; ?>]"
                               id="qty-<?php echo (int)$item['id']; ?>"
                               value="0" min="0" max="99"
                               oninput="syncQty(<?php echo (int)$item['id']; ?>, this.value)"
                               onchange="syncQty(<?php echo (int)$item['id']; ?>, this.value)">
                        <button type="button" class="qty-btn"
                                onclick="changeQty(<?php echo (int)$item['id']; ?>, 1)">+</button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

        </div><!-- /menu-side-inner -->
    </div><!-- /menu-side -->

    <!-- ══ RIGHT: CART SIDE ══ -->
    <div class="cart-side">

        <!-- Cart Header -->
        <div class="cart-header">
            <h3><i class="fa-solid fa-receipt"></i> Order Summary</h3>
            <div class="cart-order-info">
                <span class="cart-table-label">Table: <strong><?php echo htmlspecialchars($t['table_number'] ?? $table_id); ?></strong></span>
                <span class="order-type-badge <?php echo $order_type === 'reservation' ? 'badge-reservation' : 'badge-walkin'; ?>">
                    <?php echo $order_type === 'reservation' ? 'Reservation' : 'Walk-In'; ?>
                </span>
                <?php if ($order_type === 'reservation' && $reservation_info): ?>
                <span style="font-size:11px;color:var(--muted);">
                    <?php echo htmlspecialchars($reservation_info['customer_name']); ?>
                </span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Cart Body -->
        <div class="cart-body" id="cartBody">
            <div class="cart-empty" id="cartEmpty">
                <i class="fa-solid fa-cart-shopping"></i>
                <span>No items added yet</span>
                <span style="font-size:11px;color:var(--muted2);">Select items from the menu</span>
            </div>
            <div id="cartItems"></div>
        </div>

        <!-- Cart Footer -->
        <div class="cart-footer">
            <div class="cart-total-row">
                <span class="cart-total-label">Total</span>
                <span class="cart-total-val" id="cartTotal">DKK 0.00</span>
            </div>

            <!-- Notes Section -->
            <div style="margin-bottom:10px;">
                <div class="notes-label">Special Instructions</div>
                <div style="display:flex;flex-wrap:wrap;gap:5px;margin-bottom:8px;">
                    <button type="button" onclick="addTag('Nut Allergy')" class="tag-btn">🌰 Nut Allergy</button>
                    <button type="button" onclick="addTag('Gluten Free')" class="tag-btn">🌾 Gluten Free</button>
                    <button type="button" onclick="addTag('Dairy Free')"  class="tag-btn">🥛 Dairy Free</button>
                    <button type="button" onclick="addTag('Extra Spicy')" class="tag-btn">🌶 Extra Spicy</button>
                    <button type="button" onclick="addTag('No Salt')"     class="tag-btn">🧂 No Salt</button>
                    <button type="button" onclick="addTag('Vegan')"       class="tag-btn">🥗 Vegan</button>
                    <button type="button" onclick="addTag('Mild')"        class="tag-btn">😌 Mild</button>
                </div>
                <textarea name="notes"
                          id="orderNotes"
                          class="notes-textarea"
                          placeholder="Special instructions, allergies..."
                          rows="2"></textarea>
            </div>

            <button type="submit" name="submit_order" class="submit-btn" id="submitBtn" disabled>
                <i class="fa-solid fa-paper-plane"></i>
                Place Order
            </button>
        </div>

    </div><!-- /cart-side -->

</div><!-- /order-layout -->

<!-- Hidden reservation / walk-in data -->
<?php if ($reservation_id): ?>
<input type="hidden" name="reservation_id" value="<?php echo $reservation_id; ?>">
<?php endif; ?>
<?php if ($walk_in_guests): ?>
<input type="hidden" name="walk_in_guests" value="<?php echo $walk_in_guests; ?>">
<?php endif; ?>
<input type="hidden" name="order_type" value="<?php echo htmlspecialchars($order_type); ?>">

</form>

<div id="toast-wrap"></div>

<script>
/* ── Menu item data from PHP ── */
const menuData = <?php
    $jsData = [];
    foreach ($menu_items as $item) {
        $jsData[] = [
            'id'    => (int)$item['id'],
            'name'  => $item['name'],
            'price' => (float)$item['price'],
        ];
    }
    echo json_encode($jsData);
?>;

const priceMap = {};
menuData.forEach(m => { priceMap[m.id] = { name: m.name, price: m.price }; });

/* ── Cart state ── */
const cart = {};

/* ── Change qty by delta ── */
function changeQty(id, delta) {
    const input = document.getElementById('qty-' + id);
    if (!input) return;
    let val = (parseInt(input.value) || 0) + delta;
    if (val < 0) val = 0;
    if (val > 99) val = 99;
    input.value = val;
    syncQty(id, val);
}

/* ── Sync qty input → cart ── */
function syncQty(id, rawVal) {
    const val  = Math.max(0, Math.min(99, parseInt(rawVal) || 0));
    const input = document.getElementById('qty-' + id);
    if (input) input.value = val;

    const card = document.querySelector(`.menu-card[data-id="${id}"]`);
    if (card) card.classList.toggle('has-qty', val > 0);

    if (val > 0) {
        cart[id] = val;
    } else {
        delete cart[id];
    }
    updateCart();
}

/* ── Update cart display ── */
function updateCart() {
    const cartItems = document.getElementById('cartItems');
    const cartEmpty = document.getElementById('cartEmpty');
    const cartTotal = document.getElementById('cartTotal');
    const submitBtn = document.getElementById('submitBtn');

    const ids = Object.keys(cart).filter(id => cart[id] > 0);
    cartEmpty.style.display = ids.length === 0 ? 'flex' : 'none';

    let html  = '';
    let total = 0;
    ids.forEach(id => {
        const qty   = cart[id];
        const info  = priceMap[id];
        if (!info) return;
        const line  = info.price * qty;
        total      += line;
        html += `<div class="cart-item">
            <div style="flex:1;">
                <div class="ci-name">${esc(info.name)}</div>
                <div class="ci-qty">× ${qty}</div>
            </div>
            <div class="ci-price">DKK ${line.toFixed(2)}</div>
        </div>`;
    });
    cartItems.innerHTML = html;
    cartTotal.textContent = 'DKK ' + total.toFixed(2);
    submitBtn.disabled = ids.length === 0;
}

/* ── Category filter ── */
function filterCategory(pill) {
    document.querySelectorAll('.cat-pill').forEach(p => p.classList.remove('active'));
    pill.classList.add('active');
    const cat = pill.dataset.cat;
    document.querySelectorAll('.menu-card').forEach(card => {
        if (cat === 'All' || card.dataset.cat === cat) {
            card.classList.remove('hidden');
        } else {
            card.classList.add('hidden');
        }
    });
    applySearch();
}

/* ── Search filter ── */
function applySearch() {
    const q = document.getElementById('menuSearch').value.trim().toLowerCase();
    document.querySelectorAll('.menu-card').forEach(card => {
        if (card.classList.contains('hidden')) return; // already hidden by category
        const name = (card.dataset.name || '').toLowerCase();
        card.style.display = (!q || name.includes(q)) ? '' : 'none';
    });
}

document.getElementById('menuSearch').addEventListener('input', function() {
    // Reset category-hidden state first for search
    const activeCat = document.querySelector('.cat-pill.active');
    const cat = activeCat ? activeCat.dataset.cat : 'All';
    const q   = this.value.trim().toLowerCase();
    document.querySelectorAll('.menu-card').forEach(card => {
        const inCat  = (cat === 'All' || card.dataset.cat === cat);
        const inSrch = (!q || (card.dataset.name || '').toLowerCase().includes(q));
        if (inCat && inSrch) {
            card.classList.remove('hidden');
            card.style.display = '';
        } else if (!inCat) {
            card.classList.add('hidden');
            card.style.display = '';
        } else {
            card.style.display = 'none';
        }
    });
});

/* ── Add allergen tag to notes ── */
function addTag(tag) {
    const ta = document.getElementById('orderNotes');
    const cur = ta.value.trim();
    ta.value = cur ? cur + ', ' + tag : tag;
    ta.focus();
}

/* ── Escape HTML for cart display ── */
function esc(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

/* ── Toast ── */
function showToast(msg, type='info') {
    const icons = { success:'fa-circle-check', error:'fa-circle-xmark', info:'fa-circle-info', warning:'fa-triangle-exclamation' };
    const t = document.createElement('div');
    t.className = `toast ${type}`;
    t.innerHTML = `<i class="fa-solid ${icons[type]||icons.info}"></i><span>${msg}</span>`;
    document.getElementById('toast-wrap').appendChild(t);
    setTimeout(() => { t.style.opacity='0'; t.style.transition='.3s'; setTimeout(()=>t.remove(),300); }, 3000);
}

/* ── Init cart ── */
updateCart();
</script>

</body>
</html>
