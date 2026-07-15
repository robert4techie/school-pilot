<?php
/**
 * student_analysis.php
 * ─────────────────────────────────────────────────────────────────────────────
 * A-Level Individual Student Analysis Module
 * Diagnostic (current term snapshot) + Predictive (forecast) — combined page
 * NCDC CBC New Curriculum — Uganda Ministry of Education
 */
require_once '../auth.php';
require_once '../conn.php';
require_once '../tracking.php';
$tracker->trackAction("A-Level Student Analysis");

$currentYear = date('Y');
$years = range($currentYear - 2, $currentYear + 1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>A-Level Student Analysis | SchoolPilot</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sen:wght@400..800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
<style>
:root {
    --green:        #1b5e20;
    --green-mid:    #2e7d32;
    --green-500:    #388e3c;
    --green-light:  #a5d6a7;
    --green-bg:     #f1f8e9;
    --green-50:     #f9fbe7;
    --amber:        #f57c00;
    --amber-light:  #fff3e0;
    --red:          #c62828;
    --red-light:    #ffebee;
    --blue:         #1565c0;
    --blue-light:   #e3f2fd;
    --purple:       #6a1b9a;
    --purple-light: #f3e5f5;
    --card-bg:      #ffffff;
    --text:         #212121;
    --muted:        #666;
    --border:       #e0e0e0;
    --radius:       12px;
    --shadow:       0 4px 16px rgba(0,0,0,.08);
}
*,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
body {
    font-family:"Sen",sans-serif;
    background:var(--green-bg);
    color:var(--text);
    padding:24px 20px 60px;
    min-height:100vh;
}

/* ── PAGE HEADER ─────────────────────────────────────────────────────────── */
.page-header { text-align:center; margin-bottom:24px; }
.page-header h1 { font-size:2em; color:var(--green); font-weight:800; letter-spacing:-.5px; }
.page-header p  { color:var(--muted); font-size:.95em; margin-top:5px; }
.cbc-badge {
    display:inline-block; background:var(--green); color:#fff;
    font-size:.72em; font-weight:700; padding:3px 12px;
    border-radius:20px; letter-spacing:.05em; margin-top:6px;
}

/* ── FILTER PANEL ────────────────────────────────────────────────────────── */
.filters {
    background:var(--card-bg); padding:24px 28px;
    border-radius:var(--radius); box-shadow:var(--shadow);
    margin-bottom:24px; border-top:4px solid var(--green-mid);
}
.filters-heading {
    font-size:.82em; font-weight:700; letter-spacing:.08em;
    text-transform:uppercase; color:var(--green-mid);
    margin-bottom:16px; display:flex; align-items:center; gap:8px;
}
.filter-row { display:flex; flex-wrap:wrap; gap:16px; align-items:flex-end; }
.filter-group { flex:1; min-width:150px; }
.filter-group label {
    display:block; font-weight:700; font-size:.82em;
    color:var(--green); margin-bottom:5px;
    text-transform:uppercase; letter-spacing:.04em;
}
.filter-group select,
.filter-group input {
    width:100%; padding:10px 12px;
    border:1.5px solid var(--border); border-radius:8px;
    font-family:inherit; font-size:.95em;
    background:#fafafa; transition:border-color .2s,box-shadow .2s; color:var(--text);
}
.filter-group select:focus,
.filter-group input:focus {
    outline:none; border-color:var(--green-mid);
    box-shadow:0 0 0 3px rgba(46,125,50,.15);
}
.student-search-wrap { position:relative; }
.student-search-wrap input { padding-right:36px; }
.search-icon { position:absolute; right:12px; top:50%; transform:translateY(-50%); color:var(--muted); }
.student-dropdown {
    position:absolute; top:calc(100% + 4px); left:0; right:0;
    background:#fff; border:1.5px solid var(--green-mid); border-radius:8px;
    box-shadow:var(--shadow); z-index:999; max-height:260px; overflow-y:auto; display:none;
}
.student-dropdown.open { display:block; }
.student-option {
    padding:10px 14px; cursor:pointer; border-bottom:1px solid var(--border);
    transition:background .15s; font-size:.9em;
}
.student-option:last-child { border-bottom:none; }
.student-option:hover { background:var(--green-bg); }
.student-option .stu-name { font-weight:700; color:var(--green); }
.student-option .stu-meta { font-size:.78em; color:var(--muted); margin-top:2px; }
.selected-student-pill {
    display:inline-flex; align-items:center; gap:8px;
    background:var(--green-mid); color:#fff;
    padding:6px 14px; border-radius:20px;
    font-size:.88em; font-weight:700; margin-top:8px;
}
.selected-student-pill button {
    background:none; border:none; color:#fff; cursor:pointer;
    font-size:1em; opacity:.8; padding:0;
}
.selected-student-pill button:hover { opacity:1; }

/* ── BUTTONS ─────────────────────────────────────────────────────────────── */
.btn-row { display:flex; gap:10px; flex-wrap:wrap; margin-top:16px; }
.btn {
    display:inline-flex; align-items:center; gap:8px;
    padding:11px 24px; border:none; border-radius:8px;
    font-family:inherit; font-size:.93em; font-weight:700;
    cursor:pointer; transition:all .22s;
}
.btn-primary {
    background:linear-gradient(135deg,var(--green-mid),var(--green-500));
    color:#fff; box-shadow:0 4px 14px rgba(46,125,50,.28);
}
.btn-primary:hover { transform:translateY(-1px); box-shadow:0 6px 18px rgba(46,125,50,.38); }
.btn-outline {
    background:#fff; color:var(--green-mid);
    border:2px solid var(--green-mid);
}
.btn-outline:hover { background:var(--green-bg); }

/* ── STATUS ──────────────────────────────────────────────────────────────── */
.loading-state {
    text-align:center; padding:60px 20px;
    color:var(--green-mid); font-size:1.05em;
}
.error-state {
    background:var(--red-light); color:var(--red);
    padding:16px 20px; border-radius:8px;
    border-left:4px solid var(--red); margin-bottom:20px; font-weight:600;
}

/* ── STUDENT PROFILE BANNER ──────────────────────────────────────────────── */
.profile-banner {
    background:linear-gradient(135deg,var(--green) 0%,var(--green-mid) 100%);
    color:#fff; border-radius:var(--radius); padding:20px 28px;
    margin-bottom:20px; box-shadow:var(--shadow);
    display:flex; align-items:center; gap:20px; flex-wrap:wrap;
}
.profile-photo {
    width:70px; height:70px; border-radius:50%;
    border:3px solid rgba(255,255,255,.4);
    object-fit:cover; background:rgba(255,255,255,.2);
    display:flex; align-items:center; justify-content:center;
    font-size:1.8em; flex-shrink:0;
}
.profile-photo img { width:100%; height:100%; object-fit:cover; border-radius:50%; }
.profile-info { flex:1; min-width:200px; }
.profile-name { font-size:1.4em; font-weight:800; line-height:1.2; }
.profile-meta { font-size:.85em; opacity:.85; margin-top:4px; line-height:1.8; }
.profile-cbc-strip {
    display:flex; gap:10px; flex-wrap:wrap; align-items:center;
    margin-top:10px;
}
.profile-cbc-pill {
    background:rgba(255,255,255,.18); border:1px solid rgba(255,255,255,.3);
    padding:3px 12px; border-radius:20px; font-size:.8em; font-weight:700;
}

/* ── TABS ────────────────────────────────────────────────────────────────── */
.tabs {
    display:flex; gap:4px; margin-bottom:20px;
    background:var(--card-bg); padding:6px;
    border-radius:10px; box-shadow:var(--shadow-sm);
    width:fit-content;
}
.tab-btn {
    padding:10px 24px; border:none; border-radius:8px;
    font-family:inherit; font-weight:700; font-size:.92em;
    cursor:pointer; transition:all .2s; color:var(--muted);
    background:transparent;
}
.tab-btn.active {
    background:var(--green-mid); color:#fff;
    box-shadow:0 2px 8px rgba(46,125,50,.3);
}
.tab-btn:not(.active):hover { background:var(--green-bg); color:var(--green); }
.tab-pane { display:none; }
.tab-pane.active { display:block; }

/* ── HEALTH CARDS ────────────────────────────────────────────────────────── */
.health-grid {
    display:grid; grid-template-columns:repeat(auto-fit,minmax(185px,1fr));
    gap:14px; margin-bottom:22px;
}
.health-card {
    background:var(--card-bg); border-radius:var(--radius);
    padding:18px 16px 14px; box-shadow:var(--shadow);
    border-top:4px solid var(--green-light); position:relative;
    overflow:hidden; transition:box-shadow .2s;
}
.health-card:hover { box-shadow:0 8px 24px rgba(0,0,0,.12); }
.health-card.c-green { border-top-color:#43a047; }
.health-card.c-amber { border-top-color:var(--amber); }
.health-card.c-red   { border-top-color:var(--red); }
.health-card.c-blue  { border-top-color:var(--blue); }
.health-card.c-purple{ border-top-color:var(--purple); }
.hc-icon { position:absolute; top:12px; right:12px; font-size:1.6em; opacity:.1; }
.hc-label { font-size:.72em; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--muted); margin-bottom:5px; }
.hc-value { font-size:2em; font-weight:800; color:var(--green); line-height:1.1; }
.c-amber .hc-value { color:var(--amber); }
.c-red   .hc-value { color:var(--red); }
.c-blue  .hc-value { color:var(--blue); }
.c-purple .hc-value { color:var(--purple); }
.hc-sub  { font-size:.78em; color:var(--muted); margin-top:4px; line-height:1.4; }
.hc-badge {
    display:inline-block; margin-top:7px; font-size:.7em;
    font-weight:700; padding:2px 9px; border-radius:20px;
}
.bg-green  { background:#e8f5e9; color:#2e7d32; }
.bg-amber  { background:#fff3e0; color:#e65100; }
.bg-red    { background:#ffebee; color:var(--red); }
.bg-blue   { background:#e3f2fd; color:var(--blue); }
.bg-purple { background:#f3e5f5; color:var(--purple); }

/* ── SECTION ─────────────────────────────────────────────────────────────── */
.section {
    background:var(--card-bg); border-radius:var(--radius);
    padding:22px; box-shadow:var(--shadow); margin-bottom:20px;
}
.section-title {
    font-size:1.05em; font-weight:800; color:var(--green);
    margin-bottom:16px; padding-bottom:10px;
    border-bottom:3px solid var(--green-light);
    display:flex; align-items:center; gap:10px; flex-wrap:wrap;
}
.badge-label {
    font-size:.65em; background:var(--green-light); color:var(--green);
    padding:2px 10px; border-radius:20px; font-weight:700;
}

/* ── CHARTS ──────────────────────────────────────────────────────────────── */
.charts-row {
    display:grid; grid-template-columns:repeat(auto-fit,minmax(340px,1fr));
    gap:18px; margin-bottom:20px;
}
.chart-card {
    background:var(--card-bg); border-radius:var(--radius);
    padding:20px; box-shadow:var(--shadow);
}
.chart-title {
    font-size:.9em; font-weight:700; color:var(--green);
    margin-bottom:12px; display:flex; align-items:center; gap:8px;
}
canvas { max-height:280px; }

/* ── SUBJECT TABLE ───────────────────────────────────────────────────────── */
.table-wrap { overflow-x:auto; }
table { width:100%; border-collapse:collapse; font-size:.87em; }
th {
    background:var(--green-mid); color:#fff; padding:9px 11px;
    text-align:center; font-weight:700; font-size:.78em;
    text-transform:uppercase; letter-spacing:.04em; white-space:nowrap;
}
th.left { text-align:left; }
td { padding:9px 11px; text-align:center; border:1px solid var(--border); vertical-align:middle; }
td.left { text-align:left; font-weight:600; }
tbody tr:nth-child(even) { background:#fafafa; }
tbody tr:hover { background:var(--green-bg); transition:background .12s; }
td.g-A { color:#1b5e20; font-weight:800; }
td.g-B { color:#33691e; font-weight:800; }
td.g-C { color:#e65100; font-weight:800; }
td.g-D { color:#bf360c; font-weight:800; }
td.g-E { color:#b71c1c; font-weight:900; }
td.pts-principal   { background:#f1f8e9!important; color:var(--green); font-weight:800; }
td.pts-subsidiary  { background:#f3e5f5!important; color:var(--purple); font-weight:800; }
td.pts-zero        { background:#ffebee!important; color:var(--red); font-weight:900; }
td.pts-total-cell  { background:#c62828!important; color:#fff!important; font-weight:800; font-size:1em; }
td.above-avg { color:#1b5e20; font-weight:700; }
td.below-avg { color:#b71c1c; font-weight:700; }
tr.subsidiary-row td { background:#fdf6ff!important; }
tr.subsidiary-row:hover td { background:#f3e5f5!important; }
.type-badge-prin {
    background:#e8f5e9; color:#1b5e20;
    padding:2px 8px; border-radius:10px; font-size:.72em; font-weight:700;
}
.type-badge-sub {
    background:#f3e5f5; color:var(--purple);
    padding:2px 8px; border-radius:10px; font-size:.72em; font-weight:700;
}

/* ── PREDICTION CARDS ────────────────────────────────────────────────────── */
.pred-grid {
    display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr));
    gap:14px; margin-bottom:20px;
}
.pred-card {
    background:linear-gradient(135deg,var(--green-mid),var(--green-500));
    color:#fff; padding:20px; border-radius:var(--radius);
    text-align:center; box-shadow:0 6px 18px rgba(0,0,0,.15);
}
.pred-card.risk-Critical { background:linear-gradient(135deg,#b71c1c,#d32f2f); }
.pred-card.risk-High     { background:linear-gradient(135deg,#e65100,#f57c00); }
.pred-card.risk-Medium   { background:linear-gradient(135deg,#f57f17,#f9a825); }
.pred-card.risk-Low      { background:linear-gradient(135deg,var(--green-mid),var(--green-500)); }
.pred-val  { font-size:2.2em; font-weight:800; line-height:1.1; margin-bottom:6px; }
.pred-lbl  { font-size:.9em; opacity:.95; }
.pred-sub  { font-size:.78em; opacity:.8; margin-top:4px; }

/* ── RISK METER ──────────────────────────────────────────────────────────── */
.risk-meter { display:flex; align-items:center; gap:14px; margin:16px 0; }
.risk-bar { flex:1; height:28px; background:#e0e0e0; border-radius:14px; overflow:hidden; }
.risk-fill {
    height:100%; transition:width .6s ease;
    display:flex; align-items:center; justify-content:center;
    color:#fff; font-weight:700; font-size:.88em;
}
.risk-fill.Critical { background:var(--red); }
.risk-fill.High     { background:var(--amber); }
.risk-fill.Medium   { background:#f9a825; color:#333; }
.risk-fill.Low      { background:#43a047; }

/* ── RISK FACTOR CARDS ───────────────────────────────────────────────────── */
.risk-factors { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:12px; margin-top:16px; }
.rfc {
    background:#fff; padding:14px; border-radius:8px;
    border-left:4px solid var(--border);
}
.rfc.Critical { border-left-color:var(--red); }
.rfc.High     { border-left-color:var(--amber); }
.rfc.Medium   { border-left-color:#f9a825; }
.rfc.Low      { border-left-color:#43a047; }
.rfc-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:6px; }
.rfc-title  { font-weight:700; color:var(--green); font-size:.9em; }
.rfc-impact { background:var(--border); padding:2px 8px; border-radius:12px; font-size:.78em; font-weight:700; }

/* ── INSIGHTS / ACTIONS ──────────────────────────────────────────────────── */
.insight-list,.action-list { display:flex; flex-direction:column; gap:10px; }
.insight-item {
    background:var(--green-50); border-left:4px solid var(--green-mid);
    padding:12px 16px; border-radius:0 8px 8px 0; font-size:.9em; line-height:1.55;
}
.insight-item.positive { background:#e8f5e9; border-left-color:#43a047; }
.insight-item.negative { background:var(--red-light); border-left-color:var(--red); }
.insight-item.warning  { background:#fff8e1; border-left-color:#f9a825; }
.insight-item.info     { background:var(--blue-light); border-left-color:var(--blue); }
.insight-item h4 { margin:0 0 6px; color:var(--green); font-size:.95em; }
.insight-item p  { margin:0; color:#555; }
.action-item {
    padding:12px 16px; border-radius:0 8px 8px 0; font-size:.9em;
    line-height:1.55; border:1px solid var(--border); border-left-width:4px; background:#fff;
}
.action-item.priority-Critical { border-left-color:var(--red);   background:var(--red-light); }
.action-item.priority-High     { border-left-color:var(--amber); background:var(--amber-light); }
.action-item.priority-Medium   { border-left-color:var(--blue);  background:var(--blue-light); }
.action-item.priority-Low      { border-left-color:#43a047;      background:#e8f5e9; }
.action-item h4    { margin:0 0 6px; font-size:.95em; }
.action-item ul    { margin:8px 0 0 18px; color:#555; line-height:1.8; }

/* ── INTERVENTION PLAN ───────────────────────────────────────────────────── */
.int-phase {
    background:#fff; padding:18px; border-radius:8px;
    margin-bottom:12px; border-left:5px solid var(--green-mid);
}
.int-phase-title  { font-size:1em; font-weight:700; color:var(--green); margin-bottom:6px; }
.int-phase-focus  { color:var(--green-mid); font-weight:600; font-size:.88em; margin-bottom:8px; }
.int-phase ul     { margin:8px 0 0 18px; color:#555; line-height:1.8; font-size:.88em; }

/* ── STRENGTHS / WEAKNESSES ──────────────────────────────────────────────── */
.sw-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
.sw-card { border-radius:8px; padding:16px; }
.sw-card.strengths { background:#e8f5e9; border:1.5px solid #a5d6a7; }
.sw-card.weaknesses{ background:var(--red-light); border:1.5px solid #ef9a9a; }
.sw-card-title { font-weight:800; font-size:.88em; text-transform:uppercase; letter-spacing:.05em; margin-bottom:12px; display:flex; align-items:center; gap:6px; }
.strengths .sw-card-title { color:#1b5e20; }
.weaknesses .sw-card-title { color:var(--red); }
.sw-item {
    display:flex; justify-content:space-between; align-items:center;
    padding:7px 10px; background:#fff; border-radius:6px; margin-bottom:6px;
    font-size:.87em;
}
.sw-item:last-child { margin-bottom:0; }
.sw-subject { font-weight:700; color:var(--text); }
.sw-score   { font-weight:800; }

/* ── CBC GRADING STRIP ───────────────────────────────────────────────────── */
.cbc-strip {
    display:flex; flex-wrap:wrap; gap:6px; padding:12px 16px;
    background:var(--green-50); border:1.5px solid var(--green-light);
    border-radius:8px; margin-bottom:16px; align-items:center; font-size:.82em;
}
.cbc-strip-lbl { font-weight:700; color:var(--green); margin-right:4px; }
.gp { display:inline-flex; flex-direction:column; align-items:center; padding:4px 10px; border-radius:7px; font-weight:700; min-width:58px; }
.gp .gl  { font-size:1.2em; line-height:1; }
.gp .gr  { font-size:.68em; opacity:.75; }
.gp .gpt { font-size:.68em; font-weight:800; opacity:.9; }
.gp.gA { background:#e8f5e9; color:#1b5e20; }
.gp.gB { background:#f1f8e9; color:#33691e; }
.gp.gC { background:#fff8e1; color:#e65100; }
.gp.gD { background:#fff3e0; color:#bf360c; }
.gp.gE { background:#ffebee; color:#b71c1c; }

/* ── TERM COMPARISON MATRIX ──────────────────────────────────────────────── */
.tcm-cell-A { background:#e8f5e9; color:#1b5e20; font-weight:800; }
.tcm-cell-B { background:#f1f8e9; color:#33691e; font-weight:700; }
.tcm-cell-C { background:#fff8e1; color:#e65100; font-weight:700; }
.tcm-cell-D { background:#fff3e0; color:#bf360c; font-weight:700; }
.tcm-cell-E { background:#ffebee; color:#b71c1c; font-weight:900; }
.tcm-cell-  { color:var(--muted); font-style:italic; }

/* ── PRINT ───────────────────────────────────────────────────────────────── */
@media print {
    body { background:#fff; padding:0; font-size:10px; }
    .filters,.tabs,.btn-row,.no-print { display:none!important; }
    .section,.chart-card { box-shadow:none; border:1px solid #ccc; page-break-inside:avoid; }
    th,td.pts-total-cell,td.pts-principal,td.pts-subsidiary,td.pts-zero,
    .pred-card,.profile-banner {
        -webkit-print-color-adjust:exact; print-color-adjust:exact;
    }
}
@media (max-width:768px) {
    .charts-row,.sw-grid { grid-template-columns:1fr; }
    .filter-row { flex-direction:column; }
    .health-grid { grid-template-columns:1fr 1fr; }
}
@media (max-width:480px) {
    .health-grid { grid-template-columns:1fr; }
    body { padding:12px 10px 40px; }
}
</style>
</head>
<body>

<!-- PAGE HEADER -->
<div class="page-header">
    <h1><i class="fas fa-user-graduate"></i> A-Level Student Analysis</h1>
    <p>Individual diagnostic report &nbsp;·&nbsp; Performance tracking &nbsp;·&nbsp; Predictive forecasting</p>
    <span class="cbc-badge">NCDC Competence-Based Curriculum — Uganda Ministry of Education</span>
</div>

<!-- FILTER PANEL -->
<div class="filters no-print">
    <div class="filters-heading"><i class="fas fa-search"></i> Select Student</div>
    <div class="filter-row">
        <!-- Student Search -->
        <div class="filter-group" style="flex:2;min-width:220px">
            <label><i class="fas fa-user"></i> Search Student</label>
            <div class="student-search-wrap">
                <input type="text" id="student-search"
                       placeholder="Type name or student ID…"
                       oninput="searchStudents(this.value)"
                       autocomplete="off">
                <i class="fas fa-search search-icon"></i>
                <div class="student-dropdown" id="student-dropdown"></div>
            </div>
            <div id="selected-pill"></div>
        </div>
        <div class="filter-group">
            <label><i class="fas fa-calendar-alt"></i> Term</label>
            <select id="filter-term">
                <option value="1">Term 1</option>
                <option value="2">Term 2</option>
                <option value="3">Term 3</option>
            </select>
        </div>
        <div class="filter-group">
            <label><i class="fas fa-calendar"></i> Year</label>
            <select id="filter-year">
                <?php foreach ($years as $y): ?>
                    <option value="<?= $y ?>" <?= $y == $currentYear ? 'selected' : '' ?>><?= $y ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="btn-row">
        <button class="btn btn-primary" onclick="loadAnalysis()">
            <i class="fas fa-chart-line"></i> Generate Analysis
        </button>
        <button class="btn btn-outline" onclick="window.print()">
            <i class="fas fa-print"></i> Print Report
        </button>
    </div>
</div>

<!-- ERROR / LOADING -->
<div id="error-state"   class="error-state"   style="display:none"></div>
<div id="loading-state" class="loading-state" style="display:none">
    <i class="fas fa-spinner fa-spin"></i>&nbsp; Loading student data…
</div>

<!-- DASHBOARD -->
<div id="dashboard" style="display:none">

    <!-- Student Profile Banner -->
    <div class="profile-banner" id="profile-banner">
        <div class="profile-photo" id="profile-photo-wrap">
            <i class="fas fa-user-circle" style="opacity:.6"></i>
        </div>
        <div class="profile-info">
            <div class="profile-name" id="profile-name"></div>
            <div class="profile-meta" id="profile-meta"></div>
            <div class="profile-cbc-strip" id="profile-cbc-strip"></div>
        </div>
    </div>

    <!-- CBC Grading Reference -->
    <div class="cbc-strip no-print">
        <span class="cbc-strip-lbl">CBC Key — Principal:</span>
        <div class="gp gA"><span class="gl">A</span><span class="gr">80–100%</span><span class="gpt">5 pts</span></div>
        <div class="gp gB"><span class="gl">B</span><span class="gr">70–79%</span><span class="gpt">4 pts</span></div>
        <div class="gp gC"><span class="gl">C</span><span class="gr">60–69%</span><span class="gpt">3 pts</span></div>
        <div class="gp gD"><span class="gl">D</span><span class="gr">50–59%</span><span class="gpt">2 pts</span></div>
        <div class="gp gE"><span class="gl">E</span><span class="gr">0–49%</span><span class="gpt">1 pt</span></div>
        <span style="color:var(--border);margin:0 4px">|</span>
        <span class="cbc-strip-lbl" style="color:var(--purple)">Subsidiary:</span>
        <div class="gp gA"><span class="gl">D–A</span><span class="gr">50–100%</span><span class="gpt">1 pt</span></div>
        <div class="gp gE"><span class="gl">E</span><span class="gr">0–49%</span><span class="gpt">0 pts</span></div>
        <span style="margin-left:8px;font-weight:700;color:var(--green)">Max: <strong>17 pts</strong></span>
    </div>

    <!-- TABS -->
    <div class="tabs no-print">
        <button class="tab-btn active" data-tab="diagnostic" onclick="switchTab('diagnostic',this)">
            <i class="fas fa-stethoscope"></i> Diagnostic Report
        </button>
        <button class="tab-btn" data-tab="predictive" onclick="switchTab('predictive',this)">
            <i class="fas fa-chart-line"></i> Predictive Forecast
        </button>
    </div>

    <!-- ╔═══════════════════════════════════╗
         ║  DIAGNOSTIC TAB                    ║
         ╚═══════════════════════════════════╝ -->
    <div class="tab-pane active" id="tab-diagnostic">

        <!-- Health Cards -->
        <div class="health-grid" id="diag-health-grid"></div>

        <!-- Charts Row 1 -->
        <div class="charts-row">
            <div class="chart-card">
                <div class="chart-title"><i class="fas fa-chart-radar"></i> Subject Performance Radar</div>
                <canvas id="radar-chart"></canvas>
            </div>
            <div class="chart-card">
                <div class="chart-title"><i class="fas fa-chart-bar"></i> Score vs Class Average</div>
                <canvas id="vs-class-chart"></canvas>
            </div>
        </div>

        <!-- Charts Row 2 -->
        <div class="charts-row">
            <div class="chart-card">
                <div class="chart-title"><i class="fas fa-chart-line"></i> Term-by-Term Mean % Trend</div>
                <canvas id="trend-chart"></canvas>
            </div>
            <div class="chart-card">
                <div class="chart-title"><i class="fas fa-star"></i> Points by Subject</div>
                <canvas id="points-chart"></canvas>
            </div>
        </div>

        <!-- Subject Performance Table -->
        <div class="section">
            <div class="section-title">
                <i class="fas fa-table"></i> Subject Performance — Selected Term
                <span class="badge-label" id="term-badge-label"></span>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th class="left">Subject</th>
                            <th>Type</th>
                            <th>Score %</th>
                            <th>Grade</th>
                            <th>Points</th>
                            <th>Achievement Level</th>
                            <th>Class Avg %</th>
                            <th>vs Class</th>
                            <th>vs Last Term</th>
                        </tr>
                    </thead>
                    <tbody id="subject-tbody"></tbody>
                    <tfoot id="subject-tfoot"></tfoot>
                </table>
            </div>
        </div>

        <!-- Term Comparison Matrix -->
        <div class="section" id="tcm-section">
            <div class="section-title">
                <i class="fas fa-th"></i> Term Comparison Matrix
                <span class="badge-label">All terms this year — Score % / Grade</span>
            </div>
            <div class="table-wrap">
                <table id="tcm-table">
                    <thead id="tcm-thead"></thead>
                    <tbody id="tcm-tbody"></tbody>
                </table>
            </div>
        </div>

        <!-- Strengths & Weaknesses -->
        <div class="section">
            <div class="section-title"><i class="fas fa-balance-scale"></i> Strengths &amp; Weaknesses</div>
            <div class="sw-grid">
                <div class="sw-card strengths">
                    <div class="sw-card-title"><i class="fas fa-arrow-up"></i> Top Subjects</div>
                    <div id="sw-strengths"></div>
                </div>
                <div class="sw-card weaknesses">
                    <div class="sw-card-title"><i class="fas fa-arrow-down"></i> Needs Attention</div>
                    <div id="sw-weaknesses"></div>
                </div>
            </div>
            <div id="sw-progress" style="margin-top:14px;display:grid;grid-template-columns:1fr 1fr;gap:14px"></div>
        </div>

        <!-- Class Ranking -->
        <div class="section">
            <div class="section-title"><i class="fas fa-medal"></i> Class Ranking</div>
            <div id="ranking-content"></div>
        </div>

        <!-- Insights -->
        <div class="section">
            <div class="section-title"><i class="fas fa-lightbulb" style="color:var(--amber)"></i> Key Insights</div>
            <div class="insight-list" id="diag-insights"></div>
        </div>

    </div><!-- /#tab-diagnostic -->

    <!-- ╔═══════════════════════════════════╗
         ║  PREDICTIVE TAB                    ║
         ╚═══════════════════════════════════╝ -->
    <div class="tab-pane" id="tab-predictive">

        <div id="pred-no-data" style="display:none;padding:30px;text-align:center;color:var(--muted)">
            <i class="fas fa-info-circle" style="font-size:2em;margin-bottom:10px;display:block"></i>
            At least 2 terms of historical data are required to generate predictions.
            <br>Check back after more terms are recorded.
        </div>

        <div id="pred-content">
            <!-- Prediction Cards -->
            <div class="pred-grid" id="pred-grid"></div>

            <!-- Charts Row -->
            <div class="charts-row">
                <div class="chart-card">
                    <div class="chart-title"><i class="fas fa-chart-line"></i> Performance Forecast &amp; Scenarios</div>
                    <canvas id="forecast-chart"></canvas>
                </div>
                <div class="chart-card">
                    <div class="chart-title"><i class="fas fa-chart-line"></i> Points Trend &amp; Projection</div>
                    <canvas id="points-trend-chart"></canvas>
                </div>
            </div>

            <!-- Risk Assessment -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-exclamation-triangle" style="color:var(--red)"></i> Risk Assessment
                </div>
                <div id="risk-content"></div>
            </div>

            <!-- Historical Analysis Table -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-history"></i> Historical Performance Record
                    <span class="badge-label">All terms tracked — Mean % · Grade · Points</span>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Period</th>
                                <th>Mean %</th>
                                <th>Grade</th>
                                <th>Total Points</th>
                                <th>vs Previous</th>
                                <th>Trend</th>
                            </tr>
                        </thead>
                        <tbody id="hist-tbody"></tbody>
                    </table>
                </div>
            </div>

            <!-- Success Metrics -->
            <div class="section">
                <div class="section-title"><i class="fas fa-bullseye"></i> Success Probability Metrics</div>
                <div id="success-metrics" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px"></div>
            </div>

            <!-- Recommendations -->
            <div class="section">
                <div class="section-title"><i class="fas fa-list-check"></i> Recommendations</div>
                <div class="action-list" id="pred-recommendations"></div>
            </div>

            <!-- Intervention Plan -->
            <div class="section" id="intervention-section" style="display:none">
                <div class="section-title"><i class="fas fa-calendar-check"></i> Structured Intervention Plan</div>
                <div id="intervention-plan"></div>
            </div>
        </div><!-- /#pred-content -->

    </div><!-- /#tab-predictive -->

</div><!-- /#dashboard -->

<script>
'use strict';

// ── State ─────────────────────────────────────────────────────────────────────
let selectedStudentId   = null;
let selectedStudentName = '';
let searchTimer         = null;
let charts              = {};

// ── Tab switching ─────────────────────────────────────────────────────────────
function switchTab(tabId, btn) {
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + tabId).classList.add('active');
    btn.classList.add('active');
}

// ── Student search ────────────────────────────────────────────────────────────
function searchStudents(q) {
    clearTimeout(searchTimer);
    const dd = document.getElementById('student-dropdown');
    if (!q.trim()) { dd.classList.remove('open'); return; }
    searchTimer = setTimeout(async () => {
        try {
            const res  = await fetch(`fetch_alevel_students.php?q=${encodeURIComponent(q)}`);
            const list = await res.json();
            if (!list.length) {
                dd.innerHTML = '<div class="student-option" style="color:var(--muted);font-style:italic">No students found</div>';
            } else {
                dd.innerHTML = list.map(s => `
                    <div class="student-option" onclick="selectStudent('${s.student_id}','${escJs(s.first_name+' '+s.last_name)}')">
                        <div class="stu-name">${esc(s.last_name)}, ${esc(s.first_name)}</div>
                        <div class="stu-meta">${esc(s.student_id)} &nbsp;·&nbsp; ${esc(s.current_class)} ${esc(s.stream||'')}</div>
                    </div>`).join('');
            }
            dd.classList.add('open');
        } catch { dd.classList.remove('open'); }
    }, 300);
}

function selectStudent(id, name) {
    selectedStudentId   = id;
    selectedStudentName = name;
    document.getElementById('student-search').value = '';
    document.getElementById('student-dropdown').classList.remove('open');
    document.getElementById('selected-pill').innerHTML =
        `<div class="selected-student-pill">
            <i class="fas fa-user"></i> ${esc(name)} (${esc(id)})
            <button onclick="clearStudent()" title="Clear"><i class="fas fa-times"></i></button>
        </div>`;
}

function clearStudent() {
    selectedStudentId   = null;
    selectedStudentName = '';
    document.getElementById('selected-pill').innerHTML = '';
    document.getElementById('dashboard').style.display = 'none';
}

document.addEventListener('click', e => {
    if (!e.target.closest('.student-search-wrap'))
        document.getElementById('student-dropdown').classList.remove('open');
});

// ── Main Load ─────────────────────────────────────────────────────────────────
async function loadAnalysis() {
    if (!selectedStudentId) { showError('Please search and select a student first.'); return; }
    const term = document.getElementById('filter-term').value;
    const year = document.getElementById('filter-year').value;
    const params = new URLSearchParams({ student_id: selectedStudentId, term, year });

    document.getElementById('loading-state').style.display  = 'block';
    document.getElementById('dashboard').style.display      = 'none';
    document.getElementById('error-state').style.display    = 'none';

    try {
        const res  = await fetch('fetch_alevel_student_data.php?' + params);
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();
        if (data.error) throw new Error(data.error);
        renderAll(data);
    } catch (e) {
        showError(e.message);
    } finally {
        document.getElementById('loading-state').style.display = 'none';
    }
}

function showError(msg) {
    document.getElementById('loading-state').style.display = 'none';
    document.getElementById('dashboard').style.display     = 'none';
    const el = document.getElementById('error-state');
    el.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + esc(msg);
    el.style.display = 'block';
}

// ── Master render ─────────────────────────────────────────────────────────────
function renderAll(d) {
    renderBanner(d);
    renderDiagnostic(d);
    renderPredictive(d);
    document.getElementById('dashboard').style.display = 'block';
}

// ── Profile Banner ────────────────────────────────────────────────────────────
function renderBanner(d) {
    const s = d.studentInfo;
    document.getElementById('profile-name').textContent =
        s.last_name.toUpperCase() + ' ' + s.first_name;

    document.getElementById('profile-meta').innerHTML =
        `<i class="fas fa-id-card"></i> ${esc(s.student_id)}
         &nbsp;·&nbsp; <i class="fas fa-school"></i> ${esc(s.current_class)}
         ${s.stream ? '&nbsp;·&nbsp; Stream ' + esc(s.stream) : ''}
         ${s.gender ? '&nbsp;·&nbsp; <i class="fas fa-user"></i> ' + esc(s.gender) : ''}
         ${s.subject_combination ? '<br><i class="fas fa-book"></i> ' + esc(s.subject_combination) : ''}`;

    // Photo
    const pw = document.getElementById('profile-photo-wrap');
    if (s.profile_photo) {
        pw.innerHTML = `<img src="../uploads/students/${esc(s.profile_photo)}" alt="Photo"
                             onerror="this.parentNode.innerHTML='<i class=\'fas fa-user-circle\' style=\'opacity:.6;font-size:2.5em\'></i>'">`;
    }

    // CBC summary pills
    const os = d.overallStats;
    document.getElementById('profile-cbc-strip').innerHTML = os ? `
        <span class="profile-cbc-pill">Mean: ${os.mean}%</span>
        <span class="profile-cbc-pill">Grade: ${os.overall_grade}</span>
        <span class="profile-cbc-pill" style="background:rgba(255,255,255,.3);font-weight:800">
            ${os.total_points} / 17 pts
        </span>
        <span class="profile-cbc-pill">Principal: ${os.principal_points}/15</span>
        <span class="profile-cbc-pill">Subsidiary: ${os.subsidiary_points}/2</span>
        <span class="profile-cbc-pill">Rank: ${d.classRanking?.position ?? '–'} of ${d.classRanking?.totalStudents ?? '–'}</span>` : '';
}

// ═════════════════════════════════════════════════════════════════════════════
//  DIAGNOSTIC TAB
// ═════════════════════════════════════════════════════════════════════════════
function renderDiagnostic(d) {
    const term = document.getElementById('filter-term').value;
    const year = document.getElementById('filter-year').value;
    document.getElementById('term-badge-label').textContent = `Term ${term}, ${year}`;

    renderDiagHealth(d);
    renderSubjectTable(d);
    renderRadarChart(d);
    renderVsClassChart(d);
    renderTrendChart(d);
    renderPointsBarChart(d);
    renderTCMatrix(d);
    renderStrengthsWeaknesses(d);
    renderRankingSection(d);
    renderDiagInsights(d);
}

function renderDiagHealth(d) {
    const os     = d.overallStats;
    const rank   = d.classRanking;
    const atRisk = d.subjectPerformance.some(s => !s.is_subsidiary && s.grade === 'E');
    const grid   = document.getElementById('diag-health-grid');
    if (!os) { grid.innerHTML = ''; return; }

    const ptsPct   = Math.round((os.total_points / 17) * 100);
    const ptsSt    = ptsPct >= 70 ? 'green' : ptsPct >= 47 ? 'amber' : 'red';
    const meanSt   = os.mean >= 70 ? 'green' : os.mean >= 50 ? 'amber' : 'red';
    const rankSt   = rank?.percentile >= 60 ? 'green' : rank?.percentile >= 30 ? 'amber' : 'red';

    grid.innerHTML = [
        hCard('Total Points', os.total_points + '/17', 'fa-star', 'purple',
              `Principal ${os.principal_points}/15 · Subsidiary ${os.subsidiary_points}/2`,
              ptsPct + '% of max points'),
        hCard('Mean %', os.mean + '%', 'fa-chart-line', meanSt,
              `Grade ${os.overall_grade} · ${os.achievement}`,
              os.mean >= 80 ? '✔ Exceptional' : os.mean >= 70 ? '✔ Outstanding' : os.mean >= 60 ? 'Satisfactory' : os.mean >= 50 ? 'Basic' : '⚠ Elementary'),
        hCard('Subjects', os.subjectCount, 'fa-book', 'blue',
              `${d.subjectPerformance.filter(s=>!s.is_subsidiary).length} principal · ${d.subjectPerformance.filter(s=>s.is_subsidiary).length} subsidiary`,
              ''),
        hCard('Class Rank', rank?.position ? '#' + rank.position : '–', 'fa-medal', rankSt,
              rank ? `of ${rank.totalStudents} students · ${rank.percentile}th percentile` : 'No ranking data',
              rank?.percentile >= 75 ? '🏆 Top quartile' : rank?.percentile >= 50 ? 'Above median' : 'Below median'),
        hCard('At-Risk', atRisk ? 'YES' : 'No', 'fa-exclamation-triangle', atRisk ? 'red' : 'green',
              atRisk ? 'Grade E in a principal subject' : 'No grade E in principal subjects',
              atRisk ? '⚠ Intervention needed' : '✔ Competent'),
    ].join('');
}

function hCard(label, value, icon, status, sub, badge) {
    return `<div class="health-card c-${status}">
        <i class="fas ${icon} hc-icon"></i>
        <div class="hc-label">${label}</div>
        <div class="hc-value">${value}</div>
        <div class="hc-sub">${sub}</div>
        ${badge ? `<span class="hc-badge bg-${status}">${badge}</span>` : ''}
    </div>`;
}

function renderSubjectTable(d) {
    const subjs = d.subjectPerformance;
    let tbody = '', tfoot = '';
    let totalPts = 0;

    subjs.forEach(s => {
        const isSub  = s.is_subsidiary;
        const ptsMax = isSub ? 1 : 5;
        const ptsClass = s.grade === 'E' && isSub
            ? 'pts-zero'
            : isSub ? 'pts-subsidiary' : 'pts-principal';
        const vsClass = s.difference === null ? '' : s.difference >= 0 ? 'above-avg' : 'below-avg';
        const vsPfx   = s.difference !== null ? (s.difference >= 0 ? '+' : '') : '';
        const trndCls = s.trend === null ? '' : s.trend >= 0 ? 'above-avg' : 'below-avg';
        const trndPfx = s.trend !== null ? (s.trend >= 0 ? '▲ +' : '▼ ') : '';
        totalPts += s.points;

        tbody += `<tr class="${isSub ? 'subsidiary-row' : ''}">
            <td class="left">${esc(s.subject)}</td>
            <td>${isSub
                ? '<span class="type-badge-sub">Subsidiary</span>'
                : '<span class="type-badge-prin">Principal</span>'}</td>
            <td class="score-cell">${s.score !== null ? s.score + '%' : '—'}</td>
            <td class="g-${s.grade}">${s.grade}</td>
            <td class="${ptsClass}">${s.score !== null ? s.points + '/' + ptsMax : '—'}</td>
            <td>${esc(s.achievement)}</td>
            <td>${s.classAverage !== null ? s.classAverage + '%' : '—'}</td>
            <td class="${vsClass}">${s.difference !== null ? vsPfx + s.difference + '%' : '—'}</td>
            <td class="${trndCls}">${s.trend !== null ? trndPfx + Math.abs(s.trend) + '%' : '—'}</td>
        </tr>`;
    });

    const os = d.overallStats;
    if (os) {
        tfoot = `<tr style="font-weight:800;background:var(--green-50)">
            <td class="left" colspan="2">OVERALL</td>
            <td>${os.mean}%</td>
            <td class="g-${os.overall_grade}">${os.overall_grade}</td>
            <td class="pts-total-cell">${os.total_points}/17</td>
            <td>${esc(os.achievement)}</td>
            <td>${os.classOverallAvg !== null ? os.classOverallAvg + '%' : '—'}</td>
            <td class="${(os.mean - (os.classOverallAvg??os.mean)) >= 0 ? 'above-avg':'below-avg'}">
                ${os.classOverallAvg !== null
                    ? (os.mean - os.classOverallAvg >= 0 ? '+' : '') + (os.mean - os.classOverallAvg).toFixed(1) + '%'
                    : '—'}
            </td>
            <td>—</td>
        </tr>`;
    }

    document.getElementById('subject-tbody').innerHTML = tbody;
    document.getElementById('subject-tfoot').innerHTML = tfoot;
}

function renderRadarChart(d) {
    if (charts.radar) charts.radar.destroy();
    const subjs = d.subjectPerformance.filter(s => s.score !== null);
    if (!subjs.length) return;

    charts.radar = new Chart(document.getElementById('radar-chart'), {
        type: 'radar',
        data: {
            labels: subjs.map(s => s.subject.length > 14 ? s.subject.slice(0,14)+'…' : s.subject),
            datasets: [
                {
                    label: 'Student %',
                    data: subjs.map(s => s.score),
                    borderColor: '#2e7d32', backgroundColor: 'rgba(46,125,50,.18)',
                    borderWidth: 2.5, pointRadius: 5, pointBackgroundColor: '#2e7d32',
                },
                {
                    label: 'Class Avg %',
                    data: subjs.map(s => s.classAverage ?? 0),
                    borderColor: '#1565c0', backgroundColor: 'rgba(21,101,192,.08)',
                    borderWidth: 2, pointRadius: 4, pointBackgroundColor: '#1565c0',
                    borderDash: [5, 3],
                }
            ]
        },
        options: {
            responsive: true,
            scales: { r: { beginAtZero: true, max: 100, ticks: { stepSize: 20 } } },
            plugins: { legend: { position: 'bottom' } }
        }
    });
}

function renderVsClassChart(d) {
    if (charts.vsClass) charts.vsClass.destroy();
    const subjs = d.subjectPerformance.filter(s => s.score !== null);
    if (!subjs.length) return;

    charts.vsClass = new Chart(document.getElementById('vs-class-chart'), {
        type: 'bar',
        data: {
            labels: subjs.map(s => s.subject.length > 10 ? s.subject.slice(0,10)+'…' : s.subject),
            datasets: [
                {
                    label: 'Student %',
                    data: subjs.map(s => s.score),
                    backgroundColor: subjs.map(s => s.is_subsidiary ? '#7b1fa2cc' : '#2e7d32cc'),
                    borderColor:     subjs.map(s => s.is_subsidiary ? '#7b1fa2'   : '#1b5e20'),
                    borderWidth: 2, borderRadius: 5,
                },
                {
                    label: 'Class Avg %',
                    data: subjs.map(s => s.classAverage ?? 0),
                    backgroundColor: '#1565c033',
                    borderColor: '#1565c0',
                    borderWidth: 2, borderRadius: 5,
                    type: 'line',
                    tension: .3, pointRadius: 5, pointBackgroundColor: '#1565c0',
                }
            ]
        },
        options: {
            responsive: true,
            plugins: { legend: { position: 'bottom' } },
            scales: { y: { beginAtZero: true, max: 100, title: { display: true, text: 'Score %' } } }
        }
    });
}

function renderTrendChart(d) {
    if (charts.trend) charts.trend.destroy();
    const trend = d.performanceTrend;
    if (!trend?.length) return;

    charts.trend = new Chart(document.getElementById('trend-chart'), {
        type: 'line',
        data: {
            labels: trend.map(t => t.label),
            datasets: [{
                label: 'Mean %',
                data: trend.map(t => t.mean),
                borderColor: '#2e7d32', backgroundColor: 'rgba(46,125,50,.1)',
                borderWidth: 3, pointRadius: 6, pointBackgroundColor: '#2e7d32',
                tension: .35, fill: true,
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
                y: { min: 0, max: 100, title: { display: true, text: 'Mean %' },
                     ticks: { callback: v => v + '%' } }
            }
        }
    });
}

function renderPointsBarChart(d) {
    if (charts.points) charts.points.destroy();
    const subjs = d.subjectPerformance.filter(s => s.score !== null);
    if (!subjs.length) return;

    charts.points = new Chart(document.getElementById('points-chart'), {
        type: 'bar',
        data: {
            labels: subjs.map(s => s.subject.length > 10 ? s.subject.slice(0,10)+'…' : s.subject),
            datasets: [{
                label: 'Points',
                data: subjs.map(s => s.points),
                backgroundColor: subjs.map(s => s.is_subsidiary ? '#7b1fa2cc' : '#2e7d32cc'),
                borderColor:     subjs.map(s => s.is_subsidiary ? '#7b1fa2'   : '#1b5e20'),
                borderWidth: 2, borderRadius: 6,
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: ctx => {
                    const s = subjs[ctx.dataIndex];
                    const max = s.is_subsidiary ? 1 : 5;
                    return ` ${ctx.raw}/${max} pts — ${s.is_subsidiary ? 'Subsidiary' : 'Principal'}`;
                }}}
            },
            scales: { y: { beginAtZero: true, max: 5, ticks: { stepSize: 1 }, title: { display: true, text: 'Points' } } }
        }
    });
}

function renderTCMatrix(d) {
    const hist = d.termHistory;
    if (!hist || Object.keys(hist).length < 2) {
        document.getElementById('tcm-section').style.display = 'none'; return;
    }
    document.getElementById('tcm-section').style.display = 'block';

    const terms   = Object.keys(hist).sort();
    const subjects = [...new Set(terms.flatMap(t => Object.keys(hist[t])))];

    let thead = '<tr><th class="left">Subject</th>';
    terms.forEach(t => thead += `<th>Term ${t}</th>`);
    thead += '</tr>';

    let tbody = '';
    subjects.forEach(subj => {
        tbody += `<tr><td class="left">${esc(subj)}</td>`;
        terms.forEach(t => {
            const rec = hist[t]?.[subj];
            if (!rec) { tbody += `<td class="tcm-cell-" style="color:var(--muted)">—</td>`; return; }
            const sc = rec.score;
            const gr = rec.grade;
            tbody += `<td class="tcm-cell-${gr}" style="font-size:.88em">
                ${sc}%<br><strong>${gr}</strong>
            </td>`;
        });
        tbody += '</tr>';
    });

    document.getElementById('tcm-thead').innerHTML = thead;
    document.getElementById('tcm-tbody').innerHTML = tbody;
}

function renderStrengthsWeaknesses(d) {
    const sw = d.strengthsWeaknesses;
    if (!sw) return;

    function swItem(subj) {
        const isSub = subj.is_subsidiary;
        return `<div class="sw-item">
            <span class="sw-subject">${esc(subj.subject)}
                ${isSub ? '<span class="type-badge-sub" style="font-size:.68em">Sub</span>' : ''}
            </span>
            <span class="sw-score g-${subj.grade}">${subj.score}% (${subj.grade})</span>
        </div>`;
    }

    document.getElementById('sw-strengths').innerHTML =
        (sw.topSubjects??[]).map(swItem).join('') || '<p style="color:var(--muted);font-size:.88em">No data</p>';
    document.getElementById('sw-weaknesses').innerHTML =
        (sw.weakSubjects??[]).map(swItem).join('') || '<p style="color:var(--muted);font-size:.88em">No data</p>';

    // Progress cards
    let progHtml = '';
    if (sw.mostImproved) {
        progHtml += `<div style="background:#e8f5e9;border:1.5px solid #a5d6a7;border-radius:8px;padding:14px">
            <div style="font-weight:800;color:#1b5e20;font-size:.88em;margin-bottom:8px">
                <i class="fas fa-arrow-trend-up"></i> Most Improved
            </div>
            <strong>${esc(sw.mostImproved.subject)}</strong>
            &nbsp;▲ +${sw.mostImproved.improvement}%
            → ${sw.mostImproved.currentScore}% (${sw.mostImproved.grade})
        </div>`;
    }
    if (sw.mostDeclined) {
        progHtml += `<div style="background:var(--red-light);border:1.5px solid #ef9a9a;border-radius:8px;padding:14px">
            <div style="font-weight:800;color:var(--red);font-size:.88em;margin-bottom:8px">
                <i class="fas fa-arrow-trend-down"></i> Most Declined
            </div>
            <strong>${esc(sw.mostDeclined.subject)}</strong>
            &nbsp;▼ -${sw.mostDeclined.decline}%
            → ${sw.mostDeclined.currentScore}% (${sw.mostDeclined.grade})
        </div>`;
    }
    document.getElementById('sw-progress').innerHTML = progHtml;
}

function renderRankingSection(d) {
    const rank = d.classRanking;
    const el   = document.getElementById('ranking-content');
    if (!rank?.position) {
        el.innerHTML = '<p style="color:var(--muted)">Ranking data not available for selected term.</p>';
        return;
    }
    const pct   = rank.percentile;
    const clr   = pct >= 70 ? '#43a047' : pct >= 40 ? 'var(--amber)' : 'var(--red)';
    const medal = rank.position === 1 ? '🥇' : rank.position === 2 ? '🥈' : rank.position === 3 ? '🥉' : '';
    el.innerHTML = `
        <div style="display:flex;align-items:center;gap:24px;flex-wrap:wrap">
            <div style="text-align:center">
                <div style="font-size:3em;font-weight:800;color:${clr}">${medal}${rank.position}</div>
                <div style="color:var(--muted);font-size:.85em">out of ${rank.totalStudents} students</div>
            </div>
            <div style="flex:1;min-width:200px">
                <div style="font-weight:700;margin-bottom:6px;color:var(--green)">Class Percentile</div>
                <div style="background:#e0e0e0;border-radius:10px;height:22px;overflow:hidden">
                    <div style="height:100%;width:${pct}%;background:${clr};border-radius:10px;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:.82em;transition:width .6s">
                        ${pct}th
                    </div>
                </div>
                <div style="margin-top:6px;font-size:.82em;color:var(--muted)">
                    Performing better than ${pct}% of students in stream ${esc(d.studentInfo?.stream||'')}
                </div>
            </div>
        </div>`;
}

function renderDiagInsights(d) {
    const list = document.getElementById('diag-insights');
    if (!d.insights?.length) { list.innerHTML = '<p style="color:var(--muted)">No insights generated.</p>'; return; }
    list.innerHTML = d.insights.map(i =>
        `<div class="insight-item ${i.type ?? ''}">
            <h4>${i.title}</h4>
            <p>${i.description}</p>
        </div>`
    ).join('');
}

// ═════════════════════════════════════════════════════════════════════════════
//  PREDICTIVE TAB
// ═════════════════════════════════════════════════════════════════════════════
function renderPredictive(d) {
    const pred = d.predictive;
    if (!pred || pred.error) {
        document.getElementById('pred-no-data').style.display  = 'block';
        document.getElementById('pred-content').style.display  = 'none';
        return;
    }
    document.getElementById('pred-no-data').style.display  = 'none';
    document.getElementById('pred-content').style.display  = 'block';

    renderPredCards(pred);
    renderForecastChart(pred);
    renderPointsTrendChart(pred);
    renderRiskSection(pred);
    renderHistTable(pred);
    renderSuccessMetrics(pred);
    renderRecommendations(pred);
    renderInterventionPlan(pred);
}

function renderPredCards(p) {
    const pr = p.predictions;
    const rk = p.riskAssessment;
    document.getElementById('pred-grid').innerHTML = `
        <div class="pred-card">
            <div class="pred-val">${pr.ensemble}%</div>
            <div class="pred-lbl">Predicted Next-Term Mean</div>
            <div class="pred-sub">Ensemble of 3 models</div>
        </div>
        <div class="pred-card">
            <div class="pred-val">${pr.expectedGrade}</div>
            <div class="pred-lbl">Expected Grade</div>
            <div class="pred-sub">${pr.expectedPoints} pts · ${pr.expectedAchievement}</div>
        </div>
        <div class="pred-card">
            <div class="pred-val">${pr.expectedPoints}/17</div>
            <div class="pred-lbl">Projected Total Points</div>
            <div class="pred-sub">CBC Points (next term)</div>
        </div>
        <div class="pred-card">
            <div class="pred-val">${pr.trendDirection}</div>
            <div class="pred-lbl">Performance Trend</div>
            <div class="pred-sub">Slope: ${pr.trendSlope}%/term</div>
        </div>
        <div class="pred-card risk-${rk.level}">
            <div class="pred-val">${rk.level}</div>
            <div class="pred-lbl">Risk Level</div>
            <div class="pred-sub">Score: ${rk.score}/100</div>
        </div>
        <div class="pred-card">
            <div class="pred-val">${pr.confidence}%</div>
            <div class="pred-lbl">Model Confidence</div>
            <div class="pred-sub">R² = ${(pr.confidence/100).toFixed(2)}</div>
        </div>`;
}

function renderForecastChart(p) {
    if (charts.forecast) charts.forecast.destroy();
    const cd = p.chartData;
    charts.forecast = new Chart(document.getElementById('forecast-chart'), {
        type: 'line',
        data: {
            labels: cd.labels,
            datasets: [
                { label:'Historical Mean %', data:cd.historical, borderColor:'#2e7d32',
                  backgroundColor:'rgba(46,125,50,.1)', tension:.35, pointRadius:5,
                  pointBackgroundColor:'#2e7d32', fill:true },
                { label:'Predicted (Realistic)', data:cd.predicted, borderColor:'#1565c0',
                  borderDash:[6,3], tension:.35, pointRadius:5, pointBackgroundColor:'#1565c0' },
                { label:'Optimistic', data:cd.optimistic, borderColor:'#43a047',
                  borderDash:[3,2], tension:.35, pointRadius:3, pointBackgroundColor:'#43a047' },
                { label:'Pessimistic', data:cd.pessimistic, borderColor:'#e53935',
                  borderDash:[3,2], tension:.35, pointRadius:3, pointBackgroundColor:'#e53935' },
            ]
        },
        options: {
            responsive: true,
            plugins: { legend:{ position:'bottom' } },
            scales: { y:{ min:0, max:100, title:{ display:true, text:'Mean %' } } }
        }
    });
}

function renderPointsTrendChart(p) {
    if (charts.ptsTrend) charts.ptsTrend.destroy();
    const cd = p.chartData;
    if (!cd.pointsHistorical?.length) return;
    charts.ptsTrend = new Chart(document.getElementById('points-trend-chart'), {
        type: 'line',
        data: {
            labels: cd.labels,
            datasets: [
                { label:'Historical Points', data:cd.pointsHistorical, borderColor:'#c62828',
                  backgroundColor:'rgba(198,40,40,.1)', tension:.35, pointRadius:5,
                  pointBackgroundColor:'#c62828', fill:true },
                { label:'Projected Points', data:cd.pointsPredicted, borderColor:'#f57c00',
                  borderDash:[6,3], tension:.35, pointRadius:5, pointBackgroundColor:'#f57c00' },
            ]
        },
        options: {
            responsive: true,
            plugins: { legend:{ position:'bottom' } },
            scales: { y:{ min:0, max:17, title:{ display:true, text:'Total Points / 17' },
                ticks:{ callback: v => v+' pts', stepSize:1 } } }
        }
    });
}

function renderRiskSection(p) {
    const rk = p.riskAssessment;
    let html = `
        <div class="risk-meter">
            <strong style="width:80px;font-size:.88em">Risk Score:</strong>
            <div class="risk-bar">
                <div class="risk-fill ${rk.level}" style="width:${rk.score}%">${rk.score}/100</div>
            </div>
            <strong style="width:100px;text-align:right;font-size:.88em">${rk.level} Risk</strong>
        </div>
        <p style="color:#555;margin:8px 0;font-size:.9em">${rk.description}</p>`;

    if (rk.factors?.length) {
        html += '<div class="risk-factors">';
        rk.factors.forEach(f => {
            html += `<div class="rfc ${f.severity}">
                <div class="rfc-header">
                    <div class="rfc-title">${esc(f.factor)}</div>
                    <div class="rfc-impact">+${f.impact}</div>
                </div>
                <p style="color:#666;font-size:.85em;margin:4px 0 0">${esc(f.description)}</p>
            </div>`;
        });
        html += '</div>';
    }
    document.getElementById('risk-content').innerHTML = html;
}

function renderHistTable(p) {
    const hist = p.historicalPerformance;
    if (!hist?.length) { document.getElementById('hist-tbody').innerHTML = '<tr><td colspan="6" style="color:var(--muted)">No historical data.</td></tr>'; return; }

    document.getElementById('hist-tbody').innerHTML = hist.map((h, i) => {
        const prev  = hist[i-1];
        const delta = prev ? (h.mean - prev.mean).toFixed(1) : null;
        const ptsDelta = prev ? (h.total_points - prev.total_points) : null;
        const dCls  = delta === null ? '' : parseFloat(delta) >= 0 ? 'above-avg' : 'below-avg';
        const arrow = delta === null ? '—' : parseFloat(delta) >= 0 ? `▲ +${delta}%` : `▼ ${delta}%`;
        const trend = delta === null ? '–' : parseFloat(delta) > 3 ? '📈 Improving' : parseFloat(delta) < -3 ? '📉 Declining' : '➡ Stable';
        return `<tr>
            <td style="font-weight:700">${esc(h.label)}</td>
            <td class="g-${h.grade}">${h.mean}%</td>
            <td class="g-${h.grade}" style="font-weight:800">${h.grade}</td>
            <td style="font-weight:800;color:var(--red)">${h.total_points}/17</td>
            <td class="${dCls}">${arrow}</td>
            <td>${trend}</td>
        </tr>`;
    }).join('');
}

function renderSuccessMetrics(p) {
    const sm = p.successMetrics;
    const el = document.getElementById('success-metrics');
    el.innerHTML = `
        ${metric(sm.passingProbability + '%',    'Competence Probability', 'D or above in principal')}
        ${metric(sm.gradeAProbability  + '%',    'Grade A Probability',    'Achieving 80%+')}
        ${metric(sm.improvementNeeded  + ' pts', 'Points Gap to Next Grade', sm.nextGrade ? 'Target: Grade ' + sm.nextGrade : '')}
        ${metric(sm.termsToImprovement || 'N/A', 'Terms to Next Grade',    'At current trend rate')}
        ${metric(sm.confidenceInterval ? sm.confidenceInterval.lower.toFixed(1)+'% – '+sm.confidenceInterval.upper.toFixed(1)+'%' : '—', '95% Confidence Interval', 'Predicted score range')}`;
}

function metric(value, label, sub) {
    return `<div style="background:#fff;padding:18px;border-radius:8px;text-align:center;border:2px solid var(--border)">
        <div style="font-size:1.7em;font-weight:800;color:var(--green-mid)">${value}</div>
        <div style="font-size:.82em;font-weight:700;color:var(--text);margin-top:4px">${label}</div>
        ${sub ? `<div style="font-size:.75em;color:var(--muted);margin-top:2px">${sub}</div>` : ''}
    </div>`;
}

function renderRecommendations(p) {
    const el = document.getElementById('pred-recommendations');
    if (!p.recommendations?.length) { el.innerHTML = '<p style="color:var(--muted)">No recommendations.</p>'; return; }
    el.innerHTML = p.recommendations.map(r =>
        `<div class="action-item priority-${r.priority}">
            <h4>${esc(r.title)}</h4>
            <p><strong>Category:</strong> ${esc(r.category)}</p>
            <ul>${(r.actions??[]).map(a => `<li>${esc(a)}</li>`).join('')}</ul>
        </div>`
    ).join('');
}

function renderInterventionPlan(p) {
    const plan = p.interventionPlan ?? [];
    const sec  = document.getElementById('intervention-section');
    if (!plan.length) { sec.style.display = 'none'; return; }
    sec.style.display = 'block';
    document.getElementById('intervention-plan').innerHTML = plan.map(ph =>
        `<div class="int-phase">
            <div class="int-phase-title">${esc(ph.phase)}</div>
            <div class="int-phase-focus">Focus: ${esc(ph.focus)}</div>
            <ul>${(ph.activities??[]).map(a => `<li>${esc(a)}</li>`).join('')}</ul>
        </div>`
    ).join('');
}

// ── Utilities ─────────────────────────────────────────────────────────────────
function esc(str) {
    const d = document.createElement('div');
    d.textContent = str ?? '';
    return d.innerHTML;
}
function escJs(str) { return (str ?? '').replace(/'/g, "\\'"); }
</script>
</body>
</html>
