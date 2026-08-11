<?php
/** Upwork Proposal — paste a job post, get a tailored cover letter + billing plan. */
if ((int)(current_user()['uw_enabled'] ?? 0) !== 1) redirect(page_url('dashboard'));
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
      <p class="small muted" style="margin:0 0 8px">💡 Tip: open the job on Upwork, press <strong>Ctrl+A</strong> then <strong>Ctrl+C</strong>, and paste the whole page here — budget, rate and duration are <strong>auto-detected</strong> and the clutter is cleaned up.</p>
      <textarea id="upJob" class="textarea brain-input" style="min-height:230px"
        placeholder="Paste the Upwork job post here — the full description (summary, responsibilities, requirements)…"></textarea>
      <div class="row wrap mt-4" style="gap:10px">
        <div class="grow" style="min-width:160px">
          <label class="fld" for="upBudget">💰 Budget <span class="muted">(optional — e.g. $800 or $30/hr)</span></label>
          <input class="input" id="upBudget" placeholder="$500">
        </div>
        <div class="grow" style="min-width:200px">
          <label class="fld" for="upNotes">🗒️ My note <span class="muted">(optional — anything specific)</span></label>
          <input class="input" id="upNotes" placeholder="e.g. needs to ship in 2 weeks, or emphasize the React experience">
        </div>
      </div>
      <div class="row between mt-4 wrap" style="gap:10px">
        <span class="small muted">The proposal is built from your matching portfolio projects</span>
        <div class="row" style="gap:10px">
          <button class="btn btn-soft btn-lg" id="upQueue" title="Claude writes it himself (ready in a little while)">🤖 Send to Claude</button>
          <button class="btn btn-primary btn-lg" id="upGo">✨ Generate now</button>
        </div>
      </div>
    </div>

    <!-- Claude queue -->
    <div class="card card-pad animate d1">
      <div class="card-head"><h3>🤖 Claude queue</h3>
        <span class="badge" id="uqCount"></span>
        <div class="card-action"><span class="small muted">Claude works on these himself — the result opens right here when ready</span></div>
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
        <div class="card-head"><h3>💳 How should you charge?</h3><span class="badge done" id="upMode"></span></div>
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
        <div class="card-head"><h3>🧾 Upwork "Terms" section — how to fill it</h3>
          <div class="card-action"><button class="btn btn-soft btn-sm" onclick="upCopy('upTerms')">📋 Copy</button></div>
        </div>
        <pre id="upTerms" style="white-space:pre-wrap;font-family:inherit;font-size:13.5px;line-height:1.75;margin:0;background:var(--surface-2);border:1px solid var(--border);border-radius:12px;padding:16px"></pre>
      </div>

      <div class="card card-pad hidden" id="upTechsCard">
        <div class="card-head"><h3>🛠️ Technologies the client wants</h3></div>
        <div id="upTechs" class="row wrap" style="gap:8px"></div>
      </div>

      <div class="card card-pad hidden" id="upRefsCard">
        <div class="card-head"><h3>🔗 Reference links from the job</h3></div>
        <p class="small muted" style="margin:0 0 10px">Study these links carefully before applying — the client will expect you to have seen them.</p>
        <div id="upRefs" style="display:flex;flex-direction:column;gap:7px"></div>
      </div>

      <div class="card card-pad hidden" id="upAnswersCard">
        <div class="card-head"><h3>💬 Answers to the client's questions</h3>
          <div class="card-action"><button class="btn btn-soft btn-sm" onclick="upCopy('upAnswers')">📋 Copy all</button></div>
        </div>
        <p class="small muted" style="margin:0 0 10px">The client asked these in the job post — paste these answers along with your proposal (keep the same order if it's numbered).</p>
        <div id="upAnswers" style="display:flex;flex-direction:column;gap:10px"></div>
      </div>

      <div class="card card-pad">
        <div class="card-head"><h3>❓ Questions to ask the client</h3></div>
        <div id="upQuestions" style="display:flex;flex-direction:column;gap:8px"></div>
      </div>

      <div class="card card-pad" id="upVerdictCard" style="border-width:2px">
        <div class="card-head"><h3>🧭 Should you take this job?</h3><span class="badge" id="upVerdictBadge"></span></div>
        <p class="dim" id="upVerdictText" style="margin:0;font-size:14.5px;line-height:1.7"></p>
      </div>
    </div>
  </div>

  <!-- Side tips -->
  <div class="animate d1" style="display:flex;flex-direction:column;gap:20px;min-width:0">
    <div class="card card-pad">
      <div class="card-head"><h3>🔗 What's included</h3></div>
      <div class="small dim" style="display:flex;flex-direction:column;gap:10px">
        <div>✅ Live links of <strong>projects matching the job</strong></div>
        <div>✅ Your <strong>portfolio link</strong></div>
        <div>✅ Professional <strong>cover letter</strong> (150–250 words)</div>
        <div>✅ <strong>Fixed vs Milestones</strong> advice</div>
        <div>✅ Milestones: <strong>name + date + price</strong></div>
        <div>✅ Smart <strong>questions</strong> for the client</div>
      </div>
    </div>
    <div class="card card-pad" style="background:var(--primary-soft);border:0">
      <strong>💡 Best results</strong>
      <p class="small dim mt-2" style="margin:0"><?= $engine !== 'Smart offline'
        ? esc($engine) . ' is on — every job gets a fresh, tailored letter.'
        : 'Want a fresh, tailored AI letter for every job? Add a <strong>FREE Groq key</strong> in <a href="' . page_url('settings') . '" style="color:var(--primary);font-weight:700">Settings → Brain Dump AI</a> (console.groq.com — no card needed). The offline template engine is running right now.' ?></p>
    </div>

    <!-- AI Trainer: feed improvements that apply to every future proposal -->
    <div class="card card-pad animate d1">
      <div class="card-head"><h3>🧠 AI Trainer</h3>
        <span class="badge" id="urCount"></span>
      </div>
      <p class="small muted" style="margin:0 0 10px">Teach the AI — feed an improvement and every future proposal (Generate now + Claude) will follow it.</p>
      <textarea id="urInput" class="textarea" style="min-height:74px;width:100%"
        placeholder="Teach me something…"></textarea>
      <button class="btn btn-primary" id="urFeed" style="width:100%;margin-top:10px">🧠 Feed</button>
      <div id="urList" style="margin-top:14px"></div>
    </div>
  </div>
</div>

<!-- AI Trainer rule viewer modal -->
<div class="modal-back" id="urModal">
  <div class="modal" style="max-width:560px">
    <div class="modal-head"><h3>🧠 Fed rule</h3>
      <button class="icon-btn" data-close-modal style="margin-left:auto">✕</button></div>
    <div class="modal-body">
      <div id="urModalText" style="white-space:pre-wrap;font-size:13.5px;line-height:1.7;background:var(--surface-2);border:1px solid var(--border);border-radius:12px;padding:16px;max-height:55vh;overflow-y:auto"></div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-ghost" data-close-modal>Close</button>
      <button class="btn" style="background:var(--danger-soft);color:var(--coral);font-weight:700" id="urModalDel">🗑️ Delete rule</button>
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
      var meta = { yes: ['🟢 Take it!', 'var(--mint)'], caution: ['🟡 Think twice', 'var(--amber)'], no: ['🔴 Skip it', 'var(--coral)'] }[v.take] || ['🧭', 'var(--border-2)'];
      document.getElementById('upVerdictBadge').textContent = meta[0];
      document.getElementById('upVerdictBadge').style.cssText = 'background:transparent;border:1.5px solid ' + meta[1] + ';color:' + meta[1];
      document.getElementById('upVerdictText').textContent = v.advice;
      vc.style.borderColor = meta[1];
      showVerdictAlert(v);
    } else vc.classList.add('hidden');
    document.getElementById('upResult').classList.remove('hidden');
    document.getElementById('upResult').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  // Proposal-ready side alert — green (take it) / yellow (think twice) / red (skip it).
  function showVerdictAlert(v) {
    var old = document.getElementById('upVerdictAlert');
    if (old) old.remove();
    var conf = {
      yes:     { bg: '#0FA968', icon: '✅', title: 'Proposal ready — TAKE this job',   sub: 'Strong fit for your stack. Apply fast!' },
      caution: { bg: '#D97706', icon: '⚠️', title: 'Proposal ready — think twice',     sub: 'Doable, but read the advice before applying.' },
      no:      { bg: '#DC2626', icon: '⛔', title: 'Proposal ready — better to SKIP',  sub: 'Low budget / red flags — see why below.' }
    }[v.take] || { bg: '#475569', icon: '🧭', title: 'Proposal ready', sub: '' };
    var el = document.createElement('div');
    el.id = 'upVerdictAlert';
    el.style.cssText = 'position:fixed;top:84px;right:18px;z-index:9999;max-width:340px;background:' + conf.bg +
      ';color:#fff;border-radius:16px;padding:16px 18px;box-shadow:0 16px 40px -10px rgba(0,0,0,.45);cursor:pointer;' +
      'animation:uvaIn .35s cubic-bezier(.2,.9,.3,1.2)';
    el.innerHTML =
      '<div style="display:flex;gap:11px;align-items:flex-start">' +
        '<span style="font-size:24px;line-height:1">' + conf.icon + '</span>' +
        '<div style="min-width:0">' +
          '<div style="font-weight:800;font-size:14.5px;line-height:1.35">' + conf.title + '</div>' +
          (conf.sub ? '<div style="font-size:12.5px;opacity:.92;margin-top:3px">' + conf.sub + '</div>' : '') +
          '<div style="font-size:12px;opacity:.85;margin-top:6px">' + esc(String(v.advice || '').slice(0, 110)) + (String(v.advice || '').length > 110 ? '…' : '') + '</div>' +
        '</div>' +
        '<button style="background:rgba(255,255,255,.22);border:0;color:#fff;width:22px;height:22px;border-radius:7px;cursor:pointer;flex:0 0 auto;font-size:12px;line-height:1" ' +
          'onclick="event.stopPropagation();this.closest(\'#upVerdictAlert\').remove()">✕</button>' +
      '</div>';
    el.addEventListener('click', function () {
      document.getElementById('upVerdictCard').scrollIntoView({ behavior: 'smooth', block: 'center' });
      el.remove();
    });
    if (!document.getElementById('uvaStyle')) {
      var st = document.createElement('style');
      st.id = 'uvaStyle';
      st.textContent = '@keyframes uvaIn{from{opacity:0;transform:translateX(30px) scale(.95)}to{opacity:1;transform:none}}';
      document.head.appendChild(st);
    }
    document.body.appendChild(el);
    setTimeout(function () { if (el.parentNode) el.remove(); }, 20000);
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
      var gq = { model: p.model, max_tokens: 2200, temperature: 0.4,
          messages: [{ role: 'system', content: p.system }, { role: 'user', content: p.user }] };
      // gpt-oss models burn tokens on hidden reasoning — keep it minimal so the JSON isn't truncated.
      if (/gpt-oss/i.test(p.model)) gq.reasoning_effort = 'low';
      opts = { method: 'POST', headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + p.key },
        body: JSON.stringify(gq) };
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
    // Groq gpt-oss free tier = 8k tokens/min — on 413/429 retry once on llama (12k TPM)
    // so the user still gets a full-rules AI letter instead of the offline template.
    if (!res.ok && p.provider === 'groq' && /gpt-oss/i.test(p.model) && (res.status === 413 || res.status === 429)) {
      var retry = JSON.parse(opts.body);
      retry.model = 'llama-3.3-70b-versatile';
      delete retry.reasoning_effort;
      res = await fetch(url, { method: 'POST', headers: opts.headers, body: JSON.stringify(retry) });
    }
    if (!res.ok) throw new Error(p.provider + ' HTTP ' + res.status);
    var j = await res.json();
    var text = p.provider === 'groq' ? (j.choices && j.choices[0].message.content)
      : p.provider === 'gemini' ? (j.candidates && j.candidates[0].content.parts[0].text)
      : (j.content && j.content[0].text);
    var out = parseAIJson(text);
    if (!out.cover_letter) throw new Error('Incomplete AI result');
    return out;
  }

  // Smart paste: when the whole Upwork job page is pasted, auto-detect budget/rate
  // and strip the obvious page chrome so only the job content remains.
  var jobBox = document.getElementById('upJob');
  jobBox.addEventListener('paste', function () {
    setTimeout(function () {
      var t = jobBox.value;
      if (t.length < 200) return;   // a normal hand-paste, leave it alone

      // 1) Budget detection: hourly range first, then fixed price near budget-ish words.
      var budgetEl = document.getElementById('upBudget');
      if (!budgetEl.value.trim()) {
        var m = t.match(/\$\s?([\d,]+(?:\.\d+)?)\s*[-–]\s*\$\s?([\d,]+(?:\.\d+)?)\s*(?:\/\s*hr|per hour|hourly)/i)
             || t.match(/hourly[^$]{0,40}\$\s?([\d,]+(?:\.\d+)?)\s*[-–]\s*\$\s?([\d,]+(?:\.\d+)?)/i);
        if (m) { budgetEl.value = '$' + m[1] + '-$' + m[2] + '/hr'; }
        else {
          m = t.match(/(?:fixed[- ]price|budget|est\.?\s*budget)[^$]{0,60}\$\s?([\d,]+(?:\.\d+)?)/i)
           || t.match(/\$\s?([\d,]+(?:\.\d+)?)\s*(?:\n|\s)*(?:fixed[- ]price|budget)/i);
          if (m) budgetEl.value = '$' + m[1];
        }
        if (budgetEl.value) TW.toast('Budget detected: ' + budgetEl.value + ' ✓');
      }

      // 2) Strip Upwork page chrome (exact-line matches only — conservative).
      var junk = /^(apply now|save job|apply saved|log in|sign ?up|find work|find talent|why upwork|search|messages|notifications|help|how it works|upwork|home|jobs|my feed|report this job|flag as inappropriate|share|open job in a new window|about the client|member since.*|view job posting|see more|show more|less)$/i;
      var lines = t.split('\n'), out = [], blank = 0;
      for (var i = 0; i < lines.length; i++) {
        var ln = lines[i].trim();
        if (junk.test(ln)) continue;
        if (ln === '') { if (++blank > 1) continue; } else blank = 0;
        out.push(lines[i]);
      }
      var cleaned = out.join('\n').trim();
      if (cleaned.length >= 120 && cleaned.length < t.length) jobBox.value = cleaned;
    }, 60);
  });

  btn.addEventListener('click', async function () {
    var f = fields();
    if (f.job.length < 40) { TW.toast('Paste the job post first', 'info'); return; }
    btn.disabled = true; btn.textContent = '✨ Writing…';
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
    if (f.job.length < 40) { TW.toast('Paste the job post first', 'info'); return; }
    qbtn.disabled = true;
    try {
      await TW.api('upwork_queue', f);
      TW.toast('Sent to Claude 🤖 — it will appear here when ready');
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
      if (!r.items.length) { box.innerHTML = '<span class="muted">Nothing in the queue yet — try "🤖 Send to Claude".</span>'; return; }
      box.innerHTML = '';
      r.items.forEach(function (it) {
        // Opens automatically the moment it's ready — no manual refresh.
        var prev = uqLastStatus[it.id];
        uqLastStatus[it.id] = it.status;
        if (it.status === 'done' && (prev === 'pending' || prev === 'processing')) {
          TW.toast('🤖 Claude proposal ready — opening…');
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
      if (bx && bx.textContent.indexOf('Loading') !== -1) bx.innerHTML = '<span class="muted">Could not load the queue — retrying in 20 sec…</span>';
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
  // ---- AI Trainer (feed rules) ----
  function escHtml(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
  var urRules = {};   // id -> full rule text (for the popup)
  async function loadRules() {
    try {
      var r = await TW.api('upwork_rule_list', {});
      document.getElementById('urCount').textContent = r.items.length ? r.items.length + ' rules learned' : '';
      var box = document.getElementById('urList');
      urRules = {};
      if (!r.items.length) { box.innerHTML = '<span class="small muted">No rules fed yet.</span>'; return; }
      box.innerHTML = r.items.map(function (it) {
        urRules[it.id] = it.rule;
        var oneLine = it.rule.replace(/\s+/g, ' ').trim();
        return '<div class="row" role="button" tabindex="0" title="Click to view the full rule" onclick="urView(' + it.id + ')" ' +
          'style="gap:9px;align-items:center;padding:8px 11px;border:1px solid var(--border);border-radius:11px;margin-bottom:6px;background:var(--surface-2);cursor:pointer">' +
          '<span style="flex:0 0 auto;font-size:13px">🧠</span>' +
          '<div class="grow small" style="min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + escHtml(oneLine) + '</div>' +
          '<span class="muted" style="flex:0 0 auto;font-size:11px">👁️</span></div>';
      }).join('');
    } catch (e) { /* silent */ }
  }
  var urOpenId = 0;
  window.urView = function (id) {
    urOpenId = id;
    document.getElementById('urModalText').textContent = urRules[id] || '';
    document.getElementById('urModal').classList.add('open');
  };
  document.getElementById('urModalDel').addEventListener('click', async function () {
    if (!urOpenId) return;
    if (!confirm('Delete this rule? The system will forget it.')) return;
    try {
      await TW.api('upwork_rule_delete', { id: urOpenId });
      document.getElementById('urModal').classList.remove('open');
      urOpenId = 0;
      loadRules();
      TW.toast('Rule removed');
    } catch (e) { TW.toast(e.message, 'err'); }
  });
  document.getElementById('urFeed').addEventListener('click', async function () {
    var t = document.getElementById('urInput').value.trim();
    if (t.length < 5) { TW.toast('Please add a bit more detail', 'info'); return; }
    this.disabled = true;
    try {
      await TW.api('upwork_rule_add', { rule: t });
      document.getElementById('urInput').value = '';
      loadRules();
      TW.toast('Learned 🧠 — every next proposal will follow this ✓');
    } catch (e) { TW.toast(e.message, 'err'); }
    finally { this.disabled = false; }
  });

  // TW (app.js) footer mein load hota hai — full page load par uske ready hone ka intezaar karo.
  function bootQueue() {
    loadQueue();
    loadRules();
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
