<?php
/** Dashboard — daily command center. */
$ACTIVE = 'dashboard';
$PAGE_TITLE = 'Dashboard';
$PAGE_SUB = date('l, F j');

$stats     = stats_overview();
$today     = today_tasks();
$byDay     = hours_by_day(14);
$statusBd  = status_breakdown();
$projects  = get_projects('active');
$activity  = recent_activity(7);
$scopeUser = get_user(scope_uid());
$userName  = $scopeUser['name'] ?: $scopeUser['username'];
$goalMin   = $stats['daily_goal_min'];
$timer     = running_timer();

// Split today's list.
$openToday = array_filter($today, fn($t) => $t['status'] !== 'done');
$doneToday = array_filter($today, fn($t) => $t['status'] === 'done');

if ($timer) {
    $TOPBAR_ACTIONS = '<div class="chip" style="border-color:var(--coral)"><span class="live-dot"></span> <span id="timerTicker" data-started="' . esc($timer['started_at']) . '">00:00</span> · ' . esc(mb_strimwidth($timer['task_title'], 0, 22, '…')) . '</div>';
}

require __DIR__ . '/../partials/header.php';
?>

<!-- Hero -->
<div class="card animate" style="background:var(--grad-hero);color:#fff;border:0;overflow:hidden;position:relative;margin-bottom:20px">
  <div class="card-pad" style="padding:26px 28px;display:flex;align-items:center;gap:24px;flex-wrap:wrap">
    <div class="grow" style="min-width:240px">
      <div style="opacity:.85;font-weight:600;font-size:13px"><?= esc(greeting()) ?>,</div>
      <h2 style="font-size:28px;margin:2px 0 8px"><?= esc(ucfirst($userName)) ?> 👋</h2>
      <div style="opacity:.92;font-size:14px">
        You have <strong><?= count($openToday) ?></strong> task<?= count($openToday) === 1 ? '' : 's' ?> for today
        <?php if ($stats['streak'] > 1): ?> · 🔥 <strong><?= $stats['streak'] ?>-day streak</strong><?php endif; ?>
      </div>
      <div class="row mt-4" style="gap:10px">
        <a href="<?= page_url('braindump') ?>" class="btn btn-lg" style="background:#fff;color:var(--violet-600)">🧠 Brain Dump</a>
        <a href="<?= page_url('tasks') ?>" class="btn btn-lg" style="background:rgba(255,255,255,.18);color:#fff">View all tasks</a>
      </div>
    </div>
    <!-- Daily goal ring -->
    <div style="text-align:center">
      <div class="ring" style="--p:<?= $stats['goal_pct'] ?>;--size:112px;--fill:#fff;--track:rgba(255,255,255,.25);margin:0 auto">
        <span style="color:#fff;font-size:20px"><?= $stats['goal_pct'] ?>%</span>
      </div>
      <div style="opacity:.9;font-size:12.5px;margin-top:8px">
        <?= esc(fmt_hours($stats['hours_today_min'])) ?>h / <?= esc(fmt_hours($goalMin)) ?>h today
      </div>
    </div>
  </div>
</div>

<!-- Stat tiles -->
<div class="grid cols-4 mb-6">
  <div class="stat violet animate d1"><span class="stat-ic">⏱️</span>
    <div class="stat-label">Hours Today</div>
    <div class="stat-value" data-stat="hours_today_min"><?= esc(fmt_hours($stats['hours_today_min'])) ?><small>h</small></div>
    <div class="stat-meta">Goal <?= esc(fmt_hours($goalMin)) ?>h · <span data-stat="goal_pct"><?= $stats['goal_pct'] ?></span>%</div>
  </div>
  <div class="stat mint animate d2"><span class="stat-ic">📅</span>
    <div class="stat-label">Hours This Month</div>
    <div class="stat-value" data-stat="hours_month_min"><?= esc(fmt_hours($stats['hours_month_min'])) ?><small>h</small></div>
    <div class="stat-meta"><?= esc(fmt_hours($stats['hours_week_min'])) ?>h this week</div>
  </div>
  <div class="stat sky animate d3"><span class="stat-ic">✅</span>
    <div class="stat-label">Done This Month</div>
    <div class="stat-value" data-stat="done_month"><?= $stats['done_month'] ?></div>
    <div class="stat-meta"><span data-stat="done_week"><?= $stats['done_week'] ?></span> completed this week</div>
  </div>
  <div class="stat coral animate d4"><span class="stat-ic">✨</span>
    <div class="stat-label">Newly Built This Month</div>
    <div class="stat-value" data-stat="built_month"><?= $stats['built_month'] ?></div>
    <div class="stat-meta"><?= $stats['open_tasks'] ?> tasks open</div>
  </div>
</div>

<div class="grid cols-3">
  <!-- Today's tasks (span 2) -->
  <div class="span-2 animate">
    <div class="card card-pad">
      <div class="card-head">
        <h3>🎯 Today's Focus</h3>
        <span class="badge"><?= count($openToday) ?> open</span>
        <div class="card-action"><a href="<?= page_url('braindump') ?>" class="btn btn-soft btn-sm">＋ Add</a></div>
      </div>
      <?php if (!$openToday && !$doneToday): ?>
        <div class="empty"><span class="emoji">🌤️</span><h4>Nothing scheduled yet</h4>
          <p>Paste your notes into the Brain Dump and Taskway will build your list.</p>
          <a href="<?= page_url('braindump') ?>" class="btn btn-primary mt-4">🧠 Open Brain Dump</a>
        </div>
      <?php else: ?>
        <?php foreach ($openToday as $t) render_task($t, ['remove_on_done' => false]); ?>
        <?php if ($doneToday): ?>
          <div class="divider"></div>
          <div class="small muted mb-4" style="font-weight:700;text-transform:uppercase;letter-spacing:.05em">Completed today · <?= count($doneToday) ?></div>
          <?php foreach ($doneToday as $t) render_task($t); ?>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Right rail -->
  <div class="animate d1" style="display:flex;flex-direction:column;gap:20px">
    <div class="card card-pad chart">
      <div class="card-head"><h3>⏳ This Week</h3>
        <span class="card-action small strong" style="color:var(--mint)"><?= esc(fmt_hours($stats['hours_week_min'])) ?>h</span>
      </div>
      <div id="weekChart"></div>
    </div>
    <div class="card card-pad chart">
      <div class="card-head"><h3>📊 Task Breakdown</h3></div>
      <div class="row" style="gap:16px">
        <div id="statusDonut" style="width:130px;flex:0 0 130px"></div>
        <div class="legend" style="flex-direction:column;gap:9px;margin-top:0">
          <?php foreach ($statusBd as $k => $sb): ?>
            <div class="li"><span class="sw" style="background:<?= esc($sb['color']) ?>"></span><?= esc($sb['label']) ?> <strong style="margin-left:auto"><?= $sb['count'] ?></strong></div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Projects + activity -->
<div class="grid cols-3 mt-6">
  <div class="span-2 animate">
    <div class="card-head"><h3>📁 Active Projects</h3>
      <div class="card-action"><a href="<?= page_url('projects') ?>" class="btn btn-ghost btn-sm">See all</a></div>
    </div>
    <?php if (!$projects): ?>
      <div class="card card-pad empty"><span class="emoji">📁</span><h4>No projects yet</h4><p>Projects appear automatically when you brain-dump tasks under a heading.</p></div>
    <?php else: ?>
      <div class="grid cols-2">
        <?php foreach (array_slice($projects, 0, 4) as $p) render_project_card($p); ?>
      </div>
    <?php endif; ?>
  </div>
  <div class="animate d1">
    <div class="card card-pad">
      <div class="card-head"><h3>🕒 Recent Activity</h3></div>
      <?php if (!$activity): ?>
        <div class="muted small center" style="padding:20px">Your activity will show up here.</div>
      <?php else: foreach ($activity as $a) render_activity($a); endif; ?>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', drawDash);
document.addEventListener('tw:theme', drawDash);
function drawDash() {
  TWChart.bars('weekChart', <?= json_encode(array_map(fn($d) => ['label' => substr($d['label'],0,2), 'value' => $d['minutes'], 'today' => $d['date'] === date('Y-m-d')], array_slice($byDay, -7))) ?>, { goal: <?= $goalMin ?>, height: 150 });
  TWChart.donut('statusDonut', <?= json_encode(array_values(array_map(fn($s) => ['label' => $s['label'], 'value' => $s['count'], 'color' => $s['color']], $statusBd))) ?>, { size: 130, centerLabel: 'tasks' });
}
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>
