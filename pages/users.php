<?php
/** Users — super admin: global overview + user management. */
$ACTIVE = 'users';
$PAGE_TITLE = 'Users';
$PAGE_SUB = 'Manage people and see the whole workspace';

$ov = admin_overview();
$rows = admin_user_rows();
$meId = current_user_id();

$TOPBAR_ACTIONS = '<button class="btn btn-primary" data-open-modal="userModal" onclick="userForm.reset(); document.getElementById(\'umTitle\').textContent=\'Add user\'; document.getElementById(\'umId\').value=\'\'; document.getElementById(\'umPwHint\').textContent=\'\'; document.getElementById(\'umUsername\').disabled=false;">＋ Add user</button>';

require __DIR__ . '/../partials/header.php';
?>

<!-- Overview -->
<div class="grid cols-4 mb-6">
  <div class="stat violet animate"><span class="stat-ic">👥</span>
    <div class="stat-label">Users</div>
    <div class="stat-value"><?= $ov['total_users'] ?></div>
    <div class="stat-meta"><?= $ov['active_users'] ?> active</div>
  </div>
  <div class="stat mint animate d1"><span class="stat-ic">⏱️</span>
    <div class="stat-label">Hours This Month</div>
    <div class="stat-value"><?= esc(fmt_hours($ov['month_minutes'])) ?><small>h</small></div>
    <div class="stat-meta">across everyone</div>
  </div>
  <div class="stat sky animate d2"><span class="stat-ic">✅</span>
    <div class="stat-label">Tasks Done</div>
    <div class="stat-value"><?= $ov['done_tasks'] ?></div>
    <div class="stat-meta">of <?= $ov['total_tasks'] ?> total</div>
  </div>
  <div class="stat coral animate d3"><span class="stat-ic">📁</span>
    <div class="stat-label">Projects</div>
    <div class="stat-value"><?= $ov['total_projects'] ?></div>
    <div class="stat-meta">all users</div>
  </div>
</div>

<!-- Users table -->
<div class="card card-pad animate d1">
  <div class="card-head"><h3>👥 All users</h3><span class="badge"><?= count($rows) ?></span></div>
  <div style="overflow-x:auto">
    <table class="tbl">
      <thead><tr><th>User</th><th>Role</th><th>Projects</th><th>Tasks</th><th>Done</th><th>Hours (mo)</th><th>Status</th><th style="text-align:right">Actions</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $u): ?>
          <tr>
            <td>
              <div class="row" style="gap:10px">
                <div class="brand-logo" style="width:32px;height:32px;font-size:13px;border-radius:9px;background:<?= esc($u['color']) ?>"><?= esc(strtoupper(substr($u['name'] ?: $u['username'], 0, 1))) ?></div>
                <div>
                  <div class="strong" style="font-size:13.5px"><?= esc($u['name'] ?: $u['username']) ?><?= (int)$u['id'] === $meId ? ' <span class="muted">(you)</span>' : '' ?></div>
                  <div class="muted" style="font-size:11.5px">@<?= esc($u['username']) ?><?= $u['email'] ? ' · ' . esc($u['email']) : '' ?></div>
                </div>
              </div>
            </td>
            <td><?php if ($u['role'] === 'super_admin'): ?><span class="badge" style="background:var(--primary-soft);color:var(--primary)">★ Admin</span><?php else: ?><span class="badge">User</span><?php endif; ?></td>
            <td><?= (int)$u['projects'] ?></td>
            <td><?= (int)$u['tasks'] ?></td>
            <td><?= (int)$u['done'] ?></td>
            <td class="strong"><?= esc(fmt_hours((int)$u['month_minutes'])) ?>h</td>
            <td><?php if ($u['status'] === 'active'): ?><span class="badge done">Active</span><?php else: ?><span class="badge blocked">Disabled</span><?php endif; ?></td>
            <td style="text-align:right;white-space:nowrap">
              <button class="icon-btn" title="View workspace" onclick="viewUser(<?= (int)$u['id'] ?>)">👁️</button>
              <button class="icon-btn" title="Edit" onclick="editUser(<?= esc(json_encode(['id' => (int)$u['id'], 'name' => $u['name'], 'username' => $u['username'], 'email' => $u['email'], 'role' => $u['role'], 'status' => $u['status']], JSON_UNESCAPED_UNICODE)) ?>)">✏️</button>
              <?php if ((int)$u['id'] !== $meId): ?>
                <button class="icon-btn" title="Delete" onclick="deleteUser(<?= (int)$u['id'] ?>, '<?= esc($u['username']) ?>')">🗑️</button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Add / edit modal -->
<div class="modal-back" id="userModal">
  <div class="modal">
    <div class="modal-head"><h3 id="umTitle">Add user</h3><button class="icon-btn" data-close-modal style="margin-left:auto">✕</button></div>
    <form id="userForm" onsubmit="return saveUser(event)">
      <div class="modal-body">
        <input type="hidden" id="umId">
        <div class="field"><label class="fld">Name</label><input class="input" id="umName" placeholder="Full name"></div>
        <div class="field"><label class="fld">Username</label><input class="input" id="umUsername" placeholder="username" required><div class="help">3–30 chars: letters, numbers, _ or .</div></div>
        <div class="field"><label class="fld">Email <span class="muted">(optional)</span></label><input class="input" id="umEmail" type="email" placeholder="you@example.com"></div>
        <div class="field"><label class="fld">Password <span class="muted" id="umPwHint"></span></label><input class="input" id="umPassword" type="password" placeholder="at least 6 characters"></div>
        <div class="row" style="gap:14px">
          <div class="field grow"><label class="fld">Role</label><select class="select" id="umRole"><option value="user">User</option><option value="super_admin">Super Admin</option></select></div>
          <div class="field grow"><label class="fld">Status</label><select class="select" id="umStatus"><option value="active">Active</option><option value="disabled">Disabled</option></select></div>
        </div>
      </div>
      <div class="modal-foot"><button type="button" class="btn btn-ghost" data-close-modal>Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
    </form>
  </div>
</div>

<script>
function editUser(u) {
  document.getElementById('umTitle').textContent = 'Edit ' + (u.name || u.username);
  document.getElementById('umId').value = u.id;
  document.getElementById('umName').value = u.name || '';
  const un = document.getElementById('umUsername'); un.value = u.username; un.disabled = false;
  document.getElementById('umEmail').value = u.email || '';
  document.getElementById('umPassword').value = '';
  document.getElementById('umPwHint').textContent = '(leave blank to keep current)';
  document.getElementById('umRole').value = u.role;
  document.getElementById('umStatus').value = u.status;
  document.getElementById('userModal').classList.add('open');
}
async function saveUser(e) {
  e.preventDefault();
  const id = document.getElementById('umId').value;
  const payload = {
    id: id ? parseInt(id) : undefined,
    name: document.getElementById('umName').value,
    username: document.getElementById('umUsername').value,
    email: document.getElementById('umEmail').value,
    password: document.getElementById('umPassword').value,
    role: document.getElementById('umRole').value,
    status: document.getElementById('umStatus').value,
  };
  try {
    await TW.api(id ? 'admin_update_user' : 'admin_create_user', payload);
    TW.toast(id ? 'User updated' : 'User added');
    setTimeout(() => TW.reload(), 500);
  } catch (err) { TW.toast(err.message, 'err'); }
  return false;
}
async function deleteUser(id, name) {
  if (!confirm('Delete user "' + name + '" and ALL their data? This cannot be undone.')) return;
  try { await TW.api('admin_delete_user', { id }); TW.toast('User deleted'); setTimeout(() => TW.reload(), 400); }
  catch (err) { TW.toast(err.message, 'err'); }
}
async function viewUser(id) {
  try { await TW.api('admin_view_user', { id }); TW.navigate('<?= page_url('dashboard') ?>'); }
  catch (err) { TW.toast(err.message, 'err'); }
}
</script>

<?php require __DIR__ . '/../partials/footer.php'; ?>
