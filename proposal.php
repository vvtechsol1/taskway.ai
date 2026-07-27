<?php
/**
 * Taskway — Upwork proposal generator.
 * Reads the user's portfolio projects, matches them to a pasted job post, and produces:
 * cover letter (with live links + portfolio link), billing advice (fixed vs milestones),
 * and a milestone plan (name/date/price). Uses the Claude API when a key is set
 * (Settings → Brain Dump AI); falls back to a smart offline template otherwise.
 */

declare(strict_types=1);

function upwork_generate(string $job, string $budget, string $notes, array $me, array $projects, string $portfolioUrl): array
{
    $key = trim((string)setting('claude_api_key'));
    if (setting('ai_provider') === 'claude' || $key !== '') {
        $r = upwork_claude($job, $budget, $notes, $me, $projects, $portfolioUrl);
        if ($r !== null) return $r + ['engine' => 'claude'];
    }
    return upwork_local($job, $budget, $notes, $me, $projects, $portfolioUrl) + ['engine' => 'local'];
}

/* ------------------------------------------------------------------ */
/* Claude engine                                                       */
/* ------------------------------------------------------------------ */

function upwork_claude(string $job, string $budget, string $notes, array $me, array $projects, string $portfolioUrl): ?array
{
    $key = trim((string)setting('claude_api_key'));
    if ($key === '') return null;
    $model = setting('claude_model', 'claude-sonnet-5') ?: 'claude-sonnet-5';

    $plist = array_map(fn($p) => [
        'name' => $p['name'],
        'about' => mb_substr((string)$p['description'], 0, 400),
        'tech' => $p['technologies'],
        'live_url' => $p['website_url'],
    ], $projects);

    $system = "You are an expert Upwork proposal writer for a senior full-stack developer. Core rule: make it about the CLIENT'S PROBLEM, not the developer's resume — position him as a problem-solver, not another contractor. "
        . "Given a job post, the developer's real projects, and an optional budget, return ONLY valid JSON:\n"
        . '{"cover_letter": string, "relevant_projects": [{"name": string, "url": string}], '
        . '"billing": {"mode": "fixed"|"milestones"|"hourly", "reason": string, '
        . '"milestones": [{"name": string, "date": "YYYY-MM-DD", "price": string}]}, '
        . '"questions": [string]}' . "\n"
        . "COVER LETTER RULES (follow ALL):\n"
        . "1) 3-SECOND HOOK: the first 1-2 lines are all the client sees in preview. NEVER open with greetings, 'my name is', or 'I have X years experience'. Open by diagnosing their core problem, naming the key risk/bottleneck in their project, or validating their approach with an insight (e.g. 'Building an automated voice pipeline is smart — the bottleneck you'll hit is latency.').\n"
        . "2) SHOW DON'T TELL: right after the hook, give 2-3 clickable live links of the MOST relevant projects (bullet lines, one-line 'what it proves' each) + the portfolio link. No long tech-jargon paragraphs — proof beats promises. Never include irrelevant projects.\n"
        . "3) SCANNABILITY: short sentences. Bullet points for stack/deliverables. Label key parts in plain text like 'What I'd build first:' and 'Proof:' (Upwork shows plain text — no markdown syntax like ** or ##).\n"
        . "4) RISK CHUNKING: mention a small, low-risk first step (e.g. a fixed-fee architecture doc, code review, or one end-to-end feature) before the full build — consultant posture.\n"
        . "5) LOW-FRICTION CLOSE: end with ONE specific, easy-to-answer technical/operational question about THEIR project (never 'hope to hear from you').\n"
        . "150-220 words, plain text, confident but honest — never claim tools the projects don't show; bridge from adjacent experience. Sign with the developer's first name.\n"
        . "BILLING: recommend fixed for small/clear scope, milestones for medium-large scope, hourly for vague/ongoing work. When milestones: make MILESTONE 1 deliberately small and cheap (architecture/plan/single feature — lowers client risk, builds trust fast), then 2-4 more covering the job's deliverables; dates start a few days after today, realistically spaced; prices sum to ~the budget if given. reason = 2-3 sentences.\n"
        . "QUESTIONS: 2-3 smart, specific clarifying questions about their project.";

    $userMsg = "TODAY: " . date('Y-m-d') . "\nDEVELOPER: " . ($me['name'] ?: $me['username'])
        . "\nPORTFOLIO URL: $portfolioUrl"
        . "\nBUDGET GIVEN BY ME: " . ($budget !== '' ? $budget : '(none — estimate sensibly)')
        . ($notes !== '' ? "\nMY NOTES: $notes" : '')
        . "\n\nMY REAL PROJECTS (JSON):\n" . json_encode($plist, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        . "\n\nJOB POST:\n" . $job;

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_HTTPHEADER => [
            'content-type: application/json',
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => $model,
            'max_tokens' => 3000,
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $userMsg]],
        ]),
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false || $code >= 300) return null;

    $content = json_decode((string)$resp, true)['content'][0]['text'] ?? '';
    if (preg_match('/\{.*\}/s', $content, $m)) $content = $m[0];
    $out = json_decode($content, true);
    if (!is_array($out) || empty($out['cover_letter'])) return null;
    return $out;
}

/* ------------------------------------------------------------------ */
/* Offline fallback engine                                             */
/* ------------------------------------------------------------------ */

/**
 * Extract the job's REAL tech requirements (known vocabulary only — no generic words),
 * ordered by importance (core frameworks first).
 */
function upwork_job_requirements(string $jobL): array
{
    // keyword => aliases that count as the same requirement inside a project's tech list.
    $vocab = [
        'react'      => ['react', 'next.js', 'nextjs'],
        'node'       => ['node', 'express', 'nest'],
        'vue'        => ['vue', 'nuxt'],
        'typescript' => ['typescript'],
        'directus'   => ['directus', 'headless cms', 'cms'],
        'wordpress'  => ['wordpress', 'woocommerce'],
        'php'        => ['php', 'laravel', 'zend'],
        'python'     => ['python', 'django', 'flask'],
        'react native' => ['react native'],
        'shopify'    => ['shopify'],
        'mongodb'    => ['mongodb', 'mongo'],
        'mysql'      => ['mysql', 'sqlite', 'postgres', 'prisma', 'd1'],
        'redux'      => ['redux'],
        'tailwind'   => ['tailwind'],
        'bootstrap'  => ['bootstrap'],
        'ai'         => ['ai', 'llm', 'openai', 'claude', 'gpt', 'rag', 'mcp'],
        'api'        => ['api', 'rest', 'graphql', 'integration', 'webhook'],
        'ecommerce'  => ['ecommerce', 'e-commerce', 'woocommerce', 'shop', 'store', 'checkout'],
        'crm'        => ['crm'],
        'saas'       => ['saas'],
        'cloudflare' => ['cloudflare', 'workers'],
    ];
    $found = [];
    foreach ($vocab as $req => $aliases) {
        foreach ([$req, ...$aliases] as $a) {
            if (mb_strpos($jobL, $a) !== false) { $found[$req] = $aliases; break; }
        }
    }
    return $found;   // e.g. ['react'=>[aliases], 'node'=>[...], 'directus'=>[...]]
}

function upwork_local(string $job, string $budget, string $notes, array $me, array $projects, string $portfolioUrl): array
{
    $jobL = mb_strtolower($job);

    // 1) Understand the client's MAIN requirements first.
    $reqs = upwork_job_requirements($jobL);
    $coreOrder = array_keys($reqs);   // vocab order = importance (frameworks before generic 'api')

    // 2) Score projects against those requirements ONLY (tech match >> description mention).
    $scored = [];
    foreach ($projects as $p) {
        $tech = mb_strtolower((string)($p['technologies'] ?? ''));
        $desc = mb_strtolower((string)($p['description'] ?? '') . ' ' . $p['name']);
        $score = 0;
        $i = 0;
        foreach ($reqs as $req => $aliases) {
            $weight = max(1, 6 - $i);   // earlier (core) requirements weigh more
            $i++;
            foreach ([$req, ...$aliases] as $a) {
                if ($tech !== '' && mb_strpos($tech, $a) !== false) { $score += 10 * $weight; break; }
                if (mb_strpos($desc, $a) !== false) { $score += 2 * $weight; break; }
            }
        }
        if (!empty($p['website_url'])) $score += 3;
        $scored[] = ['p' => $p, 's' => $score];
    }
    usort($scored, fn($a, $b) => $b['s'] <=> $a['s']);
    // Keep only genuinely-matching projects (tech-level match); pad if too few.
    $top = array_values(array_filter($scored, fn($t) => $t['s'] >= 40));
    if (count($top) < 2) $top = array_values(array_filter($scored, fn($t) => $t['s'] >= 13));
    if (count($top) < 2) $top = $scored;
    $top = array_slice($top, 0, 4);

    $firstName = explode(' ', trim($me['name'] ?: $me['username']))[0];

    // Human-readable stack phrase from the MAIN requirements.
    $pretty = ['react' => 'React', 'node' => 'Node.js', 'vue' => 'Vue', 'typescript' => 'TypeScript',
        'directus' => 'Directus', 'wordpress' => 'WordPress', 'php' => 'PHP', 'python' => 'Python',
        'react native' => 'React Native', 'shopify' => 'Shopify', 'mongodb' => 'MongoDB', 'mysql' => 'MySQL',
        'redux' => 'Redux', 'tailwind' => 'Tailwind', 'bootstrap' => 'Bootstrap', 'ai' => 'AI',
        'api' => 'API integrations', 'ecommerce' => 'e-commerce', 'crm' => 'CRM', 'saas' => 'SaaS', 'cloudflare' => 'Cloudflare'];
    $main = array_slice($coreOrder, 0, 3);
    $stackPhrase = $main ? implode(' + ', array_map(fn($k) => $pretty[$k] ?? $k, $main)) : 'this stack';

    // 3-second hook: diagnose a likely core problem for this kind of job (no greeting, no resume).
    $hooks = [
        'react'     => "A React app like this usually lives or dies on component architecture — get state management wrong early and every feature after gets slower to ship.",
        'node'      => "The risky part of this build isn't the UI — it's the API layer: unhandled edge cases there become production bugs later.",
        'wordpress' => "Most WordPress projects go over budget on plugin conflicts, not features — starting with a clean audit avoids that.",
        'ai'        => "The hard part of an AI feature is rarely the model — it's guardrails and latency; that's where this project will be won or lost.",
        'api'       => "Integrations look simple in the job post and eat 60% of the timeline in practice — mapping the API contracts first keeps this on schedule.",
        'shopify'   => "Theme-level hacks are why most store builds break at scale — this needs clean, upgrade-safe customization from day one.",
        'php'       => "Legacy PHP work goes smoothly only if you review before you rewrite — most 'quick fixes' break hidden dependencies.",
    ];
    $hook = "Scoping this right in week one is what will keep this project on budget — most builds like this slip because the architecture isn't pinned down first.";
    foreach ($hooks as $k => $h) { if (mb_strpos($jobL, $k) !== false) { $hook = $h; break; } }

    $lines = [];
    $lines[] = $hook;
    $lines[] = "I build with " . $stackPhrase . " daily — proof below, not promises.";
    $lines[] = "";
    $lines[] = "Proof (live, click and check):";
    $rel = [];
    foreach ($top as $t) {
        $p = $t['p'];
        $url = $p['website_url'] ?: $portfolioUrl;
        $tech = $p['technologies'] ? ' — ' . $p['technologies'] : '';
        $lines[] = "• {$p['name']}{$tech}: {$url}";
        $rel[] = ['name' => $p['name'], 'url' => $url];
    }
    $lines[] = "• Full portfolio: " . $portfolioUrl;
    $lines[] = "";
    $lines[] = "What I'd build first:";
    $lines[] = "• A short, fixed-fee first milestone — code/scope review + architecture plan — so you see how I work before committing to the full build.";
    $lines[] = "• Then small, tested increments with daily updates. Clean, maintainable code — no surprise rewrites.";
    if ($notes !== '') { $lines[] = ""; $lines[] = "Note: " . $notes; }
    $lines[] = "";
    $lines[] = "Quick question so I can scope this precisely: is there an existing codebase/design I'd be working from, or is this greenfield?";
    $lines[] = "";
    $lines[] = "— " . $firstName;

    // Billing advice + milestones.
    $amount = (float)preg_replace('/[^0-9.]/', '', $budget);
    $wordCount = str_word_count($job);
    $big = $amount >= 400 || $wordCount > 120;
    $mode = $big ? 'milestones' : ($amount > 0 ? 'fixed' : 'hourly');

    $milestones = [];
    if ($mode === 'milestones') {
        $base = $amount > 0 ? $amount : 600;
        // Milestone 1 deliberately small & cheap: lowers the client's risk, builds trust fast.
        $cuts = [
            ['Kickoff: code/scope review + architecture plan (doc)', 0.12, 3],
            ['Core features development', 0.45, 12],
            ['Integrations, testing & polish', 0.28, 18],
            ['Deployment, handover & documentation', 0.15, 23],
        ];
        foreach ($cuts as [$name, $pct, $days]) {
            $milestones[] = [
                'name' => $name,
                'date' => date('Y-m-d', strtotime("+{$days} days")),
                'price' => '$' . number_format($base * $pct, 0),
            ];
        }
        $reason = "Scope looks substantial" . ($amount > 0 ? " and the budget ($budget) is big enough to split" : "") . " — milestones protect both sides: the client pays per delivered chunk, and you never work unpaid. Start with a small first milestone to build trust fast.";
    } elseif ($mode === 'fixed') {
        $reason = "Scope is small/clear enough for a single fixed-price contract" . ($amount > 0 ? " at ~$budget" : "") . ". Agree the deliverable list in writing first, keep revisions bounded (e.g. 2 rounds).";
    } else {
        $reason = "The scope reads open-ended/maintenance-like — hourly protects you from scope creep. Propose a weekly hours cap so the client feels in control.";
    }

    return [
        'cover_letter' => implode("\n", $lines),
        'relevant_projects' => $rel,
        'billing' => ['mode' => $mode, 'reason' => $reason, 'milestones' => $milestones],
        'questions' => [
            'Is there an existing codebase/design I should review before estimating, or is this greenfield?',
            'Who will provide API keys/credentials and staging access, and when?',
            'What does "done" look like for the first delivery — is there a priority feature list?',
        ],
    ];
}
