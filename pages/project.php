<?php
/** Project detail — one project's tasks, progress and time. */
$ACTIVE = 'projects';

$id = (int)($_GET['id'] ?? 0);
$project = $id ? get_project($id) : null;

if (!$project) {
    $PAGE_TITLE = 'Project';
    $PAGE_SUB = '';
    require __DIR__ . '/../partials/header.php';
    ?>
    <div class="card card-pad empty animate">
      <span class="emoji">🧭</span>
      <h4>Project not found</h4>
      <p>This project may have been deleted or the link is out of date.</p>
      <a href="<?= page_url('projects') ?>" class="btn btn-primary mt-4">← Back to projects</a>
    </div>
    <?php
    require __DIR__ . '/../partials/footer.php';
    return;
}

$color = $project['color'] ?: '#6C5CE7';
$stats = project_stats($id);

$PAGE_TITLE = $project['name'];
$PAGE_SUB = $stats['done'] . '/' . $stats['total'] . ' tasks · ' . fmt_hours($stats['spent_min']) . 'h logged';

// Task lists for this project.
$openTasks = get_tasks(['project_id' => $id, 'not_status' => ['done']]);
$doneTasks = get_tasks(['project_id' => $id, 'status' => 'done']);

// Status donut data.
$statusData = [
    ['label' => 'To Do',       'value' => $stats['todo'],        'color' => STATUS_META['todo']['color']],
    ['label' => 'In Progress', 'value' => $stats['in_progress'], 'color' => STATUS_META['in_progress']['color']],
    ['label' => 'Done',        'value' => $stats['done'],        'color' => STATUS_META['done']['color']],
    ['label' => 'Blocked',     'value' => $stats['blocked'],     'color' => STATUS_META['blocked']['color']],
];

// Hours logged for this project over the last 7 days.
$stmt = db()->prepare("SELECT log_date, SUM(minutes) m FROM time_entries
    WHERE project_id = ? AND log_date >= ? GROUP BY log_date");
$from7 = date('Y-m-d', strtotime('-6 days'));
$stmt->execute([$id, $from7]);
$map = [];
foreach ($stmt->fetchAll() as $r) $map[$r['log_date']] = (int)$r['m'];
$days7 = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $days7[] = ['date' => $d, 'label' => date('D', strtotime($d)), 'minutes' => $map[$d] ?? 0];
}

// Inline project status changer.
$projStatuses = ['active' => 'Active', 'paused' => 'Paused', 'done' => 'Done'];

require __DIR__ . '/../partials/header.php';
?>

<!-- Hero -->
<div class="card animate mb-6" style="overflow:hidden;position:relative;border-color:<?= esc($color) ?>44;background:linear-gradient(135deg, <?= esc($color) ?>1f 0%, <?= esc($color) ?>0a 60%, transparent 100%)">
  <div class="card-pad" style="padding:26px 28px;display:flex;align-items:center;gap:24px;flex-wrap:wrap">
    <div class="t-ic" style="flex:0 0 auto;width:64px;height:64px;border-radius:18px;font-size:30px;display:grid;place-items:center;background:<?= esc($color) ?>26;color:<?= esc($color) ?>;box-shadow:0 8px 22px -10px <?= esc($color) ?>">
      <?= esc($project['icon'] ?: '📁') ?>
    </div>
    <div class="grow" style="min-width:220px">
      <div class="row" style="gap:10px">
        <h2 style="font-size:26px"><?= esc($project['name']) ?></h2>
        <?php if ($project['status'] !== 'active'): ?><span class="badge"><?= esc(ucfirst($project['status'])) ?></span><?php endif; ?>
      </div>
      <?php if (trim((string)$project['description']) !== ''): ?>
        <p class="dim" style="margin:6px 0 0;max-width:56ch"><?= esc($project['description']) ?></p>
      <?php endif; ?>
      <!-- Status changer -->
      <div class="row mt-4" style="gap:10px">
        <div class="seg" id="projStatusSeg" data-id="<?= $id ?>">
          <?php foreach ($projStatuses as $val => $label): ?>
            <button type="button" class="<?= $project['status'] === $val ? 'on' : '' ?>" data-project-status="<?= $val ?>"><?= esc($label) ?></button>
          <?php endforeach; ?>
        </div>
        <a href="<?= page_url('projects') ?>" class="btn btn-ghost btn-sm">← All projects</a>
        <button class="btn btn-ghost btn-sm" onclick="projectEditorOpen('edit', <?= esc(json_encode($project, JSON_UNESCAPED_UNICODE)) ?>)">✏️ Edit</button>
        <button class="btn btn-danger btn-sm" onclick="projectDelete(<?= (int)$id ?>, <?= esc(json_encode($project['name'], JSON_UNESCAPED_UNICODE)) ?>)">🗑️ Delete</button>
      </div>
    </div>
    <!-- Progress ring -->
    <div style="text-align:center;flex:0 0 auto">
      <div class="ring" style="--p:<?= $stats['progress'] ?>;--size:104px;--fill:<?= esc($color) ?>;margin:0 auto">
        <span style="font-size:19px;color:<?= esc($color) ?>"><?= $stats['progress'] ?>%</span>
      </div>
      <div class="small muted" style="margin-top:8px"><?= $stats['done'] ?> of <?= $stats['total'] ?> done</div>
    </div>
  </div>
</div>

<!-- Key stats -->
<div class="grid cols-4 mb-6">
  <div class="tile animate d1">
    <div class="t-label">Tasks</div>
    <div class="t-value"><?= $stats['done'] ?><span class="muted" style="font-size:18px">/<?= $stats['total'] ?></span></div>
  </div>
  <div class="tile animate d2">
    <div class="t-label">Hours Logged</div>
    <div class="t-value" style="color:var(--mint)"><?= esc(fmt_hours($stats['spent_min'])) ?><small style="font-size:16px">h</small></div>
  </div>
  <div class="tile animate d3">
    <div class="t-label">In Progress</div>
    <div class="t-value" style="color:var(--sky)"><?= $stats['in_progress'] ?></div>
  </div>
  <div class="tile animate d4">
    <div class="t-label">Blocked</div>
    <div class="t-value" style="color:var(--coral)"><?= $stats['blocked'] ?></div>
  </div>
</div>

<div class="grid cols-3">
  <!-- Tasks (span 2) -->
  <div class="span-2 animate" style="display:flex;flex-direction:column;gap:20px">
    <!-- Quick add -->
    <div class="card card-pad">
      <div class="card-head"><h3>＋ Add a task</h3></div>
      <form id="quickAddForm">
        <input type="hidden" name="project_id" value="<?= $id ?>">
        <input type="hidden" name="priority" value="normal">
        <div class="field">
          <input class="input" name="title" placeholder="What needs doing?" autocomplete="off" required>
        </div>
        <div class="row wrap mt-4" style="gap:10px">
          <select class="select" name="status" style="width:auto;min-width:140px">
            <option value="todo">📝 To do</option>
            <option value="in_progress">🚧 In progress</option>
            <option value="done">✅ Done</option>
            <option value="blocked">⛔ Blocked</option>
          </select>
          <select class="select" name="type" style="width:auto;min-width:150px">
            <option value="task">• Task</option>
            <option value="feature">✨ New Build</option>
            <option value="improvement">🔧 Improvement</option>
            <option value="bug">🐞 Fix</option>
            <option value="research">🔎 Research</option>
          </select>
          <button type="submit" class="btn btn-primary" style="margin-left:auto">Add task</button>
        </div>
      </form>
    </div>

    <!-- Open tasks -->
    <div class="card card-pad">
      <div class="card-head">
        <h3>🗂️ Open Tasks</h3>
        <span class="badge"><?= count($openTasks) ?></span>
      </div>
      <?php if (!$openTasks): ?>
        <div class="empty" style="padding:32px 20px">
          <span class="emoji">🎉</span>
          <h4>All clear</h4>
          <p>No open tasks for this project. Add one above to keep the momentum going.</p>
        </div>
      <?php else: ?>
        <?php foreach ($openTasks as $t) render_task($t, ['delete' => true, 'timer' => true]); ?>
      <?php endif; ?>
    </div>

    <!-- Completed tasks -->
    <?php if ($doneTasks): ?>
      <div class="card card-pad">
        <div class="card-head">
          <h3>✅ Completed</h3>
          <span class="badge done"><?= count($doneTasks) ?></span>
          <div class="card-action"><button class="btn btn-ghost btn-sm" type="button" data-toggle="#doneList">Show / hide</button></div>
        </div>
        <div id="doneList" class="hidden">
          <?php foreach ($doneTasks as $t) render_task($t, ['delete' => true]); ?>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <!-- Right rail: charts -->
  <div class="animate d1" style="display:flex;flex-direction:column;gap:20px">
    <div class="card card-pad chart">
      <div class="card-head"><h3>⏳ Last 7 Days</h3>
        <span class="card-action small strong" style="color:<?= esc($color) ?>"><?= esc(fmt_hours(array_sum(array_column($days7, 'minutes')))) ?>h</span>
      </div>
      <div id="projWeekChart"></div>
    </div>
    <div class="card card-pad chart">
      <div class="card-head"><h3>📊 Status</h3></div>
      <?php if ($stats['total'] === 0): ?>
        <div class="muted small center" style="padding:18px">No tasks yet.</div>
      <?php else: ?>
        <div class="row" style="gap:16px">
          <div id="projStatusDonut" style="width:130px;flex:0 0 130px"></div>
          <div class="legend" style="flex-direction:column;gap:9px;margin-top:0">
            <?php foreach ($statusData as $sd): ?>
              <div class="li"><span class="sw" style="background:<?= esc($sd['color']) ?>"></span><?= esc($sd['label']) ?> <strong style="margin-left:auto"><?= $sd['value'] ?></strong></div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', drawProject);
document.addEventListener('tw:theme', drawProject);
function drawProject() {
  TWChart.bars('projWeekChart', <?= json_encode(array_map(fn($d) => ['label' => substr($d['label'], 0, 2), 'value' => $d['minutes'], 'today' => $d['date'] === date('Y-m-d')], $days7)) ?>, { height: 150, color: <?= json_encode($color) ?> });
  <?php if ($stats['total'] > 0): ?>
  TWChart.donut('projStatusDonut', <?= json_encode($statusData) ?>, { size: 130, centerLabel: 'tasks' });
  <?php endif; ?>
}

(function () {
  // Show / hide completed list.
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-toggle]');
    if (!t) return;
    var target = document.querySelector(t.getAttribute('data-toggle'));
    if (target) target.classList.toggle('hidden');
  });

  // Inline project status changer.
  var seg = document.getElementById('projStatusSeg');
  if (seg) {
    seg.addEventListener('click', async function (e) {
      var b = e.target.closest('[data-project-status]');
      if (!b || b.classList.contains('on')) return;
      var status = b.getAttribute('data-project-status');
      var prev = seg.querySelector('button.on');
      seg.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === b); });
      try {
        await TW.api('update_project', { id: <?= $id ?>, status: status });
        TW.toast('Project marked ' + status);
      } catch (err) {
        TW.toast(err.message, 'err');
        seg.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === prev); });
      }
    });
  }
})();
</script>

<?php render_project_editor(); ?>

<?php require __DIR__ . '/../partials/footer.php'; ?>
