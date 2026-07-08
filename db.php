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

    // Seed default settings once.
    $defaults = [
        'auth_enabled'    => '0',
        'auth_password'   => '',                 // password_hash() when set
        'daily_hours_goal' => '6',
        'ai_provider'     => 'local',            // local | claude
        'claude_api_key'  => '',
        'claude_model'    => 'claude-sonnet-5',
        'theme'           => 'light',            // light | dark | auto
        'accent'          => 'violet',
        'user_name'       => 'there',
    ];
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO settings(key, value) VALUES(?, ?)');
    foreach ($defaults as $k => $v) {
        $stmt->execute([$k, $v]);
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
