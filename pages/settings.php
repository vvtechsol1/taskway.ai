<?php
/** Settings — per-user profile + (super admin) global workspace settings. */
$ACTIVE = 'settings';
$PAGE_TITLE = 'Settings';
$PAGE_SUB = 'Personalize your workspace';

$me = current_user();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = $_POST['form'] ?? '';

    if ($form === 'profile') {
        update_user((int)$me['id'], [
            'name'       => $_POST['name'] ?? '',
            'email'      => $_POST['email'] ?? '',
            'color'      => $_POST['color'] ?? $me['color'],
            'daily_goal' => (int)($_POST['daily_goal'] ?? 6),
        ]);
        redirect(page_url('settings', ['saved' => 1]));
    }

    if ($form === 'password') {
        $cur = (string)($_POST['current_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $conf = (string)($_POST['confirm_password'] ?? '');
        if (!password_verify($cur, $me['password_hash'])) {
            $error = 'Current password is incorrect.';
        } elseif (strlen($new) < 6) {
            $error = 'New password must be at least 6 characters.';
        } elseif ($new !== $conf) {
            $error = 'New passwords do not match.';
        } else {
            update_user((int)$me['id'], ['password' => $new]);
            redirect(page_url('settings', ['saved' => 1]));
        }
    }

    if ($form === 'appearance') {
        $t = in_array($_POST['theme'] ?? '', ['light', 'dark', 'auto'], true) ? $_POST['theme'] : 'light';
        set_setting('theme', $t);
        redirect(page_url('settings', ['saved' => 1]));
    }

    if ($form === 'upwork_profile') {
        db()->prepare('UPDATE users SET uw_name = ?, uw_title = ?, uw_overview = ?, uw_skills = ?, uw_years = ? WHERE id = ?')
            ->execute([
                mb_substr(trim((string)($_POST['uw_name'] ?? '')), 0, 80),
                mb_substr(trim((string)($_POST['uw_title'] ?? '')), 0, 120),
                mb_substr(trim((string)($_POST['uw_overview'] ?? '')), 0, 2000),
                mb_substr(trim((string)($_POST['uw_skills'] ?? '')), 0, 300),
                mb_substr(trim((string)($_POST['uw_years'] ?? '')), 0, 10),
                current_user_id(),
            ]);
        redirect(page_url('settings', ['saved' => 1]));
    }

    if ($form === 'ai' && is_super_admin()) {
        $prov = in_array($_POST['ai_provider'] ?? '', ['local', 'claude', 'groq', 'gemini'], true) ? $_POST['ai_provider'] : 'local';
        set_setting('ai_provider', $prov);
        if (trim((string)($_POST['ai_api_key'] ?? '')) !== '') {
            set_setting('ai_api_key', trim((string)$_POST['ai_api_key']));
            if ($prov === 'claude') set_setting('claude_api_key', trim((string)$_POST['ai_api_key']));   // brain-dump parser bhi use kare
        }
        set_setting('ai_model', trim((string)($_POST['ai_model'] ?? '')));
        set_setting('allow_signup', isset($_POST['allow_signup']) ? '1' : '0');
        redirect(page_url('settings', ['saved' => 1]));
    }

    if ($form === 'reset' && ($_POST['reset'] ?? '') === '1') {
        $uid = (int)$me['id'];
        foreach (['time_entries', 'tasks', 'projects', 'activity_log'] as $t) {
            db()->prepare("DELETE FROM $t WHERE user_id = ?")->execute([$uid]);
        }
        redirect(page_url('settings', ['saved' => 'reset']));
    }
}

$me = current_user();   // refresh after any update
$saved = $_GET['saved'] ?? '';

require __DIR__ . '/../partials/header.php';
?>

<?php if ($saved): ?>
  <div class="badge done animate" style="padding:10px 16px;margin-bottom:18px"><?= $saved === 'reset' ? 'Your data was reset.' : 'Saved ✓' ?></div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="badge blocked" style="padding:10px 16px;margin-bottom:18px"><?= esc($error) ?></div>
<?php endif; ?>

<div style="max-width:1240px">
  <div class="grid cols-2 mb-6" style="align-items:start">
  <!-- Profile -->
  <div class="card card-pad animate">
    <div class="card-head"><h3>👤 Profile</h3></div>
    <form method="post" action="<?= page_url('settings') ?>">
      <input type="hidden" name="form" value="profile">
      <div class="row wrap" style="gap:16px">
        <div class="field grow"><label class="fld">Name</label><input class="input" name="name" value="<?= esc($me['name']) ?>"></div>
        <div class="field grow"><label class="fld">Email</label><input class="input" name="email" type="email" value="<?= esc($me['email']) ?>"></div>
      </div>
      <div class="row wrap" style="gap:16px">
        <div class="field grow"><label class="fld">Username</label><input class="input" value="<?= esc($me['username']) ?>" disabled><div class="help">Username can't be changed here.</div></div>
        <div class="field grow"><label class="fld">Daily hours goal</label><input class="input" name="daily_goal" type="number" min="1" max="16" value="<?= (int)$me['daily_goal'] ?>"></div>
      </div>
      <div class="field">
        <label class="fld">Avatar color</label>
        <div class="row wrap" style="gap:8px">
          <?php foreach (PROJECT_PALETTE as $c): ?>
            <label style="cursor:pointer"><input type="radio" name="color" value="<?= esc($c) ?>" <?= $me['color'] === $c ? 'checked' : '' ?> style="display:none"><span style="display:inline-block;width:26px;height:26px;border-radius:8px;background:<?= esc($c) ?>;outline:<?= $me['color'] === $c ? '3px solid var(--text-3)' : 'none' ?>;outline-offset:2px"></span></label>
          <?php endforeach; ?>
        </div>
      </div>
      <button class="btn btn-primary mt-4">Save profile</button>
    </form>
  </div>

  <!-- Upwork profile (fuels proposals) — Profile ke saath parallel -->
  <div class="card card-pad animate d1">
    <div class="card-head"><h3>🧑‍💼 Upwork Profile</h3><span class="badge in_progress"><span class="dot"></span>Proposals ka fuel</span></div>
    <p class="small muted" style="margin:0 0 14px">Ye info proposals ki opening banati hai — apne Upwork profile se copy karein.</p>
    <form method="post" action="<?= page_url('settings') ?>">
      <input type="hidden" name="form" value="upwork_profile">
      <div class="row wrap" style="gap:16px">
        <div class="field grow" style="min-width:160px"><label class="fld">Upwork name</label>
          <input class="input" name="uw_name" value="<?= esc($me['uw_name'] ?? '') ?>" placeholder="Jo naam Upwork par hai"></div>
        <div class="field" style="flex:0 0 130px"><label class="fld">Experience (years)</label>
          <input class="input" name="uw_years" value="<?= esc($me['uw_years'] ?? '') ?>" placeholder="6"></div>
      </div>
      <div class="field"><label class="fld">Professional title</label>
        <input class="input" name="uw_title" value="<?= esc($me['uw_title'] ?? '') ?>" placeholder="e.g. Senior Full-Stack Developer | React · Node · AI"></div>
      <div class="field"><label class="fld">Top skills <span class="muted">(comma se)</span></label>
        <input class="input" name="uw_skills" value="<?= esc($me['uw_skills'] ?? '') ?>" placeholder="React, Next.js, Node.js, TypeScript, PHP, AI integrations"></div>
      <div class="field"><label class="fld">Profile overview / description</label>
        <textarea class="textarea" name="uw_overview" style="min-height:96px" placeholder="Wohi overview jo aap ke Upwork profile par hai…"><?= esc($me['uw_overview'] ?? '') ?></textarea></div>
      <button class="btn btn-primary mt-2">Save Upwork profile</button>
    </form>
  </div>
  </div><!-- /grid -->

  <!-- Password -->
  <div class="card card-pad mb-6 animate d1">
    <div class="card-head"><h3>🔒 Change password</h3></div>
    <form method="post" action="<?= page_url('settings') ?>">
      <input type="hidden" name="form" value="password">
      <div class="field"><label class="fld">Current password</label><input class="input" type="password" name="current_password" required></div>
      <div class="row wrap" style="gap:16px">
        <div class="field grow"><label class="fld">New password</label><input class="input" type="password" name="new_password" required></div>
        <div class="field grow"><label class="fld">Confirm new password</label><input class="input" type="password" name="confirm_password" required></div>
      </div>
      <button class="btn btn-primary mt-4">Update password</button>
    </form>
  </div>

  <!-- Appearance -->
  <div class="card card-pad mb-6 animate d1">
    <div class="card-head"><h3>🎨 Appearance</h3></div>
    <form method="post" action="<?= page_url('settings') ?>">
      <input type="hidden" name="form" value="appearance">
      <div class="row wrap" style="gap:8px">
        <?php foreach (['light' => '☀️ Light', 'dark' => '🌙 Dark', 'auto' => '🖥️ Auto'] as $v => $lbl): ?>
          <label class="chip <?= setting('theme') === $v ? 'active' : '' ?>"><input type="radio" name="theme" value="<?= $v ?>" <?= setting('theme') === $v ? 'checked' : '' ?> style="display:none" onchange="this.form.submit()"><?= $lbl ?></label>
        <?php endforeach; ?>
      </div>
      <div class="help mt-2">The top-bar 🌙 toggle also switches theme instantly and remembers your choice on this device.</div>
    </form>
  </div>

  <?php if (is_super_admin()): ?>
  <!-- Brain Dump AI (global, admin) -->
  <div class="card card-pad mb-6 animate d2">
    <div class="card-head"><h3>🧠 Brain Dump AI</h3><span class="badge" style="background:var(--primary-soft);color:var(--primary)">Admin · everyone</span></div>
    <form method="post" action="<?= page_url('settings') ?>">
      <input type="hidden" name="form" value="ai">
      <div class="field">
        <label class="fld">Engine</label>
        <div class="row wrap" style="gap:10px">
          <?php $curProv = setting('ai_provider', 'local'); ?>
          <?php foreach (['local' => '⚡ Offline (free, template)', 'groq' => '🚀 Groq (FREE key!)', 'gemini' => '🔷 Google Gemini (FREE key)', 'claude' => '✨ Claude AI (paid)'] as $pv => $pl): ?>
            <label class="chip <?= $curProv === $pv ? 'active' : '' ?>"><input type="radio" name="ai_provider" value="<?= $pv ?>" <?= $curProv === $pv ? 'checked' : '' ?> style="display:none" onchange="this.closest('form').querySelectorAll('.chip').forEach(c=>c.classList.remove('active'));this.parentElement.classList.add('active')"><?= $pl ?></label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="field"><label class="fld">API key</label><input class="input" type="password" name="ai_api_key" placeholder="<?= (trim((string)setting('ai_api_key')) !== '' || trim((string)setting('claude_api_key')) !== '') ? '•••••••• (saved — blank to keep)' : 'apni key paste karein' ?>">
        <div class="help">🚀 <strong>Groq FREE key:</strong> console.groq.com → API Keys (koi card nahi) · 🔷 <strong>Gemini FREE key:</strong> aistudio.google.com/apikey · ✨ Claude: console.anthropic.com (paid). Key aap ke database mein rehti hai; na ho to offline engine chalta hai.</div></div>
      <div class="field"><label class="fld">Model <span class="muted">(optional — khali chhoro to best default)</span></label><input class="input" name="ai_model" value="<?= esc(setting('ai_model', '')) ?>" placeholder="groq: llama-3.3-70b-versatile · gemini: gemini-2.0-flash · claude: claude-sonnet-5"></div>
      <label class="row" style="gap:10px;cursor:pointer;margin-top:8px"><input type="checkbox" name="allow_signup" <?= setting('allow_signup') === '1' ? 'checked' : '' ?> style="width:18px;height:18px;accent-color:var(--primary)"> <span>Allow new people to self-register (sign up)</span></label>
      <button class="btn btn-primary mt-4">Save AI settings</button>
    </form>
  </div>
  <?php endif; ?>

  <!-- About + danger -->
  <div class="card card-pad animate d3">
    <div class="card-head"><h3>ℹ️ About</h3></div>
    <p class="dim small"><?= APP_NAME ?> v<?= APP_VERSION ?> — your AI-moderated personal work OS.</p>
    <div class="divider"></div>
    <strong style="color:var(--coral)">Danger zone</strong>
    <p class="small muted mt-2">This permanently deletes <strong>your</strong> tasks, projects and time — your account stays.</p>
    <form method="post" action="<?= page_url('settings') ?>" onsubmit="return confirm('Delete ALL your tasks, projects and time entries? This cannot be undone.')">
      <input type="hidden" name="form" value="reset"><input type="hidden" name="reset" value="1">
      <button class="btn btn-danger mt-2">Reset my data</button>
    </form>
  </div>
</div>

<?php require __DIR__ . '/../partials/footer.php'; ?>
