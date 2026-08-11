<?php
/**
 * Taskway — front controller / router (multi-user).
 * index.php?page=dashboard  (default). Auth required for everything except login/signup.
 */

require_once __DIR__ . '/config.php';

$page = preg_replace('/[^a-z0-9_]/', '', (string)($_GET['page'] ?? 'dashboard'));

/* ---- Public routes ------------------------------------------------ */
if ($page === 'logout') {
    logout();
    redirect(page_url('login'));
}

if ($page === 'login') {
    if (is_logged_in()) redirect(page_url('dashboard'));
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (attempt_login((string)($_POST['login'] ?? ''), (string)($_POST['password'] ?? ''))) {
            redirect(page_url('dashboard'));
        }
        $error = 'Incorrect username or password.';
    }
    require __DIR__ . '/pages/login.php';
    exit;
}

if ($page === 'signup') {
    if (is_logged_in()) redirect(page_url('dashboard'));
    if (setting('allow_signup') !== '1') redirect(page_url('login'));
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $r = create_user([
            'username' => $_POST['username'] ?? '',
            'name'     => $_POST['name'] ?? '',
            'email'    => $_POST['email'] ?? '',
            'password' => $_POST['password'] ?? '',
            'role'     => 'user',
        ]);
        if (isset($r['error'])) {
            $error = $r['error'];
        } else {
            session_regenerate_id(true);
            $_SESSION['uid'] = $r['id'];
            redirect(page_url('dashboard'));
        }
    }
    require __DIR__ . '/pages/signup.php';
    exit;
}

/* ---- Everything below requires a logged-in user ------------------- */
require_login();

$adminPages = ['users'];
$pages = ['dashboard', 'braindump', 'tasks', 'board', 'projects', 'project', 'analytics', 'attendance', 'messages', 'portfolio', 'upwork', 'upworkjobs', 'recycle', 'settings', 'users'];
if (!in_array($page, $pages, true)) {
    $page = 'dashboard';
}
if (in_array($page, $adminPages, true) && !is_super_admin()) {
    redirect(page_url('dashboard'));
}

$file = __DIR__ . '/pages/' . $page . '.php';
if (!is_file($file)) {
    http_response_code(404);
    $ACTIVE = 'dashboard';
    $PAGE_TITLE = 'Not found';
    require __DIR__ . '/partials/header.php';
    echo '<div class="empty"><span class="emoji">🧭</span><h4>Page not found</h4></div>';
    require __DIR__ . '/partials/footer.php';
    exit;
}

require $file;
