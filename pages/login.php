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
<script>
(function () {
  var EYE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
  var OFF = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
  document.querySelectorAll('input[type=password]').forEach(function (inp) {
    var w = document.createElement('div'); w.className = 'pw-wrap';
    inp.parentNode.insertBefore(w, inp); w.appendChild(inp);
    var b = document.createElement('button'); b.type = 'button'; b.className = 'pw-eye'; b.innerHTML = EYE; b.setAttribute('aria-label', 'Show password');
    w.appendChild(b);
    b.addEventListener('click', function () { var s = inp.type === 'password'; inp.type = s ? 'text' : 'password'; b.innerHTML = s ? OFF : EYE; });
  });
})();
</script>
</body>
</html>
