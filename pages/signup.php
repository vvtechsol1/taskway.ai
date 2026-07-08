<?php /** Sign-up screen (self-registration). Expects $error. */ ?>
<!doctype html>
<html lang="en" data-theme="<?= esc(setting('theme', 'light')) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Create account · <?= APP_NAME ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= url('assets/css/app.css') ?>?v=<?= APP_VERSION ?>">
</head>
<body>
<div class="auth-wrap">
  <div class="auth-card animate">
    <div class="center mb-6">
      <div class="brand-logo" style="width:56px;height:56px;font-size:28px;margin:0 auto 14px;border-radius:16px;">T</div>
      <h1 style="font-size:24px;">Create your account</h1>
      <p class="muted">Start your own <?= APP_NAME ?> workspace</p>
    </div>
    <div class="card card-pad">
      <?php if (!empty($error)): ?>
        <div class="badge blocked" style="width:100%;justify-content:center;padding:9px;margin-bottom:14px;"><?= esc($error) ?></div>
      <?php endif; ?>
      <form method="post" action="<?= page_url('signup') ?>">
        <div class="field">
          <label class="fld" for="name">Your name</label>
          <input class="input" type="text" id="name" name="name" value="<?= esc($_POST['name'] ?? '') ?>" placeholder="Talha">
        </div>
        <div class="field">
          <label class="fld" for="username">Username</label>
          <input class="input" type="text" id="username" name="username" value="<?= esc($_POST['username'] ?? '') ?>" placeholder="talha" required>
          <div class="help">3–30 chars: letters, numbers, _ or .</div>
        </div>
        <div class="field">
          <label class="fld" for="email">Email <span class="muted">(optional)</span></label>
          <input class="input" type="email" id="email" name="email" value="<?= esc($_POST['email'] ?? '') ?>" placeholder="you@example.com">
        </div>
        <div class="field">
          <label class="fld" for="pw">Password</label>
          <input class="input" type="password" id="pw" name="password" placeholder="at least 6 characters" required>
        </div>
        <button class="btn btn-primary btn-lg" style="width:100%;margin-top:18px;">Create account</button>
      </form>
      <p class="center small muted mt-4" style="margin-bottom:0">Already have an account? <a href="<?= page_url('login') ?>" style="color:var(--primary);font-weight:700">Sign in</a></p>
    </div>
  </div>
</div>
</body>
</html>
