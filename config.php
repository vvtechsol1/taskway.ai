<?php
/**
 * Taskway — central configuration & bootstrap.
 * Personal AI-moderated work OS. Single-user, self-hosted, zero external deps.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    // Long-lived session so the panel stays logged in on a personal machine.
    session_set_cookie_params(['lifetime' => 60 * 60 * 24 * 30, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

date_default_timezone_set('Asia/Karachi');

define('APP_NAME', 'Taskway');
define('APP_TAGLINE', 'Your AI-moderated work OS');
define('APP_VERSION', '1.1.1');

define('BASE_DIR', __DIR__);
// On hosting, point TASKWAY_DATA_DIR at a persistent volume (e.g. /data) so the SQLite file survives redeploys.
define('DATA_DIR', getenv('TASKWAY_DATA_DIR') ?: (BASE_DIR . DIRECTORY_SEPARATOR . 'data'));
define('DB_PATH', DATA_DIR . DIRECTORY_SEPARATOR . 'taskway.sqlite');

// Base URL path (folder under which the app is served, e.g. /taskway).
define('BASE_URL', rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/taskway/index.php')), '/'));

// Show PHP errors during development. Flip to 0 for a "production" feel.
if (getenv('TASKWAY_DEBUG') === '0') {
    error_reporting(0);
    ini_set('display_errors', '0');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED);
    ini_set('display_errors', '1');
}

if (!is_dir(DATA_DIR)) {
    @mkdir(DATA_DIR, 0775, true);
}

require_once BASE_DIR . '/db.php';
require_once BASE_DIR . '/helpers.php';
require_once BASE_DIR . '/auth.php';
require_once BASE_DIR . '/partials/ui.php';
