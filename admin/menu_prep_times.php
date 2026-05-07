<?php
/**
 * Admin — Menu Item Prep Times
 * Set how many minutes each menu item takes to prepare.
 * The service-quality scoring engine uses this as the per-item target time.
 */
session_start();
include("../config/db.php");

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: ../auth/login.php"); exit();
}

/* ── Ensure column exists ── */
$col_exists = $conn->query("SHOW COLUMNS FROM menu_items LIKE 'prep_time_minutes'")->num_rows > 0;
if (!$col_exists) {
    $conn->query("ALTER TABLE menu_items ADD COLUMN prep_time_minutes INT NOT NULL DEFAULT 0 AFTER price");
    $col_exists = true;
}

/* ── Handle AJAX save ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
    header('Content-Type: application/json');
    $id   = (int)($_POST['id']   ?? 0);
    $mins = (int)($_POST['mins'] ?? 0);
    if ($id < 1 || $mins < 0 || $mins > 300) {
        echo json_encode(['success' => false, 'error' => 'Invalid input']);
        exit();
    }
    $stmt = $conn->prepare("UPDATE menu_items SET prep_time_minutes = ? WHERE id = ?");
    if (!$stmt) {
        echo json_encode(['success' => false, 'error' => $conn->error]);
        exit();
    }
    $stmt->bind_param("ii", $mins, $id);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => true]);
    exit();
}

/* ── Handle bulk save (form POST) ── */
$saved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_save'])) {
    $times = $_POST['prep_times'] ?? [];
    $stmt  = $conn->prepare("UPDATE menu_items SET prep_time_minutes = ? WHERE id = ?");
    if ($stmt) {
        foreach ($times as $id => $mins) {
            $id   = (int)$id;
            $mins = max(0, min(300, (int)$mins));
            $stmt->bind_param("ii", $mins, $id);
            $stmt->execute();
        }
        $stmt->close();
    }
    $saved = true;
}

/* ── Load menu items grouped by category ── */
$items_q = $conn->query("
    SELECT id, name, category, price, prep_time_minutes
    FROM menu_items
    ORDER BY category ASC, name ASC
");
$categories = [];
while ($r = $items_q->fetch_assoc()) {
    $categories[$r['category']][] = $r;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Prep Times — RestaurantMS Admin</title>
<?php include('includes/admin_styles.php'); ?>
<style>
.pt-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 28px;
    flex-wrap: wrap;
    gap: 16px;
}
.pt-title { font-size: 22px; font-weight: 700; color: var(--text); font-family: 'Playfair Display', serif; }
.pt-subtitle { font-size: 13px; color: var(--muted); margin-top: 4px; }

.pt-info-card {
    background: var(--blue-dim);
    border: 1px solid rgba(96,165,250,0.2);
    border-radius: 10px;
    padding: 14px 18px;
    margin-bottom: 24px;
    display: flex;
    gap: 12px;
    align-items: flex-start;
    font-size: 13px;
    color: var(--blue);
    line-height: 1.6;
}
.pt-info-card i { margin-top: 2px; flex-shrink: 0; }

.category-block { margin-bottom: 32px; }
.category-label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .1em;
    color: var(--gold);
    padding: 0 0 10px 2px;
    border-bottom: 1px solid var(--border2);
    margin-bottom: 12px;
}

.item-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 12px;
}

.item-card {
    background: var(--surface3);
    border: 1px solid var(--border2);
    border-radius: 10px;
    padding: 14px 16px;
    display: flex;
    align-items: center;
    gap: 14px;
    transition: border-color .2s;
}
.item-card:hover { border-color: rgba(201,162,39,0.3); }
.item-card.has-time { border-left: 3px solid var(--green); }

.item-info { flex: 1; min-width: 0; }
.item-name { font-size: 14px; font-weight: 600; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.item-price { font-size: 12px; color: var(--muted); margin-top: 2px; }

.pt-input-wrap { display: flex; align-items: center; gap: 6px; flex-shrink: 0; }
.pt-input {
    width: 64px;
    padding: 7px 10px;
    border-radius: 7px;
    border: 1px solid var(--border2);
    background: var(--surface);
    color: var(--text);
    font-size: 14px;
    font-weight: 600;
    text-align: center;
    font-family: inherit;
    transition: border-color .2s, box-shadow .2s;
}
.pt-input:focus { outline: none; border-color: var(--gold); box-shadow: 0 0 0 3px var(--gold-glow); }
.pt-input.saving { border-color: var(--blue); }
.pt-input.saved  { border-color: var(--green); }
.pt-unit { font-size: 12px; color: var(--muted); }

.save-tick { font-size: 13px; color: var(--green); opacity: 0; transition: opacity .3s; }
.save-tick.show { opacity: 1; }

.btn-bulk-save {
    padding: 10px 24px;
    border-radius: 8px;
    border: none;
    background: var(--gold);
    color: #07080d;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    font-family: inherit;
    transition: background .2s;
}
.btn-bulk-save:hover { background: var(--gold2); }

.toast {
    position: fixed;
    bottom: 28px;
    right: 28px;
    background: var(--green);
    color: #07080d;
    font-weight: 700;
    font-size: 14px;
    padding: 12px 22px;
    border-radius: 10px;
    box-shadow: 0 4px 24px rgba(0,0,0,.4);
    opacity: 0;
    transform: translateY(12px);
    transition: opacity .3s, transform .3s;
    z-index: 9999;
    pointer-events: none;
}
.toast.show { opacity: 1; transform: translateY(0); }
</style>
</head>
<body>
<?php include('includes/sidebar.php'); ?>

<main class="main-content">
<div class="pt-header">
  <div>
    <div class="pt-title">Menu Prep Times</div>
    <div class="pt-subtitle">Set the expected kitchen preparation time for each item</div>
  </div>
  <form method="post">
    <input type="hidden" name="bulk_save" value="1">
    <button type="submit" class="btn-bulk-save"><i class="fa-solid fa-floppy-disk" style="margin-right:6px"></i>Save All</button>
  </form>
</div>

<?php if ($saved): ?>
<div class="pt-info-card" style="background:var(--green-dim);border-color:rgba(34,197,94,.25);color:var(--green)">
  <i class="fa-solid fa-circle-check"></i>
  <span>All prep times saved successfully.</span>
</div>
<?php endif; ?>

<div class="pt-info-card">
  <i class="fa-solid fa-circle-info"></i>
  <span>
    The service quality engine uses these times as the target for <strong>Condition 2</strong>
    (kitchen running late). If an item has no time set (0), the system falls back to the
    historical average from completed orders, then to 15 minutes.
    <br>Set the longest expected time for complex items — the engine uses the <strong>maximum</strong>
    across all items in an order.
  </span>
</div>

<form method="post">
<input type="hidden" name="bulk_save" value="1">

<?php foreach ($categories as $cat => $items): ?>
<div class="category-block">
  <div class="category-label"><i class="fa-solid fa-tag" style="margin-right:6px"></i><?php echo htmlspecialchars($cat ?: 'Uncategorised'); ?></div>
  <div class="item-grid">
    <?php foreach ($items as $item): ?>
    <?php $has_t = (int)$item['prep_time_minutes'] > 0; ?>
    <div class="item-card <?php echo $has_t ? 'has-time' : ''; ?>" id="card-<?php echo $item['id']; ?>">
      <div class="item-info">
        <div class="item-name" title="<?php echo htmlspecialchars($item['name']); ?>"><?php echo htmlspecialchars($item['name']); ?></div>
        <div class="item-price">DKK <?php echo number_format((float)$item['price'], 2); ?></div>
      </div>
      <div class="pt-input-wrap">
        <input
          type="number"
          name="prep_times[<?php echo $item['id']; ?>]"
          class="pt-input"
          value="<?php echo (int)$item['prep_time_minutes']; ?>"
          min="0" max="300" step="1"
          data-id="<?php echo $item['id']; ?>"
          placeholder="0"
          title="Prep time in minutes (0 = auto)"
        >
        <span class="pt-unit">min</span>
        <i class="fa-solid fa-check save-tick" id="tick-<?php echo $item['id']; ?>"></i>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>

</form>

<div class="toast" id="toast"></div>

<script>
function showToast(msg, ok = true) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.style.background = ok ? 'var(--green)' : 'var(--red)';
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 2800);
}

/* Auto-save on blur or Enter */
document.querySelectorAll('.pt-input').forEach(inp => {
    let last = inp.value;

    const save = () => {
        if (inp.value === last) return;
        last = inp.value;
        const id   = inp.dataset.id;
        const mins = parseInt(inp.value) || 0;
        const card = document.getElementById('card-' + id);
        const tick = document.getElementById('tick-' + id);

        inp.classList.add('saving');
        inp.classList.remove('saved');

        fetch('menu_prep_times.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=save&id=${id}&mins=${mins}`
        })
        .then(r => r.json())
        .then(d => {
            inp.classList.remove('saving');
            if (d.success) {
                inp.classList.add('saved');
                tick.classList.add('show');
                if (mins > 0) card.classList.add('has-time');
                else          card.classList.remove('has-time');
                showToast('Saved', true);
                setTimeout(() => { inp.classList.remove('saved'); tick.classList.remove('show'); }, 1800);
            } else {
                showToast('Save failed', false);
            }
        })
        .catch(() => { inp.classList.remove('saving'); showToast('Network error', false); });
    };

    inp.addEventListener('blur', save);
    inp.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); inp.blur(); } });
});
</script>
</main>
</body>
</html>
