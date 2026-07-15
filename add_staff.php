<?php
require_once "auth.php";
require_once 'tracking.php';
$tracker->trackAction("Add new staff");

// CSRF token generation (consistent with add_student.php)
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
    <title>New Staff Registration &mdash; School Pilot</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        /* ── Variables ────────────────────────────────────────────── */
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

        .staff-id-badge {
            background: rgba(255,255,255,.13);
            border: 1px solid rgba(255,255,255,.3);
            border-radius: 40px;
            padding: 10px 22px;
            text-align: center;
        }

        .staff-id-badge .id-label {
            font-size: .7rem;
            color: rgba(255,255,255,.7);
            text-transform: uppercase;
            letter-spacing: .8px;
            display: block;
            margin-bottom: 2px;
        }

        .staff-id-badge .id-value {
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

        .photo-upload-info { flex: 1; }

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

        .btn-upload:hover { background: var(--g100); border-color: var(--g800); }

        input[type="file"] { display: none; }

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

        .toast.success { background: #e8f5e9; color: #1b5e20; border-left: 4px solid var(--g700); }
        .toast.error   { background: #ffebee; color: #b71c1c; border-left: 4px solid var(--red); }
        .toast.warning { background: #fff8e1; color: #e65100; border-left: 4px solid #ffa000; }

        @keyframes slideInRight {
            from { opacity: 0; transform: translateX(30px); }
            to   { opacity: 1; transform: translateX(0); }
        }

        @keyframes spin { to { transform: rotate(360deg); } }

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
                <h1><i class="fa-solid fa-user-tie" style="margin-right:10px;opacity:.85"></i>New Staff Registration</h1>
                <p>Fill in all required fields. Ensure information is accurate before submitting.</p>
            </div>
            <div class="staff-id-badge">
                <span class="id-label">Auto-generated ID</span>
                <span class="id-value" id="id-display">
                    <span class="id-loading"></span> Loading&hellip;
                </span>
            </div>
        </div>

        <form id="staff-registration-form" enctype="multipart/form-data" novalidate>
            <!-- CSRF Token -->
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <!-- Hidden staff ID field (submitted with form, authoritative ID assigned server-side) -->
            <input type="hidden" id="staffId" name="staffId">

            <!-- ── Personal Information ─────────────────────────────── -->
            <div class="form-card">
                <div class="section-header">
                    <i class="fa-solid fa-circle-user"></i>
                    <div>
                        <h2>Personal Information</h2>
                        <p>Basic identity details of the staff member</p>
                    </div>
                </div>
                <div class="section-body">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="firstName">First Name <span class="req">*</span></label>
                            <input type="text" id="firstName" name="firstName" placeholder="e.g. Sarah" autocomplete="given-name" required>
                            <span class="field-error" id="err-firstName"><i class="fa-solid fa-circle-exclamation"></i><span></span></span>
                        </div>
                        <div class="form-group">
                            <label for="lastName">Last Name <span class="req">*</span></label>
                            <input type="text" id="lastName" name="lastName" placeholder="e.g. Nakamatte" autocomplete="family-name" required>
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
                            <label for="phoneNumber">Phone Number <span class="req">*</span></label>
                            <input type="tel" id="phoneNumber" name="phoneNumber" placeholder="e.g. +256 700 000000" autocomplete="tel" required>
                            <span class="field-error" id="err-phoneNumber"><i class="fa-solid fa-circle-exclamation"></i><span></span></span>
                        </div>
                        <div class="form-group">
                            <label for="maritalStatus">Marital Status <span class="opt">(optional)</span></label>
                            <select id="maritalStatus" name="maritalStatus">
                                <option value="">Select&hellip;</option>
                                <option value="single">Single</option>
                                <option value="married">Married</option>
                                <option value="divorced">Divorced</option>
                                <option value="widowed">Widowed</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="nationality">Nationality <span class="req">*</span></label>
                            <input type="text" id="nationality" name="nationality" placeholder="e.g. Ugandan" required>
                            <span class="field-error" id="err-nationality"><i class="fa-solid fa-circle-exclamation"></i><span></span></span>
                        </div>
                        <div class="form-group">
                            <label for="email">Email Address <span class="req">*</span></label>
                            <input type="email" id="email" name="email" placeholder="e.g. staff@email.com" autocomplete="email" required>
                            <span class="field-error" id="err-email"><i class="fa-solid fa-circle-exclamation"></i><span></span></span>
                        </div>
                    </div>

                    <!-- Profile Photo -->
                    <div style="margin-top: 20px;">
                        <label style="margin-bottom:10px;display:block;">Profile Photo <span class="opt">(optional — max 2 MB, JPEG/PNG/WebP)</span></label>
                        <div class="photo-upload-row">
                            <div class="photo-preview">
                                <img id="photo-img" src="" alt="Preview">
                                <i class="fa-solid fa-user" id="photo-placeholder"></i>
                            </div>
                            <div class="photo-upload-info">
                                <label for="profilePhoto" class="btn-upload">
                                    <i class="fa-solid fa-upload"></i> Choose Photo
                                </label>
                                <input type="file" id="profilePhoto" name="profilePhoto" accept="image/jpeg,image/png,image/webp">
                                <p>Accepted formats: JPEG, PNG, WebP. Maximum size: 2 MB.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Address -->
                    <div class="form-group" style="margin-top:18px;">
                        <label for="address">Residential Address <span class="req">*</span></label>
                        <textarea id="address" name="address" placeholder="Street / Village, District, Region&hellip;" rows="2" required></textarea>
                        <span class="field-error" id="err-address"><i class="fa-solid fa-circle-exclamation"></i><span></span></span>
                    </div>
                </div>
            </div>

            <!-- ── Professional Information ─────────────────────────── -->
            <div class="form-card">
                <div class="section-header">
                    <i class="fa-solid fa-briefcase"></i>
                    <div>
                        <h2>Professional Information</h2>
                        <p>Role, department and employment details</p>
                    </div>
                </div>
                <div class="section-body">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="designation">Designation <span class="req">*</span></label>
                            <select id="designation" name="designation" required>
                                <option value="">Select designation&hellip;</option>
                                <option value="Teacher">Teacher</option>
                                <option value="Principal">Principal</option>
                                <option value="Head Teacher">Head Teacher</option>
                                <option value="Deputy Principal">Deputy Principal</option>
                                <option value="Deputy Head Teacher">Deputy Head Teacher</option>
                                <option value="Head of Department">Head of Department</option>
                                <option value="Head of Studies">Head of Studies</option>
                                <option value="Head of Studies A Level">Head of Studies A Level</option>
                                <option value="Head of Studies O Level">Head of Studies O Level</option>
                                <option value="Admin">Administrator</option>
                                <option value="Librarian">Librarian</option>
                                <option value="Accountant">Accountant</option>
                                <option value="Secretary">Secretary</option>
                                <option value="Support Staff">Support Staff</option>
                            </select>
                            <span class="field-error" id="err-designation"><i class="fa-solid fa-circle-exclamation"></i><span></span></span>
                        </div>
                        <div class="form-group">
                            <label for="department">Department <span class="req">*</span></label>
                            <select id="department" name="department" required>
                                <option value="">Select department&hellip;</option>
                                <option value="Science">Science</option>
                                <option value="Arts">Arts</option>
                                <option value="IT">Information Technology</option>
                                <option value="Administration">Administration</option>
                                <option value="Support">Support Services</option>
                            </select>
                            <span class="field-error" id="err-department"><i class="fa-solid fa-circle-exclamation"></i><span></span></span>
                        </div>
                        <div class="form-group">
                            <label for="joiningDate">Joining Date <span class="req">*</span></label>
                            <input type="date" id="joiningDate" name="joiningDate" required>
                            <span class="field-error" id="err-joiningDate"><i class="fa-solid fa-circle-exclamation"></i><span></span></span>
                        </div>
                        <div class="form-group">
                            <label for="employmentType">Employment Type <span class="req">*</span></label>
                            <select id="employmentType" name="employmentType" required>
                                <option value="">Select type&hellip;</option>
                                <option value="full_time">Full-time</option>
                                <option value="part_time">Part-time</option>
                                <option value="contract">Contract</option>
                                <option value="temporary">Temporary</option>
                            </select>
                            <span class="field-error" id="err-employmentType"><i class="fa-solid fa-circle-exclamation"></i><span></span></span>
                        </div>
                        <div class="form-group">
                            <label for="qualifications">Qualifications <span class="req">*</span></label>
                            <select id="qualifications" name="qualifications" required>
                                <option value="">Select qualifications&hellip;</option>
                                <option value="Certificate">Certificate</option>
                                <option value="Diploma Arts">Diploma Arts</option>
                                <option value="Diploma Sciences">Diploma Sciences</option>
                                <option value="Degree Arts">Degree Arts</option>
                                <option value="Degree Sciences">Degree Sciences</option>
                                <option value="Postgraduate Diploma">Postgraduate Diploma</option>
                                <option value="Masters">Masters</option>
                                <option value="PhD">PhD</option>
                            </select>
                            <span class="field-error" id="err-qualifications"><i class="fa-solid fa-circle-exclamation"></i><span></span></span>
                        </div>
                        <div class="form-group">
                            <label for="experience">Experience <span class="opt">(years, optional)</span></label>
                            <input type="number" id="experience" name="experience" min="0" max="60" placeholder="e.g. 5">
                            <span class="field-error" id="err-experience"><i class="fa-solid fa-circle-exclamation"></i><span></span></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Official Documents ───────────────────────────────── -->
            <div class="form-card">
                <div class="section-header">
                    <i class="fa-solid fa-id-card"></i>
                    <div>
                        <h2>Official Documents</h2>
                        <p>Government-issued identification numbers</p>
                    </div>
                </div>
                <div class="section-body">
                    <div class="form-grid cols-3">
                        <div class="form-group">
                            <label for="nin">National ID (NIN) <span class="req">*</span></label>
                            <input type="text" id="nin" name="nin" placeholder="e.g. CM90100658KDEU" required>
                            <span class="field-error" id="err-nin"><i class="fa-solid fa-circle-exclamation"></i><span></span></span>
                        </div>
                        <div class="form-group">
                            <label for="tin">TIN Number <span class="opt">(optional)</span></label>
                            <input type="text" id="tin" name="tin" placeholder="e.g. 1234567890">
                        </div>
                        <div class="form-group">
                            <label for="nssf">NSSF Number <span class="opt">(optional)</span></label>
                            <input type="text" id="nssf" name="nssf" placeholder="e.g. 1234567890">
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
                    <i class="fa-solid fa-user-plus"></i> Register Staff
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
            const icons = {
                success: 'fa-circle-check',
                error:   'fa-circle-xmark',
                warning: 'fa-triangle-exclamation',
            };
            const t = document.createElement('div');
            t.className = `toast ${type}`;
            t.innerHTML = `<i class="fa-solid ${icons[type] || icons.success}"></i><span>${message}</span>`;
            toastContainer.appendChild(t);
            setTimeout(() => {
                t.style.animation = 'fadeOut .4s ease forwards';
                t.addEventListener('animationend', () => t.remove());
            }, duration);
        }

        /* ── Staff ID fetch ──────────────────────────────────── */
        const idDisplay = document.getElementById('id-display');
        const idHidden  = document.getElementById('staffId');

        async function fetchStaffId() {
            idDisplay.innerHTML = '<span class="id-loading"></span> Loading&hellip;';
            idHidden.value = '';
            try {
                const r = await fetch('api/generate_staff_id.php');
                if (!r.ok) throw new Error(`HTTP ${r.status}`);
                const d = await r.json();
                if (d.status === 'success') {
                    idHidden.value     = d.staffId;
                    idDisplay.textContent = d.staffId;
                } else {
                    throw new Error(d.message || 'ID generation failed');
                }
            } catch (err) {
                idDisplay.innerHTML = '<span style="cursor:pointer;text-decoration:underline" title="Click to retry">Error &mdash; click to retry</span>';
                idDisplay.style.cursor = 'pointer';
                idDisplay.onclick = () => {
                    idDisplay.onclick = null;
                    idDisplay.style.cursor = '';
                    fetchStaffId();
                };
                showToast('Could not generate a Staff ID. Click the ID badge to retry.', 'error');
            }
        }

        fetchStaffId();

        /* ── Photo preview ─────────────────────────────────────── */
        const photoInput       = document.getElementById('profilePhoto');
        const photoImg         = document.getElementById('photo-img');
        const photoPlaceholder = document.getElementById('photo-placeholder');

        photoInput.addEventListener('change', function () {
            const file = this.files[0];
            if (!file) return;

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
            const el    = document.getElementById(`err-${id}`);
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

        /* ── Inline validation rules ───────────────────────────── */
        const validations = {
            firstName: v => v.length < 2 || v.length > 80
                ? 'First name must be 2–80 characters.' : '',
            lastName: v => v.length < 2 || v.length > 80
                ? 'Last name must be 2–80 characters.' : '',
            gender:         v => !v ? 'Please select a gender.' : '',
            phoneNumber:    v => !v ? 'Phone number is required.'
                : !/^\+?[\d\s\-()]{7,20}$/.test(v) ? 'Invalid phone number.' : '',
            nationality:    v => !v ? 'Nationality is required.' : '',
            email:          v => !v ? 'Email is required.'
                : !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v) ? 'Invalid email address.' : '',
            address:        v => !v ? 'Address is required.' : '',
            designation:    v => !v ? 'Please select a designation.' : '',
            department:     v => !v ? 'Please select a department.' : '',
            joiningDate:    v => {
                if (!v) return 'Joining date is required.';
                const d = new Date(v);
                return isNaN(d) ? 'Invalid date.' : '';
            },
            employmentType: v => !v ? 'Please select an employment type.' : '',
            qualifications: v => !v ? 'Please select a qualification.' : '',
            nin:            v => !v ? 'National ID (NIN) is required.' : '',
            dateOfBirth:    v => {
                if (!v) return '';
                const d = new Date(v);
                if (isNaN(d)) return 'Invalid date.';
                if (d > new Date()) return 'Date of birth cannot be in the future.';
                return '';
            },
            experience: v => {
                if (!v) return '';
                const n = parseInt(v, 10);
                return isNaN(n) || n < 0 || n > 60 ? 'Experience must be 0–60 years.' : '';
            },
        };

        const form = document.getElementById('staff-registration-form');

        // Attach blur/input listeners for live feedback
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

            // Guard: ensure staff ID is properly fetched from server
            if (!idHidden.value) {
                showToast('Staff ID is not ready. Please wait or click the ID badge to retry.', 'error');
                return;
            }

            // Lock UI during submission
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="btn-spinner"></span> Registering&hellip;';

            try {
                const fd = new FormData(form);

                const res = await fetch('api/process_staff_registration.php', {
                    method: 'POST',
                    body: fd,
                });

                if (!res.ok && res.status !== 422) {
                    throw new Error(`Registration failed (${res.status}). Please try again.`);
                }

                const data = await res.json();

                if (data.status === 'success') {
                    showToast(data.message || 'Staff member registered successfully!', 'success', 6000);
                    resetForm();
                    fetchStaffId(); // pre-fetch next preview ID
                } else {
                    showToast(data.message || 'Registration failed. Please try again.', 'error');

                    // Highlight server-reported field errors
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
                submitBtn.innerHTML = '<i class="fa-solid fa-user-plus"></i> Register Staff';
            }
        });

        /* ── Reset helper ──────────────────────────────────────── */
        function resetForm() {
            form.reset();
            form.querySelectorAll('.is-invalid, .is-valid').forEach(el => {
                el.classList.remove('is-invalid', 'is-valid');
            });
            form.querySelectorAll('.field-error').forEach(el => el.classList.remove('show'));
            photoImg.src = '';
            photoImg.style.display = 'none';
            photoPlaceholder.style.display = '';
        }

        document.getElementById('btn-reset').addEventListener('click', () => {
            if (confirm('Reset the form? All entered data will be lost.')) resetForm();
        });

        document.getElementById('btn-cancel').addEventListener('click', () => {
            if (confirm('Cancel registration and go back?')) {
                window.location.href = 'view_staff.php';
            }
        });

    })();
    </script>
</body>
</html>