<?php
/**
 * _marks_design_system.php
 * Shared CSS + JS utilities for the marks entry flow.
 * Include this once per page: require_once '_marks_design_system.php';
 * Then call marks_head_styles() in <head> and marks_notify_js() before </body>.
 */

function marks_head_styles(): void { ?>
<style>
/* ── Variables — mirrors view_students.php exactly ─────── */
:root{
  --g900:#1b5e20;--g800:#2e7d32;--g700:#388e3c;--g600:#43a047;
  --g400:#66bb6a;--g100:#e8f5e9;--g50:#f1f8f1;
  --red:#d32f2f;--orange:#e65100;--blue:#1565c0;--gray:#546e7a;
  --radius:8px;--radius-lg:12px;
  --shadow:0 2px 8px rgba(0,0,0,.10);
  --shadow-lg:0 8px 28px rgba(0,0,0,.14);
  --transition:.22s ease;
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:"Sen",system-ui,sans-serif;background:#f0f4f1;min-height:100vh;color:#222;font-size:14px;line-height:1.5}
a{color:inherit;text-decoration:none}

/* ── Page shell ─────────────────────────────────────────── */
.page{max-width:1600px;margin:0 auto;padding:24px 20px 64px}

/* ── Page header — identical to view_students ───────────── */
.page-header{background:linear-gradient(135deg,var(--g900) 0%,var(--g700) 100%);border-radius:var(--radius-lg);padding:28px 32px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:20px;margin-bottom:24px;margin-top:40px;box-shadow:var(--shadow-lg)}
.page-header-left h1{color:#fff;font-size:1.45rem;font-weight:700;letter-spacing:.3px}
.page-header-left p{color:rgba(255,255,255,.78);font-size:.875rem;margin-top:3px}

/* ── Breadcrumb steps ────────────────────────────────────── */
.steps{display:flex;align-items:center;gap:0}
.step{display:flex;align-items:center;gap:8px;padding:8px 18px;border-radius:40px;font-size:.78rem;font-weight:600;color:rgba(255,255,255,.55);white-space:nowrap}
.step.active{background:rgba(255,255,255,.18);color:#fff}
.step.done{color:rgba(255,255,255,.75)}
.step-num{width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:700;background:rgba(255,255,255,.2);flex-shrink:0}
.step.active .step-num{background:#fff;color:var(--g800)}
.step.done .step-num{background:var(--g400);color:#fff}
.step-arrow{color:rgba(255,255,255,.3);font-size:.7rem;margin:0 -4px}

/* ── Info pills (class, term, subject summary) ───────────── */
.context-bar{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:20px}
.ctx-pill{display:inline-flex;align-items:center;gap:6px;background:#fff;border:1.5px solid #d8e8d8;border-radius:40px;padding:6px 14px;font-size:.8rem;font-weight:600;color:var(--g800)}
.ctx-pill i{color:var(--g600);font-size:.75rem}

/* ── Card ───────────────────────────────────────────────── */
.card{background:#fff;border-radius:var(--radius-lg);box-shadow:var(--shadow);overflow:hidden}
.card-header{padding:18px 24px;border-bottom:1px solid #e8ede9;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
.card-title{font-size:1rem;font-weight:700;color:var(--g800);display:flex;align-items:center;gap:9px}
.card-title i{color:var(--g600)}
.card-body{padding:24px}

/* ── Toolbar ─────────────────────────────────────────────── */
.toolbar{padding:14px 24px;border-bottom:1px solid #e8ede9;display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.toolbar-left{display:flex;flex-wrap:wrap;gap:10px;align-items:center;flex:1 1 auto}
.toolbar-right{display:flex;gap:10px;align-items:center;flex-shrink:0}
.search-wrap{position:relative;min-width:220px}
.search-wrap i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#8a9a8b;font-size:.85rem}
.search-wrap input{width:100%;padding:9px 12px 9px 34px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.875rem;transition:border-color var(--transition),box-shadow var(--transition);font-family:inherit}
.search-wrap input:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.12)}
.badge-count{background:var(--g100);color:var(--g800);font-size:.72rem;font-weight:700;padding:3px 10px;border-radius:20px}

/* ── Buttons ─────────────────────────────────────────────── */
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border:none;border-radius:var(--radius);font-size:.85rem;font-weight:600;font-family:inherit;transition:all var(--transition);white-space:nowrap;cursor:pointer;text-decoration:none}
.btn:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.15)}
.btn:active{transform:none}
.btn-primary{background:var(--g700);color:#fff}.btn-primary:hover{background:var(--g800)}
.btn-outline{background:#fff;color:var(--gray);border:1.5px solid #d0dbd1}.btn-outline:hover{border-color:var(--gray);background:#f5f5f5;transform:none}
.btn-ghost{background:transparent;color:var(--gray);border:1.5px solid transparent}.btn-ghost:hover{background:#f0f4f1;border-color:#d0dbd1}
.btn-danger{background:#ffebee;color:var(--red);border:1.5px solid #ffcdd2}.btn-danger:hover{background:var(--red);color:#fff}
.btn-lg{padding:12px 28px;font-size:.925rem}
.btn:disabled,.btn.disabled{opacity:.45;cursor:default;pointer-events:none}

/* ── Form elements ───────────────────────────────────────── */
.form-group{margin-bottom:20px}
.form-label{display:block;font-size:.83rem;font-weight:600;color:#546e7a;margin-bottom:6px;letter-spacing:.2px}
.form-control,.form-select{width:100%;padding:10px 14px;border:1.5px solid #d0dbd1;border-radius:var(--radius);font-size:.9rem;font-family:inherit;color:#222;background:#fff;transition:border-color var(--transition),box-shadow var(--transition)}
.form-control:focus,.form-select:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.12)}
.form-control:hover,.form-select:hover{border-color:var(--g400)}
.form-hint{font-size:.75rem;color:#8a9a8b;margin-top:5px}

/* ── Table ───────────────────────────────────────────────── */
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
thead tr{background:linear-gradient(90deg,var(--g700) 0%,var(--g600) 100%)}
thead th{padding:13px 14px;text-align:left;font-size:.78rem;font-weight:600;color:#fff;letter-spacing:.4px;white-space:nowrap}
thead th.center{text-align:center}
tbody tr{border-bottom:1px solid #f0f4f1;transition:background var(--transition)}
tbody tr:hover{background:#f5fbf5}
tbody td{padding:12px 14px;font-size:.875rem;vertical-align:middle}
tbody td.center{text-align:center}

/* ── Mark input ──────────────────────────────────────────── */
.mark-input{width:74px;padding:7px 10px;border:1.5px solid #d0dbd1;border-radius:var(--radius);text-align:center;font-size:.9rem;font-weight:600;font-family:inherit;background:#fff;transition:all var(--transition)}
.mark-input:focus{outline:none;border-color:var(--g600);box-shadow:0 0 0 3px rgba(67,160,71,.12)}
.mark-input.has-value{background:var(--g50);border-color:var(--g400);color:var(--g900)}
.mark-input.invalid{border-color:var(--red);background:#fff8f8;color:var(--red)}
.mark-input.saving{opacity:.6}

/* ── Save status indicator ───────────────────────────────── */
.save-status{display:inline-flex;align-items:center;gap:6px;font-size:.78rem;font-weight:600;padding:5px 12px;border-radius:20px;transition:all var(--transition)}
.save-status.idle{background:#f5f5f5;color:#8a9a8b}
.save-status.saving{background:#fff3e0;color:#e65100}
.save-status.saved{background:var(--g100);color:var(--g800)}
.save-status.error{background:#ffebee;color:var(--red)}
.spin{animation:spin .8s linear infinite;display:inline-block}
@keyframes spin{to{transform:rotate(360deg)}}

/* ── Badges ──────────────────────────────────────────────── */
.badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.3px}
.badge-eot{background:#e3f2fd;color:#1565c0}
.badge-aoi{background:var(--g100);color:var(--g800)}
.badge-stream{background:rgba(255,255,255,.18);color:#fff;border:1px solid rgba(255,255,255,.3);font-size:.72rem;padding:3px 10px;border-radius:20px;font-weight:600}

/* ── Pagination ──────────────────────────────────────────── */
.pagination{padding:14px 24px;display:flex;align-items:center;justify-content:space-between;border-top:1px solid #e8ede9;flex-wrap:wrap;gap:10px}
.page-info{font-size:.8rem;color:#6b7c6d}
.page-btns{display:flex;gap:4px}
.page-btn{width:32px;height:32px;border:1.5px solid #d0dbd1;border-radius:6px;background:#fff;cursor:pointer;font-size:.82rem;font-weight:600;color:#444;display:flex;align-items:center;justify-content:center;transition:all var(--transition);font-family:inherit}
.page-btn:hover:not(:disabled){border-color:var(--g600);background:var(--g100);color:var(--g800)}
.page-btn.active{background:var(--g700);border-color:var(--g700);color:#fff}
.page-btn:disabled{opacity:.38;cursor:default}

/* ── Empty state ─────────────────────────────────────────── */
.empty-state{text-align:center;padding:56px 20px;color:#8a9a8b}
.empty-state i{font-size:2.6rem;margin-bottom:12px;display:block;opacity:.4}
.empty-state p{font-size:.9rem}

/* ── Checkbox topic item ─────────────────────────────────── */
.topic-item{padding:14px 18px;border-bottom:1px solid #f0f4f1;display:flex;align-items:flex-start;gap:12px;cursor:pointer;transition:background var(--transition)}
.topic-item:last-child{border-bottom:none}
.topic-item:hover{background:var(--g50)}
.topic-item input[type=checkbox]{width:17px;height:17px;accent-color:var(--g700);flex-shrink:0;margin-top:2px;cursor:pointer}
.topic-item-body{flex:1}
.topic-item-title{font-weight:600;color:#2c3e50;font-size:.875rem}
.topic-item-desc{font-size:.78rem;color:#6b7c6d;margin-top:3px}
.topic-item.eot-item{background:linear-gradient(135deg,#e3f2fd,#bbdefb20)}
.topic-item.eot-item .topic-item-title{color:#1565c0}

/* ── Divider label ───────────────────────────────────────── */
.divider-label{padding:10px 18px;font-size:.72rem;font-weight:700;letter-spacing:.6px;text-transform:uppercase;color:#8a9a8b;background:#fafafa;border-bottom:1px solid #f0f4f1}

/* ── Preview table inside right panel ───────────────────── */
.preview-wrap{padding:16px}
.preview-table{width:100%;border-collapse:collapse;font-size:.83rem}
.preview-table th{background:var(--g50);padding:9px 12px;text-align:left;font-size:.72rem;font-weight:700;letter-spacing:.4px;color:var(--g800);border-bottom:2px solid #d8e8d8}
.preview-table td{padding:9px 12px;border-bottom:1px solid #f0f4f1;vertical-align:middle}
.preview-table tr:last-child td{border-bottom:none}

/* ── Notifications ───────────────────────────────────────── */
#notif-stack{position:fixed;top:20px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:8px;pointer-events:none}
.notif{background:#fff;border-radius:var(--radius);box-shadow:0 4px 20px rgba(0,0,0,.15);padding:14px 16px;display:flex;align-items:flex-start;gap:12px;min-width:280px;max-width:380px;pointer-events:all;transition:opacity .3s,transform .3s;border-left:4px solid #ccc}
.notif.success{border-left-color:var(--g600)}
.notif.error{border-left-color:var(--red)}
.notif.warning{border-left-color:#f39c12}
.notif.info{border-left-color:var(--blue)}
.notif-icon{font-size:1rem;margin-top:1px;flex-shrink:0}
.notif.success .notif-icon{color:var(--g600)}
.notif.error .notif-icon{color:var(--red)}
.notif.warning .notif-icon{color:#f39c12}
.notif.info .notif-icon{color:var(--blue)}
.notif-body{flex:1}
.notif-title{font-weight:700;font-size:.83rem;color:#222}
.notif-msg{font-size:.78rem;color:#546e7a;margin-top:2px}
.notif-close{background:none;border:none;cursor:pointer;color:#aaa;font-size:.9rem;padding:0;line-height:1;flex-shrink:0}
.notif-close:hover{color:#555}

/* ── Responsive ──────────────────────────────────────────── */
@media(max-width:768px){
  .page{padding:16px 12px 48px}
  .page-header{padding:20px 18px;margin-top:56px}
  .steps{display:none}
  .two-col{flex-direction:column}
  .form-row{grid-template-columns:1fr}
}
</style>
<?php }

function marks_notify_js(): void { ?>
<div id="notif-stack"></div>
<script>
function notify(title, msg, type='success', dur=4000){
  const icons={success:'fa-circle-check',error:'fa-circle-xmark',warning:'fa-triangle-exclamation',info:'fa-circle-info'};
  const n=document.createElement('div');
  n.className=`notif ${type}`;
  n.innerHTML=`<i class="fas ${icons[type]||icons.info} notif-icon"></i>
    <div class="notif-body"><div class="notif-title">${escHtml(title)}</div><div class="notif-msg">${escHtml(msg)}</div></div>
    <button class="notif-close" onclick="this.closest('.notif').remove()"><i class="fas fa-times"></i></button>`;
  document.getElementById('notif-stack').prepend(n);
  setTimeout(()=>{n.style.opacity='0';n.style.transform='translateX(24px)';setTimeout(()=>n.remove(),320);},dur);
}
function escHtml(v){return String(v||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
</script>
<?php }

/**
 * Render the page header with step breadcrumb.
 * $step: 1 = sel_add_marks, 2 = sel_aoi_add_marks, 3 = add_marks
 */
function marks_page_header(string $title, string $subtitle, int $step, array $context=[]): void {
    $steps = [
        ['label'=>'Select Class & Subject','icon'=>'fa-list-check'],
        ['label'=>'Select Topics','icon'=>'fa-tags'],
        ['label'=>'Enter Marks','icon'=>'fa-pen-to-square'],
    ];
    ?>
    <div class="page-header">
        <div class="page-header-left">
            <h1><?= htmlspecialchars($title) ?></h1>
            <p><?= htmlspecialchars($subtitle) ?></p>
        </div>
        <div class="steps">
            <?php foreach($steps as $i=>$s): $n=$i+1; $cls=($n==$step)?'active':(($n<$step)?'done':''); ?>
                <?php if($i>0): ?><span class="step-arrow"><i class="fas fa-chevron-right"></i></span><?php endif; ?>
                <div class="step <?= $cls ?>">
                    <span class="step-num"><?= $n<$step?'<i class="fas fa-check" style="font-size:.6rem"></i>':$n ?></span>
                    <?= htmlspecialchars($s['label']) ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php if(!empty($context)): ?>
    <div class="context-bar">
        <?php foreach($context as $icon=>$val): ?>
            <span class="ctx-pill"><i class="fas <?= $icon ?>"></i> <?= htmlspecialchars($val) ?></span>
        <?php endforeach; ?>
    </div>
    <?php endif;
}