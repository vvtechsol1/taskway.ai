<?php
/** Projects — overview of every project, grouped by status. */
$ACTIVE = 'projects';
$PAGE_TITLE = 'Projects';
$PAGE_SUB = 'Everything you\'re building, in one place';

$allProjects = get_projects();
$totalProjects = count($allProjects);

// Task counts across all projects (no raw SQL — reuse the breakdown helper).
$bd      = status_breakdown();
$doneAll = $bd['done']['count'];
$openAll = $bd['todo']['count'] + $bd['in_progress']['count'] + $bd['blocked']['count'];

// Total minutes ever logged.
$totalMin    = minutes_in_period('1970-01-01', date('Y-m-d'));
$activeCount = count(array_filter($allProjects, fn($p) => $p['status'] === 'active'));

// Group projects by status for tidy sections.
$groups = ['active' => [], 'paused' => [], 'done' => [], 'archived' => []];
foreach ($allProjects as $p) {
    $groups[$p['status']][] = $p;
}
$sectionMeta = [
    'active'   => ['label' => 'Active',   'emoji' => '🟢'],
    'paused'   => ['label' => 'Paused',   'emoji' => '⏸️'],
    'done'     => ['label' => 'Completed', 'emoji' => '✅'],
    'archived' => ['label' => 'Archived', 'emoji' => '🗄️'],
];

$TOPBAR_ACTIONS = '<button class="btn btn-soft" onclick="projectEditorOpen(\'create\')"><span>＋</span> New Project</button>';

require __DIR__ . '/../partials/header.php';
?>

<!-- Summary tiles -->
<div class="grid cols-4 mb-6">
  <div class="stat violet animate d1"><span class="stat-ic">📁</span>
    <div class="stat-label">Projects</div>
    <div class="stat-value"><?= $totalProjects ?></div>
    <div class="stat-meta"><?= $activeCount ?> active</div>
  </div>
  <div class="stat mint animate d2"><span class="stat-ic">⏱️</span>
    <div class="stat-label">Hours Logged</div>
    <div class="stat-value"><?= esc(fmt_hours($totalMin)) ?><small>h</small></div>
    <div class="stat-meta">across all projects</div>
  </div>
  <div class="stat sky animate d3"><span class="stat-ic">🗂️</span>
    <div class="stat-label">Open Tasks</div>
    <div class="stat-value"><?= $openAll ?></div>
    <div class="stat-meta">still to do</div>
  </div>
  <div class="stat coral animate d4"><span class="stat-ic">✅</span>
    <div class="stat-label">Completed Tasks</div>
    <div class="stat-value"><?= $doneAll ?></div>
    <div class="stat-meta">done so far</div>
  </div>
</div>

<?php if (!$allProjects): ?>
  <div class="card card-pad empty animate">
    <span class="emoji">📁</span>
    <h4>No projects yet</h4>
    <p>Projects appear automatically when you brain-dump tasks under a heading —<br>or spin one up right now.</p>
    <div class="row" style="justify-content:center;gap:10px;margin-top:16px">
      <button class="btn btn-primary" onclick="projectEditorOpen('create')">＋ New Project</button>
      <a href="<?= page_url('braindump') ?>" class="btn btn-ghost">🧠 Open Brain Dump</a>
    </div>
  </div>
<?php else: ?>
  <?php foreach ($sectionMeta as $status => $meta): ?>
    <?php if (!$groups[$status]) continue; ?>
    <div class="row between mb-4 <?= $status === 'active' ? '' : 'mt-6' ?>">
      <div class="row" style="gap:9px">
        <h3 style="font-size:15px"><?= $meta['emoji'] ?> <?= esc($meta['label']) ?></h3>
        <span class="badge"><?= count($groups[$status]) ?></span>
      </div>
    </div>
    <div class="grid cols-3">
      <?php foreach ($groups[$status] as $i => $p): ?>
        <div class="animate <?= 'd' . min(4, $i % 4 + 1) ?>"><?php render_project_card($p, ['actions' => true]); ?></div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php render_project_editor(); ?>

<?php require __DIR__ . '/../partials/footer.php'; ?>
