<?php
/** Board — Kanban view. Drag tasks between To Do / Doing / Done. */
$ACTIVE = 'board';
$PAGE_TITLE = 'Board';
$PAGE_SUB = 'Drag tasks across columns to update them';

$proj      = (int)($_GET['project'] ?? 0);
$todayOnly = ($_GET['today'] ?? '') === '1';
$today     = date('Y-m-d');

$base = [];
if ($proj) $base['project_id'] = $proj;

// To Do column also holds blocked tasks; Doing = in_progress.
$todo  = get_tasks($base + ['status' => ['todo', 'blocked']] + ($todayOnly ? ['to' => $today] : []));
$doing = get_tasks($base + ['status' => ['in_progress']] + ($todayOnly ? ['to' => $today] : []));

// Done column: most-recent completions (today only, or last 7 days by default).
$doneSql = "SELECT t.*, p.name AS project_name, p.color AS project_color, p.icon AS project_icon
    FROM tasks t LEFT JOIN projects p ON p.id = t.project_id WHERE t.status = 'done' AND t.user_id = ? AND t.deleted_at IS NULL";
$doneArgs = [scope_uid()];
if ($proj) { $doneSql .= ' AND t.project_id = ?'; $doneArgs[] = $proj; }
if ($todayOnly) { $doneSql .= " AND date(COALESCE(t.completed_at, t.task_date)) = ?"; $doneArgs[] = $today; }
else { $doneSql .= " AND date(COALESCE(t.completed_at, t.task_date)) >= ?"; $doneArgs[] = date('Y-m-d', strtotime('-7 days')); }
$doneSql .= ' ORDER BY COALESCE(t.completed_at, t.task_date) DESC LIMIT 60';
$stmt = db()->prepare($doneSql);
$stmt->execute($doneArgs);
$done = $stmt->fetchAll();

$columns = [
    'todo'        => ['label' => 'To Do',  'list' => $todo,  'color' => STATUS_META['todo']['color']],
    'in_progress' => ['label' => 'Doing',  'list' => $doing, 'color' => STATUS_META['in_progress']['color']],
    'done'        => ['label' => 'Done',   'list' => $done,  'color' => STATUS_META['done']['color']],
];

$projects = get_projects();

$TOPBAR_ACTIONS = '<a href="' . page_url('board', $todayOnly ? ($proj ? ['project' => $proj] : []) : array_merge(['today' => 1], $proj ? ['project' => $proj] : []))
    . '" class="chip ' . ($todayOnly ? 'active' : '') . '">📅 Today</a>';

function kcard(array $t): void
{
    $done = $t['status'] === 'done';
    ?>
    <div class="kcard p-<?= esc($t['priority']) ?> <?= $done ? 'is-done' : '' ?>" draggable="true"
         data-id="<?= (int)$t['id'] ?>" data-status="<?= esc($t['status']) ?>">
      <div class="kt"><?= esc($t['title']) ?></div>
      <div class="km">
        <?php if (!empty($t['project_name'])): ?>
          <span class="tag" style="color:var(--text-2)"><span class="dot" style="background:<?= esc($t['project_color'] ?? '#6C5CE7') ?>"></span><?= esc($t['project_name']) ?></span>
        <?php endif; ?>
        <?= type_chip($t['type']) ?>
        <?php if ($t['status'] === 'blocked'): ?><span class="badge blocked">Blocked</span><?php endif; ?>
        <?php if ((int)$t['spent_min'] > 0): ?><span class="tag muted">⏱ <?= esc(fmt_min((int)$t['spent_min'])) ?></span><?php endif; ?>
      </div>
    </div>
    <?php
}

require __DIR__ . '/../partials/header.php';
?>

<div class="row wrap mb-6" style="gap:10px">
  <select class="select" style="width:auto;min-width:190px" onchange="location.href=this.value">
    <option value="<?= esc(page_url('board', $todayOnly ? ['today' => 1] : [])) ?>">All projects</option>
    <?php foreach ($projects as $p): ?>
      <option value="<?= esc(page_url('board', array_merge(['project' => $p['id']], $todayOnly ? ['today' => 1] : []))) ?>" <?= $proj === (int)$p['id'] ? 'selected' : '' ?>>
        <?= esc($p['icon']) ?> <?= esc($p['name']) ?>
      </option>
    <?php endforeach; ?>
  </select>
  <?php if ($proj || $todayOnly): ?>
    <a href="<?= page_url('board') ?>" class="chip">✕ Clear</a>
  <?php endif; ?>
  <span class="small muted" style="margin-left:auto">💡 Tip: kisi task ko pakad kar dusre column mein le jayein</span>
</div>

<div class="board" id="board">
  <?php foreach ($columns as $status => $col): ?>
    <div class="board-col" data-status="<?= esc($status) ?>" data-label="<?= esc($col['label']) ?>">
      <div class="board-col-head">
        <span class="dot" style="background:<?= esc($col['color']) ?>"></span>
        <?= esc($col['label']) ?>
        <span class="cnt"><?= count($col['list']) ?></span>
      </div>
      <div class="board-drop">
        <?php if (!$col['list']): ?>
          <div class="kcol-empty"><?= $status === 'done' ? 'Nothing done yet' : 'Drop tasks here' ?></div>
        <?php else: foreach ($col['list'] as $t) kcard($t); endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<script>
(function () {
  let dragged = null;

  function bindCard(c) {
    c.addEventListener('dragstart', (e) => { dragged = c; c.classList.add('dragging'); e.dataTransfer.setData('text/plain', c.dataset.id); e.dataTransfer.effectAllowed = 'move'; });
    c.addEventListener('dragend', () => { if (dragged) dragged.classList.remove('dragging'); dragged = null; document.querySelectorAll('.board-col').forEach((x) => x.classList.remove('drag-over')); });
  }
  document.querySelectorAll('.kcard').forEach(bindCard);

  document.querySelectorAll('.board-col').forEach((col) => {
    const drop = col.querySelector('.board-drop');
    col.addEventListener('dragover', (e) => { e.preventDefault(); col.classList.add('drag-over'); e.dataTransfer.dropEffect = 'move'; });
    col.addEventListener('dragleave', (e) => { if (!col.contains(e.relatedTarget)) col.classList.remove('drag-over'); });
    col.addEventListener('drop', async (e) => {
      e.preventDefault(); col.classList.remove('drag-over');
      if (!dragged) return;
      const status = col.dataset.status;
      if (dragged.dataset.status === status) return;

      const empty = drop.querySelector('.kcol-empty'); if (empty) empty.remove();
      drop.appendChild(dragged);
      dragged.dataset.status = status;
      dragged.classList.toggle('is-done', status === 'done');
      // Blocked badge no longer applies once moved.
      const bb = dragged.querySelector('.badge.blocked'); if (bb) bb.remove();
      const id = dragged.dataset.id;
      recount();
      try {
        const r = await TW.api('set_status', { id, status });
        if (window.TWApplyStats) TWApplyStats(r.stats);
        TW.toast('Moved to ' + col.dataset.label + (status === 'done' ? ' 🎉' : ''));
      } catch (err) { TW.toast(err.message, 'err'); setTimeout(() => location.reload(), 900); }
    });
  });

  function recount() {
    document.querySelectorAll('.board-col').forEach((col) => {
      const drop = col.querySelector('.board-drop');
      const n = drop.querySelectorAll('.kcard').length;
      col.querySelector('.cnt').textContent = n;
      let empty = drop.querySelector('.kcol-empty');
      if (n === 0 && !empty) { const d = document.createElement('div'); d.className = 'kcol-empty'; d.textContent = col.dataset.status === 'done' ? 'Nothing done yet' : 'Drop tasks here'; drop.appendChild(d); }
      if (n > 0 && empty) empty.remove();
    });
  }
})();
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>
