<?php
/** Attendance — check-in/out clock + records and total hours. */
$ACTIVE = 'attendance';
$PAGE_TITLE = 'Attendance';
$PAGE_SUB = 'Your work clock & hours';

$open = current_attendance();
$today = date('Y-m-d');
[$wf, $wt] = period_range('week');
[$mf, $mt] = period_range('month');

$todayMin = attendance_minutes($today, $today);
$weekMin  = attendance_minutes($wf, $wt);
$monthMin = attendance_minutes($mf, $mt);
$monthDays = attendance_days($mf, $mt);

// Records this month, grouped by day.
$records = attendance_records(date('Y-m-01'), $today);
$byDay = [];
foreach ($records as $r) {
    $byDay[$r['log_date']][] = $r;
}

$openElapsed = $open ? max(0, time() - strtotime($open['check_in'])) : 0;

require __DIR__ . '/../partials/header.php';
?>

<!-- Status hero -->
<div class="card animate mb-6" style="border:0;overflow:hidden;color:#fff;background:<?= $open ? 'var(--grad-mint)' : 'var(--grad-hero)' ?>">
  <div class="card-pad" style="padding:26px 28px;display:flex;align-items:center;gap:22px;flex-wrap:wrap">
    <div class="grow" style="min-width:220px">
      <?php if ($open): ?>
        <div class="row" style="gap:8px;opacity:.92;font-weight:600"><span class="live-dot" style="background:#fff"></span> Checked in</div>
        <div style="font-size:34px;font-weight:800;letter-spacing:-.02em;margin:6px 0"><span class="live-elapsed" data-elapsed="<?= $openElapsed ?>">00:00:00</span></div>
        <div style="opacity:.9;font-size:13.5px">Since <?= esc(date('h:i A', strtotime($open['check_in']))) ?> today</div>
      <?php else: ?>
        <div style="opacity:.9;font-weight:600">You're currently</div>
        <div style="font-size:30px;font-weight:800;margin:4px 0">Checked out</div>
        <div style="opacity:.9;font-size:13.5px">Start your work clock whenever you begin.</div>
      <?php endif; ?>
    </div>
    <div style="flex:0 0 auto">
      <?php if ($open): ?>
        <button class="btn btn-lg" data-attendance="checkout" style="background:#fff;color:#e5484d;font-weight:800">⏹ Check Out</button>
      <?php else: ?>
        <button class="btn btn-lg" data-attendance="checkin" style="background:#fff;color:var(--violet-600);font-weight:800">🟢 Check In</button>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Totals -->
<div class="grid cols-4 mb-6">
  <div class="tile animate d1"><div class="row between"><div><div class="t-label">Today</div><div class="t-value"><?= esc(fmt_min($todayMin)) ?></div></div><div class="t-ic" style="background:var(--primary-soft);color:var(--primary)">📅</div></div></div>
  <div class="tile animate d2"><div class="row between"><div><div class="t-label">This Week</div><div class="t-value"><?= esc(fmt_min($weekMin)) ?></div></div><div class="t-ic" style="background:var(--success-soft);color:var(--mint)">🗓️</div></div></div>
  <div class="tile animate d3"><div class="row between"><div><div class="t-label">This Month</div><div class="t-value"><?= esc(fmt_min($monthMin)) ?></div></div><div class="t-ic" style="background:var(--info-soft);color:var(--sky)">📆</div></div></div>
  <div class="tile animate d4"><div class="row between"><div><div class="t-label">Days Present</div><div class="t-value"><?= $monthDays ?></div></div><div class="t-ic" style="background:var(--warn-soft);color:var(--amber)">✅</div></div></div>
</div>

<!-- Records -->
<div class="card card-pad animate d1">
  <div class="card-head"><h3>🕐 Attendance record</h3><span class="badge"><?= esc(date('F Y')) ?></span></div>
  <?php if (!$records): ?>
    <div class="empty"><span class="emoji">🕐</span><h4>No attendance yet</h4><p>Hit <strong>Check In</strong> above to start your first session.</p></div>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table class="tbl">
        <thead><tr><th>Day</th><th>Sessions</th><th>Check in → out</th><th style="text-align:right">Total</th></tr></thead>
        <tbody>
          <?php foreach ($byDay as $day => $sessions): ?>
            <?php
              $dayTotal = 0;
              foreach ($sessions as $s) {
                  $dayTotal += $s['check_out'] ? (int)$s['minutes'] : max(0, (int)round((time() - strtotime($s['check_in'])) / 60));
              }
            ?>
            <tr>
              <td>
                <div class="strong"><?= esc(human_date($day)) ?></div>
                <div class="muted small"><?= esc(date('D, M j', strtotime($day))) ?></div>
              </td>
              <td><span class="badge"><?= count($sessions) ?></span></td>
              <td>
                <?php foreach ($sessions as $s): ?>
                  <div class="small row" style="margin:2px 0;gap:7px">
                    <strong><?= esc(date('h:i A', strtotime($s['check_in']))) ?></strong>
                    <span class="muted">→</span>
                    <?php if ($s['check_out']): ?>
                      <strong><?= esc(date('h:i A', strtotime($s['check_out']))) ?></strong>
                      <span class="tag muted">(<?= esc(fmt_min((int)$s['minutes'])) ?>)</span>
                    <?php else: ?>
                      <span class="badge in_progress"><span class="dot"></span>in progress</span>
                    <?php endif; ?>
                    <button class="icon-btn" style="width:26px;height:26px;font-size:12px" title="Delete record"
                      onclick="if(confirm('Delete this attendance record?'))TW.api('attendance_delete',{id:<?= (int)$s['id'] ?>}).then(()=>location.reload()).catch(e=>TW.toast(e.message,'err'))">🗑</button>
                  </div>
                <?php endforeach; ?>
              </td>
              <td style="text-align:right"><strong style="color:var(--primary)"><?= esc(fmt_min($dayTotal)) ?></strong></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../partials/footer.php'; ?>
