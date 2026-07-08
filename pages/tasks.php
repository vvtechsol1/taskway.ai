<?php
/** Tasks — the full task manager: filter, quick-add, and act on everything. */
$ACTIVE = 'tasks';
$PAGE_TITLE = 'Tasks';

/* Fallback: add a task via a normal POST if JS/AJAX didn't intercept the form. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'quickadd') {
    create_task([
        'title'      => $_POST['title'] ?? '',
        'project_id' => $_POST['project_id'] ?? '',
        'type'       => $_POST['type'] ?? 'task',
        'priority'   => $_POST['priority'] ?? 'normal',
    ]);
    redirect(page_url('tasks', array_filter([
        'status'  => $_GET['status'] ?? '',
        'type'    => $_GET['type'] ?? '',
        'project' => $_GET['project'] ?? '',
        'q'       => $_GET['q'] ?? '',
    ], fn($v) => $v !== '' && $v !== null)));
}

/* ------------------------------------------------------------------ */
/* Read + validate filters from the query string                      */
/* ------------------------------------------------------------------ */
$validStatus = ['todo', 'in_progress', 'done', 'blocked'];
$validType   = ['feature', 'improvement', 'bug', 'research'];

$statusFilter  = (string)($_GET['status'] ?? '');
$typeFilter    = (string)($_GET['type'] ?? '');
$q             = trim((string)($_GET['q'] ?? ''));
$projectFilter = (isset($_GET['project']) && $_GET['project'] !== '') ? (int)$_GET['project'] : null;

$hasStatus = in_array($statusFilter, $validStatus, true);
$hasType   = in_array($typeFilter, $validType, true);

/* Build the query for get_tasks(). */
$filters = [];
if ($projectFilter)  $filters['project_id'] = $projectFilter;
if ($hasStatus)      $filters['status']     = $statusFilter;
if ($hasType)        $filters['type']       = $typeFilter;
if ($q !== '')       $filters['search']     = $q;

$tasks = get_tasks($filters);

/* Split for the "All statuses" view (open first, completed collapsed below). */
$openList = array_values(array_filter($tasks, fn($t) => $t['status'] !== 'done'));
$doneList = array_values(array_filter($tasks, fn($t) => $t['status'] === 'done'));

$totalShown = count($tasks);
$doneShown  = count($doneList);

/* Data for stat tiles + selects. */
$stats      = stats_overview();
$breakdown  = status_breakdown();
$inProgress = $breakdown['in_progress']['count'];
$projects   = get_projects();
$activeProj = $projectFilter ? get_project($projectFilter) : null;

$singleProject = $projectFilter !== null;
$filtersActive = $hasStatus || $hasType || $singleProject || $q !== '';

$PAGE_SUB = $stats['open_tasks'] . ' open · ' . $stats['done_week'] . ' done this week';

/* Currently active filters, keyed — used to build chip links that preserve context. */
$current = [];
if ($hasStatus)     $current['status']  = $statusFilter;
if ($projectFilter) $current['project'] = $projectFilter;
if ($hasType)       $current['type']    = $typeFilter;
if ($q !== '')      $current['q']       = $q;

/** Build a tasks URL that keeps every active filter except $drop, then sets $set. */
$filterUrl = function (string $drop, array $set = []) use ($current) {
    $p = $current;
    unset($p[$drop]);
    foreach ($set as $k => $v) {
        if ($v === '' || $v === null) unset($p[$k]);
        else $p[$k] = $v;
    }
    return page_url('tasks', $p);
};

/* Live timer chip in the topbar (same convention as the dashboard). */
$timer = running_timer();
if ($timer) {
    $TOPBAR_ACTIONS = '<div class="chip" style="border-color:var(--coral)"><span class="live-dot"></span> '
        . '<span id="timerTicker" data-started="' . esc($timer['started_at']) . '">00:00</span> · '
        . esc(mb_strimwidth($timer['task_title'], 0, 22, '…')) . '</div>';
}

/**
 * Render a list of tasks, optionally grouped by project with a subtle header.
 * Headers are skipped when there is only a single group.
 */
$taskOpts  = ['delete' => true, 'timer' => true, 'show_date' => true];
$renderList = function (array $list, bool $group) use ($taskOpts) {
    if (!$group) {
        foreach ($list as $t) render_task($t, $taskOpts);
        return;
    }
    $groups = [];
    foreach ($list as $t) {
        $key = (int)($t['project_id'] ?? 0);
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'name'  => $t['project_name'] ?: 'No project',
                'color' => $t['project_color'] ?: '#7A7890',
                'icon'  => $t['project_icon'] ?: '📂',
                'tasks' => [],
            ];
        }
        $groups[$key]['tasks'][] = $t;
    }
    if (count($groups) <= 1) {
        foreach ($list as $t) render_task($t, $taskOpts);
        return;
    }
    $first = true;
    foreach ($groups as $g) {
        ?>
        <div class="row" style="gap:9px;align-items:center;margin:<?= $first ? '2px' : '20px' ?> 2px 11px">
          <span style="width:9px;height:9px;border-radius:3px;flex:0 0 auto;background:<?= esc($g['color']) ?>"></span>
          <strong style="font-size:12.5px;letter-spacing:.01em"><?= esc($g['icon']) ?> <?= esc($g['name']) ?></strong>
          <span class="badge"><?= count($g['tasks']) ?></span>
          <span style="flex:1;height:1px;background:var(--border)"></span>
        </div>
        <?php
        $first = false;
        foreach ($g['tasks'] as $t) render_task($t, $taskOpts);
    }
};

$listTitle = ($singleProject && $activeProj)
    ? (($activeProj['icon'] ?: '📁') . ' ' . $activeProj['name'])
    : 'Tasks';

require __DIR__ . '/../partials/header.php';
?>

<!-- Stat tiles -->
<div class="grid cols-4 mb-6">
  <div class="stat violet animate"><span class="stat-ic">📋</span>
    <div class="stat-label">Open Tasks</div>
    <div class="stat-value" data-stat="open_tasks"><?= (int)$stats['open_tasks'] ?></div>
    <div class="stat-meta"><?= (int)$breakdown['todo']['count'] ?> to do · <?= (int)$breakdown['blocked']['count'] ?> blocked</div>
  </div>
  <div class="stat sky animate d1"><span class="stat-ic">⏳</span>
    <div class="stat-label">In Progress</div>
    <div class="stat-value"><?= (int)$inProgress ?></div>
    <div class="stat-meta">Currently active</div>
  </div>
  <div class="stat mint animate d2"><span class="stat-ic">✅</span>
    <div class="stat-label">Done This Week</div>
    <div class="stat-value" data-stat="done_week"><?= (int)$stats['done_week'] ?></div>
    <div class="stat-meta"><?= (int)$stats['done_today'] ?> completed today</div>
  </div>
  <div class="stat amber animate d3"><span class="stat-ic">⏱️</span>
    <div class="stat-label">Hours This Week</div>
    <div class="stat-value" data-stat="hours_week_min"><?= esc(fmt_hours($stats['hours_week_min'])) ?><small>h</small></div>
    <div class="stat-meta"><?= esc(fmt_hours($stats['hours_today_min'])) ?>h logged today</div>
  </div>
</div>

<!-- Quick add -->
<div class="card card-pad animate d1" style="margin-bottom:20px">
  <form id="quickAddForm" method="post" action="<?= esc(page_url('tasks', array_filter(['status' => $statusFilter, 'type' => $typeFilter, 'project' => $projectFilter, 'q' => $q], fn($v) => $v !== '' && $v !== null))) ?>" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <input type="hidden" name="form" value="quickadd">
    <span style="font-size:20px;flex:0 0 auto;opacity:.6">✍️</span>
    <input class="input" type="text" name="title" required maxlength="200" autocomplete="off"
           placeholder="Add a task…" style="flex:1 1 220px">
    <select class="select" name="project_id" style="width:auto;min-width:150px;flex:0 0 auto" aria-label="Project">
      <option value="">📁 No project</option>
      <?php foreach ($projects as $p): ?>
        <option value="<?= (int)$p['id'] ?>" <?= $projectFilter === (int)$p['id'] ? 'selected' : '' ?>>
          <?= esc(($p['icon'] ?: '📁') . ' ' . $p['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <select class="select" name="type" style="width:auto;min-width:130px;flex:0 0 auto" aria-label="Type">
      <option value="task">• Task</option>
      <?php foreach (['feature', 'improvement', 'bug', 'research'] as $ty): ?>
        <option value="<?= $ty ?>"><?= esc(TYPE_META[$ty]['icon'] . ' ' . TYPE_META[$ty]['label']) ?></option>
      <?php endforeach; ?>
    </select>
    <select class="select" name="priority" style="width:auto;min-width:120px;flex:0 0 auto" aria-label="Priority">
      <?php foreach (['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'] as $pv => $pl): ?>
        <option value="<?= $pv ?>" <?= $pv === 'normal' ? 'selected' : '' ?>><?= esc($pl) ?> priority</option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary" style="flex:0 0 auto"><span>＋</span> Add</button>
  </form>
</div>

<!-- Filter bar -->
<div class="card card-pad animate d2" style="margin-bottom:20px">
  <div class="row wrap" style="gap:10px;align-items:center">
    <!-- Status chips -->
    <div class="row wrap" style="gap:7px">
      <?php
      $statusChips = ['' => 'All', 'todo' => 'To do', 'in_progress' => 'Doing', 'done' => 'Done', 'blocked' => 'Blocked'];
      foreach ($statusChips as $val => $label):
          $on = ($val === '' && !$hasStatus) || ($val !== '' && $statusFilter === $val);
      ?>
        <a href="<?= $filterUrl('status', ['status' => $val]) ?>" class="chip <?= $on ? 'active' : '' ?>">
          <?php if ($val !== ''): ?><span class="dot" style="background:<?= esc(STATUS_META[$val]['color']) ?>"></span><?php endif; ?>
          <?= esc($label) ?>
        </a>
      <?php endforeach; ?>
    </div>

    <span style="flex:1;min-width:8px"></span>

    <!-- Project select -->
    <select class="select" style="width:auto;min-width:160px;flex:0 0 auto" aria-label="Filter by project"
            onchange="if(this.value)location.href=this.value">
      <option value="<?= esc($filterUrl('project')) ?>" <?= !$singleProject ? 'selected' : '' ?>>All projects</option>
      <?php foreach ($projects as $p): ?>
        <option value="<?= esc($filterUrl('project', ['project' => (int)$p['id']])) ?>"
                <?= $projectFilter === (int)$p['id'] ? 'selected' : '' ?>>
          <?= esc(($p['icon'] ?: '📁') . ' ' . $p['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <!-- Search -->
    <form method="get" action="<?= esc(url('index.php')) ?>" class="row" style="gap:0;flex:0 0 auto">
      <input type="hidden" name="page" value="tasks">
      <?php if ($hasStatus): ?><input type="hidden" name="status" value="<?= esc($statusFilter) ?>"><?php endif; ?>
      <?php if ($projectFilter): ?><input type="hidden" name="project" value="<?= (int)$projectFilter ?>"><?php endif; ?>
      <?php if ($hasType): ?><input type="hidden" name="type" value="<?= esc($typeFilter) ?>"><?php endif; ?>
      <input class="input" type="search" name="q" value="<?= esc($q) ?>" placeholder="🔍 Search tasks…"
             style="width:190px;border-top-right-radius:0;border-bottom-right-radius:0">
      <button type="submit" class="btn btn-soft" style="border-top-left-radius:0;border-bottom-left-radius:0">Go</button>
    </form>
  </div>

  <!-- Type chips + count summary -->
  <div class="row wrap" style="gap:7px;align-items:center;margin-top:12px;padding-top:12px;border-top:1px solid var(--border)">
    <span class="small muted strong" style="text-transform:uppercase;letter-spacing:.05em;font-size:10.5px">Type</span>
    <?php
    $typeChips = ['' => ['icon' => '', 'label' => 'All']] + array_intersect_key(TYPE_META, array_flip($validType));
    foreach ($typeChips as $val => $meta):
        $on = ($val === '' && !$hasType) || ($val !== '' && $typeFilter === $val);
    ?>
      <a href="<?= $filterUrl('type', ['type' => $val]) ?>" class="chip <?= $on ? 'active' : '' ?>">
        <?= $meta['icon'] !== '' ? esc($meta['icon']) . ' ' : '' ?><?= esc($meta['label']) ?>
      </a>
    <?php endforeach; ?>
    <span style="flex:1"></span>
    <span class="small muted">
      <strong style="color:var(--text-2)"><?= $totalShown ?></strong> task<?= $totalShown === 1 ? '' : 's' ?><?php if ($doneShown): ?> · <?= $doneShown ?> done<?php endif; ?>
      <?php if ($filtersActive): ?> · <a href="<?= page_url('tasks') ?>" style="color:var(--primary);font-weight:600">Clear filters</a><?php endif; ?>
    </span>
  </div>
</div>

<!-- Task list -->
<?php if (!$tasks): ?>
  <div class="card card-pad animate d3">
    <?php if ($filtersActive): ?>
      <div class="empty">
        <span class="emoji">🔍</span>
        <h4>No tasks match these filters</h4>
        <p>Try a different status, project or search term.</p>
        <a href="<?= page_url('tasks') ?>" class="btn btn-soft mt-4">Clear filters</a>
      </div>
    <?php else: ?>
      <div class="empty">
        <span class="emoji">🌱</span>
        <h4>No tasks yet</h4>
        <p>Add one above, or paste your notes into the Brain Dump and Taskway will build your list.</p>
        <a href="<?= page_url('braindump') ?>" class="btn btn-primary mt-4">🧠 Open Brain Dump</a>
      </div>
    <?php endif; ?>
  </div>
<?php else: ?>
  <div class="card card-pad animate d3">
    <div class="card-head">
      <h3><?= esc($listTitle) ?></h3>
      <?php $badgeN = $hasStatus ? $totalShown : count($openList); ?>
      <span class="badge"><?= $badgeN ?> <?= $hasStatus ? 'shown' : 'open' ?></span>
      <div class="card-action">
        <a href="<?= page_url('braindump') ?>" class="btn btn-soft btn-sm">🧠 Brain Dump</a>
      </div>
    </div>

    <?php if ($hasStatus): ?>
      <!-- A single status is selected: list every match (grouped unless one project). -->
      <?php if ($tasks): $renderList($tasks, !$singleProject); ?>
      <?php endif; ?>
    <?php else: ?>
      <!-- All statuses: open work first… -->
      <?php if ($openList): ?>
        <?php $renderList($openList, !$singleProject); ?>
      <?php else: ?>
        <div class="empty" style="padding:var(--sp-8)">
          <span class="emoji">🎉</span>
          <h4>All caught up here</h4>
          <p>No open tasks — everything below is done.</p>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <?php if (!$hasStatus && $doneList): ?>
    <!-- …then a collapsible completed section. -->
    <details class="card animate d4" style="margin-top:16px;overflow:hidden">
      <summary style="cursor:pointer;list-style:none;display:flex;align-items:center;gap:10px;padding:15px 20px;font-weight:700;font-size:14px">
        <span style="font-size:16px">☑</span>
        Completed
        <span class="badge done"><?= count($doneList) ?></span>
        <span class="muted small strong" style="margin-left:auto">Show / hide</span>
      </summary>
      <div style="padding:2px 20px 20px">
        <?php $renderList($doneList, false); ?>
      </div>
    </details>
  <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../partials/footer.php'; ?>
