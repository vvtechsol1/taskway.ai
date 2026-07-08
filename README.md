# Taskway — your AI-moderated personal work OS

A soft, eye-catching admin panel to run your day: paste rough notes → get tasks,
track projects, and see weekly/monthly progress and hours at a glance.

Built with **PHP 8 + SQLite** (zero config — no MySQL, no build step, no external services).

---

## ✨ What it does

| Page | What you get |
|------|--------------|
| **Dashboard** | Today's tasks, hours today/week/month, tasks done, "newly built" count, daily-goal ring, weekly hours chart, task breakdown, active projects, activity feed. |
| **Brain Dump** 🧠 | Paste anything from Notepad. Taskway reads status (done / working / blocked), time (`2h`, `45m`), projects (headings or `#tags`), priority (`urgent`, `!!`) and task type (built → *New*, fixed → *Fix*, updated → *Improve*). You review the preview, tweak, and hit **Add** — tasks (and projects) are created for you. |
| **Tasks** | Filter by status / project / type / search, quick-add, one-click **To do → Doing → Done**, per-task timer & time. |
| **Projects** | Every project with progress %, hours logged, and drill-in detail. |
| **Analytics** | This week / month / year: total hours, tasks completed, **what you built** vs **what got done**, hours by project, work-type mix, trends. |
| **Settings** | Name, daily-hour goal, light/dark theme, offline parser vs Claude AI, optional password lock. |

Your workflow is exactly what you asked for: **copy from Notepad → paste into Brain Dump → tasks appear → you just move them In Progress / Complete.**

---

## 🚀 Run it

It already lives under XAMPP. With Apache running, open:

```
http://localhost/taskway/
```

First time, load some realistic demo data (optional):

```
http://localhost/taskway/seed.php?confirm=1
```

…or start clean and go straight to **Brain Dump**.

No Apache? Use PHP's built-in server:

```
cd c:\xampp\htdocs\taskway
c:\xampp\php\php.exe -S localhost:8000
```

then open <http://localhost:8000/>.

---

## 🧠 How the Brain Dump reads your notes

```
Casebazar:
- fixed checkout crash 2h urgent      → Fix · Done · Urgent · 2h · Casebazar
- built product filter sidebar 3h     → New Build · Done · 3h · Casebazar
- working on payment gateway          → Doing · Casebazar

SEO project
researched competitor keywords 1.5h   → Research · Done · 1.5h · SEO project
[ ] add sitemap auto-submit           → To do
```

- **Mode toggle** — *"Things I did"* marks items done (a work log); *"Things to do"* keeps them as a plan.
- A line on its own that names a **project/app/site**, or ends with `:`, or a `# heading`, starts a new project. Everything under it is filed there.
- Turn on **Claude AI** in Settings (paste an API key) for smarter extraction — it falls back to the offline parser automatically if the key is missing or the call fails.

---

## 🎨 Design

Soft violet + mint + coral palette, rounded cards, gentle shadows, full **light & dark** mode
(toggle in the top bar — your choice is remembered).

---

## 🗂️ Structure

```
index.php          Front controller (?page=dashboard|braindump|tasks|projects|project|analytics|settings)
api.php            JSON API (tasks, projects, timers, parse, commit, stats)
parser.php         Brain-dump engine (offline heuristics + optional Claude)
config.php db.php  Bootstrap + SQLite schema (auto-created on first run)
helpers.php        Data access + analytics
partials/          Shell (header/footer), shared render helpers (ui.php)
pages/             One file per screen
assets/            app.css · app.js · charts.js
data/taskway.sqlite   Your data (auto-created)
seed.php           Optional demo data
```

Everything is local. Your data never leaves your machine.
