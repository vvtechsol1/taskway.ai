<?php
/**
 * Taskway — public portfolio page (no login required).
 * URL: p.php?u=<portfolio_token>. Premium dark showcase — Three.js + GSAP.
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

$projects = [];
$totMin = 0; $totDone = 0; $totTasks = 0;
if ($live) {
    $uid = (int)$owner['id'];
    $stmt = db()->prepare("SELECT * FROM projects WHERE user_id = ? AND deleted_at IS NULL AND in_portfolio = 1
        ORDER BY CASE status WHEN 'active' THEN 0 WHEN 'done' THEN 1 ELSE 2 END, position, name");
    $stmt->execute([$uid]);
    $projects = $stmt->fetchAll();

    $q = db()->prepare('SELECT COALESCE(SUM(minutes),0) FROM time_entries WHERE user_id = ?');
    $q->execute([$uid]);
    $totMin = (int)$q->fetchColumn();

    foreach ($projects as $i => $p) {
        $s = db()->prepare("SELECT COUNT(*) t, SUM(CASE WHEN status='done' THEN 1 ELSE 0 END) d,
            COALESCE(SUM(spent_min),0) m FROM tasks WHERE project_id = ? AND deleted_at IS NULL");
        $s->execute([$p['id']]);
        $r = $s->fetch();
        $projects[$i]['_total'] = (int)$r['t'];
        $projects[$i]['_done'] = (int)$r['d'];
        $projects[$i]['_min'] = (int)$r['m'];
        $projects[$i]['_pct'] = $r['t'] > 0 ? (int)round($r['d'] / $r['t'] * 100) : 0;
    }
    $totTasks = array_sum(array_column($projects, '_total'));
    $totDone = array_sum(array_column($projects, '_done'));
}

$name = $live ? ($owner['name'] ?: $owner['username']) : '';
$headline = $live ? (trim((string)($owner['portfolio_headline'] ?? '')) ?: 'I build things that ship.') : '';
$bio = $live ? trim((string)($owner['portfolio_bio'] ?? '')) : '';
$initial = $live ? mb_strtoupper(mb_substr($name, 0, 1)) : '?';
$hours = (int)floor($totMin / 60);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $live ? esc($name) . ' — Portfolio' : 'Portfolio' ?></title>
<meta name="description" content="<?= $live ? esc($name . ' — ' . $headline) : 'Private portfolio' ?>">
<link rel="icon" href="data:image/svg+xml,<?= rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect width="100" height="100" rx="26" fill="#6C5CE7"/><path d="M28 52l14 14 30-32" stroke="#fff" stroke-width="10" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>') ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#0B0A14; --bg2:#12101F; --card:#161327; --card2:#1C1832;
  --line:rgba(255,255,255,.08); --line2:rgba(255,255,255,.14);
  --txt:#F2F0FA; --txt2:#A9A4C4; --txt3:#6F6A8E;
  --violet:#7C6CF0; --violet2:#A29BFE; --mint:#2EE6A8; --coral:#FF7A7A; --amber:#FFC24B; --sky:#5FB8FF;
  --grad:linear-gradient(100deg,#7C6CF0,#A29BFE 45%,#2EE6A8);
}
*{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
body{background:var(--bg);color:var(--txt);font:15px/1.65 "Inter",system-ui,sans-serif;overflow-x:hidden;-webkit-font-smoothing:antialiased}
a{color:inherit;text-decoration:none}
.wrap{max-width:1180px;margin:0 auto;padding:0 24px}
.display{font-family:"Space Grotesk",sans-serif;letter-spacing:-.03em}

/* cursor glow */
#glow{position:fixed;width:520px;height:520px;border-radius:50%;pointer-events:none;z-index:1;
  background:radial-gradient(circle,rgba(124,108,240,.14),transparent 60%);transform:translate(-50%,-50%);left:50%;top:30%}
/* grain */
body::after{content:"";position:fixed;inset:0;pointer-events:none;z-index:120;opacity:.05;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='120' height='120'%3E%3Cfilter id='n'%3E%3CfeTurbulence baseFrequency='.85' numOctaves='2'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.6'/%3E%3C/svg%3E")}

/* nav */
nav{position:fixed;top:0;left:0;right:0;z-index:100;padding:16px 0;transition:.3s}
nav.scrolled{background:rgba(11,10,20,.72);backdrop-filter:blur(14px);border-bottom:1px solid var(--line)}
.nav-in{display:flex;align-items:center;gap:14px}
.avatar{width:40px;height:40px;border-radius:13px;background:var(--grad);display:grid;place-items:center;
  font-weight:700;font-size:18px;color:#0B0A14;font-family:"Space Grotesk"}
.nav-name{font-weight:700;font-size:15.5px}
.nav-sub{font-size:11.5px;color:var(--txt3)}
.btn{display:inline-flex;align-items:center;gap:8px;padding:11px 20px;border-radius:13px;font-weight:600;font-size:13.5px;
  border:1px solid var(--line2);cursor:pointer;background:transparent;color:var(--txt);font-family:inherit;transition:.25s}
.btn:hover{border-color:var(--violet);transform:translateY(-1px)}
.btn.solid{background:var(--grad);border:0;color:#0B0A14;font-weight:700;box-shadow:0 8px 30px -8px rgba(124,108,240,.5)}
.btn.solid:hover{box-shadow:0 12px 40px -8px rgba(124,108,240,.7)}

/* hero */
header{position:relative;min-height:100svh;display:flex;align-items:center}
/* Full-page 3D universe — fixed behind everything, moves with scroll */
#bg3d{position:fixed;inset:0;z-index:0;pointer-events:none}
.orb{position:absolute;border-radius:50%;filter:blur(90px);z-index:0;opacity:.5}
.orb.a{width:480px;height:480px;background:#5b48d8;top:-140px;right:-100px}
.orb.b{width:420px;height:420px;background:#0d7a5c;bottom:-160px;left:-120px}
.hero-in{position:relative;z-index:2;padding:130px 0 80px}
.badge{display:inline-flex;align-items:center;gap:9px;padding:8px 16px;border-radius:99px;font-size:12.5px;font-weight:600;
  border:1px solid var(--line2);background:rgba(255,255,255,.04);color:var(--txt2)}
.badge .dot{width:8px;height:8px;border-radius:99px;background:var(--mint);box-shadow:0 0 12px var(--mint)}
h1{font-size:clamp(44px,7.5vw,92px);line-height:1.02;font-weight:700;margin:26px 0 22px;
  text-shadow:0 1px 0 rgba(255,255,255,.06),0 14px 44px rgba(124,108,240,.35)}
h1 .grad{background:var(--grad);-webkit-background-clip:text;background-clip:text;color:transparent;
  filter:drop-shadow(0 10px 30px rgba(46,230,168,.3))}
.line-mask{display:block;overflow:hidden}
.line-mask span{display:block}
.hero-bio{max-width:560px;color:var(--txt2);font-size:16.5px}
.hero-cta{display:flex;gap:14px;margin-top:34px;flex-wrap:wrap}
.stats{display:flex;gap:0;margin-top:58px;border:1px solid var(--line);border-radius:20px;overflow:hidden;
  background:rgba(255,255,255,.025);backdrop-filter:blur(8px);max-width:640px}
.stat{flex:1;padding:22px 10px;text-align:center;border-right:1px solid var(--line)}
.stat:last-child{border-right:0}
.stat b{display:block;font-size:clamp(26px,3.4vw,38px);font-family:"Space Grotesk";letter-spacing:-.02em}
.stat b em{font-style:normal;color:var(--violet2)}
.stat i{font-style:normal;font-size:12px;color:var(--txt3);font-weight:600;letter-spacing:.06em;text-transform:uppercase}
.scroll-hint{position:absolute;bottom:26px;left:50%;transform:translateX(-50%);z-index:2;color:var(--txt3);font-size:11px;
  letter-spacing:.22em;text-transform:uppercase;display:flex;flex-direction:column;align-items:center;gap:10px}
.scroll-hint::after{content:"";width:1px;height:44px;background:linear-gradient(var(--violet),transparent)}

/* marquee */
.marquee{border-top:1px solid var(--line);border-bottom:1px solid var(--line);padding:20px 0;overflow:hidden;background:var(--bg2)}
.marq-track{display:flex;gap:54px;white-space:nowrap;will-change:transform}
.marq-track span{font-family:"Space Grotesk";font-size:22px;font-weight:600;color:var(--txt3);display:flex;align-items:center;gap:54px}
.marq-track span::after{content:"✦";color:var(--violet);font-size:15px}

/* sections float above the fixed 3D universe */
section,.marquee,footer,nav{position:relative;z-index:2}
section{padding:110px 0}
.sec-head{display:flex;align-items:flex-end;justify-content:space-between;gap:20px;margin-bottom:56px;flex-wrap:wrap}
.kicker{font-size:12px;font-weight:700;letter-spacing:.2em;text-transform:uppercase;color:var(--violet2);display:flex;align-items:center;gap:10px}
.kicker::before{content:"";width:26px;height:1.5px;background:var(--violet)}
.sec-head h2{font-size:clamp(30px,4.6vw,52px);font-weight:700;margin-top:12px}
.sec-head p{color:var(--txt3);font-size:14px;max-width:330px}

/* project cards */
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(330px,1fr));gap:22px}
.card{position:relative;border:1px solid var(--line);border-radius:24px;padding:28px;background:var(--card);
  overflow:hidden;transform-style:preserve-3d;transition:border-color .3s}
.card::before{content:"";position:absolute;inset:0;border-radius:24px;opacity:0;transition:opacity .35s;pointer-events:none;
  background:radial-gradient(600px circle at var(--mx,50%) var(--my,50%),rgba(124,108,240,.12),transparent 45%)}
.card:hover::before{opacity:1}
.card:hover{border-color:var(--line2);box-shadow:0 30px 70px -30px rgba(124,108,240,.45)}
/* real 3D depth: card children float on their own plane */
.card-top,.card .desc,.card .p-meta,.card .p-bar,.card .p-links{transform:translateZ(38px)}
.card .p-ic{transform:translateZ(14px);box-shadow:0 14px 30px -12px rgba(0,0,0,.6)}
.card-top{display:flex;align-items:center;gap:15px;margin-bottom:16px}
.p-ic{width:54px;height:54px;border-radius:16px;display:grid;place-items:center;font-size:25px;flex:0 0 auto}
.card h3{font-family:"Space Grotesk";font-size:20px;letter-spacing:-.01em}
.p-status{font-size:11px;font-weight:700;padding:3px 10px;border-radius:99px;letter-spacing:.04em}
.card p.desc{color:var(--txt2);font-size:13.5px;min-height:44px;margin-bottom:18px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.p-meta{display:flex;gap:18px;margin-bottom:14px}
.p-meta div b{display:block;font-family:"Space Grotesk";font-size:19px}
.p-meta div i{font-style:normal;font-size:11px;color:var(--txt3);text-transform:uppercase;letter-spacing:.05em}
.p-bar{height:6px;border-radius:99px;background:rgba(255,255,255,.07);overflow:hidden;margin-bottom:20px}
.p-bar i{display:block;height:100%;border-radius:99px;width:0}
.p-links{display:flex;gap:9px;flex-wrap:wrap}
.p-link{display:inline-flex;align-items:center;gap:7px;padding:8px 15px;border-radius:11px;font-size:12.5px;font-weight:600;
  border:1px solid var(--line2);transition:.25s;background:rgba(255,255,255,.03)}
.p-link:hover{transform:translateY(-2px);border-color:var(--violet);background:rgba(124,108,240,.12)}
.p-link.none{opacity:.35;pointer-events:none}

/* footer cta */
.cta{position:relative;border-top:1px solid var(--line);text-align:center;overflow:hidden}
.cta h2{font-size:clamp(34px,5.6vw,64px);font-weight:700;margin-bottom:18px}
.cta p{color:var(--txt2);margin-bottom:32px}
footer{border-top:1px solid var(--line);padding:26px 0;text-align:center;color:var(--txt3);font-size:12.5px}
footer b{color:var(--violet2)}

/* reveal helpers */
.rv{opacity:0;transform:translateY(36px)}
@media (max-width:640px){ section{padding:70px 0} .stats{flex-wrap:wrap} .stat{flex:1 1 50%;border-bottom:1px solid var(--line)} #glow{display:none} }
@media (prefers-reduced-motion:reduce){ .rv{opacity:1;transform:none} #bg3d,#glow{display:none} }

/* locked page */
.locked{min-height:100svh;display:grid;place-items:center;text-align:center;padding:24px}
.locked .box{max-width:420px}
.locked .ic{font-size:64px;margin-bottom:18px}
</style>
</head>
<body>

<?php if (!$live): ?>
  <div class="locked">
    <div class="box">
      <div class="ic">🔒</div>
      <h1 class="display" style="font-size:34px;margin-bottom:12px">This portfolio is private</h1>
      <p style="color:var(--txt2)">Ya link ghalat hai, ya owner ne portfolio private kar diya hai.</p>
    </div>
  </div>
<?php else: ?>

<div id="glow"></div>

<nav id="nav">
  <div class="wrap nav-in">
    <div class="avatar"><?= esc($initial) ?></div>
    <div>
      <div class="nav-name"><?= esc($name) ?></div>
      <div class="nav-sub">Portfolio · <?= count($projects) ?> projects</div>
    </div>
    <div style="margin-left:auto;display:flex;gap:10px">
      <button class="btn" onclick="shareIt()">📤 Share</button>
      <a class="btn solid" href="#work">View work ↓</a>
    </div>
  </div>
</nav>

<header>
  <canvas id="bg3d"></canvas>
  <div class="orb a" data-orb></div>
  <div class="orb b" data-orb></div>
  <div class="wrap hero-in">
    <span class="badge rv" data-hero><span class="dot"></span>Available for work · Powered by Taskway</span>
    <h1 class="display">
      <span class="line-mask"><span data-hline>Hi, I'm <span class="grad"><?= esc($name) ?></span>.</span></span>
      <span class="line-mask"><span data-hline><?= esc($headline) ?></span></span>
    </h1>
    <?php if ($bio): ?><p class="hero-bio rv" data-hero><?= nl2br(esc($bio)) ?></p><?php endif; ?>
    <div class="hero-cta rv" data-hero>
      <a class="btn solid" href="#work">🚀 Explore projects</a>
      <button class="btn" onclick="shareIt()">📋 Copy link</button>
    </div>
    <div class="stats rv" data-hero>
      <div class="stat"><b><em data-count="<?= count($projects) ?>">0</em></b><i>Projects</i></div>
      <div class="stat"><b><em data-count="<?= $totDone ?>">0</em>+</b><i>Tasks shipped</i></div>
      <div class="stat"><b><em data-count="<?= $hours ?>">0</em>h</b><i>Hours logged</i></div>
      <div class="stat"><b><em data-count="<?= $totTasks ?>">0</em></b><i>Total tasks</i></div>
    </div>
  </div>
  <div class="scroll-hint">Scroll</div>
</header>

<div class="marquee">
  <div class="marq-track" id="marq">
    <?php $words = array_merge(array_map(fn($p) => $p['name'], array_slice($projects, 0, 6)), ['Build', 'Ship', 'Repeat']); ?>
    <?php for ($k = 0; $k < 2; $k++) foreach ($words as $w): ?><span><?= esc($w) ?></span><?php endforeach; ?>
  </div>
</div>

<section id="work">
  <div class="wrap">
    <div class="sec-head">
      <div>
        <div class="kicker rv" data-rv>Selected work</div>
        <h2 class="display rv" data-rv>Projects I'm <span style="background:var(--grad);-webkit-background-clip:text;background-clip:text;color:transparent">building</span></h2>
      </div>
      <p class="rv" data-rv>Real projects, real progress — live from my Taskway workspace.</p>
    </div>
    <div class="grid">
      <?php foreach ($projects as $p):
        $st = $p['status'] === 'active' ? ['LIVE BUILD', 'rgba(46,230,168,.14)', 'var(--mint)']
            : ($p['status'] === 'done' ? ['SHIPPED', 'rgba(124,108,240,.16)', 'var(--violet2)'] : ['PAUSED', 'rgba(255,194,75,.13)', 'var(--amber)']);
      ?>
      <article class="card rv" data-rv data-tilt>
        <div class="card-top">
          <div class="p-ic" style="background:<?= esc($p['color']) ?>26;color:<?= esc($p['color']) ?>"><?= esc($p['icon'] ?: '📁') ?></div>
          <div style="min-width:0">
            <h3><?= esc($p['name']) ?></h3>
            <span class="p-status" style="background:<?= $st[1] ?>;color:<?= $st[2] ?>"><?= $st[0] ?></span>
          </div>
        </div>
        <p class="desc"><?= esc($p['description'] ?: 'A project in my workspace.') ?></p>
        <div class="p-meta">
          <div><b><?= $p['_done'] ?>/<?= $p['_total'] ?></b><i>Tasks</i></div>
          <div><b><?= esc(fmt_hours($p['_min'])) ?>h</b><i>Logged</i></div>
          <div><b style="color:<?= esc($p['color']) ?>"><?= $p['_pct'] ?>%</b><i>Done</i></div>
        </div>
        <div class="p-bar"><i data-bar="<?= $p['_pct'] ?>" style="background:linear-gradient(90deg,<?= esc($p['color']) ?>,var(--violet2))"></i></div>
        <div class="p-links">
          <a class="p-link <?= empty($p['website_url']) ? 'none' : '' ?>" href="<?= esc($p['website_url'] ?: '#') ?>" target="_blank" rel="noopener">🌐 Live site</a>
          <a class="p-link <?= empty($p['git_url']) ? 'none' : '' ?>" href="<?= esc($p['git_url'] ?: '#') ?>" target="_blank" rel="noopener">🔗 Code</a>
          <?php if (!empty($p['pdf_path'])): ?><a class="p-link" href="<?= esc(url($p['pdf_path'])) ?>" target="_blank" rel="noopener">📄 Docs</a><?php endif; ?>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="cta">
  <div class="orb a" style="top:auto;bottom:-220px;right:30%"></div>
  <div class="wrap">
    <div class="kicker rv" data-rv style="justify-content:center">Let's talk</div>
    <h2 class="display rv" data-rv>Like what you see?<br><span style="background:var(--grad);-webkit-background-clip:text;background-clip:text;color:transparent">Let's build together.</span></h2>
    <p class="rv" data-rv>Share this portfolio ya seedha rabta karein.</p>
    <div class="rv" data-rv><button class="btn solid" onclick="shareIt()">📤 Share this portfolio</button></div>
  </div>
</section>

<footer>
  <div class="wrap">Crafted with <b>Taskway</b> — <?= esc($name) ?>'s AI-moderated work OS · <?= date('Y') ?></div>
</footer>

<script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/ScrollTrigger.min.js"></script>
<script>
(function () {
  var reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---------- share ---------- */
  window.shareIt = function () {
    var url = location.href;
    if (navigator.share) { navigator.share({ title: document.title, url: url }).catch(function(){}); return; }
    (navigator.clipboard ? navigator.clipboard.writeText(url) : Promise.reject()).then(
      function () { alert('Link copied! 📋'); }, function () { prompt('Copy this link:', url); });
  };

  /* ---------- nav scroll state ---------- */
  addEventListener('scroll', function () {
    document.getElementById('nav').classList.toggle('scrolled', scrollY > 40);
  }, { passive: true });

  /* ---------- cursor glow ---------- */
  var glow = document.getElementById('glow');
  if (glow && matchMedia('(pointer:fine)').matches) {
    addEventListener('mousemove', function (e) {
      if (window.gsap) gsap.to(glow, { left: e.clientX, top: e.clientY, duration: .9, ease: 'power3.out' });
    });
  }

  /* ---------- THREE.js hero background ---------- */
  var canvas = document.getElementById('bg3d');
  if (canvas && window.THREE && !reduced) {
    var scene = new THREE.Scene();
    var cam = new THREE.PerspectiveCamera(60, 1, .1, 100); cam.position.z = 26;
    var renderer = new THREE.WebGLRenderer({ canvas: canvas, alpha: true, antialias: true });
    renderer.setPixelRatio(Math.min(devicePixelRatio, 1.8));

    function size() {
      renderer.setSize(innerWidth, innerHeight, false);
      cam.aspect = innerWidth / innerHeight; cam.updateProjectionMatrix();
    }
    size(); addEventListener('resize', size);

    // particle field
    var N = innerWidth < 700 ? 420 : 950;
    var pos = new Float32Array(N * 3), col = new Float32Array(N * 3);
    var palette = [[.49,.42,.94],[.64,.61,1],[.18,.9,.66],[.37,.72,1]];
    for (var i = 0; i < N; i++) {
      pos[i*3]   = (Math.random()-.5)*70;
      pos[i*3+1] = (Math.random()-.5)*42;
      pos[i*3+2] = (Math.random()-.5)*36;
      var c = palette[(Math.random()*palette.length)|0];
      col[i*3]=c[0]; col[i*3+1]=c[1]; col[i*3+2]=c[2];
    }
    var geo = new THREE.BufferGeometry();
    geo.setAttribute('position', new THREE.BufferAttribute(pos, 3));
    geo.setAttribute('color', new THREE.BufferAttribute(col, 3));
    var pts = new THREE.Points(geo, new THREE.PointsMaterial({ size: .16, vertexColors: true, transparent: true, opacity: .8, depthWrite: false }));
    scene.add(pts);

    // wireframe universe: icosahedron, torus knot, octahedron, ring of cubes
    function wire(geo, color, op, x, y, z) {
      var m = new THREE.Mesh(geo, new THREE.MeshBasicMaterial({ color: color, wireframe: true, transparent: true, opacity: op }));
      m.position.set(x, y, z); scene.add(m); return m;
    }
    var ico   = wire(new THREE.IcosahedronGeometry(7.5, 1), 0x7C6CF0, .2, 13, 2, -4);
    var torus = wire(new THREE.TorusKnotGeometry(2.8, .75, 100, 14), 0x2EE6A8, .16, -15, -6, -6);
    var octa  = wire(new THREE.OctahedronGeometry(3.4, 0), 0x5FB8FF, .18, -11, 8, -10);
    var sph   = wire(new THREE.SphereGeometry(5, 14, 14), 0xA29BFE, .1, 4, -12, -14);

    // orbiting ring of glowing cubes
    var ring = new THREE.Group();
    for (var q = 0; q < 14; q++) {
      var cube = new THREE.Mesh(
        new THREE.BoxGeometry(.55, .55, .55),
        new THREE.MeshBasicMaterial({ color: q % 2 ? 0x2EE6A8 : 0x7C6CF0, transparent: true, opacity: .55 })
      );
      var ang = (q / 14) * Math.PI * 2;
      cube.position.set(Math.cos(ang) * 11, Math.sin(ang) * 3.2, Math.sin(ang) * 11);
      ring.add(cube);
    }
    ring.position.set(0, 0, -6);
    scene.add(ring);

    var mx = 0, my = 0, scrollT = 0;
    addEventListener('mousemove', function (e) {
      mx = (e.clientX / innerWidth - .5); my = (e.clientY / innerHeight - .5);
    });
    addEventListener('scroll', function () {
      scrollT = scrollY / Math.max(1, document.body.scrollHeight - innerHeight);
    }, { passive: true });

    var t = 0, running = true;
    document.addEventListener('visibilitychange', function () { running = !document.hidden; });
    (function loop() {
      requestAnimationFrame(loop);
      if (!running) return;
      t += .0035;
      pts.rotation.y = t * .5 + scrollT * 2.2;
      pts.rotation.x = Math.sin(t * .6) * .08 + scrollT * .8;
      var s = 1 + Math.sin(t * 2.4) * .04;   // subtle breathing
      pts.material.opacity = .65 + Math.sin(t * 3) * .15;
      ico.rotation.x += .0016; ico.rotation.y += .0022; ico.scale.set(s, s, s);
      torus.rotation.x -= .0014; torus.rotation.y += .0018;
      octa.rotation.y += .003; octa.rotation.z += .0012;
      sph.rotation.y -= .0011;
      ring.rotation.y = t * .8; ring.rotation.x = .35 + scrollT * 1.4;
      // camera flies deeper as you scroll — the whole page lives inside the scene
      cam.position.z = 26 - scrollT * 9;
      cam.position.x += ((mx * 5) - cam.position.x) * .04;
      cam.position.y += ((-my * 4 - scrollT * 5) - cam.position.y) * .04;
      cam.lookAt(scene.position);
      renderer.render(scene, cam);
    })();
  }

  /* ---------- GSAP animations ---------- */
  if (window.gsap && !reduced) {
    gsap.registerPlugin(ScrollTrigger);

    // hero intro
    gsap.set('[data-hline]', { yPercent: 110 });
    var tl = gsap.timeline({ defaults: { ease: 'power4.out' } });
    tl.to('[data-hline]', { yPercent: 0, duration: 1.1, stagger: .14 }, .15)
      .to('[data-hero]', { opacity: 1, y: 0, duration: .9, stagger: .12 }, '-=.6');

    // counters
    document.querySelectorAll('[data-count]').forEach(function (el) {
      var target = parseInt(el.dataset.count, 10) || 0;
      gsap.fromTo(el, { innerText: 0 }, {
        innerText: target, duration: 1.6, ease: 'power2.out', snap: { innerText: 1 }, delay: .9,
        onUpdate: function () { el.textContent = Math.round(parseFloat(el.textContent)); }
      });
    });

    // floating orbs
    document.querySelectorAll('[data-orb]').forEach(function (o, i) {
      gsap.to(o, { y: i % 2 ? 46 : -46, x: i % 2 ? -30 : 30, duration: 7 + i * 2, yoyo: true, repeat: -1, ease: 'sine.inOut' });
    });

    // marquee infinite
    var marq = document.getElementById('marq');
    if (marq) gsap.to(marq, { xPercent: -50, duration: 26, repeat: -1, ease: 'none' });

    // scroll reveals — 3D flip up from below
    gsap.set('[data-rv]', { rotationX: 22, transformPerspective: 900, transformOrigin: '50% 100%' });
    ScrollTrigger.batch('[data-rv]', {
      start: 'top 88%',
      onEnter: function (els) { gsap.to(els, { opacity: 1, y: 0, rotationX: 0, duration: .95, stagger: .1, ease: 'power3.out' }); }
    });

    // progress bars
    document.querySelectorAll('[data-bar]').forEach(function (bar) {
      gsap.to(bar, {
        width: bar.dataset.bar + '%', duration: 1.2, ease: 'power3.out',
        scrollTrigger: { trigger: bar, start: 'top 92%' }
      });
    });

    // universe dims slightly past the hero so cards stay readable
    gsap.to('#bg3d', { opacity: .45, scrollTrigger: { trigger: 'header', start: 'center top', end: 'bottom top', scrub: true } });
  } else {
    document.querySelectorAll('.rv,[data-hero]').forEach(function (el) { el.style.opacity = 1; el.style.transform = 'none'; });
    document.querySelectorAll('[data-hline]').forEach(function (el) { el.style.transform = 'none'; });
    document.querySelectorAll('[data-count]').forEach(function (el) { el.textContent = el.dataset.count; });
    document.querySelectorAll('[data-bar]').forEach(function (el) { el.style.width = el.dataset.bar + '%'; });
  }

  /* ---------- card tilt + spotlight ---------- */
  if (matchMedia('(pointer:fine)').matches && window.gsap && !reduced) {
    document.querySelectorAll('[data-tilt]').forEach(function (card) {
      card.addEventListener('mousemove', function (e) {
        var r = card.getBoundingClientRect();
        var px = (e.clientX - r.left) / r.width, py = (e.clientY - r.top) / r.height;
        card.style.setProperty('--mx', (px * 100) + '%');
        card.style.setProperty('--my', (py * 100) + '%');
        gsap.to(card, { rotateY: (px - .5) * 11, rotateX: (.5 - py) * 11, scale: 1.02, transformPerspective: 750, duration: .5, ease: 'power2.out' });
      });
      card.addEventListener('mouseleave', function () {
        gsap.to(card, { rotateX: 0, rotateY: 0, scale: 1, duration: .7, ease: 'elastic.out(1,.5)' });
      });
    });
  }
})();
</script>
<?php endif; ?>
</body>
</html>
