/* Taskway — interaction layer: API helper, theme, toasts, task actions, timers, modals. */
(function () {
  'use strict';
  const API = (window.TASKWAY && window.TASKWAY.api) || 'api.php';

  /* ---------- API ---------- */
  const TW = {
    async api(action, data) {
      const res = await fetch(API + '?action=' + action, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data || {})
      });
      let json;
      try { json = await res.json(); } catch (e) { json = { ok: false, error: 'Bad server response' }; }
      if (!json.ok) throw new Error(json.error || 'Request failed');
      return json;
    },
    toast(msg, type) {
      const wrap = document.getElementById('toastWrap');
      if (!wrap) return alert(msg);
      const t = document.createElement('div');
      t.className = 'toast ' + (type || '');
      t.innerHTML = '<span>' + (type === 'err' ? '⚠️' : type === 'info' ? 'ℹ️' : '✅') + '</span><span>' + msg + '</span>';
      wrap.appendChild(t);
      setTimeout(() => { t.style.opacity = '0'; t.style.transform = 'translateX(20px)'; setTimeout(() => t.remove(), 300); }, 2800);
    }
  };
  window.TW = TW;

  /* ---------- PJAX: swap the content area without a full page reload ---------- */
  const pageIntervals = [];
  TW.setPageInterval = function (fn, ms) { const id = setInterval(fn, ms); pageIntervals.push(id); return id; };

  function runScripts(container) {
    container.querySelectorAll('script').forEach((old) => {
      const s = document.createElement('script');
      for (const a of old.attributes) s.setAttribute(a.name, a.value);
      s.textContent = old.textContent;
      old.replaceWith(s);
    });
  }
  function setActiveNav(url) {
    const m = url.match(/[?&]page=([a-z_]+)/);
    const page = m ? m[1] : 'dashboard';
    document.querySelectorAll('.nav-item').forEach((a) => {
      const p = (a.getAttribute('href') || '').match(/[?&]page=([a-z_]+)/);
      a.classList.toggle('active', !!p && p[1] === page);
    });
  }
  let pjaxBusy = false;
  async function pjaxLoad(url, push) {
    const main = document.querySelector('main.content');
    if (!main || pjaxBusy) { location.href = url; return; }
    pjaxBusy = true;
    document.body.classList.add('pjax-busy');
    try {
      const res = await fetch(url, { headers: { 'X-Requested-With': 'pjax' }, credentials: 'same-origin' });
      const finalUrl = res.url || url;
      if (!res.ok || /[?&]page=login/.test(finalUrl)) { location.href = url; return; }
      const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
      const nm = doc.querySelector('main.content');
      if (!nm) { location.href = url; return; }
      while (pageIntervals.length) clearInterval(pageIntervals.pop());
      main.innerHTML = nm.innerHTML;
      runScripts(main);
      const tbT = document.getElementById('tbTitle'), nT = doc.getElementById('tbTitle');
      if (tbT && nT) tbT.innerHTML = nT.innerHTML;
      const tbA = document.getElementById('tbActions'), nA = doc.getElementById('tbActions');
      if (tbA) tbA.innerHTML = nA ? nA.innerHTML : '';
      document.title = doc.title;
      setActiveNav(finalUrl);
      if (push !== false) history.pushState({ pjax: 1 }, '', finalUrl);
      window.scrollTo(0, 0);
      const sb = document.getElementById('sidebar'); if (sb) sb.classList.remove('open');
      document.querySelectorAll('.modal-back.open').forEach((m) => m.classList.remove('open'));
      document.dispatchEvent(new CustomEvent('tw:loaded'));
    } catch (e) { location.href = url; }
    finally { pjaxBusy = false; document.body.classList.remove('pjax-busy'); }
  }
  TW.navigate = (url) => pjaxLoad(url, true);
  TW.reload = () => pjaxLoad(location.href, false);

  document.addEventListener('click', (e) => {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    const a = e.target.closest('a[href]');
    if (!a || a.target === '_blank' || a.hasAttribute('download') || a.hasAttribute('data-open-modal') || a.hasAttribute('data-no-pjax')) return;
    let u; try { u = new URL(a.getAttribute('href'), location.href); } catch (err) { return; }
    if (u.origin !== location.origin || !/[?&]page=/.test(u.search) || /[?&]page=(logout|login)\b/.test(u.search)) return;
    e.preventDefault();
    pjaxLoad(u.href, true);
  });
  window.addEventListener('popstate', () => pjaxLoad(location.href, false));

  /* ---------- Theme ---------- */
  const root = document.documentElement;
  const saved = localStorage.getItem('tw-theme');
  if (saved) root.setAttribute('data-theme', saved);
  function currentTheme() {
    const t = root.getAttribute('data-theme');
    if (t) return t;
    return matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }
  function syncThemeBtn() {
    const btn = document.getElementById('themeToggle');
    if (btn) btn.textContent = currentTheme() === 'dark' ? '☀️' : '🌙';
  }
  syncThemeBtn();
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('#themeToggle');
    if (!btn) return;
    const next = currentTheme() === 'dark' ? 'light' : 'dark';
    root.setAttribute('data-theme', next);
    localStorage.setItem('tw-theme', next);
    syncThemeBtn();
    document.dispatchEvent(new CustomEvent('tw:theme', { detail: next }));
  });

  /* ---------- Task actions (event delegation) ---------- */
  document.addEventListener('click', async (e) => {
    // Complete / uncomplete via checkbox
    const check = e.target.closest('[data-check]');
    if (check) {
      const row = check.closest('[data-task]');
      const id = check.getAttribute('data-check');
      const done = check.classList.contains('checked');
      const status = done ? 'todo' : 'done';
      check.classList.toggle('checked');
      if (row) row.classList.toggle('is-done', !done);
      try {
        const r = await TW.api('set_status', { id, status });
        applyStats(r.stats);
        TW.toast(status === 'done' ? 'Nice — task completed 🎉' : 'Reopened');
        if (status === 'done' && row && row.dataset.removeOnDone) setTimeout(() => fadeRemove(row), 600);
      } catch (err) { TW.toast(err.message, 'err'); check.classList.toggle('checked'); }
    }

    // Segmented status
    const seg = e.target.closest('[data-set-status]');
    if (seg) {
      const id = seg.getAttribute('data-id');
      const status = seg.getAttribute('data-set-status');
      seg.parentElement.querySelectorAll('button').forEach((b) => b.classList.toggle('on', b === seg));
      try {
        const r = await TW.api('set_status', { id, status });
        applyStats(r.stats);
        const row = seg.closest('[data-task]');
        if (row) row.classList.toggle('is-done', status === 'done');
        TW.toast('Status updated');
      } catch (err) { TW.toast(err.message, 'err'); }
    }

    // Delete task
    const del = e.target.closest('[data-delete-task]');
    if (del) {
      if (!confirm('Delete this task?')) return;
      const id = del.getAttribute('data-delete-task');
      try { await TW.api('delete_task', { id }); fadeRemove(del.closest('[data-task]')); TW.toast('Task deleted'); }
      catch (err) { TW.toast(err.message, 'err'); }
    }

    // Timer toggle
    const timerBtn = e.target.closest('[data-timer]');
    if (timerBtn) {
      const id = timerBtn.getAttribute('data-timer');
      try {
        if (timerBtn.classList.contains('running')) {
          const r = await TW.api('timer_stop', {});
          TW.toast('Tracked ' + TWChart.fmtMin(r.minutes || 0));
          setTimeout(() => TW.reload(), 400);
        } else {
          await TW.api('timer_start', { task_id: id });
          TW.toast('Timer started ⏱️', 'info');
          setTimeout(() => TW.reload(), 300);
        }
      } catch (err) { TW.toast(err.message, 'err'); }
    }

    // Modal open. Close only via [data-close-modal] (X / Cancel) — never on outside click.
    const openM = e.target.closest('[data-open-modal]');
    if (openM) { const m = document.getElementById(openM.getAttribute('data-open-modal')); if (m) m.classList.add('open'); }
    const closeM = e.target.closest('[data-close-modal]');
    if (closeM) { const back = closeM.closest('.modal-back'); if (back) back.classList.remove('open'); }

    // Sidebar nav-group caret (Tasks submenu) — remembers state
    const caret = e.target.closest('[data-nav-caret]');
    if (caret) {
      e.preventDefault(); e.stopPropagation();
      const g = document.getElementById(caret.getAttribute('data-nav-caret'));
      if (g) { g.classList.toggle('open'); localStorage.setItem('tw-' + g.id, g.classList.contains('open') ? '1' : '0'); }
      return;
    }

    // Attendance check in / check out
    const att = e.target.closest('[data-attendance]');
    if (att) {
      const action = att.getAttribute('data-attendance');
      att.disabled = true;
      try {
        const r = await TW.api('attendance_' + action, {});
        TW.toast(action === 'checkin' ? 'Checked in ✅' : ('Checked out · ' + TWChart.fmtMin(r.minutes || 0)));
        setTimeout(() => location.reload(), 550);
      } catch (err) { TW.toast(err.message, 'err'); att.disabled = false; }
    }
  });

  // Esc closes the top-most open modal (deliberate, not an accidental outside-click).
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') document.querySelectorAll('.modal-back.open').forEach((m) => m.classList.remove('open'));
  });

  /* ---------- Show/hide password toggle (eye) ---------- */
  const EYE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
  const EYE_OFF = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
  function enhancePasswords(root) {
    (root || document).querySelectorAll('input[type=password]').forEach((inp) => {
      if (inp.dataset.eye) return;
      inp.dataset.eye = '1';
      const wrap = document.createElement('div');
      wrap.className = 'pw-wrap';
      inp.parentNode.insertBefore(wrap, inp);
      wrap.appendChild(inp);
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'pw-eye';
      btn.setAttribute('aria-label', 'Show password');
      btn.innerHTML = EYE;
      wrap.appendChild(btn);
      btn.addEventListener('click', () => {
        const show = inp.type === 'password';
        inp.type = show ? 'text' : 'password';
        btn.innerHTML = show ? EYE_OFF : EYE;
        btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
      });
    });
  }
  window.TWEnhancePasswords = enhancePasswords;
  document.addEventListener('DOMContentLoaded', () => enhancePasswords());

  /* ---------- Restore nav-group open/closed state ---------- */
  document.querySelectorAll('.nav-group[id]').forEach((g) => {
    const saved = localStorage.getItem('tw-' + g.id);
    if (saved === '1') g.classList.add('open');
    else if (saved === '0' && !g.querySelector('.nav-item.active')) g.classList.remove('open');
  });

  function fadeRemove(node) {
    if (!node) return;
    node.style.transition = 'all .35s ease';
    node.style.opacity = '0';
    node.style.transform = 'translateX(-12px)';
    node.style.maxHeight = node.offsetHeight + 'px';
    setTimeout(() => { node.style.maxHeight = '0'; node.style.margin = '0'; node.style.padding = '0'; }, 120);
    setTimeout(() => node.remove(), 420);
  }

  /* Update any element carrying data-stat="key" from a stats payload */
  function applyStats(stats) {
    if (!stats) return;
    document.querySelectorAll('[data-stat]').forEach((el) => {
      const key = el.getAttribute('data-stat');
      if (stats[key] == null) return;
      if (key.endsWith('_min')) el.textContent = TWChart.fmtMin(stats[key]);
      else el.textContent = stats[key];
    });
    const goal = document.querySelector('[data-goal-bar]');
    if (goal && stats.goal_pct != null) goal.style.width = stats.goal_pct + '%';
  }
  window.TWApplyStats = applyStats;

  /* ---------- Per-page widgets (re-wired after each PJAX load) ---------- */
  function initPage() {
    const qa = document.getElementById('quickAddForm');
    if (qa && !qa.__wired) {
      qa.__wired = true;
      qa.addEventListener('submit', async (e) => {
        e.preventDefault();
        const payload = Object.fromEntries(new FormData(qa).entries());
        if (!payload.title || !payload.title.trim()) return;
        try { await TW.api('create_task', payload); TW.toast('Task added'); TW.reload(); }
        catch (err) { TW.toast(err.message, 'err'); }
      });
    }
  }
  document.addEventListener('tw:loaded', initPage);
  if (document.readyState !== 'loading') initPage(); else document.addEventListener('DOMContentLoaded', initPage);

  /* ---------- Global 1s ticker: live-elapsed (attendance) + task timer ---------- */
  const pad2 = (n) => (n < 10 ? '0' : '') + n;
  setInterval(() => {
    document.querySelectorAll('.live-elapsed[data-elapsed]').forEach((el) => {
      if (el._start == null) el._start = Date.now() - (parseInt(el.dataset.elapsed, 10) || 0) * 1000;
      let s = Math.max(0, Math.floor((Date.now() - el._start) / 1000));
      const h = Math.floor(s / 3600); s %= 3600;
      el.textContent = pad2(h) + ':' + pad2(Math.floor(s / 60)) + ':' + pad2(s % 60);
    });
    const t = document.getElementById('timerTicker');
    if (t && t.dataset.started) {
      const s = Math.max(0, Math.floor((Date.now() - new Date(t.dataset.started.replace(' ', 'T')).getTime()) / 1000));
      t.textContent = pad2(Math.floor(s / 60)) + ':' + pad2(s % 60);
    }
  }, 1000);

  /* ---------- Global unread-message poller (bell + sidebar badge) ---------- */
  (function () {
    const bell = document.getElementById('notifBadge');
    const navB = document.getElementById('navMsgBadge');
    if (!bell && !navB) return;
    let last = -1;
    async function checkUnread() {
      try {
        const r = await TW.api('chat_unread', {});
        const n = r.unread || 0;
        [bell, navB].forEach((b) => { if (!b) return; if (n > 0) { b.textContent = n; b.style.display = ''; } else b.style.display = 'none'; });
        const base = document.title.replace(/^\(\d+\)\s*/, '');
        document.title = (n > 0 ? '(' + n + ') ' : '') + base;
        if (last >= 0 && n > last) TW.toast('📩 New message', 'info');
        last = n;
      } catch (e) {}
    }
    checkUnread();
    setInterval(checkUnread, 12000);
  })();
})();
