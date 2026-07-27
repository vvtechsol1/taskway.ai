<?php
/** Upwork Proposal — paste a job post, get a tailored cover letter + billing plan. */
$ACTIVE = 'upwork';
$PAGE_TITLE = 'Upwork Proposal';
$PAGE_SUB = 'Paste the job — get a tailored proposal with your live projects';

$engine = (setting('ai_provider') === 'claude' && trim((string)setting('claude_api_key')) !== '') ? 'Claude AI' : 'Smart offline';
$projCount = 0;
$stmt = db()->prepare("SELECT COUNT(*) FROM projects WHERE user_id = ? AND deleted_at IS NULL AND in_portfolio = 1");
$stmt->execute([current_user_id()]);
$projCount = (int)$stmt->fetchColumn();

require __DIR__ . '/../partials/header.php';
?>

<div class="grid cols-3">
  <div class="span-2" style="display:flex;flex-direction:column;gap:20px">
    <div class="card card-pad animate">
      <div class="card-head">
        <h3>📝 Job post</h3>
        <span class="badge in_progress"><span class="dot"></span><?= esc($engine) ?></span>
        <span class="badge"><?= $projCount ?> projects loaded</span>
      </div>
      <textarea id="upJob" class="textarea brain-input" style="min-height:230px"
        placeholder="Upwork ki job post yahan paste karein — poora description (summary, responsibilities, requirements)…"></textarea>
      <div class="row wrap mt-4" style="gap:10px">
        <div class="grow" style="min-width:160px">
          <label class="fld" for="upBudget">💰 Budget <span class="muted">(optional — e.g. $800 ya $30/hr)</span></label>
          <input class="input" id="upBudget" placeholder="$500">
        </div>
        <div class="grow" style="min-width:200px">
          <label class="fld" for="upNotes">🗒️ Meri baat <span class="muted">(optional — koi khaas point)</span></label>
          <input class="input" id="upNotes" placeholder="e.g. 2 hafte mein chahiye, ya main Directus pehli dafa use karunga">
        </div>
      </div>
      <div class="row between mt-4">
        <span class="small muted">Proposal aap ke portfolio projects check kar ke banega</span>
        <button class="btn btn-primary btn-lg" id="upGo">✨ Generate proposal</button>
      </div>
    </div>

    <!-- Result -->
    <div id="upResult" class="hidden" style="display:flex;flex-direction:column;gap:20px">
      <div class="card card-pad">
        <div class="card-head"><h3>✉️ Cover letter</h3>
          <div class="card-action"><button class="btn btn-soft btn-sm" onclick="upCopy('upLetter')">📋 Copy</button></div>
        </div>
        <pre id="upLetter" style="white-space:pre-wrap;font-family:inherit;font-size:14px;line-height:1.7;margin:0;background:var(--surface-2);border:1px solid var(--border);border-radius:12px;padding:18px"></pre>
      </div>

      <div class="card card-pad">
        <div class="card-head"><h3>💳 Kaise charge karein?</h3><span class="badge done" id="upMode"></span></div>
        <p class="dim" id="upReason" style="margin:0 0 14px"></p>
        <div id="upMilestonesWrap" class="hidden">
          <div style="overflow-x:auto">
            <table class="tbl">
              <thead><tr><th>#</th><th>Milestone</th><th>Due date</th><th style="text-align:right">Price</th></tr></thead>
              <tbody id="upMilestones"></tbody>
            </table>
          </div>
          <div class="row" style="justify-content:flex-end;margin-top:8px">
            <span class="small strong" id="upTotal"></span>
          </div>
        </div>
      </div>

      <div class="card card-pad">
        <div class="card-head"><h3>❓ Client se poochne wale sawal</h3></div>
        <div id="upQuestions" style="display:flex;flex-direction:column;gap:8px"></div>
      </div>
    </div>
  </div>

  <!-- Side tips -->
  <div class="animate d1" style="display:flex;flex-direction:column;gap:20px">
    <div class="card card-pad">
      <div class="card-head"><h3>🔗 Kya include hoga</h3></div>
      <div class="small dim" style="display:flex;flex-direction:column;gap:10px">
        <div>✅ Job se <strong>match hone wale projects</strong> ke live links</div>
        <div>✅ Aap ka <strong>portfolio link</strong></div>
        <div>✅ Professional <strong>cover letter</strong> (150–250 words)</div>
        <div>✅ <strong>Fixed vs Milestones</strong> ka mashwara</div>
        <div>✅ Milestones: <strong>naam + date + price</strong></div>
        <div>✅ Client ke liye smart <strong>questions</strong></div>
      </div>
    </div>
    <div class="card card-pad" style="background:var(--primary-soft);border:0">
      <strong>💡 Best results</strong>
      <p class="small dim mt-2" style="margin:0"><?= $engine === 'Claude AI'
        ? 'Claude AI on hai — har job ke liye fresh, tailored letter milega.'
        : 'Aur bhi tailored letters ke liye <a href="' . page_url('settings') . '" style="color:var(--primary);font-weight:700">Settings → Brain Dump AI</a> mein Claude API key laga dein. Abhi smart offline engine chal raha hai.' ?></p>
    </div>
  </div>
</div>

<script>
(function () {
  var btn = document.getElementById('upGo');
  if (!btn || btn.__wired) return; btn.__wired = true;

  btn.addEventListener('click', async function () {
    var job = document.getElementById('upJob').value.trim();
    if (job.length < 40) { TW.toast('Pehle job post paste karein', 'info'); return; }
    btn.disabled = true; btn.textContent = '✨ Likh raha hoon… (30-60s)';
    try {
      var r = await TW.api('upwork_proposal', {
        job: job,
        budget: document.getElementById('upBudget').value.trim(),
        notes: document.getElementById('upNotes').value.trim()
      });
      document.getElementById('upLetter').textContent = r.cover_letter || '';
      var b = r.billing || {};
      var modeLbl = { fixed: 'Fixed price', milestones: 'Milestones', hourly: 'Hourly' };
      document.getElementById('upMode').textContent = modeLbl[b.mode] || (b.mode || '');
      document.getElementById('upReason').textContent = b.reason || '';
      var ms = b.milestones || [];
      var wrap = document.getElementById('upMilestonesWrap');
      if (ms.length) {
        wrap.classList.remove('hidden');
        var tb = document.getElementById('upMilestones'); tb.innerHTML = '';
        var total = 0;
        ms.forEach(function (m, i) {
          var tr = document.createElement('tr');
          tr.innerHTML = '<td>' + (i + 1) + '</td><td class="strong">' + esc(m.name) + '</td><td>' + esc(m.date || '') + '</td><td style="text-align:right" class="strong">' + esc(m.price || '') + '</td>';
          tb.appendChild(tr);
          total += parseFloat(String(m.price || '').replace(/[^0-9.]/g, '')) || 0;
        });
        document.getElementById('upTotal').textContent = total > 0 ? ('Total: $' + total.toLocaleString()) : '';
      } else wrap.classList.add('hidden');
      var q = document.getElementById('upQuestions'); q.innerHTML = '';
      (r.questions || []).forEach(function (x) {
        var d = document.createElement('div');
        d.className = 'small'; d.style.cssText = 'background:var(--surface-2);border:1px solid var(--border);border-radius:10px;padding:10px 13px';
        d.textContent = x; q.appendChild(d);
      });
      document.getElementById('upResult').classList.remove('hidden');
      TW.toast('Proposal ready (' + (r.engine === 'claude' ? 'Claude AI' : 'offline engine') + ') ✓');
      document.getElementById('upResult').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    } catch (e) { TW.toast(e.message, 'err'); }
    finally { btn.disabled = false; btn.textContent = '✨ Generate proposal'; }
  });

  function esc(s) { return String(s || '').replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  window.upCopy = function (id) {
    var t = document.getElementById(id).textContent;
    (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).then(
      function () { TW.toast('Copied 📋'); }, function () { TW.toast('Copy failed — select manually', 'err'); });
  };
})();
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>
