<?php
/**
 * Add_visit.php — REDESIGNED
 * Design aligned with view_students.php (Sen font, green CSS vars).
 * CSRF token included in form submission.
 */

require_once "conn.php";
require_once "auth.php";
require_once 'tracking.php';
$tracker->trackAction("Add Sickbay Visit");

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
<title>Add Visit — School Pilot</title>
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

/* Page Header */
.page-header{background:linear-gradient(135deg,var(--g900) 0%,var(--g700) 100%);border-radius:var(--radius-lg);padding:26px 30px;margin-bottom:24px;box-shadow:var(--shadow-lg);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
.page-header h1{color:#fff;font-size:1.4rem;font-weight:700;display:flex;align-items:center;gap:10px}
.page-header p{color:rgba(255,255,255,.75);font-size:.88rem;margin-top:3px}
.btn-back{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25);color:#fff;padding:8px 16px;border-radius:var(--radius);font-size:.82rem;font-weight:600;font-family:inherit;cursor:pointer;display:flex;align-items:center;gap:7px;transition:background var(--transition)}
.btn-back:hover{background:rgba(255,255,255,.25)}

/* Card */
.card{background:#fff;border-radius:var(--radius-lg);box-shadow:var(--shadow);padding:0;margin-bottom:20px;overflow:hidden}
.card-head{background:var(--g50);border-bottom:1px solid #e0ebe1;padding:14px 22px;display:flex;align-items:center;gap:9px;font-size:.9rem;font-weight:700;color:var(--g800)}
.card-head i{opacity:.7}
.card-body{padding:20px 22px}

/* Form */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
@media(max-width:600px){.form-grid{grid-template-columns:1fr}}
.fg{display:flex;flex-direction:column;gap:5px}
.fg.span2{grid-column:1/-1}
.fg label{font-size:.78rem;font-weight:700;color:var(--g800);text-transform:uppercase;letter-spacing:.4px;display:flex;align-items:center;gap:5px}
.required{color:var(--red)}
.fg input,.fg select,.fg textarea{padding:9px 12px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;font-family:inherit;background:#fff;transition:border-color var(--transition)}
.fg input:focus,.fg select:focus,.fg textarea:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.12)}
.fg input[readonly]{background:#f5f5f5;color:var(--gray);cursor:default}
.fg textarea{resize:vertical;min-height:80px}

/* Checkbox group */
.cb-group{display:flex;flex-wrap:wrap;gap:10px;margin-top:4px}
.cb-item{display:flex;align-items:center;gap:7px;background:var(--g100);border:1.5px solid #d0dbd1;border-radius:var(--radius);padding:7px 12px;cursor:pointer;font-size:.85rem;transition:border-color var(--transition)}
.cb-item:hover{border-color:var(--g600)}
.cb-item input[type=checkbox]{accent-color:var(--g700);width:15px;height:15px}

/* Toggle switch */
.toggle-wrap{display:flex;align-items:center;gap:12px;font-size:.88rem;font-weight:600;color:var(--g800)}
.toggle{position:relative;display:inline-block;width:46px;height:25px;flex-shrink:0}
.toggle input{display:none}
.toggle-slider{position:absolute;inset:0;background:#ccc;border-radius:25px;cursor:pointer;transition:background .3s}
.toggle-slider:before{content:'';position:absolute;height:19px;width:19px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:transform .3s}
.toggle input:checked+.toggle-slider{background:var(--g700)}
.toggle input:checked+.toggle-slider:before{transform:translateX(21px)}

/* Med rows */
.med-row{display:grid;grid-template-columns:1fr 1fr auto auto;gap:10px;align-items:end;margin-bottom:12px}
@media(max-width:600px){.med-row{grid-template-columns:1fr 1fr;gap:8px}}
.med-row .remove-med{width:34px;height:34px;border:none;border-radius:var(--radius);background:var(--red-bg);color:var(--red);cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all var(--transition);flex-shrink:0}
.med-row .remove-med:hover{background:var(--red);color:#fff}

/* Action bar */
.action-bar{display:flex;justify-content:flex-end;gap:12px;margin-top:8px}
.btn{display:inline-flex;align-items:center;gap:7px;padding:10px 20px;border:none;border-radius:var(--radius);font-size:.9rem;font-weight:700;font-family:inherit;cursor:pointer;transition:all var(--transition)}
.btn:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.15)}
.btn-primary{background:var(--g700);color:#fff}.btn-primary:hover{background:var(--g800)}
.btn-outline{background:transparent;color:var(--gray);border:1.5px solid #d0dbd1}.btn-outline:hover{border-color:var(--gray);background:#f5f5f5;transform:none}
.btn-sm{padding:7px 14px;font-size:.8rem}
.btn-add-med{background:var(--g100);color:var(--g800);border:1.5px solid var(--g400)}.btn-add-med:hover{background:var(--g100);border-color:var(--g700);transform:none}

/* Notification */
#notif-stack{position:fixed;top:20px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:8px;pointer-events:none}
.notif{display:flex;align-items:flex-start;gap:10px;padding:12px 16px;border-radius:var(--radius);box-shadow:var(--shadow-lg);min-width:240px;max-width:340px;pointer-events:all;background:#fff;border-left:4px solid var(--g600);animation:slideIn .25s ease}
@keyframes slideIn{from{transform:translateX(30px);opacity:0}to{transform:none;opacity:1}}
.notif.error{border-left-color:var(--red)}
.notif.warning{border-left-color:var(--orange)}
.notif-icon{font-size:1rem;margin-top:1px}
.notif.success .notif-icon{color:var(--g600)}
.notif.error .notif-icon{color:var(--red)}
.notif-body{flex:1;font-size:.82rem}
.notif-title{font-weight:700}
.notif-msg{color:var(--gray)}
.notif-close{background:none;border:none;cursor:pointer;color:#aaa;font-size:.8rem;padding:0;margin-left:4px}
.hidden{display:none!important}

/* ── Searchable student dropdown ── */
.student-picker{position:relative;width:100%}
.student-picker .sp-display{
  display:flex;align-items:center;justify-content:space-between;
  padding:9px 12px;border:1.5px solid #d0dbd1;border-radius:var(--radius);
  font-size:.875rem;font-family:inherit;background:#fff;cursor:pointer;
  transition:border-color var(--transition);user-select:none;min-height:38px
}
.student-picker .sp-display:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.12)}
.student-picker .sp-display.open{border-color:var(--g600);border-bottom-left-radius:0;border-bottom-right-radius:0;box-shadow:0 0 0 3px rgba(67,160,71,.12)}
.student-picker .sp-display .sp-placeholder{color:#aaa}
.student-picker .sp-display .sp-chevron{color:#888;font-size:.75rem;transition:transform .2s;flex-shrink:0;margin-left:8px}
.student-picker .sp-display.open .sp-chevron{transform:rotate(180deg)}

.student-picker .sp-panel{
  position:absolute;top:100%;left:0;right:0;z-index:999;
  background:#fff;border:1.5px solid var(--g600);
  border-top:none;border-bottom-left-radius:var(--radius);border-bottom-right-radius:var(--radius);
  box-shadow:0 6px 20px rgba(0,0,0,.12)
}
.student-picker .sp-search-wrap{padding:8px 10px;border-bottom:1px solid #e8f0e9}
.student-picker .sp-search{
  width:100%;padding:7px 10px 7px 32px;border:1.5px solid #d0dbd1;border-radius:var(--radius);
  font-size:.85rem;font-family:inherit;background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%23888' stroke-width='2.5'%3E%3Ccircle cx='11' cy='11' r='8'/%3E%3Cline x1='21' y1='21' x2='16.65' y2='16.65'/%3E%3C/svg%3E") no-repeat 9px center;
  transition:border-color var(--transition)
}
.student-picker .sp-search:focus{outline:none;border-color:var(--g600)}
.student-picker .sp-list{max-height:220px;overflow-y:auto}
.student-picker .sp-list::-webkit-scrollbar{width:6px}
.student-picker .sp-list::-webkit-scrollbar-track{background:transparent}
.student-picker .sp-list::-webkit-scrollbar-thumb{background:#c8ddc9;border-radius:3px}
.student-picker .sp-item{
  padding:9px 14px;font-size:.875rem;cursor:pointer;
  display:flex;flex-direction:column;gap:1px;
  border-bottom:1px solid #f0f4f0;transition:background .15s
}
.student-picker .sp-item:last-child{border-bottom:none}
.student-picker .sp-item:hover,.student-picker .sp-item.focused{background:var(--g100)}
.student-picker .sp-item.selected{background:var(--g100);font-weight:700;color:var(--g800)}
.student-picker .sp-item .sp-name{font-weight:600}
.student-picker .sp-item .sp-meta{font-size:.76rem;color:var(--gray)}
.student-picker .sp-empty{padding:14px;text-align:center;color:#aaa;font-size:.85rem}
</style>
</head>
<body>
<?php require_once 'nav.php' ?>
<div id="notif-stack"></div>

<div class="page">

  <header class="page-header">
    <div>
      <h1><i class="fas fa-clinic-medical"></i> Add Sick Bay Visit</h1>
      <p>Record a new student sick bay visit and treatments administered.</p>
    </div>
    <button class="btn-back" onclick="window.history.back()"><i class="fas fa-arrow-left"></i> Back</button>
  </header>

  <form id="visitForm">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

    <!-- Student Information -->
    <div class="card">
      <div class="card-head"><i class="fas fa-user-circle"></i> Student Information</div>
      <div class="card-body">
        <div class="form-grid">
          <div class="fg span2">
            <label>Student Name <span class="required">*</span></label>
            <input type="hidden" id="studentSel" name="student_id" required>
            <div class="student-picker" id="studentPicker">
              <div class="sp-display" id="spDisplay" tabindex="0" role="combobox" aria-expanded="false">
                <span id="spLabel" class="sp-placeholder">Loading students…</span>
                <i class="fas fa-chevron-down sp-chevron"></i>
              </div>
              <div class="sp-panel hidden" id="spPanel">
                <div class="sp-search-wrap">
                  <input class="sp-search" id="spSearch" type="text" placeholder="Search by name or ID…" autocomplete="off">
                </div>
                <div class="sp-list" id="spList" role="listbox"></div>
              </div>
            </div>
          </div>
          <div class="fg"><label>Student ID</label><input type="text" id="stdId" readonly placeholder="Auto-filled"></div>
          <div class="fg"><label>Class</label><input type="text" id="stdClass" readonly placeholder="Auto-filled"></div>
          <div class="fg"><label>Stream</label><input type="text" id="stdStream" readonly placeholder="Auto-filled"></div>
        </div>
      </div>
    </div>

    <!-- Visit Details -->
    <div class="card">
      <div class="card-head"><i class="fas fa-calendar-check"></i> Visit Details</div>
      <div class="card-body">
        <div class="form-grid">
          <div class="fg"><label for="visitDate">Date <span class="required">*</span></label><input type="date" id="visitDate" name="visitDate" required></div>
          <div class="fg"><label for="visitTime">Time <span class="required">*</span></label><input type="time" id="visitTime" name="visitTime" required></div>
        </div>
      </div>
    </div>

    <!-- Chief Complaint -->
    <div class="card">
      <div class="card-head"><i class="fas fa-notes-medical"></i> Chief Complaint / Symptoms</div>
      <div class="card-body">
        <div class="fg" style="margin-bottom:16px">
          <label>Common Symptoms</label>
          <div class="cb-group">
            <?php foreach(['Headache','Fever','Stomach ache','Nausea','Vomiting','Dizziness','Fatigue','Sore throat','Cough','Body aches','Eye pain','Injury/Wound'] as $s): ?>
            <label class="cb-item"><input type="checkbox" name="symptoms[]" value="<?= htmlspecialchars($s) ?>"> <?= htmlspecialchars($s) ?></label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="fg">
          <label>Other / Free Text</label>
          <textarea name="otherSymptoms" rows="2" placeholder="Any additional complaints not listed above…"></textarea>
        </div>
      </div>
    </div>

    <!-- Assessment & Vitals -->
    <div class="card">
      <div class="card-head"><i class="fas fa-heartbeat"></i> Assessment &amp; Vitals</div>
      <div class="card-body">
        <div class="form-grid">
          <div class="fg"><label>Temperature (°C)</label><input type="number" name="temperature" step="0.1" min="30" max="45" placeholder="e.g., 37.5"></div>
          <div class="fg"><label>Blood Pressure</label><input type="text" name="bloodPressure" placeholder="e.g., 120/80"></div>
          <div class="fg span2"><label>Assessment Notes</label><textarea name="assessmentNotes" rows="3" placeholder="Clinical assessment…"></textarea></div>
        </div>
      </div>
    </div>

    <!-- Treatment -->
    <div class="card">
      <div class="card-head"><i class="fas fa-pills"></i> Treatment</div>
      <div class="card-body">
        <div class="fg" style="margin-bottom:16px">
          <label>Treatment Notes</label>
          <textarea name="treatmentNotes" rows="2" placeholder="Describe treatment given…"></textarea>
        </div>
        <div class="fg" style="margin-bottom:16px">
          <label>Rest Time (minutes)</label>
          <input type="number" name="restTime" min="0" placeholder="e.g., 30">
        </div>

        <div style="margin-bottom:12px;font-size:.78rem;font-weight:700;color:var(--g800);text-transform:uppercase;letter-spacing:.4px">Medications Dispensed</div>
        <div id="medContainer"></div>
        <button type="button" class="btn btn-add-med btn-sm" id="addMedBtn"><i class="fas fa-plus"></i> Add Medication</button>
      </div>
    </div>

    <!-- Outcome -->
    <div class="card">
      <div class="card-head"><i class="fas fa-sign-out-alt"></i> Outcome</div>
      <div class="card-body">
        <div class="form-grid">
          <div class="fg">
            <label for="actionTaken">Action Taken <span class="required">*</span></label>
            <select id="actionTaken" name="actionTaken" required>
              <option value="">Select action…</option>
              <option value="ReturnedToClass">Returned to Class</option>
              <option value="SentHome">Sent Home</option>
              <option value="ReferredToHospital">Referred to Hospital</option>
              <option value="Admitted">Admitted to Sick Bay</option>
            </select>
          </div>
          <div class="fg">
            <label for="attendedBy">Attended By <span class="required">*</span></label>
            <input type="text" id="attendedBy" name="attendedBy" required placeholder="Nurse / Health Officer name">
          </div>
        </div>
      </div>
    </div>

    <!-- Parent Notification -->
    <div class="card">
      <div class="card-head"><i class="fas fa-phone"></i> Parent Notification</div>
      <div class="card-body">
        <div class="toggle-wrap" style="margin-bottom:14px">
          <label class="toggle"><input type="checkbox" name="parentNotified" id="parentNotified"><span class="toggle-slider"></span></label>
          Parent / Guardian Notified
        </div>
        <div id="parentNotesGroup" class="fg hidden">
          <label>Notification Notes</label>
          <textarea name="parentNotes" rows="2" placeholder="Who was contacted, what was communicated…"></textarea>
        </div>
      </div>
    </div>

    <!-- Follow-up -->
    <div class="card">
      <div class="card-head"><i class="fas fa-calendar-plus"></i> Follow-up</div>
      <div class="card-body">
        <div class="toggle-wrap" style="margin-bottom:14px">
          <label class="toggle"><input type="checkbox" name="followupRequired" id="followupRequired"><span class="toggle-slider"></span></label>
          Follow-up Required
        </div>
        <div id="followupSection" class="hidden">
          <div class="form-grid">
            <div class="fg"><label>Follow-up Date</label><input type="date" name="followupDate"></div>
            <div class="fg span2"><label>Follow-up Notes</label><textarea name="followupNotes" rows="2" placeholder="Instructions for follow-up…"></textarea></div>
          </div>
        </div>
      </div>
    </div>

    <div class="action-bar">
      <button type="button" class="btn btn-outline" onclick="window.history.back()"><i class="fas fa-times"></i> Cancel</button>
      <button type="submit" class="btn btn-primary" id="btnSubmit"><i class="fas fa-save"></i> Save Visit</button>
    </div>
  </form>

</div>

<script>
const CSRF = <?= json_encode($csrf) ?>;
let studentsData = [];

// ── Init defaults ─────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  const now = new Date();
  document.getElementById('visitDate').value = now.toISOString().split('T')[0];
  document.getElementById('visitTime').value = now.toTimeString().substring(0,5);

  fetchStudents();
  addMedRow();

  document.getElementById('parentNotified').addEventListener('change', function(){
    document.getElementById('parentNotesGroup').classList.toggle('hidden', !this.checked);
  });
  document.getElementById('followupRequired').addEventListener('change', function(){
    document.getElementById('followupSection').classList.toggle('hidden', !this.checked);
  });
  document.getElementById('addMedBtn').addEventListener('click', addMedRow);
  document.getElementById('visitForm').addEventListener('submit', submitForm);


});

// ── Searchable Student Picker ─────────────────────────────────────────────────
const spDisplay = document.getElementById('spDisplay');
const spPanel   = document.getElementById('spPanel');
const spSearch  = document.getElementById('spSearch');
const spList    = document.getElementById('spList');
const spLabel   = document.getElementById('spLabel');
const spHidden  = document.getElementById('studentSel');

let spOpen = false;
let filteredStudents = [];

function spRenderList(data) {
  if (!data.length) {
    spList.innerHTML = '<div class="sp-empty">No students found</div>';
    return;
  }
  spList.innerHTML = data.map(s => {
    const selected = s.student_id === spHidden.value ? ' selected' : '';
    return `<div class="sp-item${selected}" data-id="${esc(s.student_id)}" role="option">
      <span class="sp-name">${esc(s.full_name)}</span>
      <span class="sp-meta">${esc(s.student_id)}${s.current_class ? ' · ' + esc(s.current_class) : ''}${s.stream ? ' · ' + esc(s.stream) : ''}</span>
    </div>`;
  }).join('');
  spList.querySelectorAll('.sp-item').forEach(el => {
    el.addEventListener('mousedown', e => { e.preventDefault(); spSelect(el.dataset.id); });
  });
}

function spFilterAndRender() {
  const q = spSearch.value.toLowerCase().trim();
  filteredStudents = q
    ? studentsData.filter(s => s.full_name.toLowerCase().includes(q) || s.student_id.toLowerCase().includes(q))
    : studentsData;
  spRenderList(filteredStudents);
}

function spOpenPanel() {
  if (spOpen) return;
  spOpen = true;
  spDisplay.classList.add('open');
  spDisplay.setAttribute('aria-expanded', 'true');
  spPanel.classList.remove('hidden');
  spFilterAndRender();
  spSearch.value = '';
  spSearch.focus();
}

function spClosePanel() {
  if (!spOpen) return;
  spOpen = false;
  spDisplay.classList.remove('open');
  spDisplay.setAttribute('aria-expanded', 'false');
  spPanel.classList.add('hidden');
}

function spSelect(id) {
  const s = studentsData.find(x => x.student_id === id);
  if (!s) return;
  spHidden.value = s.student_id;
  spLabel.textContent = s.full_name + ' (' + s.student_id + ')';
  spLabel.classList.remove('sp-placeholder');
  document.getElementById('stdId').value    = s.student_id;
  document.getElementById('stdClass').value = s.current_class || '';
  document.getElementById('stdStream').value = s.stream || '';
  spClosePanel();
}

spDisplay.addEventListener('click', () => spOpen ? spClosePanel() : spOpenPanel());
spDisplay.addEventListener('keydown', e => {
  if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); spOpen ? spClosePanel() : spOpenPanel(); }
  if (e.key === 'Escape') spClosePanel();
});
spSearch.addEventListener('input', spFilterAndRender);
spSearch.addEventListener('keydown', e => { if (e.key === 'Escape') spClosePanel(); });
document.addEventListener('click', e => { if (spOpen && !document.getElementById('studentPicker').contains(e.target)) spClosePanel(); });

// ── Fetch students ────────────────────────────────────────────────────────────
async function fetchStudents() {
  try {
    const res    = await fetch('api/students.php');
    const result = await res.json();
    if (!result.success) throw new Error(result.message||'Failed to load students.');
    studentsData = result.data;
    spLabel.textContent = 'Select a student…';
    spLabel.classList.add('sp-placeholder');
    spRenderList(studentsData);
  } catch(e) {
    spLabel.textContent = 'Failed to load students';
    notify('Error', e.message, 'error');
  }
}

// ── Med rows ──────────────────────────────────────────────────────────────────
let medCount = 0;
function addMedRow() {
  medCount++;
  const row = document.createElement('div');
  row.className = 'med-row';
  row.innerHTML = `
    <div class="fg"><label>Medication</label><input type="text" name="medications[]" placeholder="Name"></div>
    <div class="fg"><label>Dosage</label><input type="text" name="dosages[]" placeholder="e.g., 1 tablet"></div>
    <div class="fg"><label>Time Given</label><input type="time" name="medicationTimes[]"></div>
    <div class="fg" style="justify-content:flex-end"><label style="opacity:0">-</label><button type="button" class="remove-med" onclick="this.closest('.med-row').remove()"><i class="fas fa-trash"></i></button></div>
  `;
  document.getElementById('medContainer').appendChild(row);
}

// ── Submit ────────────────────────────────────────────────────────────────────
async function submitForm(e) {
  e.preventDefault();
  const btn = document.getElementById('btnSubmit');
  btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

  try {
    const res    = await fetch('api/process_add_visit.php', {method:'POST', body: new FormData(e.target)});
    const result = await res.json();
    if (result.success) {
      notify('Saved', 'Visit recorded successfully!', 'success');
      e.target.reset();
      document.getElementById('stdId').value = '';
      document.getElementById('stdClass').value = '';
      document.getElementById('stdStream').value = '';
      spHidden.value = ''; spLabel.textContent = 'Select a student…'; spLabel.classList.add('sp-placeholder');
      document.getElementById('parentNotesGroup').classList.add('hidden');
      document.getElementById('followupSection').classList.add('hidden');
      document.getElementById('medContainer').innerHTML = '';
      addMedRow();
      const now = new Date();
      document.getElementById('visitDate').value = now.toISOString().split('T')[0];
      document.getElementById('visitTime').value = now.toTimeString().substring(0,5);
    } else {
      notify('Error', result.message, 'error');
    }
  } catch(err) {
    notify('Error', 'Request failed. Please try again.', 'error');
  } finally {
    btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Save Visit';
  }
}

// ── Notifications ─────────────────────────────────────────────────────────────
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
