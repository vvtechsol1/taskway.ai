<?php
/** Analytics — weekly / monthly / yearly progress + hours breakdown. */
$ACTIVE = 'analytics';

$period = in_array($_GET['period'] ?? 'month', ['week', 'month', 'year'], true) ? ($_GET['period'] ?? 'month') : 'month';
$periodLabel = ['week' => 'This Week', 'month' => 'This Month', 'year' => 'This Year'][$period];

$PAGE_TITLE = 'Analytics';
$PAGE_SUB   = 'Your progress and hours — ' . strtolower($periodLabel);

/* ---- Gather data ------------------------------------------------------ */
[$from, $to] = period_range($period);
$days      = max(1, (int)floor((strtotime($to) - strtotime($from)) / 86400) + 1);

$periodMin = minutes_in_period($from, $to);
$doneCount = tasks_done_in_period($from, $to);
$avgMin    = (int)round($periodMin / $days);

$built     = completed_tasks($period, 'feature');   // "newly built" features
$builtCount = count($built);
$allDone   = completed_tasks($period);              // everything completed

/* Hours trend across the period. */
if ($period === 'week') {
    $trend = hours_by_day(7);
    $trendData = array_map(fn($d) => ['label' => date('D, M j', strtotime($d['date'])), 'value' => (int)$d['minutes']], $trend);
    $trendTitle = 'Hours this week';
} elseif ($period === 'month') {
    $trend = hours_by_day(30);
    $trendData = array_map(fn($d) => ['label' => date('M j', strtotime($d['date'])), 'value' => (int)$d['minutes']], $trend);
    $trendTitle = 'Hours — last 30 days';
} else { // year → aggregate by month
    $stmt = db()->prepare("SELECT strftime('%Y-%m', log_date) ym, COALESCE(SUM(minutes),0) m
        FROM time_entries WHERE log_date BETWEEN ? AND ? GROUP BY ym");
    $stmt->execute([$from, $to]);
    $mmap = [];
    foreach ($stmt->fetchAll() as $r) $mmap[$r['ym']] = (int)$r['m'];
    $trendData = [];
    $year = (int)date('Y');
    $curMonth = (int)date('n');
    for ($mo = 1; $mo <= $curMonth; $mo++) {
        $key = $year . '-' . str_pad((string)$mo, 2, '0', STR_PAD_LEFT);
        $trendData[] = ['label' => date('M', mktime(0, 0, 0, $mo, 1, $year)), 'value' => $mmap[$key] ?? 0];
    }
    $trendTitle = 'Hours by month';
}

/* Hours by project. */
$projHours = hours_by_project($period);
$projMax = 0; $projTotal = 0;
foreach ($projHours as $p) { $projMax = max($projMax, (int)$p['m']); $projTotal += (int)$p['m']; }

/* Work-type mix from all completed tasks. */
$typeCounts = [];
foreach (array_keys(TYPE_META) as $tk) $typeCounts[$tk] = 0;
foreach ($allDone as $t) {
    $tt = $t['type'] ?: 'task';
    if (!isset($typeCounts[$tt])) $typeCounts[$tt] = 0;
    $typeCounts[$tt]++;
}
$typeSegments = [];
foreach ($typeCounts as $tk => $cnt) {
    if ($cnt > 0) {
        $m = TYPE_META[$tk];
        $typeSegments[] = ['label' => $m['label'], 'value' => $cnt, 'color' => $m['color']];
    }
}

/* Compact completed-task row renderer (used by both lists). */
$compactDone = function (array $t, bool $showType = false): void {
    $meta  = TYPE_META[$t['type']] ?? TYPE_META['task'];
    $icon  = $meta['icon'];
    $color = $meta['color'];
    ?>
    <div class="drow">
      <span class="drow-ic" style="background:<?= esc($color) ?>1f;color:<?= esc($color) ?>"><?= $icon ?></span>
      <div class="grow">
        <div class="truncate" style="font-weight:600;font-size:13.5px"><?= esc($t['title']) ?></div>
        <div class="row wrap" style="gap:8px;margin-top:3px">
          <?php if (!empty($t['project_name'])): ?>
            <span class="tag" style="color:var(--text-3)"><span class="dot" style="background:<?= esc($t['project_color'] ?? '#6C5CE7') ?>"></span><?= esc($t['project_name']) ?></span>
          <?php endif; ?>
          <?php if ((int)$t['spent_min'] > 0): ?><span class="tag muted">⏱ <?= esc(fmt_min((int)$t['spent_min'])) ?></span><?php endif; ?>
          <?php if ($showType): ?><?= type_chip($t['type']) ?><?php endif; ?>
        </div>
      </div>
    </div>
    <?php
};

require __DIR__ . '/../partials/header.php';
?>

<style>
/* Period tabs as links (mirrors .tabs button styling) */
.tabs a { display: inline-block; text-decoration: none; font-weight: 650; font-size: 13px; color: var(--text-2); padding: 7px 15px; border-radius: 9px; transition: all var(--dur) var(--ease); }
.tabs a:hover { color: var(--text); }
.tabs a.on { background: var(--surface); color: var(--primary); box-shadow: var(--shadow-sm); }
/* Compact completed-task list */
.dlist .drow { display: flex; gap: 11px; padding: 10px 2px; align-items: flex-start; }
.dlist .drow + .drow { border-top: 1px solid var(--border); }
.drow-ic { flex: 0 0 auto; width: 30px; height: 30px; border-radius: 9px; display: grid; place-items: center; font-size: 14px; }
</style>

<!-- Period tabs -->
<div class="between wrap mb-6" style="gap:14px">
  <div class="tabs">
    <a href="<?= page_url('analytics', ['period' => 'week']) ?>"  class="<?= $period === 'week'  ? 'on' : '' ?>">This Week</a>
    <a href="<?= page_url('analytics', ['period' => 'month']) ?>" class="<?= $period === 'month' ? 'on' : '' ?>">This Month</a>
    <a href="<?= page_url('analytics', ['period' => 'year']) ?>"  class="<?= $period === 'year'  ? 'on' : '' ?>">This Year</a>
  </div>
  <div class="small muted">
    <?= esc(human_date($from)) ?> → Today · <strong style="color:var(--text-2)"><?= $days ?></strong> day<?= $days === 1 ? '' : 's' ?>
  </div>
</div>

<!-- Stat tiles -->
<div class="grid cols-4 mb-6">
  <div class="stat violet animate d1"><span class="stat-ic">⏱️</span>
    <div class="stat-label">Total Hours · <?= esc($periodLabel) ?></div>
    <div class="stat-value"><?= esc(fmt_hours($periodMin)) ?><small>h</small></div>
    <div class="stat-meta"><?= esc(fmt_min($periodMin)) ?> logged</div>
  </div>
  <div class="stat mint animate d2"><span class="stat-ic">✅</span>
    <div class="stat-label">Tasks Completed</div>
    <div class="stat-value"><?= $doneCount ?></div>
    <div class="stat-meta"><?= $builtCount ?> newly built</div>
  </div>
  <div class="stat sky animate d3"><span class="stat-ic">✨</span>
    <div class="stat-label">Newly Built</div>
    <div class="stat-value"><?= $builtCount ?></div>
    <div class="stat-meta">new features shipped</div>
  </div>
  <div class="stat coral animate d4"><span class="stat-ic">📊</span>
    <div class="stat-label">Avg Hours / Day</div>
    <div class="stat-value"><?= esc(fmt_hours($avgMin)) ?><small>h</small></div>
    <div class="stat-meta">across <?= $days ?> day<?= $days === 1 ? '' : 's' ?></div>
  </div>
</div>

<!-- Trend + project breakdown -->
<div class="grid cols-3">
  <div class="span-2 animate">
    <div class="card card-pad chart">
      <div class="card-head">
        <h3>📈 <?= esc($trendTitle) ?></h3>
        <span class="card-action small strong" style="color:var(--violet)"><?= esc(fmt_hours($periodMin)) ?>h total</span>
      </div>
      <?php if ($periodMin > 0): ?>
        <div id="trendChart"></div>
      <?php else: ?>
        <div class="empty"><span class="emoji">🌙</span><h4>No hours logged yet</h4><p>Start a timer or log time on a task and your trend will appear here.</p></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="animate d1">
    <div class="card card-pad">
      <div class="card-head"><h3>🗂️ Hours by Project</h3></div>
      <?php if (!$projHours): ?>
        <div class="empty"><span class="emoji">📁</span><h4>No time tracked</h4><p>Log time against project tasks to see the split.</p></div>
      <?php else: ?>
        <div style="display:grid;place-items:center;margin-bottom:6px">
          <div id="projDonut" style="width:160px"></div>
        </div>
        <div class="mt-2">
          <?php foreach ($projHours as $p): $col = $p['color'] ?: '#6C5CE7'; ?>
            <div style="margin-bottom:12px">
              <div class="row between" style="margin-bottom:5px;gap:10px">
                <span class="tag" style="color:var(--text)"><span class="dot" style="background:<?= esc($col) ?>"></span><span class="truncate"><?= esc($p['name']) ?></span></span>
                <span class="small strong" style="white-space:nowrap"><?= esc(fmt_hours((int)$p['m'])) ?>h</span>
              </div>
              <div class="progress thin"><i style="width:<?= $projMax > 0 ? round((int)$p['m'] / $projMax * 100) : 0 ?>%;background:<?= esc($col) ?>"></i></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Work-type mix + the two core lists -->
<div class="grid cols-3 mt-6">
  <!-- Work type mix -->
  <div class="animate">
    <div class="card card-pad">
      <div class="card-head"><h3>🧩 Work Type Mix</h3></div>
      <?php if (!$typeSegments): ?>
        <div class="empty"><span class="emoji">🧩</span><h4>Nothing completed yet</h4><p>Finish some tasks to see your mix.</p></div>
      <?php else: ?>
        <div style="display:grid;place-items:center;margin-bottom:8px">
          <div id="typeDonut" style="width:150px"></div>
        </div>
        <div class="legend" style="flex-direction:column;gap:9px;margin-top:6px">
          <?php foreach ($typeCounts as $tk => $cnt): if ($cnt === 0) continue; $m = TYPE_META[$tk]; ?>
            <div class="li"><span class="sw" style="background:<?= esc($m['color']) ?>"></span><?= $m['icon'] ?> <?= esc($m['label']) ?><strong style="margin-left:auto"><?= $cnt ?></strong></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- What I built -->
  <div class="animate d1">
    <div class="card card-pad">
      <div class="card-head">
        <h3>✨ What I Built</h3>
        <span class="badge"><?= $builtCount ?></span>
      </div>
      <?php if (!$built): ?>
        <div class="empty"><span class="emoji">🚀</span><h4>Nothing shipped yet</h4><p>Completed features (New Builds) show up here.</p></div>
      <?php else: ?>
        <div class="dlist">
          <?php foreach (array_slice($built, 0, 14) as $t) $compactDone($t, false); ?>
        </div>
        <?php if ($builtCount > 14): ?>
          <div class="small muted center mt-4">+ <?= $builtCount - 14 ?> more built</div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- What got done -->
  <div class="animate d2">
    <div class="card card-pad">
      <div class="card-head">
        <h3>✅ What Got Done</h3>
        <span class="badge"><?= count($allDone) ?></span>
      </div>
      <?php if (!$allDone): ?>
        <div class="empty"><span class="emoji">🌤️</span><h4>No completed tasks</h4><p>Finish a task <?= $period === 'week' ? 'this week' : ($period === 'year' ? 'this year' : 'this month') ?> and it lands here.</p></div>
      <?php else: ?>
        <div class="dlist">
          <?php foreach (array_slice($allDone, 0, 14) as $t) $compactDone($t, true); ?>
        </div>
        <?php if (count($allDone) > 14): ?>
          <div class="small muted center mt-4">+ <?= count($allDone) - 14 ?> more completed</div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', drawAnalytics);
document.addEventListener('tw:theme', drawAnalytics);
function drawAnalytics() {
<?php if ($periodMin > 0): ?>
  TWChart.area('trendChart', <?= json_encode($trendData) ?>, { height: 220, color: getComputedStyle(document.documentElement).getPropertyValue('--violet').trim() });
<?php endif; ?>
<?php if ($projHours): ?>
  TWChart.donut('projDonut', <?= json_encode(array_map(fn($p) => ['label' => $p['name'], 'value' => (int)$p['m'], 'color' => $p['color'] ?: '#6C5CE7'], $projHours)) ?>, { size: 160, centerValue: <?= json_encode(fmt_hours($projTotal)) ?>, centerLabel: 'hours' });
<?php endif; ?>
<?php if ($typeSegments): ?>
  TWChart.donut('typeDonut', <?= json_encode($typeSegments) ?>, { size: 150, centerValue: <?= count($allDone) ?>, centerLabel: 'done' });
<?php endif; ?>
}
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>
