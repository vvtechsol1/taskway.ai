<?php
/**
 * Taskway — demo data seeder.
 * Run:  php seed.php        (CLI)
 *   or  open  index.php? ... no — browse to  seed.php?confirm=1  in a browser.
 * Wipes tasks/projects/time/activity and inserts a realistic 30-day history.
 */

require_once __DIR__ . '/config.php';

if (PHP_SAPI !== 'cli' && ($_GET['confirm'] ?? '') !== '1') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<p style="font:16px system-ui;max-width:520px;margin:60px auto">This will erase existing Taskway data and load demo data. '
       . '<a href="?confirm=1">Yes, seed demo data</a> · <a href="index.php">Cancel</a></p>';
    exit;
}

$pdo = db();
foreach (['time_entries', 'activity_log', 'tasks', 'projects'] as $t) {
    $pdo->exec("DELETE FROM $t");
    $pdo->exec("DELETE FROM sqlite_sequence WHERE name='$t'");
}

set_setting('user_name', 'Talha');
set_setting('daily_hours_goal', '6');

/* ---- Projects (mirrors the real htdocs work) --------------------- */
$projects = [
    ['Casebazar React', '🛒', '#6C5CE7', 'Mobile-cover e-commerce store — React storefront + admin.', 'active'],
    ['SEO AI',          '🔎', '#12B886', 'AI content & keyword automation platform.', 'active'],
    ['Voice Agent',     '🎙️', '#4DABF7', 'AI calling agent / voice worker.', 'active'],
    ['Digitizing Zone', '✂️', '#F5A623', 'Embroidery digitizing service website.', 'active'],
    ['Taskway',         '✅', '#FD79A8', 'This admin panel — personal work OS.', 'active'],
];
$pids = [];
foreach ($projects as [$name, $icon, $color, $desc, $status]) {
    $pids[$name] = find_or_create_project($name, ['icon' => $icon, 'color' => $color, 'description' => $desc, 'status' => $status]);
}

/* ---- Tasks: [project, title, status, type, priority, spentMin, daysAgo] ---- */
$T = [
    ['Casebazar React', 'Fix checkout crash on Safari', 'done', 'bug', 'urgent', 130, 1],
    ['Casebazar React', 'Build product filter sidebar', 'done', 'feature', 'high', 190, 2],
    ['Casebazar React', 'Integrate Stripe payment gateway', 'in_progress', 'feature', 'high', 240, 0],
    ['Casebazar React', 'Add wishlist feature', 'todo', 'feature', 'normal', 0, 0],
    ['Casebazar React', 'Optimize product image loading', 'done', 'improvement', 'normal', 75, 4],
    ['Casebazar React', 'Redesign cart drawer', 'todo', 'improvement', 'normal', 0, 0],
    ['Casebazar React', 'Order confirmation email template', 'done', 'feature', 'normal', 95, 6],

    ['SEO AI', 'Research competitor keywords', 'done', 'research', 'normal', 90, 3],
    ['SEO AI', 'Build bulk article generator', 'in_progress', 'feature', 'high', 300, 0],
    ['SEO AI', 'Update landing page copy', 'done', 'improvement', 'normal', 45, 2],
    ['SEO AI', 'Add sitemap auto-submit', 'todo', 'feature', 'normal', 0, 0],
    ['SEO AI', 'Fix token counting bug', 'done', 'bug', 'high', 60, 5],
    ['SEO AI', 'Design keyword dashboard', 'done', 'feature', 'normal', 160, 7],

    ['Voice Agent', 'Build call-flow state machine', 'done', 'feature', 'high', 260, 3],
    ['Voice Agent', 'Integrate Twilio streaming', 'in_progress', 'feature', 'high', 180, 1],
    ['Voice Agent', 'Reduce latency on TTS', 'todo', 'improvement', 'high', 0, 0],
    ['Voice Agent', 'Test interruption handling', 'blocked', 'research', 'normal', 40, 2],
    ['Voice Agent', 'Add call transcript logging', 'done', 'feature', 'normal', 110, 8],

    ['Digitizing Zone', 'New order upload form', 'done', 'feature', 'normal', 120, 5],
    ['Digitizing Zone', 'Fix quote calculator rounding', 'done', 'bug', 'normal', 35, 9],
    ['Digitizing Zone', 'Improve gallery load speed', 'todo', 'improvement', 'low', 0, 0],
    ['Digitizing Zone', 'Write service pages SEO copy', 'in_progress', 'research', 'normal', 70, 1],

    ['Taskway', 'Build brain-dump parser', 'done', 'feature', 'high', 220, 1],
    ['Taskway', 'Design dashboard UI', 'done', 'feature', 'high', 180, 1],
    ['Taskway', 'Add analytics charts', 'done', 'feature', 'normal', 150, 0],
    ['Taskway', 'Wire up time tracking', 'done', 'improvement', 'normal', 90, 0],
];

$taskIds = [];
foreach ($T as [$proj, $title, $status, $type, $pri, $spent, $daysAgo]) {
    $date = date('Y-m-d', strtotime("-{$daysAgo} days"));
    $id = create_task([
        'title' => $title, 'project_id' => $pids[$proj], 'status' => $status,
        'type' => $type, 'priority' => $pri, 'task_date' => $date,
    ]);
    $taskIds[] = [$id, $pids[$proj], $spent, $daysAgo, $status];
    // Backdate completion for done tasks.
    if ($status === 'done') {
        $pdo->prepare("UPDATE tasks SET completed_at=? WHERE id=?")->execute([$date . ' 15:00:00', $id]);
    }
}

/* ---- Time entries spread across the last 30 days ----------------- */
$pdo->exec("DELETE FROM time_entries");
$withSpent = array_filter($taskIds, fn($r) => $r[2] > 0);
foreach ($withSpent as [$id, $pid, $spent, $daysAgo, $status]) {
    // Split the logged time across 1-2 sessions near its day.
    $date = date('Y-m-d', strtotime("-{$daysAgo} days"));
    if ($spent > 120 && mt_rand(0, 1)) {
        $a = (int)round($spent * 0.6);
        $pdo->prepare("INSERT INTO time_entries(task_id,project_id,minutes,log_date) VALUES(?,?,?,?)")->execute([$id, $pid, $a, $date]);
        $pdo->prepare("INSERT INTO time_entries(task_id,project_id,minutes,log_date) VALUES(?,?,?,?)")->execute([$id, $pid, $spent - $a, date('Y-m-d', strtotime("-" . ($daysAgo + 1) . " days"))]);
    } else {
        $pdo->prepare("INSERT INTO time_entries(task_id,project_id,minutes,log_date) VALUES(?,?,?,?)")->execute([$id, $pid, $spent, $date]);
    }
}

// Add some ambient logged time on random past days so the trend charts look alive.
$allTasks = $pdo->query("SELECT id, project_id FROM tasks")->fetchAll();
for ($d = 0; $d < 30; $d++) {
    if ($d % 7 === 6 || $d % 7 === 0) continue; // lighter on weekends
    $date = date('Y-m-d', strtotime("-{$d} days"));
    $sessions = mt_rand(0, 2);
    for ($s = 0; $s < $sessions; $s++) {
        $tk = $allTasks[array_rand($allTasks)];
        $mins = mt_rand(25, 110);
        $pdo->prepare("INSERT INTO time_entries(task_id,project_id,minutes,log_date) VALUES(?,?,?,?)")->execute([$tk['id'], $tk['project_id'], $mins, $date]);
    }
}

// Recompute cached spent per task.
foreach ($pdo->query("SELECT id FROM tasks")->fetchAll() as $r) recompute_task_spent((int)$r['id']);

log_activity('braindump', 'Seeded demo workspace', []);

$stats = stats_overview();
echo (PHP_SAPI === 'cli' ? '' : '<pre style="font:14px monospace;padding:30px">');
echo "Seed complete ✔\n";
echo "Projects: " . count($projects) . "\n";
echo "Tasks: " . $pdo->query("SELECT COUNT(*) FROM tasks")->fetchColumn() . "\n";
echo "Time entries: " . $pdo->query("SELECT COUNT(*) FROM time_entries")->fetchColumn() . "\n";
echo "Hours this month: " . fmt_hours($stats['hours_month_min']) . "h\n";
echo "Done this month: {$stats['done_month']}  ·  Newly built: {$stats['built_month']}\n";
if (PHP_SAPI !== 'cli') echo '</pre><a href="index.php?page=dashboard">Open dashboard →</a>';
