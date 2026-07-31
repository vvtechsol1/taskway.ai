<?php
/** Upwork Proposal — paste a job post, get a tailored cover letter + billing plan. */
$ACTIVE = 'upwork';
$PAGE_TITLE = 'Upwork Proposal';
$PAGE_SUB = 'Paste the job — get a tailored proposal with your live projects';

$aiProv = setting('ai_provider', 'local');
$aiHasKey = trim((string)setting('ai_api_key')) !== '' || trim((string)setting('claude_api_key')) !== '';
$engine = (in_array($aiProv, ['claude', 'groq', 'gemini'], true) && $aiHasKey)
    ? ucfirst($aiProv) . ' AI' : 'Smart offline';
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
      <div class="row between mt-4 wrap" style="gap:10px">
        <span class="small muted">Proposal aap ke portfolio projects check kar ke banega</span>
        <div class="row" style="gap:10px">
          <button class="btn btn-soft btn-lg" id="upQueue" title="Claude khud likhega (thodi der mein ready hoga)">🤖 Send to Claude</button>
          <button class="btn btn-primary btn-lg" id="upGo">✨ Generate now</button>
        </div>
      </div>
    </div>

    <!-- Claude queue -->
    <div class="card card-pad animate d1">
      <div class="card-head"><h3>🤖 Claude queue</h3>
        <span class="badge" id="uqCount"></span>
        <div class="card-action"><span class="small muted">Claude in par khud kaam karta hai — ready hone par yahin khul jayega</span></div>
      </div>
      <div id="uqList" class="small muted">Loading…</div>
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

      <div class="card card-pad hidden" id="upTermsCard">
        <div class="card-head"><h3>🧾 Upwork "Terms" section — aise bharein</h3>
          <div class="card-action"><button class="btn btn-soft btn-sm" onclick="upCopy('upTerms')">📋 Copy</button></div>
        </div>
        <pre id="upTerms" style="white-space:pre-wrap;font-family:inherit;font-size:13.5px;line-height:1.75;margin:0;background:var(--surface-2);border:1px solid var(--border);border-radius:12px;padding:16px"></pre>
      </div>

      <div class="card card-pad hidden" id="upTechsCard">
        <div class="card-head"><h3>🛠️ Client ki demand — Technologies</h3></div>
        <div id="upTechs" class="row wrap" style="gap:8px"></div>
      </div>

      <div class="card card-pad hidden" id="upRefsCard">
        <div class="card-head"><h3>🔗 Job ke reference links</h3></div>
        <p class="small muted" style="margin:0 0 10px">Apply se pehle in links ko achhi tarah study kar lein — client expect karega ke aap ne dekhe hain.</p>
        <div id="upRefs" style="display:flex;flex-direction:column;gap:7px"></div>
      </div>

      <div class="card card-pad hidden" id="upAnswersCard">
        <div class="card-head"><h3>💬 Client ke sawalon ke jawab</h3>
          <div class="card-action"><button class="btn btn-soft btn-sm" onclick="upCopy('upAnswers')">📋 Copy all</button></div>
        </div>
        <p class="small muted" style="margin:0 0 10px">Client ne job post mein ye poocha hai — ye jawab proposal ke saath paste karein (numbered format ho to wahi order rakhein).</p>
        <div id="upAnswers" style="display:flex;flex-direction:column;gap:10px"></div>
      </div>

      <div class="card card-pad">
        <div class="card-head"><h3>❓ Client se poochne wale sawal</h3></div>
        <div id="upQuestions" style="display:flex;flex-direction:column;gap:8px"></div>
      </div>

      <div class="card card-pad" id="upVerdictCard" style="border-width:2px">
        <div class="card-head"><h3>🧭 Kya ye job leni chahiye?</h3><span class="badge" id="upVerdictBadge"></span></div>
        <p class="dim" id="upVerdictText" style="margin:0;font-size:14.5px;line-height:1.7"></p>
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
      <p class="small dim mt-2" style="margin:0"><?= $engine !== 'Smart offline'
        ? esc($engine) . ' on hai — har job ke liye fresh, tailored letter milega.'
        : 'Har job ke liye alag, tailored AI letter chahiye? <a href="' . page_url('settings') . '" style="color:var(--primary);font-weight:700">Settings → Brain Dump AI</a> mein <strong>Groq ki FREE key</strong> laga dein (console.groq.com — koi card nahi). Abhi offline template engine chal raha hai.' ?></p>
    </div>
  </div>
</div>

<script>
(function () {
  var btn = document.getElementById('upGo');
  if (!btn || btn.__wired) return; btn.__wired = true;

  function esc(s) { return String(s || '').replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  function renderResult(r) {
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
    // Client's questions answered
    var ac = document.getElementById('upAnswersCard');
    if (r.client_answers && r.client_answers.length) {
      ac.classList.remove('hidden');
      var abox = document.getElementById('upAnswers'); abox.innerHTML = '';
      r.client_answers.forEach(function (qa) {
        var d = document.createElement('div');
        d.style.cssText = 'background:var(--surface-2);border:1px solid var(--border);border-radius:12px;padding:12px 14px';
        d.innerHTML = '<div class="small strong" style="color:var(--primary);margin-bottom:5px">Q: ' + esc(qa.question) + '</div>' +
          '<div class="small" style="line-height:1.6">' + esc(qa.answer) + '</div>';
        abox.appendChild(d);
      });
    } else ac.classList.add('hidden');
    // Client-demanded technologies
    var tk = document.getElementById('upTechsCard');
    if (r.job_techs && r.job_techs.length) {
      tk.classList.remove('hidden');
      var tbox = document.getElementById('upTechs'); tbox.innerHTML = '';
      r.job_techs.forEach(function (t) {
        var s = document.createElement('span');
        s.className = 'chip'; s.style.cssText = 'background:var(--primary-soft);border-color:transparent;color:var(--primary);font-weight:700';
        s.textContent = t; tbox.appendChild(s);
      });
    } else tk.classList.add('hidden');
    // Reference links from the job post
    var rc = document.getElementById('upRefsCard');
    if (r.reference_links && r.reference_links.length) {
      rc.classList.remove('hidden');
      var rbox = document.getElementById('upRefs'); rbox.innerHTML = '';
      r.reference_links.forEach(function (u) {
        var a = document.createElement('a');
        a.href = u; a.target = '_blank'; a.rel = 'noopener'; a.setAttribute('data-no-pjax', '1');
        a.className = 'small';
        a.style.cssText = 'background:var(--surface-2);border:1px solid var(--border);border-radius:10px;padding:9px 13px;color:var(--sky);font-weight:600;word-break:break-all';
        a.textContent = '↗ ' + u;
        rbox.appendChild(a);
      });
    } else rc.classList.add('hidden');
    // Upwork Terms fill-in guide
    var tc = document.getElementById('upTermsCard');
    if (r.terms_guide) { tc.classList.remove('hidden'); document.getElementById('upTerms').textContent = r.terms_guide; }
    else tc.classList.add('hidden');
    // Verdict: should you take this job?
    var vc = document.getElementById('upVerdictCard');
    var v = r.verdict || null;
    if (v && v.advice) {
      vc.classList.remove('hidden');
      var meta = { yes: ['🟢 Le lo!', 'var(--mint)'], caution: ['🟡 Soch kar', 'var(--amber)'], no: ['🔴 Chhor do', 'var(--coral)'] }[v.take] || ['🧭', 'var(--border-2)'];
      document.getElementById('upVerdictBadge').textContent = meta[0];
      document.getElementById('upVerdictBadge').style.cssText = 'background:transparent;border:1.5px solid ' + meta[1] + ';color:' + meta[1];
      document.getElementById('upVerdictText').textContent = v.advice;
      vc.style.borderColor = meta[1];
    } else vc.classList.add('hidden');
    document.getElementById('upResult').classList.remove('hidden');
    document.getElementById('upResult').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  function fields() {
    return {
      job: document.getElementById('upJob').value.trim(),
      budget: document.getElementById('upBudget').value.trim(),
      notes: document.getElementById('upNotes').value.trim()
    };
  }

  function repairJson(s) {
    // Some models emit raw newlines inside JSON strings — escape them.
    var out = '', inStr = false, esc = false;
    for (var i = 0; i < s.length; i++) {
      var ch = s[i];
      if (inStr) {
        if (esc) { out += ch; esc = false; continue; }
        if (ch === '\\') { out += ch; esc = true; continue; }
        if (ch === '"') { inStr = false; out += ch; continue; }
        if (ch === '\n') { out += '\\n'; continue; }
        if (ch === '\r') { continue; }
        if (ch === '\t') { out += '\\t'; continue; }
        out += ch;
      } else {
        if (ch === '"') inStr = true;
        out += ch;
      }
    }
    return out;
  }
  function parseAIJson(text) {
    text = String(text || '').replace(/```json|```/g, '');
    var m = text.match(/\{[\s\S]*\}/);
    if (!m) throw new Error('AI response format issue');
    try { return JSON.parse(m[0]); }
    catch (e) { return JSON.parse(repairJson(m[0])); }
  }
  async function callBrowserAI(p) {
    var url, opts;
    if (p.provider === 'groq') {
      url = 'https://api.groq.com/openai/v1/chat/completions';
      opts = { method: 'POST', headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + p.key },
        body: JSON.stringify({ model: p.model, max_tokens: 3000, temperature: 0.4,
          messages: [{ role: 'system', content: p.system }, { role: 'user', content: p.user }] }) };
    } else if (p.provider === 'gemini') {
      url = 'https://generativelanguage.googleapis.com/v1beta/models/' + encodeURIComponent(p.model) + ':generateContent?key=' + encodeURIComponent(p.key);
      opts = { method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ system_instruction: { parts: [{ text: p.system }] }, contents: [{ parts: [{ text: p.user }] }],
          generationConfig: { maxOutputTokens: 3000 } }) };
    } else { // claude
      url = 'https://api.anthropic.com/v1/messages';
      opts = { method: 'POST', headers: { 'Content-Type': 'application/json', 'x-api-key': p.key,
          'anthropic-version': '2023-06-01', 'anthropic-dangerous-direct-browser-access': 'true' },
        body: JSON.stringify({ model: p.model, max_tokens: 3000, system: p.system,
          messages: [{ role: 'user', content: p.user }] }) };
    }
    var res = await fetch(url, opts);
    if (!res.ok) throw new Error(p.provider + ' HTTP ' + res.status);
    var j = await res.json();
    var text = p.provider === 'groq' ? (j.choices && j.choices[0].message.content)
      : p.provider === 'gemini' ? (j.candidates && j.candidates[0].content.parts[0].text)
      : (j.content && j.content[0].text);
    var out = parseAIJson(text);
    if (!out.cover_letter) throw new Error('Incomplete AI result');
    return out;
  }

  btn.addEventListener('click', async function () {
    var f = fields();
    if (f.job.length < 40) { TW.toast('Pehle job post paste karein', 'info'); return; }
    btn.disabled = true; btn.textContent = '✨ Likh raha hoon…';
    try {
      var p = await TW.api('upwork_prompt', f);
      if (p.provider && p.provider !== 'local') {
        try {
          var r = await callBrowserAI(p);
          r.reference_links = p.reference_links; r.job_techs = p.job_techs;
          renderResult(r);
          TW.toast('Proposal ready (' + p.provider + ' AI) ✓');
          return;
        } catch (aiErr) { TW.toast(p.provider + ' issue (' + aiErr.message + ') — offline engine use ho raha', 'info'); }
      }
      var r2 = await TW.api('upwork_proposal', f);
      renderResult(r2);
      TW.toast('Proposal ready (' + (r2.engine === 'local' ? 'offline engine' : r2.engine + ' AI') + ') ✓');
    } catch (e) { TW.toast(e.message, 'err'); }
    finally { btn.disabled = false; btn.textContent = '✨ Generate now'; }
  });

  // ---- Claude queue ----
  var qbtn = document.getElementById('upQueue');
  qbtn.addEventListener('click', async function () {
    var f = fields();
    if (f.job.length < 40) { TW.toast('Pehle job post paste karein', 'info'); return; }
    qbtn.disabled = true;
    try {
      await TW.api('upwork_queue', f);
      TW.toast('Claude ko bhej diya 🤖 — ready hone par yahin milega');
      document.getElementById('upJob').value = '';
      loadQueue();
    } catch (e) { TW.toast(e.message, 'err'); }
    finally { qbtn.disabled = false; }
  });

  var uqLastStatus = {};
  var stBadge = { pending: '<span class="badge"><span class="dot"></span>Waiting</span>',
                  processing: '<span class="badge in_progress"><span class="dot"></span>Claude likh raha hai…</span>',
                  done: '<span class="badge done">✓ Ready</span>',
                  failed: '<span class="badge blocked">Failed</span>' };
  async function loadQueue() {
    try {
      var r = await TW.api('upwork_queue_list', {});
      var box = document.getElementById('uqList');
      document.getElementById('uqCount').textContent = r.items.length;
      if (!r.items.length) { box.innerHTML = '<span class="muted">Abhi kuch queue mein nahi — "🤖 Send to Claude" try karein.</span>'; return; }
      box.innerHTML = '';
      r.items.forEach(function (it) {
        // Ready hote hi khud khul jaye — koi manual refresh nahi.
        var prev = uqLastStatus[it.id];
        uqLastStatus[it.id] = it.status;
        if (it.status === 'done' && (prev === 'pending' || prev === 'processing')) {
          TW.toast('🤖 Claude ka proposal ready — khol raha hoon…');
          uqOpen(it.id);
        }
        var d = document.createElement('div');
        d.className = 'row between wrap';
        d.style.cssText = 'gap:10px;padding:11px 4px;border-bottom:1px solid var(--border)';
        d.innerHTML = '<div class="grow" style="min-width:200px"><div class="strong" style="font-size:13px">' + esc(it.excerpt) + '…</div>' +
          '<div class="muted" style="font-size:11.5px">' + esc(it.created_at) + (it.budget ? ' · ' + esc(it.budget) : '') + '</div></div>' +
          '<div class="row" style="gap:8px">' + (stBadge[it.status] || '') +
          (it.status === 'done' ? ' <button class="btn btn-soft btn-sm" onclick="uqOpen(' + it.id + ')">📄 Open</button>' : '') +
          ' <button class="icon-btn" style="width:28px;height:28px;font-size:12px" onclick="uqDel(' + it.id + ')">🗑</button></div>';
        box.appendChild(d);
      });
    } catch (e) {
      var bx = document.getElementById('uqList');
      if (bx && bx.textContent.indexOf('Loading') !== -1) bx.innerHTML = '<span class="muted">Queue load nahi ho saki — 20 sec mein dobara koshish hogi…</span>';
    }
  }
  window.uqOpen = async function (id) {
    try {
      var r = await TW.api('upwork_queue_get', { id: id });
      if (r.item && r.item.result) { renderResult(r.item.result); TW.toast('Claude ka likha proposal 🤖✓'); }
    } catch (e) { TW.toast(e.message, 'err'); }
  };
  window.uqDel = async function (id) {
    if (!confirm('Remove from queue?')) return;
    try { await TW.api('upwork_queue_delete', { id: id }); loadQueue(); } catch (e) {}
  };
  // TW (app.js) footer mein load hota hai — full page load par uske ready hone ka intezaar karo.
  function bootQueue() {
    loadQueue();
    if (window.TW && TW.setPageInterval) TW.setPageInterval(loadQueue, 20000);
    else setInterval(loadQueue, 20000);
  }
  if (window.TW) bootQueue();
  else document.addEventListener('DOMContentLoaded', bootQueue);

  window.upCopy = function (id) {
    var t = document.getElementById(id).textContent;
    (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).then(
      function () { TW.toast('Copied 📋'); }, function () { TW.toast('Copy failed — select manually', 'err'); });
  };
})();
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>
