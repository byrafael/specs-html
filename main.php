<?php
declare(strict_types=1);

/*
 * @byrafael/specs-html
 * --------------------------------------------------------------------------
 * A tiny PHP application for hosting AI-generated specs and plans.
 *
 * POST an HTML file to this script, get back a shareable URL, and open it in
 * a browser. One PHP file, no database, and no dependencies.
 *
 * PHP isn't the trendy new framework. That's the point. This is designed to
 * run on inexpensive shared hosting, a VPS, or pretty much anywhere PHP
 * already works, without extra runtime, build steps, or infrastructure.
 *
 * How it works:
 *
 *   • Upload
 *     POST an .html file to this script. It is stored in ./plans/ under a
 *     random, human-friendly name (for example: "brave-otter"), and the
 *     script returns the public URL.
 *
 *   • View
 *     Open that URL in a browser to render the stored HTML.
 *
 *         https://example.com/main.php/brave-otter
 *
 *     It also works when served as a directory index:
 *
 *         https://example.com/specs/brave-otter
 *
 *     No rewrite rules are required. Routing is handled through PATH_INFO.
 *     (An optional .htaccess is included if you want prettier /name URLs.)
 * --------------------------------------------------------------------------
 */

// =========================== CONFIG ========================================

// Shared secret required to upload. CHANGE THIS to a long random string.
// Set to '' to allow uploads with no auth (NOT recommended — anyone could
// host arbitrary HTML on your domain).
const UPLOAD_TOKEN = 'change-me-to-a-long-random-secret';

// Where plans live (created automatically). Kept relative to this file.
const PLANS_DIR = __DIR__ . '/plans';

// Reject uploads larger than this (also bounded by php.ini post_max_size).
const MAX_BYTES = 5 * 1024 * 1024; // 5 MB

// Leave '' to auto-detect. Set it (e.g. 'https://plans.example.com/specs') if
// you want to force a public base URL instead of deriving it from the request.
const BASE_URL = '';

// ===========================================================================

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Requested plan name: prefer PATH_INFO (main.php/<name>), fall back to ?p=
$slug = '';
if (!empty($_SERVER['PATH_INFO'])) {
    $slug = trim($_SERVER['PATH_INFO'], '/');
} elseif (isset($_GET['p'])) {
    $slug = (string) $_GET['p'];
}

if ($method === 'POST') {
    handle_upload();
} elseif ($slug !== '') {
    serve_plan($slug);
} else {
    show_usage();
}
exit;

// =========================== HANDLERS ======================================

function handle_upload(): void
{
    require_token();

    // Accept either multipart (-F file=@plan.html) or a raw body (--data-binary).
    $html = null;
    if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
        if (($_FILES['file']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            fail(400, 'File upload failed (php upload error).');
        }
        $html = file_get_contents($_FILES['file']['tmp_name']);
    } else {
        $html = file_get_contents('php://input');
    }

    if ($html === false || $html === '' || $html === null) {
        fail(400, "Empty body. Send HTML as the request body (--data-binary @plan.html) " .
                  "or as multipart field 'file' (-F file=@plan.html).");
    }
    if (strlen($html) > MAX_BYTES) {
        fail(413, 'Too large. Max is ' . (MAX_BYTES / 1024 / 1024) . ' MB.');
    }

    ensure_plans_dir();

    // Optional custom name via ?name=... ; otherwise pick a friendly random one.
    $requested = (string) ($_GET['name'] ?? $_POST['name'] ?? '');
    $clean = sanitize_slug($requested);
    $slug = $clean !== '' ? make_unique($clean) : unique_slug();

    $path = PLANS_DIR . '/' . $slug . '.html';
    if (file_put_contents($path, $html, LOCK_EX) === false) {
        fail(500, 'Could not write the plan to disk (check permissions on ' . PLANS_DIR . ').');
    }
    @chmod($path, 0644);

    $url = self_base_url() . '/' . $slug;

    $wantsJson = isset($_GET['json'])
        || stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;

    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'slug' => $slug, 'url' => $url], JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        // Plain URL on its own line — easy to copy/pipe from curl.
        header('Content-Type: text/plain; charset=utf-8');
        echo $url . "\n";
    }
    }

function serve_plan(string $slug): void
{
    // Plan names are strictly [a-z0-9-]; anything else is a 404.
    if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
        fail(404, 'No such plan.');
    }

    $path = PLANS_DIR . '/' . $slug . '.html';
    $real = realpath($path);
    $base = realpath(PLANS_DIR);

    // Defense-in-depth: ensure the resolved file really sits inside PLANS_DIR.
    if ($real === false || $base === false ||
        strncmp($real, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) !== 0) {
        fail(404, 'No such plan.');
    }

    $html = file_get_contents($real);
    if ($html === false) {
        fail(500, 'Could not read the plan.');
    }

    $base = self_base_url();
    $baseEsc = htmlspecialchars($base, ENT_QUOTES, 'UTF-8');

    // Header markup to insert into plans
    $slugEsc = htmlspecialchars($slug, ENT_QUOTES, 'UTF-8');
    $headerHtml = "<div class=\"specs-header\">\n"
        . "  <a class=\"specs-back\" href=\"{$baseEsc}\" aria-label=\"Back to specs\">←</a>\n"
        . "  <div class=\"specs-title\">📮 specs/{$slugEsc}</div>\n"
        . "  <div class=\"specs-copyright\">© 2026 Rafael S.</div>\n"
        . "</div>\n";

    // Styling for the header — injected into <head> when possible
    $style = "<style>\n"
        . ".specs-header{box-sizing:border-box;display:grid;grid-template-columns:auto 1fr auto;align-items:center;padding:22px 22px 18px;gap:0;background:linear-gradient(180deg,rgba(18,20,26,.98),rgba(12,14,18,.95));border-bottom:1px solid rgba(255,255,255,.08);box-shadow:inset 0 -1px 0 rgba(255,255,255,.03);backdrop-filter:saturate(140%) blur(10px);-webkit-backdrop-filter:saturate(140%) blur(10px);color:rgba(255,255,255,.92)}\n"
        . ".specs-back{text-decoration:none;color:rgba(255,255,255,.72);font-size:1.2rem;line-height:1;justify-self:start}\n"
        . ".specs-title{font-weight:600;justify-self:start;margin-left:8px;color:rgba(255,255,255,.92)}\n"
        . ".specs-copyright{opacity:.72;font-size:.95rem;justify-self:end;color:rgba(255,255,255,.72)}\n"
        . "body{padding-top:calc(24px + env(safe-area-inset-top));}\n"
        . "</style>\n";

    // Inject style into head if present
    if (preg_match('/<\/head>/i', $html)) {
        $html = preg_replace('/<\/head>/i', $style . '</head>', $html, 1);
    } else {
        $html = $style . $html;
    }

    // Insert header after the opening <body> tag if present, otherwise prepend
    if (preg_match('/<body[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE)) {
        $pos = $m[0][1] + strlen($m[0][0]);
        $html = substr($html, 0, $pos) . "\n" . $headerHtml . substr($html, $pos);
    } else {
        $html = $headerHtml . $html;
    }

    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . strlen($html));
    echo $html;
}

function show_usage(): void
{
    $base = self_base_url();
    header('Content-Type: text/html; charset=utf-8');
    $token   = UPLOAD_TOKEN === '' ? '(no token required)' : 'YOUR_TOKEN';
    $baseEsc = htmlspecialchars($base, ENT_QUOTES, 'UTF-8');
    $tokEsc  = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>📮 @byrafael/specs-html</title>
<style>
  :root { color-scheme: light dark; }
  body { font: 15px/1.6 system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
         max-width: 680px; margin: 8vh auto; padding: 0 20px; }
  h1 { font-size: 1.6rem; margin-bottom: .25rem; }
  p.sub { opacity: .7; margin-top: 0; }
  pre { background: rgba(127,127,127,.14); padding: 14px 16px; border-radius: 10px;
        overflow-x: auto; }
  code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
  .muted { opacity: .6; font-size: .9rem; }
</style>
</head>
<body>
  <h1>📮 @byrafael/specs-html</h1>
  <p class="sub">Publish a static HTML spec or plan, get a shareable link.</p>

  <h3>Upload</h3>
  <pre><code>curl -X POST --data-binary @plan.html \\
     -H "Authorization: Bearer {$tokEsc}" \\
     {$baseEsc}/</code></pre>

  <p>The response is the URL of your plan, e.g.
     <code>{$baseEsc}/brave-otter</code> — open it in a browser.</p>

  <h3 class="muted">Notes</h3>
  <ul class="muted">
    <li>Multipart also works: <code>-F file=@plan.html</code></li>
    <li>Pick your own name: add <code>?name=my-plan</code> to the upload URL.</li>
    <li>Get JSON back: add <code>?json</code> or <code>Accept: application/json</code>.</li>
  </ul>
    <p class="muted">Get an access token from <a href="https://rsrdev.com" target="_blank" rel="noopener noreferrer">Rafael Soley</a> or self-host: <a href="https://github.com/byrafael/specs-html" target="_blank" rel="noopener noreferrer">byrafael/specs-html</a></p>
</body>
</html>
HTML;
}

// =========================== HELPERS =======================================

function require_token(): void
{
    if (UPLOAD_TOKEN === '') {
        return; // open uploads (you were warned)
    }

    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($auth === '' && function_exists('apache_request_headers')) {
        $hdrs = apache_request_headers();
        $auth = $hdrs['Authorization'] ?? ($hdrs['authorization'] ?? '');
    }

    $token = '';
    if (preg_match('/Bearer\s+(.+)/i', $auth, $m)) {
        $token = trim($m[1]);
    }
    if ($token === '') {
        $token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
    }

    if (!hash_equals(UPLOAD_TOKEN, $token)) {
        http_response_code(401);
        header('Content-Type: text/plain; charset=utf-8');
        echo "401 Unauthorized.\n";
        echo "Send the upload token: -H \"Authorization: Bearer <token>\"\n";
        exit;
    }
}

function ensure_plans_dir(): void
{
    if (!is_dir(PLANS_DIR)) {
        if (!@mkdir(PLANS_DIR, 0755, true) && !is_dir(PLANS_DIR)) {
            fail(500, 'Cannot create plans directory: ' . PLANS_DIR);
        }
    }
}

function sanitize_slug(string $s): string
{
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9-]+/', '-', $s);
    $s = preg_replace('/-+/', '-', $s);
    $s = trim($s, '-');
    return substr($s, 0, 60);
}

function unique_slug(): string
{
    $adjectives = slug_adjectives();
    $nouns = slug_nouns();
    $adjectiveCount = count($adjectives);
    $nounCount = count($nouns);

    $startA1 = random_int(0, $adjectiveCount - 1);
    $startA2 = random_int(0, $adjectiveCount - 1);
    $startN = random_int(0, $nounCount - 1);

    for ($a1Offset = 0; $a1Offset < $adjectiveCount; $a1Offset++) {
        $a1 = $adjectives[($startA1 + $a1Offset) % $adjectiveCount];
        for ($a2Offset = 0; $a2Offset < $adjectiveCount; $a2Offset++) {
            $a2 = $adjectives[($startA2 + $a2Offset) % $adjectiveCount];
            for ($nOffset = 0; $nOffset < $nounCount; $nOffset++) {
                $n = $nouns[($startN + $nOffset) % $nounCount];
                $slug = $a1 . '-' . $a2 . '-' . $n;
                if (!file_exists(PLANS_DIR . '/' . $slug . '.html')) {
                    return $slug;
                }
            }
        }
    }

    return random_short_id();
}

function make_unique(string $slug): string
{
    if (!file_exists(PLANS_DIR . '/' . $slug . '.html')) {
        return $slug;
    }
    for ($i = 2; $i < 10000; $i++) {
        $candidate = $slug . '-' . $i;
        if (!file_exists(PLANS_DIR . '/' . $candidate . '.html')) {
            return $candidate;
        }
    }

    return $slug . '-' . random_short_id();
}

function random_name(): string
{
    $adjectives = slug_adjectives();
    $nouns = slug_nouns();
    $a1 = $adjectives[random_int(0, count($adjectives) - 1)];
    $a2 = $adjectives[random_int(0, count($adjectives) - 1)];
    $n = $nouns[random_int(0, count($nouns) - 1)];

    return $a1 . '-' . $a2 . '-' . $n;
}

function slug_adjectives(): array
{
    return [
        'brave', 'calm', 'clever', 'cosmic', 'crimson', 'dapper', 'eager',
        'fuzzy', 'gentle', 'golden', 'happy', 'jolly', 'keen', 'lively',
        'lucky', 'mellow', 'merry', 'nimble', 'noble', 'plucky', 'quiet',
        'quirky', 'rapid', 'royal', 'rustic', 'sleepy', 'snappy', 'spry',
        'sunny', 'swift', 'tidy', 'vivid', 'witty', 'zesty', 'azure',
        'breezy', 'cozy', 'frosty', 'misty', 'silver', 'amber', 'bold',
        'bright', 'choral', 'drift', 'ember', 'glossy', 'hearty', 'hushed',
        'lilac', 'lucid', 'meadow', 'noisy', 'pebble', 'proud', 'shiny',
        'sturdy', 'velvet', 'wild',
    ];
}

function slug_nouns(): array
{
    return [
        'otter', 'falcon', 'maple', 'comet', 'badger', 'heron', 'lynx',
        'panda', 'willow', 'cedar', 'robin', 'pixel', 'meadow', 'harbor',
        'cobra', 'finch', 'gecko', 'walrus', 'marmot', 'bison', 'narwhal',
        'puffin', 'quokka', 'raccoon', 'salmon', 'tapir', 'urchin', 'viper',
        'wombat', 'yak', 'zebra', 'ember', 'glacier', 'canyon', 'river',
        'beacon', 'orchard', 'thicket', 'lagoon', 'summit', 'atlas', 'brook',
        'drift', 'field', 'grove', 'horizon', 'island', 'jewel', 'kernel',
        'meteor', 'north', 'oasis', 'prairie', 'quartz', 'signal', 'tower',
    ];
}

function random_short_id(): string
{
    return rtrim(strtr(base64_encode(random_bytes(6)), '+/', '-_'), '=');
}

function self_base_url(): string
{
    if (BASE_URL !== '') {
        return rtrim(BASE_URL, '/');
    }

    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443');

    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/main.php');
    $scriptPath = '/' . ltrim(rtrim($script, '/'), '/');
    $scriptDir = rtrim(str_replace('\\', '/', dirname($scriptPath)), '/');
    if ($scriptDir === '') {
        $scriptDir = '/';
    }

    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: $scriptPath;
    $requestPath = rtrim($requestPath, '/');
    if ($requestPath === '') {
        $requestPath = '/';
    }

    if (str_starts_with($requestPath, $scriptPath)) {
        $publicPath = $scriptPath;
    } elseif ($scriptDir !== '/' && str_starts_with($requestPath, $scriptDir)) {
        $publicPath = $scriptDir;
    } else {
        $publicPath = '';
    }

    return $scheme . '://' . $host . $publicPath;
}

function fail(int $code, string $msg): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $code . ' ' . $msg . "\n";
    exit;
}
