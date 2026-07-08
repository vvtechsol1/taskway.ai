<?php
/**
 * Taskway — multi-user authentication & user management.
 * Roles: super_admin (manages users + global overview) and user (own workspace).
 */

declare(strict_types=1);

/* ------------------------------------------------------------------ */
/* Session / current user                                              */
/* ------------------------------------------------------------------ */

function current_user_id(): int
{
    // CLI/scripts may set $GLOBALS['tw_cli_uid']; web uses the session.
    return (int)($_SESSION['uid'] ?? ($GLOBALS['tw_cli_uid'] ?? 0));
}

function current_user(): ?array
{
    static $cache = null; static $cachedId = -1;
    $id = current_user_id();
    if ($id === 0) return null;
    if ($cachedId === $id && $cache !== null) return $cache;
    $cache = get_user($id);
    $cachedId = $id;
    return $cache;
}

/** The user whose data we are viewing (self, or a user a super admin is inspecting). */
function scope_uid(): int
{
    if (!empty($_SESSION['view_uid']) && is_super_admin()) {
        return (int)$_SESSION['view_uid'];
    }
    return current_user_id();
}

function is_logged_in(): bool
{
    return current_user_id() > 0;
}

function is_super_admin(): bool
{
    $u = current_user();
    return $u !== null && $u['role'] === 'super_admin';
}

/* ------------------------------------------------------------------ */
/* Login / logout                                                      */
/* ------------------------------------------------------------------ */

function get_user_by_login(string $login): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE LOWER(username) = LOWER(?) OR (email <> "" AND LOWER(email) = LOWER(?)) LIMIT 1');
    $stmt->execute([$login, $login]);
    return $stmt->fetch() ?: null;
}

function attempt_login(string $login, string $password): bool
{
    $u = get_user_by_login(trim($login));
    if (!$u || $u['status'] !== 'active') return false;
    if (!password_verify($password, $u['password_hash'])) return false;

    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    unset($_SESSION['view_uid']);
    db()->prepare("UPDATE users SET last_login = datetime('now','localtime') WHERE id = ?")->execute([$u['id']]);
    return true;
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function require_login(): void
{
    if (!is_logged_in()) {
        redirect(page_url('login'));
    }
}

function require_admin(): void
{
    require_login();
    if (!is_super_admin()) {
        redirect(page_url('dashboard'));
    }
}

/* ------------------------------------------------------------------ */
/* User CRUD                                                           */
/* ------------------------------------------------------------------ */

function get_user(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function get_users(): array
{
    return db()->query("SELECT * FROM users ORDER BY
        CASE role WHEN 'super_admin' THEN 0 ELSE 1 END, name, username")->fetchAll();
}

function username_taken(string $username, int $exceptId = 0): bool
{
    $stmt = db()->prepare('SELECT id FROM users WHERE LOWER(username) = LOWER(?) AND id <> ? LIMIT 1');
    $stmt->execute([$username, $exceptId]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Create a user. $data: name, username, email, password, role, color, daily_goal.
 * Returns [id] or ['error'=>msg].
 */
function create_user(array $data): array
{
    $username = strtolower(trim((string)($data['username'] ?? '')));
    $name = trim((string)($data['name'] ?? '')) ?: $username;
    $password = (string)($data['password'] ?? '');

    if (!preg_match('/^[a-z0-9_.]{3,30}$/', $username)) {
        return ['error' => 'Username must be 3-30 chars: letters, numbers, _ or .'];
    }
    if (strlen($password) < 6) {
        return ['error' => 'Password must be at least 6 characters.'];
    }
    if (username_taken($username)) {
        return ['error' => 'That username is already taken.'];
    }

    $palette = PROJECT_PALETTE;
    $color = (string)($data['color'] ?? $palette[(int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn() % count($palette)]);
    $role = ($data['role'] ?? 'user') === 'super_admin' ? 'super_admin' : 'user';

    $stmt = db()->prepare('INSERT INTO users(name, username, email, password_hash, role, color, daily_goal, status)
        VALUES(?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $name, $username, trim((string)($data['email'] ?? '')),
        password_hash($password, PASSWORD_DEFAULT), $role, $color,
        (int)($data['daily_goal'] ?? 6), 'active',
    ]);
    return ['id' => (int)db()->lastInsertId()];
}

/** Update a user. $data may include name, email, role, color, daily_goal, status, password. */
function update_user(int $id, array $data): array
{
    $u = get_user($id);
    if (!$u) return ['error' => 'User not found.'];

    $set = []; $args = [];
    foreach (['name', 'email', 'color'] as $c) {
        if (array_key_exists($c, $data)) { $set[] = "$c = ?"; $args[] = trim((string)$data[$c]); }
    }
    if (array_key_exists('daily_goal', $data)) { $set[] = 'daily_goal = ?'; $args[] = max(1, min(16, (int)$data['daily_goal'])); }
    if (array_key_exists('role', $data)) { $set[] = 'role = ?'; $args[] = $data['role'] === 'super_admin' ? 'super_admin' : 'user'; }
    if (array_key_exists('status', $data)) { $set[] = 'status = ?'; $args[] = $data['status'] === 'disabled' ? 'disabled' : 'active'; }
    if (array_key_exists('username', $data)) {
        $un = strtolower(trim((string)$data['username']));
        if (!preg_match('/^[a-z0-9_.]{3,30}$/', $un)) return ['error' => 'Invalid username.'];
        if (username_taken($un, $id)) return ['error' => 'Username already taken.'];
        $set[] = 'username = ?'; $args[] = $un;
    }
    if (!empty($data['password'])) {
        if (strlen((string)$data['password']) < 6) return ['error' => 'Password must be at least 6 characters.'];
        $set[] = 'password_hash = ?'; $args[] = password_hash((string)$data['password'], PASSWORD_DEFAULT);
    }
    if (!$set) return ['id' => $id];

    $args[] = $id;
    db()->prepare('UPDATE users SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($args);
    return ['id' => $id];
}

/** Delete a user and all their data. Refuses to remove the last super admin. */
function delete_user(int $id): array
{
    $u = get_user($id);
    if (!$u) return ['error' => 'User not found.'];
    if ($u['role'] === 'super_admin') {
        $admins = (int)db()->query("SELECT COUNT(*) FROM users WHERE role='super_admin'")->fetchColumn();
        if ($admins <= 1) return ['error' => 'Cannot delete the only super admin.'];
    }
    foreach (['time_entries', 'tasks', 'projects', 'activity_log'] as $t) {
        db()->prepare("DELETE FROM $t WHERE user_id = ?")->execute([$id]);
    }
    db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    return ['ok' => true];
}

function user_data_counts(int $id): array
{
    $p = db()->prepare('SELECT COUNT(*) FROM projects WHERE user_id = ?'); $p->execute([$id]);
    $t = db()->prepare("SELECT COUNT(*) FROM tasks WHERE user_id = ?"); $t->execute([$id]);
    $d = db()->prepare("SELECT COUNT(*) FROM tasks WHERE user_id = ? AND status='done'"); $d->execute([$id]);
    $m = db()->prepare('SELECT COALESCE(SUM(minutes),0) FROM time_entries WHERE user_id = ?'); $m->execute([$id]);
    return [
        'projects' => (int)$p->fetchColumn(),
        'tasks'    => (int)$t->fetchColumn(),
        'done'     => (int)$d->fetchColumn(),
        'minutes'  => (int)$m->fetchColumn(),
    ];
}
