<?php
/** Messages — direct & group chat between users. */
$ACTIVE = 'messages';
$PAGE_TITLE = 'Messages';
$PAGE_SUB = 'Chat with your team';

$me = current_user_id();
$convs = chat_conversations($me);

$activeId = (int)($_GET['c'] ?? 0);
if (!$activeId && $convs) $activeId = (int)$convs[0]['id'];

$active = null; $activeMsgs = []; $activeDisplay = null; $activeMembers = [];
if ($activeId && chat_is_member($activeId, $me)) {
    $stmt = db()->prepare('SELECT * FROM conversations WHERE id = ?');
    $stmt->execute([$activeId]);
    $active = $stmt->fetch() ?: null;
    if ($active) {
        chat_mark_read($activeId, $me);
        $activeMsgs = chat_messages($activeId, $me, 0);
        $activeDisplay = chat_display($active, $me);
        $activeMembers = chat_members($activeId);
    }
}
$allUsers = array_values(array_filter(get_users(), fn($u) => (int)$u['id'] !== $me));

require __DIR__ . '/../partials/header.php';
?>

<div class="chat-wrap card animate" style="padding:0;overflow:hidden">
  <!-- Conversation list -->
  <aside class="chat-list">
    <div class="chat-list-head">
      <strong>Chats</strong>
      <button class="btn btn-primary btn-sm" data-open-modal="newChatModal">＋ New</button>
    </div>
    <div class="chat-list-body">
      <?php if (!$convs): ?>
        <div class="empty" style="padding:26px 14px"><span class="emoji">💬</span><h4>No chats yet</h4><p class="small">Start a conversation with a teammate.</p></div>
      <?php else: foreach ($convs as $cv): ?>
        <a href="<?= page_url('messages', ['c' => $cv['id']]) ?>" class="chat-item <?= (int)$cv['id'] === $activeId ? 'active' : '' ?>">
          <div class="chat-avatar" style="background:<?= esc($cv['color']) ?>"><?= esc($cv['initial']) ?></div>
          <div class="grow" style="min-width:0">
            <div class="row between"><strong class="truncate" style="font-size:13.5px"><?= esc($cv['title']) ?></strong>
              <?php if ($cv['unread'] > 0): ?><span class="chat-unread"><?= $cv['unread'] ?></span><?php endif; ?>
            </div>
            <div class="muted small truncate"><?= $cv['last_body'] ? esc(mb_strimwidth($cv['last_body'], 0, 38, '…')) : '<em>No messages</em>' ?></div>
          </div>
        </a>
      <?php endforeach; endif; ?>
    </div>
  </aside>

  <!-- Active conversation -->
  <section class="chat-main">
    <?php if (!$active): ?>
      <div class="empty" style="margin:auto"><span class="emoji">💬</span><h4>Select a chat</h4><p>Pick a conversation or start a new one.</p></div>
    <?php else: ?>
      <header class="chat-head">
        <a href="<?= page_url('messages') ?>" class="icon-btn chat-back" title="Back">←</a>
        <div class="chat-avatar" style="background:<?= esc($activeDisplay['color']) ?>"><?= esc($activeDisplay['initial']) ?></div>
        <div class="grow" style="min-width:0">
          <strong class="truncate"><?= esc($activeDisplay['title']) ?></strong>
          <div class="muted small truncate">
            <?= $active['type'] === 'group'
                ? esc(implode(', ', array_map(fn($m) => $m['name'] ?: $m['username'], $activeMembers)))
                : esc($activeDisplay['subtitle']) ?>
          </div>
        </div>
        <button class="icon-btn" onclick="deleteChat()" title="Delete chat" style="flex:0 0 auto">🗑</button>
      </header>
      <div class="chat-messages" id="chatMessages"></div>
      <form class="chat-input" id="chatForm">
        <input type="file" id="chatFile" accept="image/*,audio/*,application/pdf" style="display:none">
        <button type="button" class="icon-btn" id="attachBtn" title="Attach image / file">📎</button>
        <button type="button" class="icon-btn" id="voiceBtn" title="Record voice message">🎤</button>
        <input class="input" id="chatBody" placeholder="Type a message… (paste an image too)" autocomplete="off" maxlength="4000" style="flex:1">
        <button class="btn btn-primary" type="submit" style="flex:0 0 auto">Send</button>
      </form>
    <?php endif; ?>
  </section>
</div>

<!-- New chat modal -->
<div class="modal-back" id="newChatModal">
  <div class="modal">
    <div class="modal-head"><h3>💬 New chat</h3><button class="icon-btn" data-close-modal style="margin-left:auto">✕</button></div>
    <div class="modal-body">
      <div class="tabs mb-4" id="chatTabs">
        <button type="button" class="on" data-tab="direct">👤 Direct</button>
        <button type="button" data-tab="group">👥 Group</button>
      </div>
      <?php if (!$allUsers): ?>
        <div class="empty" style="padding:20px"><span class="emoji">🧑‍🤝‍🧑</span><h4>No other users</h4><p class="small">Add users in the admin panel to chat with them.</p></div>
      <?php else: ?>
        <!-- Direct -->
        <div id="tabDirect">
          <p class="small muted mb-4">Choose someone to message:</p>
          <div style="display:flex;flex-direction:column;gap:6px;max-height:320px;overflow:auto">
            <?php foreach ($allUsers as $u): ?>
              <button type="button" class="chat-user-row" onclick="startDirect(<?= (int)$u['id'] ?>)">
                <div class="chat-avatar" style="background:<?= esc($u['color']) ?>;width:34px;height:34px;font-size:14px"><?= esc(mb_strtoupper(mb_substr($u['name'] ?: $u['username'], 0, 1))) ?></div>
                <div style="text-align:left"><div class="strong small"><?= esc($u['name'] ?: $u['username']) ?></div><div class="muted" style="font-size:11px">@<?= esc($u['username']) ?></div></div>
              </button>
            <?php endforeach; ?>
          </div>
        </div>
        <!-- Group -->
        <div id="tabGroup" class="hidden">
          <div class="field"><label class="fld">Group name</label><input class="input" id="groupName" placeholder="e.g. Casebazar Team"></div>
          <label class="fld">Members</label>
          <div style="display:flex;flex-direction:column;gap:4px;max-height:260px;overflow:auto">
            <?php foreach ($allUsers as $u): ?>
              <label class="chat-user-row" style="cursor:pointer">
                <input type="checkbox" class="group-member" value="<?= (int)$u['id'] ?>" style="width:18px;height:18px;accent-color:var(--primary)">
                <div class="chat-avatar" style="background:<?= esc($u['color']) ?>;width:30px;height:30px;font-size:13px"><?= esc(mb_strtoupper(mb_substr($u['name'] ?: $u['username'], 0, 1))) ?></div>
                <span class="small strong"><?= esc($u['name'] ?: $u['username']) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
          <button class="btn btn-primary mt-4" style="width:100%" onclick="createGroup()">Create group</button>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<style>
.chat-wrap { display:flex; height:calc(100vh - 168px); min-height:420px; }
.chat-list { flex:0 0 300px; border-right:1px solid var(--border); display:flex; flex-direction:column; min-width:0; }
.chat-list-head { display:flex; align-items:center; justify-content:space-between; padding:14px 16px; border-bottom:1px solid var(--border); }
.chat-list-body { overflow-y:auto; flex:1; padding:8px; }
.chat-item { display:flex; align-items:center; gap:11px; padding:10px 11px; border-radius:12px; transition:background var(--dur) var(--ease); }
.chat-item:hover { background:var(--surface-2); }
.chat-item.active { background:var(--primary-soft); }
.chat-avatar { flex:0 0 auto; width:40px; height:40px; border-radius:12px; display:grid; place-items:center; color:#fff; font-weight:800; font-size:15px; }
.chat-unread { background:var(--coral); color:#fff; font-size:11px; font-weight:800; min-width:19px; height:19px; padding:0 5px; border-radius:99px; display:grid; place-items:center; }
.chat-main { flex:1; display:flex; flex-direction:column; min-width:0; }
.chat-head { display:flex; align-items:center; gap:12px; padding:13px 18px; border-bottom:1px solid var(--border); }
.chat-back { display:none; }
.chat-messages { flex:1; overflow-y:auto; padding:20px; display:flex; flex-direction:column; gap:10px; background:var(--bg); }
.msg { max-width:74%; display:flex; flex-direction:column; gap:2px; }
.msg.me { align-self:flex-end; align-items:flex-end; }
.msg .bubble { padding:9px 13px; border-radius:15px; font-size:14px; line-height:1.45; white-space:pre-wrap; word-break:break-word; }
.msg.them .bubble { background:var(--surface); border:1px solid var(--border); border-bottom-left-radius:5px; }
.msg.me .bubble { background:var(--primary); color:#fff; border-bottom-right-radius:5px; }
.msg .meta { font-size:10.5px; color:var(--text-3); padding:0 4px; }
.msg .sender { font-size:11px; font-weight:700; padding:0 4px; }
.chat-input { display:flex; gap:8px; align-items:center; padding:12px 14px; border-top:1px solid var(--border); }
.bubble.media { padding:4px; }
.chat-img { max-width:240px; max-height:270px; border-radius:11px; display:block; cursor:pointer; }
.msg audio { max-width:238px; height:40px; display:block; }
.msg .cap { margin-top:5px; padding:0 5px 3px; }
.chat-file { color:inherit; font-weight:600; text-decoration:underline; }
.msg-del { background:none; border:0; color:var(--text-3); cursor:pointer; font:inherit; font-size:10.5px; text-decoration:underline; padding:0; opacity:0; transition:opacity var(--dur); }
.msg:hover .msg-del { opacity:1; }
#voiceBtn.rec { background:var(--coral); color:#fff; border-color:var(--coral); animation:pulse-dot 1.1s infinite; }
.chat-user-row { display:flex; align-items:center; gap:11px; width:100%; padding:8px 10px; border-radius:11px; border:1px solid var(--border); background:var(--surface); cursor:pointer; transition:all var(--dur) var(--ease); }
.chat-user-row:hover { border-color:var(--primary); background:var(--surface-2); }
@media (max-width:800px) {
  .chat-wrap { height:calc(100vh - 150px); }
  .chat-list { flex-basis:100%; }
  .chat-main { display:none; }
  .chat-wrap.viewing .chat-list { display:none; }
  .chat-wrap.viewing .chat-main { display:flex; }
  .chat-back { display:grid; }
}
</style>

<script>
(function () {
  <?php if ($active): ?>
  document.querySelector('.chat-wrap').classList.add('viewing');
  const ME = <?= (int)$me ?>, CONV = <?= $activeId ?>, isGroup = <?= $active['type'] === 'group' ? 'true' : 'false' ?>;
  const box = document.getElementById('chatMessages');
  let lastId = 0;
  function esc(s) { return (s || '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c])); }
  function fmtTime(s) { try { const d = new Date((s || '').replace(' ', 'T')); return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }); } catch (e) { return ''; } }
  const BASE = (window.TASKWAY && window.TASKWAY.base) || '';
  function render(m) {
    const mine = parseInt(m.user_id) === ME;
    const el = document.createElement('div');
    el.className = 'msg ' + (mine ? 'me' : 'them');
    let html = '';
    if (!mine && isGroup) html += '<div class="sender" style="color:' + esc(m.color || '#6C5CE7') + '">' + esc(m.name || m.username) + '</div>';
    let bubbleClass = 'bubble', content = '';
    if (m.attachment) {
      const url = BASE + '/' + m.attachment;
      if (m.attachment_type === 'image') { content += '<a href="' + url + '" target="_blank" rel="noopener"><img class="chat-img" src="' + url + '" alt="image"></a>'; bubbleClass += ' media'; }
      else if (m.attachment_type === 'audio') { content += '<audio controls preload="none" src="' + url + '"></audio>'; bubbleClass += ' media'; }
      else { content += '<a class="chat-file" href="' + url + '" target="_blank" rel="noopener" download>📎 ' + esc(m.attachment_name || 'File') + '</a>'; }
    }
    if (m.body) content += (m.attachment ? '<div class="cap">' + esc(m.body) + '</div>' : esc(m.body));
    html += '<div class="' + bubbleClass + '">' + content + '</div>';
    html += '<div class="meta">' + fmtTime(m.created_at) + (mine ? ' · <button class="msg-del" onclick="deleteMsg(this,' + parseInt(m.id) + ')">delete</button>' : '') + '</div>';
    el.innerHTML = html;
    el.dataset.id = m.id;
    box.appendChild(el);
    lastId = Math.max(lastId, parseInt(m.id));
  }
  (<?= json_encode($activeMsgs, JSON_UNESCAPED_UNICODE) ?>).forEach((m) => render(m));
  box.scrollTop = box.scrollHeight;

  const form = document.getElementById('chatForm'), input = document.getElementById('chatBody');
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const body = input.value.trim(); if (!body) return;
    input.value = '';
    try { await TW.api('chat_send', { conversation_id: CONV, body: body }); await poll(); }
    catch (err) { TW.toast(err.message, 'err'); input.value = body; }
  });
  // ---- attachments: file / paste / voice ----
  const fileInput = document.getElementById('chatFile');
  function sendFile(file) {
    if (!file) return;
    if (file.size > 6 * 1024 * 1024) { TW.toast('File too large (max 6MB)', 'err'); return; }
    const reader = new FileReader();
    reader.onload = async () => {
      try {
        await TW.api('chat_send', { conversation_id: CONV, body: input.value.trim(), attachment: reader.result, attachment_name: file.name || '' });
        input.value = ''; await poll(); box.scrollTop = box.scrollHeight;
      } catch (err) { TW.toast(err.message, 'err'); }
    };
    reader.onerror = () => TW.toast('Could not read file', 'err');
    reader.readAsDataURL(file);
  }
  document.getElementById('attachBtn').addEventListener('click', () => fileInput.click());
  fileInput.addEventListener('change', () => { if (fileInput.files[0]) sendFile(fileInput.files[0]); fileInput.value = ''; });
  input.addEventListener('paste', (e) => {
    const items = (e.clipboardData || {}).items || [];
    for (const it of items) { if (it.type && it.type.indexOf('image/') === 0) { const f = it.getAsFile(); if (f) { e.preventDefault(); sendFile(f); } } }
  });
  let rec = null, chunks = [], recording = false;
  const voiceBtn = document.getElementById('voiceBtn');
  voiceBtn.addEventListener('click', async () => {
    if (recording && rec) { rec.stop(); return; }
    if (!navigator.mediaDevices || !window.MediaRecorder) { TW.toast('Voice recording not supported here', 'err'); return; }
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      rec = new MediaRecorder(stream); chunks = [];
      rec.ondataavailable = (e) => { if (e.data && e.data.size) chunks.push(e.data); };
      rec.onstop = () => {
        stream.getTracks().forEach((t) => t.stop());
        recording = false; voiceBtn.classList.remove('rec'); voiceBtn.textContent = '🎤';
        const blob = new Blob(chunks, { type: (chunks[0] && chunks[0].type) || 'audio/webm' });
        if (blob.size > 300) sendFile(new File([blob], 'voice.webm', { type: blob.type }));
      };
      rec.start(); recording = true; voiceBtn.classList.add('rec'); voiceBtn.textContent = '⏹';
      TW.toast('Recording… tap ⏹ to send', 'info');
    } catch (err) { TW.toast('Microphone blocked', 'err'); }
  });

  async function poll() {
    try {
      const r = await TW.api('chat_poll', { conversation_id: CONV, after: lastId });
      if (r.messages && r.messages.length) { const atBottom = box.scrollHeight - box.scrollTop - box.clientHeight < 80; r.messages.forEach((m) => render(m)); if (atBottom) box.scrollTop = box.scrollHeight; }
    } catch (e) {}
  }
  TW.setPageInterval(poll, 4000);
  input.focus();

  window.deleteChat = async function () {
    if (!confirm('Delete this chat? It will be removed from your list.')) return;
    try { await TW.api('chat_delete_conversation', { conversation_id: CONV }); TW.navigate(<?= json_encode(page_url('messages')) ?>); }
    catch (err) { TW.toast(err.message, 'err'); }
  };
  window.deleteMsg = async function (btn, id) {
    if (!confirm('Delete this message?')) return;
    try { await TW.api('chat_delete_message', { id: id }); const el = btn.closest('.msg'); if (el) el.remove(); }
    catch (err) { TW.toast(err.message, 'err'); }
  };
  <?php endif; ?>

  // New-chat modal tabs
  const tabs = document.getElementById('chatTabs');
  if (tabs) tabs.addEventListener('click', (e) => {
    const b = e.target.closest('[data-tab]'); if (!b) return;
    tabs.querySelectorAll('button').forEach((x) => x.classList.toggle('on', x === b));
    document.getElementById('tabDirect').classList.toggle('hidden', b.dataset.tab !== 'direct');
    document.getElementById('tabGroup').classList.toggle('hidden', b.dataset.tab !== 'group');
  });
  window.startDirect = async function (uid) {
    try { const r = await TW.api('chat_start_direct', { user_id: uid }); TW.navigate('<?= page_url('messages') ?>&c=' + r.conversation_id); }
    catch (err) { TW.toast(err.message, 'err'); }
  };
  window.createGroup = async function () {
    const members = [...document.querySelectorAll('.group-member:checked')].map((c) => parseInt(c.value));
    if (!members.length) { TW.toast('Pick at least one member', 'info'); return; }
    try { const r = await TW.api('chat_create_group', { name: document.getElementById('groupName').value, members: members }); TW.navigate('<?= page_url('messages') ?>&c=' + r.conversation_id); }
    catch (err) { TW.toast(err.message, 'err'); }
  };
})();
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>
