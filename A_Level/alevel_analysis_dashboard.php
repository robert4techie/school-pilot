<?php
/**
 * alevel_analysis_dashboard.php
 * ─────────────────────────────────────────────────────────────────────────────
 * A-Level Analysis Hub — entry point for all A-Level analysis tools.
 * Links to: Class Results Analysis, Individual Student Analysis.
 * NCDC CBC New Curriculum — Uganda Ministry of Education
 */
require_once '../auth.php';
require_once '../conn.php';
require_once '../tracking.php';
$tracker->trackAction("A-Level Analysis Dashboard");

$currentYear = date('Y');
$currentTerm = null;

// ── Quick KPI: fetch latest available term's class mean & student count ───────
// We scan for the most recent alevel marks table that exists
$kpi = ['totalStudents' => 0, 'avgPoints' => null, 'classMean' => null,
        'atRiskCount' => 0, 'latestTerm' => null, 'latestYear' => null];

$romans = [1 => 'i', 2 => 'ii', 3 => 'iii'];
for ($y = $currentYear; $y >= $currentYear - 1; $y--) {
    for ($t = 3; $t >= 1; $t--) {
        $tbl = "{$y}_{$romans[$t]}_alevel";
        $chk = mysqli_query($conn, "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $tbl) . "'");
        if (!$chk || mysqli_num_rows($chk) === 0) continue;

        // Count distinct A-Level students
        $r = mysqli_query($conn, "SELECT COUNT(DISTINCT student_id) AS cnt FROM `{$tbl}`");
        if ($r) $kpi['totalStudents'] = (int) mysqli_fetch_assoc($r)['cnt'];

        // Class mean %
        $r = mysqli_query($conn, "SELECT AVG(mark) AS avg FROM `{$tbl}` WHERE mark IS NOT NULL");
        if ($r && ($row = mysqli_fetch_assoc($r)) && $row['avg'] !== null)
            $kpi['classMean'] = round((float)$row['avg'], 1);

        $kpi['latestTerm'] = $t;
        $kpi['latestYear'] = $y;
        $currentTerm = $t;
        break 2;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>A-Level Analysis | SchoolPilot</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sen:wght@400..800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
<style>
:root {
    --green:       #1b5e20;
    --green-mid:   #2e7d32;
    --green-500:   #388e3c;
    --green-light: #a5d6a7;
    --green-bg:    #f1f8e9;
    --green-50:    #f9fbe7;
    --amber:       #f57c00;
    --red:         #c62828;
    --blue:        #1565c0;
    --card-bg:     #ffffff;
    --text:        #212121;
    --muted:       #666;
    --border:      #e0e0e0;
    --radius:      14px;
    --shadow:      0 4px 20px rgba(0,0,0,.08);
    --shadow-hover:0 10px 32px rgba(0,0,0,.14);
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: "Sen", sans-serif;
    background: var(--green-bg);
    color: var(--text);
    padding: 32px 24px 80px;
    min-height: 100vh;
}

/* ── PAGE HEADER ─────────────────────────────────────────────────────── */
.page-header {
    text-align: center;
    margin-bottom: 36px;
}
.page-header h1 {
    font-size: 2.2em;
    font-weight: 800;
    color: var(--green);
    letter-spacing: -.5px;
    margin-bottom: 8px;
}
.page-header p {
    color: var(--muted);
    font-size: .98em;
    line-height: 1.6;
}
.cbc-badge {
    display: inline-block;
    background: var(--green);
    color: #fff;
    font-size: .72em;
    font-weight: 700;
    padding: 3px 14px;
    border-radius: 20px;
    letter-spacing: .05em;
    margin-top: 8px;
}

/* ── KPI STRIP ───────────────────────────────────────────────────────── */
.kpi-strip {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 14px;
    margin-bottom: 36px;
}
.kpi-card {
    background: var(--card-bg);
    border-radius: var(--radius);
    padding: 20px 18px;
    box-shadow: var(--shadow);
    border-top: 4px solid var(--green-light);
    display: flex;
    align-items: center;
    gap: 16px;
    transition: box-shadow .2s;
}
.kpi-card:hover { box-shadow: var(--shadow-hover); }
.kpi-card.kpi-green  { border-top-color: #43a047; }
.kpi-card.kpi-amber  { border-top-color: var(--amber); }
.kpi-card.kpi-red    { border-top-color: var(--red); }
.kpi-card.kpi-blue   { border-top-color: var(--blue); }
.kpi-icon {
    width: 48px; height: 48px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.35em; flex-shrink: 0;
}
.kpi-green  .kpi-icon { background: #e8f5e9; color: #2e7d32; }
.kpi-amber  .kpi-icon { background: #fff3e0; color: var(--amber); }
.kpi-red    .kpi-icon { background: #ffebee; color: var(--red); }
.kpi-blue   .kpi-icon { background: #e3f2fd; color: var(--blue); }
.kpi-info .kpi-value {
    font-size: 1.8em;
    font-weight: 800;
    color: var(--green);
    line-height: 1.1;
}
.kpi-amber  .kpi-value { color: var(--amber); }
.kpi-red    .kpi-value { color: var(--red); }
.kpi-blue   .kpi-value { color: var(--blue); }
.kpi-info .kpi-label {
    font-size: .78em;
    color: var(--muted);
    font-weight: 600;
    margin-top: 2px;
    line-height: 1.3;
}

/* ── SECTION HEADING ─────────────────────────────────────────────────── */
.section-heading {
    font-size: .75em;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .1em;
    color: var(--green-mid);
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
    padding-left: 2px;
}
.section-heading::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--border);
    margin-left: 4px;
}

/* ── ANALYSIS TOOL CARDS ─────────────────────────────────────────────── */
.tools-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap: 22px;
}
.tool-card {
    display: block;
    background: var(--card-bg);
    border-radius: var(--radius);
    padding: 28px 26px;
    text-decoration: none;
    color: var(--text);
    box-shadow: var(--shadow);
    border-left: 5px solid var(--green-light);
    transition: transform .25s, box-shadow .25s, border-left-color .25s;
    position: relative;
    overflow: hidden;
}
.tool-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    background: linear-gradient(90deg, var(--green-mid), var(--green-500));
    opacity: 0;
    transition: opacity .25s;
}
.tool-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-hover);
    border-left-color: var(--green-mid);
}
.tool-card:hover::before { opacity: 1; }

.tool-card-header {
    display: flex;
    align-items: flex-start;
    gap: 16px;
    margin-bottom: 14px;
}
.tool-icon {
    width: 52px; height: 52px;
    border-radius: 12px;
    background: linear-gradient(135deg, var(--green-mid), var(--green-500));
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.4em;
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(46,125,50,.3);
    transition: transform .25s;
}
.tool-card:hover .tool-icon { transform: scale(1.08); }

/* Icon colour variants */
.tool-card.variant-blue   .tool-icon { background: linear-gradient(135deg,#1565c0,#1976d2); box-shadow: 0 4px 12px rgba(21,101,192,.3); }
.tool-card.variant-purple .tool-icon { background: linear-gradient(135deg,#6a1b9a,#8e24aa); box-shadow: 0 4px 12px rgba(106,27,154,.3); }
.tool-card.variant-amber  .tool-icon { background: linear-gradient(135deg,#e65100,#f57c00); box-shadow: 0 4px 12px rgba(230,81,0,.3); }

.tool-card.variant-blue   { border-left-color: #90caf9; }
.tool-card.variant-blue:hover { border-left-color: #1565c0; }
.tool-card.variant-blue::before { background: linear-gradient(90deg,#1565c0,#1976d2); }

.tool-card.variant-purple { border-left-color: #ce93d8; }
.tool-card.variant-purple:hover { border-left-color: #6a1b9a; }
.tool-card.variant-purple::before { background: linear-gradient(90deg,#6a1b9a,#8e24aa); }

.tool-card.variant-amber  { border-left-color: #ffcc80; }
.tool-card.variant-amber:hover { border-left-color: #e65100; }
.tool-card.variant-amber::before { background: linear-gradient(90deg,#e65100,#f57c00); }

.tool-title {
    font-size: 1.15em;
    font-weight: 800;
    color: var(--green);
    margin-bottom: 3px;
    line-height: 1.2;
}
.tool-card.variant-blue   .tool-title  { color: #1565c0; }
.tool-card.variant-purple .tool-title  { color: #6a1b9a; }
.tool-card.variant-amber  .tool-title  { color: #e65100; }

.tool-subtitle {
    font-size: .78em;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: var(--muted);
    margin-bottom: 0;
}
.tool-description {
    font-size: .9em;
    color: #555;
    line-height: 1.65;
    margin-bottom: 16px;
}

/* Feature list */
.tool-features {
    list-style: none;
    display: flex;
    flex-direction: column;
    gap: 5px;
    margin-bottom: 18px;
}
.tool-features li {
    font-size: .82em;
    color: var(--muted);
    display: flex;
    align-items: center;
    gap: 7px;
    line-height: 1.4;
}
.tool-features li i {
    font-size: .78em;
    color: var(--green-mid);
    width: 14px;
    flex-shrink: 0;
}
.tool-card.variant-blue   .tool-features li i { color: #1565c0; }
.tool-card.variant-purple .tool-features li i { color: #6a1b9a; }
.tool-card.variant-amber  .tool-features li i { color: #e65100; }

/* CTA row */
.tool-cta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding-top: 14px;
    border-top: 1px solid var(--border);
}
.tool-cta-text {
    font-size: .82em;
    font-weight: 700;
    color: var(--green-mid);
    display: flex;
    align-items: center;
    gap: 6px;
}
.tool-card.variant-blue   .tool-cta-text { color: #1565c0; }
.tool-card.variant-purple .tool-cta-text { color: #6a1b9a; }
.tool-card.variant-amber  .tool-cta-text { color: #e65100; }
.tool-cta-arrow {
    width: 32px; height: 32px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    background: var(--green-bg);
    color: var(--green-mid);
    font-size: .9em;
    transition: transform .2s, background .2s;
}
.tool-card.variant-blue  .tool-cta-arrow { background: #e3f2fd; color: #1565c0; }
.tool-card.variant-purple .tool-cta-arrow { background: #f3e5f5; color: #6a1b9a; }
.tool-card.variant-amber  .tool-cta-arrow { background: #fff3e0; color: #e65100; }
.tool-card:hover .tool-cta-arrow { transform: translateX(4px); }

/* ── CBC REFERENCE STRIP ─────────────────────────────────────────────── */
.cbc-ref {
    background: var(--card-bg);
    border-radius: var(--radius);
    padding: 16px 22px;
    box-shadow: var(--shadow);
    margin-top: 28px;
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
    border-top: 3px solid var(--green-light);
}
.cbc-ref-title {
    font-size: .78em;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: var(--green-mid);
    margin-right: 4px;
}
.gp {
    display: inline-flex; flex-direction: column;
    align-items: center; padding: 5px 12px;
    border-radius: 8px; font-weight: 700; min-width: 64px;
}
.gp .gl { font-size: 1.15em; line-height: 1; }
.gp .gr { font-size: .68em; opacity: .75; }
.gp .gpt { font-size: .68em; font-weight: 800; opacity: .9; }
.gA { background: #e8f5e9; color: #1b5e20; }
.gB { background: #f1f8e9; color: #33691e; }
.gC { background: #fff8e1; color: #e65100; }
.gD { background: #fff3e0; color: #bf360c; }
.gE { background: #ffebee; color: #b71c1c; }
.cbc-divider { width:1px; height:32px; background: var(--border); margin:0 4px; }
.cbc-max { font-size: .82em; font-weight: 700; color: var(--green); }

/* ── RESPONSIVE ──────────────────────────────────────────────────────── */
@media (max-width: 768px) {
    body { padding: 16px 12px 60px; }
    .tools-grid { grid-template-columns: 1fr; }
    .kpi-strip { grid-template-columns: 1fr 1fr; }
    .cbc-ref  { flex-direction: column; align-items: flex-start; }
}
@media (max-width: 480px) {
    .kpi-strip { grid-template-columns: 1fr; }
    .page-header h1 { font-size: 1.6em; }
}
</style>
</head>
<body>

<?php require_once '../nav.php'; ?>

<div style="max-width:1200px;margin:60px auto 0;">

    <!-- PAGE HEADER -->
    <div class="page-header">
        <h1><i class="fas fa-graduation-cap" style="margin-right:10px;opacity:.85"></i>A-Level Analysis</h1>
        <p>Senior Five &amp; Senior Six &nbsp;·&nbsp; NCDC Competence-Based Curriculum</p>
        <span class="cbc-badge">Uganda Ministry of Education — CBC Grading System</span>
    </div>

    <!-- KPI STRIP -->
    <div class="kpi-strip">
        <div class="kpi-card kpi-green">
            <div class="kpi-icon"><i class="fas fa-users"></i></div>
            <div class="kpi-info">
                <div class="kpi-value">
                    <?= $kpi['totalStudents'] > 0 ? number_format($kpi['totalStudents']) : '—' ?>
                </div>
                <div class="kpi-label">
                    A-Level Students<?= $kpi['latestTerm'] ? " · Term {$kpi['latestTerm']}, {$kpi['latestYear']}" : '' ?>
                </div>
            </div>
        </div>
        <div class="kpi-card kpi-blue">
            <div class="kpi-icon"><i class="fas fa-chart-line"></i></div>
            <div class="kpi-info">
                <div class="kpi-value">
                    <?= $kpi['classMean'] !== null ? $kpi['classMean'] . '%' : '—' ?>
                </div>
                <div class="kpi-label">Class Mean %<?= $kpi['latestTerm'] ? " · Latest Term" : '' ?></div>
            </div>
        </div>
        <div class="kpi-card kpi-amber">
            <div class="kpi-icon"><i class="fas fa-star"></i></div>
            <div class="kpi-info">
                <div class="kpi-value">17 pts</div>
                <div class="kpi-label">Max CBC Points · 15 Principal + 2 Subsidiary</div>
            </div>
        </div>
        <div class="kpi-card kpi-red">
            <div class="kpi-icon"><i class="fas fa-graduation-cap"></i></div>
            <div class="kpi-info">
                <div class="kpi-value">S5 &amp; S6</div>
                <div class="kpi-label">Senior Five &amp; Senior Six Covered</div>
            </div>
        </div>
    </div>

    <!-- ANALYSIS TOOLS -->
    <div class="section-heading">
        <i class="fas fa-chart-bar"></i> Analysis Tools
    </div>

    <div class="tools-grid">

        <!-- Class Results Analysis -->
        <a href="alevel_analysis.php" class="tool-card">
            <div class="tool-card-header">
                <div class="tool-icon"><i class="fas fa-chart-bar"></i></div>
                <div>
                    <div class="tool-title">Class Results Analysis</div>
                    <div class="tool-subtitle">Full cohort · Subject · Stream · Combination</div>
                </div>
            </div>
            <p class="tool-description">
                Comprehensive analysis of the entire class across all subjects and streams.
                Identifies top performers, at-risk students, and subject combination trends
                using CBC points as the primary ranking metric.
            </p>
            <ul class="tool-features">
                <li><i class="fas fa-check-circle"></i> Health cards — Mean %, Avg Points/17, Competence Rate, At-Risk count</li>
                <li><i class="fas fa-check-circle"></i> Grade distribution &amp; Points distribution charts</li>
                <li><i class="fas fa-check-circle"></i> Subject performance table — Score / Grade / Points per paper</li>
                <li><i class="fas fa-check-circle"></i> Subject combination analysis with competence rates</li>
                <li><i class="fas fa-check-circle"></i> Gender breakdown — mean %, grade distribution comparison</li>
                <li><i class="fas fa-check-circle"></i> Full class results grid — every student × every subject</li>
                <li><i class="fas fa-check-circle"></i> Most Improved / Most Declined progress tracking</li>
                <li><i class="fas fa-check-circle"></i> Auto-generated insights &amp; action recommendations</li>
                <li><i class="fas fa-check-circle"></i> CSV export for HODs &amp; printable PDF</li>
            </ul>
            <div class="tool-cta">
                <span class="tool-cta-text"><i class="fas fa-layer-group"></i> Class-wide analysis</span>
                <div class="tool-cta-arrow"><i class="fas fa-arrow-right"></i></div>
            </div>
        </a>

        <!-- Individual Student Analysis -->
        <a href="student_analysis.php" class="tool-card variant-blue">
            <div class="tool-card-header">
                <div class="tool-icon"><i class="fas fa-user-graduate"></i></div>
                <div>
                    <div class="tool-title">Individual Student Analysis</div>
                    <div class="tool-subtitle">Diagnostic · Predictive · Intervention</div>
                </div>
            </div>
            <p class="tool-description">
                Deep-dive into a single student's performance across all subjects and terms.
                Combines a live diagnostic snapshot with a multi-model predictive forecast
                to project expected performance and CBC points trajectory.
            </p>
            <ul class="tool-features">
                <li><i class="fas fa-check-circle"></i> Diagnostic tab — current term snapshot with class comparison</li>
                <li><i class="fas fa-check-circle"></i> Points headline — Total /17, Principal /15, Subsidiary /2</li>
                <li><i class="fas fa-check-circle"></i> Subject radar chart &amp; vs-class bar chart</li>
                <li><i class="fas fa-check-circle"></i> Term comparison matrix — all terms side by side</li>
                <li><i class="fas fa-check-circle"></i> Strengths, weaknesses, most improved, most declined</li>
                <li><i class="fas fa-check-circle"></i> Class ranking by CBC points — with percentile bar</li>
                <li><i class="fas fa-check-circle"></i> Predictive tab — 3-model ensemble forecast (linear + MA + exp)</li>
                <li><i class="fas fa-check-circle"></i> Risk assessment with CBC-aware risk factors</li>
                <li><i class="fas fa-check-circle"></i> Structured 3-phase intervention plan for at-risk students</li>
            </ul>
            <div class="tool-cta">
                <span class="tool-cta-text"><i class="fas fa-user"></i> Search &amp; analyse one student</span>
                <div class="tool-cta-arrow"><i class="fas fa-arrow-right"></i></div>
            </div>
        </a>

    </div><!-- /.tools-grid -->

    <!-- CBC GRADING REFERENCE -->
    <div class="cbc-ref">
        <span class="cbc-ref-title"><i class="fas fa-info-circle"></i> CBC Grading Key:</span>
        <span class="cbc-ref-title" style="color:var(--green-mid)">Principal</span>
        <div class="gp gA"><span class="gl">A</span><span class="gr">80–100%</span><span class="gpt">5 pts</span></div>
        <div class="gp gB"><span class="gl">B</span><span class="gr">70–79%</span><span class="gpt">4 pts</span></div>
        <div class="gp gC"><span class="gl">C</span><span class="gr">60–69%</span><span class="gpt">3 pts</span></div>
        <div class="gp gD"><span class="gl">D</span><span class="gr">50–59%</span><span class="gpt">2 pts</span></div>
        <div class="gp gE"><span class="gl">E</span><span class="gr">0–49%</span><span class="gpt">1 pt</span></div>
        <div class="cbc-divider"></div>
        <span class="cbc-ref-title" style="color:var(--red)">Subsidiary</span>
        <div class="gp gA"><span class="gl">D–A</span><span class="gr">50–100%</span><span class="gpt">1 pt</span></div>
        <div class="gp gE"><span class="gl">E</span><span class="gr">0–49%</span><span class="gpt">0 pts</span></div>
        <div class="cbc-divider"></div>
        <span class="cbc-max">Max: <strong>17 pts</strong> &nbsp;(15 principal + 2 subsidiary)</span>
    </div>

</div><!-- /container -->

</body>
</html>
