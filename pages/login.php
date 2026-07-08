<?php /** Login screen (multi-user). Expects $error. */ ?>
<!doctype html>
<html lang="en" data-theme="<?= esc(setting('theme', 'light')) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in · <?= APP_NAME ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= url('assets/css/app.css') ?>?v=<?= APP_VERSION ?>">
</head>
<body>
<div class="auth-wrap">
  <div class="auth-card animate">
    <div class="center mb-6">
      <div class="brand-logo" style="width:56px;height:56px;font-size:28px;margin:0 auto 14px;border-radius:16px;">T</div>
      <h1 style="font-size:24px;">Welcome back</h1>
      <p class="muted">Sign in to your <?= APP_NAME ?> workspace</p>
    </div>
    <div class="card card-pad">
      <?php if (!empty($error)): ?>
        <div class="badge blocked" style="width:100%;justify-content:center;padding:9px;margin-bottom:14px;"><?= esc($error) ?></div>
      <?php endif; ?>
      <form method="post" action="<?= page_url('login') ?>">
        <div class="field">
          <label class="fld" for="login">Username or email</label>
          <input class="input" type="text" id="login" name="login" autofocus autocomplete="username" placeholder="you">
        </div>
        <div class="field">
          <label class="fld" for="pw">Password</label>
          <input class="input" type="password" id="pw" name="password" autocomplete="current-password" placeholder="••••••••">
        </div>
        <button class="btn btn-primary btn-lg" style="width:100%;margin-top:18px;">Sign in</button>
      </form>
      <?php if (setting('allow_signup') === '1'): ?>
        <p class="center small muted mt-4" style="margin-bottom:0">No account? <a href="<?= page_url('signup') ?>" style="color:var(--primary);font-weight:700">Create one</a></p>
      <?php endif; ?>
    </div>
    <p class="center muted small mt-4">Taskway · your AI-moderated work OS</p>
  </div>
</div>
</body>
</html>
