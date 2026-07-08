<?php
/** Settings — personalize the workspace. Handles its own POST (one hidden `form` marker per card). */
$ACTIVE = 'settings';
$PAGE_TITLE = 'Settings';
$PAGE_SUB = 'Personalize your workspace';
$saved = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = (string)($_POST['form'] ?? '');

    /* ---- Danger zone: wipe all data (settings kept). Only on explicit confirm+flag. ---- */
    if ($form === 'reset' && ($_POST['reset'] ?? '') === '1') {
        db()->exec('DELETE FROM time_entries');
        db()->exec('DELETE FROM tasks');
        db()->exec('DELETE FROM activity_log');
        db()->exec('DELETE FROM projects');
        redirect(page_url('settings', ['saved' => 'reset']));
    }

    /* ---- Profile ---- */
    if ($form === 'profile') {
        $name = trim((string)($_POST['user_name'] ?? ''));
        set_setting('user_name', $name !== '' ? $name : 'there');

        $goal = (int)($_POST['daily_hours_goal'] ?? 6);
        $goal = max(1, min(16, $goal));
        set_setting('daily_hours_goal', (string)$goal);

        redirect(page_url('settings', ['saved' => 1]));
    }

    /* ---- Appearance ---- */
    if ($form === 'appearance') {
        $theme = (string)($_POST['theme'] ?? 'light');
        if (!in_array($theme, ['light', 'dark', 'auto'], true)) {
            $theme = 'light';
        }
        set_setting('theme', $theme);
        redirect(page_url('settings', ['saved' => 1]));
    }

    /* ---- Brain Dump AI ---- */
    if ($form === 'ai') {
        $provider = (string)($_POST['ai_provider'] ?? 'local');
        if (!in_array($provider, ['local', 'claude'], true)) {
            $provider = 'local';
        }
        set_setting('ai_provider', $provider);

        // Blank key means "keep the existing one" — never overwrite with empty.
        $newKey = trim((string)($_POST['claude_api_key'] ?? ''));
        if ($newKey !== '') {
            set_setting('claude_api_key', $newKey);
        }

        $model = trim((string)($_POST['claude_model'] ?? ''));
        set_setting('claude_model', $model !== '' ? $model : 'claude-sonnet-5');

        redirect(page_url('settings', ['saved' => 1]));
    }

    /* ---- Security (password lock) ---- */
    if ($form === 'security') {
        $wantAuth    = ($_POST['auth_enabled'] ?? '') === '1';
        $newPw       = (string)($_POST['new_password'] ?? '');
        $confirmPw   = (string)($_POST['confirm_password'] ?? '');
        $existingHash = (string)setting('auth_password'); // request-start value; unchanged so far

        if ($newPw !== '') {
            if ($newPw !== $confirmPw) {
                $error = 'Those passwords don\'t match — nothing was changed.';
            } elseif (strlen($newPw) < 4) {
                $error = 'Pick a password of at least 4 characters.';
            } else {
                set_setting('auth_password', password_hash($newPw, PASSWORD_DEFAULT));
                set_setting('auth_enabled', $wantAuth ? '1' : '0');
            }
        } else {
            // No new password typed — just toggle the lock.
            if ($wantAuth) {
                if ($existingHash !== '') {
                    set_setting('auth_enabled', '1');
                } else {
                    // Guard: never enable the lock without a stored password (would lock the user out).
                    set_setting('auth_enabled', '0');
                    $error = 'Set a password first — Taskway won\'t enable the lock without one.';
                }
            } else {
                set_setting('auth_enabled', '0'); // remove-password / disable path
            }
        }

        if ($error === '') {
            redirect(page_url('settings', ['saved' => 1]));
        }
    }
}

/* ---- Current values for the form (read after POST; success paths already redirected) ---- */
$userName   = (string)setting('user_name', 'there');
$userField  = $userName === 'there' ? '' : $userName;
$goalHours  = (int)setting('daily_hours_goal', '6');
$theme      = (string)setting('theme', 'light');
$provider   = (string)setting('ai_provider', 'local');
$hasKey     = trim((string)setting('claude_api_key')) !== '';
$model      = (string)setting('claude_model', 'claude-sonnet-5');
$authOn     = setting('auth_enabled') === '1';
$hasPassword = (string)setting('auth_password') !== '';
$savedFlag  = (string)($_GET['saved'] ?? '');

require __DIR__ . '/../partials/header.php';
?>

<style>
.set-wrap { max-width: 720px; margin: 0 auto; display: flex; flex-direction: column; gap: 20px; }
.opt {
  display: flex; gap: 12px; align-items: flex-start;
  padding: 13px 14px; border: 1px solid var(--border-2); border-radius: 13px;
  background: var(--surface-2); cursor: pointer;
  transition: all var(--dur) var(--ease);
}
.opt:hover { background: var(--surface); border-color: var(--border-2); }
.opt.active { border-color: var(--primary); background: var(--primary-soft); }
.opt input[type=radio] { margin-top: 3px; accent-color: var(--primary); }
.opt .opt-t { font-weight: 650; font-size: 14px; }
.opt .opt-d { font-size: 12.5px; color: var(--text-3); margin-top: 2px; }
.chip.on-radio input { display: none; }
.toggle-row {
  display: flex; align-items: flex-start; gap: 12px;
  padding: 13px 14px; border: 1px solid var(--border-2); border-radius: 13px;
  background: var(--surface-2);
}
.toggle-row input[type=checkbox] { width: 20px; height: 20px; margin-top: 1px; accent-color: var(--primary); cursor: pointer; flex: 0 0 auto; }
.danger-zone { border: 1px solid var(--danger-soft); background: var(--danger-soft); border-radius: 13px; padding: 14px 15px; }
</style>

<div class="set-wrap">

  <?php if ($savedFlag === 'reset'): ?>
    <div class="badge done animate" style="width:100%;justify-content:center;padding:11px;">🧹 All tasks, time and projects were reset. Your settings were kept.</div>
  <?php elseif ($savedFlag !== ''): ?>
    <div class="badge done animate" style="width:100%;justify-content:center;padding:11px;">✅ Settings saved.</div>
  <?php endif; ?>

  <?php if ($error !== ''): ?>
    <div class="badge blocked animate" style="width:100%;justify-content:center;padding:11px;"><?= esc($error) ?></div>
  <?php endif; ?>

  <!-- 1) Profile -->
  <div class="card card-pad animate">
    <div class="card-head"><h3>👤 Profile</h3></div>
    <form method="post" action="<?= page_url('settings') ?>">
      <input type="hidden" name="form" value="profile">
      <div class="field">
        <label class="fld" for="user_name">Display name</label>
        <input class="input" type="text" id="user_name" name="user_name" maxlength="60"
               value="<?= esc($userField) ?>" placeholder="Your name">
        <div class="help">Shown in your dashboard greeting.</div>
      </div>
      <div class="field">
        <label class="fld" for="daily_hours_goal">Daily hours goal</label>
        <input class="input" type="number" id="daily_hours_goal" name="daily_hours_goal"
               min="1" max="16" step="1" value="<?= esc((string)$goalHours) ?>" style="max-width:160px">
        <div class="help">Drives the daily goal ring on your dashboard (1–16 hours).</div>
      </div>
      <div class="row mt-4"><button class="btn btn-primary" type="submit">Save profile</button></div>
    </form>
  </div>

  <!-- 2) Appearance -->
  <div class="card card-pad animate">
    <div class="card-head"><h3>🎨 Appearance</h3></div>
    <form method="post" action="<?= page_url('settings') ?>">
      <input type="hidden" name="form" value="appearance">
      <div class="field">
        <label class="fld">Default theme</label>
        <div class="row wrap" id="themeChips" style="gap:8px">
          <?php foreach (['light' => '☀️ Light', 'dark' => '🌙 Dark', 'auto' => '🌗 Auto'] as $val => $label): ?>
            <label class="chip on-radio <?= $theme === $val ? 'active' : '' ?>">
              <input type="radio" name="theme" value="<?= esc($val) ?>" <?= $theme === $val ? 'checked' : '' ?>>
              <?= $label ?>
            </label>
          <?php endforeach; ?>
        </div>
        <div class="help">The topbar 🌙 button still lets you flip themes instantly on this device.</div>
      </div>
      <div class="row mt-4"><button class="btn btn-primary" type="submit">Save appearance</button></div>
    </form>
  </div>

  <!-- 3) Brain Dump AI -->
  <div class="card card-pad animate">
    <div class="card-head">
      <h3>🧠 Brain Dump AI</h3>
      <span class="badge <?= $provider === 'claude' && $hasKey ? 'in_progress' : '' ?>">
        <?= $provider === 'claude' && $hasKey ? 'Claude AI' : 'Smart parser' ?>
      </span>
    </div>
    <form method="post" action="<?= page_url('settings') ?>">
      <input type="hidden" name="form" value="ai">
      <div class="field">
        <label class="fld">Parsing engine</label>
        <div style="display:grid;gap:10px" id="providerOpts">
          <label class="opt <?= $provider === 'local' ? 'active' : '' ?>">
            <input type="radio" name="ai_provider" value="local" <?= $provider === 'local' ? 'checked' : '' ?>>
            <span>
              <span class="opt-t">Smart parser <span class="muted" style="font-weight:600">· offline</span></span>
              <span class="opt-d">Built-in. Reads status, time, projects and priority. No key needed.</span>
            </span>
          </label>
          <label class="opt <?= $provider === 'claude' ? 'active' : '' ?>">
            <input type="radio" name="ai_provider" value="claude" <?= $provider === 'claude' ? 'checked' : '' ?>>
            <span>
              <span class="opt-t">Claude AI <span class="muted" style="font-weight:600">· smarter</span></span>
              <span class="opt-d">Sends your notes to Claude for richer extraction. Needs an API key.</span>
            </span>
          </label>
        </div>
      </div>

      <div class="field">
        <label class="fld" for="claude_api_key">Claude API key</label>
        <input class="input" type="password" id="claude_api_key" name="claude_api_key" autocomplete="off"
               placeholder="<?= $hasKey ? '•••••••••• saved — leave blank to keep' : 'sk-ant-…' ?>">
        <div class="help">Get a key at console.anthropic.com. Stored locally in your SQLite DB. Leave blank to keep the current key.</div>
      </div>

      <div class="field">
        <label class="fld" for="claude_model">Claude model</label>
        <input class="input" type="text" id="claude_model" name="claude_model"
               value="<?= esc($model) ?>" placeholder="claude-sonnet-5" style="max-width:280px">
      </div>

      <p class="help" style="margin-top:12px">If Claude AI is selected but no key is set, Taskway automatically falls back to the offline smart parser.</p>
      <div class="row mt-4"><button class="btn btn-primary" type="submit">Save AI settings</button></div>
    </form>
  </div>

  <!-- 4) Security -->
  <div class="card card-pad animate">
    <div class="card-head">
      <h3>🔒 Security</h3>
      <span class="badge <?= $authOn ? 'done' : '' ?>"><?= $authOn ? 'Lock on' : 'Open' ?></span>
    </div>
    <form method="post" action="<?= page_url('settings') ?>">
      <input type="hidden" name="form" value="security">
      <div class="field">
        <label class="toggle-row">
          <input type="checkbox" name="auth_enabled" value="1" <?= $authOn ? 'checked' : '' ?>>
          <span>
            <span style="font-weight:650;font-size:14px;display:block">Require a password to open Taskway</span>
            <span class="help" style="margin-top:2px">
              <?php if ($hasPassword): ?>A password is set. Untick and save to remove the lock.<?php else: ?>Set a password below before enabling this.<?php endif; ?>
            </span>
          </span>
        </label>
      </div>
      <div class="field">
        <label class="fld" for="new_password"><?= $hasPassword ? 'New password' : 'Password' ?></label>
        <input class="input" type="password" id="new_password" name="new_password" autocomplete="new-password"
               placeholder="<?= $hasPassword ? 'Leave blank to keep current' : '••••••••' ?>" style="max-width:320px">
      </div>
      <div class="field">
        <label class="fld" for="confirm_password">Confirm password</label>
        <input class="input" type="password" id="confirm_password" name="confirm_password" autocomplete="new-password"
               placeholder="Repeat password" style="max-width:320px">
      </div>
      <div class="help">Stored as a secure hash — the password itself is never saved or shown.</div>
      <div class="row mt-4"><button class="btn btn-primary" type="submit">Save security</button></div>
    </form>
  </div>

  <!-- 5) About -->
  <div class="card card-pad animate">
    <div class="card-head"><h3>ℹ️ About</h3></div>
    <div class="row" style="gap:14px">
      <div class="brand-logo" style="width:46px;height:46px;font-size:24px;border-radius:14px">T</div>
      <div class="grow">
        <div class="big" style="font-size:18px"><?= esc(APP_NAME) ?> <span class="muted small strong">v<?= esc(APP_VERSION) ?></span></div>
        <div class="small muted">AI-moderated personal work OS</div>
      </div>
    </div>

    <div class="divider"></div>

    <div class="danger-zone">
      <div class="row between wrap" style="gap:12px">
        <div>
          <div class="strong" style="color:var(--coral)">⚠️ Danger zone</div>
          <div class="small muted">Permanently deletes all tasks, time entries, projects and activity. Your settings stay.</div>
        </div>
        <form method="post" action="<?= page_url('settings') ?>"
              onsubmit="return confirm('This permanently deletes ALL tasks, time entries, projects and activity.\n\nYour settings are kept. This cannot be undone. Continue?');">
          <input type="hidden" name="form" value="reset">
          <input type="hidden" name="reset" value="1">
          <button type="submit" class="btn btn-danger">🗑 Reset all data</button>
        </form>
      </div>
    </div>
  </div>

</div>

<script>
(function () {
  function wire(scope, itemSel) {
    var root = document.querySelector(scope);
    if (!root) return;
    root.addEventListener('change', function () {
      root.querySelectorAll(itemSel).forEach(function (el) {
        var input = el.querySelector('input[type=radio]');
        el.classList.toggle('active', input && input.checked);
      });
    });
  }
  wire('#themeChips', '.chip');
  wire('#providerOpts', '.opt');
})();
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>
