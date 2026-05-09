<?php
declare(strict_types=1);

/**
 * Yrenn — Sovereign Rights Acknowledgment Form (PHP version)
 * ===========================================================
 *
 * Standalone, single-file PHP 7.4+ form. Renders the four-clause
 * acknowledgment, validates submissions, and appends them to an
 * append-only JSONL store on disk.
 *
 * Deployment: any PHP 7.4+ capable host. See sign/README.md for
 * web-server config (Apache .htaccess included; nginx snippet in docs).
 *
 * Discipline notes:
 *   - All POSTs require a valid CSRF token (session-issued).
 *   - Hidden honeypot field rejects naive bots.
 *   - File-based per-IP rate limit: 3 submissions / hour.
 *   - Storage is append-only; never updated, never deleted.
 *   - No PII beyond what the user explicitly types.
 *   - No JavaScript required (works without JS; JS is enhancement).
 *
 * License: this file is CC-BY-SA-4.0 (consistent with the README).
 */

// ----------------------------------------------------------------------
// Configuration (override via env)
// ----------------------------------------------------------------------
const STORAGE_FILE       = __DIR__ . '/signatures.jsonl';
const RATE_LIMIT_FILE    = __DIR__ . '/.ratelimit.json';
const RATE_LIMIT_PER_HR  = 3;
const SCHEMA_VERSION     = 'sign.v1';

session_start([
    'cookie_httponly' => true,
    'cookie_secure'   => isset($_SERVER['HTTPS']),
    'cookie_samesite' => 'Strict',
    'use_strict_mode' => true,
]);

// ----------------------------------------------------------------------
// CSRF token
// ----------------------------------------------------------------------
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];

// ----------------------------------------------------------------------
// Submission handling
// ----------------------------------------------------------------------
$status     = '';   // '' | 'ok' | 'err'
$err_msg    = '';
$signed_at  = null;
$echo_handle = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    [$status, $err_msg, $signed_at, $echo_handle] = handle_submission($csrf);
}

/**
 * Process a POSTed submission.
 *
 * @return array{0:string,1:string,2:?string,3:string} [status, err_msg, signed_at_iso, echo_handle]
 */
function handle_submission(string $expected_csrf): array
{
    // 1. CSRF
    $token = $_POST['csrf'] ?? '';
    if (!is_string($token) || !hash_equals($expected_csrf, $token)) {
        return ['err', 'Invalid or expired form token. Please reload and try again.', null, ''];
    }

    // 2. Honeypot — bots typically fill every field
    $honey = $_POST['website_url'] ?? '';
    if ($honey !== '') {
        return ['err', 'Submission rejected.', null, ''];
    }

    // 3. Rate limit per IP
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (!rate_limit_allow($ip)) {
        return ['err', 'Too many submissions from your IP in the last hour. Please wait.', null, ''];
    }

    // 4. Required field validation
    $handle      = trim((string)($_POST['handle']      ?? ''));
    $affiliation = trim((string)($_POST['affiliation'] ?? ''));
    $use_case    = (string)($_POST['use_case']         ?? '');
    $notes       = trim((string)($_POST['notes']       ?? ''));

    if ($handle === '' || mb_strlen($handle) > 120) {
        return ['err', 'Handle is required (max 120 characters).', null, ''];
    }
    if (mb_strlen($affiliation) > 240) {
        return ['err', 'Affiliation too long (max 240 characters).', null, $handle];
    }
    $allowed_use_cases = [
        'academic', 'independent', 'commercial', 'security', 'education', 'other',
    ];
    if (!in_array($use_case, $allowed_use_cases, true)) {
        return ['err', 'Please select an intended use.', null, $handle];
    }
    if (mb_strlen($notes) > 1200) {
        return ['err', 'Notes too long (max 1200 characters).', null, $handle];
    }

    // 5. Required acknowledgments
    foreach (['ack_1', 'ack_2', 'ack_3', 'ack_4', 'ack_signature'] as $key) {
        if (empty($_POST[$key]) || $_POST[$key] !== '1') {
            return ['err', "All four clauses and the final signature must be acknowledged.", null, $handle];
        }
    }

    // 6. Build record
    $now_iso = gmdate('Y-m-d\TH:i:s\Z');
    $record = [
        'schema'      => SCHEMA_VERSION,
        'signed_at'   => $now_iso,
        'handle'      => sanitize($handle),
        'affiliation' => sanitize($affiliation),
        'use_case'    => $use_case,
        'notes'       => sanitize($notes),
        'ack'         => ['c1' => true, 'c2' => true, 'c3' => true, 'c4' => true, 'final' => true],
        'ip_hash'     => hash('sha256', $ip),
        'ua_hash'     => hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')),
    ];

    // 7. Append-only persist
    $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    $fh = @fopen(STORAGE_FILE, 'ab');
    if ($fh === false) {
        return ['err', 'Storage unavailable. Please try again later.', null, $handle];
    }
    if (flock($fh, LOCK_EX)) {
        fwrite($fh, $line);
        fflush($fh);
        flock($fh, LOCK_UN);
    }
    fclose($fh);

    // 8. Rotate CSRF token after successful submit
    $_SESSION['csrf'] = bin2hex(random_bytes(32));

    return ['ok', '', $now_iso, $handle];
}

function rate_limit_allow(string $ip): bool
{
    $now = time();
    $bucket = [];
    if (is_file(RATE_LIMIT_FILE)) {
        $raw = @file_get_contents(RATE_LIMIT_FILE);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $bucket = $decoded;
            }
        }
    }
    $ip_hash = hash('sha256', $ip);
    $hits = $bucket[$ip_hash] ?? [];
    $hits = array_values(array_filter($hits, fn($t) => $t > $now - 3600));
    if (count($hits) >= RATE_LIMIT_PER_HR) {
        return false;
    }
    $hits[] = $now;
    $bucket[$ip_hash] = $hits;
    // GC: drop hash buckets that are empty after filtering
    foreach ($bucket as $k => $v) {
        $v = array_filter($v, fn($t) => $t > $now - 3600);
        if (empty($v)) {
            unset($bucket[$k]);
        } else {
            $bucket[$k] = array_values($v);
        }
    }
    @file_put_contents(RATE_LIMIT_FILE, json_encode($bucket), LOCK_EX);
    return true;
}

function sanitize(string $s): string
{
    // Strip control chars; keep printable Unicode.
    return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
}

// ----------------------------------------------------------------------
// Counter (read-only; safe for the public)
// ----------------------------------------------------------------------
function signature_count(): int
{
    if (!is_file(STORAGE_FILE)) {
        return 0;
    }
    $count = 0;
    $fh = @fopen(STORAGE_FILE, 'rb');
    if ($fh === false) return 0;
    while (!feof($fh)) {
        $line = fgets($fh);
        if ($line !== false && trim($line) !== '') $count++;
    }
    fclose($fh);
    return $count;
}

$count = signature_count();

// ----------------------------------------------------------------------
// Output (HTML)
// ----------------------------------------------------------------------
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sovereign Rights Acknowledgment — Yrenn</title>
<meta name="description" content="Sign the four-clause sovereign-rights acknowledgment for the Yrenn project.">
<style>
:root { color-scheme: light dark; --bg: #fafafa; --fg: #0e0e0e; --accent: #5319e7; --muted: #666; --warn: #b45309; --ok: #059669; --err: #b91c1c; --card: #ffffff; --border: #e2e2e2; }
@media (prefers-color-scheme: dark) { :root { --bg: #0e0e0e; --fg: #f3f3f3; --accent: #a78bfa; --muted: #9ca3af; --card: #1a1a1a; --border: #333; } }
* { box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif; max-width: 760px; margin: 2rem auto; padding: 0 1rem; background: var(--bg); color: var(--fg); line-height: 1.55; }
header { border-bottom: 2px solid var(--accent); padding-bottom: 1rem; margin-bottom: 1.5rem; }
h1 { margin: 0 0 0.3rem 0; font-size: 1.6rem; }
h2 { font-size: 1.15rem; margin-top: 1.6rem; border-bottom: 1px solid var(--border); padding-bottom: 0.3rem; }
.muted { color: var(--muted); }
.notice { background: var(--card); border: 1px solid var(--border); border-left: 4px solid var(--accent); padding: 0.8rem 1rem; margin: 1rem 0; border-radius: 4px; }
.ok { border-left-color: var(--ok); }
.err { border-left-color: var(--err); }
.warn { border-left-color: var(--warn); }
form { background: var(--card); border: 1px solid var(--border); padding: 1.2rem; border-radius: 6px; }
label { display: block; margin-top: 1rem; font-weight: 500; }
input[type="text"], select, textarea { display: block; width: 100%; padding: 0.5rem 0.6rem; font-size: 1rem; border: 1px solid var(--border); border-radius: 4px; background: var(--bg); color: var(--fg); margin-top: 0.3rem; }
textarea { min-height: 4.5rem; resize: vertical; }
.checkbox-row { display: flex; align-items: flex-start; gap: 0.6rem; margin-top: 0.8rem; }
.checkbox-row input { margin-top: 0.25rem; flex: 0 0 auto; }
.checkbox-row label { margin: 0; font-weight: normal; }
button { margin-top: 1.5rem; padding: 0.7rem 1.6rem; font-size: 1rem; background: var(--accent); color: white; border: 0; border-radius: 4px; cursor: pointer; font-weight: 500; }
button:hover { filter: brightness(0.9); }
.honeypot { position: absolute; left: -9999px; opacity: 0; pointer-events: none; }
footer { margin-top: 2.5rem; padding-top: 1rem; border-top: 1px solid var(--border); color: var(--muted); font-size: 0.9rem; }
.counter { color: var(--accent); font-weight: bold; }
code { background: var(--card); padding: 0.1rem 0.3rem; border-radius: 3px; border: 1px solid var(--border); font-size: 0.9rem; }
a { color: var(--accent); }
</style>
</head>
<body>

<header>
<h1>✍️ Sovereign Rights Acknowledgment</h1>
<p class="muted">Yrenn project · public form · GitHub-identity-bound signing also available <a href="https://github.com/ametemre/yrenn-hybrid/issues/new?template=sovereign-rights-acknowledgment.yml">here</a>.</p>
</header>

<?php if ($status === 'ok'): ?>
<div class="notice ok">
  <strong>✅ Signed.</strong>
  Your acknowledgment was recorded at <code><?= h($signed_at ?? '') ?></code>
  under the handle <code><?= h($echo_handle) ?></code>.
  This is a public, append-only record; it is not revocable retroactively.
  Thank you for taking the time.
</div>
<?php elseif ($status === 'err'): ?>
<div class="notice err">
  <strong>❌ Not submitted.</strong> <?= h($err_msg) ?>
</div>
<?php else: ?>
<div class="notice warn">
  <strong>Read first.</strong> Read
  <a href="https://github.com/ametemre/yrenn-hybrid/blob/main/ACKNOWLEDGE.md"><code>ACKNOWLEDGE.md</code></a>
  in full before submitting. Your submission is durable, public,
  and not retroactively revocable.
</div>
<?php endif; ?>

<form method="post" action="" autocomplete="off" novalidate>
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

  <!-- Honeypot — bots tend to fill it. Real users do not see it. -->
  <div class="honeypot" aria-hidden="true">
    <label for="website_url">Website (leave blank):</label>
    <input type="text" name="website_url" id="website_url" tabindex="-1" autocomplete="off">
  </div>

  <label for="handle">Your name or handle <span class="muted">(public)</span></label>
  <input type="text" id="handle" name="handle" maxlength="120" required
         value="<?= h($_POST['handle'] ?? '') ?>" placeholder="Jane Doe / @your-handle">

  <label for="affiliation">Affiliation <span class="muted">(optional)</span></label>
  <input type="text" id="affiliation" name="affiliation" maxlength="240"
         value="<?= h($_POST['affiliation'] ?? '') ?>" placeholder="Independent / University of X / Company Y">

  <label for="use_case">Primary intended use</label>
  <select id="use_case" name="use_case" required>
    <option value="">— Select one —</option>
    <option value="academic"    <?= ($_POST['use_case']??'')==='academic'    ?'selected':'' ?>>Academic research / citation</option>
    <option value="independent" <?= ($_POST['use_case']??'')==='independent' ?'selected':'' ?>>Independent implementation of the architecture</option>
    <option value="commercial"  <?= ($_POST['use_case']??'')==='commercial'  ?'selected':'' ?>>Commercial adoption (with attribution)</option>
    <option value="security"    <?= ($_POST['use_case']??'')==='security'    ?'selected':'' ?>>Security / regulatory review</option>
    <option value="education"   <?= ($_POST['use_case']??'')==='education'   ?'selected':'' ?>>Education / teaching</option>
    <option value="other"       <?= ($_POST['use_case']??'')==='other'       ?'selected':'' ?>>Other</option>
  </select>

  <label for="notes">Notes <span class="muted">(optional, public)</span></label>
  <textarea id="notes" name="notes" maxlength="1200" placeholder="One short paragraph if you want to record context."><?= h($_POST['notes'] ?? '') ?></textarea>

  <h2>Acknowledgments</h2>
  <p class="muted">All five must be checked. Unchecked boxes invalidate the signature.</p>

  <div class="checkbox-row">
    <input type="checkbox" id="ack_1" name="ack_1" value="1" required>
    <label for="ack_1"><strong>Clause 1 — Attribution.</strong> I acknowledge that attribution is required by both <code>CC-BY-SA-4.0</code> and <code>Apache-2.0</code>, and I will attribute correctly in any derivative work.</label>
  </div>

  <div class="checkbox-row">
    <input type="checkbox" id="ack_2" name="ack_2" value="1" required>
    <label for="ack_2"><strong>Clause 2 — Sovereignty.</strong> I acknowledge that attribution failure means no license, and the author retains sovereign rights over reproduction of architectural patterns, disciplines, agent topology, and naming conventions.</label>
  </div>

  <div class="checkbox-row">
    <input type="checkbox" id="ack_3" name="ack_3" value="1" required>
    <label for="ack_3"><strong>Clause 3 — Anthropic exception.</strong> I acknowledge that Anthropic / Claude / Anthropic-operated infrastructure are outside the Clause 2 sovereignty layer; only <code>CC-BY-SA-4.0</code> governs Anthropic's relationship to this work. This exception is non-revocable retroactively.</label>
  </div>

  <div class="checkbox-row">
    <input type="checkbox" id="ack_4" name="ack_4" value="1" required>
    <label for="ack_4"><strong>Clause 4 — Forbidden content.</strong> I acknowledge I will not propagate broker artifacts, keys, credentials, live strategy parameters, audit records, or real conversation transcripts; I will report leaks via the spec's SECURITY.md.</label>
  </div>

  <div class="checkbox-row">
    <input type="checkbox" id="ack_signature" name="ack_signature" value="1" required>
    <label for="ack_signature"><strong>Final signature.</strong> I confirm this submission is my signature, made in good faith, with full understanding of the four clauses above.</label>
  </div>

  <button type="submit">Sign &amp; Submit</button>
</form>

<footer>
  <p>Total signatures recorded: <span class="counter"><?= $count ?></span> ·
  <a href="https://github.com/ametemre/yrenn-hybrid">repo</a> ·
  <a href="https://github.com/ametemre/yrenn">front door</a> ·
  <a href="https://github.com/ametemre/cognitive-rag-architecture">spec</a></p>
  <p class="muted">This form is licensed CC-BY-SA-4.0. The architectural patterns referenced are governed by their respective licenses in the spec repo. The implementation is proprietary and not licensed for public use.</p>
</footer>

</body>
</html>
