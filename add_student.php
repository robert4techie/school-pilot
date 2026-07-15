<?php
require_once "auth.php";
require_once 'tracking.php';
$tracker->trackAction("Add new student");

// CSRF token generation (consistent with view_students.php)
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
    <title>New Student Registration &mdash; School Pilot</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        /* ── Variables (shared with view_students.php) ────────────── */
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

        /* ── Layout ──────────────────────────────────────────────── */
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

        .student-id-badge {
            background: rgba(255,255,255,.13);
            border: 1px solid rgba(255,255,255,.3);
            border-radius: 40px;
            padding: 10px 22px;
            text-align: center;
        }

        .student-id-badge .id-label {
            font-size: .7rem;
            color: rgba(255,255,255,.7);
            text-transform: uppercase;
            letter-spacing: .8px;
            display: block;
            margin-bottom: 2px;
        }

        .student-id-badge .id-value {
            font-size: 1.1rem;
            font-weight: 700;
            color: #fff;
            font-family: 'Courier New', monospace;
            letter-spacing: 1px;
        }

        .id-loading {
            display: inline-block;
            width: 12px;
            height: 12px;
            border: 2px solid rgba(255,255,255,.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin .8s linear infinite;
            vertical-align: middle;
            margin-right: 4px;
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

        .section-header i {
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

        /* ── Form Body ───────────────────────────────────────────── */
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

        .form-group.span-2 {
            grid-column: span 2;
        }

        label {
            font-size: .82rem;
            font-weight: 600;
            color: #3a4a3c;
            letter-spacing: .2px;
        }

        label .req {
            color: var(--red);
            margin-left: 2px;
        }

        label .opt {
            font-weight: 400;
            color: #8a9a8b;
            font-size: .75rem;
            margin-left: 4px;
        }

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

        input[readonly] {
            background: #f0f4f1;
            color: #6b7c6d;
            cursor: not-allowed;
            border-style: dashed;
        }

        textarea {
            resize: vertical;
            min-height: 80px;
        }

        /* ── Photo Upload ─────────────────────────────────────────── */
        .photo-upload-row {
            display: flex;
            gap: 20px;
            align-items: flex-start;
            flex-wrap: wrap;
        }

        .photo-preview {
            width: 96px;
            height: 96px;
            border-radius: 50%;
            border: 3px solid var(--g400);
            background: var(--g50);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
        }

        .photo-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: none;
        }

        .photo-preview i {
            color: #b0c4b1;
            font-size: 2.2rem;
        }

        .photo-upload-info {
            flex: 1;
        }

        .photo-upload-info p {
            font-size: .8rem;
            color: #6b7c6d;
            margin-top: 6px;
            line-height: 1.5;
        }

        .btn-upload {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 18px;
            background: var(--g50);
            border: 1.5px dashed var(--g600);
            border-radius: var(--radius);
            color: var(--g800);
            font-size: .85rem;
            font-weight: 600;
            cursor: pointer;
            transition: all var(--transition);
        }

        .btn-upload:hover {
            background: var(--g100);
            border-color: var(--g800);
        }

        input[type="file"] { display: none; }

        /* ── Subject Combination hint ──────────────────────────────── */
        .subject-hint {
            font-size: .75rem;
            color: #8a9a8b;
            margin-top: 4px;
        }

        /* ── Form Actions ─────────────────────────────────────────── */
        .form-actions {
            background: #fff;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            padding: 20px 24px;
            display: flex;
            justify-content: flex-end;
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

        /* ── Validation States ────────────────────────────────────── */
        .is-invalid {
            border-color: var(--red) !important;
            box-shadow: 0 0 0 3px rgba(211,47,47,.1) !important;
        }

        .is-valid {
            border-color: var(--g600) !important;
        }

        .field-error {
            font-size: .76rem;
            color: var(--red);
            display: flex;
            align-items: center;
            gap: 4px;
            margin-top: 2px;
            display: none;
        }

        .field-error.show { display: flex; }

        /* ── Toast Notifications ──────────────────────────────────── */
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
            max-width: 360px;
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

        .toast.success {
            background: #e8f5e9;
            color: #1b5e20;
            border-left: 4px solid var(--g700);
        }

        .toast.error {
            background: #ffebee;
            color: #b71c1c;
            border-left: 4px solid var(--red);
        }

        .toast.warning {
            background: #fff8e1;
            color: #e65100;
            border-left: 4px solid #ffa000;
        }

        @keyframes slideInRight {
            from { opacity: 0; transform: translateX(30px); }
            to   { opacity: 1; transform: translateX(0); }
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        @keyframes fadeOut {
            to { opacity: 0; transform: translateX(10px); }
        }

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
                <h1><i class="fa-solid fa-user-plus" style="margin-right:10px;opacity:.85"></i>New Student Registration</h1>
                <p>Fill in all required fields. Ensure information is accurate before submitting.</p>
            </div>
            <div class="student-id-badge">
                <span class="id-label">Auto-generated ID</span>
                <span class="id-value" id="id-display">
                    <span class="id-loading"></span> Loading&hellip;
                </span>
            </div>
        </div>

        <form id="registration-form" enctype="multipart/form-data" novalidate>
            <!-- CSRF Token -->
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <!-- Hidden student ID field (submitted with form) -->
            <input type="hidden" id="studentId" name="studentId">

            <!-- ── Personal Information ─────────────────────────────── -->
            <div class="form-card">
                <div class="section-header">
                    <i class="fa-solid fa-circle-user"></i>
                    <div>
                        <h2>Personal Information</h2>
                        <p>Basic identity details of the student</p>
                    </div>
                </div>
                <div class="section-body">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="firstName">First Name <span class="req">*</span></label>
                            <input type="text" id="firstName" name="firstName" placeholder="e.g. Amara" autocomplete="given-name" required>
                            <span class="field-error" id="err-firstName"><i class="fa-solid fa-circle-exclamation"></i><span></span></span>
                        </div>
                        <div class="form-group">
                            <label for="lastName">Last Name <span class="req">*</span></label>
                            <input type="text" id="lastName" name="lastName" placeholder="e.g. Nakato" autocomplete="family-name" required>
                            <span class="field-error" id="err-lastName"><i class="fa-solid fa-circle-exclamation"></i><span></span></span>
                        </div>
                        <div class="form-group">
                            <label for="dateOfBirth">Date of Birth <span class="opt">(optional)</span></label>
                            <input type="date" id="dateOfBirth" name="dateOfBirth">
                            <span class="field-error" id="err-dateOfBirth"><i class="fa-solid fa-circle-exclamation"></i><span></span></span>
                        </div>
                        <div class="form-group">
                            <label for="gender">Gender <span class="req">*</span></label>
                            <select id="gender" name="gender" required>
                                <option value="">Select gender&hellip;</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                            <span class="field-error" id="err-gender"><i class="fa-solid fa-circle-exclamation"></i><span></span></span>
                        </div>
                        <div class="form-group">
                            <label for="nationality">Nationality <span class="req">*</span></label>
                            <input type="text" id="nationality" name="nationality" placeholder="e.g. Ugandan" required>
                            <span class="field-error" id="err-nationality"><i class="fa-solid fa-circle-exclamation"></i><span></span></span>
                        </div>
                        <div class="form-group">
                            <label for="religion">Religious Affiliation <span class="opt">(optional)</span></label>
                            <select id="religion" name="religion">
                                <option value="">Select&hellip;</option>
                                <option value="Christianity">Christianity</option>
                                <option value="Islam">Islam</option>
                                <option value="Hinduism">Hinduism</option>
                                <option value="Buddhism">Buddhism</option>
                                <option value="Judaism">Judaism</option>
                                <option value="Sikhism">Sikhism</option>
                                <option value="Other">Other</option>
                                <option value="None">Prefer not to say</option>
                            </select>
                        </div>
                    </div>

                    <!-- Profile Photo -->
                    <div style="margin-top:22px;">
                        <label style="display:block;margin-bottom:10px;">Profile Photo <span class="opt">(optional &mdash; JPG, PNG, GIF &le;2 MB)</span></label>
                        <div class="photo-upload-row">
                            <div class="photo-preview" id="photo-preview">
                                <img id="photo-img" src="" alt="Preview">
                                <i class="fa-regular fa-circle-user" id="photo-placeholder"></i>
                            </div>
                            <div class="photo-upload-info">
                                <label for="profilePhoto" class="btn-upload">
                                    <i class="fa-solid fa-upload"></i> Choose Photo
                                </label>
                                <input type="file" id="profilePhoto" name="profilePhoto" accept="image/jpeg,image/png,image/gif">
                                <p>Recommended: square image, at least 200&times;200 px. The photo will be resized automatically.</p>
                                <span class="field-error" id="err-profilePhoto" style="margin-top:6px;"><i class="fa-solid fa-circle-exclamation"></i><span></span></span>
                            </div>
                        </div>
                    </div>

                    <!-- Residential Address -->
                    <div class="form-group" style="margin-top:18px;">
                        <label for="residentialAddress">Residential Address <span class="opt">(optional)</span></label>
                        <textarea id="residentialAddress" name="residentialAddress" placeholder="Street / Village, District, Region&hellip;" rows="2"></textarea>
                    </div>
                </div>
            </div>

            <!-- ── Parent / Guardian Information ───────────────────── -->
            <div class="form-card">
                <div class="section-header">
                    <i class="fa-solid fa-people-roof"></i>
                    <div>
                        <h2>Parent / Guardian Information</h2>
                        <p>Contact details for the student&rsquo;s guardian</p>
                    </div>
                </div>
                <div class="section-body">
                    <div class="form-grid">
                        <div class="form-group span-2">
                            <label for="parentName">Full Name <span class="opt">(optional)</span></label>
                            <input type="text" id="parentName" name="parentName" placeholder="e.g. John Ssekandi" autocomplete="off">
                        </div>
                        <div class="form-group">
                            <label for="parentOccupation">Occupation <span class="opt">(optional)</span></label>
                            <input type="text" id="parentOccupation" name="parentOccupation" placeholder="e.g. Teacher">
                        </div>
                        <div class="form-group">
                            <label for="parentPhone">Phone Number <span class="opt">(optional)</span></label>
                            <input type="tel" id="parentPhone" name="parentPhone" placeholder="e.g. +256 700 000000" autocomplete="tel">
                            <span class="field-error" id="err-parentPhone"><i class="fa-solid fa-circle-exclamation"></i><span></span></span>
                        </div>
                        <div class="form-group span-2">
                            <label for="parentEmail">Email Address <span class="opt">(optional)</span></label>
                            <input type="email" id="parentEmail" name="parentEmail" placeholder="e.g. parent@email.com" autocomplete="email">
                            <span class="field-error" id="err-parentEmail"><i class="fa-solid fa-circle-exclamation"></i><span></span></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Academic Information ─────────────────────────────── -->
            <div class="form-card">
                <div class="section-header">
                    <i class="fa-solid fa-book-open-reader"></i>
                    <div>
                        <h2>Academic Information</h2>
                        <p>Class placement and academic history</p>
                    </div>
                </div>
                <div class="section-body">
                    <div class="form-grid cols-3">
                        <div class="form-group">
                            <label for="currentClass">Current Class <span class="req">*</span></label>
                            <select id="currentClass" name="currentClass" required>
                                <option value="">Select class&hellip;</option>
                                <option value="Senior One">Senior One</option>
                                <option value="Senior Two">Senior Two</option>
                                <option value="Senior Three">Senior Three</option>
                                <option value="Senior Four">Senior Four</option>
                                <option value="Senior Five">Senior Five</option>
                                <option value="Senior Six">Senior Six</option>
                            </select>
                            <span class="field-error" id="err-currentClass"><i class="fa-solid fa-circle-exclamation"></i><span></span></span>
                        </div>
                        <div class="form-group">
                            <label for="stream">Stream <span class="req">*</span></label>
                            <select id="stream" name="stream" required>
                                <option value="">Select stream&hellip;</option>
                                <option value="East">East</option>
                                <option value="West">West</option>
                                <option value="South">South</option>
                                <option value="North">North</option>
                                <option value="Arts">Arts</option>
                                <option value="Sciences">Sciences</option>
                            </select>
                            <span class="field-error" id="err-stream"><i class="fa-solid fa-circle-exclamation"></i><span></span></span>
                        </div>
                        <div class="form-group">
                            <label for="section">Section <span class="req">*</span></label>
                            <select id="section" name="section" required>
                                <option value="">Select section&hellip;</option>
                                <option value="Day">Day</option>
                                <option value="Boarding">Boarding</option>
                            </select>
                            <span class="field-error" id="err-section"><i class="fa-solid fa-circle-exclamation"></i><span></span></span>
                        </div>
                        <div class="form-group">
                            <label for="schoolPayCode">School Pay Code <span class="opt">(optional)</span></label>
                            <input type="text" id="schoolPayCode" name="schoolPayCode" placeholder="e.g. SP-00123">
                        </div>
                        <div class="form-group">
                            <label for="dateOfEnrolment">Date of Enrolment <span class="opt">(optional)</span></label>
                            <input type="date" id="dateOfEnrolment" name="dateOfEnrolment">
                            <span class="field-error" id="err-dateOfEnrolment"><i class="fa-solid fa-circle-exclamation"></i><span></span></span>
                        </div>
                        <div class="form-group">
                            <label for="previousSchool">Previous School <span class="opt">(optional)</span></label>
                            <input type="text" id="previousSchool" name="previousSchool" placeholder="e.g. Kampala Parents School">
                        </div>
                        <!-- Subject Combination — free text as requested -->
                        <div class="form-group span-2">
                            <label for="subjectCombination">Subject Combination <span class="opt">(optional)</span></label>
                            <input type="text" id="subjectCombination" name="subjectCombination"
                                placeholder="e.g. PCM, HEG, MEE, or type your own…" maxlength="120">
                            <p class="subject-hint">
                                <i class="fa-solid fa-circle-info" style="color:var(--g600)"></i>
                                Common examples: PCM (Physics, Chemistry, Maths) &bull; HEG (History, Economics, Geography) &bull; PCB &bull; MEE &bull; BCM
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Actions ──────────────────────────────────────────── -->
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" id="btn-cancel">
                    <i class="fa-solid fa-xmark"></i> Cancel
                </button>
                <button type="button" class="btn btn-secondary" id="btn-reset">
                    <i class="fa-solid fa-rotate-left"></i> Reset Form
                </button>
                <button type="submit" class="btn btn-primary" id="btn-submit">
                    <i class="fa-solid fa-user-plus"></i> Register Student
                </button>
            </div>
        </form>
    </div><!-- /.page -->

    <script>
    (function () {
        'use strict';

        /* ── Toast ─────────────────────────────────────────────── */
        const toastContainer = document.getElementById('toast-container');

        function showToast(message, type = 'success', duration = 5000) {
            const icons = { success: 'fa-circle-check', error: 'fa-circle-xmark', warning: 'fa-triangle-exclamation' };
            const t = document.createElement('div');
            t.className = `toast ${type}`;
            t.innerHTML = `<i class="fa-solid ${icons[type] || icons.success}"></i><span>${message}</span>`;
            toastContainer.appendChild(t);
            setTimeout(() => {
                t.style.animation = 'fadeOut .4s ease forwards';
                t.addEventListener('animationend', () => t.remove());
            }, duration);
        }

        /* ── Student ID fetch ──────────────────────────────────── */
        const idDisplay  = document.getElementById('id-display');
        const idHidden   = document.getElementById('studentId');

        async function fetchStudentId() {
            idDisplay.innerHTML = '<span class="id-loading"></span> Loading&hellip;';
            idHidden.value = '';
            try {
                const r = await fetch('api/generate_student_id.php');
                if (!r.ok) throw new Error(`HTTP ${r.status}`);
                const d = await r.json();
                if (d.status === 'success') {
                    idHidden.value  = d.studentId;
                    idDisplay.textContent = d.studentId;
                } else {
                    throw new Error(d.message || 'ID generation failed');
                }
            } catch (err) {
                // Make the badge clickable so the user can retry without refreshing.
                idDisplay.innerHTML = '<span style="cursor:pointer;text-decoration:underline" title="Click to retry">Error — click to retry</span>';
                idDisplay.style.cursor = 'pointer';
                idDisplay.onclick = () => { idDisplay.onclick = null; idDisplay.style.cursor = ''; fetchStudentId(); };
                showToast('Could not generate a Student ID. Click the ID badge to retry.', 'error');
            }
        }

        fetchStudentId();

        /* ── Photo preview ─────────────────────────────────────── */
        const photoInput       = document.getElementById('profilePhoto');
        const photoImg         = document.getElementById('photo-img');
        const photoPlaceholder = document.getElementById('photo-placeholder');

        photoInput.addEventListener('change', function () {
            const file = this.files[0];
            if (!file) return;

            // Client-side size guard
            if (file.size > 2 * 1024 * 1024) {
                showToast('Photo is too large (max 2 MB).', 'error');
                this.value = '';
                return;
            }

            const reader = new FileReader();
            reader.onload = e => {
                photoImg.src = e.target.result;
                photoImg.style.display = 'block';
                photoPlaceholder.style.display = 'none';
            };
            reader.readAsDataURL(file);
        });

        /* ── Field error helpers ───────────────────────────────── */
        function setError(id, msg) {
            const el = document.getElementById(`err-${id}`);
            const input = document.getElementById(id);
            if (el) {
                el.querySelector('span').textContent = msg;
                el.classList.toggle('show', !!msg);
            }
            if (input) {
                input.classList.toggle('is-invalid', !!msg);
                input.classList.toggle('is-valid', !msg && input.value.trim() !== '');
            }
        }

        function clearError(id) { setError(id, ''); }

        /* ── Inline validation ─────────────────────────────────── */
        const validations = {
            firstName:      v => v.length < 2 || v.length > 50 ? 'First name must be 2–50 characters.' : '',
            lastName:       v => v.length < 2 || v.length > 50 ? 'Last name must be 2–50 characters.' : '',
            gender:         v => !v ? 'Please select a gender.' : '',
            nationality:    v => !v ? 'Nationality is required.' : '',
            currentClass:   v => !v ? 'Please select a class.' : '',
            stream:         v => !v ? 'Please select a stream.' : '',
            section:        v => !v ? 'Please select a section.' : '',
            dateOfBirth:    v => {
                if (!v) return '';
                const d = new Date(v);
                if (isNaN(d)) return 'Invalid date.';
                if (d > new Date()) return 'Date of birth cannot be in the future.';
                return '';
            },
            dateOfEnrolment: v => {
                if (!v) return '';
                const d = new Date(v);
                if (isNaN(d)) return 'Invalid date.';
                if (d > new Date()) return 'Enrolment date cannot be in the future.';
                return '';
            },
            parentEmail: v => {
                if (!v) return '';
                return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v) ? '' : 'Invalid email address.';
            },
            parentPhone: v => {
                if (!v) return '';
                return /^\+?[\d\s\-()]{10,15}$/.test(v.replace(/[\s()-]/g, '')) ? '' : 'Invalid phone number (10–15 digits).';
            },
        };

        const form = document.getElementById('registration-form');

        // Attach blur listeners for live feedback
        Object.keys(validations).forEach(id => {
            const el = document.getElementById(id);
            if (!el) return;
            el.addEventListener('blur', () => {
                setError(id, validations[id](el.value.trim()));
            });
            el.addEventListener('input', () => {
                if (el.classList.contains('is-invalid')) {
                    setError(id, validations[id](el.value.trim()));
                }
            });
        });

        /* ── Full form validation ──────────────────────────────── */
        function validateAll() {
            let valid = true;
            Object.keys(validations).forEach(id => {
                const el = document.getElementById(id);
                if (!el) return;
                const msg = validations[id](el.value.trim());
                setError(id, msg);
                if (msg) valid = false;
            });
            return valid;
        }

        /* ── Submit ────────────────────────────────────────────── */
        const submitBtn = document.getElementById('btn-submit');

        form.addEventListener('submit', async function (e) {
            e.preventDefault();

            if (!validateAll()) {
                showToast('Please correct the highlighted errors before submitting.', 'warning');
                const first = form.querySelector('.is-invalid');
                if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }

            // Guard: ensure student ID is properly generated
            if (!idHidden.value || idHidden.value.includes('TEMP')) {
                showToast('Student ID is not ready. Please wait or refresh the page.', 'error');
                return;
            }

            // Lock UI
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="btn-spinner"></span> Registering…';

            try {
                const fd = new FormData(form);

                const res = await fetch('api/process_registration.php', {
                    method: 'POST',
                    body: fd,
                });

                if (!res.ok) {
                    // Do NOT include raw server response text in the message —
                    // it may leak PHP error details even through output buffering.
                    throw new Error(`Registration failed (${res.status}). Please try again.`);
                }

                const data = await res.json();

              if (data.status === 'success') {
    showToast(data.message || 'Student registered successfully!', 'success', 6000);
    resetForm();
    if (data.csrf_token) {
        form.querySelector('input[name="csrf_token"]').value = data.csrf_token;
    }
    fetchStudentId();
} else {
    showToast(data.message || 'Registration failed. Please try again.', 'error');

    if (data.errors && typeof data.errors === 'object') {
        Object.entries(data.errors).forEach(([field, msg]) => setError(field, msg));
        const first = form.querySelector('.is-invalid');
        if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
}
            } catch (err) {
                showToast('Network error: ' + err.message, 'error');
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fa-solid fa-user-plus"></i> Register Student';
            }
        });

        /* ── Reset helper ──────────────────────────────────────── */
        function resetForm() {
            form.reset();
            // Clear all validation state
            form.querySelectorAll('.is-invalid, .is-valid').forEach(el => {
                el.classList.remove('is-invalid', 'is-valid');
            });
            form.querySelectorAll('.field-error').forEach(el => el.classList.remove('show'));
            // Reset photo preview
            photoImg.src = '';
            photoImg.style.display = 'none';
            photoPlaceholder.style.display = '';
        }

        document.getElementById('btn-reset').addEventListener('click', () => {
            if (confirm('Reset the form? All entered data will be lost.')) resetForm();
        });

        document.getElementById('btn-cancel').addEventListener('click', () => {
            if (confirm('Cancel registration and go back?')) {
                window.location.href = 'view_students.php';
            }
        });

    })();
    </script>
</body>
</html>