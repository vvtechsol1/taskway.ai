<?php
/** Portfolio — manage your public portfolio: visibility, share link, and per-project control. */
$ACTIVE = 'portfolio';
$PAGE_TITLE = 'Portfolio';
$PAGE_SUB = 'Your public showcase — share it with anyone';

$me = current_user();
$enabled = (int)($me['portfolio_enabled'] ?? 1) === 1;
$token = (string)($me['portfolio_token'] ?? '');

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$publicUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . BASE_URL . '/p.php?u=' . $token;

$projects = get_projects();
$shownCount = count(array_filter($projects, fn($p) => (int)($p['in_portfolio'] ?? 1) === 1));

$TOPBAR_ACTIONS = '<a href="' . esc($publicUrl) . '" target="_blank" rel="noopener" data-no-pjax class="btn btn-soft">👁️ Preview</a>';

require __DIR__ . '/../partials/header.php';
?>

<!-- Share / status hero -->
<div class="card animate mb-6" style="background:var(--grad-hero);color:#fff;border:0;overflow:hidden">
  <div class="card-pad" style="padding:26px 28px">
    <div class="row wrap" style="gap:18px;align-items:flex-start">
      <div class="grow" style="min-width:250px">
        <div class="row" style="gap:10px">
          <h2 style="font-size:24px">💼 Your Public Portfolio</h2>
          <span class="badge" id="pfStateBadge" style="background:rgba(255,255,255,.2);color:#fff"><?= $enabled ? '🟢 Live' : '🔒 Private' ?></span>
        </div>
        <p style="opacity:.92;margin:8px 0 0;font-size:13.5px">
          <?= $shownCount ?> of <?= count($projects) ?> projects visible · ek premium page jo aap clients/team ko share kar sakte hain.
        </p>
        <div class="row wrap mt-4" style="gap:8px;align-items:center">
          <code id="pfUrl" style="background:rgba(0,0,0,.25);padding:9px 13px;border-radius:10px;font-size:12.5px;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= esc($publicUrl) ?></code>
          <button class="btn btn-sm" style="background:#fff;color:var(--violet-600);font-weight:700" onclick="pfCopy()">📋 Copy</button>
          <a class="btn btn-sm" style="background:rgba(255,255,255,.18);color:#fff" target="_blank" rel="noopener" data-no-pjax
             href="https://wa.me/?text=<?= rawurlencode('Check out my project portfolio: ' . $publicUrl) ?>">💬 WhatsApp</a>
          <a class="btn btn-sm" style="background:rgba(255,255,255,.18);color:#fff" target="_blank" rel="noopener" data-no-pjax href="<?= esc($publicUrl) ?>">🔗 Open</a>
        </div>
      </div>
      <div style="flex:0 0 auto;text-align:right">
        <label class="fld" style="color:#fff;opacity:.9">Portfolio status</label>
        <div class="tabs" style="background:rgba(0,0,0,.22);border:0">
          <button type="button" id="pfOn"  class="<?= $enabled ? 'on' : '' ?>" onclick="pfEnable(1)">🌐 Public</button>
          <button type="button" id="pfOff" class="<?= $enabled ? '' : 'on' ?>" onclick="pfEnable(0)">🔒 Private</button>
        </div>
        <div class="small mt-2" style="opacity:.85">Private = link band ho jata hai</div>
        <button class="btn btn-sm mt-2" style="background:rgba(255,255,255,.14);color:#fff" onclick="pfRegen()">♻️ New link</button>
      </div>
    </div>
  </div>
</div>

<div class="grid cols-3">
  <!-- Profile text -->
  <div class="animate d1">
    <div class="card card-pad">
      <div class="card-head"><h3>✍️ Intro text</h3></div>
      <div class="field">
        <label class="fld" for="pfHeadline">Headline</label>
        <input class="input" id="pfHeadline" maxlength="90" placeholder="e.g. Full-stack developer & builder"
               value="<?= esc($me['portfolio_headline'] ?? '') ?>">
      </div>
      <div class="field">
        <label class="fld" for="pfBio">Short bio</label>
        <textarea class="textarea" id="pfBio" maxlength="400" placeholder="2–3 lines about you and your work…" style="min-height:110px"><?= esc($me['portfolio_bio'] ?? '') ?></textarea>
      </div>
      <button class="btn btn-primary mt-2" style="width:100%" onclick="pfSaveText()">Save intro</button>
      <div class="divider"></div>
      <p class="small muted" style="margin:0">💡 Portfolio par har project ka naam, description, progress, hours, aur uske 🌐 Live / 🔗 Git / 📄 PDF links dikhte hain — jo aap ne project edit mein lagaye hain.</p>
    </div>
  </div>

  <!-- Project visibility -->
  <div class="span-2 animate">
    <div class="card card-pad">
      <div class="card-head"><h3>📁 Projects on your portfolio</h3><span class="badge" id="pfCount"><?= $shownCount ?> visible</span></div>
      <?php if (!$projects): ?>
        <div class="empty"><span class="emoji">📁</span><h4>No projects yet</h4><p>Create projects first — then choose which ones appear publicly.</p></div>
      <?php else: foreach ($projects as $p): $on = (int)($p['in_portfolio'] ?? 1) === 1; ?>
        <div class="task" data-pf-row>
          <div class="t-ic" style="background:<?= esc($p['color']) ?>22;color:<?= esc($p['color']) ?>;width:40px;height:40px;border-radius:12px;display:grid;place-items:center;font-size:18px"><?= esc($p['icon'] ?: '📁') ?></div>
          <div class="task-main">
            <div class="task-title"><?= esc($p['name']) ?></div>
            <div class="task-meta">
              <?php if (!empty($p['website_url'])): ?><span class="tag" style="color:var(--mint)">🌐 Live</span><?php endif; ?>
              <?php if (!empty($p['git_url'])): ?><span class="tag" style="color:var(--sky)">🔗 Git</span><?php endif; ?>
              <?php if (!empty($p['pdf_path'])): ?><span class="tag" style="color:var(--amber)">📄 PDF</span><?php endif; ?>
              <?php if (empty($p['website_url']) && empty($p['git_url']) && empty($p['pdf_path'])): ?>
                <span class="tag muted">koi link nahi — Edit se add karein</span>
              <?php endif; ?>
            </div>
          </div>
          <div class="task-side">
            <button class="btn btn-soft btn-sm" onclick='pfCustomize(<?= json_encode([
                'id' => (int)$p['id'], 'name' => $p['name'],
                'thumb' => $p['thumb_path'] ?? '', 'tech' => $p['technologies'] ?? '',
                'shots' => json_decode((string)($p['shots'] ?? '[]'), true) ?: [],
            ], JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>)'>🎨 Customize</button>
            <div class="seg">
              <button type="button" class="<?= $on ? 'on' : '' ?>" data-v="done" onclick="pfProj(<?= (int)$p['id'] ?>,1,this)">🌐 Public</button>
              <button type="button" class="<?= $on ? '' : 'on' ?>" onclick="pfProj(<?= (int)$p['id'] ?>,0,this)">🔒 Private</button>
            </div>
          </div>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>

<!-- Customize project modal -->
<div class="modal-back" id="pfcModal">
  <div class="modal" style="max-width:560px">
    <div class="modal-head"><h3 id="pfcTitle">🎨 Customize</h3>
      <button class="icon-btn" data-close-modal style="margin-left:auto">✕</button></div>
    <div class="modal-body">
      <input type="hidden" id="pfcId">
      <div class="field">
        <label class="fld">🖼️ Cover image <span class="muted">(portfolio card par dikhegi · png/jpg, max 5MB)</span></label>
        <div class="row" style="gap:12px;align-items:center">
          <div id="pfcThumbPrev" style="width:120px;height:76px;border-radius:12px;background:var(--surface-3);border:1px solid var(--border-2);display:grid;place-items:center;overflow:hidden;font-size:22px">🖼️</div>
          <div class="grow">
            <input type="file" id="pfcThumbFile" accept="image/*" class="input" style="padding:8px 11px">
            <div class="help">Best: website ka screenshot ya banner (16:10)</div>
          </div>
        </div>
      </div>
      <div class="field">
        <label class="fld">🛠️ Technologies <span class="muted">(comma se alag karein)</span></label>
        <input class="input" id="pfcTech" placeholder="React, PHP, SQLite, GSAP">
      </div>
      <div class="field">
        <label class="fld">📸 Screenshots <span class="muted">(detail page par gallery — max 12)</span></label>
        <input type="file" id="pfcShotFile" accept="image/*" multiple class="input" style="padding:8px 11px">
        <div id="pfcShots" class="row wrap mt-2" style="gap:8px"></div>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-ghost" data-close-modal>Close</button>
      <button class="btn btn-primary" onclick="pfcSaveTech()">Save</button>
    </div>
  </div>
</div>

<script>
var PFC = { id: 0, base: (window.TASKWAY && window.TASKWAY.base) || '' };

function pfCustomize(d) {
  PFC.id = d.id;
  document.getElementById('pfcId').value = d.id;
  document.getElementById('pfcTitle').textContent = '🎨 ' + d.name;
  document.getElementById('pfcTech').value = d.tech || '';
  pfcThumbShow(d.thumb);
  pfcShotsShow(d.shots || []);
  document.getElementById('pfcThumbFile').value = '';
  document.getElementById('pfcShotFile').value = '';
  document.getElementById('pfcModal').classList.add('open');
}
function pfcThumbShow(path) {
  var el = document.getElementById('pfcThumbPrev');
  el.innerHTML = path ? '<img src="' + PFC.base + '/' + path + '" style="width:100%;height:100%;object-fit:cover">' : '🖼️';
}
function pfcShotsShow(shots) {
  var box = document.getElementById('pfcShots');
  box.innerHTML = '';
  (shots || []).forEach(function (s) {
    var d = document.createElement('div');
    d.style.cssText = 'position:relative;width:86px;height:58px;border-radius:9px;overflow:hidden;border:1px solid var(--border-2)';
    d.innerHTML = '<img src="' + PFC.base + '/' + s + '" style="width:100%;height:100%;object-fit:cover">' +
      '<button style="position:absolute;top:2px;right:2px;width:20px;height:20px;border:0;border-radius:6px;background:rgba(0,0,0,.6);color:#fff;cursor:pointer;font-size:11px;line-height:1" onclick="pfcShotDel(\'' + s + '\')">✕</button>';
    box.appendChild(d);
  });
  if (!(shots || []).length) box.innerHTML = '<span class="small muted">Abhi koi screenshot nahi</span>';
}
function fileToData(f) { return new Promise(function (res, rej) { var r = new FileReader(); r.onload = function () { res(r.result); }; r.onerror = rej; r.readAsDataURL(f); }); }

document.getElementById('pfcThumbFile').addEventListener('change', async function () {
  var f = this.files[0]; if (!f) return;
  if (f.size > 5 * 1024 * 1024) { TW.toast('Max 5MB', 'err'); return; }
  try {
    var r = await TW.api('portfolio_media', { id: PFC.id, thumb: await fileToData(f) });
    pfcThumbShow(r.thumb); TW.toast('Cover saved ✓');
  } catch (e) { TW.toast(e.message, 'err'); }
});
document.getElementById('pfcShotFile').addEventListener('change', async function () {
  var files = Array.prototype.slice.call(this.files);
  for (var i = 0; i < files.length; i++) {
    if (files[i].size > 5 * 1024 * 1024) { TW.toast(files[i].name + ': max 5MB', 'err'); continue; }
    try {
      var r = await TW.api('portfolio_media', { id: PFC.id, shot: await fileToData(files[i]) });
      pfcShotsShow(r.shots);
    } catch (e) { TW.toast(e.message, 'err'); }
  }
  if (files.length) TW.toast('Screenshots uploaded ✓');
  this.value = '';
});
async function pfcShotDel(path) {
  try { var r = await TW.api('portfolio_media', { id: PFC.id, remove_shot: path }); pfcShotsShow(r.shots); }
  catch (e) { TW.toast(e.message, 'err'); }
}
async function pfcSaveTech() {
  try { await TW.api('update_project', { id: PFC.id, technologies: document.getElementById('pfcTech').value.trim() }); TW.toast('Saved ✓'); document.getElementById('pfcModal').classList.remove('open'); }
  catch (e) { TW.toast(e.message, 'err'); }
}

async function pfEnable(on) {
  try {
    await TW.api('portfolio_save', { enabled: on, headline: document.getElementById('pfHeadline').value, bio: document.getElementById('pfBio').value });
    document.getElementById('pfOn').classList.toggle('on', !!on);
    document.getElementById('pfOff').classList.toggle('on', !on);
    document.getElementById('pfStateBadge').textContent = on ? '🟢 Live' : '🔒 Private';
    TW.toast(on ? 'Portfolio is now public 🌐' : 'Portfolio is now private 🔒');
  } catch (e) { TW.toast(e.message, 'err'); }
}
async function pfSaveText() {
  try {
    var on = document.getElementById('pfOn').classList.contains('on') ? 1 : 0;
    await TW.api('portfolio_save', { enabled: on, headline: document.getElementById('pfHeadline').value, bio: document.getElementById('pfBio').value });
    TW.toast('Intro saved ✓');
  } catch (e) { TW.toast(e.message, 'err'); }
}
async function pfProj(id, show, btn) {
  try {
    await TW.api('portfolio_project', { id: id, show: show });
    var seg = btn.parentElement;
    seg.querySelectorAll('button').forEach(function (b) { b.classList.toggle('on', b === btn); });
    var n = 0; document.querySelectorAll('[data-pf-row] .seg button:first-child.on').forEach(function () { n++; });
    document.getElementById('pfCount').textContent = n + ' visible';
    TW.toast(show ? 'Project public ✓' : 'Project hidden');
  } catch (e) { TW.toast(e.message, 'err'); }
}
function pfCopy() {
  var txt = document.getElementById('pfUrl').textContent.trim();
  (navigator.clipboard ? navigator.clipboard.writeText(txt) : Promise.reject()).then(
    function () { TW.toast('Link copied 📋'); },
    function () { prompt('Copy this link:', txt); }
  );
}
async function pfRegen() {
  if (!confirm('Generate a NEW link? Purana link kaam karna band kar dega.')) return;
  try { var r = await TW.api('portfolio_regen', {}); TW.toast('New link generated'); setTimeout(function(){ TW.reload(); }, 400); }
  catch (e) { TW.toast(e.message, 'err'); }
}
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>
