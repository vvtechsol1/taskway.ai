<?php
/**
 * Taskway — JSON API. All actions are POST (JSON body) except read-only GETs.
 * Usage: fetch(api.php?action=set_status, {method:'POST', body: JSON}) -> {ok:true, ...}
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/parser.php';

$action = preg_replace('/[^a-z0-9_]/', '', (string)($_GET['action'] ?? ''));
$in = input_json();

/* ---- Claude bridge (secret-authenticated, no session) ------------- */
if (str_starts_with($action, 'bridge_')) {
    $secret = (string)($in['secret'] ?? '');
    if ($secret === '' || !hash_equals((string)setting('bridge_secret'), $secret)) {
        json_response(['ok' => false, 'error' => 'Bad secret.'], 403);
    }
    if ($action === 'bridge_pull') {
        $rows = db()->query("SELECT * FROM proposal_queue WHERE status = 'pending' ORDER BY id LIMIT 5")->fetchAll();
        $jobs = [];
        foreach ($rows as $r) {
            $uid = (int)$r['user_id'];
            $u = get_user($uid);
            $ps = db()->prepare("SELECT name, description, technologies, website_url, status FROM projects
                WHERE user_id = ? AND deleted_at IS NULL AND in_portfolio = 1 ORDER BY position, name");
            $ps->execute([$uid]);
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $jobs[] = [
                'id' => (int)$r['id'],
                'job' => $r['job'], 'budget' => $r['budget'], 'notes' => $r['notes'],
                'user' => ['name' => $u['name'] ?? '', 'username' => $u['username'] ?? '',
                    'uw_name' => $u['uw_name'] ?? '', 'uw_title' => $u['uw_title'] ?? '', 'uw_years' => $u['uw_years'] ?? '',
                    'uw_skills' => $u['uw_skills'] ?? '', 'uw_overview' => mb_substr((string)($u['uw_overview'] ?? ''), 0, 600),
                    'company' => $u['portfolio_headline'] ?? '', 'company_bio' => mb_substr((string)($u['portfolio_bio'] ?? ''), 0, 400)],
                'projects' => $ps->fetchAll(),
                'portfolio_url' => $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '') . url('p.php') . '?u=' . ($u['portfolio_token'] ?? ''),
                'custom_rules' => (function () use ($uid) {
                    $s = db()->prepare('SELECT rule FROM upwork_rules WHERE user_id = ? ORDER BY id');
                    $s->execute([$uid]);
                    return $s->fetchAll(PDO::FETCH_COLUMN);
                })(),
            ];
            db()->prepare("UPDATE proposal_queue SET status = 'processing' WHERE id = ?")->execute([$r['id']]);
        }
        // Portfolio enrichment jobs: projects Claude should research, screenshot and complete.
        $pfJobs = [];
        foreach (db()->query("SELECT q.id, q.project_id, q.url, q.note, q.user_id,
                p.name AS project_name, p.description, p.technologies, p.thumb_path, p.shots
                FROM portfolio_queue q LEFT JOIN projects p ON p.id = q.project_id
                WHERE q.status = 'pending' ORDER BY q.id LIMIT 5")->fetchAll() as $q) {
            $pfJobs[] = $q;
            db()->prepare("UPDATE portfolio_queue SET status = 'processing' WHERE id = ?")->execute([$q['id']]);
        }
        json_response(['ok' => true, 'jobs' => $jobs, 'pf_jobs' => $pfJobs]);
    }
    if ($action === 'bridge_pf_done') {
        $id = (int)($in['id'] ?? 0);
        $st = in_array($in['status'] ?? 'done', ['done', 'failed'], true) ? $in['status'] : 'done';
        if (!$id) json_response(['ok' => false, 'error' => 'id required.'], 400);
        db()->prepare("UPDATE portfolio_queue SET status = ?, done_at = ? WHERE id = ?")
            ->execute([$st, date('Y-m-d H:i:s'), $id]);
        json_response(['ok' => true]);
    }
    if ($action === 'bridge_push') {
        $id = (int)($in['id'] ?? 0);
        $result = $in['result'] ?? null;
        if (!$id || !is_array($result) || empty($result['cover_letter'])) {
            json_response(['ok' => false, 'error' => 'id + result{cover_letter,...} required.'], 400);
        }
        db()->prepare("UPDATE proposal_queue SET status = 'done', result = ?, done_at = ? WHERE id = ?")
            ->execute([json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), date('Y-m-d H:i:s'), $id]);
        json_response(['ok' => true]);
    }
    if ($action === 'bridge_reset') {   // stuck processing -> pending again
        db()->exec("UPDATE proposal_queue SET status = 'pending' WHERE status = 'processing'");
        json_response(['ok' => true]);
    }
    json_response(['ok' => false, 'error' => 'Unknown bridge action.'], 404);
}

require_login();

try {
    switch ($action) {

        /* ---- Brain Dump ------------------------------------------ */
        case 'parse':
            $text = (string)($in['text'] ?? '');
            if (trim($text) === '') json_response(['ok' => false, 'error' => 'Nothing to parse.'], 400);
            $result = parse_braindump($text, [
                'default_status' => in_array($in['default_status'] ?? '', ['todo', 'done'], true) ? $in['default_status'] : 'todo',
                'project'        => trim((string)($in['project'] ?? '')),
                'date'           => $in['date'] ?? date('Y-m-d'),
            ]);
            json_response(['ok' => true] + $result);

        case 'commit':
            $tasks = $in['tasks'] ?? [];
            if (!is_array($tasks) || !$tasks) json_response(['ok' => false, 'error' => 'No tasks to add.'], 400);
            $created = 0; $newProjects = [];
            $projCount = db()->prepare('SELECT COUNT(*) FROM projects WHERE user_id = ?');
            $projCount->execute([scope_uid()]); $before = (int)$projCount->fetchColumn();
            foreach ($tasks as $t) {
                if (empty($t['title'])) continue;
                $id = create_task([
                    'title'        => (string)$t['title'],
                    'project_name' => trim((string)($t['project_name'] ?? '')),
                    'auto_project' => false,   // the Brain Dump preview already decided the project
                    'status'       => in_array($t['status'] ?? '', ['todo','in_progress','done','blocked'], true) ? $t['status'] : 'todo',
                    'type'         => in_array($t['type'] ?? '', ['feature','improvement','bug','research','task'], true) ? $t['type'] : 'task',
                    'priority'     => in_array($t['priority'] ?? '', ['low','normal','high','urgent'], true) ? $t['priority'] : 'normal',
                    'spent_min'    => (int)($t['spent_min'] ?? 0),
                    'estimate_min' => (int)($t['estimate_min'] ?? 0),
                    'task_date'    => $t['task_date'] ?? date('Y-m-d'),
                ]);
                if ($id) $created++;
            }
            $projCount->execute([scope_uid()]); $after = (int)$projCount->fetchColumn();
            log_activity('braindump', "Added {$created} tasks from Brain Dump", ['count' => $created]);
            json_response(['ok' => true, 'created' => $created, 'new_projects' => max(0, $after - $before)]);

        /* ---- Tasks ----------------------------------------------- */
        case 'create_task':
            $id = create_task($in);
            if (!$id) json_response(['ok' => false, 'error' => 'Title required.'], 400);
            json_response(['ok' => true, 'id' => $id, 'task' => get_task($id)]);

        case 'update_task':
            $id = (int)($in['id'] ?? 0);
            if (!$id || !update_task($id, $in)) json_response(['ok' => false, 'error' => 'Update failed.'], 400);
            json_response(['ok' => true, 'task' => get_task($id)]);

        case 'set_status':
            $id = (int)($in['id'] ?? 0);
            $status = (string)($in['status'] ?? '');
            if (!$id || !in_array($status, ['todo','in_progress','done','blocked'], true)) {
                json_response(['ok' => false, 'error' => 'Bad request.'], 400);
            }
            update_task($id, ['status' => $status]);
            json_response(['ok' => true, 'task' => get_task($id), 'stats' => stats_overview()]);

        case 'delete_task':
            $id = (int)($in['id'] ?? 0);
            if (!$id) json_response(['ok' => false, 'error' => 'Bad id.'], 400);
            delete_task($id);
            json_response(['ok' => true]);

        /* ---- Recycle bin (restore / permanent delete) ------------ */
        case 'restore_task':
            restore_task((int)($in['id'] ?? 0));
            json_response(['ok' => true]);
        case 'purge_task':
            purge_task((int)($in['id'] ?? 0));
            json_response(['ok' => true]);
        case 'restore_project':
            restore_project((int)($in['id'] ?? 0));
            json_response(['ok' => true]);
        case 'purge_project':
            purge_project((int)($in['id'] ?? 0));
            json_response(['ok' => true]);
        case 'empty_recycle':
            db()->prepare('DELETE FROM tasks WHERE user_id = ? AND deleted_at IS NOT NULL')->execute([scope_uid()]);
            db()->prepare('DELETE FROM projects WHERE user_id = ? AND deleted_at IS NOT NULL')->execute([scope_uid()]);
            json_response(['ok' => true]);

        /* ---- Time ------------------------------------------------ */
        case 'add_time':
            $id = (int)($in['task_id'] ?? 0);
            $minutes = (int)($in['minutes'] ?? 0);
            if (!$id || $minutes <= 0) json_response(['ok' => false, 'error' => 'Task and minutes required.'], 400);
            add_time_entry($id, $minutes, $in['date'] ?? date('Y-m-d'), (string)($in['note'] ?? 'Manual entry'));
            log_activity('timer', 'Logged ' . fmt_min($minutes) . ' on a task', ['task_id' => $id]);
            json_response(['ok' => true, 'task' => get_task($id), 'stats' => stats_overview()]);

        case 'timer_start':
            $id = (int)($in['task_id'] ?? 0);
            if (!$id) json_response(['ok' => false, 'error' => 'Task required.'], 400);
            // Stop this user's existing running timer first (scoped — never touch others').
            db()->prepare("DELETE FROM time_entries WHERE user_id = ? AND started_at IS NOT NULL AND minutes = 0")
                ->execute([current_user_id()]);
            $task = get_task($id);
            $stmt = db()->prepare("INSERT INTO time_entries(task_id, project_id, minutes, started_at, log_date)
                VALUES(?, ?, 0, ?, ?)");
            $stmt->execute([$id, $task['project_id'] ?? null, date('Y-m-d H:i:s'), date('Y-m-d')]);
            update_task($id, ['status' => 'in_progress']);
            json_response(['ok' => true, 'timer' => running_timer()]);

        case 'timer_stop':
            $timer = running_timer();
            if (!$timer) json_response(['ok' => true, 'stopped' => false]);
            $minutes = max(1, (int)round((time() - strtotime($timer['started_at'])) / 60));
            $stmt = db()->prepare('UPDATE time_entries SET minutes = ?, started_at = NULL WHERE id = ?');
            $stmt->execute([$minutes, $timer['id']]);
            recompute_task_spent((int)$timer['task_id']);
            log_activity('timer', 'Tracked ' . fmt_min($minutes), ['task_id' => $timer['task_id']]);
            json_response(['ok' => true, 'stopped' => true, 'minutes' => $minutes, 'stats' => stats_overview()]);

        case 'timer_status':
            json_response(['ok' => true, 'timer' => running_timer()]);

        /* ---- Projects -------------------------------------------- */
        case 'create_project':
            $name = trim((string)($in['name'] ?? ''));
            if ($name === '') json_response(['ok' => false, 'error' => 'Name required.'], 400);
            $id = find_or_create_project($name, [
                'icon'        => (string)($in['icon'] ?? '📁'),
                'color'       => (string)($in['color'] ?? ''),
                'description' => (string)($in['description'] ?? ''),
                'git_url'     => trim((string)($in['git_url'] ?? '')) ?: null,
                'website_url' => trim((string)($in['website_url'] ?? '')) ?: null,
            ]);
            if (!empty($in['pdf']) && ($pdf = save_project_pdf((string)$in['pdf']))) {
                db()->prepare('UPDATE projects SET pdf_path = ? WHERE id = ? AND user_id = ?')->execute([$pdf, $id, scope_uid()]);
            }
            json_response(['ok' => true, 'id' => $id, 'project' => get_project($id)]);

        case 'update_project':
            $id = (int)($in['id'] ?? 0);
            if (!$id || !get_project($id)) json_response(['ok' => false, 'error' => 'Not found.'], 404);
            if (!empty($in['pdf']) && ($pdf = save_project_pdf((string)$in['pdf']))) {
                db()->prepare('UPDATE projects SET pdf_path = ? WHERE id = ? AND user_id = ?')->execute([$pdf, $id, scope_uid()]);
            }
            $cols = ['name','description','color','icon','status','git_url','website_url','technologies'];
            $set = []; $args = [];
            foreach ($cols as $c) if (array_key_exists($c, $in)) { $set[] = "$c = ?"; $args[] = $in[$c]; }
            if ($set) {
                $set[] = "updated_at = datetime('now','localtime')";
                $args[] = $id;
                $args[] = scope_uid();
                db()->prepare('UPDATE projects SET ' . implode(', ', $set) . ' WHERE id = ? AND user_id = ?')->execute($args);
            }
            json_response(['ok' => true, 'project' => get_project($id)]);

        case 'delete_project':
            $id = (int)($in['id'] ?? 0);
            if (!$id || !get_project($id)) json_response(['ok' => false, 'error' => 'Not found.'], 404);
            soft_delete_project($id);
            json_response(['ok' => true]);

        /* ---- Live stats (for polling) ---------------------------- */
        case 'stats':
            json_response(['ok' => true, 'stats' => stats_overview(), 'timer' => running_timer()]);

        /* ---- Attendance (check in / out) ------------------------- */
        case 'attendance_checkin':
            $r = attendance_check_in();
            if (isset($r['error'])) json_response(['ok' => false, 'error' => $r['error']], 400);
            json_response(['ok' => true] + $r);

        case 'attendance_checkout':
            $r = attendance_check_out();
            if (isset($r['error'])) json_response(['ok' => false, 'error' => $r['error']], 400);
            json_response(['ok' => true] + $r);

        case 'attendance_status':
            json_response(['ok' => true, 'attendance' => current_attendance()]);

        /* ---- Portfolio ------------------------------------------- */
        case 'portfolio_save':
            db()->prepare('UPDATE users SET portfolio_enabled = ?, portfolio_headline = ?, portfolio_bio = ? WHERE id = ?')
                ->execute([
                    !empty($in['enabled']) ? 1 : 0,
                    mb_substr(trim((string)($in['headline'] ?? '')), 0, 90),
                    mb_substr(trim((string)($in['bio'] ?? '')), 0, 400),
                    current_user_id(),
                ]);
            json_response(['ok' => true]);

        case 'portfolio_project':
            $id = (int)($in['id'] ?? 0);
            if (!$id || !get_project($id)) json_response(['ok' => false, 'error' => 'Not found.'], 404);
            db()->prepare('UPDATE projects SET in_portfolio = ? WHERE id = ? AND user_id = ?')
                ->execute([!empty($in['show']) ? 1 : 0, $id, scope_uid()]);
            json_response(['ok' => true]);

        case 'portfolio_add': {
            // Add a portfolio project from the Portfolio page. Whatever is missing
            // (description / thumbnail / gallery) gets queued for Claude to research + complete.
            $name = trim((string)($in['name'] ?? ''));
            $url  = trim((string)($in['url'] ?? ''));
            $desc = trim((string)($in['description'] ?? ''));
            $tech = trim((string)($in['technologies'] ?? ''));
            if ($name === '' && $url === '') json_response(['ok' => false, 'error' => 'Enter at least a title or a web link.'], 400);
            if ($url !== '' && !preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
            if ($name === '') {
                $host = (string)parse_url($url, PHP_URL_HOST);
                $host = preg_replace('/^www\./i', '', $host);
                $name = ucwords(str_replace(['-', '_', '.'], ' ', explode('.', $host)[0] ?: 'New Project'));
            }
            $pid = find_or_create_project($name, ['description' => $desc, 'website_url' => $url ?: null]);
            if (!$pid) json_response(['ok' => false, 'error' => 'Could not create the project.'], 500);
            if ($tech !== '') {
                db()->prepare('UPDATE projects SET technologies = ? WHERE id = ? AND user_id = ?')
                    ->execute([mb_substr($tech, 0, 300), $pid, scope_uid()]);
            }
            db()->prepare('UPDATE projects SET in_portfolio = 1, website_url = COALESCE(NULLIF(?, \'\'), website_url) WHERE id = ? AND user_id = ?')
                ->execute([$url, $pid, scope_uid()]);

            $missing = [];
            if ($desc === '') $missing[] = 'description';
            if (empty($in['has_thumb'])) $missing[] = 'thumbnail';
            if (empty($in['has_shots'])) $missing[] = 'gallery';
            $queued = $url !== '' && $missing;
            if ($queued) {
                db()->prepare('INSERT INTO portfolio_queue(user_id, project_id, url, note) VALUES(?, ?, ?, ?)')
                    ->execute([current_user_id(), $pid, $url, 'missing: ' . implode(', ', $missing)]);
            }
            json_response(['ok' => true, 'id' => $pid, 'queued' => (bool)$queued]);
        }

        case 'portfolio_media':
            $id = (int)($in['id'] ?? 0);
            $proj = $id ? get_project($id) : null;
            if (!$proj) json_response(['ok' => false, 'error' => 'Not found.'], 404);
            // Cover image
            if (!empty($in['thumb'])) {
                $img = save_project_image((string)$in['thumb']);
                if (!$img) json_response(['ok' => false, 'error' => 'Invalid image (max 5MB, png/jpg/webp).'], 400);
                if (!empty($proj['thumb_path']) && is_file(BASE_DIR . '/' . $proj['thumb_path'])) @unlink(BASE_DIR . '/' . $proj['thumb_path']);
                db()->prepare('UPDATE projects SET thumb_path = ? WHERE id = ? AND user_id = ?')->execute([$img, $id, scope_uid()]);
                json_response(['ok' => true, 'thumb' => $img]);
            }
            // Add screenshot
            if (!empty($in['shot'])) {
                $shots = json_decode((string)($proj['shots'] ?? '[]'), true) ?: [];
                if (count($shots) >= 12) json_response(['ok' => false, 'error' => 'Max 12 screenshots.'], 400);
                $img = save_project_image((string)$in['shot']);
                if (!$img) json_response(['ok' => false, 'error' => 'Invalid image (max 5MB, png/jpg/webp).'], 400);
                $shots[] = $img;
                db()->prepare('UPDATE projects SET shots = ? WHERE id = ? AND user_id = ?')->execute([json_encode($shots), $id, scope_uid()]);
                json_response(['ok' => true, 'shots' => $shots]);
            }
            // Remove screenshot
            if (!empty($in['remove_shot'])) {
                $shots = json_decode((string)($proj['shots'] ?? '[]'), true) ?: [];
                $rm = (string)$in['remove_shot'];
                $shots = array_values(array_filter($shots, fn($s) => $s !== $rm));
                if (str_starts_with($rm, 'uploads/projects/') && is_file(BASE_DIR . '/' . $rm)) @unlink(BASE_DIR . '/' . $rm);
                db()->prepare('UPDATE projects SET shots = ? WHERE id = ? AND user_id = ?')->execute([json_encode($shots), $id, scope_uid()]);
                json_response(['ok' => true, 'shots' => $shots]);
            }
            json_response(['ok' => false, 'error' => 'Nothing to do.'], 400);

        /* ---- Upwork proposal generator --------------------------- */
        case 'upwork_prompt': {
            // Browser-side AI: the host blocks outbound calls, so the browser talks to the provider directly.
            require_once __DIR__ . '/proposal.php';
            $job = trim((string)($in['job'] ?? ''));
            if (mb_strlen($job) < 40) json_response(['ok' => false, 'error' => 'Paste the job post (a bit more detail needed).'], 400);
            $prov = ai_active_provider();
            $extras = ['reference_links' => extract_reference_links($job), 'job_techs' => upwork_job_tech_names($job)];
            if ($prov === 'local') json_response(['ok' => true, 'provider' => 'local'] + $extras);
            $me = current_user();
            $stmt = db()->prepare("SELECT name, description, technologies, website_url, status FROM projects
                WHERE user_id = ? AND deleted_at IS NULL AND in_portfolio = 1 ORDER BY position, name");
            $stmt->execute([current_user_id()]);
            $projects = $stmt->fetchAll();
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $portfolioUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . url('p.php') . '?u=' . ($me['portfolio_token'] ?? '');
            $pp = upwork_build_prompt($job, trim((string)($in['budget'] ?? '')), trim((string)($in['notes'] ?? '')), $me, $projects, $portfolioUrl);
            json_response(['ok' => true, 'provider' => $prov, 'key' => ai_api_key(), 'model' => ai_resolved_model($prov),
                'system' => $pp['system'], 'user' => $pp['user']] + $extras);
        }

        case 'upwork_proposal':
            require_once __DIR__ . '/proposal.php';
            $job = trim((string)($in['job'] ?? ''));
            if (mb_strlen($job) < 40) json_response(['ok' => false, 'error' => 'Paste the job post (a bit more detail needed).'], 400);
            $me = current_user();
            $stmt = db()->prepare("SELECT name, description, technologies, website_url, status FROM projects
                WHERE user_id = ? AND deleted_at IS NULL AND in_portfolio = 1 ORDER BY position, name");
            $stmt->execute([current_user_id()]);
            $projects = $stmt->fetchAll();
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $portfolioUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . url('p.php') . '?u=' . ($me['portfolio_token'] ?? '');
            $result = upwork_generate($job, trim((string)($in['budget'] ?? '')), trim((string)($in['notes'] ?? '')), $me, $projects, $portfolioUrl);
            json_response(['ok' => true] + $result);

        /* ---- Upwork Jobs tracker ---------------------------------- */
        case 'uw_job_add': {
            $title = trim((string)($in['title'] ?? ''));
            if ($title === '') json_response(['ok' => false, 'error' => 'Job title required.'], 400);
            db()->prepare('INSERT INTO uw_jobs(user_id, title, summary, proposal, status) VALUES(?, ?, ?, ?, ?)')
                ->execute([current_user_id(), mb_substr($title, 0, 160),
                    mb_substr(trim((string)($in['summary'] ?? '')), 0, 6000),
                    mb_substr(trim((string)($in['proposal'] ?? '')), 0, 8000),
                    in_array($in['status'] ?? '', ['applied', 'replied', 'interview', 'hired', 'closed'], true) ? $in['status'] : 'applied']);
            json_response(['ok' => true, 'id' => (int)db()->lastInsertId()]);
        }

        case 'uw_job_list': {
            $stmt = db()->prepare("SELECT j.id, j.title, j.status, j.updated_at,
                    (SELECT COUNT(*) FROM uw_job_msgs m WHERE m.job_id = j.id) AS msg_count
                FROM uw_jobs j WHERE j.user_id = ? ORDER BY j.updated_at DESC, j.id DESC");
            $stmt->execute([current_user_id()]);
            json_response(['ok' => true, 'items' => $stmt->fetchAll()]);
        }

        case 'uw_job_get': {
            $stmt = db()->prepare('SELECT * FROM uw_jobs WHERE id = ? AND user_id = ?');
            $stmt->execute([(int)($in['id'] ?? 0), current_user_id()]);
            $job = $stmt->fetch();
            if (!$job) json_response(['ok' => false, 'error' => 'Not found.'], 404);
            $ms = db()->prepare('SELECT id, sender, body, created_at FROM uw_job_msgs WHERE job_id = ? ORDER BY id');
            $ms->execute([$job['id']]);
            $job['messages'] = $ms->fetchAll();
            json_response(['ok' => true, 'job' => $job]);
        }

        case 'uw_job_update': {
            $id = (int)($in['id'] ?? 0);
            $chk = db()->prepare('SELECT id FROM uw_jobs WHERE id = ? AND user_id = ?');
            $chk->execute([$id, current_user_id()]);
            if (!$chk->fetchColumn()) json_response(['ok' => false, 'error' => 'Not found.'], 404);
            $set = []; $args = [];
            if (isset($in['title']) && trim((string)$in['title']) !== '') { $set[] = 'title = ?'; $args[] = mb_substr(trim((string)$in['title']), 0, 160); }
            if (isset($in['summary'])) { $set[] = 'summary = ?'; $args[] = mb_substr(trim((string)$in['summary']), 0, 6000); }
            if (isset($in['proposal'])) { $set[] = 'proposal = ?'; $args[] = mb_substr(trim((string)$in['proposal']), 0, 8000); }
            if (isset($in['status']) && in_array($in['status'], ['applied', 'replied', 'interview', 'hired', 'closed'], true)) { $set[] = 'status = ?'; $args[] = $in['status']; }
            if ($set) {
                $set[] = "updated_at = datetime('now','localtime')";
                $args[] = $id;
                db()->prepare('UPDATE uw_jobs SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($args);
            }
            json_response(['ok' => true]);
        }

        case 'uw_job_delete': {
            $id = (int)($in['id'] ?? 0);
            db()->prepare('DELETE FROM uw_jobs WHERE id = ? AND user_id = ?')->execute([$id, current_user_id()]);
            db()->prepare('DELETE FROM uw_job_msgs WHERE job_id = ?')->execute([$id]);
            json_response(['ok' => true]);
        }

        case 'uw_job_msg_add': {
            $jid = (int)($in['job_id'] ?? 0);
            $body = trim((string)($in['body'] ?? ''));
            if ($body === '') json_response(['ok' => false, 'error' => 'Empty message.'], 400);
            $chk = db()->prepare('SELECT id FROM uw_jobs WHERE id = ? AND user_id = ?');
            $chk->execute([$jid, current_user_id()]);
            if (!$chk->fetchColumn()) json_response(['ok' => false, 'error' => 'Not found.'], 404);
            db()->prepare('INSERT INTO uw_job_msgs(job_id, user_id, sender, body) VALUES(?, ?, ?, ?)')
                ->execute([$jid, current_user_id(), ($in['sender'] ?? '') === 'me' ? 'me' : 'client', mb_substr($body, 0, 6000)]);
            db()->prepare("UPDATE uw_jobs SET updated_at = datetime('now','localtime'), status = CASE WHEN status = 'applied' THEN 'replied' ELSE status END WHERE id = ?")->execute([$jid]);
            json_response(['ok' => true, 'id' => (int)db()->lastInsertId()]);
        }

        case 'uw_job_reply': {
            // Suggested human reply to the client's latest message (browser calls the AI —
            // the host blocks outbound HTTP; 'local' provider gets a simple fallback).
            require_once __DIR__ . '/proposal.php';
            $stmt = db()->prepare('SELECT * FROM uw_jobs WHERE id = ? AND user_id = ?');
            $stmt->execute([(int)($in['id'] ?? 0), current_user_id()]);
            $job = $stmt->fetch();
            if (!$job) json_response(['ok' => false, 'error' => 'Not found.'], 404);
            $ms = db()->prepare('SELECT sender, body FROM uw_job_msgs WHERE job_id = ? ORDER BY id');
            $ms->execute([$job['id']]);
            $msgs = $ms->fetchAll();
            if (!$msgs) json_response(['ok' => false, 'error' => 'No conversation yet.'], 400);
            $pp = uw_reply_build_prompt($job, $msgs, current_user());
            $prov = ai_active_provider();
            if ($prov === 'local') {
                json_response(['ok' => true, 'provider' => 'local',
                    'reply' => "Thanks for getting back to me! Yes, that works on my side. I can get started right away — should I go ahead?"]);
            }
            json_response(['ok' => true, 'provider' => $prov, 'key' => ai_api_key(), 'model' => ai_resolved_model($prov),
                'system' => $pp['system'], 'user' => $pp['user']]);
        }

        case 'uw_job_msg_delete': {
            db()->prepare('DELETE FROM uw_job_msgs WHERE id = ? AND user_id = ?')->execute([(int)($in['id'] ?? 0), current_user_id()]);
            json_response(['ok' => true]);
        }

        case 'upwork_rule_add': {
            $rule = trim((string)($in['rule'] ?? ''));
            if (mb_strlen($rule) < 5) json_response(['ok' => false, 'error' => 'Please write a bit more detail.'], 400);
            db()->prepare('INSERT INTO upwork_rules(user_id, rule) VALUES(?, ?)')
                ->execute([current_user_id(), $rule]);
            json_response(['ok' => true, 'id' => (int)db()->lastInsertId()]);
        }

        case 'upwork_rule_list': {
            $stmt = db()->prepare('SELECT id, rule, created_at FROM upwork_rules WHERE user_id = ? ORDER BY id DESC');
            $stmt->execute([current_user_id()]);
            json_response(['ok' => true, 'items' => $stmt->fetchAll()]);
        }

        case 'upwork_rule_delete': {
            db()->prepare('DELETE FROM upwork_rules WHERE id = ? AND user_id = ?')
                ->execute([(int)($in['id'] ?? 0), current_user_id()]);
            json_response(['ok' => true]);
        }

        case 'upwork_queue':
            $job = trim((string)($in['job'] ?? ''));
            if (mb_strlen($job) < 40) json_response(['ok' => false, 'error' => 'Paste the job post (a bit more detail needed).'], 400);
            db()->prepare('INSERT INTO proposal_queue(user_id, job, budget, notes) VALUES(?, ?, ?, ?)')
                ->execute([current_user_id(), $job, trim((string)($in['budget'] ?? '')), trim((string)($in['notes'] ?? ''))]);
            json_response(['ok' => true, 'id' => (int)db()->lastInsertId()]);

        case 'upwork_queue_draft': {
            // Instant browser-generated draft on a queued job — never overwrites Claude's final version.
            $res = $in['result'] ?? null;
            if (is_array($res) && !empty($res['cover_letter'])) {
                $res['draft'] = true;
                db()->prepare("UPDATE proposal_queue SET result = ? WHERE id = ? AND user_id = ? AND status != 'done'")
                    ->execute([json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (int)($in['id'] ?? 0), current_user_id()]);
            }
            json_response(['ok' => true]);
        }

        case 'upwork_queue_list':
            $stmt = db()->prepare("SELECT id, status, budget, substr(job,1,90) excerpt, created_at, done_at,
                CASE WHEN result IS NOT NULL THEN 1 ELSE 0 END AS has_draft
                FROM proposal_queue WHERE user_id = ? ORDER BY id DESC LIMIT 20");
            $stmt->execute([current_user_id()]);
            json_response(['ok' => true, 'items' => $stmt->fetchAll()]);

        case 'upwork_queue_get':
            $stmt = db()->prepare('SELECT * FROM proposal_queue WHERE id = ? AND user_id = ?');
            $stmt->execute([(int)($in['id'] ?? 0), current_user_id()]);
            $row = $stmt->fetch();
            if (!$row) json_response(['ok' => false, 'error' => 'Not found.'], 404);
            $row['result'] = $row['result'] ? json_decode($row['result'], true) : null;
            // Always derive reference links + demanded techs from the stored job post.
            if (is_array($row['result'])) {
                require_once __DIR__ . '/proposal.php';
                if (empty($row['result']['reference_links'])) $row['result']['reference_links'] = extract_reference_links((string)$row['job']);
                if (empty($row['result']['job_techs'])) $row['result']['job_techs'] = upwork_job_tech_names((string)$row['job']);
            }
            json_response(['ok' => true, 'item' => $row]);

        case 'upwork_queue_delete':
            db()->prepare('DELETE FROM proposal_queue WHERE id = ? AND user_id = ?')
                ->execute([(int)($in['id'] ?? 0), current_user_id()]);
            json_response(['ok' => true]);

        case 'portfolio_regen':
            $tok = bin2hex(random_bytes(8));
            db()->prepare('UPDATE users SET portfolio_token = ? WHERE id = ?')->execute([$tok, current_user_id()]);
            json_response(['ok' => true, 'token' => $tok]);

        case 'attendance_delete':
            $id = (int)($in['id'] ?? 0);
            if (!$id) json_response(['ok' => false, 'error' => 'Bad id.'], 400);
            db()->prepare('DELETE FROM attendance WHERE id = ? AND user_id = ?')->execute([$id, current_user_id()]);
            json_response(['ok' => true]);

        /* ---- Chat / messaging ------------------------------------ */
        case 'chat_start_direct':
            $other = (int)($in['user_id'] ?? 0);
            if (!$other || !get_user($other)) json_response(['ok' => false, 'error' => 'User not found.'], 400);
            $cid = chat_get_or_create_direct(current_user_id(), $other);
            if (!$cid) json_response(['ok' => false, 'error' => 'Cannot message yourself.'], 400);
            json_response(['ok' => true, 'conversation_id' => $cid]);

        case 'chat_create_group':
            $name = trim((string)($in['name'] ?? ''));
            $members = $in['members'] ?? [];
            if (!is_array($members) || count($members) < 1) json_response(['ok' => false, 'error' => 'Pick at least one member.'], 400);
            $cid = chat_create_group($name, $members, current_user_id());
            json_response(['ok' => true, 'conversation_id' => $cid]);

        case 'chat_send':
            $cid = (int)($in['conversation_id'] ?? 0);
            $r = chat_send($cid, current_user_id(), (string)($in['body'] ?? ''),
                isset($in['attachment']) ? (string)$in['attachment'] : null,
                (string)($in['attachment_name'] ?? ''));
            if (isset($r['error'])) json_response(['ok' => false, 'error' => $r['error']], 400);
            json_response(['ok' => true] + $r);

        case 'chat_poll':
            $cid = (int)($in['conversation_id'] ?? 0);
            $after = (int)($in['after'] ?? 0);
            if (!chat_is_member($cid, current_user_id())) json_response(['ok' => false, 'error' => 'Not allowed.'], 403);
            $msgs = chat_messages($cid, current_user_id(), $after);
            chat_mark_read($cid, current_user_id());
            json_response(['ok' => true, 'messages' => $msgs, 'me' => current_user_id(),
                'seen_up_to' => chat_seen_up_to($cid, current_user_id())]);

        case 'chat_mark_read':
            chat_mark_read((int)($in['conversation_id'] ?? 0), current_user_id());
            json_response(['ok' => true]);

        case 'chat_unread':
            json_response(['ok' => true, 'unread' => chat_total_unread(current_user_id())]);

        case 'chat_delete_conversation':
            $r = chat_delete_conversation((int)($in['conversation_id'] ?? 0), current_user_id());
            if (isset($r['error'])) json_response(['ok' => false, 'error' => $r['error']], 400);
            json_response(['ok' => true]);

        case 'chat_delete_message':
            $r = chat_delete_message((int)($in['id'] ?? 0), current_user_id());
            if (isset($r['error'])) json_response(['ok' => false, 'error' => $r['error']], 400);
            json_response(['ok' => true]);

        /* ---- Super admin: user management ------------------------ */
        case 'admin_create_user':
            if (!is_super_admin()) json_response(['ok' => false, 'error' => 'Admins only.'], 403);
            $r = create_user($in);
            if (isset($r['error'])) json_response(['ok' => false, 'error' => $r['error']], 400);
            log_activity('user_created', 'Added user ' . ($in['username'] ?? ''), ['id' => $r['id']]);
            json_response(['ok' => true, 'id' => $r['id']]);

        case 'admin_update_user':
            if (!is_super_admin()) json_response(['ok' => false, 'error' => 'Admins only.'], 403);
            $id = (int)($in['id'] ?? 0);
            // A super admin may not strip their own admin role or disable themselves (avoid lockout).
            if ($id === current_user_id() && (($in['role'] ?? 'super_admin') !== 'super_admin' || ($in['status'] ?? 'active') === 'disabled')) {
                json_response(['ok' => false, 'error' => "You can't demote or disable yourself."], 400);
            }
            $r = update_user($id, $in);
            if (isset($r['error'])) json_response(['ok' => false, 'error' => $r['error']], 400);
            json_response(['ok' => true]);

        case 'admin_delete_user':
            if (!is_super_admin()) json_response(['ok' => false, 'error' => 'Admins only.'], 403);
            $id = (int)($in['id'] ?? 0);
            if ($id === current_user_id()) json_response(['ok' => false, 'error' => "You can't delete yourself."], 400);
            $r = delete_user($id);
            if (isset($r['error'])) json_response(['ok' => false, 'error' => $r['error']], 400);
            json_response(['ok' => true]);

        case 'admin_view_user':
            if (!is_super_admin()) json_response(['ok' => false, 'error' => 'Admins only.'], 403);
            $id = (int)($in['id'] ?? 0);
            if (get_user($id)) $_SESSION['view_uid'] = $id;
            json_response(['ok' => true]);

        case 'admin_exit_view':
            unset($_SESSION['view_uid']);
            json_response(['ok' => true]);

        default:
            json_response(['ok' => false, 'error' => 'Unknown action.'], 404);
    }
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => 'Server error: ' . $e->getMessage()], 500);
}
