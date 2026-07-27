<?php
/**
 * Taskway — JSON API. All actions are POST (JSON body) except read-only GETs.
 * Usage: fetch(api.php?action=set_status, {method:'POST', body: JSON}) -> {ok:true, ...}
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/parser.php';

require_login();

$action = preg_replace('/[^a-z0-9_]/', '', (string)($_GET['action'] ?? ''));
$in = input_json();

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
        case 'upwork_proposal':
            require_once __DIR__ . '/proposal.php';
            $job = trim((string)($in['job'] ?? ''));
            if (mb_strlen($job) < 40) json_response(['ok' => false, 'error' => 'Job post paste karein (thoda detail chahiye).'], 400);
            $me = current_user();
            $stmt = db()->prepare("SELECT name, description, technologies, website_url, status FROM projects
                WHERE user_id = ? AND deleted_at IS NULL AND in_portfolio = 1 ORDER BY position, name");
            $stmt->execute([current_user_id()]);
            $projects = $stmt->fetchAll();
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $portfolioUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . url('p.php') . '?u=' . ($me['portfolio_token'] ?? '');
            $result = upwork_generate($job, trim((string)($in['budget'] ?? '')), trim((string)($in['notes'] ?? '')), $me, $projects, $portfolioUrl);
            json_response(['ok' => true] + $result);

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
            json_response(['ok' => true, 'messages' => $msgs, 'me' => current_user_id()]);

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
