<?php
/**
 * Taskway — shared helpers: formatting, data access, and analytics.
 * Pages call these functions instead of writing raw SQL, so behaviour stays consistent.
 */

declare(strict_types=1);

/* ------------------------------------------------------------------ */
/* Output & routing helpers                                            */
/* ------------------------------------------------------------------ */

function esc($s): string
{
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

function page_url(string $page, array $params = []): string
{
    $params = array_merge(['page' => $page], $params);
    return url('index.php') . '?' . http_build_query($params);
}

function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

function json_response($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function input_json(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '[]', true);
    return is_array($data) ? $data : [];
}

/* ------------------------------------------------------------------ */
/* Formatting                                                          */
/* ------------------------------------------------------------------ */

/** 150 -> "2h 30m", 60 -> "1h", 45 -> "45m", 0 -> "0m" */
function fmt_min(int $min): string
{
    $min = max(0, $min);
    $h = intdiv($min, 60);
    $m = $min % 60;
    if ($h && $m) return "{$h}h {$m}m";
    if ($h) return "{$h}h";
    return "{$m}m";
}

/** Decimal hours, e.g. 150 -> "2.5" */
function fmt_hours(int $min, int $decimals = 1): string
{
    $h = $min / 60;
    $s = number_format($h, $decimals);
    return rtrim(rtrim($s, '0'), '.') ?: '0';
}

function human_date(?string $date): string
{
    if (!$date) return '';
    $ts = strtotime($date);
    $today = strtotime(date('Y-m-d'));
    $d = strtotime(date('Y-m-d', $ts));
    $diff = (int)round(($d - $today) / 86400);
    if ($diff === 0) return 'Today';
    if ($diff === -1) return 'Yesterday';
    if ($diff === 1) return 'Tomorrow';
    return date('M j', $ts) . ($ts < strtotime(date('Y') . '-01-01') || date('Y', $ts) !== date('Y') ? ', ' . date('Y', $ts) : '');
}

function relative_time(?string $datetime): string
{
    if (!$datetime) return '';
    $ts = strtotime($datetime);
    $diff = time() - $ts;
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j', $ts);
}

function slugify(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
    $text = trim($text, '-');
    return $text !== '' ? $text : 'item-' . substr((string)crc32($text . microtime()), 0, 6);
}

function greeting(): string
{
    $h = (int)date('G');
    if ($h < 5) return 'Still up';
    if ($h < 12) return 'Good morning';
    if ($h < 17) return 'Good afternoon';
    if ($h < 21) return 'Good evening';
    return 'Good night';
}

/* ------------------------------------------------------------------ */
/* Vocabulary / metadata for labels & colours                         */
/* ------------------------------------------------------------------ */

const STATUS_META = [
    'todo'        => ['label' => 'To Do',        'color' => '#7A7890'],
    'in_progress' => ['label' => 'In Progress',  'color' => '#4DABF7'],
    'done'        => ['label' => 'Done',         'color' => '#12B886'],
    'blocked'     => ['label' => 'Blocked',      'color' => '#FF6B6B'],
];

const TYPE_META = [
    'feature'     => ['label' => 'New Build',    'color' => '#6C5CE7', 'icon' => '✨'],
    'improvement' => ['label' => 'Improvement',  'color' => '#4DABF7', 'icon' => '🔧'],
    'bug'         => ['label' => 'Fix',          'color' => '#FF6B6B', 'icon' => '🐞'],
    'research'    => ['label' => 'Research',      'color' => '#F5A623', 'icon' => '🔎'],
    'task'        => ['label' => 'Task',          'color' => '#7A7890', 'icon' => '•'],
];

const PRIORITY_META = [
    'low'    => ['label' => 'Low',    'color' => '#7A7890'],
    'normal' => ['label' => 'Normal', 'color' => '#4DABF7'],
    'high'   => ['label' => 'High',   'color' => '#F5A623'],
    'urgent' => ['label' => 'Urgent', 'color' => '#FF6B6B'],
];

const PROJECT_PALETTE = ['#6C5CE7', '#12B886', '#4DABF7', '#FD79A8', '#F5A623', '#00B8D4', '#9B59F0', '#FF6B6B'];

function status_label(string $s): string { return STATUS_META[$s]['label'] ?? ucfirst($s); }
function type_label(string $s): string { return TYPE_META[$s]['label'] ?? ucfirst($s); }

/* ------------------------------------------------------------------ */
/* Period helpers                                                      */
/* ------------------------------------------------------------------ */

function period_range(string $period): array
{
    $today = date('Y-m-d');
    switch ($period) {
        case 'today':
            return [$today, $today];
        case 'week':
            $monday = date('Y-m-d', strtotime('monday this week'));
            return [$monday, $today];
        case 'month':
            return [date('Y-m-01'), $today];
        case 'year':
            return [date('Y-01-01'), $today];
        default:
            return ['1970-01-01', $today];
    }
}

/* ------------------------------------------------------------------ */
/* Activity feed                                                       */
/* ------------------------------------------------------------------ */

function log_activity(string $kind, string $title, array $meta = []): void
{
    $stmt = db()->prepare('INSERT INTO activity_log(kind, title, meta, user_id) VALUES(?, ?, ?, ?)');
    $stmt->execute([$kind, $title, json_encode($meta, JSON_UNESCAPED_UNICODE), scope_uid()]);
}

function recent_activity(int $limit = 12): array
{
    $stmt = db()->prepare('SELECT * FROM activity_log WHERE user_id = ? ORDER BY id DESC LIMIT ?');
    $stmt->execute([scope_uid(), $limit]);
    return $stmt->fetchAll();
}

/* ------------------------------------------------------------------ */
/* Super-admin global aggregates (across ALL users)                    */
/* ------------------------------------------------------------------ */

function admin_overview(): array
{
    [$mf, $mt] = period_range('month');
    $totalUsers = (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $activeUsers = (int)db()->query("SELECT COUNT(*) FROM users WHERE status='active'")->fetchColumn();
    $totalTasks = (int)db()->query('SELECT COUNT(*) FROM tasks')->fetchColumn();
    $doneTasks = (int)db()->query("SELECT COUNT(*) FROM tasks WHERE status='done'")->fetchColumn();
    $totalProjects = (int)db()->query('SELECT COUNT(*) FROM projects')->fetchColumn();

    $mm = db()->prepare('SELECT COALESCE(SUM(minutes),0) FROM time_entries WHERE log_date BETWEEN ? AND ?');
    $mm->execute([$mf, $mt]);
    $monthMinutes = (int)$mm->fetchColumn();

    return [
        'total_users'    => $totalUsers,
        'active_users'   => $activeUsers,
        'total_tasks'    => $totalTasks,
        'done_tasks'     => $doneTasks,
        'total_projects' => $totalProjects,
        'month_minutes'  => $monthMinutes,
    ];
}

/** Per-user leaderboard: hours (this month) + task counts. */
function admin_user_rows(): array
{
    [$mf, $mt] = period_range('month');
    $out = [];
    foreach (get_users() as $u) {
        $mm = db()->prepare('SELECT COALESCE(SUM(minutes),0) FROM time_entries WHERE user_id = ? AND log_date BETWEEN ? AND ?');
        $mm->execute([$u['id'], $mf, $mt]);
        $c = user_data_counts((int)$u['id']);
        $out[] = $u + ['month_minutes' => (int)$mm->fetchColumn()] + $c;
    }
    usort($out, fn($a, $b) => $b['month_minutes'] <=> $a['month_minutes']);
    return $out;
}

/* ------------------------------------------------------------------ */
/* Projects                                                            */
/* ------------------------------------------------------------------ */

function get_projects(?string $status = null): array
{
    $uid = scope_uid();
    if ($status) {
        $stmt = db()->prepare('SELECT * FROM projects WHERE user_id = ? AND status = ? ORDER BY position, name');
        $stmt->execute([$uid, $status]);
        return $stmt->fetchAll();
    }
    $stmt = db()->prepare("SELECT * FROM projects WHERE user_id = ? ORDER BY
        CASE status WHEN 'active' THEN 0 WHEN 'paused' THEN 1 WHEN 'done' THEN 2 ELSE 3 END,
        position, name");
    $stmt->execute([$uid]);
    return $stmt->fetchAll();
}

function get_project($idOrSlug): ?array
{
    $col = ctype_digit((string)$idOrSlug) ? 'id' : 'slug';
    $stmt = db()->prepare("SELECT * FROM projects WHERE {$col} = ? AND user_id = ? LIMIT 1");
    $stmt->execute([$idOrSlug, scope_uid()]);
    return $stmt->fetch() ?: null;
}

/** Find an existing project by name (case-insensitive) or create one. Returns project id. */
function find_or_create_project(string $name, array $extra = []): int
{
    $name = trim($name);
    if ($name === '') {
        return 0;
    }
    $uid = scope_uid();
    $stmt = db()->prepare('SELECT id FROM projects WHERE LOWER(name) = LOWER(?) AND user_id = ? LIMIT 1');
    $stmt->execute([$name, $uid]);
    $found = $stmt->fetchColumn();
    if ($found) {
        return (int)$found;
    }
    $count = (int)(function () use ($uid) {
        $s = db()->prepare('SELECT COUNT(*) FROM projects WHERE user_id = ?'); $s->execute([$uid]); return $s->fetchColumn();
    })();
    $color = $extra['color'] ?? PROJECT_PALETTE[$count % count(PROJECT_PALETTE)];
    $slug = slugify($name);
    // Ensure globally-unique slug (the column is UNIQUE across all users).
    $slugExists = function (string $s): bool {
        $q = db()->prepare('SELECT 1 FROM projects WHERE slug = ? LIMIT 1'); $q->execute([$s]); return (bool)$q->fetchColumn();
    };
    $base = $slug; $i = 2;
    while ($slugExists($slug)) { $slug = $base . '-' . $i++; }

    $stmt = db()->prepare('INSERT INTO projects(name, slug, description, color, icon, status, position, user_id)
        VALUES(?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $name, $slug, $extra['description'] ?? '', $color,
        $extra['icon'] ?? '📁', $extra['status'] ?? 'active', $count, $uid,
    ]);
    $id = (int)db()->lastInsertId();
    log_activity('project_created', $name, ['id' => $id]);
    return $id;
}

function project_stats(int $projectId): array
{
    $t = db()->prepare("SELECT
            COUNT(*) total,
            SUM(CASE WHEN status='done' THEN 1 ELSE 0 END) done,
            SUM(CASE WHEN status='in_progress' THEN 1 ELSE 0 END) in_progress,
            SUM(CASE WHEN status='todo' THEN 1 ELSE 0 END) todo,
            SUM(CASE WHEN status='blocked' THEN 1 ELSE 0 END) blocked,
            COALESCE(SUM(spent_min),0) spent
        FROM tasks WHERE project_id = ?");
    $t->execute([$projectId]);
    $row = $t->fetch() ?: [];
    $total = (int)($row['total'] ?? 0);
    $done = (int)($row['done'] ?? 0);
    return [
        'total'       => $total,
        'done'        => $done,
        'in_progress' => (int)($row['in_progress'] ?? 0),
        'todo'        => (int)($row['todo'] ?? 0),
        'blocked'     => (int)($row['blocked'] ?? 0),
        'spent_min'   => (int)($row['spent'] ?? 0),
        'progress'    => $total > 0 ? (int)round($done / $total * 100) : 0,
    ];
}

/* ------------------------------------------------------------------ */
/* Tasks                                                               */
/* ------------------------------------------------------------------ */

function get_task(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM tasks WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, scope_uid()]);
    return $stmt->fetch() ?: null;
}

/**
 * Flexible task query.
 * Filters: project_id, status (string|array), type, date, from, to, priority, search, limit
 */
function get_tasks(array $f = []): array
{
    $where = ['t.user_id = ?'];
    $args = [scope_uid()];
    // Columns are qualified with t. because the projects join also has status/description/etc.
    if (isset($f['project_id'])) { $where[] = 't.project_id = ?'; $args[] = $f['project_id']; }
    if (!empty($f['status'])) {
        $st = (array)$f['status'];
        $where[] = 't.status IN (' . implode(',', array_fill(0, count($st), '?')) . ')';
        array_push($args, ...$st);
    }
    if (!empty($f['not_status'])) {
        $st = (array)$f['not_status'];
        $where[] = 't.status NOT IN (' . implode(',', array_fill(0, count($st), '?')) . ')';
        array_push($args, ...$st);
    }
    if (!empty($f['type']))     { $where[] = 't.type = ?'; $args[] = $f['type']; }
    if (!empty($f['priority'])) { $where[] = 't.priority = ?'; $args[] = $f['priority']; }
    if (!empty($f['date']))     { $where[] = 't.task_date = ?'; $args[] = $f['date']; }
    if (!empty($f['from']))     { $where[] = 't.task_date >= ?'; $args[] = $f['from']; }
    if (!empty($f['to']))       { $where[] = 't.task_date <= ?'; $args[] = $f['to']; }
    if (!empty($f['search']))   { $where[] = '(t.title LIKE ? OR t.description LIKE ?)'; $args[] = '%' . $f['search'] . '%'; $args[] = '%' . $f['search'] . '%'; }

    $sql = 'SELECT t.*, p.name AS project_name, p.color AS project_color, p.icon AS project_icon
            FROM tasks t LEFT JOIN projects p ON p.id = t.project_id';
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= " ORDER BY
        CASE t.status WHEN 'in_progress' THEN 0 WHEN 'blocked' THEN 1 WHEN 'todo' THEN 2 ELSE 3 END,
        CASE t.priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END,
        t.position, t.id DESC";
    if (!empty($f['limit'])) $sql .= ' LIMIT ' . (int)$f['limit'];

    $stmt = db()->prepare($sql);
    $stmt->execute($args);
    return $stmt->fetchAll();
}

function today_tasks(): array
{
    // Everything due today plus anything still open (carried over) but not completed.
    $today = date('Y-m-d');
    $stmt = db()->prepare("SELECT t.*, p.name AS project_name, p.color AS project_color, p.icon AS project_icon
        FROM tasks t LEFT JOIN projects p ON p.id = t.project_id
        WHERE t.user_id = ? AND (t.task_date = ? OR (t.status IN ('todo','in_progress','blocked') AND t.task_date <= ?))
        ORDER BY
          CASE t.status WHEN 'in_progress' THEN 0 WHEN 'blocked' THEN 1 WHEN 'todo' THEN 2 ELSE 3 END,
          CASE t.priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END,
          t.position, t.id DESC");
    $stmt->execute([scope_uid(), $today, $today]);
    return $stmt->fetchAll();
}

/**
 * Create a task. $data keys: title, project_id|project_name, description, status,
 * type, priority, estimate_min, spent_min, task_date. Returns task id.
 */
function create_task(array $data): int
{
    $title = trim((string)($data['title'] ?? ''));
    if ($title === '') return 0;

    $projectId = $data['project_id'] ?? null;
    if (!$projectId && !empty($data['project_name'])) {
        $projectId = find_or_create_project((string)$data['project_name']) ?: null;
    }

    $status = $data['status'] ?? 'todo';
    $completedAt = $status === 'done' ? date('Y-m-d H:i:s') : null;

    $stmt = db()->prepare('INSERT INTO tasks
        (project_id, title, description, status, type, priority, estimate_min, spent_min, task_date, completed_at, user_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $projectId,
        $title,
        trim((string)($data['description'] ?? '')),
        $status,
        $data['type'] ?? 'task',
        $data['priority'] ?? 'normal',
        (int)($data['estimate_min'] ?? 0),
        (int)($data['spent_min'] ?? 0),
        $data['task_date'] ?? date('Y-m-d'),
        $completedAt,
        scope_uid(),
    ]);
    $id = (int)db()->lastInsertId();

    // If time was pre-logged (e.g. parsed "2h"), record it in the ledger.
    if (!empty($data['spent_min'])) {
        add_time_entry($id, (int)$data['spent_min'], $data['task_date'] ?? date('Y-m-d'), 'Logged on create');
    }

    log_activity('task_created', $title, ['id' => $id, 'project_id' => $projectId, 'status' => $status]);
    return $id;
}

function update_task(int $id, array $fields): bool
{
    $task = get_task($id);
    if (!$task) return false;

    $allowed = ['title', 'description', 'status', 'type', 'priority', 'estimate_min', 'project_id', 'task_date'];
    $set = [];
    $args = [];
    foreach ($allowed as $col) {
        if (array_key_exists($col, $fields)) {
            $set[] = "$col = ?";
            $args[] = $fields[$col];
        }
    }

    // Manage completed_at + log when status flips.
    if (isset($fields['status'])) {
        if ($fields['status'] === 'done' && $task['status'] !== 'done') {
            $set[] = 'completed_at = ?';
            $args[] = date('Y-m-d H:i:s');
            log_activity('task_done', $task['title'], ['id' => $id]);
        } elseif ($fields['status'] !== 'done') {
            $set[] = 'completed_at = NULL';
        }
    }

    if (!$set) return false;
    $set[] = "updated_at = datetime('now','localtime')";
    $args[] = $id;
    $stmt = db()->prepare('UPDATE tasks SET ' . implode(', ', $set) . ' WHERE id = ?');
    return $stmt->execute($args);
}

function delete_task(int $id): bool
{
    $stmt = db()->prepare('DELETE FROM tasks WHERE id = ? AND user_id = ?');
    return $stmt->execute([$id, scope_uid()]);
}

/* ------------------------------------------------------------------ */
/* Time tracking                                                       */
/* ------------------------------------------------------------------ */

function add_time_entry(int $taskId, int $minutes, ?string $date = null, string $note = ''): int
{
    $task = get_task($taskId);
    $stmt = db()->prepare('INSERT INTO time_entries(task_id, project_id, minutes, log_date, note, user_id)
        VALUES(?, ?, ?, ?, ?, ?)');
    $stmt->execute([$taskId, $task['project_id'] ?? null, $minutes, $date ?: date('Y-m-d'), $note, scope_uid()]);
    $id = (int)db()->lastInsertId();
    recompute_task_spent($taskId);
    return $id;
}

function recompute_task_spent(int $taskId): void
{
    $stmt = db()->prepare('UPDATE tasks SET spent_min =
        (SELECT COALESCE(SUM(minutes),0) FROM time_entries WHERE task_id = ?) WHERE id = ?');
    $stmt->execute([$taskId, $taskId]);
}

/** Currently running timer (a time_entry with started_at set, no minutes yet), if any. */
function running_timer(): ?array
{
    $stmt = db()->prepare("SELECT te.*, t.title AS task_title, t.id AS task_id
        FROM time_entries te JOIN tasks t ON t.id = te.task_id
        WHERE te.user_id = ? AND te.started_at IS NOT NULL AND te.minutes = 0 ORDER BY te.id DESC LIMIT 1");
    $stmt->execute([scope_uid()]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/* ------------------------------------------------------------------ */
/* Analytics / stats                                                   */
/* ------------------------------------------------------------------ */

function minutes_in_period(string $from, string $to): int
{
    $stmt = db()->prepare('SELECT COALESCE(SUM(minutes),0) FROM time_entries WHERE user_id = ? AND log_date BETWEEN ? AND ?');
    $stmt->execute([scope_uid(), $from, $to]);
    return (int)$stmt->fetchColumn();
}

function tasks_done_in_period(string $from, string $to): int
{
    $stmt = db()->prepare("SELECT COUNT(*) FROM tasks WHERE user_id = ? AND status='done'
        AND date(COALESCE(completed_at, task_date)) BETWEEN ? AND ?");
    $stmt->execute([scope_uid(), $from, $to]);
    return (int)$stmt->fetchColumn();
}

function stats_overview(): array
{
    [$mf, $mt] = period_range('month');
    [$wf, $wt] = period_range('week');
    $today = date('Y-m-d');

    $uid = scope_uid();
    $ap = db()->prepare("SELECT COUNT(*) FROM projects WHERE user_id = ? AND status='active'"); $ap->execute([$uid]);
    $activeProjects = (int)$ap->fetchColumn();
    $ot = db()->prepare("SELECT COUNT(*) FROM tasks WHERE user_id = ? AND status IN ('todo','in_progress','blocked')"); $ot->execute([$uid]);
    $openTasks = (int)$ot->fetchColumn();

    // "Newly built" this month = completed feature-type tasks.
    $stmt = db()->prepare("SELECT COUNT(*) FROM tasks WHERE user_id = ? AND type='feature' AND status='done'
        AND date(COALESCE(completed_at, task_date)) BETWEEN ? AND ?");
    $stmt->execute([$uid, $mf, $mt]);
    $builtThisMonth = (int)$stmt->fetchColumn();

    $u = get_user($uid);
    $goalMin = (int)(($u['daily_goal'] ?? setting('daily_hours_goal', '6'))) * 60;

    return [
        'hours_today_min'   => minutes_in_period($today, $today),
        'hours_week_min'    => minutes_in_period($wf, $wt),
        'hours_month_min'   => minutes_in_period($mf, $mt),
        'done_today'        => tasks_done_in_period($today, $today),
        'done_week'         => tasks_done_in_period($wf, $wt),
        'done_month'        => tasks_done_in_period($mf, $mt),
        'active_projects'   => $activeProjects,
        'open_tasks'        => $openTasks,
        'built_month'       => $builtThisMonth,
        'daily_goal_min'    => $goalMin,
        'goal_pct'          => $goalMin > 0 ? min(100, (int)round(minutes_in_period($today, $today) / $goalMin * 100)) : 0,
        'streak'            => active_day_streak(),
    ];
}

/** Consecutive days (ending today or yesterday) with at least one logged minute or completed task. */
function active_day_streak(): int
{
    $uid = scope_uid();
    $stmt = db()->prepare("SELECT log_date FROM time_entries WHERE user_id = ? AND minutes > 0
        UNION SELECT date(COALESCE(completed_at, task_date)) FROM tasks WHERE user_id = ? AND status='done'");
    $stmt->execute([$uid, $uid]);
    $days = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $set = array_flip($days);
    $streak = 0;
    $cursor = time();
    // Allow the streak to still count if nothing done yet today.
    if (!isset($set[date('Y-m-d', $cursor)])) {
        $cursor -= 86400;
    }
    while (isset($set[date('Y-m-d', $cursor)])) {
        $streak++;
        $cursor -= 86400;
    }
    return $streak;
}

/** Daily minute totals for the last N days, oldest first. */
function hours_by_day(int $days = 14): array
{
    $stmt = db()->prepare('SELECT log_date, COALESCE(SUM(minutes),0) m FROM time_entries
        WHERE user_id = ? AND log_date >= ? GROUP BY log_date');
    $from = date('Y-m-d', strtotime("-" . ($days - 1) . " days"));
    $stmt->execute([scope_uid(), $from]);
    $map = [];
    foreach ($stmt->fetchAll() as $r) $map[$r['log_date']] = (int)$r['m'];

    $out = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $out[] = ['date' => $d, 'label' => date('D', strtotime($d)), 'minutes' => $map[$d] ?? 0];
    }
    return $out;
}

function status_breakdown(): array
{
    $stmt = db()->prepare("SELECT status, COUNT(*) c FROM tasks WHERE user_id = ? GROUP BY status");
    $stmt->execute([scope_uid()]);
    $rows = $stmt->fetchAll();
    $out = [];
    foreach (STATUS_META as $key => $meta) $out[$key] = ['label' => $meta['label'], 'color' => $meta['color'], 'count' => 0];
    foreach ($rows as $r) if (isset($out[$r['status']])) $out[$r['status']]['count'] = (int)$r['c'];
    return $out;
}

/** Minutes logged per project within a period. */
function hours_by_project(string $period = 'month'): array
{
    [$from, $to] = period_range($period);
    $stmt = db()->prepare("SELECT p.id, p.name, p.color, p.icon, COALESCE(SUM(te.minutes),0) m
        FROM projects p LEFT JOIN time_entries te ON te.project_id = p.id AND te.log_date BETWEEN ? AND ?
        WHERE p.user_id = ?
        GROUP BY p.id HAVING m > 0 ORDER BY m DESC");
    $stmt->execute([$from, $to, scope_uid()]);
    return $stmt->fetchAll();
}

/** Completed tasks within a period, optionally filtered by type (e.g. 'feature' for "newly built"). */
function completed_tasks(string $period = 'month', ?string $type = null): array
{
    [$from, $to] = period_range($period);
    $sql = "SELECT t.*, p.name AS project_name, p.color AS project_color, p.icon AS project_icon
        FROM tasks t LEFT JOIN projects p ON p.id = t.project_id
        WHERE t.user_id = ? AND t.status='done' AND date(COALESCE(t.completed_at, t.task_date)) BETWEEN ? AND ?";
    $args = [scope_uid(), $from, $to];
    if ($type) { $sql .= ' AND t.type = ?'; $args[] = $type; }
    $sql .= ' ORDER BY COALESCE(t.completed_at, t.task_date) DESC';
    $stmt = db()->prepare($sql);
    $stmt->execute($args);
    return $stmt->fetchAll();
}
