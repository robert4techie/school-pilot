<?php
/**
 * withdraw_medicine.php — REDESIGNED
 * Design aligned with view_students.php.
 * CSRF token sent with dispensing request.
 * Shows live stock info when an item is selected.
 */

require_once "conn.php";
require_once "auth.php";
require_once 'tracking.php';
$tracker->trackAction("Dispense Medical Supplies");

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dispense Supplies — School Pilot</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
@import url('https://fonts.googleapis.com/css2?family=Sen:wght@400;600;700;800&display=swap');
:root{
  --g900:#1b5e20;--g800:#2e7d32;--g700:#388e3c;--g600:#43a047;
  --g400:#66bb6a;--g100:#e8f5e9;--g50:#f1f8f1;
  --red:#d32f2f;--red-bg:#ffebee;
  --orange:#e65100;--orange-bg:#fff3e0;
  --gray:#546e7a;
  --radius:8px;--radius-lg:12px;
  --shadow:0 2px 8px rgba(0,0,0,.10);
  --shadow-lg:0 8px 28px rgba(0,0,0,.14);
  --transition:.22s ease;
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:"Sen",system-ui,sans-serif;background:#f0f4f1;min-height:100vh;color:#222}

.page{max-width:100%;margin:0 auto;padding:24px 20px 52px;margin-top:40px}

.page-header{background:linear-gradient(135deg,var(--g900) 0%,var(--g700) 100%);border-radius:var(--radius-lg);padding:26px 30px;margin-bottom:24px;box-shadow:var(--shadow-lg);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
.page-header h1{color:#fff;font-size:1.4rem;font-weight:700;display:flex;align-items:center;gap:10px}
.page-header p{color:rgba(255,255,255,.75);font-size:.88rem;margin-top:3px}
.btn-back{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25);color:#fff;padding:8px 16px;border-radius:var(--radius);font-size:.82rem;font-weight:600;font-family:inherit;cursor:pointer;display:flex;align-items:center;gap:7px;transition:background var(--transition)}
.btn-back:hover{background:rgba(255,255,255,.25)}

.card{background:#fff;border-radius:var(--radius-lg);box-shadow:var(--shadow);margin-bottom:20px;overflow:hidden}
.card-head{background:var(--g50);border-bottom:1px solid #e0ebe1;padding:14px 22px;display:flex;align-items:center;gap:9px;font-size:.9rem;font-weight:700;color:var(--g800)}
.card-head i{opacity:.7}
.card-body{padding:20px 22px}

.fg{display:flex;flex-direction:column;gap:5px;margin-bottom:16px}
.fg:last-child{margin-bottom:0}
.fg label{font-size:.78rem;font-weight:700;color:var(--g800);text-transform:uppercase;letter-spacing:.4px}
.required{color:var(--red)}
.fg input,.fg select,.fg textarea{padding:9px 12px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;font-family:inherit;background:#fff;transition:border-color var(--transition)}
.fg input:focus,.fg select:focus,.fg textarea:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.12)}
.fg input[readonly]{background:#f5f5f5;color:var(--gray);cursor:default}
.fg textarea{resize:vertical;min-height:80px}

/* Stock preview box */
.stock-preview{display:none;background:var(--g50);border:1.5px solid var(--g400);border-radius:var(--radius);padding:12px 16px;margin-bottom:16px;font-size:.85rem}
.stock-preview.show{display:block}
.stock-preview .spl{font-size:.72rem;font-weight:700;color:var(--gray);text-transform:uppercase;letter-spacing:.4px}
.stock-preview .spv{font-size:1rem;font-weight:700;color:var(--g800);margin-top:2px}
.stock-preview.warn{background:var(--orange-bg);border-color:var(--orange)}
.stock-preview.warn .spv{color:var(--orange)}
.stock-bar-wrap{height:6px;background:#d0dbd1;border-radius:3px;margin-top:8px;overflow:hidden}
.stock-bar{height:100%;border-radius:3px;background:var(--g600);transition:width .4s ease}
.stock-bar.low{background:var(--orange)}
.stock-bar.out{background:var(--red)}

.action-bar{display:flex;justify-content:flex-end;gap:12px}
.btn{display:inline-flex;align-items:center;gap:7px;padding:10px 20px;border:none;border-radius:var(--radius);font-size:.9rem;font-weight:700;font-family:inherit;cursor:pointer;transition:all var(--transition)}
.btn:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.15)}
.btn-primary{background:var(--g700);color:#fff}.btn-primary:hover{background:var(--g800)}
.btn-outline{background:transparent;color:var(--gray);border:1.5px solid #d0dbd1}.btn-outline:hover{border-color:var(--gray);background:#f5f5f5;transform:none}

#notif-stack{position:fixed;top:20px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:8px;pointer-events:none}
.notif{display:flex;align-items:flex-start;gap:10px;padding:12px 16px;border-radius:var(--radius);box-shadow:var(--shadow-lg);min-width:240px;max-width:340px;pointer-events:all;background:#fff;border-left:4px solid var(--g600);animation:slideIn .25s ease}
@keyframes slideIn{from{transform:translateX(30px);opacity:0}to{transform:none;opacity:1}}
.notif.error{border-left-color:var(--red)}
.notif.warning{border-left-color:var(--orange)}
.notif-icon{margin-top:1px}
.notif.success .notif-icon{color:var(--g600)}
.notif.error .notif-icon{color:var(--red)}
.notif.warning .notif-icon{color:var(--orange)}
.notif-body{flex:1;font-size:.82rem}
.notif-title{font-weight:700}
.notif-msg{color:var(--gray)}
.notif-close{background:none;border:none;cursor:pointer;color:#aaa;font-size:.8rem;padding:0;margin-left:4px}
</style>
</head>
<body>
<?php require_once 'nav.php' ?>
<div id="notif-stack"></div>

<div class="page">

  <header class="page-header">
    <div>
      <h1><i class="fas fa-prescription-bottle-alt"></i> Dispense Medical Supplies</h1>
      <p>Record a dispensing transaction and deduct stock automatically.</p>
    </div>
    <button class="btn-back" onclick="window.history.back()"><i class="fas fa-arrow-left"></i> Back</button>
  </header>

  <form id="dispenseForm">

    <!-- Patient -->
    <div class="card">
      <div class="card-head"><i class="fas fa-user-injured"></i> Patient Information</div>
      <div class="card-body">
        <div class="fg">
          <label for="studentSel">Student Name <span class="required">*</span></label>
          <select id="studentSel" name="student_id" required>
            <option value="">Loading students…</option>
          </select>
        </div>
        <div class="fg">
          <label>Student ID</label>
          <input type="text" id="stdId" readonly placeholder="Auto-filled on selection">
        </div>
      </div>
    </div>

    <!-- Item -->
    <div class="card">
      <div class="card-head"><i class="fas fa-pills"></i> Item to Dispense</div>
      <div class="card-body">
        <div class="fg">
          <label for="itemSel">Select Item <span class="required">*</span></label>
          <select id="itemSel" name="item_id" required>
            <option value="">Loading inventory…</option>
          </select>
        </div>

        <!-- Live stock preview — shown on item selection -->
        <div id="stockPreview" class="stock-preview">
          <div class="spl">Current Stock</div>
          <div class="spv" id="stockVal">—</div>
          <div class="stock-bar-wrap"><div class="stock-bar" id="stockBar"></div></div>
        </div>

        <div class="fg">
          <label for="qtyInput">Quantity to Dispense <span class="required">*</span></label>
          <input type="number" id="qtyInput" name="quantity" min="1" required placeholder="e.g., 2">
        </div>
        <div class="fg">
          <label for="notesInput">Notes / Purpose</label>
          <textarea id="notesInput" name="notes" rows="3" placeholder="Reason for dispensing, instructions…"></textarea>
        </div>
      </div>
    </div>

    <div class="action-bar">
      <button type="button" class="btn btn-outline" onclick="window.history.back()"><i class="fas fa-times"></i> Cancel</button>
      <button type="submit" class="btn btn-primary" id="btnSubmit"><i class="fas fa-check"></i> Dispense</button>
    </div>

  </form>
</div>

<script>
const CSRF = <?= json_encode($csrf) ?>;
let studentsData = [];
let inventoryData = [];

document.addEventListener('DOMContentLoaded', () => {
  fetchStudents();
  fetchInventory();

  document.getElementById('studentSel').addEventListener('change', function(){
    const s = studentsData.find(x=>x.student_id===this.value);
    document.getElementById('stdId').value = s ? s.student_id : '';
  });

  document.getElementById('itemSel').addEventListener('change', function(){
    const item = inventoryData.find(i=>i.id==this.value);
    updateStockPreview(item);
  });

  document.getElementById('dispenseForm').addEventListener('submit', submitForm);
});

// ── Fetch students ────────────────────────────────────────────────────────────
async function fetchStudents() {
  try {
    const res    = await fetch('api/students.php');
    const result = await res.json();
    if (!result.success) throw new Error(result.message||'Failed to load students.');
    studentsData = result.data;
    const sel = document.getElementById('studentSel');
    sel.innerHTML = '<option value="">Select a student…</option>'
      + studentsData.map(s=>`<option value="${esc(s.student_id)}">${esc(s.full_name)} (${esc(s.student_id)})</option>`).join('');
  } catch(e) {
    document.getElementById('studentSel').innerHTML = '<option value="">Failed to load students</option>';
    notify('Error', e.message, 'error');
  }
}

// ── Fetch inventory ───────────────────────────────────────────────────────────
async function fetchInventory() {
  try {
    const res  = await fetch('api/get_inventory.php');
    const data = await res.json();
    if (data.error) throw new Error(data.error);
    inventoryData = data;
    const sel = document.getElementById('itemSel');
    sel.innerHTML = '<option value="">Select an item…</option>'
      + inventoryData.map(i=>
          `<option value="${i.id}" ${parseInt(i.quantity)===0?'disabled':''}>
            ${esc(i.item_name)} — Stock: ${esc(i.quantity)} ${esc(i.unit||'')}
           </option>`
        ).join('');
  } catch(e) {
    document.getElementById('itemSel').innerHTML = '<option value="">Failed to load inventory</option>';
    notify('Error', e.message, 'error');
  }
}

// ── Stock preview ─────────────────────────────────────────────────────────────
function updateStockPreview(item) {
  const box = document.getElementById('stockPreview');
  if (!item) { box.classList.remove('show','warn'); return; }
  const qty = parseInt(item.quantity);
  const thr = parseInt(item.threshold);
  const pct = Math.min(100, Math.round((qty/Math.max(thr*2,1))*100));
  const isLow = qty <= thr;
  document.getElementById('stockVal').textContent = `${qty} ${item.unit||'units'} available`;
  const bar = document.getElementById('stockBar');
  bar.style.width = pct+'%';
  bar.className = 'stock-bar' + (qty===0?' out': isLow?' low':'');
  box.classList.remove('warn');
  if (isLow) box.classList.add('warn');
  box.classList.add('show');
}

// ── Submit ────────────────────────────────────────────────────────────────────
async function submitForm(e) {
  e.preventDefault();
  const btn = document.getElementById('btnSubmit');
  btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Dispensing…';

  const studentId = document.getElementById('studentSel').value;
  const itemId    = parseInt(document.getElementById('itemSel').value);
  const quantity  = parseInt(document.getElementById('qtyInput').value);
  const notes     = document.getElementById('notesInput').value;

  // Client-side stock check
  const item = inventoryData.find(i=>i.id==itemId);
  if (item && quantity > parseInt(item.quantity)) {
    notify('Insufficient Stock', `Only ${item.quantity} ${item.unit||'units'} available.`, 'warning');
    btn.disabled = false; btn.innerHTML = '<i class="fas fa-check"></i> Dispense';
    return;
  }

  try {
    const res    = await fetch('api/process_withdrawal.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({csrf_token:CSRF, student_id:studentId, item_id:itemId, quantity, notes})
    });
    const result = await res.json();
    if (result.success) {
      notify('Dispensed', result.message, 'success');
      e.target.reset();
      document.getElementById('stdId').value = '';
      document.getElementById('stockPreview').classList.remove('show','warn');
      await fetchInventory(); // refresh stock display
    } else {
      notify('Error', result.message, 'error');
    }
  } catch(err) {
    notify('Error', 'Request failed. Please try again.', 'error');
  } finally {
    btn.disabled = false; btn.innerHTML = '<i class="fas fa-check"></i> Dispense';
  }
}

// ── Notifications & utils ─────────────────────────────────────────────────────
function notify(title, msg, type='success', dur=4500) {
  const icons={success:'fa-circle-check',error:'fa-circle-xmark',warning:'fa-triangle-exclamation'};
  const n = document.createElement('div');
  n.className=`notif ${type}`;
  n.innerHTML=`<i class="fas ${icons[type]||icons.success} notif-icon"></i>
    <div class="notif-body"><div class="notif-title">${esc(title)}</div><div class="notif-msg">${esc(msg)}</div></div>
    <button class="notif-close" onclick="this.closest('.notif').remove()"><i class="fas fa-times"></i></button>`;
  document.getElementById('notif-stack').prepend(n);
  setTimeout(()=>{n.style.opacity='0';n.style.transform='translateX(30px)';n.style.transition='.3s';setTimeout(()=>n.remove(),300);},dur);
}
function esc(v){ return String(v??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
</script>
</body>
</html>
