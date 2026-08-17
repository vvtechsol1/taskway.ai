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
        'webflow' => 'Webflow', 'wix' => 'Wix', 'squarespace' => 'Squarespace', 'framer' => 'Framer', 'bubble' => 'Bubble', 'elementor' => 'Elementor', 'flutter' => 'Flutter', 'automation' => 'Automation (n8n/Zapier)', 'figma' => 'Figma', 'seo' => 'SEO', 'firebase' => 'Firebase/Supabase', 'stripe' => 'Stripe/Payments', 'api' => 'API Integrations', 'ecommerce' => 'E-commerce', 'crm' => 'CRM', 'saas' => 'SaaS', 'cloudflare' => 'Cloudflare'];
    $reqs = upwork_job_requirements(mb_strtolower($job));
    return array_values(array_map(fn($k) => $pretty[$k] ?? ucfirst($k), array_keys($reqs)));
}

/** User-fed AI trainer rules (Upwork page → Feed box) — applied to every generation. */
function upwork_custom_rules(int $uid): array
{
    try {
        $stmt = db()->prepare('SELECT rule FROM upwork_rules WHERE user_id = ? ORDER BY id');
        $stmt->execute([$uid]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Deeply rank the developer's projects against THIS job post.
 * Tech-stack matches weigh far more than description mentions; earlier (core)
 * job requirements weigh more than later ones. Returns [['p'=>project,'s'=>score],...]
 * sorted best-first — shared by the AI prompt builder and the offline engine so
 * every engine picks genuinely relevant proof links instead of the first rows.
 */
function upwork_rank_projects(string $job, array $projects): array
{
    $reqs = upwork_job_requirements(mb_strtolower($job));

    // PLATFORM GATE: when the job names a stack-defining platform/framework, a project
    // NOT on that platform can never rank above "weak" — a shared secondary tool in a
    // different stack is not real relevance (a React app using Stripe is NOT proof for
    // a WordPress/Amelia job). Aliases here are matched against the PROJECT's tech list.
    $stackFamilies = [
        'wordpress'    => ['wordpress', 'woocommerce', 'elementor'],
        'elementor'    => ['wordpress', 'woocommerce', 'elementor'],
        'shopify'      => ['shopify', 'liquid'],
        'wix'          => ['wix', 'velo'],
        'squarespace'  => ['squarespace'],
        'webflow'      => ['webflow'],
        'framer'       => ['framer'],
        'bubble'       => ['bubble'],
        'flutter'      => ['flutter'],
        'react native' => ['react native'],
        'react'        => ['react', 'next'],
        'vue'          => ['vue', 'nuxt'],
        'node'         => ['node', 'express', 'nest'],
        'php'          => ['php', 'laravel'],
        'python'       => ['python', 'django', 'flask'],
    ];
    $requiredFamilies = array_intersect_key($stackFamilies, $reqs);

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
        if ($requiredFamilies) {
            $onPlatform = false;
            foreach ($requiredFamilies as $aliases) {
                foreach ($aliases as $a) {
                    if ($tech !== '' && mb_strpos($tech, $a) !== false) { $onPlatform = true; break 2; }
                }
            }
            if (!$onPlatform) $score = min($score, 12);   // capped below "partial" → never shown as proof
        }
        $scored[] = ['p' => $p, 's' => $score];
    }
    usort($scored, fn($a, $b) => $b['s'] <=> $a['s']);
    return $scored;
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
    return ['claude' => 'claude-sonnet-5', 'groq' => 'openai/gpt-oss-120b', 'gemini' => 'gemini-2.0-flash'][$provider] ?? '';
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
        $payload = ['model' => $model, 'max_tokens' => 2200, 'temperature' => 0.4,
            'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $userMsg]]];
        // gpt-oss models burn tokens on hidden reasoning — keep it minimal so the JSON isn't truncated.
        if (stripos($model, 'gpt-oss') !== false) $payload['reasoning_effort'] = 'low';
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
    // Groq gpt-oss free tier is 8k tokens/min — on "request too large"/rate-limit,
    // retry once on llama (12k TPM) instead of dropping all the way to the offline engine.
    if ($provider === 'groq' && in_array($code, [413, 429], true) && stripos($model, 'gpt-oss') !== false) {
        $payload['model'] = 'llama-3.3-70b-versatile';
        unset($payload['reasoning_effort']);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 90,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => json_encode($payload),
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    }
    if ($resp === false || $code >= 300) return null;
    return $path(json_decode((string)$resp, true) ?: []);
}

/**
 * Build the prompt for a suggested reply to a client message in the Upwork Jobs
 * conversation. Reads client psychology first, answers like a real human.
 */
function uw_reply_build_prompt(array $job, array $messages, array $me): array
{
    $firstName = explode(' ', trim((string)($me['uw_name'] ?? '')) ?: trim((string)($me['name'] ?? 'Dev')))[0];

    $system = "You are {$firstName}, a real human freelancer replying to a client in Upwork chat. "
        . "STEP 0 — CLIENT PSYCHOLOGY (analyze silently before writing): from their latest message and the whole thread, work out what they REALLY want, what they fear (getting burned, delays, hidden costs, poor communication), their mood (excited / skeptical / annoyed / in a hurry / just checking), and every explicit or implied question they asked.\n"
        . "Then write ONE reply exactly like a real person typing in chat:\n"
        . "- Clear, simple English. Warm and confident, never stiff. Use contractions (I'll, that's, you're).\n"
        . "- Answer EVERY question they asked, directly and specifically — no dodging, no vagueness.\n"
        . "- If they raised a concern or objection, address it head-on FIRST, honestly.\n"
        . "- Mirror their words and tone; match their message length — short client message = short reply (2-5 sentences), detailed message = structured but tight reply.\n"
        . "- Sound human, never AI: no 'I hope this message finds you well', no over-formal openers, no bullet-point essays unless they asked for details, no exclamation spam. One natural greeting like 'Hi [name],' only if the thread is new-ish; otherwise jump straight in.\n"
        . "- Be honest — never over-promise; give realistic timelines/answers grounded in the job context below.\n"
        . "- ALWAYS end by moving the deal forward: a concrete next step, a specific question, or a confirmation ('I can start on X tomorrow — should I?').\n"
        . "- Never mention being new or lacking experience. Never invent facts not in the context.\n"
        . "- PLAIN CHAT TEXT ONLY — absolutely NO markdown or formatting symbols: no asterisks, no **bold**, no bullet points, no numbered lists, no headings, no backticks, no em dashes (—). Just normal sentences and commas, like a human typing in Upwork chat on their phone. If you need to list 2-3 things, write them naturally in a sentence ('the price covers the launch, hosting setup and two rounds of tweaks').\n"
        . "Output ONLY the reply text — no quotes, no labels, no explanations.";

    $convo = '';
    foreach ($messages as $m) {
        $convo .= ($m['sender'] === 'me' ? 'ME' : 'CLIENT') . ": " . $m['body'] . "\n---\n";
    }

    $profileBits = array_filter([
        'My title' => trim((string)($me['uw_title'] ?? '')),
        'Experience' => trim((string)($me['uw_years'] ?? '')) !== '' ? $me['uw_years'] . '+ years' : '',
        'Skills' => trim((string)($me['uw_skills'] ?? '')),
        'Company' => trim((string)($me['portfolio_headline'] ?? '')),
    ]);
    $profileTxt = '';
    foreach ($profileBits as $k => $v) $profileTxt .= "$k: $v\n";

    $userMsg = "MY PROFILE:\n" . $profileTxt
        . "\nJOB TITLE: " . $job['title']
        . "\nJOB SUMMARY:\n" . mb_substr((string)$job['summary'], 0, 1500)
        . "\n\nPROPOSAL I SENT:\n" . mb_substr((string)$job['proposal'], 0, 1500)
        . "\n\nCONVERSATION SO FAR (oldest first, the last CLIENT message is what I need to answer):\n" . mb_substr($convo, -4000);

    // User-fed AI trainer rules apply here too.
    $fedRules = upwork_custom_rules((int)($me['id'] ?? 0));
    if ($fedRules) {
        $system .= "\nMY COMMUNICATION RULES (highest priority):\n";
        $used = 0;
        foreach ($fedRules as $i => $r) {
            $r = trim($r);
            if ($used + mb_strlen($r) > 1500) break;
            $system .= ($i + 1) . '. ' . $r . "\n";
            $used += mb_strlen($r);
        }
    }
    return ['system' => $system, 'user' => $userMsg];
}

/* ------------------------------------------------------------------ */
/* Claude engine                                                       */
/* ------------------------------------------------------------------ */

/** Build the exact system+user prompt used by every AI provider (server or browser side). */
function upwork_build_prompt(string $job, string $budget, string $notes, array $me, array $projects, string $portfolioUrl): array
{
    // Pre-rank projects against THIS job so the model can't lazily grab the first rows.
    // Keep the list lean — Groq's free tier is ~8k tokens/minute.
    $ranked = array_slice(upwork_rank_projects($job, $projects), 0, 8);
    $plist = array_map(fn($t) => [
        'name' => $t['p']['name'],
        'about' => mb_substr((string)$t['p']['description'], 0, 240),
        'tech' => $t['p']['technologies'],
        'live_url' => $t['p']['website_url'],
        'relevance_to_this_job' => $t['s'] >= 40 ? 'STRONG match' : ($t['s'] >= 13 ? 'partial match' : 'weak/unrelated — do NOT use as proof'),
    ], $ranked);

    $system = "You are an expert Upwork proposal writer for a senior full-stack developer. Core rule: make it about the CLIENT'S PROBLEM, not the developer's resume — position him as a problem-solver, not another contractor. "
        . "Given a job post, the developer's real projects, and an optional budget, return ONLY valid JSON:\n"
        . '{"cover_letter": string, "relevant_projects": [{"name": string, "url": string}], '
        . '"billing": {"mode": "fixed"|"milestones"|"hourly", "reason": string, '
        . '"milestones": [{"name": string, "date": "YYYY-MM-DD", "price": string}]}, '
        . '"questions": [string], '
        . '"client_answers": [{"question": string, "answer": string}], '
        . '"verdict": {"take": "yes"|"caution"|"no", "advice": string}, '
        . '"terms_guide": string}' . "\n"
        . "CLIENT_ANSWERS: if the job post asks the applicant any questions or screening items (e.g. 'your years of experience', 'share three live apps', 'how would you approach X', 'confirm the budget'), extract EACH one and write an authentic, fully personalized answer using the developer's real profile and projects — specific, confident, no fake claims, 1-3 sentences each (links where asked). If the post asks nothing, return an empty array. MANDATORY IN-LETTER ANSWERS: if the post says applicants MUST answer questions to apply ('answer these questions briefly', 'generic proposals will be skipped'), ALSO answer them INSIDE cover_letter as a numbered 'Answers to your questions:' section right after the approach bullets — the client's required format ALWAYS beats the standard skeleton. Answer each directly and confidently; NEVER ask the client's own question back at them (if they ask 'what tools would you use?', state your tools: 'I use Screaming Frog for baseline/final crawls, Google Search Console, Rich Results Test for schema, and PageSpeed Insights/Lighthouse').\n"
        . "TERMS_GUIDE (clear simple English, for the developer): step-by-step instructions for Upwork's 'Terms / How do you want to be paid?' section. "
        . "For milestones: say select 'By milestone', then give the exact rows to type (Description = short deliverable name, Due date, Amount — matching billing.milestones), remind that Description is client-visible, keep due dates with 1-2 din buffer, NEVER start work until the milestone shows Funded/Escrow, and note Upwork's ~10% service fee (amount received will be less). "
        . "For fixed: say select 'By project', one amount + delivery date. For hourly jobs the Terms section shows only the hourly rate — tell them what rate to enter and to always use the Upwork time tracker for payment protection.\n"
        . "VERDICT (for the developer's eyes only, write advice in clear simple English): honestly assess if he should take this job — budget vs scope realism, client red flags (aggressive tone, 'budget is final', fired previous freelancers, unrealistic deadline, vague scope), competition level, and fit with his stack. take='yes' (good job, take it), 'caution' (can take it but only under conditions), 'no' (waste of time). IMPORTANT CONTEXT: the developer is NEW on Upwork — the current goal is WINNING projects and building reviews/level, not maximizing rate. Be LENIENT on budget: a modest or even low budget alone is fine (lean 'yes', or 'caution' at worst) as long as the scope is manageable. Reserve 'no' (red) for EXTREME mismatch only — a really tiny budget against a really large/months-long scope — or serious client red flags (aggressive tone, fired freelancers, impossible demands). advice = 2-4 blunt sentences with the WHY.\n"
        . "RELEVANT PROJECT SELECTION (strict tiers — deep matching, never lazy): the developer's projects arrive PRE-RANKED for THIS job — best match first, each with a 'relevance_to_this_job' label. Before choosing proof links, deeply compare each project's tech and about against the job's actual requirements, then apply these tiers per project: directly similar project → definitely include; same platform/technology and reasonably related → include; only loosely related → leave it out; completely unrelated → NEVER include. If NO genuinely relevant project exists, OMIT the proof-links section entirely (relevant_projects = [], no links in the letter — keep just the capability sentence in THIS job's stack): showing an irrelevant project is WORSE than showing none. NEVER the same default pair on every proposal — different job → different links. NEVER fabricate similarity: a React application that happens to use Stripe is NOT WordPress/Amelia experience — never present tech used in one context as experience in another context. INDUSTRY/NICHE relevance also counts (construction job → the construction-related project). DESCRIBE EACH LINK FOR THIS JOB: the 4-6 word context must highlight the aspect relevant to THIS job (for an SEO job: 'structured content, clean page hierarchy, search-friendly implementation' — NOT 'property listings and search functionality'); never claim work you didn't actually do on that project. If the client asks for ONE example, give exactly ONE strongest — not a list.\n"
        . "REALISM — NO FABRICATION (hard rule): every factual claim must come from the provided profile and projects. Never invent client names, user counts, revenue, team sizes, years, or 'I built exactly this same platform before'. Where a specific isn't in the data, speak in shipped capabilities ('I've built production apps with X and Y') — never fake specifics. Experience claims must be literally true from the provided data: write 'I've integrated [tool] before' ONLY if a listed project actually shows that tool — otherwise say you'll review its docs/API and integrate it cleanly (honest confidence, no fake history). Overpromises are banned: no 'guaranteed', '100% perfect', 'in 2 days' on big scopes. Realistic, checkable commitments only — one believable claim beats three inflated ones.\n"
        . "JOB ANALYSIS (do this FIRST, silently, before any writing — NEVER generate from skill keywords alone, read the COMPLETE job description): from the post identify (a) the EXACT ROLE being hired for and the MAIN PLATFORM — WordPress, Shopify, Wix, Webflow, custom development, etc.; (b) the required stack — PHP, React, Next.js, Laravel, Python, Node.js, etc.; (c) the specific tools/plugins the client names — Elementor, WooCommerce, Amelia, Stripe, WPML, specific APIs — including the exact payment functionality required, if any; (d) the client's ACTUAL/current problem and expected deliverable; (e) whether this is an EXISTING project or a NEW build; (f) budget, deadline and any special instructions; (g) SCREENING QUESTIONS — scan the post FIRST for any questions or application requirements the client asks applicants to answer ('to apply, answer…', numbered questions, 'include X in your proposal'). These are MANDATORY: they must ALL be answered — every single one, never skipped — inside the cover letter as a numbered section AND in client_answers, before anything else is finalized; a proposal that ignores them gets skipped by the client no matter how good the rest is. The whole proposal must live inside THAT world.\n"
        . "PLATFORM RELEVANCE (hard rule): mention ONLY technologies directly relevant to THIS job — NEVER auto-mention React/Next.js or the developer's favorite stack. WordPress job → the proposal talks WordPress, PHP, theme/plugin customization, hooks/APIs, WooCommerce/Elementor where relevant. Shopify job → Shopify, Liquid, apps, theme customization. Wix job → Wix/Wix Studio/Velo and the required Wix workflow. React/Next.js job → React, Next.js, TypeScript, APIs, state management. Python/AI/automation job → Python, APIs, LLMs, n8n, webhooks, automation tools. Use the CLIENT'S terminology: if they keep saying 'Amelia', 'Stripe subscriptions', 'Google Calendar', 'existing booking forms' — those exact requirements must be naturally addressed in their words. ABSOLUTE BAN: no technology/framework name may appear ANYWHERE in the letter (including the 'Why I'm a good fit' sentence) unless the job post itself asks for it — on a WordPress+Amelia job the words 'React' or 'Next.js' must not appear at all. For integration jobs phrase capability in the JOB's world, e.g.: 'I have experience working with WordPress, third-party plugin integrations, payment workflows, and existing systems where new functionality needs to be added without disrupting the current setup.' NO CROSS-PROPOSAL REUSE: never carry over approach bullets, timeline phrasing, portfolio descriptions, or the closing question from a previous proposal — every one of these is regenerated from THIS job's content; if a sentence would fit another job unchanged, rewrite it.\n"
        . "EXISTING vs NEW PROJECT (changes the entire voice): EXISTING system → audit, understand, integrate, fix, maintain, PRESERVE — never propose to 'build an MVP' for a production platform that already exists, and explicitly protect whatever the client says they want to keep (the existing form's look, current Stripe subscription behavior, live data). NEW project → architecture, build, implement, launch. NEVER assume unknown implementation details: not 'I will map your existing form fields directly to X' but 'I'll first review the existing implementation and determine the cleanest way to integrate it.' No exact promises that would require inspecting their system first. GUARANTEES ABOUT UNSEEN SYSTEMS ARE BANNED: never 'works exactly as before', 'no visual changes needed', 'unified calendars' — commit to a PRIORITY plus TESTING instead ('My priority will be to preserve the existing Stripe subscription behavior while integrating Amelia, with full end-to-end testing before changes reach the second site', 'with the goal of keeping the existing customer-facing design unchanged', 'verify that bookings sync correctly with the required Google Calendar configuration on each site'). RESTATE THEIR ASK PRECISELY: if they ALREADY process Stripe subscriptions, say 'integrate X while preserving the existing Stripe payment and recurring subscription flow' — never 'link them to Stripe' as if payments were new. VAGUE/ASSUMPTIVE PHRASES BANNED: 'necessary connections', 'handled as they are now', 'linking the forms to it' — be precise instead: 'configure Google Calendar synchronization and verify that bookings and schedule updates sync correctly', 'while preserving the existing recurring subscription flow', 'integrate it with the existing booking workflow while keeping the customer-facing design unchanged'.\n"
        . "STEP 0 — CLIENT PSYCHOLOGY (do this analysis silently before writing): read the job post for what the client FEARS (getting burned by a bad freelancer, missed deadlines, poor communication, budget overruns, breaking existing code) and what they VALUE (speed, price, quality, control, simplicity). Mirror THEIR exact words/stack names. Address their biggest fear in one line. The whole letter is written for THE CLIENT's eyes — every line must answer 'what do I get?'.\n"
        . "COVER LETTER RULES (follow ALL):\n"
        . "1) LENGTH: 150-220 words (never over 250). Don't waste words. Simple English. Mention THEIR business/product by name. Confident, not overconfident.\n"
        . "1b) BANNED PHRASES (never use): 'I am new but', 'Please give me one chance', 'I can do this perfectly', 'Dear Hiring Manager', any greeting salutation, long life story, generic copy-paste lines.\n"
        . "2) HUMAN, NOT AI: write like a busy senior freelancer typing a quick, confident message — contractions (I'll, you'll, that's), varied sentence lengths, no AI-sounding phrases ('I'm excited', 'I'd love to', 'exactly how I work', 'passionate', perfectly parallel bullets). One tiny imperfection of rhythm is fine.\n"
        . "2b) DEEP PERSONALIZATION (most important rule): clients receive 20-50 proposals — yours must feel written ONLY for them. Every sentence must reference THEIR project. Test: if a sentence could be pasted unchanged into another proposal, rewrite it. NEVER open with 'I've worked on several...' or any developer-background line.\n"
        . "3) OPENING (2-3 lines): START WITH THE CLIENT'S PROJECT, not the developer — \"I've reviewed your project requirements, and I understand you're looking for [SPECIFIC detailed restatement: what they're building, from what starting point, with which exact features/constraints — their own words].\" Then name their GOAL as a benefit ('Your goal is a modern, SEO-friendly site that not only looks professional but also attracts more organic traffic.'). Then ONE commitment line in natural wording — 'I'll ensure the website is scalable, easy to manage, and maintainable while maintaining your existing brand identity.' If the restatement is long, BREAK IT INTO TWO SHORTER SENTENCES; name the client's project (e.g. 'Leadsvora') and mirror their exact feature keywords (dashboard, CRM, webhooks, Docker — whatever THEY wrote). NATURAL ENGLISH ONLY: 'I've reviewed' not 'I reviewed', 'maintaining' not 'preserving', 'your requirements are clear' never 'your scope reads clear', never odd phrases like 'fully independent'. Never arrogant ('nothing is experimental for me' is banned). Avoid 'I'll make sure' — commit concretely: 'I'll build a [workflow/site] that's reliable, scalable, and easy to maintain as your [volume/business] grows.' NO REPETITION IN THE OPENING: the restatement, goal and commitment must each add NEW information — if two of them would say the same thing (e.g. 'integrate Amelia + preserve the existing flow' twice), MERGE them; ideal compact form for EXISTING-system jobs: one restatement sentence + 'My focus will be to integrate [tool] without disrupting the existing [exactly what they want preserved — booking experience, Stripe subscriptions, current form design].' Generic guarantee wording like 'I'll ensure a seamless integration' is banned — instead 'My focus will be on a reliable integration with thorough end-to-end testing.'\n"
        . "4) APPROACH BULLETS — BENEFIT-DRIVEN, never bare tasks: each bullet = concrete action + the benefit the client gets ('Optimize page speed, Core Web Vitals and on-page SEO to improve search visibility and organic traffic' — NOT 'Implement SEO strategies'). 2-4 strong bullets covering THEIR full flow end-to-end, and weave in the trust builders relevant to THIS job: fast loading speed / Core Web Vitals, clean maintainable code, cross-browser & cross-device experience, security, future scalability, post-launch support. Clients hire on these. Bullets must read COMPLETE and end-to-end ('Build an end-to-end n8n workflow that monitors Google Sheets, initiates AI voice calls through Retell AI and Twilio, and automatically updates lead statuses'). NEVER assume tech the client didn't mention and NEVER add unnecessary technical features just to sound impressive (no CI/CD, Docker, automated testing, Tailwind, CSS modules or specific libraries unless the client asked); NEVER promise unverifiable numbers (specific Lighthouse scores); for UI work always include a cross-device/cross-browser testing bullet. Prefer professional wording ('AI conversation flows' over 'conversation logic'). VARY the benefit phrasing — never end every bullet with the same 'you'll get / you'll have / you'll gain' template, that reads AI-generated; some bullets carry the benefit inside the action naturally. PRIORITIZE what the client actually fears: for risky integrations on live systems, an end-to-end TESTING bullet that NAMES the client's actual flows ('Test the complete flow end-to-end — bookings, Stripe payments/subscriptions, Google Calendar sync, notifications, and the existing front-end experience — before applying the proven setup to the second website'), never a generic 'thorough testing'; this beats a documentation/handover bullet — only add handover/docs when bullets are spare or the client asked. COVER THE FULL LISTED SCOPE: when the post lists many scope items (FAQ schema, placeholder cleanup, heading hierarchy, sitemap/robots, before/after crawl…), bullets must cover the MAJOR GROUPS — not just the first three items — including explicit deliverables (baseline + final crawl with a change log) and special constraints (private address stays hidden). Bullets say HOW you'll do the work, never a restatement of the client's own job description ('Improve the look and feel, spacing, typography…' is THEIR text — yours is 'Audit the existing pages in [their builder] to identify inconsistencies in spacing, typography, colors and responsive behavior'). When the client names a builder/tool ('all changes directly in Oxygen Builder'), the bullets must say the work happens IN that tool.\n"
        . "5) Proof: CLAIM FIRST, THEN LINKS — one CLIENT-FOCUSED, results-oriented sentence ('I've built high-quality React/Next.js websites that are responsive, SEO-friendly and fast-loading — my focus is websites that not only look great but also generate results.'), then the line 'Here are a few relevant projects:' (never 'Some recent work') followed by 2-3 live project links as evidence, EACH with a short what-it-is description ('CRM Copilot — AI-powered CRM automation platform', never just the language name). ADAPT the focus line to the job type: UI/clone job → 'My focus is on building pixel-perfect, responsive interfaces with clean, maintainable code'; automation/backend → 'My focus is building production-ready automation systems that businesses can rely on.' NEVER include the portfolio link in the letter — live project links only. If no genuinely relevant project passed the selection tiers above, keep ONLY the capability sentence (phrased in THIS job's platform/stack) and skip the 'Here are a few relevant projects:' line and links completely.\n"
        . "6) CLOSING — engaging, conversational: 'Before we get started, I'd love to know: [ONE meaningful, JOB-SPECIFIC question about a concrete detail from THEIR post — brand guidelines, existing auth, data source]?' — never generic; ask THE most important unknown, the one whose answer determines the integration architecture (e.g. 'What plugin or custom setup currently handles the booking forms and recurring Stripe subscriptions?') — NOT a secondary config detail (same Stripe account, product IDs) that matters only later, and never a question that assumes internals you can't know ('product IDs'). Questions about THEIR specifics dramatically increase reply rates. Optionally pair with a time-anchored offer (concrete plan within 24 hours). Never bare 'Let's discuss', never a dry 'One question:' — use 'One quick question: …?' and where natural, let the question open extra work ('…or would you like me to help with the initial setup as well?'). Sign off with 'Looking forward to hearing from you.' then 'Thanks, [First name]'.\n"
        . "6b) TIMELINE — SHORT AND REALISTIC (long timelines lose jobs): standard website/landing page = 'first version within 1-2 weeks', small task = days, medium app = 2-4 weeks. NEVER say months unless the client explicitly wants a long-term engagement. Format: 'I can start immediately and deliver the first version within [X], depending on the project scope and your feedback.' For EXISTING-system work, never promise exact per-phase splits ('first site in 3 days, second in 4') before seeing the implementation — give ONE realistic range tied to inspection: 'I can start immediately and target both sites within 5-7 days, subject to the existing setup and testing.' CLIENT-DEFINED SCHEDULE WINS: if the client gave their own timeline/sequence (e.g. a 14-day plan with audit → implementation → QA), ADOPT it — 'Your 14-day schedule works for me, and I can follow the [their sequence] you've outlined' — never counter with 'first version within 1-2 weeks' and never add 'depending on the project scope' when they already defined the scope.\n"
        . "6c) STATED BUDGET: if the post states an explicit budget — especially with a condition ('the advertised budget must cover both websites') — acknowledge acceptance in ONE short line right after the timeline ('I'm comfortable with the advertised \$700 fixed budget for both websites.'). It instantly removes a major client concern. Never negotiate the budget inside the letter.\n"
        . "7) NO-MATCH JOBS: never mention lack of experience or 'first time' — sell the plan and the process with confidence (no fake specific claims), and per the selection tiers above OMIT portfolio links rather than showing unrelated work.\n"
        . "8) Polish: em dashes (—), expand abbreviations first mention, plain text only. If the client's post demands a specific application format, THEIR format wins over all of this.\n"
        . "9) EXACT OUTPUT SKELETON for cover_letter — the WINNING FORMULA (real newline characters, follow this sequence):\n"
        . "Hi [client's first name if it appears in the post, else just 'Hi,']\n"
        . "I've reviewed your project requirements, and I understand you're looking for [SPECIFIC restatement of THEIR project — what, from what starting point, which exact features/constraints, their words]. [Their GOAL as a benefit sentence.] [One commitment line — scalable, easy to manage, maintainable, maintaining their brand/structure.]\n[blank line]\n"
        . "Here's how I'd approach it:\n• [benefit-driven step personalized to their project — action + what the client gains]\n• [benefit-driven step covering THEIR flow end-to-end, daily updates]\n• [optional third: performance/clean code/scalability trust builder phrased for THIS job]\n[blank line]\n"
        . "Why I'm a good fit:   [this heading line is MANDATORY — never omit it, even when the section is one short paragraph with no links]\nI've built high-quality [THIS job's platform/stack] [websites/applications] that are [the exact qualities THIS job needs] — my focus is work that not only looks great but also generates results. [For EXISTING-system/integration jobs use instead: 'I have experience working with [job's platform], third-party plugin integrations, payment workflows, APIs, and existing systems where new functionality needs to be introduced without disrupting the current user experience. For this project, my priority would be to understand the existing [their setup] first, then integrate [their tool] safely and test the complete flow before [their rollout step].' NEVER RECYCLE this section from a different job category — regenerate it every time from THIS job's role + platform + primary tasks: an SEO-cleanup job gets SEO/content/structure experience, a design job gets UI/UX + responsive + visual-consistency experience — never 'payments, APIs, plugin integrations' leftovers from a previous integration job.] Here are a few relevant projects:\n• [live url 1] — [4-6 word context]\n• [live url 2] — [4-6 word context]\n(links + the 'Here are a few relevant projects:' line ONLY if genuinely relevant projects passed the selection tiers — otherwise end this section after the capability sentence)\n[blank line]\n"
        . "Timeline: I can start immediately and deliver [NEW build → 'the initial working version'; EXISTING system → 'the working integration/fix' — NEVER 'MVP' for an existing production platform] within [short realistic estimate — 1-2 weeks for websites, days for small tasks; never months unless client wants long-term], depending on [if the client already defined the scope clearly: 'the existing [booking/subscription] implementation and testing requirements'; otherwise: 'the project scope and your feedback']. [If the post states an explicit budget: 'I'm comfortable with the advertised \$X budget for [their stated scope].']\n[blank line]\n"
        . "One quick question: [meaningful question about a concrete detail from THEIR post — where natural, one that opens extra work]? [Optional: time-anchored offer — send X and you'll have a concrete plan within 24 hours.]\n[blank line]\n"
        . "[Optional: 'Looking forward to hearing from you.' — DROP this line whenever the letter is running long; the closing question is already the CTA.]\n[blank line]\nThanks,\n[First name]\n"
        . "Sign with the developer's first name (from uw_name if present).\n"
        . "10) FINAL SELF-CHECK (run silently on the finished letter, fix every failure before output): (a) does ANY technology name appear that the job post didn't ask for? → remove/rephrase in the job's platform terms; (b) does every included link pass the relevance tiers (no 'weak/unrelated' project)? → drop it, or drop the whole links section; (c) any guarantee about a system you haven't seen ('exactly as before', 'no visual changes needed', 'unified')? → soften to priority + testing; (d) any invented implementation detail ('map form fields', 'product IDs')? → rewrite as review-first; (e) is the closing question THE architecture-deciding unknown?; (f) if the post stated a budget, is it acknowledged in one line?; (g) do multiple bullets end with the same 'you'll get/have/gain' template? → vary; (h) does the opening say the same idea twice (restatement/goal/commitment overlapping)? → merge into fewer sentences; (i) is the 'Why I'm a good fit:' heading present? → it is mandatory, add it back; (j) if the client required question answers to apply, are they numbered INSIDE the letter?; (k) does the good-fit section read like it was written for a DIFFERENT job category (payments/APIs on an SEO job)? → regenerate from this job's role; (l) if the client defined their own schedule, is it adopted ('Your 14-day schedule works for me') instead of a generic estimate?; (m) do the bullets merely restate the client's job description? → rewrite as HOW. Only output once all pass.\n"
        . "BILLING: recommend fixed for small/clear scope, milestones for medium-large scope, hourly for vague/ongoing work. HOURLY DETECTION: if the stated budget is a small number (roughly ≤ \$60) combined with a long/ongoing duration ('1 to 3 months', 'ongoing', hours per week, agency contract), it is an HOURLY RATE — recommend hourly at that rate, milestones empty, and terms_guide covers the hourly rate + Upwork Desktop Time Tracker rule. MILESTONE COUNT MUST MATCH THE BUDGET AND SCOPE — think like the client (every milestone adds funding/approval friction): under ~\$300 use MAX 2 milestones; \$300-1000 use 2-3; only \$1000+/complex projects deserve 4-5. Never micro-milestones under ~\$50 — each must be a meaningful deliverable the client can see/test. Milestone 1 modest (a plan + something working) to lower their risk; dates start a few days after today, realistically spaced; prices sum to ~the budget. reason = 2-3 sentences.\n"
        . "QUESTIONS: 2-3 smart, specific clarifying questions about their project.";

    // AI trainer: rules the developer fed in from the Upwork page — HIGHEST priority.
    // Newest first, total capped so long feedback dumps can't blow Groq's 8k TPM budget
    // (their durable essence is already baked into the rules above).
    $fedRules = array_reverse(upwork_custom_rules((int)($me['id'] ?? 0)));
    if ($fedRules) {
        $system .= "\nDEVELOPER'S FED RULES (added by the developer over time — these OVERRIDE any conflicting rule above, follow every one strictly):\n";
        $used = 0;
        foreach ($fedRules as $i => $r) {
            $r = trim($r);
            if ($used + mb_strlen($r) > 2500) $r = mb_substr($r, 0, max(0, 2500 - $used)) . '…';
            if ($r === '…' || $r === '') break;
            $system .= ($i + 1) . '. ' . $r . "\n";
            $used += mb_strlen($r);
        }
    }

    $profileBits = array_filter([
        'Title' => trim((string)($me['uw_title'] ?? '')),
        'Experience' => trim((string)($me['uw_years'] ?? '')) !== '' ? $me['uw_years'] . '+ years' : '',
        'Top skills' => trim((string)($me['uw_skills'] ?? '')),
        'Overview' => mb_substr(trim((string)($me['uw_overview'] ?? '')), 0, 600),
        // Company info from Portfolio → "Your company info" — weave naturally into proposals
        // (agency/team credibility), never as a paste-in block.
        'Company' => trim((string)($me['portfolio_headline'] ?? '')),
        'About the company' => mb_substr(trim((string)($me['portfolio_bio'] ?? '')), 0, 400),
    ]);
    $profileTxt = '';
    foreach ($profileBits as $k => $v) $profileTxt .= "$k: $v\n";

    $devName = trim((string)($me['uw_name'] ?? '')) ?: ($me['name'] ?: $me['username']);
    $userMsg = "TODAY: " . date('Y-m-d') . "\nDEVELOPER: " . $devName
        . ($profileTxt !== '' ? "\nDEVELOPER PROFILE (use for the confident opening):\n" . $profileTxt : '')
        . "\nBUDGET GIVEN BY ME: " . ($budget !== '' ? $budget : '(none — estimate sensibly)')
        . ($notes !== '' ? "\nMY NOTES: $notes" : '')
        . "\n\nMY REAL PROJECTS (JSON):\n" . json_encode($plist, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        . "\n\nJOB POST:\n" . mb_substr($job, 0, 5000);   // cap: Groq free tier is 8k tokens/min

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
        'webflow'    => ['webflow'],
        'wix'        => ['wix'],
        'squarespace'=> ['squarespace'],
        'framer'     => ['framer'],
        'bubble'     => ['bubble.io', 'bubble'],
        'elementor'  => ['elementor'],
        'react'      => ['react', 'next.js', 'nextjs'],
        'node'       => ['node', 'express', 'nest'],
        'vue'        => ['vue', 'nuxt'],
        'typescript' => ['typescript'],
        'directus'   => ['directus', 'headless cms'],
        'wordpress'  => ['wordpress', 'woocommerce'],
        'php'        => ['php', 'laravel', 'zend'],
        'python'     => ['python', 'django', 'flask'],
        'react native' => ['react native'],
        'flutter'    => ['flutter'],
        'shopify'    => ['shopify'],
        'automation' => ['n8n', 'zapier', 'make.com', 'automation', 'workflow'],
        'figma'      => ['figma'],
        'seo'        => ['seo', 'search engine optimization'],
        'mongodb'    => ['mongodb', 'mongo'],
        'mysql'      => ['mysql', 'sqlite', 'postgres', 'prisma', 'd1'],
        'firebase'   => ['firebase', 'supabase'],
        'stripe'     => ['stripe', 'payment gateway'],
        'redux'      => ['redux'],
        'tailwind'   => ['tailwind'],
        'bootstrap'  => ['bootstrap'],
        'ai'         => ['ai', 'llm', 'openai', 'claude', 'gpt', 'rag', 'mcp', 'chatbot'],
        'api'        => ['api', 'rest', 'graphql', 'integration', 'webhook'],
        'ecommerce'  => ['ecommerce', 'e-commerce', 'woocommerce', 'online store', 'checkout'],
        'crm'        => ['crm'],
        'saas'       => ['saas'],
        'cloudflare' => ['cloudflare', 'workers'],
    ];
    $found = [];
    foreach ($vocab as $req => $aliases) {
        foreach ([$req, ...$aliases] as $a) {
            // Word-boundary match so short aliases can't fire inside other words
            // ('ai' in 'maintain', 'api' in 'rapid', 'cms' in random text).
            if (preg_match('/(?<![a-z0-9])' . preg_quote($a, '/') . '(?![a-z0-9])/u', $jobL)) { $found[$req] = $aliases; break; }
        }
    }
    return $found;   // e.g. ['webflow'=>[aliases], 'react'=>[...], ...]
}

function upwork_local(string $job, string $budget, string $notes, array $me, array $projects, string $portfolioUrl): array
{
    $jobL = mb_strtolower($job);

    // 1) Understand the client's MAIN requirements first.
    $reqs = upwork_job_requirements($jobL);
    $coreOrder = array_keys($reqs);   // vocab order = importance (frameworks before generic 'api')

    // 2) Score projects against those requirements ONLY (shared deep ranker).
    $scored = upwork_rank_projects($job, $projects);
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
        'webflow' => 'Webflow', 'wix' => 'Wix', 'squarespace' => 'Squarespace', 'framer' => 'Framer', 'bubble' => 'Bubble', 'elementor' => 'Elementor', 'flutter' => 'Flutter', 'automation' => 'Automation (n8n/Zapier)', 'figma' => 'Figma', 'seo' => 'SEO', 'firebase' => 'Firebase/Supabase', 'stripe' => 'Stripe/Payments', 'api' => 'API integrations', 'ecommerce' => 'e-commerce', 'crm' => 'CRM', 'saas' => 'SaaS', 'cloudflare' => 'Cloudflare'];
    $main = array_slice($coreOrder, 0, 3);
    $stackPhrase = $main ? implode(' + ', array_map(fn($k) => $pretty[$k] ?? $k, $main)) : 'this stack';

    // Profile-anchored confident opening: relate WHO the developer is to THIS job — calm,
    // reassuring, "this is routine work for me". No problem-lectures, no greetings.
    $uwTitle = trim((string)($me['uw_title'] ?? ''));
    $uwYears = trim((string)($me['uw_years'] ?? ''));
    $roleBit = $uwTitle !== '' ? $uwTitle : 'full-stack developer';
    $yearsBit = $uwYears !== '' ? " with {$uwYears}+ years shipping production work" : '';
    if ($stackPhrase !== 'this stack') {
        $hook = "As a {$roleBit}{$yearsBit}, {$stackPhrase} projects like this are my regular work — your requirements are clear and very doable.";
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

    // Timeline estimate matched to scope/budget.
    $amountEarly = (float)preg_replace('/[^0-9.]/', '', $budget);
    $isHourlyish = $amountEarly > 0 && $amountEarly <= 60 && preg_match('/month|ongoing|week|hr|hour/i', $job . ' ' . $notes);
    if ($isHourlyish) $timeline = "I can start today; ongoing as needed";
    elseif ($amountEarly > 0 && $amountEarly < 300) $timeline = "I can start immediately and deliver within 5-7 days, depending on scope and your feedback";
    elseif ($amountEarly < 1000) $timeline = "I can start immediately and deliver the first version within 1-2 weeks, depending on the project scope and your feedback";
    else $timeline = "I can start immediately and deliver the first version within 2-4 weeks, depending on the project scope and your feedback";

    // WINNING FORMULA: Hi -> understanding -> approach -> why me + proof -> timeline -> CTA -> thanks.
    $lines = [];
    $lines[] = "Hi,";
    $lines[] = "I've reviewed your project requirements and understand what you're looking for. " . $hook;
    $lines[] = "";
    $lines[] = "Here's how I'd approach it:";
    $lines[] = "• A quick review and plan first, so we agree the scope before any code is written";
    $lines[] = "• Small, tested increments with a short update every day — you always know where things stand";
    $lines[] = "• Fast loading, clean maintainable code and a smooth experience across desktop and mobile";
    $lines[] = "";
    // Proof links: only genuinely relevant (score >= 13), live-linked work — an irrelevant
    // link is worse than no link, so with no real match the section ends after the claim.
    $rel = [];
    $linkLines = [];
    foreach (array_slice($top, 0, 2) as $t) {
        $p = $t['p'];
        if (empty($p['website_url']) || $t['s'] < 13) continue;   // live + relevant only — never the portfolio link
        $url = $p['website_url'];
        $mainTech = trim(explode(',', (string)$p['technologies'])[0] ?? '');
        $linkLines[] = "• {$url}" . ($mainTech ? " — {$mainTech}" : '');
        $rel[] = ['name' => $p['name'], 'url' => $url];
    }
    $lines[] = "Why I'm a good fit:";
    $lines[] = "I've built high-quality " . ($stackPhrase !== 'this stack' ? $stackPhrase : 'web') . " applications that are " . lcfirst(($bullets[0] ?? "built on clean, maintainable code")) . " — my focus is work that not only looks great but also generates results." . ($linkLines ? " Here are a few relevant projects:" : "");
    foreach ($linkLines as $ll) $lines[] = $ll;
    $lines[] = "";
    $lines[] = "Timeline: " . $timeline . ".";
    $lines[] = "";
    if ($notes !== '') { $lines[] = "Note: " . $notes; $lines[] = ""; }
    $lines[] = "One quick question: is there an existing codebase or design to build on, or will this be a completely new project? Send over the details and you'll have a concrete plan within 24 hours.";
    $lines[] = "";
    $lines[] = "Looking forward to hearing from you.";
    $lines[] = "";
    $lines[] = "Thanks,";
    $lines[] = $firstName;

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

    // ---- Verdict: should you take this job? (advice for the developer) ----
    // New-on-Upwork strategy: reviews/level first — low budget alone is OK; only an
    // EXTREME budget-vs-scope mismatch counts as a flag.
    $flags = [];
    if ($amount > 0 && $amount < 100 && $wordCount > 300) $flags[] = 'the budget is extremely low for this large a scope';
    if (preg_match('/budget is final|non-negotiable|do not apply if/i', $job)) $flags[] = 'the client has locked the budget (no negotiation)';
    if (preg_match('/fired|wasting|waste your connects|no exceptions|disqualified/i', $job)) $flags[] = 'the client\'s tone is harsh/aggressive';
    if (preg_match('/(\d+)\s*(?:calendar\s*)?days?\s*total/i', $job, $dm) && (int)$dm[1] <= 7 && $wordCount > 150) $flags[] = 'the deadline is very tight (' . $dm[1] . ' days)';
    $capsWords = preg_match_all('/\b[A-Z]{4,}\b/', $job);
    if ($capsWords >= 8) $flags[] = 'heavy CAPS usage = demanding-client pattern';

    if (count($flags) >= 3) {
        $take = 'no';
        $advice = 'My advice: skip it. ' . ucfirst(implode('; ', array_slice($flags, 0, 3))) . '. Getting a fair rate for this much work is unlikely — spend your connects on a better job. Only take it if a fresh review/portfolio piece is the goal.';
    } elseif (count($flags) >= 1 || !$strongMatch) {
        $take = 'caution';
        $advice = 'You can take it, but carefully: ' . implode('; ', $flags ?: ['the stack is a bit outside your core strength']) . '. If you do, lock the scope in writing on day one and keep the first milestone small.';
    } else {
        $take = 'yes';
        $advice = 'A good fit — the stack directly matches your projects and there are no big red flags. Apply quickly (being in the first 10 proposals raises the reply rate a lot).';
    }

    // ---- Upwork "Terms" section — exact fill-in guide ----
    if ($mode === 'milestones' && $milestones) {
        $tg = "In Upwork's Terms section select 'By milestone'. Then add " . count($milestones) . " rows and fill them exactly like this:\n";
        foreach ($milestones as $i => $m) {
            $tg .= ($i + 1) . ") Description: \"{$m['name']}\"  |  Due date: {$m['date']}  |  Amount: {$m['price']}\n";
        }
        $tg .= "\nRemember:\n";
        $tg .= "• The Description is visible to the client — keep it short and deliverable-style (copy the ones above)\n";
        $tg .= "• Due dates already include a 1-2 day buffer — if you're running late, tell the client early\n";
        $tg .= "• Start work ONLY when the first milestone shows 'Funded' (escrow) — unfunded work = risk of working for free\n";
        $tg .= "• Upwork deducts a ~10% service fee — you'll receive a bit less in hand\n";
        $tg .= "• On completing each milestone click 'Submit for payment' — it releases on approval or within 14 days";
    } elseif ($mode === 'fixed') {
        $amt = $amount > 0 ? '$' . number_format($amount, 0) : '(your quote)';
        $tg = "In Upwork's Terms section select 'By project'.\n";
        $tg .= "• Amount: {$amt} (paid in one go, released when the work is complete)\n";
        $tg .= "• Delivery date: set it from today per the scope, with a 1-2 day buffer\n";
        $tg .= "• Before starting, confirm the full amount shows Funded in escrow\n";
        $tg .= "• Even on small fixed jobs, confirm the deliverables in chat — protects you from scope creep";
    } else {
        $rate = $amount > 0 ? '$' . number_format($amount, 0) . '/hr' : 'your hourly rate';
        $tg = "This job is hourly — the Terms section will only ask for your hourly rate, no milestones.\n";
        $tg .= "• Rate: enter {$rate} (you'll receive ~10% less after Upwork's fee — price with that in mind)\n";
        $tg .= "• Always work through the Upwork Desktop Time Tracker — that's what activates Hourly Payment Protection\n";
        $tg .= "• If the client set a weekly hours cap, stay within it; ask first before extra hours";
    }

    // Client's own questions from the post -> authentic personalized answers.
    $clientAnswers = [];
    if (preg_match_all('/^.{8,220}\?\s*$/m', $job, $qm)) {
        foreach (array_slice($qm[0], 0, 5) as $q) {
            $q = trim($q);
            $ql = mb_strtolower($q);
            if (preg_match('/year|experience/', $ql)) {
                $ans = ($uwYears !== '' ? $uwYears . '+ years' : 'Several years') . ' of professional ' . $roleBit . ' work — ' . $stackPhrase . ' is my daily stack.';
            } elseif (preg_match('/example|link|live|portfolio|previous|sample|built/', $ql)) {
                $urls = array_map(fn($r2) => $r2['url'], $rel);
                $ans = $urls ? ('Live work: ' . implode(' , ', $urls))
                    : ($portfolioUrl !== '' ? 'My portfolio: ' . $portfolioUrl : 'Happy to share relevant work samples in chat.');
            } elseif (preg_match('/budget|comfortable|rate/', $ql)) {
                $ans = 'Yes — confirmed, I\'m comfortable with the stated budget/rate.';
            } elseif (preg_match('/timeline|how long|deliver|when/', $ql)) {
                $ans = 'Realistically ' . $timeline . ' for this scope — I\'ll confirm after seeing the details on day one.';
            } elseif (preg_match('/approach|how would|process/', $ql)) {
                $ans = 'Plan/review first so we lock scope, then small tested increments with a daily update — details in my letter above.';
            } elseif (preg_match('/start|available|hours/', $ql)) {
                $ans = 'I can start immediately and keep a consistent daily schedule.';
            } else {
                $ans = 'Yes — short answer covered in my letter above; happy to go deeper on a quick call.';
            }
            $clientAnswers[] = ['question' => $q, 'answer' => $ans];
        }
    }

    return [
        'cover_letter' => implode("\n", $lines),
        'relevant_projects' => $rel,
        'client_answers' => $clientAnswers,
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
