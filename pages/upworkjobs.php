<?php
/** Upwork Jobs — track applied jobs: summary, sent proposal, and the client conversation. */
if ((int)(current_user()['uw_enabled'] ?? 0) !== 1) redirect(page_url('dashboard'));
$ACTIVE = 'upworkjobs';
$PAGE_TITLE = 'Upwork Jobs';
$PAGE_SUB = 'Every job you applied to — summary, proposal and client conversation in one place';

$TOPBAR_ACTIONS = '<button class="btn btn-primary" data-open-modal="ujAddModal">＋ Add job</button>';

require __DIR__ . '/../partials/header.php';
?>

<div class="grid cols-3" style="align-items:start">
  <!-- Jobs list -->
  <div class="animate" style="min-width:0">
    <div class="card card-pad">
      <div class="card-head"><h3>🗂️ My jobs</h3><span class="badge" id="ujCount"></span></div>
      <div id="ujList" class="small muted">Loading…</div>
    </div>
  </div>

  <!-- Job detail -->
  <div class="span-2 animate d1" style="min-width:0">
    <div class="card card-pad" id="ujEmpty">
      <div class="empty"><span class="emoji">🗂️</span><h4>No job selected</h4>
        <p>Add a job with <strong>＋ Add job</strong>, or pick one from the list — its summary, proposal and client conversation will show here.</p></div>
    </div>

    <div id="ujDetail" class="hidden" style="display:flex;flex-direction:column;gap:20px">
      <div class="card card-pad">
        <div class="card-head">
          <h3 id="ujTitle" style="min-width:0;overflow:hidden;text-overflow:ellipsis"></h3>
          <div class="card-action row" style="gap:8px">
            <select class="input" id="ujStatus" style="width:auto;padding:8px 12px;font-weight:600" onchange="ujSetStatus(this.value)">
              <option value="applied">📤 Applied</option>
              <option value="replied">💬 Client replied</option>
              <option value="interview">🎯 Interview</option>
              <option value="hired">✅ Hired</option>
              <option value="closed">🔒 Closed</option>
            </select>
            <button class="icon-btn" title="Delete job" style="color:var(--coral)" onclick="ujDelete()">🗑️</button>
          </div>
        </div>
        <div class="field"><label class="fld">📝 Job summary</label>
          <textarea class="textarea" id="ujSummary" style="min-height:90px" placeholder="Short summary of what the client wants…"></textarea></div>
        <div class="field"><label class="fld">✉️ Proposal you sent
            <button class="btn btn-soft btn-sm" style="margin-left:8px" onclick="upCopyEl('ujProposal')">📋 Copy</button></label>
          <textarea class="textarea" id="ujProposal" style="min-height:130px" placeholder="Paste the proposal you submitted…"></textarea></div>
        <button class="btn btn-primary" onclick="ujSave()">💾 Save changes</button>
      </div>

      <!-- Conversation -->
      <div class="card card-pad">
        <div class="card-head"><h3>💬 Client conversation</h3><span class="badge" id="ujMsgCount"></span></div>
        <div id="ujMsgs" style="display:flex;flex-direction:column;gap:10px;max-height:440px;overflow-y:auto;padding:4px 2px"></div>

        <!-- AI-suggested reply -->
        <div id="ujSuggest" class="hidden" style="margin-top:12px;border:1.5px solid var(--primary);border-radius:14px;background:var(--primary-soft);padding:14px 16px">
          <div class="row" style="gap:8px;align-items:center;margin-bottom:8px">
            <strong style="font-size:13px">✨ Suggested reply</strong>
            <span class="small muted" id="ujSuggestState"></span>
            <div style="margin-left:auto" class="row" style="gap:6px">
              <button class="btn btn-soft btn-sm" onclick="upCopyEl('ujSuggestText')">📋 Copy</button>
              <button class="btn btn-soft btn-sm" onclick="ujGenReply()">🔄 Regenerate</button>
              <button class="btn btn-primary btn-sm" onclick="ujUseReply()">🙋 Use as my reply</button>
            </div>
          </div>
          <div id="ujSuggestText" style="white-space:pre-wrap;font-size:13.5px;line-height:1.65"></div>
        </div>
        <div class="divider"></div>
        <div class="row" style="gap:8px;align-items:flex-end;flex-wrap:wrap">
          <div class="seg" id="ujSenderSeg" style="flex:0 0 auto">
            <button type="button" class="on" data-s="client" onclick="ujSender('client', this)">👤 Client</button>
            <button type="button" data-s="me" onclick="ujSender('me', this)">🙋 Me</button>
          </div>
          <textarea class="textarea" id="ujMsgInput" style="min-height:52px;flex:1;min-width:200px" placeholder="Paste the client's message, or your reply…"></textarea>
          <button class="btn btn-primary" id="ujMsgSend" onclick="ujAddMsg()">Add</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Add job modal -->
<div class="modal-back" id="ujAddModal">
  <div class="modal" style="max-width:600px">
    <div class="modal-head"><h3>＋ Add Upwork job</h3>
      <button class="icon-btn" data-close-modal style="margin-left:auto">✕</button></div>
    <div class="modal-body">
      <div class="field"><label class="fld">Job title</label>
        <input class="input" id="ujaTitle" placeholder="e.g. React dashboard for analytics SaaS"></div>
      <div class="field"><label class="fld">📝 Job summary <span class="muted">(optional)</span></label>
        <textarea class="textarea" id="ujaSummary" style="min-height:80px" placeholder="What the client wants, budget, key requirements…"></textarea></div>
      <div class="field"><label class="fld">✉️ Proposal you sent <span class="muted">(optional)</span></label>
        <textarea class="textarea" id="ujaProposal" style="min-height:110px" placeholder="Paste the proposal you submitted…"></textarea></div>
    </div>
    <div class="modal-foot">
      <button class="btn btn-ghost" data-close-modal>Cancel</button>
      <button class="btn btn-primary" id="ujaAdd">＋ Add job</button>
    </div>
  </div>
</div>

<script>
(function () {
  if (window.__ujWired) return; window.__ujWired = true;
  var CUR = 0, curSender = 'client';
  var STATUS_META = { applied: ['📤 Applied', 'var(--sky)'], replied: ['💬 Replied', 'var(--amber)'],
    interview: ['🎯 Interview', 'var(--violet-600)'], hired: ['✅ Hired', 'var(--mint)'], closed: ['🔒 Closed', 'var(--mut, #888)'] };
  function esc(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }
  function fmtDT(s) { try { var d = new Date((s || '').replace(' ', 'T')); return d.toLocaleDateString([], { day: 'numeric', month: 'short' }) + ' · ' + d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }); } catch (e) { return ''; } }

  window.upCopyEl = function (id) {
    var t = document.getElementById(id).value || document.getElementById(id).textContent;
    (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).then(
      function () { TW.toast('Copied 📋'); }, function () { TW.toast('Copy failed', 'err'); });
  };

  async function loadList(openId) {
    try {
      var r = await TW.api('uw_job_list', {});
      document.getElementById('ujCount').textContent = r.items.length || '';
      var box = document.getElementById('ujList');
      if (!r.items.length) { box.innerHTML = '<span class="muted">No jobs yet — click ＋ Add job.</span>'; return; }
      box.innerHTML = r.items.map(function (it) {
        var m = STATUS_META[it.status] || ['', 'var(--border-2)'];
        return '<div role="button" tabindex="0" onclick="ujOpen(' + it.id + ')" ' +
          'style="padding:11px 13px;border:1.5px solid ' + (it.id === CUR ? m[1] : 'var(--border)') + ';border-radius:12px;margin-bottom:8px;cursor:pointer;background:var(--surface-2)">' +
          '<div class="strong" style="font-size:13.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(it.title) + '</div>' +
          '<div class="row" style="gap:8px;margin-top:5px;align-items:center">' +
            '<span class="small" style="font-weight:700;color:' + m[1] + '">' + m[0] + '</span>' +
            '<span class="small muted">💬 ' + it.msg_count + '</span>' +
            '<span class="small muted" style="margin-left:auto">' + fmtDT(it.updated_at) + '</span>' +
          '</div></div>';
      }).join('');
      if (openId) ujOpen(openId);
    } catch (e) { /* silent */ }
  }

  window.ujOpen = async function (id) {
    try {
      var r = await TW.api('uw_job_get', { id: id });
      CUR = id;
      document.getElementById('ujEmpty').classList.add('hidden');
      document.getElementById('ujDetail').classList.remove('hidden');
      document.getElementById('ujTitle').textContent = r.job.title;
      document.getElementById('ujStatus').value = r.job.status;
      document.getElementById('ujSummary').value = r.job.summary || '';
      document.getElementById('ujProposal').value = r.job.proposal || '';
      document.getElementById('ujSuggest').classList.add('hidden');
      renderMsgs(r.job.messages || []);
      loadList();
    } catch (e) { TW.toast(e.message, 'err'); }
  };

  function renderMsgs(msgs) {
    var box = document.getElementById('ujMsgs');
    document.getElementById('ujMsgCount').textContent = msgs.length ? msgs.length + ' messages' : '';
    if (!msgs.length) { box.innerHTML = '<span class="small muted">No conversation yet — log the client\'s reply below.</span>'; return; }
    box.innerHTML = msgs.map(function (m) {
      var me = m.sender === 'me';
      return '<div style="display:flex;flex-direction:column;align-items:' + (me ? 'flex-end' : 'flex-start') + '">' +
        '<div style="max-width:82%;padding:10px 14px;border-radius:14px;font-size:13.5px;line-height:1.6;white-space:pre-wrap;' +
          (me ? 'background:var(--primary-soft);border:1px solid var(--primary);border-bottom-right-radius:5px'
              : 'background:var(--surface-2);border:1px solid var(--border);border-bottom-left-radius:5px') + '">' + esc(m.body) + '</div>' +
        '<div class="small muted" style="margin-top:3px;font-size:10.5px">' + (me ? '🙋 Me' : '👤 Client') + ' · ' + fmtDT(m.created_at) +
          ' · <a href="#" style="color:var(--coral)" onclick="event.preventDefault();ujDelMsg(' + m.id + ')">delete</a></div></div>';
    }).join('');
    box.scrollTop = box.scrollHeight;
  }

  window.ujSender = function (s, btn) {
    curSender = s;
    document.querySelectorAll('#ujSenderSeg button').forEach(function (b) { b.classList.toggle('on', b === btn); });
  };

  window.ujAddMsg = async function () {
    var t = document.getElementById('ujMsgInput').value.trim();
    if (!t || !CUR) return;
    var wasClient = curSender === 'client';
    try {
      await TW.api('uw_job_msg_add', { job_id: CUR, sender: curSender, body: t });
      document.getElementById('ujMsgInput').value = '';
      await ujOpen(CUR);
      if (wasClient) ujGenReply();   // client message logged -> auto-draft a human reply
    } catch (e) { TW.toast(e.message, 'err'); }
  };

  // ---- AI-suggested reply (understands client psychology, answers like a real human) ----
  async function callReplyAI(p) {
    var url, opts;
    if (p.provider === 'groq') {
      url = 'https://api.groq.com/openai/v1/chat/completions';
      var gq = { model: p.model, max_tokens: 900, temperature: 0.5,
        messages: [{ role: 'system', content: p.system }, { role: 'user', content: p.user }] };
      if (/gpt-oss/i.test(p.model)) gq.reasoning_effort = 'low';
      opts = { method: 'POST', headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + p.key }, body: JSON.stringify(gq) };
    } else if (p.provider === 'gemini') {
      url = 'https://generativelanguage.googleapis.com/v1beta/models/' + encodeURIComponent(p.model) + ':generateContent?key=' + encodeURIComponent(p.key);
      opts = { method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ system_instruction: { parts: [{ text: p.system }] }, contents: [{ parts: [{ text: p.user }] }], generationConfig: { maxOutputTokens: 900 } }) };
    } else {
      url = 'https://api.anthropic.com/v1/messages';
      opts = { method: 'POST', headers: { 'Content-Type': 'application/json', 'x-api-key': p.key,
          'anthropic-version': '2023-06-01', 'anthropic-dangerous-direct-browser-access': 'true' },
        body: JSON.stringify({ model: p.model, max_tokens: 900, system: p.system, messages: [{ role: 'user', content: p.user }] }) };
    }
    var res = await fetch(url, opts);
    if (!res.ok && p.provider === 'groq' && /gpt-oss/i.test(p.model) && (res.status === 413 || res.status === 429)) {
      var retry = JSON.parse(opts.body); retry.model = 'llama-3.3-70b-versatile'; delete retry.reasoning_effort;
      res = await fetch(url, { method: 'POST', headers: opts.headers, body: JSON.stringify(retry) });
    }
    if (!res.ok) throw new Error(p.provider + ' HTTP ' + res.status);
    var j = await res.json();
    var text = p.provider === 'groq' ? (j.choices && j.choices[0].message.content)
      : p.provider === 'gemini' ? (j.candidates && j.candidates[0].content.parts[0].text)
      : (j.content && j.content[0].text);
    // Humanize: strip any markdown/AI formatting the model sneaks in.
    text = String(text || '').trim()
      .replace(/^["'`]+|["'`]+$/g, '')
      .replace(/\*\*?|__|`+|#+\s/g, '')            // asterisks, bold, backticks, headings
      .replace(/^\s*[-•▪]\s+/gm, '')               // bullet markers at line starts
      .replace(/\s*[—–]\s*/g, ', ')                // em/en dashes -> natural comma
      .replace(/‑/g, '-')
      .replace(/\n{3,}/g, '\n\n');
    return text;
  }

  window.ujGenReply = async function () {
    if (!CUR) return;
    var box = document.getElementById('ujSuggest');
    box.classList.remove('hidden');
    document.getElementById('ujSuggestState').textContent = 'thinking…';
    document.getElementById('ujSuggestText').textContent = '';
    try {
      var p = await TW.api('uw_job_reply', { id: CUR });
      var reply = p.provider === 'local' ? p.reply : await callReplyAI(p);
      if (!reply) throw new Error('Empty reply');
      document.getElementById('ujSuggestText').textContent = reply;
      document.getElementById('ujSuggestState').textContent = '';
    } catch (e) {
      document.getElementById('ujSuggestState').textContent = 'could not generate (' + e.message + ') — try 🔄';
    }
  };

  window.ujUseReply = function () {
    var t = document.getElementById('ujSuggestText').textContent.trim();
    if (!t) return;
    document.getElementById('ujMsgInput').value = t;
    var meBtn = document.querySelector('#ujSenderSeg button[data-s="me"]');
    if (meBtn) ujSender('me', meBtn);
    document.getElementById('ujMsgInput').scrollIntoView({ behavior: 'smooth', block: 'center' });
    TW.toast('Reply filled in — edit if needed, then Add 🙋');
  };

  window.ujDelMsg = async function (id) {
    if (!confirm('Delete this message?')) return;
    try { await TW.api('uw_job_msg_delete', { id: id }); ujOpen(CUR); } catch (e) { TW.toast(e.message, 'err'); }
  };

  window.ujSetStatus = async function (s) {
    if (!CUR) return;
    try { await TW.api('uw_job_update', { id: CUR, status: s }); TW.toast('Status updated ✓'); loadList(); }
    catch (e) { TW.toast(e.message, 'err'); }
  };

  window.ujSave = async function () {
    if (!CUR) return;
    try {
      await TW.api('uw_job_update', { id: CUR, summary: document.getElementById('ujSummary').value, proposal: document.getElementById('ujProposal').value });
      TW.toast('Saved ✓'); loadList();
    } catch (e) { TW.toast(e.message, 'err'); }
  };

  window.ujDelete = async function () {
    if (!CUR) return;
    if (!confirm('Delete this job and its whole conversation? This cannot be undone.')) return;
    try {
      await TW.api('uw_job_delete', { id: CUR });
      CUR = 0;
      document.getElementById('ujDetail').classList.add('hidden');
      document.getElementById('ujEmpty').classList.remove('hidden');
      TW.toast('Job deleted');
      loadList();
    } catch (e) { TW.toast(e.message, 'err'); }
  };

  document.getElementById('ujaAdd').addEventListener('click', async function () {
    var title = document.getElementById('ujaTitle').value.trim();
    if (!title) { TW.toast('Job title required', 'info'); return; }
    this.disabled = true;
    try {
      var r = await TW.api('uw_job_add', {
        title: title,
        summary: document.getElementById('ujaSummary').value.trim(),
        proposal: document.getElementById('ujaProposal').value.trim()
      });
      document.getElementById('ujaTitle').value = '';
      document.getElementById('ujaSummary').value = '';
      document.getElementById('ujaProposal').value = '';
      document.getElementById('ujAddModal').classList.remove('open');
      TW.toast('Job added ✓');
      loadList(r.id);
    } catch (e) { TW.toast(e.message, 'err'); }
    finally { this.disabled = false; }
  });

  function boot() { loadList(); }
  if (window.TW) boot();
  else document.addEventListener('DOMContentLoaded', boot);
})();
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>
