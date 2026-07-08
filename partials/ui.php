<?php
/**
 * Taskway — shared render helpers so every page (and every worker-built page)
 * draws tasks, badges and project cards identically.
 */

declare(strict_types=1);

function type_chip(string $type): string
{
    $m = TYPE_META[$type] ?? TYPE_META['task'];
    return '<span class="tag" style="color:' . esc($m['color']) . '">' . $m['icon'] . ' ' . esc($m['label']) . '</span>';
}

function priority_badge(string $priority): string
{
    if (!in_array($priority, ['high', 'urgent'], true)) return '';
    $m = PRIORITY_META[$priority];
    return '<span class="badge ' . esc($priority) . '"><span class="dot"></span>' . esc($m['label']) . '</span>';
}

function status_badge(string $status): string
{
    $m = STATUS_META[$status] ?? ['label' => ucfirst($status)];
    return '<span class="badge ' . esc($status) . '">' . esc($m['label']) . '</span>';
}

/** Segmented To do / Doing / Done control bound to the task. */
function status_seg(array $t): string
{
    $id = (int)$t['id'];
    $map = ['todo' => 'To do', 'in_progress' => 'Doing', 'done' => 'Done'];
    $out = '<div class="seg">';
    foreach ($map as $val => $label) {
        $on = $t['status'] === $val ? 'on' : '';
        $out .= '<button class="' . $on . '" data-v="' . $val . '" data-set-status="' . $val . '" data-id="' . $id . '">' . $label . '</button>';
    }
    return $out . '</div>';
}

/**
 * Render one task row. $opts: seg(bool, default true), delete(bool), remove_on_done(bool), show_date(bool)
 */
function render_task(array $t, array $opts = []): void
{
    $done = $t['status'] === 'done';
    $seg = $opts['seg'] ?? true;
    $showDate = $opts['show_date'] ?? false;
    $checkSvg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5L20 6"/></svg>';
    ?>
    <div class="task <?= $done ? 'is-done' : '' ?>" data-task="<?= (int)$t['id'] ?>" <?= !empty($opts['remove_on_done']) ? 'data-remove-on-done="1"' : '' ?>>
      <span class="pri-bar <?= esc($t['priority']) ?>"></span>
      <div class="task-check <?= $done ? 'checked' : '' ?>" data-check="<?= (int)$t['id'] ?>" title="Toggle complete"><?= $checkSvg ?></div>
      <div class="task-main">
        <div class="task-title"><?= esc($t['title']) ?></div>
        <div class="task-meta">
          <?php if (!empty($t['project_name'])): ?>
            <span class="tag" style="color:var(--text-2)"><span class="dot" style="background:<?= esc($t['project_color'] ?? '#6C5CE7') ?>"></span><?= esc($t['project_name']) ?></span>
          <?php endif; ?>
          <?= type_chip($t['type']) ?>
          <?= priority_badge($t['priority']) ?>
          <?php if ((int)$t['spent_min'] > 0): ?><span class="tag" style="color:var(--text-3)">⏱ <?= esc(fmt_min((int)$t['spent_min'])) ?></span><?php endif; ?>
          <?php if ((int)$t['spent_min'] === 0 && (int)$t['estimate_min'] > 0): ?><span class="tag muted">~<?= esc(fmt_min((int)$t['estimate_min'])) ?></span><?php endif; ?>
          <?php if ($showDate): ?><span class="tag muted"><?= esc(human_date($t['task_date'])) ?></span><?php endif; ?>
        </div>
      </div>
      <div class="task-side">
        <?php if ($seg): ?><?= status_seg($t) ?><?php endif; ?>
        <?php if (!empty($opts['timer'])): ?>
          <button class="icon-btn" data-timer="<?= (int)$t['id'] ?>" title="Start timer">⏱</button>
        <?php endif; ?>
        <?php if (!empty($opts['delete'])): ?>
          <button class="icon-btn" data-delete-task="<?= (int)$t['id'] ?>" title="Delete">🗑</button>
        <?php endif; ?>
      </div>
    </div>
    <?php
}

/** Compact project card with progress + hours. $opts['actions']=true adds an Edit/Delete menu. */
function render_project_card(array $p, array $opts = []): void
{
    $s = project_stats((int)$p['id']);
    $actions = !empty($opts['actions']);
    ?>
    <div class="card card-pad card-hover proj-card" style="position:relative;overflow:visible">
      <a href="<?= page_url('project', ['id' => $p['id']]) ?>" class="stretch-link" aria-label="Open <?= esc($p['name']) ?>"></a>
      <?php if ($actions): ?>
        <div class="proj-actions">
          <button type="button" class="icon-btn proj-kebab" data-proj-menu aria-label="Project options">⋯</button>
          <div class="proj-menu">
            <button type="button" onclick='projectEditorOpen("edit", <?= json_encode($p, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>)'>✏️ Edit</button>
            <button type="button" class="danger" onclick="projectDelete(<?= (int)$p['id'] ?>, <?= json_encode($p['name'], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>)">🗑️ Delete</button>
          </div>
        </div>
      <?php endif; ?>
      <div class="row" style="align-items:flex-start">
        <div class="t-ic" style="background:<?= esc($p['color']) ?>22;color:<?= esc($p['color']) ?>;font-size:20px;width:44px;height:44px;border-radius:13px;display:grid;place-items:center"><?= esc($p['icon'] ?: '📁') ?></div>
        <div class="grow">
          <div class="row between">
            <strong style="font-size:15px" class="truncate"><?= esc($p['name']) ?></strong>
            <?php if ($p['status'] !== 'active'): ?><span class="badge"><?= esc(ucfirst($p['status'])) ?></span><?php endif; ?>
          </div>
          <div class="small muted"><?= $s['done'] ?>/<?= $s['total'] ?> tasks · <?= esc(fmt_hours($s['spent_min'])) ?>h logged</div>
        </div>
      </div>
      <div class="row between mt-4" style="gap:10px">
        <div class="progress grow" style="flex:1"><i style="width:<?= $s['progress'] ?>%;background:<?= esc($p['color']) ?>"></i></div>
        <span class="small strong" style="color:<?= esc($p['color']) ?>"><?= $s['progress'] ?>%</span>
      </div>
      <?php if ($s['in_progress'] > 0): ?>
        <div class="small mt-2" style="color:var(--sky)">● <?= $s['in_progress'] ?> in progress</div>
      <?php endif; ?>
    </div>
    <?php
}

/** Shared "new / edit project" modal + JS. Include once per page that needs it. */
function render_project_editor(): void
{
    $first = PROJECT_PALETTE[0];
    ?>
    <div class="modal-back" id="projectModal">
      <div class="modal">
        <div class="modal-head"><h3 id="peTitle">📁 New project</h3>
          <button class="icon-btn" data-close-modal style="margin-left:auto" title="Close">✕</button>
        </div>
        <div class="modal-body">
          <input type="hidden" id="peId">
          <div class="field"><label class="fld" for="peName">Project name</label>
            <input class="input" id="peName" placeholder="e.g. Casebazar" autocomplete="off"></div>
          <div class="field">
            <div class="row" style="gap:14px;align-items:flex-start">
              <div style="flex:0 0 92px"><label class="fld" for="peIcon">Icon</label>
                <input class="input" id="peIcon" value="📁" maxlength="4" style="text-align:center;font-size:20px"></div>
              <div class="grow"><label class="fld">Colour</label>
                <div class="row wrap" id="peSwatches" style="gap:9px">
                  <?php foreach (PROJECT_PALETTE as $i => $hex): ?>
                    <button type="button" class="np-swatch <?= $i === 0 ? 'active' : '' ?>" data-color="<?= esc($hex) ?>"
                      style="width:30px;height:30px;border-radius:9px;border:2px solid transparent;cursor:pointer;background:<?= esc($hex) ?>"></button>
                  <?php endforeach; ?>
                </div>
                <input type="hidden" id="peColor" value="<?= esc($first) ?>">
              </div>
            </div>
          </div>
          <div class="field" id="peStatusField" style="display:none">
            <label class="fld" for="peStatus">Status</label>
            <select class="select" id="peStatus">
              <option value="active">🟢 Active</option>
              <option value="paused">⏸️ Paused</option>
              <option value="done">✅ Completed</option>
              <option value="archived">🗄️ Archived</option>
            </select>
          </div>
          <div class="field"><label class="fld" for="peDesc">Description <span class="muted">(optional)</span></label>
            <textarea class="textarea" id="peDesc" placeholder="What is this project about?" style="min-height:70px"></textarea></div>
        </div>
        <div class="modal-foot">
          <button class="btn btn-ghost" data-close-modal>Cancel</button>
          <button class="btn btn-primary" id="peSave" onclick="projectSave()">Create project</button>
        </div>
      </div>
    </div>
    <script>
    (function () {
      var PAL0 = <?= json_encode($first) ?>;
      var sw = document.getElementById('peSwatches'), colorInp = document.getElementById('peColor');
      sw.addEventListener('click', function (e) { var b = e.target.closest('.np-swatch'); if (!b) return; colorInp.value = b.getAttribute('data-color'); sw.querySelectorAll('.np-swatch').forEach(function (x) { x.classList.toggle('active', x === b); }); });

      window.projectEditorOpen = function (mode, data) {
        data = data || {};
        var edit = mode === 'edit';
        document.getElementById('peId').value = edit ? data.id : '';
        document.getElementById('peTitle').textContent = edit ? '✏️ Edit project' : '📁 New project';
        document.getElementById('peName').value = data.name || '';
        document.getElementById('peIcon').value = data.icon || '📁';
        document.getElementById('peDesc').value = data.description || '';
        var col = data.color || PAL0; colorInp.value = col;
        sw.querySelectorAll('.np-swatch').forEach(function (x) { x.classList.toggle('active', x.getAttribute('data-color') === col); });
        document.getElementById('peStatusField').style.display = edit ? '' : 'none';
        if (edit && data.status) document.getElementById('peStatus').value = data.status;
        document.getElementById('peSave').textContent = edit ? 'Save changes' : 'Create project';
        document.getElementById('projectModal').classList.add('open');
        setTimeout(function () { document.getElementById('peName').focus(); }, 50);
      };

      window.projectSave = async function () {
        var id = document.getElementById('peId').value;
        var name = document.getElementById('peName').value.trim();
        if (!name) { TW.toast('Give your project a name', 'info'); document.getElementById('peName').focus(); return; }
        var payload = { name: name, icon: document.getElementById('peIcon').value.trim() || '📁', color: colorInp.value, description: document.getElementById('peDesc').value.trim() };
        if (id) { payload.id = parseInt(id); payload.status = document.getElementById('peStatus').value; }
        var btn = document.getElementById('peSave'); btn.disabled = true;
        try {
          await TW.api(id ? 'update_project' : 'create_project', payload);
          TW.toast(id ? 'Project updated ✓' : 'Project created 🎉');
          setTimeout(function () { location.reload(); }, 450);
        } catch (err) { TW.toast(err.message, 'err'); btn.disabled = false; }
      };

      window.projectDelete = async function (id, name) {
        if (!confirm('Delete project "' + name + '"?\n\nIts tasks stay in your list but are no longer linked to this project.')) return;
        try {
          await TW.api('delete_project', { id: id });
          TW.toast('Project deleted');
          setTimeout(function () { location.href = <?= json_encode(page_url('projects')) ?>; }, 450);
        } catch (err) { TW.toast(err.message, 'err'); }
      };

      // Kebab menu toggle (delegated).
      document.addEventListener('click', function (e) {
        var k = e.target.closest('[data-proj-menu]');
        document.querySelectorAll('.proj-menu.open').forEach(function (m) { if (!k || m !== k.parentElement.querySelector('.proj-menu')) m.classList.remove('open'); });
        if (k) { e.preventDefault(); e.stopPropagation(); k.parentElement.querySelector('.proj-menu').classList.toggle('open'); }
      });
    })();
    </script>
    <?php
}

/** Activity feed item. */
function render_activity(array $a): void
{
    $icons = ['task_created' => '📝', 'task_done' => '✅', 'braindump' => '🧠', 'project_created' => '📁', 'timer' => '⏱'];
    $ic = $icons[$a['kind']] ?? '•';
    ?>
    <div class="feed-item">
      <div class="feed-dot"><?= $ic ?></div>
      <div class="feed-body">
        <div class="t truncate"><?= esc($a['title']) ?></div>
        <div class="ts"><?= esc(relative_time($a['created_at'])) ?></div>
      </div>
    </div>
    <?php
}
