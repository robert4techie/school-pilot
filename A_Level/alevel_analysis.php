<?php
/**
 * alevel_analysis.php
 * ─────────────────────────────────────────────────────────
 * A-Level Results Analysis Dashboard
 * NCDC CBC New Curriculum — Uganda Ministry of Education
 *
 * Grading:
 *   Principal:  A=80–100 (5pts) · B=70–79 (4pts) · C=60–69 (3pts)
 *               D=50–59 (2pts)  · E=0–49  (1pt)
 *   Subsidiary: D–A (50–100) = 1pt · E (0–49) = 0pts
 *   Max Points: 17 (15 principal + 2 subsidiary)
 *
 * Features:
 *  ✔ Health cards: Class Mean %, Avg Points /17, Competence Rate, At-Risk, vs Last Term
 *  ✔ Points-based ranking (ministry's official CBC metric)
 *  ✔ At-risk = any grade E in a principal subject
 *  ✔ Grade distribution chart + Points distribution chart
 *  ✔ Term trend line chart (Mean % over time)
 *  ✔ Stream comparison chart
 *  ✔ Subject performance table: Score %, Grade, Points per subject
 *  ✔ Top performers ranked by Total Points (/17)
 *  ✔ At-risk list: students with any E in principal
 *  ✔ Auto-generated insights & action recommendations (CBC-aware)
 *  ✔ Grading reference strips (dual principal / subsidiary)
 *  ✔ CSV export + Print/PDF
 */
require_once '../auth.php';
require_once '../conn.php';
require_once '../tracking.php';
$tracker->trackAction("A-Level Results Analysis");

// ── Dynamic data for filters ──────────────────────────────────────────────────
$classes_sql = "SELECT DISTINCT current_class FROM students
                WHERE current_class LIKE 'Senior Five%' OR current_class LIKE 'Senior Six%'
                ORDER BY current_class";
$classes_res  = mysqli_query($conn, $classes_sql);
$alevel_classes = [];
if ($classes_res) while ($r = mysqli_fetch_assoc($classes_res)) $alevel_classes[] = $r['current_class'];
if (empty($alevel_classes)) $alevel_classes = ['Senior Five', 'Senior Six'];

$streams_sql = "SELECT DISTINCT stream FROM students WHERE stream IS NOT NULL AND stream != '' ORDER BY stream";
$streams_res  = mysqli_query($conn, $streams_sql);
$all_streams  = ['All'];
if ($streams_res) while ($r = mysqli_fetch_assoc($streams_res)) $all_streams[] = $r['stream'];

$currentYear = date('Y');
$years = range($currentYear - 2, $currentYear + 2); // Past 2 + current + future 2
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>A-Level Results Analysis | SchoolPilot</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sen:wght@400..800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>

    <style>
        /* ══════════════════════════════════════════════════════
           CSS VARIABLES
        ══════════════════════════════════════════════════════ */
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
            --shadow-sm:    0 2px 8px rgba(0,0,0,.06);
        }

        /* ══════════════════════════════════════════════════════
           RESET & BASE
        ══════════════════════════════════════════════════════ */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: "Sen", sans-serif;
            background: var(--green-bg);
            color: var(--text);
            padding: 24px 20px 60px;
            min-height: 100vh;
        }

        /* ══════════════════════════════════════════════════════
           PAGE HEADER
        ══════════════════════════════════════════════════════ */
        .page-header {
            text-align: center;
            margin-bottom: 28px;
        }
        .page-header h1 {
            font-size: 2em;
            color: var(--green);
            font-weight: 800;
            letter-spacing: -.5px;
        }
        .page-header p {
            color: var(--muted);
            font-size: .98em;
            margin-top: 5px;
            line-height: 1.6;
        }
        .cbc-badge {
            display: inline-block;
            background: var(--green);
            color: #fff;
            font-size: .72em;
            font-weight: 700;
            padding: 3px 12px;
            border-radius: 20px;
            letter-spacing: .05em;
            margin-top: 6px;
        }

        /* ══════════════════════════════════════════════════════
           FILTER PANEL
        ══════════════════════════════════════════════════════ */
        .filters {
            background: var(--card-bg);
            padding: 24px 28px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-bottom: 28px;
            border-top: 4px solid var(--green-mid);
        }
        .filters-heading {
            font-size: .82em;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: var(--green-mid);
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .filter-row {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            align-items: flex-end;
            margin-bottom: 16px;
        }
        .filter-group { flex: 1; min-width: 140px; }
        .filter-group label {
            display: block;
            font-weight: 700;
            font-size: .82em;
            color: var(--green);
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: .04em;
        }
        .filter-group select,
        .filter-group input {
            width: 100%;
            padding: 10px 12px;
            border: 1.5px solid var(--border);
            border-radius: 8px;
            font-family: inherit;
            font-size: .95em;
            background: #fafafa;
            transition: border-color .2s, box-shadow .2s;
            color: var(--text);
        }
        .filter-group select:focus,
        .filter-group input:focus {
            outline: none;
            border-color: var(--green-mid);
            box-shadow: 0 0 0 3px rgba(46,125,50,.15);
        }

        /* Exam sets */
        .exam-sets-panel {
            background: var(--green-50);
            border: 1.5px solid var(--green-light);
            border-radius: 8px;
            padding: 14px 18px;
            margin-bottom: 18px;
        }
        .exam-sets-panel .panel-label {
            font-weight: 700;
            font-size: .82em;
            color: var(--green);
            text-transform: uppercase;
            letter-spacing: .04em;
            display: block;
            margin-bottom: 10px;
        }
        .exam-sets-grid { display: flex; flex-wrap: wrap; gap: 10px; }
        .exam-set-chip {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            background: white;
            border: 1.5px solid var(--green-light);
            padding: 7px 14px;
            border-radius: 20px;
            cursor: pointer;
            font-size: .88em;
            font-weight: 600;
            transition: all .2s;
            user-select: none;
        }
        .exam-set-chip:hover { background: var(--green-bg); border-color: var(--green-mid); }
        .exam-set-chip.checked {
            background: var(--green-mid);
            color: white;
            border-color: var(--green-mid);
        }
        .exam-set-chip input[type=checkbox] { accent-color: white; }

        /* Buttons */
        .btn-row { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 4px; }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 11px 24px;
            border: none;
            border-radius: 8px;
            font-family: inherit;
            font-size: .93em;
            font-weight: 700;
            cursor: pointer;
            transition: all .22s;
            letter-spacing: .02em;
        }
        .btn-primary {
            background: linear-gradient(135deg, var(--green-mid), var(--green-500));
            color: white;
            box-shadow: 0 4px 14px rgba(46,125,50,.28);
        }
        .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 6px 18px rgba(46,125,50,.38); }
        .btn-primary:active { transform: translateY(0); }
        .btn-outline {
            background: white;
            color: var(--green-mid);
            border: 2px solid var(--green-mid);
        }
        .btn-outline:hover { background: var(--green-bg); }
        .btn-amber { background: var(--amber); color: white; box-shadow: 0 4px 14px rgba(245,124,0,.25); }
        .btn-amber:hover { filter: brightness(1.08); transform: translateY(-1px); }

        /* ══════════════════════════════════════════════════════
           STATUS STATES
        ══════════════════════════════════════════════════════ */
        .loading-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--green-mid);
            font-size: 1.05em;
        }
        .error-state {
            background: var(--red-light);
            color: var(--red);
            padding: 16px 20px;
            border-radius: 8px;
            border-left: 4px solid var(--red);
            margin-bottom: 20px;
            font-weight: 600;
        }

        /* ══════════════════════════════════════════════════════
           CBC GRADING REFERENCE STRIP
        ══════════════════════════════════════════════════════ */
        .cbc-ref-strip {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            padding: 14px 18px;
            background: var(--green-50);
            border: 1.5px solid var(--green-light);
            border-radius: 10px;
            margin-bottom: 24px;
            align-items: center;
        }
        .cbc-ref-heading {
            font-size: .78em;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: var(--green);
            margin-right: 6px;
        }
        .cbc-divider {
            width: 1px; height: 28px;
            background: var(--border);
            margin: 0 6px;
        }
        .grade-pill {
            display: inline-flex;
            flex-direction: column;
            align-items: center;
            padding: 5px 12px;
            border-radius: 8px;
            font-weight: 700;
            min-width: 68px;
            font-size: .82em;
        }
        .grade-pill .gp-letter { font-size: 1.25em; line-height: 1; }
        .grade-pill .gp-range  { font-size: .72em; opacity: .78; margin-top: 1px; }
        .grade-pill .gp-pts    { font-size: .7em; font-weight: 800; margin-top: 1px; opacity: .9; }
        .grade-pill.gA { background: #e8f5e9; color: #1b5e20; }
        .grade-pill.gB { background: #f1f8e9; color: #33691e; }
        .grade-pill.gC { background: #fff8e1; color: #e65100; }
        .grade-pill.gD { background: #fff3e0; color: #bf360c; }
        .grade-pill.gE { background: #ffebee; color: #b71c1c; }
        .sub-note {
            font-size: .78em;
            color: var(--muted);
            font-style: italic;
            margin-left: 4px;
        }

        /* ══════════════════════════════════════════════════════
           HEALTH CARDS
        ══════════════════════════════════════════════════════ */
        .health-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 28px;
        }
        .health-card {
            background: var(--card-bg);
            border-radius: var(--radius);
            padding: 20px 18px 16px;
            box-shadow: var(--shadow);
            border-top: 4px solid var(--green-light);
            position: relative;
            overflow: hidden;
            transition: box-shadow .2s;
        }
        .health-card:hover { box-shadow: 0 8px 24px rgba(0,0,0,.12); }
        .health-card.status-green { border-top-color: #43a047; }
        .health-card.status-amber { border-top-color: var(--amber); }
        .health-card.status-red   { border-top-color: var(--red); }
        .health-card.status-blue  { border-top-color: var(--blue); }
        .hc-icon {
            position: absolute; top: 14px; right: 14px;
            font-size: 1.7em; opacity: .12;
        }
        .hc-label {
            font-size: .75em; font-weight: 700;
            text-transform: uppercase; letter-spacing: .06em;
            color: var(--muted); margin-bottom: 6px;
        }
        .hc-value {
            font-size: 2.2em; font-weight: 800;
            color: var(--green); line-height: 1.1;
        }
        .health-card.status-amber .hc-value { color: var(--amber); }
        .health-card.status-red   .hc-value { color: var(--red); }
        .health-card.status-blue  .hc-value { color: var(--blue); }
        .hc-sub {
            font-size: .8em; color: var(--muted); margin-top: 5px; line-height: 1.4;
        }
        .hc-badge {
            display: inline-block; margin-top: 8px;
            font-size: .73em; font-weight: 700;
            padding: 3px 10px; border-radius: 20px;
        }
        .badge-green  { background: #e8f5e9; color: #2e7d32; }
        .badge-amber  { background: #fff3e0; color: #e65100; }
        .badge-red    { background: #ffebee; color: var(--red); }
        .badge-blue   { background: #e3f2fd; color: var(--blue); }

        /* ══════════════════════════════════════════════════════
           SECTION CARDS
        ══════════════════════════════════════════════════════ */
        .section {
            background: var(--card-bg);
            border-radius: var(--radius);
            padding: 24px;
            box-shadow: var(--shadow);
            margin-bottom: 24px;
        }
        .section-title {
            font-size: 1.1em;
            font-weight: 800;
            color: var(--green);
            margin-bottom: 18px;
            padding-bottom: 10px;
            border-bottom: 3px solid var(--green-light);
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .section-title .badge-label {
            font-size: .65em;
            background: var(--green-light);
            color: var(--green);
            padding: 2px 10px;
            border-radius: 20px;
            font-weight: 700;
            letter-spacing: .02em;
        }

        /* ══════════════════════════════════════════════════════
           CHARTS
        ══════════════════════════════════════════════════════ */
        .charts-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(360px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }
        .chart-card {
            background: var(--card-bg);
            border-radius: var(--radius);
            padding: 22px;
            box-shadow: var(--shadow);
        }
        .chart-title {
            font-size: .95em;
            font-weight: 700;
            color: var(--green);
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        canvas { max-height: none; }


        /* ══════════════════════════════════════════════════════
           TABLES
        ══════════════════════════════════════════════════════ */
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: .88em; }
        th {
            background: var(--green-mid);
            color: white;
            padding: 10px 11px;
            text-align: center;
            font-weight: 700;
            font-size: .8em;
            text-transform: uppercase;
            letter-spacing: .04em;
            white-space: nowrap;
        }
        th.left { text-align: left; }
        td {
            padding: 9px 11px;
            text-align: center;
            border: 1px solid var(--border);
            vertical-align: middle;
        }
        td.left { text-align: left; font-weight: 600; }
        tbody tr:nth-child(even) { background: #fafafa; }
        tbody tr:hover { background: var(--green-bg); transition: background .12s; }

        /* Subject table column variants */
        th.col-score  { background: #388e3c; }
        th.col-grade  { background: #2e7d32; font-size: .76em; }
        th.col-pts    { background: #1b5e20; font-size: .76em; }
        td.score-cell { font-weight: 700; }
        td.grade-cell { font-weight: 800; font-size: 1.05em; }
        td.pts-cell   { font-weight: 800; font-size: .92em; }

        /* Grade colour coding */
        td.grade-A, .gc-A { color: #1b5e20; }
        td.grade-B, .gc-B { color: #33691e; }
        td.grade-C, .gc-C { color: #e65100; }
        td.grade-D, .gc-D { color: #bf360c; }
        td.grade-E, .gc-E { color: #b71c1c; font-weight: 900; }

        /* Score colour helper (applied inline via JS) */
        .score-A { color: #1b5e20; }
        .score-B { color: #33691e; }
        .score-C { color: #e65100; }
        .score-D { color: #bf360c; }
        .score-E { color: #b71c1c; }

        /* Subject type badges */
        .badge-prin {
            background: #e8f5e9; color: #1b5e20;
            padding: 2px 8px; border-radius: 10px;
            font-size: .73em; font-weight: 700;
            white-space: nowrap;
        }
        .badge-sub {
            background: var(--purple-light); color: var(--purple);
            padding: 2px 8px; border-radius: 10px;
            font-size: .73em; font-weight: 700;
            white-space: nowrap;
        }
        tr.subsidiary-row td { background: #fdf6ff !important; }
        tr.subsidiary-row:hover td { background: #f3e5f5 !important; }

        /* Points cells */
        td.pts-principal   { background: #f1f8e9 !important; color: var(--green); font-weight: 800; }
        td.pts-subsidiary  { background: #fff8e1 !important; color: var(--amber); font-weight: 800; }
        td.pts-total       { background: #c62828 !important; color: #fff !important; font-weight: 800; font-size: .95em; }
        td.pts-zero        { background: #ffebee !important; color: var(--red) !important; font-weight: 800; }

        /* ══════════════════════════════════════════════════════
           STUDENT SEGMENTS
        ══════════════════════════════════════════════════════ */
        .segments-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(340px, 1fr));
            gap: 24px;
            margin-bottom: 24px;
        }
        .student-rank {
            display: inline-flex;
            align-items: center; justify-content: center;
            width: 30px; height: 30px;
            border-radius: 50%;
            background: var(--green-light);
            color: var(--green);
            font-weight: 800; font-size: .82em;
            flex-shrink: 0;
        }

        /* ══════════════════════════════════════════════════════
           INSIGHTS & ACTIONS
        ══════════════════════════════════════════════════════ */
        .insight-list, .action-list {
            display: flex; flex-direction: column; gap: 10px;
        }
        .insight-item {
            background: var(--green-50);
            border-left: 4px solid var(--green-mid);
            padding: 12px 16px;
            border-radius: 0 8px 8px 0;
            font-size: .93em;
            line-height: 1.55;
        }
        .action-item {
            border-left: 4px solid var(--green-mid);
            padding: 12px 16px;
            border-radius: 0 8px 8px 0;
            font-size: .93em;
            line-height: 1.55;
            background: white;
            border: 1px solid var(--border);
            border-left-width: 4px;
        }
        .action-item.priority-urgent { border-left-color: var(--red);   background: var(--red-light); }
        .action-item.priority-high   { border-left-color: var(--amber); background: var(--amber-light); }
        .action-item.priority-medium { border-left-color: var(--blue);  background: var(--blue-light); }
        .action-item.priority-low    { border-left-color: var(--green-mid); background: var(--green-50); }

        /* ══════════════════════════════════════════════════════
           COMPARISON TABS
        ══════════════════════════════════════════════════════ */
        .tab-buttons {
            display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap;
        }
        .tab-btn {
            padding: 8px 18px;
            border: 2px solid var(--green-mid);
            border-radius: 20px;
            background: white;
            color: var(--green-mid);
            font-family: inherit;
            font-weight: 700;
            font-size: .86em;
            cursor: pointer;
            transition: all .2s;
        }
        .tab-btn.active, .tab-btn:hover {
            background: var(--green-mid); color: white;
        }

        /* ══════════════════════════════════════════════════════
           DASHBOARD TITLE BAR
        ══════════════════════════════════════════════════════ */
        #dash-title {
            background: linear-gradient(135deg, var(--green) 0%, var(--green-mid) 100%);
            color: white;
            border-radius: var(--radius);
            padding: 16px 24px;
            margin-bottom: 24px;
            box-shadow: var(--shadow);
        }
        #dash-title-text {
            font-size: 1em;
            font-weight: 700;
            letter-spacing: .01em;
            line-height: 1.5;
        }

        /* ══════════════════════════════════════════════════════
           EMPTY / NO DATA
        ══════════════════════════════════════════════════════ */
        .no-data-row td {
            color: var(--muted);
            font-style: italic;
            padding: 20px;
        }

        /* ══════════════════════════════════════════════════════
           FULL CLASS RESULTS GRID CONTROLS
        ══════════════════════════════════════════════════════ */
        .grid-controls {
            background: var(--green-50);
            border: 1.5px solid var(--green-light);
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 16px;
        }
        .grid-controls-inner {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            align-items: flex-end;
        }
        .gc-group { display: flex; flex-direction: column; gap: 4px; }
        .gc-label {
            font-size: .75em;
            font-weight: 700;
            color: var(--green);
            text-transform: uppercase;
            letter-spacing: .04em;
        }
        .gc-group select {
            padding: 8px 10px;
            border: 1.5px solid var(--border);
            border-radius: 7px;
            font-family: inherit;
            font-size: .88em;
            background: #fff;
            color: var(--text);
            cursor: pointer;
            min-width: 160px;
        }
        .gc-group select:focus { outline: none; border-color: var(--green-mid); }

        /* Grid table specifics */
        #full-grid-table th.col-subj { background: #1b5e20; font-size: .72em; min-width: 80px; }
        #full-grid-table th.col-subj-sub { background: #4a148c; font-size: .72em; min-width: 80px; }
        #full-grid-table td.col-total-pts { background: #c62828 !important; color: #fff !important; font-weight: 800; }
        #full-grid-table td.col-rank { background: #e8eaf6 !important; color: #283593; font-weight: 800; }
        #full-grid-table tr.row-at-risk { background: #fff5f5 !important; }
        #full-grid-table tr.row-at-risk:hover { background: #ffebee !important; }
        #full-grid-table td.cell-E { color: #b71c1c; font-weight: 900; }
        #full-grid-table td.cell-A { color: #1b5e20; font-weight: 800; }
        #full-grid-table td.cell-B { color: #33691e; font-weight: 700; }
        #full-grid-table td.cell-C { color: #e65100; font-weight: 700; }
        #full-grid-table td.cell-D { color: #bf360c; font-weight: 700; }

        /* ══════════════════════════════════════════════════════
           COMBINATION & GENDER LAYOUTS
        ══════════════════════════════════════════════════════ */
            .combo-layout {
            display: flex;
            flex-direction: column;
            gap: 28px;
        }
        .gender-layout {
            display: flex;
            flex-wrap: wrap;
            gap: 24px;
            align-items: flex-start;
        }
        .gender-cards {
            display: flex;
            flex-direction: column;
            gap: 14px;
            flex: 1;
            min-width: 260px;
        }
        .gender-card {
            background: var(--green-50);
            border: 1.5px solid var(--green-light);
            border-radius: 10px;
            padding: 16px 20px;
        }
        .gender-card.card-male   { border-color: #90caf9; background: #e3f2fd; }
        .gender-card.card-female { border-color: #f48fb1; background: #fce4ec; }
        .gender-card-title {
            font-weight: 800;
            font-size: 1em;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .gender-card-title.male   { color: #1565c0; }
        .gender-card-title.female { color: #880e4f; }
        .gender-stat-row {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 8px;
        }
        .gender-stat {
            display: flex;
            flex-direction: column;
        }
        .gender-stat-val {
            font-size: 1.5em;
            font-weight: 800;
            line-height: 1.1;
        }
        .gender-stat-lbl {
            font-size: .72em;
            color: var(--muted);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .04em;
        }
        .gender-grade-bar {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            margin-top: 8px;
        }
        .ggb-pill {
            display: inline-flex;
            flex-direction: column;
            align-items: center;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: .78em;
            font-weight: 700;
            min-width: 44px;
        }

        /* ══════════════════════════════════════════════════════
           PROGRESS TRACKING
        ══════════════════════════════════════════════════════ */
        .progress-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }
        .delta-positive { color: #2e7d32; font-weight: 800; }
        .delta-negative { color: var(--red); font-weight: 800; }
        .delta-neutral  { color: var(--muted); font-weight: 600; }

        /* Combo table */
        td.combo-rank-1 { color: #e65100; font-weight: 800; font-size: 1.1em; }
        td.combo-rank-2 { color: #1565c0; font-weight: 700; }
        td.combo-risk   { color: var(--red); font-weight: 800; }

        @media (max-width: 768px) {
            .progress-grid { grid-template-columns: 1fr; }
            .combo-layout, .gender-layout { flex-direction: column; }
        }
        @media print {
            #section-full-grid .grid-controls { display: none !important; }
            .progress-grid { grid-template-columns: 1fr 1fr; }
        }
        @media print {
            body { background: white; padding: 0; font-size: 11px; }
            .filters, .btn-row, .tab-buttons, .no-print { display: none !important; }
            .section, .chart-card { box-shadow: none; page-break-inside: avoid; border: 1px solid #ccc; }
            .charts-row { grid-template-columns: 1fr 1fr; }
            canvas { max-height: 180px !important; }
            #dash-title { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            th { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            td.pts-total, td.pts-principal, td.pts-subsidiary, td.pts-zero {
                -webkit-print-color-adjust: exact; print-color-adjust: exact;
            }
            tr.subsidiary-row td { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }

        /* ══════════════════════════════════════════════════════
           RESPONSIVE
        ══════════════════════════════════════════════════════ */
        @media (max-width: 768px) {
            .charts-row, .segments-grid { grid-template-columns: 1fr; }
            .filter-row { flex-direction: column; }
            .health-grid { grid-template-columns: 1fr 1fr; }
            .cbc-divider { display: none; }
        }
        @media (max-width: 480px) {
            .health-grid { grid-template-columns: 1fr; }
            body { padding: 12px 10px 40px; }
            .cbc-ref-strip { flex-direction: column; align-items: flex-start; }
        }
    </style>
</head>
<body>

<!-- ══════════════════════════════════════════════════════
     PAGE HEADER
═══════════════════════════════════════════════════════════ -->
<div class="page-header">
    <h1><i class="fas fa-graduation-cap"></i> A-Level Results Analysis</h1>
    <p>Senior Five &amp; Senior Six &nbsp;·&nbsp; Principal &amp; Subsidiary Subjects &nbsp;·&nbsp; CBC Grading System</p>
    <span class="cbc-badge">NCDC Competence-Based Curriculum — Uganda Ministry of Education</span>
</div>

<!-- ══════════════════════════════════════════════════════
     CBC GRADING REFERENCE STRIP (always visible)
═══════════════════════════════════════════════════════════ -->
<div class="cbc-ref-strip no-print">
    <span class="cbc-ref-heading"><i class="fas fa-info-circle"></i> CBC Grading Key:</span>
    <span class="cbc-ref-heading" style="color:var(--green-mid)">Principal</span>
    <div class="grade-pill gA"><span class="gp-letter">A</span><span class="gp-range">80–100%</span><span class="gp-pts">5 pts</span></div>
    <div class="grade-pill gB"><span class="gp-letter">B</span><span class="gp-range">70–79%</span><span class="gp-pts">4 pts</span></div>
    <div class="grade-pill gC"><span class="gp-letter">C</span><span class="gp-range">60–69%</span><span class="gp-pts">3 pts</span></div>
    <div class="grade-pill gD"><span class="gp-letter">D</span><span class="gp-range">50–59%</span><span class="gp-pts">2 pts</span></div>
    <div class="grade-pill gE"><span class="gp-letter">E</span><span class="gp-range">0–49%</span><span class="gp-pts">1 pt</span></div>
    <div class="cbc-divider"></div>
    <span class="cbc-ref-heading" style="color:var(--red)">Subsidiary</span>
    <div class="grade-pill gA"><span class="gp-letter">D–A</span><span class="gp-range">50–100%</span><span class="gp-pts">1 pt</span></div>
    <div class="grade-pill gE"><span class="gp-letter">E</span><span class="gp-range">0–49%</span><span class="gp-pts">0 pts</span></div>
    <div class="cbc-divider"></div>
    <span style="font-size:.8em;font-weight:700;color:var(--green)">Max: <strong>17 pts</strong> &nbsp;(15 principal + 2 subsidiary)</span>
</div>

<!-- ══════════════════════════════════════════════════════
     FILTER PANEL
═══════════════════════════════════════════════════════════ -->
<div class="filters no-print">
    <div class="filters-heading">
        <i class="fas fa-sliders-h"></i> Report Filters
    </div>
    <div class="filter-row">
        <div class="filter-group">
            <label><i class="fas fa-school"></i> Class</label>
            <select id="filter-class" onchange="loadExamSets()">
                <?php foreach ($alevel_classes as $c): ?>
                    <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label><i class="fas fa-layer-group"></i> Stream</label>
            <select id="filter-stream">
                <?php foreach ($all_streams as $s): ?>
                    <option value="<?= htmlspecialchars($s) ?>"><?= htmlspecialchars($s) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label><i class="fas fa-calendar-alt"></i> Term</label>
            <select id="filter-term" onchange="loadExamSets()">
                <option value="1">Term 1</option>
                <option value="2">Term 2</option>
                <option value="3">Term 3</option>
            </select>
        </div>
        <div class="filter-group">
            <label><i class="fas fa-calendar"></i> Year</label>
            <select id="filter-year" onchange="loadExamSets()">
                <?php foreach ($years as $y): ?>
                    <option value="<?= $y ?>" <?= $y == $currentYear ? 'selected' : '' ?>><?= $y ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label><i class="fas fa-bullseye"></i> Target Competence %</label>
            <input type="number" id="filter-target" value="80" min="1" max="100" step="1">
        </div>
    </div>

    <div class="exam-sets-panel">
        <span class="panel-label"><i class="fas fa-clipboard-list"></i> Exam Sets — select one or more to include</span>
        <div class="exam-sets-grid" id="exam-sets-grid">
            <span style="color:var(--muted);font-size:.88em;font-style:italic">
                <i class="fas fa-spinner fa-spin"></i> Loading exam sets…
            </span>
        </div>
    </div>

    <div class="btn-row">
        <button class="btn btn-primary" onclick="loadData()">
            <i class="fas fa-chart-bar"></i> Generate Analysis
        </button>
        <button class="btn btn-outline" onclick="window.print()">
            <i class="fas fa-file-pdf"></i> Print / PDF
        </button>
        <button class="btn btn-amber" onclick="exportCSV()">
            <i class="fas fa-file-excel"></i> Export CSV for HODs
        </button>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════
     STATUS STATES
═══════════════════════════════════════════════════════════ -->
<div id="error-state"   class="error-state"   style="display:none"></div>
<div id="loading-state" class="loading-state" style="display:none">
    <i class="fas fa-spinner fa-spin"></i> Loading results data…
</div>

<!-- ══════════════════════════════════════════════════════
     DASHBOARD
═══════════════════════════════════════════════════════════ -->
<div id="dashboard" style="display:none">

    <!-- Title bar -->
    <div id="dash-title">
        <div id="dash-title-text"></div>
    </div>

    <!-- Health Cards -->
    <div class="health-grid" id="health-grid"></div>

    <!-- Charts Row -->
    <div class="charts-row">
        <div class="chart-card">
            <div class="chart-title"><i class="fas fa-chart-bar"></i> Grade Distribution (All Subjects)</div>
            <canvas id="gradeChart"></canvas>
        </div>
        <div class="chart-card">
            <div class="chart-title"><i class="fas fa-trophy"></i> Points Distribution (Students)</div>
            <canvas id="pointsChart"></canvas>
        </div>
    </div>
    <div class="charts-row">
        <div class="chart-card">
            <div class="chart-title"><i class="fas fa-chart-line"></i> Class Mean % — Term Trend</div>
            <canvas id="trendChart"></canvas>
        </div>
        <div class="chart-card">
            <div class="chart-title"><i class="fas fa-chart-line"></i> Avg Points — Term Trend</div>
            <canvas id="pointsTrendChart"></canvas>
        </div>
    </div>

    <!-- Subject Performance Table -->
    <div class="section">
        <div class="section-title">
            <i class="fas fa-table"></i> Subject Performance
            <span class="badge-label">Score % · Grade · Points — per paper &amp; overall</span>
        </div>

        <!-- CBC grading key inside section for print -->
        <div class="cbc-ref-strip" style="margin-bottom:16px;">
            <span class="cbc-ref-heading">Principal:</span>
            <div class="grade-pill gA"><span class="gp-letter">A</span><span class="gp-range">80–100%</span><span class="gp-pts">5pts</span></div>
            <div class="grade-pill gB"><span class="gp-letter">B</span><span class="gp-range">70–79%</span><span class="gp-pts">4pts</span></div>
            <div class="grade-pill gC"><span class="gp-letter">C</span><span class="gp-range">60–69%</span><span class="gp-pts">3pts</span></div>
            <div class="grade-pill gD"><span class="gp-letter">D</span><span class="gp-range">50–59%</span><span class="gp-pts">2pts</span></div>
            <div class="grade-pill gE"><span class="gp-letter">E</span><span class="gp-range">0–49%</span><span class="gp-pts">1pt</span></div>
            <div class="cbc-divider"></div>
            <span class="cbc-ref-heading" style="color:var(--red)">Subsidiary:</span>
            <div class="grade-pill gA"><span class="gp-letter">D–A</span><span class="gp-range">50–100%</span><span class="gp-pts">1pt</span></div>
            <div class="grade-pill gE"><span class="gp-letter">E</span><span class="gp-range">0–49%</span><span class="gp-pts">0pts</span></div>
        </div>

        <div class="table-wrap">
            <table id="subject-table">
                <thead id="subject-thead"></thead>
                <tbody id="subject-tbody"></tbody>
            </table>
        </div>
        <p style="font-size:.78em;color:var(--muted);margin-top:10px;line-height:1.6">
            Each paper shows: <strong>Score %</strong> · <strong>Grade</strong> · <strong>Points</strong> separately.
            Subsidiary rows shown in lavender. At-risk = any <strong>E in a principal subject</strong>.
            Competence = Grade D or above for principal (D=2pts minimum).
        </p>
    </div>

    <!-- Comparison Tools -->
    <div class="section">
        <div class="section-title"><i class="fas fa-balance-scale"></i> Comparison Tools</div>
        <div class="tab-buttons no-print">
            <button class="tab-btn active" data-comp="streams">
                <i class="fas fa-layer-group"></i> vs Other Streams
            </button>
            <button class="tab-btn" data-comp="trend">
                <i class="fas fa-chart-line"></i> vs Past Terms (Mean %)
            </button>
        </div>
        <canvas id="comparisonChart" style="max-height:220px"></canvas>
    </div>

    <!-- Student Segments -->
    <div class="segments-grid">
        <!-- Top Performers -->
        <div class="section">
            <div class="section-title">
                <i class="fas fa-star" style="color:#f9a825"></i> Top 10 Performers
                <span class="badge-label">Ranked by Total Points / 17</span>
            </div>
            <div class="table-wrap">
                <table id="top-table">
                    <thead>
                        <tr>
                            <th style="width:36px">#</th>
                            <th class="left">Name</th>
                            <th>Stream</th>
                            <th>Total Pts</th>
                            <th>Mean %</th>
                            <th>Grade</th>
                        </tr>
                    </thead>
                    <tbody id="top-tbody"></tbody>
                </table>
            </div>
        </div>
        <!-- At Risk -->
        <div class="section">
            <div class="section-title" style="color:var(--red)">
                <i class="fas fa-exclamation-triangle"></i> Needs Intervention
                <span class="badge-label" style="background:var(--red-light);color:var(--red)">Grade E in any Principal</span>
            </div>
            <div class="table-wrap">
                <table id="risk-table">
                    <thead>
                        <tr>
                            <th class="left">Name</th>
                            <th>Stream</th>
                            <th>Total Pts</th>
                            <th>Mean %</th>
                            <th>Subjects with E</th>
                        </tr>
                    </thead>
                    <tbody id="risk-tbody"></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════
         FULL CLASS RESULTS GRID
    ═══════════════════════════════════════════════════════════ -->
    <div class="section" id="section-full-grid">
        <div class="section-title">
            <i class="fas fa-th"></i> Full Class Results Grid
            <span class="badge-label">Every student · Every subject · Points · Total</span>
            <span id="grid-student-count" style="margin-left:auto;font-size:.78em;color:var(--muted);font-weight:600"></span>
        </div>

        <!-- Sort & filter controls -->
        <div class="grid-controls no-print">
            <div class="grid-controls-inner">
                <div class="gc-group">
                    <label class="gc-label">Sort by</label>
                    <select id="grid-sort" onchange="sortFullGrid()">
                        <option value="name">Name (A–Z)</option>
                        <option value="points-desc" selected>Total Points (High→Low)</option>
                        <option value="points-asc">Total Points (Low→High)</option>
                        <option value="mean-desc">Mean % (High→Low)</option>
                        <option value="stream">Stream</option>
                    </select>
                </div>
                <div class="gc-group">
                    <label class="gc-label">Filter stream</label>
                    <select id="grid-stream-filter" onchange="filterFullGrid()">
                        <option value="all">All Streams</option>
                    </select>
                </div>
                <div class="gc-group">
                    <label class="gc-label">Show</label>
                    <select id="grid-show-filter" onchange="filterFullGrid()">
                        <option value="all">All Students</option>
                        <option value="at-risk">At-Risk Only (E in principal)</option>
                        <option value="top">Top 20 by Points</option>
                    </select>
                </div>
                <div class="gc-group" style="align-self:flex-end">
                    <input type="text" id="grid-search"
                           placeholder="🔍  Search student name…"
                           oninput="filterFullGrid()"
                           style="padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;font-family:inherit;font-size:.88em;width:200px;">
                </div>
            </div>
        </div>

        <div class="table-wrap" id="grid-wrap">
            <table id="full-grid-table">
                <thead id="full-grid-thead"></thead>
                <tbody id="full-grid-tbody"></tbody>
            </table>
        </div>
        <p id="full-grid-footer" style="font-size:.76em;color:var(--muted);margin-top:10px;line-height:1.6"></p>
    </div>

    <!-- ══════════════════════════════════════════════════════
         SUBJECT COMBINATION ANALYSIS
    ═══════════════════════════════════════════════════════════ -->
    <div class="section" id="section-combinations">
        <div class="section-title">
            <i class="fas fa-object-group"></i> Subject Combination Analysis
            <span class="badge-label">Mean % · Competence rate · At-risk per combination</span>
        </div>
        <div class="combo-layout">
            <div class="combo-table-wrap table-wrap">
                <table id="combo-table">
                    <thead>
                        <tr>
                            <th class="left">Combination</th>
                            <th>Students</th>
                            <th>Mean %</th>
                            <th>Grade</th>
                            <th>Competence Rate</th>
                            <th>Full Pass Rate</th>
                            <th>At-Risk</th>
                            <th>Ranking</th>
                        </tr>
                    </thead>
                    <tbody id="combo-tbody"></tbody>
                </table>
            </div>
            <div class="combo-chart-wrap">
                    <div class="chart-title" style="margin-bottom:14px">
                        <i class="fas fa-chart-bar"></i> Mean % by Combination
                    </div>
                   <div style="position:relative;height:520px;width:100%">
                        <canvas id="comboChart"></canvas>
                    </div>
                </div>
            </div>
    </div>

    <!-- ══════════════════════════════════════════════════════
         GENDER BREAKDOWN
    ═══════════════════════════════════════════════════════════ -->
    <div class="section" id="section-gender">
        <div class="section-title">
            <i class="fas fa-venus-mars"></i> Gender Breakdown
            <span class="badge-label">Mean % · Competence rate · Grade distribution</span>
        </div>
        <div class="gender-layout">
            <div id="gender-cards" class="gender-cards"></div>
            <div style="flex:1;min-width:280px">
                <div class="chart-title" style="margin-bottom:10px">
                    <i class="fas fa-chart-bar"></i> Grade Distribution by Gender
                </div>
                <canvas id="genderChart" style="max-height:260px"></canvas>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════
         PROGRESS TRACKING — Most Improved / Most Declined
    ═══════════════════════════════════════════════════════════ -->
    <div class="section" id="section-progress">
        <div class="section-title">
            <i class="fas fa-arrows-alt-v"></i> Progress Tracking vs Last Term
            <span class="badge-label">Students with biggest positive or negative change in mean %</span>
        </div>
        <div class="progress-grid">
            <!-- Most Improved -->
            <div>
                <div style="font-weight:800;color:var(--green);font-size:.92em;margin-bottom:10px;display:flex;align-items:center;gap:8px">
                    <i class="fas fa-arrow-trend-up" style="color:#43a047"></i> Most Improved
                </div>
                <table id="improved-table">
                    <thead>
                        <tr>
                            <th class="left">Student</th>
                            <th>Stream</th>
                            <th>Prev %</th>
                            <th>Now %</th>
                            <th>Change</th>
                            <th>Total Pts</th>
                        </tr>
                    </thead>
                    <tbody id="improved-tbody"></tbody>
                </table>
            </div>
            <!-- Most Declined -->
            <div>
                <div style="font-weight:800;color:var(--red);font-size:.92em;margin-bottom:10px;display:flex;align-items:center;gap:8px">
                    <i class="fas fa-arrow-trend-down" style="color:var(--red)"></i> Most Declined
                </div>
                <table id="declined-table">
                    <thead>
                        <tr>
                            <th class="left">Student</th>
                            <th>Stream</th>
                            <th>Prev %</th>
                            <th>Now %</th>
                            <th>Change</th>
                            <th>Total Pts</th>
                        </tr>
                    </thead>
                    <tbody id="declined-tbody"></tbody>
                </table>
            </div>
        </div>
        <p id="progress-no-data" style="color:var(--muted);font-size:.88em;font-style:italic;display:none;margin-top:8px">
            <i class="fas fa-info-circle"></i>
            No previous-term data available — progress tracking requires marks from the preceding term.
        </p>
    </div>
    <div class="section">
        <div class="section-title"><i class="fas fa-lightbulb" style="color:var(--amber)"></i> Key Insights</div>
        <div class="insight-list" id="insights-list"></div>
    </div>

    <!-- Action Recommendations -->
    <div class="section">
        <div class="section-title"><i class="fas fa-tasks"></i> Action Recommendations</div>
        <div class="action-list" id="actions-list"></div>
    </div>

</div><!-- /#dashboard -->

<!-- ══════════════════════════════════════════════════════
     JAVASCRIPT
═══════════════════════════════════════════════════════════ -->
<script>
'use strict';

// ── State ─────────────────────────────────────────────────────────────────────
let currentData    = null;
let gradeChart     = null;
let pointsChart    = null;
let trendChart     = null;
let ptsTrendChart  = null;
let compChart      = null;
let activeCompTab  = 'streams';

// ── Constants — CBC grading ───────────────────────────────────────────────────
const PAPER_ORDER  = ['I','II','III','IV','V'];

const GRADE_COLORS = {
    A: '#43a047',
    B: '#7cb342',
    C: '#f9a825',
    D: '#fb8c00',
    E: '#e53935'
};

/** Score → CSS class for colour coding */
function scoreClass(score) {
    if (score === null || score === undefined || score === '–') return '';
    const s = parseFloat(score);
    if (isNaN(s)) return '';
    if (s >= 80) return 'score-A';
    if (s >= 70) return 'score-B';
    if (s >= 60) return 'score-C';
    if (s >= 50) return 'score-D';
    return 'score-E';
}

/** Principal points: A=5, B=4, C=3, D=2, E=1 */
function principalPoints(grade) {
    return { A:5, B:4, C:3, D:2, E:1 }[grade] ?? 0;
}

/** Subsidiary points: D–A=1, E=0 */
function subsidiaryPoints(grade) {
    return ['A','B','C','D'].includes(grade) ? 1 : 0;
}

// ── Exam Sets Loader ──────────────────────────────────────────────────────────
async function loadExamSets() {
    const cls  = document.getElementById('filter-class').value;
    const term = document.getElementById('filter-term').value;
    const year = document.getElementById('filter-year').value;
    const grid = document.getElementById('exam-sets-grid');

    grid.innerHTML = '<span style="color:var(--muted);font-size:.88em;font-style:italic">'
                   + '<i class="fas fa-spinner fa-spin"></i> Loading…</span>';

    try {
        const res  = await fetch(`get_alevel_exam_sets.php?class=${encodeURIComponent(cls)}&term=${term}&year=${year}`);
        const sets = await res.json();

        if (!sets || sets.error || sets.length === 0) {
            grid.innerHTML = '<span style="color:var(--muted);font-size:.88em">No exam sets found for this selection.</span>';
            return;
        }

        grid.innerHTML = '';
        sets.forEach(s => {
            const label = document.createElement('label');
            label.className = 'exam-set-chip';
            label.innerHTML = `<input type="checkbox" value="${s.id}" checked> ${escHtml(s.label ?? s.exam_set ?? 'Set '+s.id)}`;
            label.querySelector('input').addEventListener('change', function() {
                label.classList.toggle('checked', this.checked);
            });
            label.classList.add('checked');
            grid.appendChild(label);
        });
    } catch (e) {
        grid.innerHTML = '<span style="color:var(--muted);font-size:.88em">Could not load exam sets.</span>';
    }
}

// ── Main Data Loader ──────────────────────────────────────────────────────────
async function loadData() {
    const cls    = document.getElementById('filter-class').value;
    const stream = document.getElementById('filter-stream').value;
    const term   = document.getElementById('filter-term').value;
    const year   = document.getElementById('filter-year').value;
    const target = parseInt(document.getElementById('filter-target').value, 10) || 80;

    const checked  = document.querySelectorAll('#exam-sets-grid input[type=checkbox]:checked');
    const examSets = Array.from(checked).map(cb => cb.value).join(',');

    if (!examSets) {
        showError('Please select at least one exam set.');
        return;
    }

    const params = new URLSearchParams({ class: cls, stream, term, year, target });
    params.append('exam_sets', examSets);

    document.getElementById('loading-state').style.display = 'block';
    document.getElementById('dashboard').style.display     = 'none';
    document.getElementById('error-state').style.display   = 'none';

    try {
        const res  = await fetch('fetch_alevel_analysis.php?' + params.toString());
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const data = await res.json();

        if (data.error) { showError(data.error); return; }

        currentData = data;
        renderDashboard(data);
    } catch (e) {
        showError('Failed to load data — ' + e.message + '. Please check your connection and try again.');
    } finally {
        document.getElementById('loading-state').style.display = 'none';
    }
}

function showError(msg) {
    document.getElementById('loading-state').style.display = 'none';
    document.getElementById('dashboard').style.display     = 'none';
    const el = document.getElementById('error-state');
    el.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${escHtml(msg)}`;
    el.style.display = 'block';
}

// ── Master Render ─────────────────────────────────────────────────────────────
function renderDashboard(d) {
    renderTitle(d);
    renderHealthCards(d);
    renderGradeChart(d);
    renderPointsChart(d);
    renderTrendChart(d);
    renderPointsTrendChart(d);
    renderSubjectTable(d);
    renderStudentSegments(d);
    renderFullGrid(d);
    renderCombinationAnalysis(d);
    renderGenderBreakdown(d);
    renderProgressTracking(d);
    renderInsights(d);
    renderActions(d);
    showComparison(activeCompTab, true);
    document.getElementById('dashboard').style.display = 'block';
}

// ── Dashboard Title ───────────────────────────────────────────────────────────
function renderTitle(d) {
    const f         = d.filters;
    const examLabel = (d.examSets || []).map(e => e.exam_set || e.label || 'Set '+e.id).join(' + ') || 'All Exam Sets';
    document.getElementById('dash-title-text').innerHTML =
        `<i class="fas fa-graduation-cap" style="margin-right:8px;opacity:.8"></i>
         ${escHtml(f.class)} &nbsp;|&nbsp; Stream: ${escHtml(f.stream)}
         &nbsp;|&nbsp; Term ${escHtml(String(f.term))}, ${escHtml(String(f.year))}
         &nbsp;|&nbsp; ${escHtml(examLabel)}
         &nbsp;|&nbsp; <strong>${d.totalStudents}</strong> students`;
}

// ── Health Cards ──────────────────────────────────────────────────────────────
function renderHealthCards(d) {
    const target = d.filters.target;
    const grid   = document.getElementById('health-grid');

    // Mean %
    const meanSt = d.classMean >= target ? 'green' : d.classMean >= target - 10 ? 'amber' : 'red';

    // Avg Points /17
    const maxPts  = 17;
    const avgPts  = d.avgTotalPoints ?? null;
    const ptsPct  = avgPts !== null ? Math.round((avgPts / maxPts) * 100) : null;
    const ptsSt   = ptsPct === null ? 'blue' : ptsPct >= 65 ? 'green' : ptsPct >= 45 ? 'amber' : 'red';

    // Competence rate (grade D or above for principal = competent)
    const compRate = d.competenceRate ?? d.principalPassRate;
    const compSt   = compRate >= target ? 'green' : compRate >= target - 15 ? 'amber' : 'red';

    // At-risk = any E in principal
    const arCount = d.atRiskCount;
    const arSt    = arCount === 0 ? 'green' : arCount <= 3 ? 'amber' : 'red';

    // vs last term
    let trendSt = 'green', trendVal = '–', trendSub = 'No previous term data';
    if (d.vsLastTerm !== null && d.vsLastTerm !== undefined) {
        const v    = d.vsLastTerm;
        trendSt  = v >= 0 ? 'green' : v >= -5 ? 'amber' : 'red';
        trendVal = (v >= 0 ? '+' : '') + v + '%';
        trendSub = v >= 0 ? 'Improvement from last term' : 'Decline from last term';
    }

    grid.innerHTML = [
        hCard('Class Mean',
              d.classMean + '%',
              'fa-chart-line', meanSt,
              `Target: ${target}%`,
              meanSt,
              meanSt === 'green' ? '✔ On target' : '⚠ Below target'),

        hCard('Avg Points / 17',
              avgPts !== null ? avgPts.toFixed(1) : '–',
              'fa-star', ptsSt,
              avgPts !== null ? `${ptsPct}% of max points` : 'No points data',
              ptsSt,
              avgPts !== null ? (ptsSt==='green'?'✔ Strong performance':ptsSt==='amber'?'⚠ Developing':'⚠ Low points') : '—'),

        hCard('Competence Rate',
              compRate + '%',
              'fa-check-circle', compSt,
              `Grade D or above (principal) &nbsp;·&nbsp; ${d.studentsWithMarks ?? d.totalStudents} students`,
              compSt,
              compSt==='green' ? '✔ At/above target' : `⚠ Below ${target}% target`),

        hCard('At-Risk Students',
              arCount,
              'fa-exclamation-triangle', arSt,
              'Any grade E in a principal subject',
              arSt,
              arSt==='green' ? '✔ None at risk' : `${arCount} need intervention`),

        hCard('vs Last Term',
              trendVal,
              'fa-exchange-alt', trendSt,
              trendSub, trendSt,
              trendSt==='green' ? '📈 Improving' : trendSt==='amber' ? '➡ Stable' : '📉 Declining'),

    ].join('');
}

function hCard(label, value, icon, status, sub, badgeType, badgeText) {
    return `<div class="health-card status-${status}">
        <i class="fas ${icon} hc-icon"></i>
        <div class="hc-label">${label}</div>
        <div class="hc-value">${value}</div>
        <div class="hc-sub">${sub}</div>
        <span class="hc-badge badge-${badgeType}">${badgeText}</span>
    </div>`;
}

// ── Grade Distribution Chart ──────────────────────────────────────────────────
function renderGradeChart(d) {
    const labels = Object.keys(d.gradeDistribution);
    const counts = labels.map(g => d.gradeDistribution[g].count);
    const pcts   = labels.map(g => d.gradeDistribution[g].percentage);
    const colors = labels.map(g => GRADE_COLORS[g] || '#999');

    if (gradeChart) gradeChart.destroy();
    gradeChart = new Chart(document.getElementById('gradeChart'), {
        type: 'bar',
        data: {
            labels: labels.map((g,i) => `${g}  (${pcts[i]}%)`),
            datasets: [{
                label: 'Students',
                data: counts,
                backgroundColor: colors.map(c => c + 'cc'),
                borderColor: colors,
                borderWidth: 2,
                borderRadius: 6,
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => ` ${ctx.raw} students (${pcts[ctx.dataIndex]}%)`
                    }
                }
            },
            scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
        }
    });
}

// ── Points Distribution Chart ─────────────────────────────────────────────────
function renderPointsChart(d) {
    if (pointsChart) pointsChart.destroy();

    // Expect d.pointsDistribution: { "0-4": n, "5-8": n, "9-12": n, "13-15": n, "16-17": n }
    const dist = d.pointsDistribution;
    if (!dist) return;

    const labels = Object.keys(dist);
    const counts = labels.map(k => dist[k]);
    const bgColors = ['#e53935cc','#fb8c00cc','#f9a825cc','#7cb342cc','#43a047cc'];
    const bdColors = ['#e53935',  '#fb8c00',  '#f9a825',  '#7cb342',  '#43a047' ];

    pointsChart = new Chart(document.getElementById('pointsChart'), {
        type: 'bar',
        data: {
            labels: labels.map(l => `${l} pts`),
            datasets: [{
                label: 'Students',
                data: counts,
                backgroundColor: bgColors.slice(0, labels.length),
                borderColor: bdColors.slice(0, labels.length),
                borderWidth: 2,
                borderRadius: 6,
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: ctx => ` ${ctx.raw} students` } }
            },
            scales: {
                y: { beginAtZero: true, ticks: { stepSize: 1 } },
                x: { title: { display: true, text: 'Points Band (out of 17)' } }
            }
        }
    });
}

// ── Term Trend — Mean % ───────────────────────────────────────────────────────
function renderTrendChart(d) {
    if (trendChart) trendChart.destroy();
    if (!d.trendData?.labels?.length) return;

    trendChart = new Chart(document.getElementById('trendChart'), {
        type: 'line',
        data: {
            labels: d.trendData.labels,
            datasets: [{
                label: 'Class Mean %',
                data: d.trendData.data,
                borderColor: '#2e7d32',
                backgroundColor: 'rgba(46,125,50,.1)',
                borderWidth: 3,
                pointRadius: 6,
                pointBackgroundColor: '#2e7d32',
                tension: .35,
                fill: true,
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: { y: { min: 0, max: 100, title: { display: true, text: 'Mean %' } } }
        }
    });
}

// ── Term Trend — Avg Points ───────────────────────────────────────────────────
function renderPointsTrendChart(d) {
    if (ptsTrendChart) ptsTrendChart.destroy();
    if (!d.pointsTrendData?.labels?.length) return;

    ptsTrendChart = new Chart(document.getElementById('pointsTrendChart'), {
        type: 'line',
        data: {
            labels: d.pointsTrendData.labels,
            datasets: [{
                label: 'Avg Points',
                data: d.pointsTrendData.data,
                borderColor: '#c62828',
                backgroundColor: 'rgba(198,40,40,.08)',
                borderWidth: 3,
                pointRadius: 6,
                pointBackgroundColor: '#c62828',
                tension: .35,
                fill: true,
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
                y: {
                    min: 0, max: 17,
                    title: { display: true, text: 'Avg Points / 17' },
                    ticks: {
                        callback: v => v + ' pts'
                    }
                }
            }
        }
    });
}

// ── Subject Performance Table ─────────────────────────────────────────────────
function renderSubjectTable(d) {
    const subjects = d.subjectPerformance;
    if (!subjects?.length) return;

    // Collect all unique papers
    const allPapers = [];
    subjects.forEach(s => {
        Object.keys(s.paper_means ?? {}).forEach(p => {
            if (!allPapers.includes(p)) allPapers.push(p);
        });
    });
    allPapers.sort((a,b) => (PAPER_ORDER.indexOf(a)+1||99) - (PAPER_ORDER.indexOf(b)+1||99));

    // ── Header ──
    let thead = '<tr>';
    thead += '<th class="left" rowspan="2">Subject</th>';
    thead += '<th rowspan="2">Type</th>';
    allPapers.forEach(p => {
        thead += `<th colspan="3" class="col-score">Paper ${p}</th>`;
    });
    thead += '<th rowspan="2">Overall %</th>';
    thead += '<th rowspan="2">Grade</th>';
    thead += '<th rowspan="2">Points</th>';
    thead += '<th rowspan="2">Competence %</th>';
    thead += '</tr><tr>';
    allPapers.forEach(() => {
        thead += '<th class="col-score" style="font-size:.73em">Score %</th>';
        thead += '<th class="col-grade" style="font-size:.73em">Grade</th>';
        thead += '<th class="col-pts"   style="font-size:.73em">Pts</th>';
    });
    thead += '</tr>';
    document.getElementById('subject-thead').innerHTML = thead;

    // ── Rows ──
    let tbody = '';
    subjects.forEach(s => {
        const isSub     = s.is_subsidiary;
        const rowClass  = isSub ? 'subsidiary-row' : '';
        const typeBadge = isSub
            ? '<span class="badge-sub">SUBSIDIARY</span>'
            : '<span class="badge-prin">PRINCIPAL</span>';

        tbody += `<tr class="${rowClass}">`;
        tbody += `<td class="left">${escHtml(s.subject)}</td>`;
        tbody += `<td>${typeBadge}</td>`;

        allPapers.forEach(p => {
            const score = s.paper_means?.[p]  !== undefined ? s.paper_means[p]  : null;
            const grade = s.paper_grades?.[p] !== undefined ? s.paper_grades[p] : null;
            const pts   = grade !== null
                ? (isSub ? subsidiaryPoints(grade) : principalPoints(grade))
                : null;

            const sClass = score !== null ? scoreClass(score) : '';
            const gClass = grade !== null ? `grade-${grade}` : '';
            const ptsMax = isSub ? 1 : 5;
            const ptsClass = pts !== null
                ? (isSub ? 'pts-subsidiary' : 'pts-principal')
                : '';
            const ptsZero = pts === 0 && isSub ? 'pts-zero' : '';

            tbody += `<td class="score-cell ${sClass}">${score !== null ? score+'%' : '—'}</td>`;
            tbody += `<td class="grade-cell ${gClass}">${grade ?? '—'}</td>`;
            tbody += `<td class="pts-cell ${pts !== null ? (ptsZero || ptsClass) : ''}">${pts !== null ? pts+'/'+ptsMax : '—'}</td>`;
        });

        // Overall
        const og      = s.overall_mean;
        const ogGrade = s.overall_grade;
        const ogPts   = ogGrade
            ? (isSub ? subsidiaryPoints(ogGrade) : principalPoints(ogGrade))
            : null;
        const ogPtsMax = isSub ? 1 : 5;
        const ogSClass = og !== null && og !== undefined ? scoreClass(og) : '';
        const ogGClass = ogGrade ? `grade-${ogGrade}` : '';
        const ogPtsClass = ogPts !== null
            ? (isSub ? (ogPts === 0 ? 'pts-zero' : 'pts-subsidiary') : 'pts-principal')
            : '';

        tbody += `<td class="score-cell ${ogSClass}" style="font-size:1.03em">${og !== null && og !== undefined ? og+'%' : '—'}</td>`;
        tbody += `<td class="grade-cell ${ogGClass}" style="font-size:1.08em">${ogGrade ?? '—'}</td>`;
        tbody += `<td class="pts-cell ${ogPtsClass}">${ogPts !== null ? ogPts+'/'+ogPtsMax : '—'}</td>`;

        // Competence rate
        const cr = s.pass_rate ?? s.competence_rate;
        const crColor = cr >= 80 ? 'var(--green)' : cr >= 60 ? 'var(--amber)' : 'var(--red)';
        tbody += `<td style="font-weight:700;color:${crColor}">${cr !== null && cr !== undefined ? cr+'%' : '—'}</td>`;

        tbody += '</tr>';
    });
    document.getElementById('subject-tbody').innerHTML = tbody;
}

// ── Student Segments ──────────────────────────────────────────────────────────
function renderStudentSegments(d) {
    // Top performers — ranked by total points
    const top = (d.topStudents ?? []);
    let topHtml = '';
    top.forEach((s, i) => {
        const rankBg = i===0?'#ffd700':i===1?'#c0c0c0':i===2?'#cd7f32':'var(--green-light)';
        const rankColor = i < 3 ? '#333' : 'var(--green)';
        const gradeClass = s.overall_grade ? `gc-${s.overall_grade}` : '';
        topHtml += `<tr>
            <td><span class="student-rank" style="background:${rankBg};color:${rankColor}">${i+1}</span></td>
            <td class="left">${escHtml(s.name)}</td>
            <td>${escHtml(s.stream || '—')}</td>
            <td class="pts-total" style="font-weight:800">${s.total_points ?? '—'}<span style="font-size:.75em;opacity:.8">/17</span></td>
            <td style="font-weight:700">${s.overall_mean ?? '—'}%</td>
            <td class="grade-cell ${gradeClass}">${s.overall_grade ?? '—'}</td>
        </tr>`;
    });
    document.getElementById('top-tbody').innerHTML =
        topHtml || '<tr class="no-data-row"><td colspan="6">No data available.</td></tr>';

    // At-risk — grade E in any principal subject
    const risk = (d.atRiskStudents ?? []);
    let riskHtml = '';
    risk.slice(0, 20).forEach(s => {
        const gradeClass = s.overall_grade ? `gc-${s.overall_grade}` : '';
        riskHtml += `<tr>
            <td class="left">${escHtml(s.name)}</td>
            <td>${escHtml(s.stream || '—')}</td>
            <td style="font-weight:800;color:var(--red)">${s.total_points ?? '—'}<span style="font-size:.75em">/17</span></td>
            <td style="color:var(--red);font-weight:700">${s.overall_mean ?? '—'}%</td>
            <td style="color:var(--red);font-weight:700">${s.principal_e_subjects ?? s.principal_fails ?? '—'}</td>
        </tr>`;
    });
    document.getElementById('risk-tbody').innerHTML =
        riskHtml || '<tr><td colspan="5" style="color:var(--green-mid);font-weight:700;padding:16px">✔ No students with grade E in any principal subject</td></tr>';
}

// ── Insights ──────────────────────────────────────────────────────────────────
function renderInsights(d) {
    const list = document.getElementById('insights-list');
    if (!d.insights?.length) {
        list.innerHTML = '<p style="color:var(--muted)">No insights generated.</p>';
        return;
    }
    list.innerHTML = d.insights
        .map(i => `<div class="insight-item">${i.icon ?? '📊'} ${i.text}</div>`)
        .join('');
}

// ── Actions ───────────────────────────────────────────────────────────────────
function renderActions(d) {
    const list = document.getElementById('actions-list');
    if (!d.actions?.length) {
        list.innerHTML = '<p style="color:var(--muted)">No recommendations generated.</p>';
        return;
    }
    const emoji = { urgent:'🚨', high:'⚠️', medium:'ℹ️', low:'✅' };
    list.innerHTML = d.actions
        .map(a => `<div class="action-item priority-${a.priority ?? 'low'}">
            ${emoji[a.priority] ?? '•'} ${a.text}
        </div>`)
        .join('');
}

// ── Comparison Chart ──────────────────────────────────────────────────────────
function showComparison(type, silent = false) {
    activeCompTab = type;

    if (!silent) {
        document.querySelectorAll('.tab-btn').forEach(b => {
            b.classList.toggle('active', b.dataset.comp === type);
        });
    }

    if (compChart) { compChart.destroy(); compChart = null; }
    if (!currentData) return;

    if (type === 'streams') {
        const rows = currentData.streamComparison;
        if (!rows?.length) return;
        compChart = new Chart(document.getElementById('comparisonChart'), {
            type: 'bar',
            data: {
                labels: rows.map(s => `${s.stream} (${s.students} students)`),
                datasets: [{
                    label: 'Class Mean %',
                    data: rows.map(s => s.mean),
                    backgroundColor: rows.map(s =>
                        s.stream === currentData.filters.stream ? '#2e7d32cc' : '#a5d6a7cc'),
                    borderColor: rows.map(s =>
                        s.stream === currentData.filters.stream ? '#1b5e20' : '#4caf50'),
                    borderWidth: 2,
                    borderRadius: 6,
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, max: 100, title: { display: true, text: 'Mean %' } } }
            }
        });
    } else {
        const td = currentData.trendData;
        if (!td?.labels?.length) return;
        compChart = new Chart(document.getElementById('comparisonChart'), {
            type: 'line',
            data: {
                labels: td.labels,
                datasets: [{
                    label: 'Class Mean %',
                    data: td.data,
                    borderColor: '#1b5e20',
                    backgroundColor: 'rgba(27,94,32,.08)',
                    borderWidth: 3,
                    pointRadius: 6,
                    tension: .3,
                    fill: true,
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: { y: { min: 0, max: 100, title: { display: true, text: 'Mean %' } } }
            }
        });
    }
}

// ── Full Class Results Grid ───────────────────────────────────────────────────
let fullGridData      = [];    // all processed student rows
let fullGridSubjects  = [];    // ordered subject list
let comboChartInst    = null;
let genderChartInst   = null;

function renderFullGrid(d) {
    const students  = d.topStudents ? null : []; // we build from atRiskStudents + topStudents union
    // Backend sends topStudents (top 10) and atRiskStudents — we need ALL students.
    // They are in studentResults via allStudents key if we add it, but currently we
    // reconstruct from subjectPerformance HOD export for a complete per-student view.

    // Collect ordered subjects: principal first then subsidiary
    const principal  = (d.subjectPerformance ?? []).filter(s => !s.is_subsidiary);
    const subsidiary = (d.subjectPerformance ?? []).filter(s =>  s.is_subsidiary);
    fullGridSubjects = [...principal, ...subsidiary];

    if (!fullGridSubjects.length) return;

    // Build student → subjects map from HOD export
    const studentMap = {};
    fullGridSubjects.forEach(subj => {
        (subj.hod_export ?? []).forEach(row => {
            if (!studentMap[row.name]) {
                studentMap[row.name] = { name: row.name, stream: row.stream, subjects: {}, total_points: 0 };
            }
            const pts = subj.is_subsidiary
                ? subsidiaryPoints(row.grade)
                : principalPoints(row.grade);
            studentMap[row.name].subjects[subj.subject] = {
                mean:  row.mean,
                grade: row.grade,
                pts,
                is_sub: subj.is_subsidiary,
            };
            studentMap[row.name].total_points += pts;
        });
    });

    // Merge in overall mean from topStudents + atRiskStudents to get deltas
    const knownStudents = [...(d.topStudents ?? []), ...(d.atRiskStudents ?? []),
                           ...(d.mostImproved ?? []), ...(d.mostDeclined ?? []),
                           ...(d.needsWorkStudents ?? [])];
    const extraLookup = {};
    knownStudents.forEach(s => {
        if (!extraLookup[s.name]) extraLookup[s.name] = s;
    });

    fullGridData = Object.values(studentMap).map(stu => {
        const extra = extraLookup[stu.name] ?? {};
        const marks = Object.values(stu.subjects).map(s => s.mean).filter(m => m != null);
        const overallMean = marks.length ? Math.round(marks.reduce((a,b)=>a+b,0)/marks.length*10)/10 : null;
        return {
            ...stu,
            overall_mean:  overallMean,
            overall_grade: gradeFromMean(overallMean),
            progress_delta: extra.progress_delta ?? null,
            principal_e_subjects: extra.principal_e_subjects ?? null,
            principal_fails: extra.principal_fails ?? 0,
        };
    });

    // Populate stream filter dropdown
    const streams = [...new Set(fullGridData.map(s => s.stream).filter(Boolean))].sort();
    const sf = document.getElementById('grid-stream-filter');
    sf.innerHTML = '<option value="all">All Streams</option>';
    streams.forEach(st => { sf.innerHTML += `<option value="${escHtml(st)}">${escHtml(st)}</option>`; });

    document.getElementById('grid-student-count').textContent = `${fullGridData.length} students`;

    buildFullGridHeader();
    sortFullGrid(); // also calls filterFullGrid → renderFullGridRows
}

function gradeFromMean(m) {
    if (m == null) return '-';
    if (m >= 80) return 'A';
    if (m >= 70) return 'B';
    if (m >= 60) return 'C';
    if (m >= 50) return 'D';
    return 'E';
}

function buildFullGridHeader() {
    const principal  = fullGridSubjects.filter(s => !s.is_subsidiary);
    const subsidiary = fullGridSubjects.filter(s =>  s.is_subsidiary);

    let h = '<tr>';
    h += '<th style="width:28px">#</th>';
    h += '<th class="left" style="min-width:140px">Student</th>';
    h += '<th>Stream</th>';
    if (principal.length)  h += `<th colspan="${principal.length * 2}"  style="background:#2e7d32">PRINCIPAL (Score % / Grade)</th>`;
    if (subsidiary.length) h += `<th colspan="${subsidiary.length * 2}" style="background:#4a148c">SUBSIDIARY (Score % / Grade)</th>`;
    h += '<th style="background:#c62828">Total Pts</th>';
    h += '<th style="background:#1565c0">Mean %</th>';
    h += '<th style="background:#424242">Grade</th>';
    h += '<th style="background:#e65100">Change</th>';
    h += '</tr><tr>';
    h += '<th colspan="3"></th>';
    [...principal, ...subsidiary].forEach(s => {
        const bg = s.is_subsidiary ? 'col-subj-sub' : 'col-subj';
        const abbr = s.subject.length > 12 ? s.subject.substring(0,12)+'…' : s.subject;
        h += `<th class="${bg}" title="${escHtml(s.subject)}">${escHtml(abbr)}<br><small style="font-weight:500;opacity:.8">${s.is_subsidiary?'Sub':'Prin'}</small></th>`;
        h += `<th class="${bg}" style="font-size:.68em">Pts</th>`;
    });
    h += '<th style="background:#c62828"></th>';
    h += '<th style="background:#1565c0"></th>';
    h += '<th style="background:#424242"></th>';
    h += '<th style="background:#e65100"></th>';
    h += '</tr>';

    document.getElementById('full-grid-thead').innerHTML = h;
}

function sortFullGrid() {
    const sort = document.getElementById('grid-sort').value;
    fullGridData.sort((a, b) => {
        switch (sort) {
            case 'name':        return a.name.localeCompare(b.name);
            case 'points-desc': return (b.total_points ?? 0) - (a.total_points ?? 0);
            case 'points-asc':  return (a.total_points ?? 0) - (b.total_points ?? 0);
            case 'mean-desc':   return (b.overall_mean ?? 0) - (a.overall_mean ?? 0);
            case 'stream':      return (a.stream ?? '').localeCompare(b.stream ?? '');
            default:            return 0;
        }
    });
    filterFullGrid();
}

function filterFullGrid() {
    const streamF = document.getElementById('grid-stream-filter').value;
    const showF   = document.getElementById('grid-show-filter').value;
    const search  = (document.getElementById('grid-search').value ?? '').trim().toLowerCase();

    let rows = fullGridData.filter(s => {
        if (streamF !== 'all' && s.stream !== streamF) return false;
        if (showF === 'at-risk' && (s.principal_fails ?? 0) === 0) return false;
        if (search && !s.name.toLowerCase().includes(search)) return false;
        return true;
    });
    if (showF === 'top') rows = rows.slice(0, 20);

    renderFullGridRows(rows);
    document.getElementById('grid-student-count').textContent =
        `${rows.length} of ${fullGridData.length} students`;
}

function renderFullGridRows(rows) {
    const subjects = fullGridSubjects;
    let tbody = '';

    rows.forEach((stu, idx) => {
        const isAtRisk = (stu.principal_fails ?? 0) > 0;
        tbody += `<tr class="${isAtRisk ? 'row-at-risk' : ''}">`;
        tbody += `<td class="col-rank">${idx + 1}</td>`;
        tbody += `<td class="left" style="font-weight:700">${escHtml(stu.name)}`;
        if (isAtRisk) tbody += ` <span style="color:var(--red);font-size:.72em;font-weight:800">⚠ AT-RISK</span>`;
        tbody += '</td>';
        tbody += `<td>${escHtml(stu.stream || '—')}</td>`;

        subjects.forEach(subj => {
            const sd = stu.subjects[subj.subject];
            if (!sd) {
                tbody += '<td style="color:var(--muted)">—</td><td style="color:var(--muted)">—</td>';
            } else {
                const grClass = sd.grade ? `cell-${sd.grade}` : '';
                const ptsMax  = sd.is_sub ? 1 : 5;
                tbody += `<td class="${grClass}" style="font-size:.85em">${sd.mean != null ? sd.mean+'%' : '—'}</td>`;
                tbody += `<td class="${grClass}" style="font-weight:800;font-size:.85em">${sd.pts}/${ptsMax}</td>`;
            }
        });

        // Total pts
        tbody += `<td class="col-total-pts">${stu.total_points}<span style="font-size:.72em;opacity:.8">/17</span></td>`;
        // Mean
        const meanClass = stu.overall_mean != null ? `cell-${gradeFromMean(stu.overall_mean)}` : '';
        tbody += `<td class="${meanClass}" style="font-weight:700">${stu.overall_mean != null ? stu.overall_mean+'%' : '—'}</td>`;
        // Grade
        const gc = stu.overall_grade !== '-' ? `cell-${stu.overall_grade}` : '';
        tbody += `<td class="${gc}" style="font-weight:800;font-size:1.05em">${stu.overall_grade}</td>`;
        // Delta
        const delta = stu.progress_delta;
        if (delta == null) {
            tbody += '<td class="delta-neutral">—</td>';
        } else {
            const cls = delta > 0 ? 'delta-positive' : delta < 0 ? 'delta-negative' : 'delta-neutral';
            const pfx = delta > 0 ? '+' : '';
            tbody += `<td class="${cls}">${pfx}${delta}%</td>`;
        }
        tbody += '</tr>';
    });

    document.getElementById('full-grid-tbody').innerHTML =
        tbody || '<tr><td colspan="50" style="color:var(--muted);font-style:italic;padding:20px">No students match the current filters.</td></tr>';

    document.getElementById('full-grid-footer').textContent =
        `Showing ${rows.length} student(s). Score = class average across all exam sets. ` +
        `At-risk rows (red) = grade E in any principal subject. ` +
        `Change = current mean % minus previous term mean %.`;
}

// ── Combination Analysis ──────────────────────────────────────────────────────
function renderCombinationAnalysis(d) {
    const combos = d.combinationAnalysis ?? [];
    if (!combos.length) {
        document.getElementById('section-combinations').style.display = 'none';
        return;
    }

    // Sort by mean desc
    const sorted = [...combos].sort((a, b) => (b.mean ?? 0) - (a.mean ?? 0));

    let tbody = '';
    sorted.forEach((c, i) => {
        const crColor = c.competence_rate >= 80 ? 'var(--green)' : c.competence_rate >= 60 ? 'var(--amber)' : 'var(--red)';
        const rankCls = i === 0 ? 'combo-rank-1' : i === 1 ? 'combo-rank-2' : '';
        const riskCls = c.at_risk_count > 0 ? 'combo-risk' : '';
        const grCls   = c.grade ? `gc-${c.grade}` : '';

        tbody += `<tr>
            <td class="left" style="font-weight:700">${escHtml(c.combination)}</td>
            <td>${c.total_students}</td>
            <td class="${rankCls}">${c.mean ?? '—'}%</td>
            <td class="${grCls}" style="font-weight:800">${c.grade ?? '—'}</td>
            <td style="font-weight:700;color:${crColor}">${c.competence_rate ?? 0}%</td>
            <td style="font-weight:700;color:var(--blue)">${c.full_combination_pass_rate ?? 0}%</td>
            <td class="${riskCls}">${c.at_risk_count > 0 ? '⚠ ' + c.at_risk_count : '✔ 0'}</td>
            <td class="${rankCls}">${i === 0 ? '🥇 Best' : i === sorted.length - 1 ? '⚠ Weakest' : '#' + (i + 1)}</td>
        </tr>`;
    });
    document.getElementById('combo-tbody').innerHTML = tbody;

    // Chart
    if (comboChartInst) comboChartInst.destroy();
    comboChartInst = new Chart(document.getElementById('comboChart'), {
        type: 'bar',
        data: {
            labels: sorted.map(c => c.combination.length > 18 ? c.combination.slice(0,18)+'…' : c.combination),
            datasets: [
                {
                    label: 'Mean %',
                    data: sorted.map(c => c.mean ?? 0),
                    backgroundColor: sorted.map((_, i) => i === 0 ? '#43a047cc' : '#a5d6a7cc'),
                    borderColor:     sorted.map((_, i) => i === 0 ? '#2e7d32' : '#4caf50'),
                    borderWidth: 2,
                    borderRadius: 5,
                },
                {
                    label: 'Competence %',
                    data: sorted.map(c => c.competence_rate ?? 0),
                    backgroundColor: '#1565c033',
                    borderColor:     '#1565c0',
                    borderWidth: 2,
                    borderRadius: 5,
                    type: 'line',
                    tension: .3,
                    pointRadius: 5,
                    yAxisID: 'y',
                }
            ]
        },
       options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: true, position: 'top' },
                tooltip: {
                    callbacks: {
                        label: ctx => ` ${ctx.dataset.label}: ${ctx.raw}%`
                    }
                }
            },
            scales: {
                y: { beginAtZero: true, max: 100, title: { display: true, text: 'Percentage (%)' } }
            }
        }
    });
}

// ── Gender Breakdown ──────────────────────────────────────────────────────────
function renderGenderBreakdown(d) {
    const gb = d.genderBreakdown ?? {};
    const genders = Object.keys(gb).filter(g => g !== 'Unknown');
    if (!genders.length) {
        document.getElementById('section-gender').style.display = 'none';
        return;
    }

    const gradeColors = { A:'#43a047', B:'#7cb342', C:'#f9a825', D:'#fb8c00', E:'#e53935' };

    // ── Cards ──
    let cardsHtml = '';
    genders.forEach(g => {
        const data     = gb[g];
        const isMale   = g === 'Male';
        const cardCls  = isMale ? 'card-male' : 'card-female';
        const titleCls = isMale ? 'male' : 'female';
        const icon     = isMale ? 'fa-mars' : 'fa-venus';
        const meanColor= isMale ? '#1565c0' : '#880e4f';
        const crColor  = data.competence_rate >= 80 ? 'var(--green)' : data.competence_rate >= 60 ? 'var(--amber)' : 'var(--red)';

        let gradePills = '';
        Object.entries(data.grade_distribution ?? {}).forEach(([gr, info]) => {
            gradePills += `<span class="ggb-pill" style="background:${gradeColors[gr]}22;color:${gradeColors[gr]}">
                <span style="font-size:1.1em">${gr}</span>
                <span style="font-size:.7em">${info.count} (${info.percentage}%)</span>
            </span>`;
        });

        cardsHtml += `<div class="gender-card ${cardCls}">
            <div class="gender-card-title ${titleCls}">
                <i class="fas ${icon}"></i> ${g}
                <span style="font-size:.75em;font-weight:600;color:var(--muted)">${data.total} students</span>
            </div>
            <div class="gender-stat-row">
                <div class="gender-stat">
                    <span class="gender-stat-val" style="color:${meanColor}">${data.mean ?? '—'}%</span>
                    <span class="gender-stat-lbl">Mean %</span>
                </div>
                <div class="gender-stat">
                    <span class="gender-stat-val" style="color:${crColor}">${data.competence_rate ?? 0}%</span>
                    <span class="gender-stat-lbl">Competence Rate</span>
                </div>
                <div class="gender-stat">
                    <span class="gender-stat-val" style="color:var(--green-mid)">${data.grade ?? '—'}</span>
                    <span class="gender-stat-lbl">Avg Grade</span>
                </div>
            </div>
            <div class="gender-grade-bar">${gradePills}</div>
        </div>`;
    });

    // Gender gap callout
    if (genders.length === 2) {
        const g1 = gb[genders[0]], g2 = gb[genders[1]];
        const gap = Math.abs((g1.mean ?? 0) - (g2.mean ?? 0)).toFixed(1);
        const better = (g1.mean ?? 0) >= (g2.mean ?? 0) ? genders[0] : genders[1];
        const gapColor = parseFloat(gap) >= 5 ? 'var(--amber)' : 'var(--green)';
        cardsHtml += `<div style="background:#fff;border:1.5px solid var(--border);border-radius:8px;padding:12px 16px;font-size:.88em">
            <strong>Gender Gap:</strong> <span style="color:${gapColor};font-weight:800">${gap}%</span>
            &nbsp;—&nbsp; <strong>${better}</strong> students have a higher mean.
            ${parseFloat(gap) >= 5 ? '<br><span style="color:var(--amber)">⚠ A gap of ≥5% warrants gender-specific support strategies.</span>' : ''}
        </div>`;
    }

    document.getElementById('gender-cards').innerHTML = cardsHtml;

    // ── Chart ──
    if (genderChartInst) genderChartInst.destroy();
    const grades = ['A', 'B', 'C', 'D', 'E'];
    const colors = ['#43a047', '#7cb342', '#f9a825', '#fb8c00', '#e53935'];

    genderChartInst = new Chart(document.getElementById('genderChart'), {
        type: 'bar',
        data: {
            labels: genders,
            datasets: grades.map((g, i) => ({
                label: `Grade ${g}`,
                data: genders.map(gen => gb[gen]?.grade_distribution?.[g]?.percentage ?? 0),
                backgroundColor: colors[i] + 'cc',
                borderColor: colors[i],
                borderWidth: 2,
                borderRadius: 4,
            }))
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'top' },
                tooltip: { callbacks: { label: ctx => ` Grade ${ctx.dataset.label}: ${ctx.raw}%` } }
            },
            scales: {
                x: { stacked: false },
                y: { beginAtZero: true, max: 100, title: { display: true, text: '% of students' } }
            }
        }
    });
}

// ── Progress Tracking ─────────────────────────────────────────────────────────
function renderProgressTracking(d) {
    const improved = d.mostImproved ?? [];
    const declined = d.mostDeclined ?? [];

    const hasData = improved.length > 0 || declined.length > 0;
    document.getElementById('progress-no-data').style.display = hasData ? 'none' : 'block';

    function buildRows(arr, direction) {
        if (!arr.length) {
            return `<tr><td colspan="6" style="color:var(--muted);font-style:italic;padding:14px">No ${direction} students this term.</td></tr>`;
        }
        return arr.map(s => {
            const delta   = s.progress_delta ?? 0;
            const pfx     = delta > 0 ? '+' : '';
            const cls     = delta > 0 ? 'delta-positive' : 'delta-negative';
            const prev    = s.prev_term_mean != null ? s.prev_term_mean + '%' : '—';
            const now     = s.overall_mean != null ? s.overall_mean + '%' : '—';
            const gc      = s.overall_grade ? `gc-${s.overall_grade}` : '';
            return `<tr>
                <td class="left" style="font-weight:700">${escHtml(s.name)}</td>
                <td>${escHtml(s.stream || '—')}</td>
                <td style="color:var(--muted)">${prev}</td>
                <td class="${gc}" style="font-weight:700">${now}</td>
                <td class="${cls}" style="font-size:1.05em">${pfx}${delta}%</td>
                <td style="font-weight:800;color:var(--red)">${s.total_points ?? '—'}<span style="font-size:.72em">/17</span></td>
            </tr>`;
        }).join('');
    }

    document.getElementById('improved-tbody').innerHTML = buildRows(improved, 'improved');
    document.getElementById('declined-tbody').innerHTML = buildRows(declined, 'declined');
}
function exportCSV() {
    if (!currentData) { alert('Please run the analysis first.'); return; }
    const d = currentData;
    const f = d.filters;

    let csv = `A-Level Results Analysis — NCDC CBC System\n`;
    csv += `Class: ${f.class} | Stream: ${f.stream} | Term: ${f.term} | Year: ${f.year}\n`;
    csv += `Class Mean: ${d.classMean}% | Avg Points: ${d.avgTotalPoints ?? '–'}/17 | Competence Rate: ${d.competenceRate ?? d.principalPassRate}% | At-Risk: ${d.atRiskCount}\n\n`;

    // Subject performance
    csv += 'SUBJECT PERFORMANCE\n';
    const papers = [];
    d.subjectPerformance.forEach(s =>
        Object.keys(s.paper_means ?? {}).forEach(p => !papers.includes(p) && papers.push(p))
    );
    papers.sort();

    csv += 'Subject,Type,Overall %,Grade,Points,Competence %';
    papers.forEach(p => { csv += `,Paper ${p} %,Paper ${p} Grade,Paper ${p} Pts`; });
    csv += '\n';

    d.subjectPerformance.forEach(s => {
        const isSub = s.is_subsidiary;
        const ogPts = s.overall_grade
            ? (isSub ? subsidiaryPoints(s.overall_grade) : principalPoints(s.overall_grade))
            : '';
        const ptsMax = isSub ? 1 : 5;
        csv += `"${s.subject}",${isSub?'Subsidiary':'Principal'},${s.overall_mean ?? ''}%,${s.overall_grade ?? ''},${ogPts !== '' ? ogPts+'/'+ptsMax : ''},${s.pass_rate ?? s.competence_rate ?? ''}%`;
        papers.forEach(p => {
            const sc = s.paper_means?.[p] !== undefined ? s.paper_means[p]+'%' : '';
            const gr = s.paper_grades?.[p] || '';
            const pt = gr ? (isSub ? subsidiaryPoints(gr) : principalPoints(gr)) + '/' + (isSub?1:5) : '';
            csv += `,${sc},${gr},${pt}`;
        });
        csv += '\n';
    });

    // Top students
    csv += '\nTOP PERFORMERS (ranked by Total Points)\n';
    csv += 'Rank,Name,Stream,Total Points,Mean %,Grade\n';
    (d.topStudents ?? []).forEach((s,i) => {
        csv += `${i+1},"${s.name}","${s.stream||''}",${s.total_points ?? ''}/${17},${s.overall_mean ?? ''}%,${s.overall_grade ?? ''}\n`;
    });

    // At-risk
    csv += '\nAT-RISK STUDENTS (Grade E in any Principal Subject)\n';
    csv += 'Name,Stream,Total Points,Mean %,Principal Subjects with E\n';
    (d.atRiskStudents ?? []).forEach(s => {
        csv += `"${s.name}","${s.stream||''}",${s.total_points ?? ''}/${17},${s.overall_mean ?? ''}%,${s.principal_e_subjects ?? s.principal_fails ?? ''}\n`;
    });

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url  = URL.createObjectURL(blob);
    const a    = Object.assign(document.createElement('a'), {
        href: url,
        download: `alevel_cbc_analysis_${f.class.replace(/\s+/g,'_')}_T${f.term}_${f.year}.csv`
    });
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

// ── Utilities ─────────────────────────────────────────────────────────────────
function escHtml(str) {
    const d = document.createElement('div');
    d.textContent = str ?? '';
    return d.innerHTML;
}

// ── Tab buttons ───────────────────────────────────────────────────────────────
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        showComparison(this.dataset.comp);
    });
});

// ── Init ──────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', loadExamSets);
</script>
</body>
</html>