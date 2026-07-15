<?php
require_once 'auth.php';
require_once 'conn.php';
require_once 'tracking.php';
$tracker->trackAction('Issue student gatepass');

// CSRF token (consistent with add_student.php / view_students.php)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// Pre-fill "Issued By" from session if available
$issued_by_default = htmlspecialchars(
    $_SESSION['user_name'] ?? $_SESSION['username'] ?? $_SESSION['full_name'] ?? '',
    ENT_QUOTES, 'UTF-8'
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Issue Gate Pass &mdash; School Pilot</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        /* ── Variables (shared with add_student.php / view_students.php) ── */
        :root {
            --g900: #1b5e20; --g800: #2e7d32; --g700: #388e3c; --g600: #43a047;
            --g400: #66bb6a; --g100: #e8f5e9; --g50: #f1f8f1;
            --red: #d32f2f; --orange: #e65100; --blue: #1565c0; --gray: #546e7a;
            --radius: 8px; --radius-lg: 12px;
            --shadow: 0 2px 8px rgba(0,0,0,.10);
            --shadow-lg: 0 8px 28px rgba(0,0,0,.14);
            --transition: .22s ease;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: "Sen", system-ui, sans-serif;
            background: #f0f4f1;
            min-height: 100vh;
            color: #222;
        }

        /* ── Layout ─────────────────────────────────────────────── */
        .page {
            max-width: 100%;
            margin: 0 auto;
            padding: 24px 20px 60px;
        }

        /* ── Page Header ─────────────────────────────────────────── */
        .page-header {
            background: linear-gradient(135deg, var(--g900) 0%, var(--g700) 100%);
            border-radius: var(--radius-lg);
            padding: 28px 32px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 28px;
            margin-top: 40px;
            box-shadow: var(--shadow-lg);
        }

        .page-header-left h1 {
            color: #fff;
            font-size: 1.55rem;
            font-weight: 700;
            letter-spacing: .3px;
        }

        .page-header-left p {
            color: rgba(255,255,255,.75);
            font-size: .88rem;
            margin-top: 4px;
        }

        .header-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn-header {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 9px 18px;
            border: 1.5px solid rgba(255,255,255,.4);
            border-radius: var(--radius);
            background: rgba(255,255,255,.12);
            color: #fff;
            font-size: .85rem;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            text-decoration: none;
            transition: all var(--transition);
        }

        .btn-header:hover {
            background: rgba(255,255,255,.22);
            border-color: rgba(255,255,255,.7);
        }

        /* ── Form Card ───────────────────────────────────────────── */
        .form-card {
            background: #fff;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            overflow: hidden;
            margin-bottom: 20px;
        }

        /* ── Section Header ──────────────────────────────────────── */
        .section-header {
            padding: 16px 24px;
            border-bottom: 1px solid #e8ede9;
            display: flex;
            align-items: center;
            gap: 10px;
            background: var(--g50);
        }

        .section-header .icon {
            width: 32px;
            height: 32px;
            background: var(--g100);
            color: var(--g800);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .9rem;
            flex-shrink: 0;
        }

        .section-header h2 {
            font-size: 1rem;
            font-weight: 700;
            color: var(--g900);
        }

        .section-header p {
            font-size: .8rem;
            color: #6b7c6d;
            margin-top: 1px;
        }

        /* ── Section Body ────────────────────────────────────────── */
        .section-body {
            padding: 24px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 18px;
        }

        .form-grid.cols-3 {
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .form-group.span-2 { grid-column: span 2; }
        .form-group.span-full { grid-column: 1 / -1; }

        label {
            font-size: .82rem;
            font-weight: 600;
            color: #3a4a3c;
            letter-spacing: .2px;
        }

        label .req { color: var(--red); margin-left: 2px; }
        label .opt { font-weight: 400; color: #8a9a8b; font-size: .75rem; margin-left: 4px; }

        input, select, textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1.5px solid #d0dbd1;
            border-radius: var(--radius);
            font-size: .875rem;
            font-family: inherit;
            color: #222;
            background: #fff;
            transition: border-color var(--transition), box-shadow var(--transition);
        }

        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--g600);
            box-shadow: 0 0 0 3px rgba(67,160,71,.12);
        }

        input[readonly], input[disabled] {
            background: #f0f4f1;
            color: #6b7c6d;
            cursor: not-allowed;
            border-style: dashed;
        }

        textarea { resize: vertical; min-height: 90px; }

        /* ── Priority badges ─────────────────────────────────────── */
        select#priority option[value="normal"]    { color: var(--gray); }
        select#priority option[value="urgent"]    { color: var(--orange); }
        select#priority option[value="emergency"] { color: var(--red); }

        .priority-hint {
            font-size: .75rem;
            margin-top: 2px;
        }
        .priority-hint.normal    { color: var(--gray); }
        .priority-hint.urgent    { color: var(--orange); }
        .priority-hint.emergency { color: var(--red); font-weight: 700; }

        /* ── Validation states ───────────────────────────────────── */
        .is-invalid {
            border-color: var(--red) !important;
            box-shadow: 0 0 0 3px rgba(211,47,47,.1) !important;
        }

        .is-valid { border-color: var(--g600) !important; }

        .field-error {
            font-size: .76rem;
            color: var(--red);
            display: none;
            align-items: center;
            gap: 4px;
            margin-top: 2px;
        }
        .field-error.show { display: flex; }

        /* ── Student info card ───────────────────────────────────── */
        .student-info-card {
            display: none;
            background: var(--g50);
            border: 1.5px solid var(--g100);
            border-radius: var(--radius);
            padding: 12px 16px;
            gap: 16px;
            flex-wrap: wrap;
            align-items: center;
            margin-top: 12px;
        }
        .student-info-card.visible { display: flex; }
        .student-info-card .info-pill {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .student-info-card .info-pill .lbl {
            font-size: .7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: #6b7c6d;
        }
        .student-info-card .info-pill .val {
            font-size: .9rem;
            font-weight: 700;
            color: var(--g900);
        }

        /* ── Form Actions ────────────────────────────────────────── */
        .form-actions {
            background: #fff;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            padding: 20px 24px;
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 11px 24px;
            border: none;
            border-radius: var(--radius);
            font-size: .9rem;
            font-weight: 700;
            font-family: inherit;
            cursor: pointer;
            transition: all var(--transition);
            text-decoration: none;
        }
        .btn:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,.15); }
        .btn:active { transform: none; }

        .btn-secondary {
            background: #f0f4f1;
            color: var(--gray);
            border: 1.5px solid #d0dbd1;
        }
        .btn-secondary:hover { background: #e0e8e1; border-color: #b0c4b1; box-shadow: none; }

        .btn-primary {
            background: var(--g700);
            color: #fff;
        }
        .btn-primary:hover { background: var(--g800); }
        .btn-primary:disabled {
            opacity: .65;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .btn-spinner {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 2px solid rgba(255,255,255,.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin .8s linear infinite;
        }

        /* ── Success Card ────────────────────────────────────────── */
        .success-card {
            display: none;
            background: #fff;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            padding: 40px 32px;
            text-align: center;
            margin-bottom: 20px;
            border-top: 4px solid var(--g700);
        }
        .success-card.visible { display: block; }
        .success-card .success-icon {
            width: 72px;
            height: 72px;
            background: var(--g100);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 2rem;
            color: var(--g700);
        }
        .success-card h2 {
            font-size: 1.4rem;
            color: var(--g900);
            margin-bottom: 8px;
        }
        .success-card p {
            color: #6b7c6d;
            font-size: .9rem;
            margin-bottom: 24px;
        }
        .success-ref {
            display: inline-block;
            background: var(--g50);
            border: 1.5px solid var(--g100);
            border-radius: var(--radius);
            padding: 8px 20px;
            font-family: 'Courier New', monospace;
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--g800);
            letter-spacing: 1px;
            margin-bottom: 24px;
        }
        .success-actions {
            display: flex;
            gap: 12px;
            justify-content: center;
            flex-wrap: wrap;
        }
        .btn-print {
            background: var(--blue);
            color: #fff;
        }
        .btn-print:hover { background: #0d47a1; }
        .btn-new {
            background: var(--g700);
            color: #fff;
        }
        .btn-new:hover { background: var(--g800); }

        /* ── Toast Notifications ─────────────────────────────────── */
        #toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 10px;
            pointer-events: none;
        }

        .toast {
            min-width: 280px;
            max-width: 380px;
            padding: 14px 18px;
            border-radius: var(--radius);
            font-size: .875rem;
            font-weight: 600;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            box-shadow: var(--shadow-lg);
            animation: slideInRight .3s ease;
            pointer-events: auto;
        }

        .toast i { font-size: 1.1rem; flex-shrink: 0; margin-top: 1px; }

        .toast.success { background: #e8f5e9; color: #1b5e20; border-left: 4px solid var(--g700); }
        .toast.error   { background: #ffebee; color: #b71c1c; border-left: 4px solid var(--red); }
        .toast.warning { background: #fff8e1; color: #e65100; border-left: 4px solid #ffa000; }

        @keyframes slideInRight {
            from { opacity: 0; transform: translateX(30px); }
            to   { opacity: 1; transform: translateX(0); }
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        @keyframes fadeOut { to { opacity: 0; transform: translateX(10px); } }

        /* ── Responsive ──────────────────────────────────────────── */
        @media (max-width: 640px) {
            .form-grid { grid-template-columns: 1fr; }
            .form-group.span-2 { grid-column: span 1; }
            .page-header { flex-direction: column; }
        }
    </style>
</head>
<body>
    <?php require_once 'nav.php'; ?>

    <div id="toast-container"></div>

    <div class="page">
        <!-- Page Header -->
        <div class="page-header">
            <div class="page-header-left">
                <h1><i class="fa-solid fa-id-card-clip" style="margin-right:10px;opacity:.85"></i>Issue Gate Pass</h1>
                <p>Authorise a student to leave the school premises</p>
            </div>
            <div class="header-actions">
                <a href="view_gate_passes.php" class="btn-header">
                    <i class="fa-solid fa-list"></i> View All Passes
                </a>
            </div>
        </div>

        <!-- Success Card (hidden until a pass is issued) -->
        <div class="success-card" id="success-card">
            <div class="success-icon"><i class="fa-solid fa-check"></i></div>
            <h2>Gate Pass Issued!</h2>
            <p>The gate pass has been created successfully.</p>
            <div class="success-ref" id="success-ref"></div>
            <div class="success-actions">
                <a id="btn-print-pass" href="#" class="btn btn-print" target="_blank">
                    <i class="fa-solid fa-print"></i> Print Gate Pass
                </a>
                <button id="btn-issue-another" class="btn btn-new">
                    <i class="fa-solid fa-plus"></i> Issue Another Pass
                </button>
            </div>
        </div>

        <!-- Gate Pass Form -->
        <form id="gate-pass-form" novalidate>
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

            <!-- Section 1: Student Information -->
            <div class="form-card">
                <div class="section-header">
                    <div class="icon"><i class="fa-solid fa-user-graduate"></i></div>
                    <div>
                        <h2>Student Information</h2>
                        <p>Select the student this gate pass is for</p>
                    </div>
                </div>
                <div class="section-body">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="student_id_select">Student Name <span class="req">*</span></label>
                            <select id="student_id_select" name="student_id" required>
                                <option value="">Loading students&hellip;</option>
                            </select>
                            <span class="field-error" id="err-student_id">
                                <i class="fa-solid fa-circle-exclamation"></i>
                                <span>Please select a student.</span>
                            </span>
                        </div>

                        <!-- Hidden fields populated when student is selected -->
                        <input type="hidden" id="student_name" name="student_name">

                        <div class="form-group">
                            <label for="student_id_display">Student ID</label>
                            <input type="text" id="student_id_display" readonly disabled placeholder="Auto-filled on selection">
                        </div>
                    </div>

                    <!-- Student info summary card, shown after selection -->
                    <div class="student-info-card" id="student-info-card">
                        <div class="info-pill">
                            <span class="lbl">Class</span>
                            <span class="val" id="info-class">&mdash;</span>
                        </div>
                        <div class="info-pill">
                            <span class="lbl">Stream</span>
                            <span class="val" id="info-stream">&mdash;</span>
                        </div>
                    </div>

                    <!-- Hidden fields for class & stream so they are submitted -->
                    <input type="hidden" id="class" name="class">
                    <input type="hidden" id="stream" name="stream">
                </div>
            </div>

            <!-- Section 2: Pass Details -->
            <div class="form-card">
                <div class="section-header">
                    <div class="icon"><i class="fa-solid fa-clipboard-list"></i></div>
                    <div>
                        <h2>Pass Details</h2>
                        <p>Departure time, destination, and reason</p>
                    </div>
                </div>
                <div class="section-body">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="departure_time">Departure Time <span class="req">*</span></label>
                            <input type="datetime-local" id="departure_time" name="departure_time" required>
                            <span class="field-error" id="err-departure_time">
                                <i class="fa-solid fa-circle-exclamation"></i>
                                <span>Please set a valid departure time.</span>
                            </span>
                        </div>

                        <div class="form-group">
                            <label for="expected_return">Expected Return <span class="req">*</span></label>
                            <input type="datetime-local" id="expected_return" name="expected_return" required>
                            <span class="field-error" id="err-expected_return">
                                <i class="fa-solid fa-circle-exclamation"></i>
                                <span>Return time must be after departure.</span>
                            </span>
                        </div>

                        <div class="form-group">
                            <label for="destination">Destination <span class="req">*</span></label>
                            <input type="text" id="destination" name="destination" placeholder="e.g., Hospital, Home, Bank" required>
                            <span class="field-error" id="err-destination">
                                <i class="fa-solid fa-circle-exclamation"></i>
                                <span>Destination is required.</span>
                            </span>
                        </div>

                        <div class="form-group">
                            <label for="priority">Priority Level <span class="req">*</span></label>
                            <select id="priority" name="priority" required>
                                <option value="">Select Priority</option>
                                <option value="normal">Normal</option>
                                <option value="urgent">Urgent</option>
                                <option value="emergency">Emergency</option>
                            </select>
                            <span class="field-error" id="err-priority">
                                <i class="fa-solid fa-circle-exclamation"></i>
                                <span>Please select a priority level.</span>
                            </span>
                            <span class="priority-hint" id="priority-hint"></span>
                        </div>

                        <div class="form-group span-full">
                            <label for="reason">Reason for Leaving <span class="req">*</span></label>
                            <textarea id="reason" name="reason" placeholder="Please provide a detailed reason for the student leaving the school premises&hellip;" required></textarea>
                            <span class="field-error" id="err-reason">
                                <i class="fa-solid fa-circle-exclamation"></i>
                                <span>A reason is required.</span>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section 3: Contact & Emergency Information -->
            <div class="form-card">
                <div class="section-header">
                    <div class="icon"><i class="fa-solid fa-phone"></i></div>
                    <div>
                        <h2>Contact &amp; Emergency Information</h2>
                        <p>Optional contacts in case of emergency</p>
                    </div>
                </div>
                <div class="section-body">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="parent_contact">Parent / Guardian Contact <span class="opt">(optional)</span></label>
                            <input type="tel" id="parent_contact" name="parent_contact" placeholder="+256 700 000 000">
                            <span class="field-error" id="err-parent_contact">
                                <i class="fa-solid fa-circle-exclamation"></i>
                                <span>Enter a valid phone number (10–15 digits).</span>
                            </span>
                        </div>

                        <div class="form-group">
                            <label for="student_contact">Student Contact <span class="opt">(optional)</span></label>
                            <input type="tel" id="student_contact" name="student_contact" placeholder="+256 700 000 000">
                            <span class="field-error" id="err-student_contact">
                                <i class="fa-solid fa-circle-exclamation"></i>
                                <span>Enter a valid phone number (10–15 digits).</span>
                            </span>
                        </div>

                        <div class="form-group span-full">
                            <label for="accompanying_person">Accompanying Person <span class="opt">(optional)</span></label>
                            <input type="text" id="accompanying_person" name="accompanying_person" placeholder="Name and relationship, e.g. John Doe (Father)">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section 4: Authorisation -->
            <div class="form-card">
                <div class="section-header">
                    <div class="icon"><i class="fa-solid fa-user-shield"></i></div>
                    <div>
                        <h2>Authorisation</h2>
                        <p>The staff member issuing this gate pass</p>
                    </div>
                </div>
                <div class="section-body">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="issued_by">Issued By <span class="req">*</span></label>
                            <input type="text" id="issued_by" name="issued_by"
                                   placeholder="Your full name"
                                   value="<?= $issued_by_default ?>"
                                   required>
                            <span class="field-error" id="err-issued_by">
                                <i class="fa-solid fa-circle-exclamation"></i>
                                <span>Please enter the issuer&rsquo;s name.</span>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="form-actions">
                <button type="button" id="btn-reset" class="btn btn-secondary">
                    <i class="fa-solid fa-rotate-left"></i> Reset Form
                </button>
                <button type="submit" id="btn-submit" class="btn btn-primary">
                    <i class="fa-solid fa-id-card-clip"></i> Issue Gate Pass
                </button>
            </div>
        </form>
    </div><!-- /.page -->

    <script>
    (() => {
        'use strict';

        /* ── DOM refs ──────────────────────────────────────────────── */
        const form            = document.getElementById('gate-pass-form');
        const submitBtn       = document.getElementById('btn-submit');
        const studentSelect   = document.getElementById('student_id_select');
        const studentNameInput= document.getElementById('student_name');
        const studentIdDisplay= document.getElementById('student_id_display');
        const classInput      = document.getElementById('class');
        const streamInput     = document.getElementById('stream');
        const infoCard        = document.getElementById('student-info-card');
        const infoClass       = document.getElementById('info-class');
        const infoStream      = document.getElementById('info-stream');
        const successCard     = document.getElementById('success-card');
        const successRef      = document.getElementById('success-ref');
        const btnPrint        = document.getElementById('btn-print-pass');
        const btnIssueAnother = document.getElementById('btn-issue-another');
        const prioritySelect  = document.getElementById('priority');
        const priorityHint    = document.getElementById('priority-hint');

        /* ── Toast helper (no innerHTML for user content) ──────────── */
        const toastContainer = document.getElementById('toast-container');
        function showToast(message, type = 'success', duration = 5000) {
            const icons = { success: 'fa-circle-check', error: 'fa-circle-xmark', warning: 'fa-triangle-exclamation' };
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            const icon = document.createElement('i');
            icon.className = `fa-solid ${icons[type] ?? icons.success}`;
            const text = document.createElement('span');
            text.textContent = message;          // textContent — safe, no XSS
            toast.append(icon, text);
            toastContainer.appendChild(toast);
            setTimeout(() => {
                toast.style.animation = 'fadeOut .4s ease forwards';
                setTimeout(() => toast.remove(), 400);
            }, duration);
        }

        /* ── Field error helpers ────────────────────────────────────── */
        function setFieldError(id, message) {
            const errEl  = document.getElementById(`err-${id}`);
            const input  = document.getElementById(id) ?? document.querySelector(`[name="${id}"]`);
            if (errEl) {
                errEl.querySelector('span').textContent = message;
                errEl.classList.toggle('show', !!message);
            }
            if (input) {
                input.classList.toggle('is-invalid', !!message);
                input.classList.toggle('is-valid',   !message && input.value.trim() !== '');
            }
        }

        function clearFieldError(id) { setFieldError(id, ''); }

        /* ── Priority hint ──────────────────────────────────────────── */
        const PRIORITY_HINTS = {
            normal:    'Routine exit — standard processing.',
            urgent:    'Time-sensitive matter — expedited handling.',
            emergency: 'Immediate action required — notify administration.'
        };
        prioritySelect.addEventListener('change', () => {
            const val = prioritySelect.value;
            priorityHint.textContent   = PRIORITY_HINTS[val] ?? '';
            priorityHint.className     = `priority-hint ${val}`;
            clearFieldError('priority');
        });

        /* ── Load students list ─────────────────────────────────────── */
        async function fetchStudents() {
            try {
                const res = await fetch('api/get_students_list.php');
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                const data = await res.json();

                if (!data.success) throw new Error(data.error ?? 'Failed to load students.');

                studentSelect.innerHTML = '<option value="">— Select a Student —</option>';
                data.data.forEach(s => {
                    const opt = document.createElement('option');
                    opt.value = s.student_id;
                    // Store extra data as JSON in a data attribute — avoids separate API call
                    opt.dataset.info = JSON.stringify({ class: s.class, stream: s.stream, name: s.full_name });
                    opt.textContent  = `${s.full_name} (${s.class} ${s.stream})`;
                    studentSelect.appendChild(opt);
                });
            } catch (err) {
                studentSelect.innerHTML = '<option value="">Error loading students — refresh to retry</option>';
                showToast('Could not load the students list: ' + err.message, 'error');
            }
        }

        /* ── Handle student selection ───────────────────────────────── */
        function handleStudentChange() {
            const opt = studentSelect.options[studentSelect.selectedIndex];
            if (!opt || !opt.value) {
                studentIdDisplay.value = '';
                studentNameInput.value = '';
                classInput.value       = '';
                streamInput.value      = '';
                infoCard.classList.remove('visible');
                return;
            }
            const info = JSON.parse(opt.dataset.info);
            studentIdDisplay.value = opt.value;
            studentNameInput.value = info.name;
            classInput.value       = info.class;
            streamInput.value      = info.stream;
            infoClass.textContent  = info.class  || '—';
            infoStream.textContent = info.stream || '—';
            infoCard.classList.add('visible');
            clearFieldError('student_id');
        }

        studentSelect.addEventListener('change', handleStudentChange);

        /* ── Client-side validation ─────────────────────────────────── */
        const phoneRe = /^\+?[\d\s\-()\[\]]{10,20}$/;

        const VALIDATIONS = {
            student_id: v => v ? '' : 'Please select a student.',
            departure_time: v => {
                if (!v) return 'Departure time is required.';
                const d = new Date(v);
                if (isNaN(d)) return 'Invalid departure time.';
                // Allow up to 10 minutes in the past to account for form-fill delay
                if (d < new Date(Date.now() - 10 * 60 * 1000)) return 'Departure time cannot be in the past.';
                return '';
            },
            expected_return: v => {
                if (!v) return 'Expected return time is required.';
                const d = new Date(v);
                if (isNaN(d)) return 'Invalid return time.';
                const dep = new Date(document.getElementById('departure_time').value);
                if (!isNaN(dep) && d <= dep) return 'Return time must be after departure.';
                return '';
            },
            destination: v => v.length < 2 ? 'Destination is required (min 2 characters).' : '',
            priority:    v => ['normal','urgent','emergency'].includes(v) ? '' : 'Please select a priority level.',
            reason:      v => v.length < 5 ? 'Reason is required (min 5 characters).' : '',
            issued_by:   v => v.length < 2 ? 'Issuer name is required.' : '',
            parent_contact:  v => !v || phoneRe.test(v) ? '' : 'Enter a valid phone number (10–15 digits).',
            student_contact: v => !v || phoneRe.test(v) ? '' : 'Enter a valid phone number (10–15 digits).',
        };

        // Attach live-validation (blur + re-check on input if already invalid)
        Object.keys(VALIDATIONS).forEach(id => {
            const el = document.getElementById(id) ?? document.querySelector(`[name="${id}"]`);
            if (!el) return;
            el.addEventListener('blur', () => setFieldError(id, VALIDATIONS[id](el.value.trim())));
            el.addEventListener('input', () => {
                if (el.classList.contains('is-invalid')) setFieldError(id, VALIDATIONS[id](el.value.trim()));
            });
        });

        function validateAll() {
            let valid = true;
            Object.keys(VALIDATIONS).forEach(id => {
                const el = document.getElementById(id) ?? document.querySelector(`[name="${id}"]`);
                if (!el) return;
                const msg = VALIDATIONS[id](el.value.trim());
                setFieldError(id, msg);
                if (msg) valid = false;
            });
            return valid;
        }

        /* ── Form submit ────────────────────────────────────────────── */
        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            if (!validateAll()) {
                showToast('Please correct the highlighted errors before submitting.', 'warning');
                const first = form.querySelector('.is-invalid');
                if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }

            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="btn-spinner"></span> Issuing&hellip;';

            // Build payload from form fields — avoids FormData serialisation issues with JSON endpoint
            const payload = {
                csrf_token:          form.querySelector('[name="csrf_token"]').value,
                student_id:          studentSelect.value,
                student_name:        studentNameInput.value,
                class:               classInput.value,
                stream:              streamInput.value,
                departure_time:      document.getElementById('departure_time').value,
                expected_return:     document.getElementById('expected_return').value,
                destination:         document.getElementById('destination').value,
                priority:            document.getElementById('priority').value,
                reason:              document.getElementById('reason').value,
                parent_contact:      document.getElementById('parent_contact').value,
                student_contact:     document.getElementById('student_contact').value,
                accompanying_person: document.getElementById('accompanying_person').value,
                issued_by:           document.getElementById('issued_by').value,
            };

            try {
                const res = await fetch('api/process_gate_pass.php', {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body:    JSON.stringify(payload),
                });

                const result = await res.json();

                if (result.success) {
                    // Show the success card
                    form.style.display = 'none';
                    successCard.classList.add('visible');
                    successRef.textContent        = result.data.reference_number;
                    btnPrint.href = `api/print_gate_pass.php?id=${encodeURIComponent(result.data.pass_id)}`;
                    successCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
                } else {
                    // Show server-returned field errors
                    if (Array.isArray(result.errors) && result.errors.length) {
                        result.errors.forEach(msg => showToast(msg, 'error', 7000));
                    } else {
                        showToast(result.error ?? 'An unknown error occurred. Please try again.', 'error');
                    }
                }
            } catch (err) {
                showToast('A network error occurred. Please check your connection and try again.', 'error');
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fa-solid fa-id-card-clip"></i> Issue Gate Pass';
            }
        });

        /* ── Reset ──────────────────────────────────────────────────── */
        function resetForm() {
            form.reset();
            form.querySelectorAll('.is-invalid, .is-valid').forEach(el => el.classList.remove('is-invalid','is-valid'));
            form.querySelectorAll('.field-error').forEach(el => el.classList.remove('show'));
            studentIdDisplay.value = '';
            studentNameInput.value = '';
            classInput.value       = '';
            streamInput.value      = '';
            infoCard.classList.remove('visible');
            priorityHint.textContent = '';
            priorityHint.className   = 'priority-hint';
        }

        document.getElementById('btn-reset').addEventListener('click', () => {
            if (confirm('Reset the form? All entered data will be lost.')) resetForm();
        });

        btnIssueAnother.addEventListener('click', () => {
            successCard.classList.remove('visible');
            form.style.display = '';
            resetForm();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });

        /* ── Set minimum datetime to now ────────────────────────────── */
        function setMinDatetime() {
            const now = new Date();
            now.setSeconds(0, 0);
            const iso = now.toISOString().slice(0, 16);
            document.getElementById('departure_time').min = iso;
            document.getElementById('expected_return').min = iso;
        }

        setMinDatetime();
        fetchStudents();
    })();
    </script>
</body>
</html>