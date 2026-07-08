<?php
/**
 * Taskway — Brain Dump parser.
 *
 * Turns free-form notepad text into structured tasks. Works fully offline with a
 * heuristic engine; if a Claude API key is configured it upgrades to LLM extraction
 * and falls back to the local engine on any error.
 *
 * Returned shape:
 *   [ 'tasks' => [ {title, project_name, status, type, priority, spent_min, estimate_min, task_date, description}, ... ],
 *     'projects' => ['Name', ...],
 *     'engine' => 'local'|'claude' ]
 */

declare(strict_types=1);

/* ---- keyword dictionaries ---------------------------------------- */

const KW_DONE    = ['done', 'completed', 'complete', 'finished', 'shipped', 'deployed', 'resolved', 'merged', 'closed', 'delivered', 'wrapped up'];
const KW_PROG    = ['working on', 'in progress', 'wip', 'started', 'ongoing', 'doing', 'continuing', 'continued', 'half done', 'halfway', 'in-progress'];
const KW_BLOCKED = ['blocked', 'stuck', 'waiting on', 'waiting for', 'on hold', 'pending review', 'need help'];

const KW_FEATURE = ['built', 'build', 'created', 'create', 'added', 'add', 'implemented', 'implement', 'developed', 'develop', 'made', 'designed', 'design', 'launched', 'launch', 'set up', 'setup', 'integrated', 'integrate', 'new '];
const KW_BUG     = ['fixed', 'fix', 'bug', 'patched', 'patch', 'debug', 'debugged', 'hotfix', 'crash', 'error', 'issue'];
const KW_IMPROVE = ['improved', 'improve', 'updated', 'update', 'refactored', 'refactor', 'optimized', 'optimize', 'enhanced', 'polish', 'polished', 'tweaked', 'redesigned', 'restyle', 'cleanup', 'clean up'];
const KW_RESEARCH= ['research', 'researched', 'explore', 'explored', 'investigate', 'read ', 'learn', 'study', 'analyze', 'analyzed', 'plan ', 'planned', 'spec', 'review', 'reviewed', 'test', 'tested', 'testing', 'meeting', 'call with', 'discuss'];

const KW_URGENT  = ['urgent', 'asap', 'critical', '!!!', '🔥', 'immediately', 'right now'];
const KW_HIGH    = ['important', 'high priority', 'priority', '!!', 'must ', 'need to'];

// Roman-Urdu / Roman-Hindi recognition (distinct multi-char phrases to avoid false hits).
const KW_DONE_UR    = ['mukammal', 'ho gaya', 'hogaya', 'ho gai', 'hogai', 'kar liya', 'karliya', 'kar diya', 'kardiya', 'khatam', 'complete kar', 'ban gaya', 'bangaya'];
const KW_PROG_UR    = ['kar raha', 'kar rahi', 'kar rha', 'chal raha', 'chal rahi', 'ho raha', 'kaam kar', 'jari hai', 'jari he'];
const KW_BLOCKED_UR = ['atka', 'atki', 'ruka hua', 'ruki hui', 'intezar', 'intzar', 'phansa'];
const KW_FEATURE_UR = ['banaya', 'banai', 'bana diya', 'banadiya', 'naya ', 'nai ', 'tayyar kiya', 'shuru kiya'];
const KW_BUG_UR     = ['theek kiya', 'theek kar', 'thik kiya', 'masla', 'kharabi', 'ghalti', 'error aa'];
const KW_IMPROVE_UR = ['behtar', 'behter', 'update kiya', 'badla', 'tabdeel', 'improve kiya'];
const KW_URGENT_UR  = ['zaroori', 'zaruri', 'jaldi', 'foran', 'fauran'];
// Future intent -> this is still a to-do even in "things I did" mode.
const KW_TODO_UR    = ['karna hai', 'karni hai', 'karna he', 'karni he', 'krna hai', 'krni hai', 'karna hoga', 'karni hogi', 'baqi hai', 'baaki hai'];

function parse_braindump(string $text, array $opts = []): array
{
    $provider = setting('ai_provider', 'local');
    if ($provider === 'claude' && trim((string)setting('claude_api_key')) !== '') {
        $llm = claude_parse($text, $opts);
        if ($llm !== null) {
            return $llm;
        }
    }
    return local_parse($text, $opts);
}

/* ================================================================== */
/* Heuristic (offline) engine                                          */
/* ================================================================== */

function local_parse(string $text, array $opts = []): array
{
    $defaultStatus = $opts['default_status'] ?? 'todo';   // 'done' when logging finished work
    $defaultDate   = $opts['date'] ?? date('Y-m-d');

    $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
    $tasks = [];
    $currentProject = trim((string)($opts['project'] ?? ''));
    $projects = [];

    foreach ($lines as $raw) {
        $line = trim($raw);
        if ($line === '') continue;
        // Section separators.
        if (preg_match('/^[-=_*·•]{3,}$/', $line)) continue;

        // --- Project header detection ---
        // Markdown heading: "# Casebazar" / "## Casebazar"
        if (preg_match('/^#{1,4}\s+(.{2,60})$/u', $line, $m) && !preg_match('/[.!?]$/', $m[1])) {
            $currentProject = clean_project_name($m[1]);
            if ($currentProject) $projects[$currentProject] = true;
            continue;
        }
        // "Project: X" / "Project - X"
        if (preg_match('/^project\s*[:\-]\s*(.{2,60})$/iu', $line, $m)) {
            $currentProject = clean_project_name($m[1]);
            if ($currentProject) $projects[$currentProject] = true;
            continue;
        }
        // Short line ending in ":" that is not itself a task (few words) => header.
        if (preg_match('/^(.{2,40}):$/u', $line, $m) && str_word_count($m[1]) <= 5 && !has_list_marker($raw)) {
            $currentProject = clean_project_name($m[1]);
            if ($currentProject) $projects[$currentProject] = true;
            continue;
        }
        // Fuzzy header: a short un-bulleted line naming a project/app/site.
        if (looks_like_header($line)) {
            $currentProject = clean_project_name($line);
            if ($currentProject) $projects[$currentProject] = true;
            continue;
        }

        $task = parse_line($line, $defaultStatus, $defaultDate);
        if ($task === null) continue;

        // Inline project (#tag / [tag]) wins over the current section.
        if ($task['project_name'] === '' && $currentProject !== '') {
            $task['project_name'] = $currentProject;
        }
        if ($task['project_name'] !== '') {
            $projects[$task['project_name']] = true;
        }
        $tasks[] = $task;
    }

    return [
        'tasks'    => $tasks,
        'projects' => array_keys($projects),
        'engine'   => 'local',
    ];
}

function has_list_marker(string $raw): bool
{
    return (bool)preg_match('/^\s*(?:[-*•–▪]|\d+[.)]|\[[ xX]\])\s+/u', $raw);
}

/** A short, un-bulleted line that names a project/app/site (not a task). */
function looks_like_header(string $line): bool
{
    if (has_list_marker($line)) return false;
    $w = str_word_count($line);
    if ($w < 1 || $w > 5) return false;
    if (extract_minutes($line) > 0) return false;
    if (preg_match('/[.!?,]/', $line)) return false;
    // Lines starting with an action verb are tasks, not headers.
    if (preg_match('/^(fixed|fix|built|build|created|create|add|added|updated|update|working|make|made|design|designed|research|researched|implement|implemented|review|reviewed|test|tested|call|email|write|wrote|read|plan|planned|setup|set|deploy|deployed|ship|shipped|launch|launched|start|started|finish|finished|complete|completed|remove|removed|change|changed)\b/i', $line)) return false;
    $lower = mb_strtolower($line);
    if (kw_any($lower, KW_DONE) || kw_any($lower, KW_PROG) || kw_any($lower, KW_BLOCKED)) return false;
    // Roman-Urdu sentence markers mean it's a task, not a project heading.
    if (preg_match('/\b(ka|ke|ki|ko|se|par|pe|hai|hain|ha|hy|karna|karni|kar|kiya|kia|raha|rahi|banao|banana|banaya|karo|krna|chahiye|hoga)\b/i', $line)) return false;
    return (bool)preg_match('/\b(project|app|application|website|web ?site|dashboard|portal|client|store|shop|platform)\b/i', $line);
}

function clean_project_name(string $s): string
{
    $s = trim($s, " \t\-:•*#");
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    return mb_substr(ucwords($s), 0, 60);
}

/**
 * Autocorrect + tidy an English task title so it reads naturally and is action-first.
 * Fixes common typos, capitalises acronyms, phrases deadlines, and turns noun-phrases
 * ("API integration", "database backup") into imperative tasks ("Integrate API", "Back up database").
 */
function polish_title(string $t): string
{
    $t = trim($t);
    if ($t === '') return $t;

    // 1) Fix common misspellings (whole-word, case-insensitive).
    static $typos = [
        'databse'=>'database','databse'=>'database','recieve'=>'receive','payement'=>'payment','pyament'=>'payment',
        'buton'=>'button','fucntion'=>'function','functino'=>'function','responsevie'=>'responsive','responsove'=>'responsive',
        'integaration'=>'integration','integratoin'=>'integration','optmize'=>'optimize','optimise'=>'optimize','optimisation'=>'optimization',
        'colour'=>'color','wishlst'=>'wishlist','chekout'=>'checkout','chckout'=>'checkout','dashbaord'=>'dashboard',
        'homepge'=>'homepage','deploment'=>'deployment','authentiction'=>'authentication','authenticaton'=>'authentication',
        'calender'=>'calendar','seperate'=>'separate','succesful'=>'successful','navigaton'=>'navigation','notifcation'=>'notification',
        'defualt'=>'default','widht'=>'width','heigth'=>'height','langauge'=>'language','managment'=>'management',
    ];
    $t = preg_replace_callback('/[A-Za-z]+/u', function ($m) use ($typos) {
        $l = mb_strtolower($m[0]);
        return $typos[$l] ?? $m[0];
    }, $t) ?? $t;

    // 2) Phrase deadlines: "1d" -> "in 1 day", "3d" -> "in 3 days".
    $t = preg_replace_callback('/\b(\d+)\s*d\b/i', fn($m) => 'in ' . $m[1] . ' day' . ($m[1] == 1 ? '' : 's'), $t) ?? $t;

    // 3) Noun-phrase -> imperative, but only when it doesn't already start with an action verb.
    $leadVerbs = 'add|update|fix|build|create|make|design|deploy|test|review|research|remove|integrate|optimize|refactor|write|send|call|check|set|configure|migrate|back|launch|connect|improve|plan|finish|complete|implement|redesign|setup|install|debug|handle|prepare|record|track|schedule|apply|enable|disable|publish|upload|generate|run|work';
    if (!preg_match('/^(' . $leadVerbs . ')\b/i', $t)) {
        // regex => imperative verb; the object is always capture group 1.
        $rules = [
            '/^(.*\S)\s+integration$/i'   => 'Integrate',
            '/^(.*\S)\s+deployment$/i'    => 'Deploy',
            '/^(.*\S)\s+migration$/i'     => 'Migrate',
            '/^(.*\S)\s+optimization$/i'  => 'Optimize',
            '/^(.*\S)\s+configuration$/i' => 'Configure',
            '/^(.*\S)\s+backup$/i'        => 'Back up',
            '/^backup\s+(.*\S)$/i'        => 'Back up',
            '/^(.*\S)\s+testing$/i'       => 'Test',
            '/^testing\s+(.*\S)$/i'       => 'Test',
            '/^(.*\S)\s+research$/i'      => 'Research',
            '/^research\s+(.*\S)$/i'      => 'Research',
            '/^(.*\S)\s+refactoring$/i'   => 'Refactor',
            '/^(.*\S)\s+redesign$/i'      => 'Redesign',
            '/^(.*\S)\s+setup$/i'         => 'Set up',
            '/^(.*\S)\s+installation$/i'  => 'Install',
        ];
        foreach ($rules as $re => $verb) {
            if (preg_match($re, $t, $mm)) {
                $obj = $mm[1];
                // The object moves mid-sentence: lowercase its lead unless it's an acronym.
                $firstWord = preg_split('/\s+/u', $obj)[0] ?? $obj;
                if (!preg_match('/^[A-Z0-9]{2,}$/', $firstWord)) {
                    $obj = mb_strtolower(mb_substr($obj, 0, 1)) . mb_substr($obj, 1);
                }
                $t = $verb . ' ' . $obj;
                break;
            }
        }
    }

    // 4) Re-capitalise well-known acronyms.
    $t = preg_replace_callback('/\b(api|seo|ui|ux|css|html|db|url|sql|ai|faq|pdf|sms|otp|jwt|crm|cms|ssl|dns|cdn|ios)\b/i',
        fn($m) => strtoupper($m[0]), $t) ?? $t;

    // 5) Collapse spaces + sentence-case.
    $t = preg_replace('/\s{2,}/', ' ', trim($t)) ?? $t;
    if (mb_strlen($t) > 1) $t = mb_strtoupper(mb_substr($t, 0, 1)) . mb_substr($t, 1);
    return $t;
}

/**
 * Parse a single content line into a task, or null if it's noise.
 */
function parse_line(string $line, string $defaultStatus, string $defaultDate): ?array
{
    $original = $line;
    $lower = mb_strtolower($line);

    $status   = $defaultStatus;
    $type     = 'task';
    $priority = 'normal';
    $projectName = '';
    $spent = 0;
    $date  = $defaultDate;

    // Checkbox state.
    if (preg_match('/^\s*[-*•]?\s*\[[xX✓✔]\]/u', $original)) { $status = 'done'; }
    if (preg_match('/^\s*[-*•]?\s*\[\s\]/u', $original))     { $status = ($defaultStatus === 'done') ? 'todo' : $defaultStatus; }
    if (preg_match('/(^|\s)(✅|✔️?|☑️?)/u', $original))       { $status = 'done'; }
    if (preg_match('/(^|\s)🚧/u', $original))                { $status = 'in_progress'; }

    // Inline project tags: #Name  [Name]  @Name
    if (preg_match('/(?:^|\s)#([A-Za-z0-9][\w\- ]{1,40})/u', $line, $m)) { $projectName = clean_project_name(str_replace('_', ' ', $m[1])); }
    elseif (preg_match('/\[([A-Za-z][\w\- ]{1,40})\]\s*$/u', $line, $m))  { $projectName = clean_project_name($m[1]); }
    elseif (preg_match('/(?:^|\s)@([A-Za-z][\w\-]{1,30})/u', $line, $m))  { $projectName = clean_project_name($m[1]); }

    // Status keywords (explicit wins over default). English + Roman-Urdu.
    if (kw_any($lower, KW_DONE) || kw_any($lower, KW_DONE_UR))       $status = 'done';
    if (kw_any($lower, KW_PROG) || kw_any($lower, KW_PROG_UR))       $status = 'in_progress';
    if (kw_any($lower, KW_BLOCKED) || kw_any($lower, KW_BLOCKED_UR)) $status = 'blocked';
    if (kw_any($lower, KW_TODO_UR)) $status = 'todo';   // future intent overrides "done" default

    // Type.
    if (kw_any($lower, KW_BUG) || kw_any($lower, KW_BUG_UR))              $type = 'bug';
    elseif (kw_any($lower, KW_IMPROVE) || kw_any($lower, KW_IMPROVE_UR))  $type = 'improvement';
    elseif (kw_any($lower, KW_FEATURE) || kw_any($lower, KW_FEATURE_UR))  $type = 'feature';
    elseif (kw_any($lower, KW_RESEARCH))                                  $type = 'research';

    // Priority.
    if (kw_any($lower, KW_URGENT) || kw_any($lower, KW_URGENT_UR))    $priority = 'urgent';
    elseif (kw_any($lower, KW_HIGH))                                  $priority = 'high';

    // Date hints.
    if (preg_match('/\byesterday\b/i', $lower)) $date = date('Y-m-d', strtotime('yesterday'));
    if (preg_match('/\btomorrow\b/i', $lower))  $date = date('Y-m-d', strtotime('tomorrow'));

    // Time / duration -> minutes.
    $spent = extract_minutes($line);

    // Build clean title, then translate Roman-Urdu wording to English (English passes through).
    $title = strip_title($original);
    if ($title === '' || mb_strlen($title) < 2) return null;
    $title = translate_roman_urdu($title);
    $title = polish_title($title);

    $isPlanning = ($defaultStatus !== 'done' && $status !== 'done');
    return [
        'title'        => $title,
        'project_name' => $projectName,
        'status'       => $status,
        'type'         => $type,
        'priority'     => $priority,
        'spent_min'    => $isPlanning ? 0 : $spent,
        'estimate_min' => $isPlanning ? $spent : 0,
        'task_date'    => $date,
        'description'  => '',
    ];
}

function kw_any(string $haystack, array $words): bool
{
    foreach ($words as $w) {
        if (mb_strpos($haystack, $w) !== false) return true;
    }
    return false;
}

/**
 * Best-effort offline Roman-Urdu / Roman-Hindi -> English translation of a task title.
 * Roman is usually Subject-Object-Verb; English is Verb-Object, so we lift the verb to the
 * front and drop grammatical particles. English input (no Roman tokens) is returned untouched.
 * For higher-quality translation, the Claude engine handles it directly.
 */
function translate_roman_urdu(string $s): string
{
    // Grammatical particles / fillers to drop entirely.
    $drop = ['ka','ke','ki','ko','ka,','se','mein','me','main','hai','hain','ha','he','hy','hai.',
        'hun','hoon','ho','raha','rahi','rahe','rha','rhi','tha','thi','the','wala','wali','wale','ye','yeh','yh',
        'wo','woh','abhi','ab','hi','bhi','to','na','ne','liye','k','ka.','kr','jo','ek'];

    // Roman verbs -> English imperative (these lift to the front).
    $verbs = [
        'banaya'=>'build','banai'=>'build','banana'=>'build','banani'=>'build','banao'=>'build','bana'=>'build','banwana'=>'build',
        'theek'=>'fix','thik'=>'fix','sahi'=>'fix',
        'likha'=>'write','likhna'=>'write','likhni'=>'write','likho'=>'write','likhi'=>'write',
        'bheja'=>'send','bhejna'=>'send','bhejni'=>'send','bhejo'=>'send','bheji'=>'send',
        'parha'=>'read','parhna'=>'read','parhni'=>'read','parho'=>'read','parhi'=>'read',
        'dekha'=>'review','dekhna'=>'review','dekhni'=>'review','dekho'=>'review','dekhi'=>'review',
        'hataya'=>'remove','hatana'=>'remove','hatani'=>'remove','hatao'=>'remove','hatadi'=>'remove',
        'lagaya'=>'set up','lagana'=>'set up','lagani'=>'set up','lagao'=>'set up',
        'chalaya'=>'run','chalana'=>'run','chalani'=>'run','chalao'=>'run',
        'kaam'=>'work on','research'=>'research','banwao'=>'build',
        // shared English verbs (kept, also lift to front)
        'add'=>'add','update'=>'update','fix'=>'fix','check'=>'check','test'=>'test','call'=>'call',
        'design'=>'design','deploy'=>'deploy','integrate'=>'integrate','review'=>'review','setup'=>'set up',
        'improve'=>'improve','optimize'=>'optimize','create'=>'create','build'=>'build','send'=>'send',
        'write'=>'write','remove'=>'remove','launch'=>'launch','fixed'=>'fix','made'=>'build','finish'=>'finish',
        'complete'=>'complete','connect'=>'connect','setup'=>'set up','plan'=>'plan',
        'apply'=>'apply','implement'=>'implement','install'=>'install','enable'=>'enable','disable'=>'disable',
        'configure'=>'configure','publish'=>'publish','upload'=>'upload','generate'=>'generate','migrate'=>'migrate',
        'handle'=>'handle','prepare'=>'prepare','record'=>'record','track'=>'track','schedule'=>'schedule','debug'=>'debug',
    ];

    // Roman nouns/adjectives -> English (kept in place).
    $words = [
        'naya'=>'new','nayi'=>'new','nai'=>'new','naye'=>'new','purana'=>'old','purani'=>'old',
        'masla'=>'issue','masle'=>'issues','ghalti'=>'error','kharabi'=>'issue','bug'=>'bug',
        'safha'=>'page','safhe'=>'pages','safhy'=>'page','tasveer'=>'image','tasveerein'=>'images',
        'rang'=>'color','number'=>'number','naam'=>'name','list'=>'list','form'=>'form',
        'aur'=>'and','or'=>'and','ya'=>'or','magar'=>'but','lekin'=>'but',
    ];

    // Tokens that prove the line is Roman (so English lines are left alone).
    $romanOnly = array_merge($drop, ['banaya','banai','banana','banao','bana','theek','thik','sahi','likha','likhna','likho',
        'bheja','bhejna','bhejo','parha','parhna','parho','dekha','dekhna','dekho','hataya','hatana','hatao',
        'lagaya','lagana','lagao','chalaya','chalana','chalao','kaam','naya','nayi','nai','naye','purana',
        'masla','masle','ghalti','kharabi','safha','safhe','karna','karni','karne','kar','krna','krni','karo',
        'diya','dena','deni','dedo','dediya','mukammal','zaroori','zaruri','jaldi','intezar','intzar','baqi','baaki']);

    $skip = array_merge($drop, ['hu','karna','karni','karne','kar','krna','krni','karo','karke','karunga','karungi',
        'diya','dena','deni','dedo','dediya','kiya','kia','kien','liya','leni','lena','chahiye','chahye','hoga','hogi',
        'zaroori','zaruri','jaldi','foran','fauran','urgent','important','asap']);

    $tokens = preg_split('/\s+/u', trim($s)) ?: [];
    $seq = [];   // ordered items: ['verb'=>bool, 't'=>string, 'orig'=>string]
    $tail = []; $loc = []; $romanHits = 0;

    foreach ($tokens as $tk) {
        $low = mb_strtolower(trim($tk, " \t.,:;!?()"));
        if ($low === '') continue;

        // Postposition: "X par / pe / pr" means "on X" -> lift X to the end as a location.
        if (in_array($low, ['par', 'pe', 'pr', 'peh', 'pay'], true)) {
            $romanHits++;
            $prev = array_pop($seq);
            if ($prev !== null) {
                $loc[] = 'on ' . $prev['orig'];
            }
            continue;
        }

        // Deadline-ish tokens go to the end.
        if (preg_match('/^\d+\s*d$/', $low) || in_array($low, ['kal','aaj','parso','din'], true)) {
            $tail[] = $low === 'kal' ? 'tomorrow' : ($low === 'aaj' ? 'today' : ($low === 'parso' ? 'day after' : $low));
            $romanHits++;
            continue;
        }
        if (in_array($low, $skip, true)) { $romanHits++; continue; }
        if (isset($verbs[$low])) {
            $seq[] = ['verb' => true, 't' => $verbs[$low], 'orig' => $verbs[$low]];
            if (in_array($low, $romanOnly, true)) $romanHits++;
            continue;
        }
        if (isset($words[$low])) {
            $seq[] = ['verb' => false, 't' => $words[$low], 'orig' => $words[$low]];
            if (in_array($low, $romanOnly, true)) $romanHits++;
            continue;
        }
        // Unknown token: keep original case as the location form; lowercase (unless acronym) for the body.
        $orig = trim($tk, " \t.,:;!?()");
        $keep = preg_match('/^[A-Z0-9]{2,}$/', $orig) ? $orig : mb_strtolower($orig);
        $seq[] = ['verb' => false, 't' => $keep, 'orig' => $orig];
    }

    // No Roman signals -> treat as English, leave unchanged.
    if ($romanHits === 0) return $s;

    // Roman is verb-final: use the LAST verb as the main action, demote earlier "verbs" to words.
    $lastVerbIdx = -1;
    foreach ($seq as $i => $it) if ($it['verb']) $lastVerbIdx = $i;

    $main = '';
    $rest = [];
    foreach ($seq as $i => $it) {
        if ($i === $lastVerbIdx) { $main = $it['t']; continue; }
        $rest[] = $it['t'];
    }

    // Assemble: verb + objects + location(s) + deadline(s).
    $out = trim($main . ' ' . implode(' ', $rest) . ' ' . implode(' ', $loc) . ' ' . implode(' ', $tail));
    $out = preg_replace('/\s{2,}/', ' ', $out) ?? $out;
    if (mb_strlen(trim($out)) < 2) return $s;      // safety: never produce an empty title
    return mb_strtoupper(mb_substr($out, 0, 1)) . mb_substr($out, 1);
}

/** Sum durations found in a line: "2h", "1.5 hrs", "90m", "45 min", "2h30m". */
function extract_minutes(string $line): int
{
    $min = 0;
    if (preg_match_all('/(\d+(?:\.\d+)?)\s*(?:h|hr|hrs|hour|hours)\b/i', $line, $mm)) {
        foreach ($mm[1] as $h) $min += (int)round(((float)$h) * 60);
    }
    if (preg_match_all('/(\d+)\s*(?:m|min|mins|minute|minutes)\b/i', $line, $mm)) {
        foreach ($mm[1] as $mn) $min += (int)$mn;
    }
    return $min;
}

/** Remove list markers, tags, checkboxes and time tokens; tidy for display. */
function strip_title(string $line): string
{
    $t = $line;
    // Leading list / checkbox markers.
    $t = preg_replace('/^\s*(?:[-*•–▪]\s*)?(?:\[[ xX✓✔]\]\s*)?(?:\d+[.)]\s*)?/u', '', $t) ?? $t;
    $t = preg_replace('/^\s*[-*•–▪]\s*/u', '', $t) ?? $t;
    // Status emojis.
    $t = preg_replace('/(✅|✔️?|☑️?|🚧|🔥)/u', '', $t) ?? $t;
    // Leading status connectors ("Working on ...", "Blocked: ...").
    $t = preg_replace('/^\s*(working on|in[\s\-]?progress|wip|blocked|on hold|to ?do|note)\b\s*[:\-–]?\s*/i', '', $t) ?? $t;
    // Inline project tags.
    $t = preg_replace('/(?:^|\s)#[A-Za-z0-9][\w\- ]{1,40}/u', ' ', $t) ?? $t;
    $t = preg_replace('/\[[A-Za-z][\w\- ]{1,40}\]\s*$/u', '', $t) ?? $t;
    $t = preg_replace('/(?:^|\s)@[A-Za-z][\w\-]{1,30}/u', ' ', $t) ?? $t;
    // Time tokens -> single space (keep surrounding words apart).
    $t = preg_replace('/\(?\s*\d+(?:\.\d+)?\s*(?:h|hr|hrs|hour|hours)\b\s*\)?/i', ' ', $t) ?? $t;
    $t = preg_replace('/\(?\s*\d+\s*(?:m|min|mins|minute|minutes)\b\s*\)?/i', ' ', $t) ?? $t;
    // Priority words / emphasis.
    $t = preg_replace('/\b(urgent|asap|critical|important|high priority|priority)\b/i', ' ', $t) ?? $t;
    $t = preg_replace('/!{2,}/', ' ', $t) ?? $t;
    // Trailing "- done" / "(done)" style annotations.
    $t = preg_replace('/[\s\-–—]+\(?(done|completed|complete|finished)\)?\s*$/i', '', $t) ?? $t;
    // Collapse whitespace & stray separators.
    $t = preg_replace('/\s{2,}/', ' ', $t) ?? $t;
    $t = trim($t, " \t-–—:•*");
    if ($t !== '' && mb_strlen($t) > 1) {
        $t = mb_strtoupper(mb_substr($t, 0, 1)) . mb_substr($t, 1);
    }
    return $t;
}

/* ================================================================== */
/* Claude API engine (optional)                                        */
/* ================================================================== */

function claude_parse(string $text, array $opts = []): ?array
{
    $key = trim((string)setting('claude_api_key'));
    if ($key === '') return null;
    $model = setting('claude_model', 'claude-sonnet-5');
    $defaultStatus = $opts['default_status'] ?? 'todo';
    $today = date('Y-m-d');

    $system = "You extract a clean task list from a person's rough work notes. "
        . "Return ONLY valid JSON, no prose. Schema: "
        . '{"tasks":[{"title":string,"project_name":string,"status":"todo|in_progress|done|blocked","type":"feature|improvement|bug|research|task","priority":"low|normal|high|urgent","minutes":number}]}. '
        . "IMPORTANT: Always write every 'title' and 'project_name' in clear, natural, concise English. "
        . "The notes may be written in Urdu, Hindi, Roman Urdu/Hindi, or a mix — translate the meaning into professional English task titles (do NOT transliterate; give the English equivalent). "
        . "Rules: title is a short imperative phrase, no markdown. project_name is '' if none is implied. "
        . "type 'feature' = something newly built/created; 'bug' = a fix; 'improvement' = update/refactor; 'research' = plan/read/review/test. "
        . "'minutes' is any time the note mentions for that item, else 0. "
        . "If the note is a log of completed work, default status to 'done'; the caller says the default status is '{$defaultStatus}'. Group items under the project they belong to.";

    $payload = [
        'model' => $model,
        'max_tokens' => 2000,
        'system' => $system,
        'messages' => [[
            'role' => 'user',
            'content' => "Today is {$today}. Extract tasks from these notes:\n\n" . $text,
        ]],
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_HTTPHEADER => [
            'content-type: application/json',
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false || $code >= 300) return null;

    $data = json_decode((string)$resp, true);
    $content = $data['content'][0]['text'] ?? '';
    if (!$content) return null;

    // Extract the JSON object even if wrapped in stray text.
    if (preg_match('/\{.*\}/s', $content, $m)) $content = $m[0];
    $parsed = json_decode($content, true);
    if (!is_array($parsed) || empty($parsed['tasks'])) return null;

    $tasks = [];
    $projects = [];
    foreach ($parsed['tasks'] as $t) {
        $title = trim((string)($t['title'] ?? ''));
        if ($title === '') continue;
        $proj = clean_project_name((string)($t['project_name'] ?? ''));
        $status = in_array($t['status'] ?? '', ['todo','in_progress','done','blocked'], true) ? $t['status'] : $defaultStatus;
        $mins = (int)($t['minutes'] ?? 0);
        if ($proj) $projects[$proj] = true;
        $tasks[] = [
            'title'        => $title,
            'project_name' => $proj,
            'status'       => $status,
            'type'         => in_array($t['type'] ?? '', ['feature','improvement','bug','research','task'], true) ? $t['type'] : 'task',
            'priority'     => in_array($t['priority'] ?? '', ['low','normal','high','urgent'], true) ? $t['priority'] : 'normal',
            'spent_min'    => $status === 'done' ? $mins : 0,
            'estimate_min' => $status === 'done' ? 0 : $mins,
            'task_date'    => $opts['date'] ?? $today,
            'description'  => '',
        ];
    }
    if (!$tasks) return null;

    return ['tasks' => $tasks, 'projects' => array_keys($projects), 'engine' => 'claude'];
}
