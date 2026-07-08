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

/** Compact project card with progress + hours. */
function render_project_card(array $p): void
{
    $s = project_stats((int)$p['id']);
    ?>
    <a href="<?= page_url('project', ['id' => $p['id']]) ?>" class="card card-pad card-hover" style="display:block">
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
    </a>
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
