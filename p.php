<?php
/**
 * Taskway — public portfolio (no login).
 * List:   p.php?u=<token>
 * Detail: p.php?u=<token>&p=<project_id>
 * Clean editorial style — big type, image cards, tech chips, GSAP-subtle motion.
 */
require_once __DIR__ . '/config.php';

$token = preg_replace('/[^a-f0-9]/', '', (string)($_GET['u'] ?? ''));
$owner = null;
if ($token !== '') {
    $stmt = db()->prepare('SELECT * FROM users WHERE portfolio_token = ? LIMIT 1');
    $stmt->execute([$token]);
    $owner = $stmt->fetch() ?: null;
}
$live = $owner && (int)($owner['portfolio_enabled'] ?? 1) === 1 && $owner['status'] === 'active';

$projects = []; $detail = null; $totMin = 0; $totDone = 0;
if ($live) {
    $uid = (int)$owner['id'];
    $stmt = db()->prepare("SELECT * FROM projects WHERE user_id = ? AND deleted_at IS NULL AND in_portfolio = 1
        ORDER BY CASE status WHEN 'active' THEN 0 WHEN 'done' THEN 1 ELSE 2 END, position, name");
    $stmt->execute([$uid]);
    $projects = $stmt->fetchAll();

    foreach ($projects as $i => $p) {
        $s = db()->prepare("SELECT COUNT(*) t, SUM(CASE WHEN status='done' THEN 1 ELSE 0 END) d,
            COALESCE(SUM(spent_min),0) m FROM tasks WHERE project_id = ? AND deleted_at IS NULL");
        $s->execute([$p['id']]);
        $r = $s->fetch();
        $projects[$i]['_total'] = (int)$r['t'];
        $projects[$i]['_done'] = (int)$r['d'];
        $projects[$i]['_min'] = (int)$r['m'];
        $projects[$i]['_tech'] = array_values(array_filter(array_map('trim', explode(',', (string)($p['technologies'] ?? '')))));
        $projects[$i]['_shots'] = json_decode((string)($p['shots'] ?? '[]'), true) ?: [];
    }
    $totMin = array_sum(array_column($projects, '_min'));
    $totDone = array_sum(array_column($projects, '_done'));

    $pid = (int)($_GET['p'] ?? 0);
    if ($pid) {
        foreach ($projects as $idx => $p) if ((int)$p['id'] === $pid) { $detail = $p; $detailIdx = $idx; break; }
    }
}

$name = $live ? ($owner['name'] ?: $owner['username']) : '';
$headline = $live ? (trim((string)($owner['portfolio_headline'] ?? '')) ?: 'Building digital products that ship.') : '';
$bio = $live ? trim((string)($owner['portfolio_bio'] ?? '')) : '';
$hours = (int)floor($totMin / 60);
$base = 'p.php?u=' . esc($token);

function cover_block(array $p, string $cls = ''): void
{
    ?>
    <div class="cover <?= $cls ?>">
      <?php if (!empty($p['thumb_path'])): ?>
        <img src="<?= esc(url($p['thumb_path'])) ?>" alt="<?= esc($p['name']) ?>" loading="lazy">
      <?php else: ?>
        <div class="cover-fallback" style="background:linear-gradient(135deg,<?= esc($p['color']) ?>26,<?= esc($p['color']) ?>0d)">
          <span style="filter:drop-shadow(0 10px 22px <?= esc($p['color']) ?>66)"><?= esc($p['icon'] ?: '📁') ?></span>
        </div>
      <?php endif; ?>
    </div>
    <?php
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $live ? esc($name) . ($detail ? ' — ' . esc($detail['name']) : ' — Portfolio') : 'Portfolio' ?></title>
<meta name="description" content="<?= $live ? esc($name . ' — ' . $headline) : 'Private portfolio' ?>">
<link rel="icon" href="data:image/svg+xml,<?= rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect width="100" height="100" rx="26" fill="#111"/><path d="M28 52l14 14 30-32" stroke="#fff" stroke-width="10" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>') ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;600;700;800;900&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root{ --bg:#F5F3EF; --ink:#101012; --mut:#77746D; --line:#E4E0D8; --card:#EBE7E0; --white:#fff; }
*{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
body{background:var(--bg);color:var(--ink);font:15.5px/1.65 "Inter",system-ui,sans-serif;-webkit-font-smoothing:antialiased;overflow-x:hidden}
a{color:inherit;text-decoration:none}
.wrap{max-width:1240px;margin:0 auto;padding:0 28px}
.disp{font-family:"Archivo",sans-serif;letter-spacing:-.03em}

/* nav */
nav{position:sticky;top:0;z-index:50;background:rgba(245,243,239,.85);backdrop-filter:blur(12px);border-bottom:1px solid var(--line)}
.nav-in{display:flex;align-items:center;gap:14px;padding:16px 0}
.logo{display:flex;align-items:center;gap:11px;font-family:"Archivo";font-weight:800;font-size:19px}
.logo .mark{width:36px;height:36px;border-radius:11px;background:var(--ink);color:#fff;display:grid;place-items:center;font-size:16px}
.pill{display:inline-flex;align-items:center;gap:8px;padding:11px 22px;border-radius:99px;font-weight:600;font-size:13.5px;cursor:pointer;transition:.25s;border:1px solid var(--ink);background:transparent;font-family:inherit}
.pill:hover{background:var(--ink);color:#fff}
.pill.dark{background:var(--ink);color:#fff}
.pill.dark:hover{transform:translateY(-2px);box-shadow:0 10px 24px -10px rgba(0,0,0,.4)}

/* hero */
.hero{padding:84px 0 40px}
.kick{font-size:12px;font-weight:700;letter-spacing:.28em;text-transform:uppercase;color:var(--mut);display:flex;align-items:center;gap:12px}
.kick::before{content:"";width:34px;height:2px;background:var(--ink)}
h1.mega{font-size:clamp(52px,10vw,138px);font-weight:900;line-height:.98;text-transform:uppercase;margin:22px 0 8px}
h1.mega .o{-webkit-text-stroke:2px var(--ink);color:transparent}
.hero-row{display:flex;gap:40px;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;margin-top:26px}
.hero-row p{max-width:520px;color:var(--mut);font-size:16px}
.hstats{display:flex;gap:34px}
.hstats b{display:block;font-family:"Archivo";font-size:30px;font-weight:800}
.hstats i{font-style:normal;font-size:11px;color:var(--mut);letter-spacing:.12em;text-transform:uppercase;font-weight:600}

/* marquee divider */
.marq{border-top:1.5px solid var(--ink);border-bottom:1.5px solid var(--ink);padding:16px 0;overflow:hidden;margin:56px 0 0;background:var(--bg)}
.marq-t{display:flex;gap:44px;white-space:nowrap;will-change:transform;font-family:"Archivo";font-weight:800;font-size:19px;text-transform:uppercase;letter-spacing:.02em}
.marq-t span{display:flex;align-items:center;gap:44px}
.marq-t span::after{content:"✦"}

/* work grid */
.work{padding:72px 0 30px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:56px 40px}
.pcard{display:block}
.cover{position:relative;border-radius:22px;overflow:hidden;aspect-ratio:4/3;background:var(--card)}
.cover img{width:100%;height:100%;object-fit:cover;transition:transform .8s cubic-bezier(.2,.6,.2,1)}
.pcard:hover .cover img{transform:scale(1.045)}
.cover-fallback{width:100%;height:100%;display:grid;place-items:center;font-size:84px;transition:transform .8s cubic-bezier(.2,.6,.2,1)}
.pcard:hover .cover-fallback{transform:scale(1.045)}
.pcard .cover::before{content:"";position:absolute;inset:0;background:rgba(16,16,18,.24);opacity:0;transition:.35s;z-index:1}
.pcard .cover::after{content:"View project ↗";position:absolute;left:50%;top:50%;transform:translate(-50%,-40%);z-index:2;
  background:#fff;color:var(--ink);border-radius:99px;padding:13px 26px;font-family:"Archivo";font-weight:700;font-size:14px;
  opacity:0;transition:.35s;white-space:nowrap}
.pcard:hover .cover::before{opacity:1}
.pcard:hover .cover::after{opacity:1;transform:translate(-50%,-50%)}
.prow{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-top:18px;flex-wrap:wrap}
.prow h3{font-family:"Archivo";font-size:22px;font-weight:700;letter-spacing:-.02em}
.chips{display:flex;gap:7px;flex-wrap:wrap}
.chip{font-size:10.5px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;padding:6px 13px;border-radius:99px;background:var(--white);border:1px solid var(--line);color:var(--mut)}

/* footer band */
.band{background:var(--ink);color:#fff;margin-top:90px;overflow:hidden}
.band .marq2{padding:34px 0;overflow:hidden}
.band .marq-t{font-size:clamp(54px,9vw,110px);color:#fff;gap:60px}
.band .marq-t span{gap:60px}
.band-in{display:flex;align-items:center;justify-content:space-between;gap:20px;flex-wrap:wrap;
  border-top:1px solid rgba(255,255,255,.15);padding:22px 0;font-size:13px;color:rgba(255,255,255,.65)}
.band-in b{color:#fff}
.band .pill{border-color:#fff;color:#fff}
.band .pill:hover{background:#fff;color:var(--ink)}

/* detail page */
.dhero{padding:70px 0 34px}
.dback{display:inline-flex;align-items:center;gap:9px;font-weight:600;font-size:13.5px;color:var(--mut);margin-bottom:26px;transition:.25s}
.dback:hover{color:var(--ink);transform:translateX(-3px)}
h1.dtitle{font-size:clamp(40px,7vw,92px);font-weight:900;line-height:1;letter-spacing:-.03em;text-transform:uppercase}
.dmeta{display:flex;gap:0;margin:34px 0 0;border:1.5px solid var(--ink);border-radius:18px;overflow:hidden;flex-wrap:wrap;background:var(--white)}
.dmeta>div{flex:1 1 140px;padding:18px 20px;border-right:1px solid var(--line)}
.dmeta>div:last-child{border-right:0}
.dmeta i{font-style:normal;display:block;font-size:10.5px;letter-spacing:.14em;text-transform:uppercase;color:var(--mut);font-weight:700;margin-bottom:4px}
.dmeta b{font-family:"Archivo";font-size:17px;font-weight:700}
.dcover{margin:40px 0 0}
.dcover .cover{aspect-ratio:16/9;border-radius:26px}
.dbody{display:grid;grid-template-columns:1.3fr .7fr;gap:56px;padding:60px 0 10px}
.dbody h2{font-family:"Archivo";font-size:26px;font-weight:800;margin-bottom:16px;letter-spacing:-.02em}
.dbody p.about{color:#3f3d38;font-size:16.5px;white-space:pre-line}
.dside .box{border:1px solid var(--line);background:var(--white);border-radius:18px;padding:22px;margin-bottom:18px}
.dside .box i{font-style:normal;display:block;font-size:10.5px;letter-spacing:.14em;text-transform:uppercase;color:var(--mut);font-weight:700;margin-bottom:11px}
.links a{display:flex;align-items:center;justify-content:space-between;padding:13px 16px;border:1px solid var(--line);border-radius:12px;font-weight:600;font-size:14px;margin-bottom:9px;transition:.25s;background:var(--bg)}
.links a:hover{background:var(--ink);color:#fff;transform:translateX(3px)}
.links a:last-child{margin-bottom:0}
.shots{padding:30px 0 10px}
.shots h2{font-family:"Archivo";font-size:clamp(26px,4vw,44px);font-weight:800;margin-bottom:30px;letter-spacing:-.02em}
.shot{border-radius:22px;overflow:hidden;border:1px solid var(--line);margin-bottom:30px;background:var(--card)}
.shot img{width:100%;display:block}
.dnav{display:flex;justify-content:space-between;gap:16px;padding:40px 0 0;flex-wrap:wrap}
.dnav a{font-family:"Archivo";font-weight:700;font-size:16px;display:flex;align-items:center;gap:10px;transition:.25s}
.dnav a:hover{color:#000;letter-spacing:.01em}

.rv{opacity:0;transform:translateY(30px)}
@media(max-width:860px){ .grid{grid-template-columns:1fr} .dbody{grid-template-columns:1fr;gap:34px} }
@media(prefers-reduced-motion:reduce){ .rv{opacity:1;transform:none} }

.locked{min-height:100svh;display:grid;place-items:center;text-align:center;padding:24px}
</style>
</head>
<body>

<?php if (!$live): ?>
  <div class="locked"><div>
    <div style="font-size:60px;margin-bottom:16px">🔒</div>
    <h1 class="disp" style="font-size:34px;margin-bottom:10px">This portfolio is private</h1>
    <p style="color:var(--mut)">Ya link ghalat hai, ya owner ne portfolio private kar diya hai.</p>
  </div></div>

<?php elseif ($detail): /* ============ PROJECT DETAIL ============ */ ?>

<nav><div class="wrap nav-in">
  <a class="logo" href="<?= $base ?>"><span class="mark"><?= esc(mb_strtoupper(mb_substr($name, 0, 1))) ?></span><?= esc($name) ?></a>
  <div style="margin-left:auto;display:flex;gap:10px">
    <button class="pill" onclick="shareIt()">Share ↗</button>
    <a class="pill dark" href="<?= $base ?>#work">All work</a>
  </div>
</div></nav>

<section class="dhero"><div class="wrap">
  <a class="dback rv" href="<?= $base ?>#work">← Back to portfolio</a>
  <h1 class="disp dtitle rv"><?= esc($detail['name']) ?></h1>
  <div class="dmeta rv">
    <div><i>Status</i><b><?= $detail['status'] === 'done' ? '✅ Completed' : '🟢 Live' ?></b></div>
    <div><i>Year</i><b><?= esc(date('Y', strtotime($detail['created_at'] ?? 'now'))) ?></b></div>
    <div><i>Stack</i><b><?= $detail['_tech'] ? esc(implode(' · ', array_slice($detail['_tech'], 0, 3))) : 'Web' ?></b></div>
    <?php if (!empty($detail['website_url'])): ?>
      <div style="display:flex;align-items:center"><a class="pill dark" href="<?= esc($detail['website_url']) ?>" target="_blank" rel="noopener">Visit live website ↗</a></div>
    <?php endif; ?>
  </div>
  <div class="dcover rv"><?php cover_block($detail); ?></div>
</div></section>

<section><div class="wrap dbody">
  <div>
    <h2 class="rv">About this project</h2>
    <p class="about rv"><?= esc($detail['description'] ?: 'A project built and tracked in my Taskway workspace.') ?></p>
    <?php if ($detail['_tech']): ?>
      <div class="chips rv" style="margin-top:24px">
        <?php foreach ($detail['_tech'] as $t): ?><span class="chip"><?= esc($t) ?></span><?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
  <aside class="dside">
    <div class="box rv"><i>Project links</i>
      <div class="links">
        <?php if (!empty($detail['website_url'])): ?><a href="<?= esc($detail['website_url']) ?>" target="_blank" rel="noopener">🌐 Live website <span>↗</span></a><?php endif; ?>
        <?php if (!empty($detail['git_url'])): ?><a href="<?= esc($detail['git_url']) ?>" target="_blank" rel="noopener">🔗 Source code <span>↗</span></a><?php endif; ?>
        <?php if (!empty($detail['pdf_path'])): ?><a href="<?= esc(url($detail['pdf_path'])) ?>" target="_blank" rel="noopener">📄 Documentation <span>↗</span></a><?php endif; ?>
        <?php if (empty($detail['website_url']) && empty($detail['git_url']) && empty($detail['pdf_path'])): ?>
          <span style="color:var(--mut);font-size:13.5px">No public links yet.</span>
        <?php endif; ?>
      </div>
    </div>
    <div class="box rv"><i>Progress</i>
      <?php $pct = $detail['_total'] > 0 ? (int)round($detail['_done'] / $detail['_total'] * 100) : 0; ?>
      <div style="display:flex;align-items:center;gap:14px">
        <div style="flex:1;height:8px;border-radius:99px;background:var(--card);overflow:hidden">
          <i style="display:block;height:100%;width:<?= $pct ?>%;background:var(--ink);border-radius:99px"></i>
        </div>
        <b class="disp" style="font-size:20px"><?= $pct ?>%</b>
      </div>
    </div>
  </aside>
</div></section>

<?php if ($detail['_shots']): ?>
<section class="shots"><div class="wrap">
  <h2 class="rv">Screenshots</h2>
  <?php foreach ($detail['_shots'] as $s): ?>
    <figure class="shot rv"><img src="<?= esc(url($s)) ?>" alt="<?= esc($detail['name']) ?> screenshot" loading="lazy"></figure>
  <?php endforeach; ?>
</div></section>
<?php endif; ?>

<section><div class="wrap dnav">
  <?php
    $n = count($projects);
    $prev = $projects[($detailIdx - 1 + $n) % $n];
    $next = $projects[($detailIdx + 1) % $n];
  ?>
  <a href="<?= $base ?>&p=<?= (int)$prev['id'] ?>">← <?= esc($prev['name']) ?></a>
  <a href="<?= $base ?>&p=<?= (int)$next['id'] ?>"><?= esc($next['name']) ?> →</a>
</div></section>

<?php else: /* ============ PORTFOLIO LIST ============ */ ?>

<nav><div class="wrap nav-in">
  <a class="logo" href="<?= $base ?>"><span class="mark"><?= esc(mb_strtoupper(mb_substr($name, 0, 1))) ?></span><?= esc($name) ?></a>
  <div style="margin-left:auto;display:flex;gap:10px">
    <button class="pill" onclick="shareIt()">Share ↗</button>
    <a class="pill dark" href="#work">View work ↓</a>
  </div>
</div></nav>

<header class="hero"><div class="wrap">
  <div class="kick rv">Portfolio · <?= date('Y') ?></div>
  <h1 class="disp mega">
    <span class="rv" style="display:block"><?= esc($name) ?></span>
    <span class="rv o" style="display:block"><?= esc($headline) ?></span>
  </h1>
  <div class="hero-row">
    <p class="rv"><?= $bio ? nl2br(esc($bio)) : 'Real projects with real progress — tracked live in my Taskway workspace.' ?></p>
    <div class="hstats rv">
      <div><b data-count="<?= count($projects) ?>">0</b><i>Projects</i></div>
      <div><b data-count="<?= $totDone ?>">0</b><i>Tasks done</i></div>
      <div><b data-count="<?= $hours ?>">0</b><i>Hours</i></div>
    </div>
  </div>
</div></header>

<div class="marq"><div class="marq-t" id="marq1">
  <?php for ($k = 0; $k < 6; $k++): ?><span>Selected Work</span><?php endfor; ?>
</div></div>

<section class="work" id="work"><div class="wrap">
  <?php if (!$projects): ?>
    <p style="text-align:center;color:var(--mut);padding:60px 0">No public projects yet.</p>
  <?php else: ?>
  <div class="grid">
    <?php foreach ($projects as $p): ?>
      <a class="pcard rv" href="<?= $base ?>&p=<?= (int)$p['id'] ?>">
        <?php cover_block($p); ?>
        <div class="prow">
          <h3><?= esc($p['name']) ?></h3>
          <div class="chips">
            <?php if ($p['_tech']): foreach (array_slice($p['_tech'], 0, 3) as $t): ?>
              <span class="chip"><?= esc($t) ?></span>
            <?php endforeach; else: ?>
              <span class="chip"><?= $p['status'] === 'done' ? 'Shipped' : 'In development' ?></span>
            <?php endif; ?>
          </div>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div></section>

<?php endif; ?>

<?php if ($live): ?>
<div class="band">
  <div class="marq2"><div class="marq-t" id="marq2">
    <?php for ($k = 0; $k < 4; $k++): ?><span>Say Hello!</span><?php endfor; ?>
  </div></div>
  <div class="wrap band-in">
    <div>Crafted with <b>Taskway</b> — <?= esc($name) ?>'s work OS · © <?= date('Y') ?></div>
    <button class="pill" onclick="shareIt()">Share this portfolio ↗</button>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/ScrollTrigger.min.js"></script>
<script>
window.shareIt = function () {
  var url = location.href.split('&p=')[0];
  if (navigator.share) { navigator.share({ title: document.title, url: url }).catch(function(){}); return; }
  (navigator.clipboard ? navigator.clipboard.writeText(url) : Promise.reject()).then(
    function () { alert('Link copied! 📋'); }, function () { prompt('Copy this link:', url); });
};
(function () {
  if (!window.gsap || matchMedia('(prefers-reduced-motion: reduce)').matches) {
    document.querySelectorAll('.rv').forEach(function (el) { el.style.opacity = 1; el.style.transform = 'none'; });
    document.querySelectorAll('[data-count]').forEach(function (el) { el.textContent = el.dataset.count; });
    return;
  }
  gsap.registerPlugin(ScrollTrigger);

  // gentle reveals
  ScrollTrigger.batch('.rv', {
    start: 'top 90%',
    onEnter: function (els) { gsap.to(els, { opacity: 1, y: 0, duration: .9, stagger: .09, ease: 'power3.out' }); }
  });
  gsap.to('.hero .rv, .dhero .rv', { opacity: 1, y: 0, duration: 1, stagger: .12, ease: 'power3.out', delay: .1 });

  // counters
  document.querySelectorAll('[data-count]').forEach(function (el) {
    var t = parseInt(el.dataset.count, 10) || 0;
    gsap.fromTo(el, { innerText: 0 }, { innerText: t, duration: 1.4, delay: .5, snap: { innerText: 1 }, ease: 'power2.out',
      onUpdate: function () { el.textContent = Math.round(parseFloat(el.textContent)); } });
  });

  // marquees
  ['marq1', 'marq2'].forEach(function (id, i) {
    var m = document.getElementById(id);
    if (m) gsap.to(m, { xPercent: -50, duration: i ? 20 : 26, repeat: -1, ease: 'none' });
  });

  // soft image parallax
  document.querySelectorAll('.cover img, .shot img').forEach(function (img) {
    gsap.fromTo(img, { yPercent: -4 }, { yPercent: 4, ease: 'none',
      scrollTrigger: { trigger: img, start: 'top bottom', end: 'bottom top', scrub: true } });
  });
})();
</script>
<?php endif; ?>
</body>
</html>
