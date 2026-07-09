<?php
/** Shared shell: <head>, sidebar, topbar. Pages set $ACTIVE, $PAGE_TITLE, $PAGE_SUB, $TOPBAR_ACTIONS before including. */
$ACTIVE = $ACTIVE ?? 'dashboard';
$PAGE_TITLE = $PAGE_TITLE ?? APP_NAME;
$PAGE_SUB = $PAGE_SUB ?? '';
$theme = setting('theme', 'light');

$me = current_user();
$viewingUid = (!empty($_SESSION['view_uid']) && is_super_admin()) ? (int)$_SESSION['view_uid'] : 0;
$viewingUser = $viewingUid ? get_user($viewingUid) : null;
$att = current_attendance();
$unread = chat_total_unread(current_user_id());
$rc = recycle_counts();
$recTotal = $rc['tasks'] + $rc['projects'];

// Per-user nav badges (namespaced so they never collide with a page's own vars).
$nb = db()->prepare("SELECT COUNT(*) FROM tasks WHERE user_id = ? AND status IN ('todo','in_progress','blocked')");
$nb->execute([scope_uid()]);
$navOpenTasks = (int)$nb->fetchColumn();
$np = db()->prepare("SELECT COUNT(*) FROM projects WHERE user_id = ? AND status='active'");
$np->execute([scope_uid()]);
$navActiveProjects = (int)$np->fetchColumn();

$nav = [
    ['dashboard', '📊', 'Dashboard', null],
    ['braindump', '🧠', 'Brain Dump', null],
    ['tasks',     '✅', 'Tasks',     $navOpenTasks ?: null],
    ['board',     '📋', 'Board',     null],
    ['projects',  '📁', 'Projects',  $navActiveProjects ?: null],
    ['analytics', '📈', 'Analytics', null],
    ['attendance','🕐', 'Attendance', null],
    ['messages',  '💬', 'Messages',  $unread ?: null],
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
          <?php if ($key === 'messages'): ?>
            <span class="nav-badge" id="navMsgBadge" style="<?= $badge ? '' : 'display:none' ?>"><?= (int)$badge ?></span>
          <?php elseif ($badge): ?>
            <span class="nav-badge"><?= (int)$badge ?></span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-foot">
      <?php if (is_super_admin()): ?>
        <div class="nav-label">Admin</div>
        <a href="<?= page_url('users') ?>" class="nav-item <?= $ACTIVE === 'users' ? 'active' : '' ?>">
          <span class="ic">👥</span>Users
          <span class="nav-badge"><?= (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn() ?></span>
        </a>
      <?php endif; ?>
      <div class="nav-label">Account</div>
      <a href="<?= page_url('recycle') ?>" class="nav-item <?= $ACTIVE === 'recycle' ? 'active' : '' ?>">
        <span class="ic">♻️</span>Recycle Bin
        <?php if ($recTotal): ?><span class="nav-badge"><?= $recTotal ?></span><?php endif; ?>
      </a>
      <a href="<?= page_url('settings') ?>" class="nav-item <?= $ACTIVE === 'settings' ? 'active' : '' ?>">
        <span class="ic">⚙️</span>Settings
      </a>
      <div class="row" style="gap:10px;padding:10px 12px;margin-top:4px;border-radius:12px;background:var(--surface-2);border:1px solid var(--border)">
        <div class="brand-logo" style="width:34px;height:34px;font-size:14px;border-radius:10px;background:<?= esc($me['color'] ?? '#6C5CE7') ?>">
          <?= esc(strtoupper(substr($me['name'] ?: $me['username'], 0, 1))) ?>
        </div>
        <div class="grow" style="min-width:0">
          <div class="strong truncate" style="font-size:13px"><?= esc($me['name'] ?: $me['username']) ?></div>
          <div class="muted" style="font-size:11px"><?= is_super_admin() ? '★ Super Admin' : '@' . esc($me['username']) ?></div>
        </div>
        <a href="<?= page_url('logout') ?>" class="icon-btn" title="Sign out" style="width:32px;height:32px">⏻</a>
      </div>
    </div>
  </aside>

  <div class="main">
    <header class="topbar">
      <button class="icon-btn menu-btn" onclick="document.getElementById('sidebar').classList.toggle('open')">☰</button>
      <div id="tbTitle">
        <h1><?= esc($PAGE_TITLE) ?></h1>
        <?php if ($PAGE_SUB): ?><div class="sub"><?= esc($PAGE_SUB) ?></div><?php endif; ?>
      </div>
      <div class="topbar-right">
        <span id="tbActions"><?= $TOPBAR_ACTIONS ?? '' ?></span>
        <?php if ($att): ?>
          <button class="btn" style="background:var(--danger-soft);color:var(--coral);font-weight:700" data-attendance="checkout" title="Check out">
            <span class="live-dot"></span> <span class="live-elapsed" data-elapsed="<?= max(0, time() - strtotime($att['check_in'])) ?>">00:00:00</span> · Check out
          </button>
        <?php else: ?>
          <button class="btn" style="background:var(--success-soft);color:var(--mint);font-weight:700" data-attendance="checkin" title="Check in">🟢 Check in</button>
        <?php endif; ?>
        <a href="<?= page_url('messages') ?>" class="icon-btn" id="notifBell" title="Messages" style="position:relative;text-decoration:none">
          🔔<span class="notif-badge" id="notifBadge" style="<?= $unread ? '' : 'display:none' ?>"><?= (int)$unread ?></span>
        </a>
        <button class="icon-btn" id="themeToggle" title="Toggle theme" aria-label="Toggle theme">🌙</button>
        <a href="<?= page_url('braindump') ?>" class="btn btn-primary"><span>＋</span> Brain Dump</a>
      </div>
    </header>
    <main class="content">
    <?php if ($viewingUser): ?>
      <div class="card card-pad mb-4" style="background:var(--warn-soft);border-color:var(--amber);display:flex;align-items:center;gap:12px">
        <span style="font-size:20px">👁️</span>
        <div class="grow">You are viewing <strong><?= esc($viewingUser['name'] ?: $viewingUser['username']) ?></strong>'s workspace (read-only overview).</div>
        <button class="btn btn-ghost btn-sm" onclick="TW.api('admin_exit_view',{}).then(()=>location.href='<?= page_url('users') ?>')">Exit view</button>
      </div>
    <?php endif; ?>
