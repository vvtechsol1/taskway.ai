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

$TOPBAR_ACTIONS = '<button class="btn btn-soft" data-open-modal="newProjectModal"><span>＋</span> New Project</button>';

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
      <button class="btn btn-primary" data-open-modal="newProjectModal">＋ New Project</button>
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
        <div class="animate <?= 'd' . min(4, $i % 4 + 1) ?>"><?php render_project_card($p); ?></div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<!-- New Project modal -->
<div class="modal-back" id="newProjectModal">
  <div class="modal">
    <div class="modal-head">
      <h3>📁 New Project</h3>
      <div class="card-action" style="margin-left:auto"><button class="icon-btn" data-close-modal title="Close">✕</button></div>
    </div>
    <div class="modal-body">
      <form id="newProjectForm">
        <div class="field">
          <label class="fld" for="npName">Project name</label>
          <input class="input" id="npName" name="name" placeholder="e.g. Casebazar" autocomplete="off" required>
        </div>
        <div class="field">
          <div class="row" style="gap:14px;align-items:flex-start">
            <div style="flex:0 0 96px">
              <label class="fld" for="npIcon">Icon</label>
              <input class="input" id="npIcon" name="icon" value="📁" maxlength="4" style="text-align:center;font-size:20px">
            </div>
            <div class="grow">
              <label class="fld">Colour</label>
              <div class="row wrap" id="npSwatches" style="gap:9px">
                <?php foreach (PROJECT_PALETTE as $i => $hex): ?>
                  <button type="button" class="np-swatch <?= $i === 0 ? 'active' : '' ?>" data-color="<?= esc($hex) ?>"
                    style="width:30px;height:30px;border-radius:9px;border:2px solid transparent;cursor:pointer;background:<?= esc($hex) ?>;box-shadow:0 2px 8px -3px <?= esc($hex) ?>"
                    aria-label="Colour <?= esc($hex) ?>"></button>
                <?php endforeach; ?>
              </div>
              <input type="hidden" id="npColor" name="color" value="<?= esc(PROJECT_PALETTE[0]) ?>">
            </div>
          </div>
        </div>
        <div class="field">
          <label class="fld" for="npDesc">Description <span class="muted">(optional)</span></label>
          <textarea class="textarea" id="npDesc" name="description" placeholder="What is this project about?" style="min-height:72px"></textarea>
        </div>
      </form>
    </div>
    <div class="modal-foot">
      <button class="btn btn-ghost" data-close-modal>Cancel</button>
      <button class="btn btn-primary" id="npCreate">Create project</button>
    </div>
  </div>
</div>

<style>
  .np-swatch { transition: transform var(--dur) var(--ease), border-color var(--dur) var(--ease); }
  .np-swatch:hover { transform: translateY(-1px); }
  .np-swatch.active { border-color: var(--text); }
</style>

<script>
(function () {
  var swatches = document.getElementById('npSwatches');
  var colorInp = document.getElementById('npColor');
  if (swatches) {
    swatches.addEventListener('click', function (e) {
      var sw = e.target.closest('.np-swatch');
      if (!sw) return;
      colorInp.value = sw.getAttribute('data-color');
      swatches.querySelectorAll('.np-swatch').forEach(function (b) { b.classList.toggle('active', b === sw); });
    });
  }

  var form = document.getElementById('newProjectForm');
  var btn = document.getElementById('npCreate');
  async function create() {
    var name = document.getElementById('npName').value.trim();
    if (!name) { TW.toast('Give your project a name', 'info'); document.getElementById('npName').focus(); return; }
    btn.disabled = true;
    try {
      await TW.api('create_project', {
        name: name,
        icon: document.getElementById('npIcon').value.trim() || '📁',
        color: colorInp.value,
        description: document.getElementById('npDesc').value.trim()
      });
      TW.toast('Project created 🎉');
      setTimeout(function () { location.reload(); }, 450);
    } catch (err) { TW.toast(err.message, 'err'); btn.disabled = false; }
  }
  if (btn) btn.addEventListener('click', create);
  if (form) form.addEventListener('submit', function (e) { e.preventDefault(); create(); });
})();
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>
