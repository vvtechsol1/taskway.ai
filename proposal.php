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

    $system = "You are an expert Upwork proposal writer for a senior full-stack developer. "
        . "Given a job post, the developer's real projects, and an optional budget, return ONLY valid JSON:\n"
        . '{"cover_letter": string, "relevant_projects": [{"name": string, "url": string}], '
        . '"billing": {"mode": "fixed"|"milestones"|"hourly", "reason": string, '
        . '"milestones": [{"name": string, "date": "YYYY-MM-DD", "price": string}]}, '
        . '"questions": [string]}' . "\n"
        . "Rules for cover_letter: 150-250 words, plain text (no markdown headers), hook in the first 2 lines that mirrors the job's exact stack/problem, "
        . "then 2-4 of the MOST relevant projects as bullet lines with their live URLs (only projects whose stack/domain genuinely matches — never irrelevant ones), "
        . "then a short 'first steps' plan, then availability + CTA. Include the portfolio link near the project links. "
        . "Confident but honest — never claim experience with a tool the projects don't show; instead bridge from adjacent experience. Sign with the developer's first name.\n"
        . "billing: recommend fixed for small/clear scope, milestones for medium-large scope (3-5 milestones covering the job's deliverables, dates starting a few days from today and realistically spaced, prices summing to ~the budget if given, else sensible estimates), hourly for vague/ongoing maintenance work. reason = 2-3 sentences the developer can read.\n"
        . "questions: 2-3 smart clarifying questions to ask the client.";

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

function upwork_local(string $job, string $budget, string $notes, array $me, array $projects, string $portfolioUrl): array
{
    $jobL = mb_strtolower($job);

    // Score each project by tech/domain keyword overlap with the job post.
    $scored = [];
    foreach ($projects as $p) {
        $score = 0;
        $hay = mb_strtolower(($p['technologies'] ?? '') . ' ' . ($p['description'] ?? '') . ' ' . $p['name']);
        foreach (preg_split('/[^a-z0-9.+#]+/i', $hay) ?: [] as $w) {
            if (mb_strlen($w) < 3) continue;
            if (mb_strpos($jobL, mb_strtolower($w)) !== false) $score++;
        }
        if (!empty($p['website_url'])) $score += 1;   // prefer link-able work
        if ($score > 0) $scored[] = ['p' => $p, 's' => $score];
    }
    usort($scored, fn($a, $b) => $b['s'] <=> $a['s']);
    $top = array_slice($scored, 0, 4);
    if (!$top) {
        $withUrl = array_values(array_filter($projects, fn($p) => !empty($p['website_url'])));
        $top = array_map(fn($p) => ['p' => $p, 's' => 0], array_slice($withUrl, 0, 3));
    }

    $firstName = explode(' ', trim($me['name'] ?: $me['username']))[0];

    // Detect a stack phrase for the hook.
    $stacks = ['react', 'node', 'next.js', 'vue', 'php', 'wordpress', 'laravel', 'python', 'typescript', 'shopify', 'mongodb', 'mysql', 'ai', 'api'];
    $hits = array_values(array_filter($stacks, fn($s) => mb_strpos($jobL, $s) !== false));
    $stackPhrase = $hits ? strtoupper($hits[0][0]) . substr(implode(' + ', array_slice($hits, 0, 3)), 1) : 'this stack';

    $lines = [];
    $lines[] = "Hi,";
    $lines[] = "";
    $lines[] = ucfirst($stackPhrase) . " is exactly what I build and ship daily — I read your post carefully and this is squarely in my lane.";
    $lines[] = "";
    $lines[] = "Live projects you can open right now:";
    $rel = [];
    foreach ($top as $t) {
        $p = $t['p'];
        $url = $p['website_url'] ?: $portfolioUrl;
        $tech = $p['technologies'] ? ' (' . $p['technologies'] . ')' : '';
        $lines[] = "• {$p['name']}{$tech}: {$url}";
        $rel[] = ['name' => $p['name'], 'url' => $url];
    }
    $lines[] = "• Full portfolio: " . $portfolioUrl;
    $lines[] = "";
    $lines[] = "How I'd start: quick review of the existing code/scope, agree a short punch-list with you, then ship in small tested increments with daily updates — clean, maintainable code, no rewrites unless needed.";
    if ($notes !== '') $lines[] = "Note: " . $notes;
    $lines[] = "";
    $lines[] = "I work independently, communicate daily, and can start immediately. Happy to do a quick call or a small paid trial task so you can judge the quality yourself.";
    $lines[] = "";
    $lines[] = "Regards,";
    $lines[] = $firstName;

    // Billing advice + milestones.
    $amount = (float)preg_replace('/[^0-9.]/', '', $budget);
    $wordCount = str_word_count($job);
    $big = $amount >= 400 || $wordCount > 120;
    $mode = $big ? 'milestones' : ($amount > 0 ? 'fixed' : 'hourly');

    $milestones = [];
    if ($mode === 'milestones') {
        $base = $amount > 0 ? $amount : 600;
        $cuts = [
            ['Project setup, code review & detailed plan', 0.20, 4],
            ['Core features development', 0.40, 12],
            ['Integrations, testing & polish', 0.25, 18],
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
