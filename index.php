<?php
/**
 * Taskway — front controller / router.
 * Usage: index.php?page=dashboard  (default page is the dashboard)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

$page = preg_replace('/[^a-z0-9_]/', '', (string)($_GET['page'] ?? 'dashboard'));

// Auth-free routes.
if ($page === 'logout') {
    logout();
    redirect(page_url('login'));
}

if ($page === 'login') {
    if (!auth_enabled() || is_logged_in()) {
        redirect(page_url('dashboard'));
    }
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (attempt_login((string)($_POST['password'] ?? ''))) {
            redirect(page_url('dashboard'));
        }
        $error = 'Incorrect password. Try again.';
    }
    require __DIR__ . '/pages/login.php';
    exit;
}

// Everything below requires auth (when enabled).
require_auth();

$pages = ['dashboard', 'braindump', 'tasks', 'board', 'projects', 'project', 'analytics', 'settings'];
if (!in_array($page, $pages, true)) {
    $page = 'dashboard';
}

$file = __DIR__ . '/pages/' . $page . '.php';
if (!is_file($file)) {
    http_response_code(404);
    $ACTIVE = 'dashboard';
    $PAGE_TITLE = 'Not found';
    require __DIR__ . '/partials/header.php';
    echo '<div class="empty"><span class="emoji">🧭</span><h4>Page not found</h4><p>That page doesn\'t exist yet.</p></div>';
    require __DIR__ . '/partials/footer.php';
    exit;
}

require $file;
