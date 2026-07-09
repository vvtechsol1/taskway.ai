<?php
/**
 * Taskway — SQLite database layer.
 * Auto-creates the schema on first run and applies lightweight migrations.
 */

declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode = WAL;');
    $pdo->exec('PRAGMA foreign_keys = ON;');
    $pdo->exec('PRAGMA busy_timeout = 5000;');

    db_migrate($pdo);
    return $pdo;
}

function db_migrate(PDO $pdo): void
{
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS projects (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            name        TEXT NOT NULL,
            slug        TEXT NOT NULL UNIQUE,
            description TEXT DEFAULT '',
            color       TEXT DEFAULT '#6C5CE7',
            icon        TEXT DEFAULT '📁',
            status      TEXT NOT NULL DEFAULT 'active',   -- active | paused | done | archived
            position    INTEGER DEFAULT 0,
            created_at  TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            updated_at  TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        );
    SQL);

    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS tasks (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id    INTEGER REFERENCES projects(id) ON DELETE SET NULL,
            title         TEXT NOT NULL,
            description   TEXT DEFAULT '',
            status        TEXT NOT NULL DEFAULT 'todo',    -- todo | in_progress | done | blocked
            type          TEXT NOT NULL DEFAULT 'task',    -- feature | bug | improvement | research | task
            priority      TEXT NOT NULL DEFAULT 'normal',  -- low | normal | high | urgent
            estimate_min  INTEGER DEFAULT 0,
            spent_min     INTEGER DEFAULT 0,               -- cached sum of time_entries
            task_date     TEXT NOT NULL DEFAULT (date('now','localtime')),
            position      INTEGER DEFAULT 0,
            created_at    TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            updated_at    TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            completed_at  TEXT DEFAULT NULL
        );
    SQL);

    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS time_entries (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id     INTEGER REFERENCES tasks(id) ON DELETE CASCADE,
            project_id  INTEGER REFERENCES projects(id) ON DELETE SET NULL,
            minutes     INTEGER NOT NULL DEFAULT 0,
            log_date    TEXT NOT NULL DEFAULT (date('now','localtime')),
            started_at  TEXT DEFAULT NULL,                 -- set while a live timer is running
            note        TEXT DEFAULT '',
            created_at  TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        );
    SQL);

    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS activity_log (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            kind        TEXT NOT NULL,        -- task_created | task_done | timer | braindump | project ...
            title       TEXT NOT NULL,
            meta        TEXT DEFAULT '{}',
            created_at  TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        );
    SQL);

    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS settings (
            key    TEXT PRIMARY KEY,
            value  TEXT
        );
    SQL);

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_project ON tasks(project_id);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_date ON tasks(task_date);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tasks_status ON tasks(status);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_time_date ON time_entries(log_date);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_time_task ON time_entries(task_id);');

    // Attendance: clock in / clock out sessions.
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS attendance (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER,
            check_in   TEXT NOT NULL,
            check_out  TEXT DEFAULT NULL,
            minutes    INTEGER DEFAULT 0,
            log_date   TEXT NOT NULL DEFAULT (date('now','localtime')),
            note       TEXT DEFAULT '',
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        );
    SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_attendance_user ON attendance(user_id, log_date);');

    // Chat: conversations (direct / group), members, and messages.
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS conversations (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            type       TEXT NOT NULL DEFAULT 'direct',   -- direct | group
            name       TEXT DEFAULT '',
            created_by INTEGER,
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        );
    SQL);
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS conversation_members (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            conversation_id INTEGER,
            user_id         INTEGER,
            last_read_id    INTEGER DEFAULT 0,
            joined_at       TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        );
    SQL);
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS messages (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            conversation_id INTEGER,
            user_id         INTEGER,
            body            TEXT NOT NULL DEFAULT '',
            attachment      TEXT DEFAULT NULL,
            attachment_type TEXT DEFAULT NULL,
            attachment_name TEXT DEFAULT NULL,
            created_at      TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        );
    SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_msg_conv ON messages(conversation_id, id);');
    // Add attachment columns to an already-created messages table.
    $mcols = $pdo->query("PRAGMA table_info(messages)")->fetchAll(PDO::FETCH_COLUMN, 1);
    foreach (['attachment', 'attachment_type', 'attachment_name'] as $col) {
        if (!in_array($col, $mcols, true)) $pdo->exec("ALTER TABLE messages ADD COLUMN $col TEXT DEFAULT NULL");
    }
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cm_user ON conversation_members(user_id);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cm_conv ON conversation_members(conversation_id);');

    // Seed default settings once.
    $defaults = [
        'auth_enabled'    => '0',
        'auth_password'   => '',                 // legacy single-user password
        'daily_hours_goal' => '6',               // legacy default; per-user goal lives on users.daily_goal
        'ai_provider'     => 'local',            // local | claude  (global, admin-managed)
        'claude_api_key'  => '',
        'claude_model'    => 'claude-sonnet-5',
        'theme'           => 'light',
        'accent'          => 'violet',
        'user_name'       => 'there',
        'allow_signup'    => '1',                // let people self-register
    ];
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO settings(key, value) VALUES(?, ?)');
    foreach ($defaults as $k => $v) {
        $stmt->execute([$k, $v]);
    }

    db_migrate_users($pdo);
}

/**
 * Multi-user upgrade: users table, per-row ownership, and a seeded super admin.
 * Runs every boot but every step is idempotent.
 */
function db_migrate_users(PDO $pdo): void
{
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS users (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            name          TEXT NOT NULL DEFAULT '',
            username      TEXT NOT NULL UNIQUE,
            email         TEXT DEFAULT '',
            password_hash TEXT NOT NULL,
            role          TEXT NOT NULL DEFAULT 'user',    -- super_admin | user
            color         TEXT DEFAULT '#6C5CE7',
            daily_goal    INTEGER DEFAULT 6,
            status        TEXT NOT NULL DEFAULT 'active',   -- active | disabled
            created_at    TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            last_login    TEXT DEFAULT NULL
        );
    SQL);

    // Add user_id ownership column to each data table if missing.
    foreach (['projects', 'tasks', 'time_entries', 'activity_log'] as $t) {
        $cols = $pdo->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('user_id', $cols, true)) {
            $pdo->exec("ALTER TABLE $t ADD COLUMN user_id INTEGER");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_{$t}_user ON $t(user_id)");
        }
    }

    // Soft-delete (recycle bin) column for tasks & projects.
    foreach (['tasks', 'projects'] as $t) {
        $cols = $pdo->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('deleted_at', $cols, true)) {
            $pdo->exec("ALTER TABLE $t ADD COLUMN deleted_at TEXT DEFAULT NULL");
        }
    }

    // First run: create a super admin and hand it all pre-existing data.
    if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
        // Reuse a legacy single-user password if one was set, else a default.
        $legacy = $pdo->query("SELECT value FROM settings WHERE key='auth_password'")->fetchColumn();
        $hash = ($legacy && is_string($legacy) && $legacy !== '') ? $legacy : password_hash('admin123', PASSWORD_DEFAULT);
        $name = $pdo->query("SELECT value FROM settings WHERE key='user_name'")->fetchColumn() ?: 'Super Admin';
        $goal = (int)($pdo->query("SELECT value FROM settings WHERE key='daily_hours_goal'")->fetchColumn() ?: 6);

        $stmt = $pdo->prepare("INSERT INTO users(name, username, email, password_hash, role, color, daily_goal)
            VALUES(?, 'admin', '', ?, 'super_admin', '#6C5CE7', ?)");
        $stmt->execute([$name, $hash, $goal]);
        $adminId = (int)$pdo->lastInsertId();

        foreach (['projects', 'tasks', 'time_entries', 'activity_log'] as $t) {
            $pdo->exec("UPDATE $t SET user_id = $adminId WHERE user_id IS NULL");
        }
    }
}

/* ------------------------------------------------------------------ */
/* Settings helpers                                                    */
/* ------------------------------------------------------------------ */

function setting(string $key, ?string $default = null): ?string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT key, value FROM settings')->fetchAll() as $row) {
            $cache[$row['key']] = $row['value'];
        }
    }
    return array_key_exists($key, $cache) ? $cache[$key] : $default;
}

function set_setting(string $key, string $value): void
{
    $stmt = db()->prepare('INSERT INTO settings(key, value) VALUES(?, ?)
        ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $stmt->execute([$key, $value]);
}
