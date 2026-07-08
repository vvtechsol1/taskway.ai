<?php
/** Brain Dump — paste rough notes, let Taskway build the task list. */
$ACTIVE = 'braindump';
$PAGE_TITLE = 'Brain Dump';
$PAGE_SUB = 'Paste your notes — Taskway turns them into tasks';

$engine = setting('ai_provider') === 'claude' && trim((string)setting('claude_api_key')) !== '' ? 'Claude AI' : 'Smart parser';
$projects = get_projects();

require __DIR__ . '/../partials/header.php';
?>

<div class="grid cols-3">
  <div class="span-2" style="display:flex;flex-direction:column;gap:20px">
    <!-- Input -->
    <div class="card card-pad animate">
      <div class="card-head">
        <h3>🧠 Dump your notes</h3>
        <span class="badge in_progress" title="Active engine"><span class="dot"></span><?= esc($engine) ?></span>
      </div>

      <div class="row wrap mb-4" style="gap:10px">
        <div class="tabs" id="modeTabs">
          <button type="button" class="on" data-mode="done">✅ Things I did</button>
          <button type="button" data-mode="todo">📝 Things to do</button>
        </div>
        <select class="select" id="defaultProject" style="width:auto;min-width:180px">
          <option value="">Auto-detect project</option>
          <?php foreach ($projects as $p): ?>
            <option value="<?= esc($p['name']) ?>"><?= esc($p['icon']) ?> <?= esc($p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <textarea id="brainText" class="textarea brain-input" placeholder="Paste anything — a rough log, a to-do list, a standup update…

Casebazar:
- fixed checkout crash 2h urgent
- built new product filter sidebar 3h
- working on payment gateway integration

SEO project
researched competitor keywords 1.5h
updated landing page copy 45m

Taskway reads status (done / working / blocked), time (2h, 45m),
projects (#tags or headings) and priority (urgent, !!) automatically."></textarea>

      <div class="row between mt-4 wrap" style="gap:10px">
        <span class="small muted" id="charCount">0 characters</span>
        <div class="row" style="gap:10px">
          <button class="btn btn-ghost" id="clearBtn">Clear</button>
          <button class="btn btn-primary btn-lg" id="parseBtn">✨ Parse into tasks</button>
        </div>
      </div>
    </div>

    <!-- Preview -->
    <div class="card card-pad animate hidden" id="previewCard">
      <div class="card-head">
        <h3>👀 Review &amp; confirm</h3>
        <span class="badge" id="previewCount">0 tasks</span>
        <div class="card-action row" style="gap:8px">
          <button class="btn btn-ghost btn-sm" id="selectAll">Toggle all</button>
          <button class="btn btn-primary" id="commitBtn">Add tasks →</button>
        </div>
      </div>
      <p class="small muted mb-4">Edit anything before adding. Untick a row to skip it. Projects are created automatically.</p>
      <div id="previewList"></div>
      <div class="row between mt-6" style="border-top:1px solid var(--border);padding-top:16px">
        <span class="small muted" id="previewSummary"></span>
        <button class="btn btn-primary btn-lg" id="commitBtn2">✅ Add selected tasks</button>
      </div>
    </div>
  </div>

  <!-- Tips -->
  <div class="animate d1" style="display:flex;flex-direction:column;gap:20px">
    <div class="card card-pad">
      <div class="card-head"><h3>💡 How it reads</h3></div>
      <div style="display:flex;flex-direction:column;gap:12px" class="small">
        <div><span class="badge done">done</span> <span class="muted">done, fixed, shipped, ✅, [x]</span></div>
        <div><span class="badge in_progress">doing</span> <span class="muted">working on, wip, started, 🚧</span></div>
        <div><span class="badge blocked">blocked</span> <span class="muted">blocked, stuck, waiting</span></div>
        <div class="divider" style="margin:4px 0"></div>
        <div><strong>⏱ Time</strong> <span class="muted">2h · 1.5h · 45m · 2h30m</span></div>
        <div><strong>📁 Project</strong> <span class="muted">A heading line, <code>#tag</code>, or [Name]</span></div>
        <div><strong>🔥 Priority</strong> <span class="muted">urgent, asap, !! , important</span></div>
        <div><strong>✨ Type</strong> <span class="muted">built→New, fixed→Fix, updated→Improve</span></div>
      </div>
    </div>
    <div class="card card-pad" style="background:var(--primary-soft);border:0">
      <strong>🌐 Urdu / Roman-Urdu → English</strong>
      <p class="small dim mt-2" style="margin:0">Notes Roman-Urdu mein likhein — titles khudbakhud English mein ban jayenge. Jaise <em>"checkout ka bug theek kiya"</em> → <strong>"Fix checkout bug"</strong>.
      <?php if ($engine === 'Claude AI'): ?> Claude AI on hai — best-quality translation.<?php else: ?> Aur behtar/mushkil jumlon ki translation ke liye <a href="<?= page_url('settings') ?>" style="color:var(--primary);font-weight:700">Settings</a> se Claude AI on karein.<?php endif; ?></p>
    </div>
    <div class="card card-pad" style="background:var(--primary-soft);border:0">
      <strong>Tip</strong>
      <p class="small dim mt-2" style="margin:0">Group tasks by writing a project name on its own line, then list the work beneath it. Everything gets filed under that project.</p>
    </div>
  </div>
</div>

<script>
(function () {
  let mode = 'done';
  const $ = (id) => document.getElementById(id);
  const text = $('brainText'), preview = $('previewCard'), list = $('previewList');

  $('modeTabs').addEventListener('click', (e) => {
    const b = e.target.closest('button'); if (!b) return;
    mode = b.dataset.mode;
    $('modeTabs').querySelectorAll('button').forEach((x) => x.classList.toggle('on', x === b));
  });
  text.addEventListener('input', () => { $('charCount').textContent = text.value.length + ' characters'; });
  $('clearBtn').addEventListener('click', () => { text.value = ''; text.dispatchEvent(new Event('input')); preview.classList.add('hidden'); text.focus(); });

  const STATUSES = { todo: 'To do', in_progress: 'Doing', done: 'Done', blocked: 'Blocked' };
  const TYPES = { feature: '✨ New Build', improvement: '🔧 Improvement', bug: '🐞 Fix', research: '🔎 Research', task: '• Task' };

  function fmtMin(m) { if (!m) return ''; return m >= 60 ? (Math.round(m / 6) / 10) + 'h' : m + 'm'; }

  function row(t, i) {
    const el = document.createElement('div');
    el.className = 'task';
    el.dataset.i = i;
    el.innerHTML = `
      <input type="checkbox" class="incl" checked style="width:20px;height:20px;margin-top:4px;accent-color:var(--primary);cursor:pointer">
      <div class="task-main" style="display:grid;gap:8px">
        <input class="input pv-title" value="${escapeHtml(t.title)}" style="font-weight:600;padding:8px 11px">
        <div class="row wrap" style="gap:8px">
          <input class="input pv-project" placeholder="No project" value="${escapeHtml(t.project_name || '')}" style="width:150px;padding:6px 10px;font-size:12.5px">
          <select class="select pv-status" style="width:auto;padding:6px 10px;font-size:12.5px">${opts(STATUSES, t.status)}</select>
          <select class="select pv-type" style="width:auto;padding:6px 10px;font-size:12.5px">${opts(TYPES, t.type)}</select>
          <input class="input pv-time" value="${fmtMin(t.spent_min || t.estimate_min)}" placeholder="0h" style="width:64px;padding:6px 10px;font-size:12.5px" title="Time (e.g. 2h, 45m)">
          ${t.priority === 'urgent' || t.priority === 'high' ? `<span class="badge ${t.priority}">${t.priority}</span>` : ''}
        </div>
      </div>`;
    el.querySelector('.incl').addEventListener('change', updateSummary);
    return el;
  }
  function opts(map, sel) { return Object.entries(map).map(([k, v]) => `<option value="${k}"${k === sel ? ' selected' : ''}>${v}</option>`).join(''); }
  function escapeHtml(s) { return (s || '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c])); }

  function updateSummary() {
    const rows = [...list.querySelectorAll('.task')];
    const on = rows.filter((r) => r.querySelector('.incl').checked);
    $('previewCount').textContent = on.length + ' task' + (on.length === 1 ? '' : 's');
    const projs = new Set(on.map((r) => r.querySelector('.pv-project').value.trim()).filter(Boolean));
    $('previewSummary').textContent = `${on.length} tasks · ${projs.size} project${projs.size === 1 ? '' : 's'}`;
  }

  $('parseBtn').addEventListener('click', async () => {
    if (!text.value.trim()) { TW.toast('Paste some notes first', 'info'); return; }
    const btn = $('parseBtn'); btn.disabled = true; btn.textContent = '✨ Parsing…';
    try {
      const r = await TW.api('parse', { text: text.value, default_status: mode, project: $('defaultProject').value });
      list.innerHTML = '';
      if (!r.tasks.length) { TW.toast('Could not find tasks — try one item per line', 'info'); return; }
      r.tasks.forEach((t, i) => list.appendChild(row(t, i)));
      preview.classList.remove('hidden');
      updateSummary();
      preview.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      TW.toast(`Found ${r.tasks.length} tasks via ${r.engine === 'claude' ? 'Claude AI' : 'smart parser'}`);
    } catch (err) { TW.toast(err.message, 'err'); }
    finally { btn.disabled = false; btn.textContent = '✨ Parse into tasks'; }
  });

  $('selectAll').addEventListener('click', () => {
    const boxes = [...list.querySelectorAll('.incl')];
    const anyOff = boxes.some((b) => !b.checked);
    boxes.forEach((b) => b.checked = anyOff);
    updateSummary();
  });

  function parseTime(s) {
    s = (s || '').toLowerCase(); let m = 0;
    const h = s.match(/([\d.]+)\s*h/); if (h) m += Math.round(parseFloat(h[1]) * 60);
    const mm = s.match(/(\d+)\s*m/); if (mm) m += parseInt(mm[1]);
    if (!h && !mm && parseFloat(s)) m = Math.round(parseFloat(s) * 60);
    return m;
  }

  async function commit() {
    const rows = [...list.querySelectorAll('.task')].filter((r) => r.querySelector('.incl').checked);
    if (!rows.length) { TW.toast('Select at least one task', 'info'); return; }
    const tasks = rows.map((r) => {
      const status = r.querySelector('.pv-status').value;
      const mins = parseTime(r.querySelector('.pv-time').value);
      return {
        title: r.querySelector('.pv-title').value,
        project_name: r.querySelector('.pv-project').value,
        status, type: r.querySelector('.pv-type').value,
        priority: 'normal',
        spent_min: status === 'done' ? mins : 0,
        estimate_min: status === 'done' ? 0 : mins
      };
    });
    [$('commitBtn'), $('commitBtn2')].forEach((b) => b.disabled = true);
    try {
      const r = await TW.api('commit', { tasks });
      TW.toast(`Added ${r.created} tasks 🎉`);
      list.innerHTML = '';
      preview.innerHTML = `<div class="empty"><span class="emoji">🎉</span><h4>${r.created} tasks added!</h4>
        <p>${r.new_projects ? r.new_projects + ' new project(s) created. ' : ''}They're on your dashboard now.</p>
        <div class="row" style="justify-content:center;gap:10px;margin-top:14px">
          <a href="${window.TASKWAY.base}/index.php?page=dashboard" class="btn btn-primary">Go to dashboard</a>
          <a href="${window.TASKWAY.base}/index.php?page=tasks" class="btn btn-ghost">View tasks</a>
        </div></div>`;
      text.value = ''; text.dispatchEvent(new Event('input'));
    } catch (err) { TW.toast(err.message, 'err'); [$('commitBtn'), $('commitBtn2')].forEach((b) => b.disabled = false); }
  }
  $('commitBtn').addEventListener('click', commit);
  $('commitBtn2').addEventListener('click', commit);
  list.addEventListener('input', (e) => { if (e.target.classList.contains('pv-project')) updateSummary(); });
})();
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>
