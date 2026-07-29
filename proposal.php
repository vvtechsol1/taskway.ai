<?php
/**
 * Taskway — Upwork proposal generator.
 * Reads the user's portfolio projects, matches them to a pasted job post, and produces:
 * cover letter (with live links + portfolio link), billing advice (fixed vs milestones),
 * and a milestone plan (name/date/price). Uses the Claude API when a key is set
 * (Settings → Brain Dump AI); falls back to a smart offline template otherwise.
 */

declare(strict_types=1);

/** Pull any reference URLs the client put in the job post (designs, existing site, docs). */
function extract_reference_links(string $text): array
{
    $links = [];
    // Full URLs and www. links
    if (preg_match_all('#\bhttps?://[^\s<>"\')\]]+#i', $text, $m)) $links = array_merge($links, $m[0]);
    if (preg_match_all('#(?<![/\w])www\.[^\s<>"\')\]]+#i', $text, $m)) {
        foreach ($m[0] as $u) $links[] = 'https://' . $u;
    }
    // Bare domains like crewupapp.co or dropbox.com/scl/...
    if (preg_match_all('#(?<![/\w@.-])([a-z0-9][a-z0-9-]*\.)+(com|net|org|io|co|app|dev|ai|me)(/[^\s<>"\')\]]*)?#i', $text, $m)) {
        foreach ($m[0] as $u) $links[] = 'https://' . $u;
    }
    // Clean + dedupe (by normalized form), keep original order, cap at 8.
    $seen = [];
    $out = [];
    foreach ($links as $u) {
        $u = rtrim($u, '.,;:!?)');
        $norm = strtolower(preg_replace('#^https?://(www\.)?#i', '', $u));
        if ($norm === '' || isset($seen[$norm])) continue;
        $seen[$norm] = true;
        $out[] = $u;
        if (count($out) >= 8) break;
    }
    return $out;
}

/** Human-readable names of the technologies the client's job post asks for. */
function upwork_job_tech_names(string $job): array
{
    $pretty = ['react' => 'React', 'node' => 'Node.js', 'vue' => 'Vue.js', 'typescript' => 'TypeScript',
        'directus' => 'Directus', 'wordpress' => 'WordPress', 'php' => 'PHP', 'python' => 'Python',
        'react native' => 'React Native', 'shopify' => 'Shopify', 'mongodb' => 'MongoDB', 'mysql' => 'MySQL',
        'redux' => 'Redux', 'tailwind' => 'Tailwind CSS', 'bootstrap' => 'Bootstrap', 'ai' => 'AI/LLM',
        'api' => 'API Integrations', 'ecommerce' => 'E-commerce', 'crm' => 'CRM', 'saas' => 'SaaS', 'cloudflare' => 'Cloudflare'];
    $reqs = upwork_job_requirements(mb_strtolower($job));
    return array_values(array_map(fn($k) => $pretty[$k] ?? ucfirst($k), array_keys($reqs)));
}

function upwork_generate(string $job, string $budget, string $notes, array $me, array $projects, string $portfolioUrl): array
{
    $extras = [
        'reference_links' => extract_reference_links($job),
        'job_techs'       => upwork_job_tech_names($job),
    ];
    $provider = ai_active_provider();
    if ($provider !== 'local') {
        $r = upwork_claude($job, $budget, $notes, $me, $projects, $portfolioUrl);
        if ($r !== null) return $r + $extras + ['engine' => $provider];
    }
    return upwork_local($job, $budget, $notes, $me, $projects, $portfolioUrl) + $extras + ['engine' => 'local'];
}

/* ------------------------------------------------------------------ */
/* AI provider layer: Claude / Groq (free) / Gemini (free)             */
/* ------------------------------------------------------------------ */

function ai_api_key(): string
{
    $k = trim((string)setting('ai_api_key'));
    return $k !== '' ? $k : trim((string)setting('claude_api_key'));
}

/** Which AI provider is actually usable right now. */
function ai_active_provider(): string
{
    $p = setting('ai_provider', 'local');
    if (!in_array($p, ['claude', 'groq', 'gemini'], true)) return 'local';
    return ai_api_key() !== '' ? $p : 'local';
}

function ai_default_model(string $provider): string
{
    return ['claude' => 'claude-sonnet-5', 'groq' => 'llama-3.3-70b-versatile', 'gemini' => 'gemini-2.0-flash'][$provider] ?? '';
}

/** Escape raw newlines/tabs inside JSON strings (some models emit invalid JSON). */
function ai_repair_json(string $s): string
{
    $out = ''; $inStr = false; $esc = false;
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $ch = $s[$i];
        if ($inStr) {
            if ($esc) { $out .= $ch; $esc = false; continue; }
            if ($ch === '\\') { $out .= $ch; $esc = true; continue; }
            if ($ch === '"') { $inStr = false; $out .= $ch; continue; }
            if ($ch === "\n") { $out .= '\\n'; continue; }
            if ($ch === "\r") { continue; }
            if ($ch === "\t") { $out .= '\\t'; continue; }
            $out .= $ch;
        } else {
            if ($ch === '"') $inStr = true;
            $out .= $ch;
        }
    }
    return $out;
}

/** The model that will actually be used for the given provider. */
function ai_resolved_model(string $provider): string
{
    $model = trim((string)setting('ai_model')) ?: (trim((string)setting('claude_model')) ?: '');
    if ($model === '' || ($provider !== 'claude' && str_starts_with($model, 'claude'))) $model = ai_default_model($provider);
    return $model;
}

/** Send system+user to the active provider; returns raw text or null on any failure. */
function ai_complete(string $system, string $userMsg): ?string
{
    $provider = ai_active_provider();
    if ($provider === 'local') return null;
    $key = ai_api_key();
    $model = ai_resolved_model($provider);

    if ($provider === 'claude') {
        $url = 'https://api.anthropic.com/v1/messages';
        $headers = ['content-type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01'];
        $payload = ['model' => $model, 'max_tokens' => 3000, 'system' => $system,
            'messages' => [['role' => 'user', 'content' => $userMsg]]];
        $path = fn($d) => $d['content'][0]['text'] ?? null;
    } elseif ($provider === 'groq') {
        $url = 'https://api.groq.com/openai/v1/chat/completions';
        $headers = ['content-type: application/json', 'Authorization: Bearer ' . $key];
        $payload = ['model' => $model, 'max_tokens' => 3000, 'temperature' => 0.7,
            'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $userMsg]]];
        $path = fn($d) => $d['choices'][0]['message']['content'] ?? null;
    } else { // gemini
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($key);
        $headers = ['content-type: application/json'];
        $payload = ['system_instruction' => ['parts' => [['text' => $system]]],
            'contents' => [['parts' => [['text' => $userMsg]]]],
            'generationConfig' => ['maxOutputTokens' => 3000]];
        $path = fn($d) => $d['candidates'][0]['content']['parts'][0]['text'] ?? null;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 90,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => json_encode($payload),
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false || $code >= 300) return null;
    return $path(json_decode((string)$resp, true) ?: []);
}

/* ------------------------------------------------------------------ */
/* Claude engine                                                       */
/* ------------------------------------------------------------------ */

/** Build the exact system+user prompt used by every AI provider (server or browser side). */
function upwork_build_prompt(string $job, string $budget, string $notes, array $me, array $projects, string $portfolioUrl): array
{
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
        . '"questions": [string], '
        . '"verdict": {"take": "yes"|"caution"|"no", "advice": string}, '
        . '"terms_guide": string}' . "\n"
        . "TERMS_GUIDE (Roman Urdu, for the developer): step-by-step instructions for Upwork's 'Terms / How do you want to be paid?' section. "
        . "For milestones: say select 'By milestone', then give the exact rows to type (Description = short deliverable name, Due date, Amount — matching billing.milestones), remind that Description is client-visible, keep due dates with 1-2 din buffer, NEVER start work until the milestone shows Funded/Escrow, and note Upwork's ~10% service fee (amount received will be less). "
        . "For fixed: say select 'By project', one amount + delivery date. For hourly jobs the Terms section shows only the hourly rate — tell them what rate to enter and to always use the Upwork time tracker for payment protection.\n"
        . "VERDICT (for the developer's eyes only, write advice in Roman Urdu): honestly assess if he should take this job — budget vs scope realism, client red flags (aggressive tone, 'budget is final', fired previous freelancers, unrealistic deadline, vague scope), competition level, and fit with his stack. take='yes' (achhi job, le lo), 'caution' (le sakte hain lekin in shartoon ke saath...), 'no' (waqt zaya hoga). advice = 2-4 blunt sentences with the WHY.\n"
        . "STEP 0 — CLIENT PSYCHOLOGY (do this analysis silently before writing): read the job post for what the client FEARS (getting burned by a bad freelancer, missed deadlines, poor communication, budget overruns, breaking existing code) and what they VALUE (speed, price, quality, control, simplicity). Mirror THEIR exact words/stack names. Address their biggest fear in one line. The whole letter is written for THE CLIENT's eyes — every line must answer 'what do I get?'.\n"
        . "COVER LETTER RULES (follow ALL):\n"
        . "1) SHORT: 90-130 words MAX. Busy clients skim on phones. No filler, no throat-clearing.\n"
        . "2) HUMAN, NOT AI: write like a busy senior freelancer typing a quick, confident message — contractions (I'll, you'll, that's), varied sentence lengths, no AI-sounding phrases ('I'm excited', 'I'd love to', 'exactly how I work', 'passionate', perfectly parallel bullets). One tiny imperfection of rhythm is fine.\n"
        . "3) OPENING (1-2 lines): PROFILE-ANCHORED CONFIDENCE — connect the developer's profile (title/experience/strongest relevant skill from DEVELOPER PROFILE below) directly to THIS job, in a calm, reassuring tone that makes the client relax: this is routine, well-practiced work for him (e.g. 'Cross-browser extensions in TypeScript are regular work for me — your feature list is familiar territory, nothing here is experimental.'). NEVER lecture about their problems/risks in the opening, never greetings, never resume-dumps. The client should finish line 2 feeling 'this person can clearly handle it'.\n"
        . "4) 'What you'll get:' — 3 concrete deliverable bullets tailored to THIS job (outcomes, not skills: 'a survey your visitors actually finish', not 'React experience'). This is where skills show, framed as what WE deliver to THEM.\n"
        . "5) Proof: MAX 2 live links of genuinely matching projects + the portfolio link, each with 3-5 words of context.\n"
        . "6) CLOSING MOVE (last 1-2 lines): drive toward hire with an assumptive, low-friction next step anchored in time — e.g. 'Send me the designs and you'll have a concrete plan + timeline within 24 hours.' Make replying feel like the obvious easy step. Never 'hope to hear from you'.\n"
        . "7) NO-MATCH JOBS: never mention lack of experience or 'first time' — present closest work as recent shipped work; sell the plan (no fake specific claims).\n"
        . "8) Polish: em dashes (—), expand abbreviations first mention, plain text only. If the client's post demands a specific application format, THEIR format wins over all of this.\n"
        . "Sign with the developer's first name.\n"
        . "BILLING: recommend fixed for small/clear scope, milestones for medium-large scope, hourly for vague/ongoing work. MILESTONE COUNT MUST MATCH THE BUDGET AND SCOPE — think like the client (every milestone adds funding/approval friction): under ~\$300 use MAX 2 milestones; \$300-1000 use 2-3; only \$1000+/complex projects deserve 4-5. Never micro-milestones under ~\$50 — each must be a meaningful deliverable the client can see/test. Milestone 1 modest (a plan + something working) to lower their risk; dates start a few days after today, realistically spaced; prices sum to ~the budget. reason = 2-3 sentences.\n"
        . "QUESTIONS: 2-3 smart, specific clarifying questions about their project.";

    $profileBits = array_filter([
        'Title' => trim((string)($me['uw_title'] ?? '')),
        'Experience' => trim((string)($me['uw_years'] ?? '')) !== '' ? $me['uw_years'] . '+ years' : '',
        'Top skills' => trim((string)($me['uw_skills'] ?? '')),
        'Overview' => mb_substr(trim((string)($me['uw_overview'] ?? '')), 0, 600),
    ]);
    $profileTxt = '';
    foreach ($profileBits as $k => $v) $profileTxt .= "$k: $v\n";

    $devName = trim((string)($me['uw_name'] ?? '')) ?: ($me['name'] ?: $me['username']);
    $userMsg = "TODAY: " . date('Y-m-d') . "\nDEVELOPER: " . $devName
        . ($profileTxt !== '' ? "\nDEVELOPER PROFILE (use for the confident opening):\n" . $profileTxt : '')
        . "\nPORTFOLIO URL: $portfolioUrl"
        . "\nBUDGET GIVEN BY ME: " . ($budget !== '' ? $budget : '(none — estimate sensibly)')
        . ($notes !== '' ? "\nMY NOTES: $notes" : '')
        . "\n\nMY REAL PROJECTS (JSON):\n" . json_encode($plist, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        . "\n\nJOB POST:\n" . $job;

    return ['system' => $system, 'user' => $userMsg];
}

function upwork_claude(string $job, string $budget, string $notes, array $me, array $projects, string $portfolioUrl): ?array
{
    $pp = upwork_build_prompt($job, $budget, $notes, $me, $projects, $portfolioUrl);
    $content = (string)ai_complete($pp['system'], $pp['user']);
    if ($content === '') return null;
    // Strip markdown fences some models wrap JSON in, then grab the JSON object.
    $content = preg_replace('/^```(?:json)?|```$/m', '', $content) ?? $content;
    if (preg_match('/\{.*\}/s', $content, $m)) $content = $m[0];
    $out = json_decode($content, true);
    if (!is_array($out)) $out = json_decode(ai_repair_json($content), true);
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
    $strongMatch = count($top) >= 1;
    if (count($top) < 2) $top = array_values(array_filter($scored, fn($t) => $t['s'] >= 13));
    if (count($top) < 2) {
        // No real stack match — present strongest linked work as general delivery proof
        // (confident generalist mode: the gap is never mentioned).
        $top = array_values(array_filter($scored, fn($t) => !empty($t['p']['website_url'])));
        if (!$top) $top = $scored;
    }
    $top = array_slice($top, 0, $strongMatch ? 4 : 3);

    $firstName = explode(' ', trim((string)($me['uw_name'] ?? '')) ?: trim($me['name'] ?: $me['username']))[0];

    // Human-readable stack phrase from the MAIN requirements.
    $pretty = ['react' => 'React', 'node' => 'Node.js', 'vue' => 'Vue', 'typescript' => 'TypeScript',
        'directus' => 'Directus', 'wordpress' => 'WordPress', 'php' => 'PHP', 'python' => 'Python',
        'react native' => 'React Native', 'shopify' => 'Shopify', 'mongodb' => 'MongoDB', 'mysql' => 'MySQL',
        'redux' => 'Redux', 'tailwind' => 'Tailwind', 'bootstrap' => 'Bootstrap', 'ai' => 'AI',
        'api' => 'API integrations', 'ecommerce' => 'e-commerce', 'crm' => 'CRM', 'saas' => 'SaaS', 'cloudflare' => 'Cloudflare'];
    $main = array_slice($coreOrder, 0, 3);
    $stackPhrase = $main ? implode(' + ', array_map(fn($k) => $pretty[$k] ?? $k, $main)) : 'this stack';

    // Profile-anchored confident opening: relate WHO the developer is to THIS job — calm,
    // reassuring, "this is routine work for me". No problem-lectures, no greetings.
    $uwTitle = trim((string)($me['uw_title'] ?? ''));
    $uwYears = trim((string)($me['uw_years'] ?? ''));
    $roleBit = $uwTitle !== '' ? $uwTitle : 'full-stack developer';
    $yearsBit = $uwYears !== '' ? " with {$uwYears}+ years shipping production work" : '';
    if ($stackPhrase !== 'this stack') {
        $hook = "As a {$roleBit}{$yearsBit}, {$stackPhrase} projects like this are my regular work — your scope reads clear, and nothing in it is experimental for me.";
    } else {
        $hook = "As a {$roleBit}{$yearsBit}, builds like this are familiar territory for me — your scope reads clear and very doable.";
    }

    // Deliverable bullets tailored to the job's detected requirements ("what you'll get", not "my skills").
    $deliverMap = [
        'react'      => "A fast, clean React front end your users will actually feel the difference in",
        'node'       => "A solid Node.js backend with APIs that don't break in production",
        'vue'        => "A polished Vue front end matching your designs exactly",
        'typescript' => "Fully typed code — fewer bugs now, cheaper changes later",
        'wordpress'  => "WordPress work that's upgrade-safe — no plugin hacks that break later",
        'php'        => "Clean PHP that respects your existing codebase — review first, never blind rewrites",
        'python'     => "Reliable Python with proper error handling and tests",
        'shopify'    => "Store changes that survive theme updates",
        'api'        => "API integrations mapped and tested end to end",
        'ecommerce'  => "A checkout flow that converts instead of leaking customers",
        'ai'         => "AI features with guardrails — useful output, no embarrassing responses",
        'crm'        => "A CRM setup your team will actually use daily",
        'mysql'      => "A database layer that stays fast as your data grows",
        'mongodb'    => "A database layer that stays fast as your data grows",
    ];
    $bullets = [];
    foreach ($coreOrder as $k) {
        if (isset($deliverMap[$k]) && !in_array($deliverMap[$k], $bullets, true)) $bullets[] = $deliverMap[$k];
        if (count($bullets) >= 2) break;
    }
    $bullets[] = "Daily updates, small tested deliveries — you always know exactly where things stand";

    $lines = [];
    $lines[] = $hook;
    $lines[] = "";
    $lines[] = "What you'll get:";
    foreach ($bullets as $b) $lines[] = "• " . $b;
    $lines[] = "";
    $lines[] = "Recent work (live):";
    $rel = [];
    foreach (array_slice($top, 0, 2) as $t) {
        $p = $t['p'];
        $url = $p['website_url'] ?: $portfolioUrl;
        $mainTech = trim(explode(',', (string)$p['technologies'])[0] ?? '');
        $lines[] = "• {$url}" . ($mainTech ? " ({$mainTech})" : '');
        $rel[] = ['name' => $p['name'], 'url' => $url];
    }
    $lines[] = "• More: " . $portfolioUrl;
    $lines[] = "";
    if ($notes !== '') { $lines[] = "Note: " . $notes; $lines[] = ""; }
    // Closing move: assumptive, time-anchored next step.
    $lines[] = "Send over your " . ($strongMatch ? "scope/designs" : "details") . " and I'll have a concrete plan with timeline back to you within 24 hours — you'll know fast if I'm the right fit.";
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
        // Milestone count scales with budget — micro-milestones on small jobs look amateur
        // and add funding/approval friction for the client.
        if ($base < 300) {
            $cuts = [
                ['Kickoff: plan + first working piece (demo)', 0.30, 6],
                ['Complete build, testing & handover', 0.70, 18],
            ];
        } elseif ($base < 1000) {
            $cuts = [
                ['Kickoff: plan + first working feature', 0.20, 5],
                ['Core build', 0.50, 14],
                ['Testing, polish & handover', 0.30, 21],
            ];
        } else {
            $cuts = [
                ['Kickoff: code/scope review + architecture plan', 0.12, 4],
                ['Core features development', 0.45, 13],
                ['Integrations, testing & polish', 0.28, 20],
                ['Deployment, handover & documentation', 0.15, 26],
            ];
        }
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

    // ---- Verdict: should you take this job? (advice in Roman Urdu, for the developer) ----
    $flags = [];
    if ($amount > 0 && $amount < 150 && $wordCount > 150) $flags[] = 'budget scope ke muqable bahut kam hai';
    if (preg_match('/budget is final|non-negotiable|do not apply if/i', $job)) $flags[] = 'client ne budget lock kar diya hai (negotiate nahi hoga)';
    if (preg_match('/fired|wasting|waste your connects|no exceptions|disqualified/i', $job)) $flags[] = 'client ka lehja sakht/aggressive hai';
    if (preg_match('/(\d+)\s*(?:calendar\s*)?days?\s*total/i', $job, $dm) && (int)$dm[1] <= 7 && $wordCount > 150) $flags[] = 'deadline bahut tight hai (' . $dm[1] . ' din)';
    $capsWords = preg_match_all('/\b[A-Z]{4,}\b/', $job);
    if ($capsWords >= 8) $flags[] = 'zyada CAPS = demanding client ka pattern';

    if (count($flags) >= 3) {
        $take = 'no';
        $advice = 'Meri raaye: chhor dein. ' . ucfirst(implode('; ', array_slice($flags, 0, 3))) . '. Itni mehnat ka sahi rate milna mushkil hai — connects kisi behtar job par lagayein. Sirf tab lein agar naya review/portfolio hi maqsad ho.';
    } elseif (count($flags) >= 1 || !$strongMatch) {
        $take = 'caution';
        $advice = 'Le sakte hain, lekin soch kar: ' . implode('; ', $flags ?: ['stack aap ki core strength se thoda hat ke hai']) . '. Lein to scope pehle din likh kar lock karein aur pehla chhota milestone zaroor rakhein.';
    } else {
        $take = 'yes';
        $advice = 'Achhi fit hai — stack aap ke projects se seedha match karta hai aur koi bara red flag nahi. Jaldi apply karein (pehle 10 proposals mein hone se reply-rate kaafi barh jata hai).';
    }

    // ---- Upwork "Terms" section — exact fill-in guide (Roman Urdu) ----
    if ($mode === 'milestones' && $milestones) {
        $tg = "Upwork ke Terms section mein 'By milestone' select karein. Phir " . count($milestones) . " rows add karein aur bilkul aise bharein:\n";
        foreach ($milestones as $i => $m) {
            $tg .= ($i + 1) . ") Description: \"{$m['name']}\"  |  Due date: {$m['date']}  |  Amount: {$m['price']}\n";
        }
        $tg .= "\nYaad rakhein:\n";
        $tg .= "• Description client ko dikhta hai — chhota aur deliverable-style rakhein (upar wale copy kar lein)\n";
        $tg .= "• Due dates mein 1-2 din ka buffer already rakha hai — late hone par client ko pehle bata dein\n";
        $tg .= "• Kaam SIRF tab shuru karein jab pehla milestone 'Funded' (escrow) dikhaye — bina funding kaam = free kaam ka risk\n";
        $tg .= "• Upwork ~10% service fee kaat-ta hai — haath mein amount thora kam aayega\n";
        $tg .= "• Har milestone complete hone par 'Submit for payment' karein — approval ya 14 din mein payment release ho jati hai";
    } elseif ($mode === 'fixed') {
        $amt = $amount > 0 ? '$' . number_format($amount, 0) : '(apna quote)';
        $tg = "Upwork ke Terms section mein 'By project' select karein.\n";
        $tg .= "• Amount: {$amt} (poora ek saath, kaam mukammal hone par release hoga)\n";
        $tg .= "• Delivery date: aaj se scope ke hisaab se 1-2 din buffer ke saath rakhein\n";
        $tg .= "• Kaam shuru karne se pehle confirm karein ke poora amount escrow mein Funded hai\n";
        $tg .= "• Chhoti fixed jobs par bhi deliverables chat mein likh kar confirm karwa lein — 'scope creep' se bachein";
    } else {
        $rate = $amount > 0 ? '$' . number_format($amount, 0) . '/hr' : 'apna hourly rate';
        $tg = "Ye job hourly hai — Terms section mein milestone wala hissa nahi aayega, sirf hourly rate poocha jayega.\n";
        $tg .= "• Rate: {$rate} likhein (Upwork fee ke baad ~10% kam milega, isko soch kar rate rakhein)\n";
        $tg .= "• Hamesha Upwork Desktop Time Tracker se kaam karein — tabhi Hourly Payment Protection milti hai\n";
        $tg .= "• Weekly hours cap client ne set kiya ho to us ke andar rahein; extra hours pehle poochh kar";
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
        'verdict' => ['take' => $take, 'advice' => $advice],
        'terms_guide' => $tg,
    ];
}
