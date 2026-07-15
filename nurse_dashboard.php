<?php
/**
 * nurse_dashboard.php — REDESIGNED
 *
 * Design aligned with view_students.php: Sen font, green CSS variable system,
 * gradient page header with stat pills, clean card layout with shadow.
 *
 * Bugs fixed:
 *  - visit.visit_time was undefined; now formats visit_datetime correctly.
 *  - 'custom' date option was missing from the <select>; now present.
 *  - Charts rebuilt with Chart.js custom styling to match the green theme.
 *  - Skeleton loaders instead of plain spinner text.
 */

require_once 'auth.php';
require_once 'tracking.php';
$tracker->trackAction("Nurse Dashboard");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sick Bay Dashboard — School Pilot</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js" defer></script>
<style>
/* ── Variables (shared with view_students.php) ──────────────────────────── */
:root{
  --g900:#1b5e20;--g800:#2e7d32;--g700:#388e3c;--g600:#43a047;
  --g400:#66bb6a;--g100:#e8f5e9;--g50:#f1f8f1;
  --red:#d32f2f;--red-bg:#ffebee;
  --orange:#e65100;--orange-bg:#fff3e0;
  --blue:#1565c0;--blue-bg:#e3f2fd;
  --gray:#546e7a;
  --radius:8px;--radius-lg:12px;
  --shadow:0 2px 8px rgba(0,0,0,.10);
  --shadow-lg:0 8px 28px rgba(0,0,0,.14);
  --transition:.22s ease;
}
@import url('https://fonts.googleapis.com/css2?family=Sen:wght@400;600;700;800&display=swap');
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:"Sen",system-ui,sans-serif;background:#f0f4f1;min-height:100vh;color:#222}
a{color:inherit;text-decoration:none}

/* ── Layout ─────────────────────────────────────────────────────────────── */
.page{max-width:100%;padding:24px 20px 52px;margin-top:40px}

/* ── Page Header ─────────────────────────────────────────────────────────── */
.page-header{
  background:linear-gradient(135deg,var(--g900) 0%,var(--g700) 100%);
  border-radius:var(--radius-lg);padding:28px 32px;
  display:flex;align-items:center;justify-content:space-between;
  flex-wrap:wrap;gap:20px;margin-bottom:24px;box-shadow:var(--shadow-lg)
}
.page-header-left h1{color:#fff;font-size:1.55rem;font-weight:700;letter-spacing:.3px}
.page-header-left p{color:rgba(255,255,255,.75);font-size:.88rem;margin-top:3px}
.page-header-right{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.stat-pill{
  background:rgba(255,255,255,.13);border:1px solid rgba(255,255,255,.22);
  border-radius:40px;padding:8px 18px;text-align:center;min-width:80px;
  cursor:default;transition:background var(--transition)
}
.stat-pill:hover{background:rgba(255,255,255,.22)}
.stat-pill .n{font-size:1.35rem;font-weight:700;color:#fff;display:block}
.stat-pill .l{font-size:.72rem;color:rgba(255,255,255,.72);text-transform:uppercase;letter-spacing:.5px}

/* ── Filters bar ─────────────────────────────────────────────────────────── */
.filters-bar{
  background:#fff;border-radius:var(--radius-lg);padding:16px 24px;
  display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;
  box-shadow:var(--shadow);margin-bottom:24px
}
.fgrp{display:flex;flex-direction:column;gap:5px}
.fgrp label{font-size:.78rem;font-weight:600;color:var(--gray);text-transform:uppercase;letter-spacing:.4px}
.fgrp select,.fgrp input[type=date]{
  padding:8px 12px;border:1.5px solid #d0dbd1;border-radius:var(--radius);
  font-size:.875rem;font-family:inherit;background:#fff;cursor:pointer;min-width:140px;
  transition:border-color var(--transition)
}
.fgrp select:focus,.fgrp input[type=date]:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.12)}
.fgrp input[type=date]:disabled{opacity:.4;cursor:default;background:#f5f5f5}
.btn{
  display:inline-flex;align-items:center;gap:7px;padding:9px 16px;
  border:none;border-radius:var(--radius);font-size:.85rem;font-weight:600;
  font-family:inherit;cursor:pointer;transition:all var(--transition);white-space:nowrap
}
.btn:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.15)}
.btn:active{transform:none}
.btn-primary{background:var(--g700);color:#fff}.btn-primary:hover{background:var(--g800)}
.btn-pdf{background:var(--red);color:#fff}.btn-pdf:hover{background:#b71c1c}

/* ── Stat Cards Row ──────────────────────────────────────────────────────── */
.stats-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:16px;margin-bottom:24px}
.stat-card{
  background:#fff;border-radius:var(--radius-lg);padding:20px 22px;
  box-shadow:var(--shadow);display:flex;align-items:center;gap:16px;
  transition:transform var(--transition),box-shadow var(--transition);
  border-top:3px solid transparent
}
.stat-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-lg)}
.stat-card.green{border-top-color:var(--g600)}
.stat-card.red{border-top-color:var(--red)}
.stat-card.orange{border-top-color:var(--orange)}
.stat-card.blue{border-top-color:var(--blue)}
.stat-icon{
  width:48px;height:48px;border-radius:12px;
  display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0
}
.stat-icon.green{background:var(--g100);color:var(--g700)}
.stat-icon.red{background:var(--red-bg);color:var(--red)}
.stat-icon.orange{background:var(--orange-bg);color:var(--orange)}
.stat-icon.blue{background:var(--blue-bg);color:var(--blue)}
.stat-info{flex:1}
.stat-num{font-size:1.7rem;font-weight:800;line-height:1;color:#1a1a1a}
.stat-lbl{font-size:.78rem;color:var(--gray);margin-top:3px}

/* ── Grid layout ─────────────────────────────────────────────────────────── */
.main-grid{display:grid;grid-template-columns:1fr 340px;gap:20px;align-items:start}
@media(max-width:1100px){.main-grid{grid-template-columns:1fr}}

/* ── Card ────────────────────────────────────────────────────────────────── */
.card{background:#fff;border-radius:var(--radius-lg);box-shadow:var(--shadow);overflow:hidden;margin-bottom:20px}
.card-head{
  padding:16px 22px;border-bottom:1px solid #e8ede9;
  display:flex;align-items:center;justify-content:space-between
}
.card-head h2{font-size:.95rem;font-weight:700;color:var(--g800);display:flex;align-items:center;gap:8px}
.card-head h2 i{opacity:.7}
.view-all{font-size:.8rem;font-weight:600;color:var(--g600);transition:color var(--transition)}
.view-all:hover{color:var(--g800)}
.card-body{padding:20px 22px}

/* ── Chart containers ────────────────────────────────────────────────────── */
.chart-wrap{position:relative;height:240px}

/* ── Table ───────────────────────────────────────────────────────────────── */
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
thead tr{background:linear-gradient(90deg,var(--g700) 0%,var(--g600) 100%)}
thead th{padding:11px 14px;text-align:left;font-size:.78rem;font-weight:600;color:#fff;letter-spacing:.4px;white-space:nowrap}
tbody tr{border-bottom:1px solid #f0f4f1;transition:background var(--transition)}
tbody tr:hover{background:#f5fbf5}
tbody td{padding:11px 14px;font-size:.85rem;vertical-align:middle}
.empty-row td{text-align:center;color:#8a9a8b;padding:36px 14px;font-size:.88rem}
.empty-row i{opacity:.4;margin-right:6px}

/* ── Badges ──────────────────────────────────────────────────────────────── */
.badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap}
.badge-returned{background:var(--g100);color:var(--g800)}
.badge-home{background:var(--orange-bg);color:var(--orange)}
.badge-hospital{background:var(--red-bg);color:var(--red)}
.badge-admitted{background:var(--blue-bg);color:var(--blue)}

/* ── Skeleton ────────────────────────────────────────────────────────────── */
.skel{background:linear-gradient(90deg,#eee 25%,#f5f5f5 50%,#eee 75%);background-size:200% 100%;animation:shimmer 1.4s infinite;border-radius:4px;display:inline-block}
@keyframes shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}

/* ── Low stock items ─────────────────────────────────────────────────────── */
.stock-item{display:flex;align-items:center;gap:12px;padding:11px 0;border-bottom:1px solid #f0f4f1}
.stock-item:last-child{border-bottom:none;padding-bottom:0}
.stock-item-icon{width:36px;height:36px;border-radius:8px;background:var(--g100);color:var(--g700);display:flex;align-items:center;justify-content:center;font-size:.95rem;flex-shrink:0}
.stock-item-info{flex:1;min-width:0}
.stock-item-name{font-size:.85rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.stock-meta{font-size:.75rem;color:var(--gray);margin-top:2px}
.stock-bar-wrap{height:5px;background:#e0e0e0;border-radius:3px;margin-top:6px;overflow:hidden}
.stock-bar-fill{height:100%;border-radius:3px;background:var(--red);transition:width .5s ease}

/* ── Quick actions ───────────────────────────────────────────────────────── */
.qa-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.qa-btn{
  display:flex;flex-direction:column;align-items:center;justify-content:center;
  gap:8px;padding:16px 10px;border-radius:var(--radius);
  background:var(--g100);color:var(--g800);font-size:.8rem;font-weight:700;
  text-align:center;transition:all var(--transition);border:1.5px solid transparent
}
.qa-btn:hover{background:var(--g700);color:#fff;transform:translateY(-2px);box-shadow:0 4px 12px rgba(46,125,50,.3)}
.qa-btn i{font-size:1.4rem}

/* ── Notification toast ──────────────────────────────────────────────────── */
#notif-stack{position:fixed;top:20px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:8px;pointer-events:none}
.notif{
  display:flex;align-items:flex-start;gap:10px;padding:12px 16px;
  border-radius:var(--radius);box-shadow:var(--shadow-lg);min-width:240px;max-width:340px;
  pointer-events:all;background:#fff;border-left:4px solid var(--g600);
  animation:slideIn .25s ease
}
@keyframes slideIn{from{transform:translateX(30px);opacity:0}to{transform:none;opacity:1}}
.notif.error{border-left-color:var(--red)}
.notif.warning{border-left-color:var(--orange)}
.notif-icon{font-size:1rem;margin-top:1px}
.notif.success .notif-icon{color:var(--g600)}
.notif.error .notif-icon{color:var(--red)}
.notif.warning .notif-icon{color:var(--orange)}
.notif-body{flex:1;font-size:.82rem}
.notif-title{font-weight:700;margin-bottom:1px}
.notif-msg{color:var(--gray)}
.notif-close{background:none;border:none;cursor:pointer;color:#aaa;font-size:.8rem;padding:0;margin-left:4px}

/* ── Responsive ──────────────────────────────────────────────────────────── */
@media(max-width:600px){
  .page{padding:12px 12px 40px}
  .page-header{padding:20px 18px}
  .page-header h1{font-size:1.25rem}
  .stats-row{grid-template-columns:repeat(2,1fr)}
  .qa-grid{grid-template-columns:1fr 1fr}
}
</style>
</head>
<body>
<?php require_once 'nav.php' ?>

<div id="notif-stack"></div>

<div class="page">

  <!-- ── Page Header ──────────────────────────────────────────────────────── -->
  <header class="page-header">
    <div class="page-header-left">
      <h1><i class="fas fa-hospital-user" style="margin-right:10px;opacity:.85"></i>Sick Bay Dashboard</h1>
      <p id="headerDateLabel">Loading date range…</p>
    </div>
    <div class="page-header-right" id="headerPills">
      <!-- Pills injected by JS -->
    </div>
  </header>

  <!-- ── Filters ──────────────────────────────────────────────────────────── -->
  <div class="filters-bar">
    <div class="fgrp">
      <label for="dateRange">Date Range</label>
      <select id="dateRange">
        <option value="today">Today</option>
        <option value="this_week">This Week</option>
        <option value="this_month" selected>This Month</option>
        <option value="last_month">Last Month</option>
        <option value="last_3_months">Last 3 Months</option>
        <option value="custom">Custom…</option>
      </select>
    </div>
    <div class="fgrp" id="customDateGroup" style="display:none;flex-direction:row;gap:8px;align-items:flex-end">
      <div class="fgrp">
        <label for="customStart">From</label>
        <input type="date" id="customStart">
      </div>
      <div class="fgrp">
        <label for="customEnd">To</label>
        <input type="date" id="customEnd">
      </div>
    </div>
    <button class="btn btn-pdf" onclick="exportToPdf()" style="margin-left:auto">
      <i class="fas fa-file-pdf"></i> Export PDF
    </button>
  </div>

  <!-- ── Stat Cards ───────────────────────────────────────────────────────── -->
  <div class="stats-row" id="statsRow">
    <!-- Skeleton -->
    <div class="stat-card green"><div class="stat-icon green"><i class="fas fa-user-injured"></i></div><div class="stat-info"><div class="stat-num skel" style="width:48px;height:28px"> </div><div class="stat-lbl skel" style="width:80px;height:12px;margin-top:6px"> </div></div></div>
    <div class="stat-card red"><div class="stat-icon red"><i class="fas fa-thermometer-half"></i></div><div class="stat-info"><div class="stat-num skel" style="width:48px;height:28px"> </div><div class="stat-lbl skel" style="width:80px;height:12px;margin-top:6px"> </div></div></div>
    <div class="stat-card orange"><div class="stat-icon orange"><i class="fas fa-pills"></i></div><div class="stat-info"><div class="stat-num skel" style="width:48px;height:28px"> </div><div class="stat-lbl skel" style="width:80px;height:12px;margin-top:6px"> </div></div></div>
    <div class="stat-card blue"><div class="stat-icon blue"><i class="fas fa-hospital"></i></div><div class="stat-info"><div class="stat-num skel" style="width:48px;height:28px"> </div><div class="stat-lbl skel" style="width:80px;height:12px;margin-top:6px"> </div></div></div>
  </div>

  <!-- ── Main Grid ─────────────────────────────────────────────────────────── -->
  <div class="main-grid">

    <!-- Left column -->
    <div>

      <!-- Charts row -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px" id="chartsGrid">
        <div class="card">
          <div class="card-head"><h2><i class="fas fa-chart-line"></i> Visits Over Time</h2></div>
          <div class="card-body"><div class="chart-wrap"><canvas id="visitsChart"></canvas></div></div>
        </div>
        <div class="card">
          <div class="card-head"><h2><i class="fas fa-chart-bar"></i> Top Complaints</h2></div>
          <div class="card-body"><div class="chart-wrap"><canvas id="complaintChart"></canvas></div></div>
        </div>
      </div>
      <style>@media(max-width:760px){#chartsGrid{grid-template-columns:1fr}}</style>

      <!-- Recent visits table -->
      <div class="card">
        <div class="card-head">
          <h2><i class="fas fa-clipboard-list"></i> Recent Visits</h2>
          <a href="visit_reports.php" class="view-all">View all <i class="fas fa-arrow-right" style="font-size:.7rem"></i></a>
        </div>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Student</th><th>Class</th><th>Complaint</th><th>Date &amp; Time</th><th>Outcome</th>
              </tr>
            </thead>
            <tbody id="recentVisitsTbody">
              <tr><td colspan="5" style="text-align:center;padding:30px;color:#8a9a8b">
                <span class="skel" style="width:60%;height:14px;display:inline-block"> </span>
              </td></tr>
            </tbody>
          </table>
        </div>
      </div>

    </div><!-- /left col -->

    <!-- Right column -->
    <div>

      <!-- Quick Actions -->
      <div class="card" style="margin-bottom:20px">
        <div class="card-head"><h2><i class="fas fa-bolt"></i> Quick Actions</h2></div>
        <div class="card-body">
          <div class="qa-grid">
            <a href="Add_visit.php" class="qa-btn"><i class="fas fa-plus-circle"></i><span>New Visit</span></a>
            <a href="withdraw_medicine.php" class="qa-btn"><i class="fas fa-pills"></i><span>Dispense</span></a>
            <a href="inventory.php" class="qa-btn"><i class="fas fa-boxes"></i><span>Inventory</span></a>
            <a href="dispensed_reports.php" class="qa-btn"><i class="fas fa-file-medical"></i><span>Reports</span></a>
          </div>
        </div>
      </div>

      <!-- Low stock -->
      <div class="card">
        <div class="card-head">
          <h2><i class="fas fa-exclamation-triangle"></i> Low Stock</h2>
          <a href="inventory.php" class="view-all">Manage <i class="fas fa-arrow-right" style="font-size:.7rem"></i></a>
        </div>
        <div class="card-body" id="lowStockBody">
          <div style="text-align:center;padding:20px;color:#8a9a8b">
            <span class="skel" style="width:80%;height:14px;display:inline-block;margin-bottom:8px"> </span>
            <span class="skel" style="width:65%;height:14px;display:inline-block"> </span>
          </div>
        </div>
      </div>

    </div><!-- /right col -->

  </div><!-- /main-grid -->

</div><!-- /page -->

<script>
document.addEventListener('DOMContentLoaded', () => {
  // ── DOM refs ──────────────────────────────────────────────────────────────
  const dateRangeEl      = document.getElementById('dateRange');
  const customDateGroup  = document.getElementById('customDateGroup');
  const customStart      = document.getElementById('customStart');
  const customEnd        = document.getElementById('customEnd');
  const statsRow         = document.getElementById('statsRow');
  const headerPills      = document.getElementById('headerPills');
  const headerDateLabel  = document.getElementById('headerDateLabel');
  const recentTbody      = document.getElementById('recentVisitsTbody');
  const lowStockBody     = document.getElementById('lowStockBody');

  let visitsChart    = null;
  let complaintChart = null;

  // Cache last stats for PDF
  let lastStats = null;
  let lastVisits = [];

  // ── Chart.js shared defaults ───────────────────────────────────────────
  Chart.defaults.font.family = "'Sen', system-ui, sans-serif";
  Chart.defaults.color       = '#546e7a';

  const GREEN_PALETTE = [
    '#388e3c','#43a047','#66bb6a','#81c784','#a5d6a7',
    '#2e7d32','#1b5e20','#4caf50','#c8e6c9','#558b2f'
  ];

  // ── Date helpers ──────────────────────────────────────────────────────
  function fmt(d){
    return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');
  }

  function getRange(range){
    const now = new Date();
    let s = new Date(), e = new Date(now);
    switch(range){
      case 'today':
        break;
      case 'this_week':
        s.setDate(now.getDate() - now.getDay());
        break;
      case 'this_month':
        s = new Date(now.getFullYear(), now.getMonth(), 1);
        break;
      case 'last_month':
        s = new Date(now.getFullYear(), now.getMonth()-1, 1);
        e = new Date(now.getFullYear(), now.getMonth(), 0);
        break;
      case 'last_3_months':
        s = new Date(now.getFullYear(), now.getMonth()-3, now.getDate());
        break;
      case 'custom':
        if(customStart.value && customEnd.value){
          s = new Date(customStart.value);
          e = new Date(customEnd.value);
        }
        break;
    }
    return { start: fmt(s), end: fmt(e) };
  }

  function prettyRange(start, end){
    const opts = {day:'numeric',month:'short',year:'numeric'};
    const s = new Date(start+'T00:00:00').toLocaleDateString('en-UG', opts);
    const ed = new Date(end+'T00:00:00').toLocaleDateString('en-UG', opts);
    return s === ed ? s : `${s} — ${ed}`;
  }

  // FIX: Format visit_datetime (was visit_time which was undefined)
  function fmtDatetime(dt){
    if(!dt) return '—';
    try{
      const d = new Date(dt);
      return d.toLocaleDateString('en-UG',{day:'2-digit',month:'short',year:'numeric'}) +
             ' ' + d.toLocaleTimeString('en-UG',{hour:'2-digit',minute:'2-digit'});
    } catch(_){ return dt; }
  }

  // ── Fetch & render ────────────────────────────────────────────────────
  async function load(){
    const { start, end } = getRange(dateRangeEl.value);
    headerDateLabel.textContent = prettyRange(start, end);

    const qs = new URLSearchParams({ start_date: start, end_date: end });

    try {
      const res  = await fetch(`api/get_dashboard_data.php?${qs}`);
      const data = await res.json();

      if(!data.success){
        notify('Error', data.message || 'Failed to load data.', 'error');
        return;
      }

      lastStats  = data.stats;
      lastVisits = data.recent_visits;

      renderStatCards(data.stats);
      renderHeaderPills(data.stats);
      renderVisitsChart(data.visits_over_time);
      renderComplaintChart(data.complaints);
      renderRecentVisits(data.recent_visits);
      renderLowStock(data.low_stock);

    } catch(err){
      notify('Network Error', err.message, 'error');
    }
  }

  // ── Stat Cards ────────────────────────────────────────────────────────
  function renderStatCards(s){
    const cards = [
      {cls:'green',icon:'fa-user-injured',num:s.total_visits||0,     lbl:'Total Visits'},
      {cls:'red',  icon:'fa-thermometer-half',num:s.fever_cases||0,  lbl:'Fever Cases'},
      {cls:'orange',icon:'fa-pills',num:s.low_stock_count||0,        lbl:'Low Stock Items'},
      {cls:'blue', icon:'fa-hospital',num:s.hospital_referrals||0,   lbl:'Hospital Referrals'},
    ];
    statsRow.innerHTML = cards.map(c=>`
      <div class="stat-card ${c.cls}">
        <div class="stat-icon ${c.cls}"><i class="fas ${c.icon}"></i></div>
        <div class="stat-info">
          <div class="stat-num">${c.num}</div>
          <div class="stat-lbl">${c.lbl}</div>
        </div>
      </div>
    `).join('');
  }

  function renderHeaderPills(s){
    headerPills.innerHTML = `
      <div class="stat-pill"><span class="n">${s.total_visits||0}</span><span class="l">Visits</span></div>
      <div class="stat-pill"><span class="n">${s.fever_cases||0}</span><span class="l">Fever</span></div>
      <div class="stat-pill"><span class="n">${s.low_stock_count||0}</span><span class="l">Low Stock</span></div>
    `;
  }

  // ── Visits Over Time Chart ────────────────────────────────────────────
  function renderVisitsChart(data){
    if(visitsChart) visitsChart.destroy();
    const ctx = document.getElementById('visitsChart').getContext('2d');
    const labels = data.map(r=>r.date);
    const counts = data.map(r=>parseInt(r.count));

    const gradient = ctx.createLinearGradient(0,0,0,220);
    gradient.addColorStop(0,'rgba(56,142,60,.35)');
    gradient.addColorStop(1,'rgba(56,142,60,.02)');

    visitsChart = new Chart(ctx,{
      type:'line',
      data:{
        labels,
        datasets:[{
          label:'Visits',
          data:counts,
          borderColor:'#388e3c',
          backgroundColor:gradient,
          borderWidth:2.5,
          tension:.4,
          fill:true,
          pointBackgroundColor:'#fff',
          pointBorderColor:'#388e3c',
          pointBorderWidth:2,
          pointRadius:4,
          pointHoverRadius:6,
        }]
      },
      options:{
        responsive:true,maintainAspectRatio:false,
        plugins:{legend:{display:false},tooltip:{
          backgroundColor:'#1b5e20',titleColor:'#fff',bodyColor:'rgba(255,255,255,.85)',
          padding:10,cornerRadius:8,
          callbacks:{label:ctx=>`${ctx.parsed.y} visit${ctx.parsed.y!==1?'s':''}`}
        }},
        scales:{
          x:{grid:{display:false},ticks:{font:{size:10},maxRotation:30}},
          y:{beginAtZero:true,grid:{color:'#f0f0f0'},
             ticks:{stepSize:1,font:{size:10}}}
        }
      }
    });
  }

  // ── Complaints Chart ──────────────────────────────────────────────────
  function renderComplaintChart(data){
    if(complaintChart) complaintChart.destroy();
    const ctx = document.getElementById('complaintChart').getContext('2d');
    const labels = data.map(r=>r.complaint.length>18?r.complaint.substring(0,16)+'…':r.complaint);
    const counts = data.map(r=>parseInt(r.count));
    const colors = counts.map((_,i)=>GREEN_PALETTE[i%GREEN_PALETTE.length]);

    complaintChart = new Chart(ctx,{
      type:'bar',
      data:{labels,datasets:[{label:'Cases',data:counts,backgroundColor:colors,borderRadius:6,borderSkipped:false}]},
      options:{
        responsive:true,maintainAspectRatio:false,
        plugins:{legend:{display:false},tooltip:{
          backgroundColor:'#1b5e20',titleColor:'#fff',bodyColor:'rgba(255,255,255,.85)',
          padding:10,cornerRadius:8
        }},
        scales:{
          x:{grid:{display:false},ticks:{font:{size:10},maxRotation:40}},
          y:{beginAtZero:true,grid:{color:'#f0f0f0'},ticks:{stepSize:1,font:{size:10}}}
        }
      }
    });
  }

  // ── Recent Visits ─────────────────────────────────────────────────────
  function renderRecentVisits(visits){
    if(!visits.length){
      recentTbody.innerHTML=`<tr class="empty-row"><td colspan="5"><i class="fas fa-clipboard"></i> No visits in this period</td></tr>`;
      return;
    }

    const actionLabel = {
      ReturnedToClass: ['badge-returned','Returned to Class'],
      SentHome:        ['badge-home',    'Sent Home'],
      ReferredToHospital:['badge-hospital','Referred'],
      Admitted:        ['badge-admitted','Admitted'],
    };

    recentTbody.innerHTML = visits.map(v=>{
      const [badgeCls, label] = actionLabel[v.action_taken] || ['badge-returned', v.action_taken||'—'];
      // FIX: use visit_datetime (visit_time was undefined, causing blank column)
      return `<tr>
        <td><strong>${esc(v.full_name)}</strong></td>
        <td>${esc(v.current_class||'—')}</td>
        <td>${esc(v.chief_complaint||'—')}</td>
        <td style="white-space:nowrap;font-size:.78rem">${fmtDatetime(v.visit_datetime)}</td>
        <td><span class="badge ${badgeCls}">${label}</span></td>
      </tr>`;
    }).join('');
  }

  // ── Low Stock ─────────────────────────────────────────────────────────
  function renderLowStock(items){
    if(!items.length){
      lowStockBody.innerHTML=`<div style="text-align:center;padding:20px;color:#8a9a8b;font-size:.85rem"><i class="fas fa-check-circle" style="color:var(--g600);margin-right:5px"></i>All items are sufficiently stocked.</div>`;
      return;
    }
    lowStockBody.innerHTML = items.map(item=>{
      const pct = Math.min(100, Math.round((item.quantity/Math.max(item.threshold,1))*100));
      return `<div class="stock-item">
        <div class="stock-item-icon"><i class="fas fa-capsules"></i></div>
        <div class="stock-item-info">
          <div class="stock-item-name">${esc(item.item_name)}</div>
          <div class="stock-meta">${item.quantity} ${esc(item.unit||'units')} &nbsp;·&nbsp; Threshold: ${item.threshold}</div>
          <div class="stock-bar-wrap"><div class="stock-bar-fill" style="width:${pct}%"></div></div>
        </div>
      </div>`;
    }).join('');
  }

  // ── Event listeners ───────────────────────────────────────────────────
  dateRangeEl.addEventListener('change', ()=>{
    customDateGroup.style.display = dateRangeEl.value==='custom' ? 'flex' : 'none';
    if(dateRangeEl.value !== 'custom') load();
  });
  customStart.addEventListener('change', ()=>{ if(customStart.value && customEnd.value) load(); });
  customEnd.addEventListener('change',   ()=>{ if(customStart.value && customEnd.value) load(); });

  // ── PDF Export ────────────────────────────────────────────────────────
  window.exportToPdf = function(){
    const { jsPDF } = window.jspdf;
    if(!jsPDF){ notify('Error','PDF library not loaded.','error'); return; }
    const doc = new jsPDF('p','mm','a4');
    doc.setFontSize(18); doc.setTextColor(27,94,32);
    doc.text('Sick Bay Dashboard Report', 14, 20);
    doc.setFontSize(9); doc.setTextColor(100);
    doc.text('Period: '+headerDateLabel.textContent, 14, 28);
    doc.text('Generated: '+new Date().toLocaleDateString('en-UG'), 14, 34);

    if(lastStats){
      doc.setFontSize(11); doc.setTextColor(27,94,32);
      doc.text('Summary', 14, 44);
      const sData = [
        ['Total Visits', lastStats.total_visits||0],
        ['Fever Cases',  lastStats.fever_cases||0],
        ['Hospital Referrals', lastStats.hospital_referrals||0],
        ['Low Stock Items', lastStats.low_stock_count||0],
      ];
      doc.autoTable({
        head:[['Metric','Value']], body:sData, startY:48,
        theme:'grid', headStyles:{fillColor:[46,125,50], fontSize:9},
        bodyStyles:{fontSize:8}, columnStyles:{1:{halign:'center'}}
      });
    }

    if(lastVisits.length){
      const finalY = doc.lastAutoTable?.finalY || 80;
      doc.setFontSize(11); doc.setTextColor(27,94,32);
      doc.text('Recent Visits', 14, finalY+10);
      const rows = lastVisits.map(v=>[v.full_name, v.current_class||'', v.chief_complaint||'', fmtDatetime(v.visit_datetime), v.action_taken||'']);
      doc.autoTable({
        head:[['Student','Class','Complaint','Date & Time','Outcome']],
        body:rows, startY:finalY+14,
        theme:'grid', headStyles:{fillColor:[46,125,50], fontSize:9},
        bodyStyles:{fontSize:8}
      });
    }

    doc.save('sickbay-dashboard-'+fmt(new Date())+'.pdf');
    notify('Exported','PDF downloaded.','success');
  };

  // ── Utilities ─────────────────────────────────────────────────────────
  function esc(v){ return String(v||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

  function notify(title, msg, type='success', dur=4500){
    const icons={success:'fa-circle-check',error:'fa-circle-xmark',warning:'fa-triangle-exclamation'};
    const n = document.createElement('div');
    n.className=`notif ${type}`;
    n.innerHTML=`<i class="fas ${icons[type]||icons.success} notif-icon"></i>
      <div class="notif-body"><div class="notif-title">${esc(title)}</div><div class="notif-msg">${esc(msg)}</div></div>
      <button class="notif-close" onclick="this.closest('.notif').remove()"><i class="fas fa-times"></i></button>`;
    document.getElementById('notif-stack').prepend(n);
    setTimeout(()=>{n.style.opacity='0';n.style.transform='translateX(30px)';n.style.transition='.3s';setTimeout(()=>n.remove(),300);},dur);
  }

  // ── Init ──────────────────────────────────────────────────────────────
  load();
});
</script>
</body>
</html>