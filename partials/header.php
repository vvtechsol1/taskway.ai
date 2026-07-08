<?php
/** Shared shell: <head>, sidebar, topbar. Pages set $ACTIVE, $PAGE_TITLE, $PAGE_SUB, $TOPBAR_ACTIONS before including. */
$ACTIVE = $ACTIVE ?? 'dashboard';
$PAGE_TITLE = $PAGE_TITLE ?? APP_NAME;
$PAGE_SUB = $PAGE_SUB ?? '';
$theme = setting('theme', 'light');

// Namespaced so these never collide with a page's own $openTasks / $activeProjects.
$navOpenTasks = (int)db()->query("SELECT COUNT(*) FROM tasks WHERE status IN ('todo','in_progress','blocked')")->fetchColumn();
$navActiveProjects = (int)db()->query("SELECT COUNT(*) FROM projects WHERE status='active'")->fetchColumn();

$nav = [
    ['dashboard', '📊', 'Dashboard', null],
    ['braindump', '🧠', 'Brain Dump', null],
    ['tasks',     '✅', 'Tasks',     $navOpenTasks ?: null],
    ['board',     '📋', 'Board',     null],
    ['projects',  '📁', 'Projects',  $navActiveProjects ?: null],
    ['analytics', '📈', 'Analytics', null],
];
?>
<!doctype html>
<html lang="en" data-theme="<?= esc($theme === 'auto' ? '' : $theme) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=5">
<meta name="theme-color" content="#6C5CE7">
<title><?= esc($PAGE_TITLE) ?> · <?= APP_NAME ?></title>
<link rel="icon" href="data:image/svg+xml,<?= rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect width="100" height="100" rx="26" fill="#6C5CE7"/><path d="M28 52l14 14 30-32" stroke="#fff" stroke-width="10" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>') ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= url('assets/css/app.css') ?>?v=<?= APP_VERSION ?>">
<script>window.TASKWAY = { base: <?= json_encode(BASE_URL) ?>, api: <?= json_encode(url('api.php')) ?> };</script>
</head>
<body>
<div class="app">
  <aside class="sidebar" id="sidebar">
    <a href="<?= page_url('dashboard') ?>" class="brand">
      <span class="brand-logo">T</span>
      <span class="brand-name">Task<span>way</span></span>
    </a>
    <nav>
      <?php foreach ($nav as [$key, $ic, $label, $badge]): ?>
        <a href="<?= page_url($key) ?>" class="nav-item <?= $ACTIVE === $key ? 'active' : '' ?>">
          <span class="ic"><?= $ic ?></span><?= esc($label) ?>
          <?php if ($badge): ?><span class="nav-badge"><?= (int)$badge ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-foot">
      <div class="nav-label">Workspace</div>
      <a href="<?= page_url('settings') ?>" class="nav-item <?= $ACTIVE === 'settings' ? 'active' : '' ?>">
        <span class="ic">⚙️</span>Settings
      </a>
      <a href="<?= page_url('braindump') ?>" class="nav-item" style="margin-top:8px;background:var(--primary-soft);color:var(--primary);justify-content:center;font-weight:700;">
        <span class="ic">✨</span>Quick Add
      </a>
    </div>
  </aside>

  <div class="main">
    <header class="topbar">
      <button class="icon-btn menu-btn" onclick="document.getElementById('sidebar').classList.toggle('open')">☰</button>
      <div>
        <h1><?= esc($PAGE_TITLE) ?></h1>
        <?php if ($PAGE_SUB): ?><div class="sub"><?= esc($PAGE_SUB) ?></div><?php endif; ?>
      </div>
      <div class="topbar-right">
        <?= $TOPBAR_ACTIONS ?? '' ?>
        <button class="icon-btn" id="themeToggle" title="Toggle theme" aria-label="Toggle theme">🌙</button>
        <a href="<?= page_url('braindump') ?>" class="btn btn-primary"><span>＋</span> Brain Dump</a>
      </div>
    </header>
    <main class="content">
