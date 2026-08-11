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

const SCHEMA_VERSION = '16';

function db_migrate(PDO $pdo): void
{
    // Fast path: schema already current -> skip all migration work (big win on shared hosting,
    // where the CREATE/PRAGMA/INSERT storm used to run on EVERY request).
    try {
        $cur = $pdo->query("SELECT value FROM settings WHERE key='schema_version'")->fetchColumn();
        if ($cur === SCHEMA_VERSION) return;
    } catch (Throwable $e) {
        // settings table doesn't exist yet — first run, do the full migration.
    }

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

    // Upwork proposal queue — jobs waiting for Claude (the bridge) to write them.
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS proposal_queue (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER,
            job        TEXT NOT NULL,
            budget     TEXT DEFAULT '',
            notes      TEXT DEFAULT '',
            status     TEXT NOT NULL DEFAULT 'pending',   -- pending | processing | done | failed
            result     TEXT DEFAULT NULL,                 -- JSON {cover_letter, relevant_projects, billing, questions}
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            done_at    TEXT DEFAULT NULL
        );
    SQL);

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
        'bridge_secret'   => bin2hex(random_bytes(16)),   // auth for the Claude bridge (INSERT OR IGNORE keeps the first)
    ];
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO settings(key, value) VALUES(?, ?)');
    foreach ($defaults as $k => $v) {
        $stmt->execute([$k, $v]);
    }

    // Portfolio enrichment queue — projects Claude should research + screenshot + complete.
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS portfolio_queue (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER,
            project_id INTEGER,
            url        TEXT DEFAULT '',
            note       TEXT DEFAULT '',
            status     TEXT NOT NULL DEFAULT 'pending',   -- pending | processing | done | failed
            created_at TEXT DEFAULT (datetime('now','localtime')),
            done_at    TEXT
        );
    SQL);

    // Upwork Jobs tracker — applied jobs with summary, sent proposal, and the client conversation.
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS uw_jobs (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER,
            title      TEXT NOT NULL,
            summary    TEXT DEFAULT '',
            proposal   TEXT DEFAULT '',
            status     TEXT NOT NULL DEFAULT 'applied',   -- applied | replied | interview | hired | closed
            created_at TEXT DEFAULT (datetime('now','localtime')),
            updated_at TEXT DEFAULT (datetime('now','localtime'))
        );
    SQL);
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS uw_job_msgs (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            job_id     INTEGER NOT NULL,
            user_id    INTEGER,
            sender     TEXT NOT NULL DEFAULT 'client',    -- client | me
            body       TEXT NOT NULL,
            created_at TEXT DEFAULT (datetime('now','localtime'))
        );
    SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_uwjm_job ON uw_job_msgs(job_id);');

    // Upwork AI trainer — user-fed improvement rules applied to every proposal generation.
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS upwork_rules (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER,
            rule       TEXT NOT NULL,
            created_at TEXT DEFAULT (datetime('now','localtime'))
        );
    SQL);

    db_migrate_users($pdo);

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pq_status ON proposal_queue(status);');

    // Mark schema current so the next request skips all of the above.
    $pdo->prepare("INSERT INTO settings(key, value) VALUES('schema_version', ?)
        ON CONFLICT(key) DO UPDATE SET value = excluded.value")->execute([SCHEMA_VERSION]);
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

    // Upwork profile fields (fuel proposals with the developer's real identity).
    $ucols = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_COLUMN, 1);
    foreach (['uw_name', 'uw_title', 'uw_overview', 'uw_skills', 'uw_years'] as $col) {
        if (!in_array($col, $ucols, true)) $pdo->exec("ALTER TABLE users ADD COLUMN $col TEXT DEFAULT ''");
    }
    // Per-user Upwork module toggle (hides Upwork menu + profile card when off). Default OFF.
    if (!in_array('uw_enabled', $ucols, true)) $pdo->exec("ALTER TABLE users ADD COLUMN uw_enabled INTEGER DEFAULT 0");

    // Add user_id ownership column to each data table if missing.
    foreach (['projects', 'tasks', 'time_entries', 'activity_log'] as $t) {
        $cols = $pdo->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('user_id', $cols, true)) {
            $pdo->exec("ALTER TABLE $t ADD COLUMN user_id INTEGER");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_{$t}_user ON $t(user_id)");
        }
    }

    // Public portfolio settings on users.
    $ucols = $pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_COLUMN, 1);
    foreach (['portfolio_token' => 'TEXT', 'portfolio_enabled' => "INTEGER DEFAULT 1",
              'portfolio_headline' => 'TEXT', 'portfolio_bio' => 'TEXT'] as $col => $type) {
        if (!in_array($col, $ucols, true)) $pdo->exec("ALTER TABLE users ADD COLUMN $col $type");
    }
    // Give every user an unguessable share token.
    foreach ($pdo->query("SELECT id FROM users WHERE portfolio_token IS NULL OR portfolio_token = ''")->fetchAll(PDO::FETCH_COLUMN) as $uid) {
        $pdo->prepare('UPDATE users SET portfolio_token = ? WHERE id = ?')->execute([bin2hex(random_bytes(8)), $uid]);
    }

    // Soft-delete (recycle bin) column for tasks & projects.
    foreach (['tasks', 'projects'] as $t) {
        $cols = $pdo->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('deleted_at', $cols, true)) {
            $pdo->exec("ALTER TABLE $t ADD COLUMN deleted_at TEXT DEFAULT NULL");
        }
    }

    // Project resources: git repo, website, PDF attachment.
    $pcols = $pdo->query("PRAGMA table_info(projects)")->fetchAll(PDO::FETCH_COLUMN, 1);
    foreach (['git_url', 'website_url', 'pdf_path'] as $col) {
        if (!in_array($col, $pcols, true)) $pdo->exec("ALTER TABLE projects ADD COLUMN $col TEXT DEFAULT NULL");
    }
    // Portfolio visibility per project (1 = shown on the public portfolio).
    if (!in_array('in_portfolio', $pcols, true)) {
        $pdo->exec("ALTER TABLE projects ADD COLUMN in_portfolio INTEGER DEFAULT 1");
    }
    // Portfolio presentation: cover image, tech stack, screenshots (JSON array of paths).
    foreach (['thumb_path' => 'TEXT', 'technologies' => 'TEXT', 'shots' => 'TEXT'] as $col => $type) {
        if (!in_array($col, $pcols, true)) $pdo->exec("ALTER TABLE projects ADD COLUMN $col $type DEFAULT NULL");
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
