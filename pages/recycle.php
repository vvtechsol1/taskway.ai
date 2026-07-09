<?php
/** Recycle Bin — restore or permanently delete removed tasks & projects. */
$ACTIVE = 'recycle';
$PAGE_TITLE = 'Recycle Bin';
$PAGE_SUB = 'Restore deleted items, or clear them for good';

$delTasks = get_deleted_tasks();
$delProjects = get_deleted_projects();
$total = count($delTasks) + count($delProjects);

if ($total > 0) {
    $TOPBAR_ACTIONS = '<button class="btn btn-danger" onclick="emptyRecycle()">🗑 Empty bin</button>';
}

require __DIR__ . '/../partials/header.php';
?>

<?php if ($total === 0): ?>
  <div class="card card-pad empty animate">
    <span class="emoji">♻️</span>
    <h4>Recycle bin is empty</h4>
    <p>Deleted tasks and projects land here so you can restore them.</p>
  </div>
<?php else: ?>

  <?php if ($delProjects): ?>
    <div class="card card-pad mb-6 animate">
      <div class="card-head"><h3>📁 Deleted projects</h3><span class="badge"><?= count($delProjects) ?></span></div>
      <?php foreach ($delProjects as $p): ?>
        <div class="task" data-recycle="project-<?= (int)$p['id'] ?>">
          <div class="t-ic" style="background:<?= esc($p['color']) ?>22;color:<?= esc($p['color']) ?>;width:38px;height:38px;border-radius:11px;display:grid;place-items:center;font-size:17px"><?= esc($p['icon'] ?: '📁') ?></div>
          <div class="task-main">
            <div class="task-title"><?= esc($p['name']) ?></div>
            <div class="task-meta"><span class="tag muted">Deleted <?= esc(relative_time($p['deleted_at'])) ?></span></div>
          </div>
          <div class="task-side">
            <button class="btn btn-soft btn-sm" onclick="rec('restore_project',<?= (int)$p['id'] ?>,this)">↩ Restore</button>
            <button class="icon-btn" title="Delete permanently" onclick="rec('purge_project',<?= (int)$p['id'] ?>,this,true)">🗑</button>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($delTasks): ?>
    <div class="card card-pad animate d1">
      <div class="card-head"><h3>✅ Deleted tasks</h3><span class="badge"><?= count($delTasks) ?></span></div>
      <?php foreach ($delTasks as $t): ?>
        <div class="task <?= $t['status'] === 'done' ? 'is-done' : '' ?>" data-recycle="task-<?= (int)$t['id'] ?>">
          <span class="pri-bar <?= esc($t['priority']) ?>"></span>
          <div class="task-main">
            <div class="task-title"><?= esc($t['title']) ?></div>
            <div class="task-meta">
              <?php if (!empty($t['project_name'])): ?><span class="tag" style="color:var(--text-2)"><span class="dot" style="background:<?= esc($t['project_color'] ?? '#6C5CE7') ?>"></span><?= esc($t['project_name']) ?></span><?php endif; ?>
              <?= status_badge($t['status']) ?>
              <span class="tag muted">Deleted <?= esc(relative_time($t['deleted_at'])) ?></span>
            </div>
          </div>
          <div class="task-side">
            <button class="btn btn-soft btn-sm" onclick="rec('restore_task',<?= (int)$t['id'] ?>,this)">↩ Restore</button>
            <button class="icon-btn" title="Delete permanently" onclick="rec('purge_task',<?= (int)$t['id'] ?>,this,true)">🗑</button>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

<?php endif; ?>

<script>
async function rec(action, id, btn, confirmFirst) {
  if (confirmFirst && !confirm('Delete permanently? This cannot be undone.')) return;
  try {
    await TW.api(action, { id: id });
    TW.toast(action.indexOf('restore') === 0 ? 'Restored ✓' : 'Permanently deleted');
    const row = btn.closest('[data-recycle]');
    if (row) { row.style.transition = 'all .3s'; row.style.opacity = '0'; setTimeout(() => { row.remove(); if (!document.querySelector('[data-recycle]')) location.reload(); }, 300); }
  } catch (err) { TW.toast(err.message, 'err'); }
}
async function emptyRecycle() {
  if (!confirm('Permanently delete EVERYTHING in the recycle bin? This cannot be undone.')) return;
  try { await TW.api('empty_recycle', {}); TW.toast('Recycle bin emptied'); setTimeout(() => location.reload(), 400); }
  catch (err) { TW.toast(err.message, 'err'); }
}
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>
