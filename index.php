<?php
/**
 * xsukax RSS Filter
 * Single-file RSS feed filtering service with SQLite, authentication, and edit support.
 *
 * @author  xsukax
 * @license GPL-3.0 (https://www.gnu.org/licenses/gpl-3.0.html)
 */

declare(strict_types=1);

define('APP_NAME',    'xsukax RSS Filter');
define('APP_VERSION', '1.0.0');
define('DB_PATH',     dirname(__FILE__) . '/../rss_filter.db');
define('AUTH_USER',   'admin');
define('AUTH_PASS',   'admin@123');
define('SESSION_KEY', 'xsukax_rss_auth');

mb_internal_encoding('UTF-8');
mb_language('uni');

if (session_status() === PHP_SESSION_NONE) session_start();

$db     = initDB();
$action = isset($_GET['action']) ? trim($_GET['action']) : '';

if ($action === 'feed' && !empty($_GET['token'])) { serveFeed($db, (string)$_GET['token']); exit; }

if (!isLoggedIn() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $u = trim((string)($_POST['username'] ?? ''));
    $p = (string)($_POST['password'] ?? '');
    if (hash_equals(AUTH_USER, $u) && hash_equals(AUTH_PASS, $p)) {
        session_regenerate_id(true);
        $_SESSION[SESSION_KEY] = true;
        header('Location: ' . selfURL()); exit;
    }
    $_SESSION['login_error'] = 'Invalid username or password.';
    header('Location: ' . selfURL()); exit;
}

if (!isLoggedIn()) { renderLogin(); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['logout']))      { session_destroy(); header('Location: ' . selfURL()); exit; }
    if (isset($_POST['add_feed']))    handleAddFeed($db);
    if (isset($_POST['edit_feed']))   handleEditFeed($db);
    if (isset($_POST['delete_feed'])) handleDeleteFeed($db);
}

renderDashboard($db);
exit;

// =============================================================================
// DATABASE
// =============================================================================
function initDB(): PDO {
    try {
        $dir = dirname(DB_PATH);
        if (!is_dir($dir))      throw new RuntimeException('DB directory missing: '       . $dir);
        if (!is_writable($dir)) throw new RuntimeException('DB directory not writable: '  . $dir);
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec("PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;");
        $pdo->exec("CREATE TABLE IF NOT EXISTS feeds (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            name       TEXT NOT NULL,
            url        TEXT NOT NULL,
            title_kw   TEXT NOT NULL DEFAULT '',
            desc_kw    TEXT NOT NULL DEFAULT '',
            both_kw    TEXT NOT NULL DEFAULT '',
            logic      TEXT NOT NULL DEFAULT 'OR',
            token      TEXT NOT NULL UNIQUE,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        );");
        return $pdo;
    } catch (Exception $e) {
        http_response_code(500);
        exit('<pre>DB Error: ' . htmlspecialchars($e->getMessage()) . '</pre>');
    }
}

// =============================================================================
// AUTH
// =============================================================================
function isLoggedIn(): bool { return !empty($_SESSION[SESSION_KEY]); }

// =============================================================================
// FEED CRUD
// =============================================================================
function handleAddFeed(PDO $db): void {
    $name   = trim((string)($_POST['name']     ?? ''));
    $url    = trim((string)($_POST['url']      ?? ''));
    $titleK = trim((string)($_POST['title_kw'] ?? ''));
    $descK  = trim((string)($_POST['desc_kw']  ?? ''));
    $bothK  = trim((string)($_POST['both_kw']  ?? ''));
    $logic  = (isset($_POST['logic']) && $_POST['logic'] === 'AND') ? 'AND' : 'OR';

    if ($name === '' || $url === '')                       { $_SESSION['form_error'] = 'Name and URL are required.';     header('Location: ' . selfURL()); exit; }
    if (filter_var($url, FILTER_VALIDATE_URL) === false)   { $_SESSION['form_error'] = 'Please enter a valid feed URL.'; header('Location: ' . selfURL()); exit; }

    $token = bin2hex(random_bytes(32));
    $db->prepare("INSERT INTO feeds (name,url,title_kw,desc_kw,both_kw,logic,token) VALUES (?,?,?,?,?,?,?)")
       ->execute([$name, $url, $titleK, $descK, $bothK, $logic, $token]);

    $_SESSION['form_success'] = 'Feed "' . $name . '" added.';
    header('Location: ' . selfURL()); exit;
}

function handleEditFeed(PDO $db): void {
    $id     = (int)($_POST['feed_id']  ?? 0);
    $name   = trim((string)($_POST['name']     ?? ''));
    $url    = trim((string)($_POST['url']      ?? ''));
    $titleK = trim((string)($_POST['title_kw'] ?? ''));
    $descK  = trim((string)($_POST['desc_kw']  ?? ''));
    $bothK  = trim((string)($_POST['both_kw']  ?? ''));
    $logic  = (isset($_POST['logic']) && $_POST['logic'] === 'AND') ? 'AND' : 'OR';

    if ($id <= 0 || $name === '' || $url === '')         { $_SESSION['form_error'] = 'Invalid data.';              header('Location: ' . selfURL()); exit; }
    if (filter_var($url, FILTER_VALIDATE_URL) === false) { $_SESSION['form_error'] = 'Please enter a valid URL.';  header('Location: ' . selfURL()); exit; }

    $db->prepare("UPDATE feeds SET name=?,url=?,title_kw=?,desc_kw=?,both_kw=?,logic=? WHERE id=?")
       ->execute([$name, $url, $titleK, $descK, $bothK, $logic, $id]);

    $_SESSION['form_success'] = 'Feed "' . $name . '" updated.';
    header('Location: ' . selfURL()); exit;
}

function handleDeleteFeed(PDO $db): void {
    $id = (int)($_POST['feed_id'] ?? 0);
    if ($id > 0) $db->prepare("DELETE FROM feeds WHERE id=?")->execute([$id]);
    header('Location: ' . selfURL()); exit;
}

// =============================================================================
// FEED SERVING (PUBLIC)
// =============================================================================
function serveFeed(PDO $db, string $rawToken): void {
    $token = preg_replace('/[^a-f0-9]/i', '', $rawToken);
    if (strlen($token) < 16) { http_response_code(400); header('Content-Type: text/plain'); echo '400 Bad token.'; return; }

    $stmt = $db->prepare("SELECT * FROM feeds WHERE token=?");
    $stmt->execute([$token]);
    $feed = $stmt->fetch();

    if (!$feed) { http_response_code(404); header('Content-Type: text/plain'); echo '404 Feed not found.'; return; }

    $raw = fetchURL($feed['url']);
    if ($raw === false || trim($raw) === '') { http_response_code(502); header('Content-Type: text/plain'); echo '502 Cannot fetch upstream feed.'; return; }

    $raw = ensureUTF8($raw);

    $tKw   = parseKeywords($feed['title_kw']);
    $dKw   = parseKeywords($feed['desc_kw']);
    $bKw   = parseKeywords($feed['both_kw']);
    $logic = $feed['logic'];

    libxml_use_internal_errors(true);
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->recover = true;
    $dom->strictErrorChecking = false;
    $loaded = @$dom->loadXML($raw);
    libxml_clear_errors();

    if (!$loaded) { http_response_code(502); header('Content-Type: text/plain'); echo '502 Malformed feed from upstream.'; return; }

    $root   = $dom->documentElement;
    $isAtom = $root && $root->localName === 'feed';

    if ($isAtom) outputAtom($dom, $feed['name'], $tKw, $dKw, $bKw, $logic);
    else         outputRSS2($dom, $feed['name'], $tKw, $dKw, $bKw, $logic);
}

// ── Keyword helpers ───────────────────────────────────────────────────────────
function parseKeywords(string $raw): array {
    if (trim($raw) === '') return [];
    $out = [];
    foreach (explode(',', $raw) as $p) { $p = trim($p); if ($p !== '') $out[] = mb_strtolower($p, 'UTF-8'); }
    return $out;
}

function matchesAny(string $text, array $kws): bool {
    if (empty($kws) || trim($text) === '') return false;
    $text = mb_strtolower($text, 'UTF-8');
    foreach ($kws as $kw) { if (mb_strpos($text, $kw, 0, 'UTF-8') !== false) return true; }
    return false;
}

function itemPasses(string $title, string $desc, array $tKw, array $dKw, array $bKw, string $logic): bool {
    if (empty($tKw) && empty($dKw) && empty($bKw)) return true;

    $results = [];
    if (!empty($tKw)) $results[] = matchesAny($title, $tKw);
    if (!empty($dKw)) $results[] = matchesAny($desc,  $dKw);
    if (!empty($bKw)) $results[] = matchesAny($title, $bKw) || matchesAny($desc, $bKw);

    if (empty($results)) return true;

    if ($logic === 'AND') { foreach ($results as $r) { if (!$r) return false; } return true; }
    foreach ($results as $r) { if ($r) return true; }
    return false;
}

// ── RSS 2.0 output ────────────────────────────────────────────────────────────
function outputRSS2(DOMDocument $dom, string $feedName, array $tKw, array $dKw, array $bKw, string $logic): void {
    header('Content-Type: application/rss+xml; charset=UTF-8');
    $xpath = new DOMXPath($dom);
    foreach (['content' => 'http://purl.org/rss/1.0/modules/content/', 'dc' => 'http://purl.org/dc/elements/1.1/', 'media' => 'http://search.yahoo.com/mrss/'] as $pfx => $ns) $xpath->registerNamespace($pfx, $ns);

    $chanTitle = xpathText($xpath, '//channel/title');
    $chanLink  = xpathText($xpath, '//channel/link');
    $chanDesc  = xpathText($xpath, '//channel/description');

    $out  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n<rss version=\"2.0\">\n<channel>\n";
    $out .= '<title>'       . xe($chanTitle ?: $feedName) . ' [Filtered]</title>' . "\n";
    $out .= '<link>'        . xe($chanLink)  . '</link>'        . "\n";
    $out .= '<description>' . xe($chanDesc)  . '</description>' . "\n";

    $items = $xpath->query('//channel/item');
    if ($items) {
        foreach ($items as $item) {
            $title = trim(domText($xpath, $item, 'title'));
            $desc  = trim(domText($xpath, $item, 'description'));
            if ($desc === '') { $cn = $xpath->query('content:encoded', $item); if ($cn && $cn->length > 0) $desc = $cn->item(0)->textContent; }
            if (!itemPasses($title, strip_tags($desc), $tKw, $dKw, $bKw, $logic)) continue;
            $link    = trim(domText($xpath, $item, 'link'));
            $pubDate = trim(domText($xpath, $item, 'pubDate'));
            $guid    = trim(domText($xpath, $item, 'guid'));
            $out .= "<item>\n  <title>"       . xe($title)   . "</title>\n";
            $out .= "  <link>"        . xe($link)    . "</link>\n";
            $out .= "  <description>" . xe($desc)    . "</description>\n";
            if ($pubDate !== '') $out .= "  <pubDate>" . xe($pubDate) . "</pubDate>\n";
            if ($guid    !== '') $out .= "  <guid>"    . xe($guid)    . "</guid>\n";
            $out .= "</item>\n";
        }
    }
    echo $out . "</channel>\n</rss>";
}

// ── Atom output ───────────────────────────────────────────────────────────────
function outputAtom(DOMDocument $dom, string $feedName, array $tKw, array $dKw, array $bKw, string $logic): void {
    header('Content-Type: application/atom+xml; charset=UTF-8');
    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('a', 'http://www.w3.org/2005/Atom');

    $feedTitle   = xpathText($xpath, '//a:feed/a:title') ?: xpathText($xpath, '//feed/title');
    $feedId      = xpathText($xpath, '//a:feed/a:id')    ?: xpathText($xpath, '//feed/id');
    $feedUpdated = xpathText($xpath, '//a:feed/a:updated') ?: xpathText($xpath, '//feed/updated');

    $out  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n<feed xmlns=\"http://www.w3.org/2005/Atom\">\n";
    $out .= '<title>'  . xe($feedTitle ?: $feedName) . ' [Filtered]</title>' . "\n";
    $out .= '<id>'     . xe($feedId)      . '</id>'      . "\n";
    $out .= '<updated>'. xe($feedUpdated) . '</updated>' . "\n";

    $entries = $xpath->query('//a:entry') ?: $xpath->query('//entry');
    if ($entries) {
        foreach ($entries as $entry) {
            $title   = trim(domText($xpath, $entry, 'title'));
            $summary = trim(domText($xpath, $entry, 'summary'));
            $content = trim(domText($xpath, $entry, 'content'));
            if (!itemPasses($title, strip_tags($summary !== '' ? $summary : $content), $tKw, $dKw, $bKw, $logic)) continue;
            $id      = trim(domText($xpath, $entry, 'id'));
            $updated = trim(domText($xpath, $entry, 'updated'));
            $out .= "<entry>\n  <title>"   . xe($title)   . "</title>\n";
            $out .= "  <id>"      . xe($id)       . "</id>\n";
            $out .= "  <updated>" . xe($updated)  . "</updated>\n";
            if ($summary !== '') $out .= "  <summary>" . xe($summary) . "</summary>\n";
            $links = $xpath->query('a:link | link', $entry);
            if ($links) { foreach ($links as $lnk) { $href = $lnk->getAttribute('href'); $rel = $lnk->getAttribute('rel') ?: 'alternate'; if ($href !== '') $out .= '  <link rel="' . xe($rel) . '" href="' . xe($href) . '"/>' . "\n"; } }
            $out .= "</entry>\n";
        }
    }
    echo $out . '</feed>';
}

// ── DOM helpers ───────────────────────────────────────────────────────────────
function xpathText(DOMXPath $x, string $q): string { $n = $x->query($q); return ($n && $n->length > 0) ? $n->item(0)->textContent : ''; }
function domText(DOMXPath $x, DOMNode $ctx, string $tag): string { foreach ([$tag, 'a:' . $tag] as $q) { $n = @$x->query($q, $ctx); if ($n && $n->length > 0) return $n->item(0)->textContent; } return ''; }

// ── Encoding ──────────────────────────────────────────────────────────────────
function ensureUTF8(string $raw): string {
    if (preg_match('/<\?xml[^>]+encoding=["\']([^"\']+)["\']/', $raw, $m)) {
        $enc = strtoupper(trim($m[1]));
        if ($enc !== 'UTF-8') { $c = @mb_convert_encoding($raw, 'UTF-8', $enc); if ($c !== false && $c !== '') $raw = preg_replace('/(encoding=["\'])([^"\']+)(["\'])/', '${1}UTF-8${3}', $c); }
    }
    if (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'auto');
    return $raw;
}
function xe(string $s): string { return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }

// =============================================================================
// HTTP FETCH
// =============================================================================
function fetchURL(string $url) {
    if (!function_exists('curl_init')) {
        $ctx = stream_context_create(['http' => ['user_agent' => 'Mozilla/5.0 (compatible; xsukax-RSS-Filter/1.0)', 'timeout' => 20, 'ignore_errors' => true]]);
        $r = @file_get_contents($url, false, $ctx);
        return ($r !== false) ? $r : false;
    }
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER => ['Accept: application/rss+xml, application/atom+xml, application/xml, text/xml, */*;q=0.8', 'Accept-Language: ar,en-US;q=0.9,en;q=0.8', 'Cache-Control: no-cache'],
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($ch); $errno = curl_errno($ch); curl_close($ch);
    return ($errno === 0 && $body !== false) ? (string)$body : false;
}

// =============================================================================
// UTILITY
// =============================================================================
function selfURL(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path  = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    return ($https ? 'https' : 'http') . '://' . $host . $path;
}
function feedURL(string $token): string { return selfURL() . '?action=feed&token=' . rawurlencode($token); }
function shortenStr(string $s, int $max = 46): string { return mb_strlen($s, 'UTF-8') > $max ? mb_substr($s, 0, $max, 'UTF-8') . '…' : $s; }
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function logo(int $sz = 32): string { return '<svg width="'.$sz.'" height="'.$sz.'" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg"><rect width="40" height="40" rx="8" fill="#0969da"/><path d="M10 28 Q10 12 28 12" stroke="#fff" stroke-width="3" fill="none" stroke-linecap="round"/><path d="M10 28 Q10 18 22 18" stroke="#fff" stroke-width="3" fill="none" stroke-linecap="round"/><circle cx="11" cy="29" r="2.5" fill="#fff"/></svg>'; }

// =============================================================================
// CSS
// =============================================================================
function css(): string { return '<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif;font-size:14px;line-height:1.6;color:#24292f;background:#f6f8fa;}
a{color:#0969da;text-decoration:none;}
a:hover{text-decoration:underline;}
.container{max-width:1260px;margin:0 auto;padding:0 16px;}
.site-header{background:#fff;border-bottom:1px solid #d0d7de;position:sticky;top:0;z-index:100;}
.header-inner{display:flex;align-items:center;justify-content:space-between;height:56px;}
.header-brand{display:flex;align-items:center;gap:8px;}
.brand-name{font-weight:600;font-size:15px;color:#24292f;}
.brand-ver{font-size:11px;color:#57606a;background:#eaeef2;padding:2px 6px;border-radius:20px;}
.main-content{padding:24px 16px;}
.mt{margin-top:20px;}
.card{background:#fff;border:1px solid #d0d7de;border-radius:6px;overflow:hidden;}
.card-header{padding:12px 16px;border-bottom:1px solid #d0d7de;background:#f6f8fa;display:flex;align-items:center;gap:8px;}
.card-title{font-size:14px;font-weight:600;color:#24292f;}
.card-body{padding:16px;}
.card-body.p0{padding:0;}
.form-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px;margin-bottom:12px;}
.form-group{display:flex;flex-direction:column;gap:4px;}
.form-group label{font-size:13px;font-weight:500;color:#24292f;}
.form-input{padding:5px 12px;border:1px solid #d0d7de;border-radius:6px;font-size:13px;line-height:20px;color:#24292f;background:#fff;width:100%;transition:border .15s,box-shadow .15s;}
.form-input:focus{outline:none;border-color:#0969da;box-shadow:0 0 0 3px rgba(9,105,218,.15);}
.form-hint-box{font-size:12px;color:#57606a;background:#f6f8fa;border:1px solid #d0d7de;border-radius:6px;padding:10px 12px;margin-bottom:12px;line-height:1.8;}
.req{color:#cf222e;}
.hint{font-weight:400;color:#57606a;font-size:11px;}
.btn{display:inline-flex;align-items:center;justify-content:center;padding:5px 16px;font-size:13px;font-weight:500;border-radius:6px;border:1px solid #d0d7de;cursor:pointer;transition:background .15s,border-color .15s,color .15s;white-space:nowrap;background:#f6f8fa;color:#24292f;text-decoration:none;line-height:1.4;}
.btn:hover{background:#eaeef2;}
.btn-primary{background:#0969da;border-color:#0969da;color:#fff;}
.btn-primary:hover{background:#0860ca;border-color:#0860ca;}
.btn-sm{padding:3px 12px;font-size:12px;}
.btn-xs{padding:2px 8px;font-size:11px;}
.btn-warn{background:#fff8e1;border-color:#e3a008;color:#7a4600;}
.btn-warn:hover{background:#e3a008;color:#fff;}
.btn-danger{background:#fff0f0;border-color:#cf222e;color:#cf222e;}
.btn-danger:hover{background:#cf222e;color:#fff;}
.btn-full{width:100%;margin-top:4px;}
/* Logic toggle */
.logic-row{grid-column:1/-1;display:flex;align-items:center;gap:12px;padding:4px 0;}
.logic-row .logic-label{font-size:12px;color:#57606a;}
.logic-toggle{position:relative;display:inline-flex;border:1px solid #d0d7de;border-radius:6px;overflow:hidden;background:#f6f8fa;flex-shrink:0;}
.logic-toggle input[type=hidden]{display:none;}
.logic-opt{padding:4px 14px;font-size:12px;font-weight:600;cursor:pointer;transition:background .15s,color .15s;user-select:none;line-height:1.6;}
.logic-opt.or-opt{border-right:1px solid #d0d7de;}
.logic-opt.active-or{background:#0969da;color:#fff;}
.logic-opt.active-and{background:#1a7f37;color:#fff;}
.logic-desc{font-size:11px;color:#57606a;font-style:italic;}
/* badge */
.badge{display:inline-block;padding:1px 7px;font-size:11px;font-weight:500;border-radius:20px;background:#eaeef2;color:#57606a;}
.badge-or{background:#ddf4ff;color:#0969da;}
.badge-and{background:#d1f0e5;color:#116329;}
/* flash */
.flash{padding:10px 14px;border-radius:6px;margin-bottom:16px;font-size:13px;}
.flash-error{background:#fff0f0;border:1px solid #ffb8b8;color:#cf222e;}
.flash-ok{background:#d1f0e5;border:1px solid #a3d9be;color:#116329;}
/* table */
.table-wrap{overflow-x:auto;}
.data-table{width:100%;border-collapse:collapse;font-size:13px;}
.data-table th{padding:8px 12px;background:#f6f8fa;border-bottom:1px solid #d0d7de;font-weight:600;color:#57606a;text-align:left;white-space:nowrap;}
.data-table td{padding:8px 12px;border-bottom:1px solid #eaeef2;vertical-align:middle;}
.data-table tr:last-child td{border-bottom:none;}
.data-table tr:hover td{background:#f6f8fa;}
.td-name{font-weight:500;white-space:nowrap;}
.td-kw{font-family:monospace;font-size:11px;color:#57606a;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.td-feed{min-width:200px;}
.td-date{color:#57606a;white-space:nowrap;}
.td-actions{white-space:nowrap;}
.feed-link{font-size:11px;font-family:monospace;word-break:break-all;}
.link-muted{color:#57606a;}
.empty-state{padding:40px;text-align:center;color:#57606a;}
/* login */
.login-body{background:#f6f8fa;display:flex;align-items:center;justify-content:center;min-height:100vh;}
.login-box{background:#fff;border:1px solid #d0d7de;border-radius:10px;padding:32px;width:100%;max-width:360px;box-shadow:0 4px 20px rgba(0,0,0,.06);}
.login-logo{display:flex;justify-content:center;margin-bottom:12px;}
.login-title{font-size:20px;font-weight:600;text-align:center;color:#24292f;margin-bottom:4px;}
.login-sub{font-size:13px;color:#57606a;text-align:center;margin-bottom:20px;}
/* modal */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;align-items:center;justify-content:center;}
.modal-overlay.open{display:flex;}
.modal{background:#fff;border:1px solid #d0d7de;border-radius:8px;width:100%;max-width:740px;max-height:90vh;overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,.18);}
.modal-header{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid #d0d7de;background:#f6f8fa;}
.modal-title{font-size:14px;font-weight:600;}
.modal-close{background:none;border:none;font-size:20px;cursor:pointer;color:#57606a;line-height:1;padding:0 4px;}
.modal-close:hover{color:#24292f;}
.modal-body{padding:16px;}
/* footer */
.site-footer{border-top:1px solid #d0d7de;padding:16px 0;margin-top:32px;font-size:12px;color:#57606a;text-align:center;}
.copy-btn{margin-left:5px;vertical-align:middle;}
@media(max-width:640px){.form-grid{grid-template-columns:1fr;}.data-table{font-size:12px;}.modal{margin:8px;max-width:100%;}.logic-row{flex-wrap:wrap;}}
</style>'; }

// =============================================================================
// LOGIC TOGGLE HTML helper
// =============================================================================
function logicToggleHTML(string $prefix = '', string $current = 'OR'): string {
    $isAnd   = $current === 'AND';
    $orCls   = $isAnd ? 'logic-opt or-opt'          : 'logic-opt or-opt active-or';
    $andCls  = $isAnd ? 'logic-opt and-opt active-and' : 'logic-opt and-opt';
    $descTxt = $isAnd ? 'Title <strong>AND</strong> Desc must both match'
                      : 'Title <strong>OR</strong> Desc can match';
    $val     = $isAnd ? 'AND' : 'OR';
    $id      = $prefix . 'logic_toggle';
    return '<div class="logic-row">
  <span class="logic-label">Relation:</span>
  <div class="logic-toggle" id="' . $id . '" data-prefix="' . h($prefix) . '">
    <input type="hidden" name="logic" value="' . $val . '">
    <span class="' . $orCls  . '" data-val="OR">OR</span>
    <span class="' . $andCls . '" data-val="AND">AND</span>
  </div>
  <span class="logic-desc" id="' . $id . '_desc">' . $descTxt . '</span>
</div>';
}

// =============================================================================
// RENDER: LOGIN
// =============================================================================
function renderLogin(): void {
    $err = isset($_SESSION['login_error']) ? $_SESSION['login_error'] : '';
    unset($_SESSION['login_error']);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . APP_NAME . ' &ndash; Login</title>' . css() . '</head><body class="login-body"><div class="login-box"><div class="login-logo">' . logo(40) . '</div><h1 class="login-title">' . APP_NAME . '</h1><p class="login-sub">Sign in to manage your filtered feeds</p>';
    if ($err) echo '<div class="flash flash-error">' . h($err) . '</div>';
    echo '<form method="post" action=""><div class="form-group" style="margin-bottom:12px"><label for="u">Username</label><input id="u" name="username" type="text" class="form-input" placeholder="admin" required autofocus></div><div class="form-group" style="margin-bottom:16px"><label for="p">Password</label><input id="p" name="password" type="password" class="form-input" placeholder="••••••••" required></div><button type="submit" name="login" class="btn btn-primary btn-full">Sign in</button></form></div></body></html>';
}

// =============================================================================
// RENDER: DASHBOARD
// =============================================================================
function renderDashboard(PDO $db): void {
    $feeds = $db->query("SELECT * FROM feeds ORDER BY created_at DESC")->fetchAll();
    $fErr  = isset($_SESSION['form_error'])   ? $_SESSION['form_error']   : '';
    $fOk   = isset($_SESSION['form_success']) ? $_SESSION['form_success'] : '';
    unset($_SESSION['form_error'], $_SESSION['form_success']);

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . APP_NAME . '</title>' . css() . '</head><body>';
    echo '<header class="site-header"><div class="container header-inner"><div class="header-brand">' . logo(28) . '<span class="brand-name">' . APP_NAME . '</span><span class="brand-ver">v' . APP_VERSION . '</span></div><form method="post"><button type="submit" name="logout" class="btn btn-sm">Sign out</button></form></div></header>';
    echo '<main class="container main-content">';
    if ($fErr) echo '<div class="flash flash-error">' . h($fErr) . '</div>';
    if ($fOk)  echo '<div class="flash flash-ok">'    . h($fOk)  . '</div>';

    // ── Add form ─────────────────────────────────────────────────────────────
    echo '<section class="card">
<div class="card-header"><h2 class="card-title">Add New Filtered Feed</h2></div>
<div class="card-body">
<form method="post" action="">
<div class="form-grid">
  <div class="form-group"><label>Feed Name <span class="req">*</span></label><input name="name" type="text" class="form-input" placeholder="e.g. My News Feed" required></div>
  <div class="form-group"><label>Feed URL <span class="req">*</span></label><input name="url" type="url" class="form-input" placeholder="e.g. https://example.com/rss" required></div>
  <div class="form-group"><label>Title Keywords <span class="hint">(comma-separated)</span></label><input name="title_kw" type="text" class="form-input" placeholder="e.g. politics, economy"></div>'
  . logicToggleHTML('add_') .
  '<div class="form-group"><label>Description Keywords <span class="hint">(comma-separated)</span></label><input name="desc_kw" type="text" class="form-input" placeholder="e.g. breaking, update"></div>
  <div class="form-group"><label>Both (Title &amp; Desc) Keywords <span class="hint">(comma-separated)</span></label><input name="both_kw" type="text" class="form-input" placeholder="e.g. sports, tech"></div>
</div>
<div class="form-hint-box">
  <strong>Title KW</strong> checks title only &nbsp;|&nbsp; <strong>Desc KW</strong> checks description only &nbsp;|&nbsp; <strong>Both KW</strong> checks either.<br>
  <strong>OR</strong> (default): item passes if title <em>or</em> description keyword matches &nbsp;|&nbsp; <strong>AND</strong>: title <em>and</em> description keywords must both match.<br>
  <strong>Both KW</strong> always uses OR internally and is unaffected by the toggle. Leave all blank to pass every item.
</div>
<button type="submit" name="add_feed" class="btn btn-primary">Add Feed</button>
</form></div></section>';

    // ── Feeds table ──────────────────────────────────────────────────────────
    echo '<section class="card mt"><div class="card-header"><h2 class="card-title">Configured Feeds</h2><span class="badge">' . count($feeds) . '</span></div><div class="card-body p0">';

    if (empty($feeds)) {
        echo '<div class="empty-state">No feeds configured yet. Add one above.</div>';
    } else {
        echo '<div class="table-wrap"><table class="data-table"><thead><tr><th>Name</th><th>Source URL</th><th>Title&nbsp;KW</th><th>Relation</th><th>Desc&nbsp;KW</th><th>Both&nbsp;KW</th><th>Filtered Feed URL</th><th>Added</th><th>Actions</th></tr></thead><tbody>';
        foreach ($feeds as $f) {
            $furl = feedURL($f['token']);
            $logic = $f['logic'] ?? 'OR';
            $badgeCls = $logic === 'AND' ? 'badge-and' : 'badge-or';
            echo '<tr>';
            echo '<td class="td-name">'  . h($f['name']) . '</td>';
            echo '<td><a href="' . h($f['url']) . '" target="_blank" class="link-muted" title="' . h($f['url']) . '">' . h(shortenStr($f['url'])) . '</a></td>';
            echo '<td class="td-kw" title="' . h($f['title_kw']) . '">' . h($f['title_kw'] !== '' ? $f['title_kw'] : '—') . '</td>';
            echo '<td><span class="badge ' . $badgeCls . '">' . h($logic) . '</span></td>';
            echo '<td class="td-kw" title="' . h($f['desc_kw'])  . '">' . h($f['desc_kw']  !== '' ? $f['desc_kw']  : '—') . '</td>';
            echo '<td class="td-kw" title="' . h($f['both_kw'])  . '">' . h($f['both_kw']  !== '' ? $f['both_kw']  : '—') . '</td>';
            echo '<td class="td-feed"><a href="' . h($furl) . '" target="_blank" class="link-blue feed-link">' . h(shortenStr($furl)) . '</a> <button type="button" class="btn btn-xs copy-btn" data-url="' . h($furl) . '">Copy</button></td>';
            echo '<td class="td-date">' . h(substr($f['created_at'], 0, 10)) . '</td>';
            echo '<td class="td-actions">'
               . '<button type="button" class="btn btn-xs btn-warn edit-btn"'
               . ' data-id="'       . (int)$f['id']    . '"'
               . ' data-name="'     . h($f['name'])     . '"'
               . ' data-url="'      . h($f['url'])      . '"'
               . ' data-title_kw="' . h($f['title_kw']) . '"'
               . ' data-desc_kw="'  . h($f['desc_kw'])  . '"'
               . ' data-both_kw="'  . h($f['both_kw'])  . '"'
               . ' data-logic="'    . h($logic)         . '"'
               . '>Edit</button> ';
            echo '<form style="display:inline" method="post" onsubmit="return confirm(\'Delete this feed?\')"><input type="hidden" name="feed_id" value="' . (int)$f['id'] . '"><button type="submit" name="delete_feed" class="btn btn-xs btn-danger">Delete</button></form>';
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div></section></main>';

    // ── Edit Modal ───────────────────────────────────────────────────────────
    echo '<div class="modal-overlay" id="editModal">
<div class="modal">
  <div class="modal-header"><span class="modal-title">Edit Feed</span><button class="modal-close" id="modalClose">&times;</button></div>
  <div class="modal-body">
    <form method="post" action="">
      <input type="hidden" name="feed_id" id="edit_id">
      <div class="form-grid">
        <div class="form-group"><label>Feed Name <span class="req">*</span></label><input name="name" id="edit_name" type="text" class="form-input" required></div>
        <div class="form-group"><label>Feed URL <span class="req">*</span></label><input name="url" id="edit_url" type="url" class="form-input" required></div>
        <div class="form-group"><label>Title Keywords <span class="hint">(comma-separated)</span></label><input name="title_kw" id="edit_title_kw" type="text" class="form-input"></div>'
        . logicToggleHTML('edit_') .
        '<div class="form-group"><label>Description Keywords <span class="hint">(comma-separated)</span></label><input name="desc_kw" id="edit_desc_kw" type="text" class="form-input"></div>
        <div class="form-group"><label>Both (Title &amp; Desc) Keywords <span class="hint">(comma-separated)</span></label><input name="both_kw" id="edit_both_kw" type="text" class="form-input"></div>
      </div>
      <button type="submit" name="edit_feed" class="btn btn-primary">Save Changes</button>
      <button type="button" id="modalCancelBtn" class="btn" style="margin-left:8px">Cancel</button>
    </form>
  </div>
</div></div>';

    // Footer
    echo '<footer class="site-footer"><div class="container">&copy; <a href="https://github.com/xsukax" class="link-muted" target="_blank">xsukax</a> &mdash; ' . APP_NAME . ' &mdash; GPL-3.0</div></footer>';

    // JS
    echo '<script>
(function(){
  // ── Logic toggle ────────────────────────────────────────────────────────
  function initToggle(wrapperId){
    var wrap = document.getElementById(wrapperId);
    if(!wrap) return;
    var hidden  = wrap.querySelector("input[type=hidden]");
    var orBtn   = wrap.querySelector(".or-opt");
    var andBtn  = wrap.querySelector(".and-opt");
    var descEl  = document.getElementById(wrapperId + "_desc");

    function setVal(val){
      hidden.value = val;
      if(val === "AND"){
        orBtn.className  = "logic-opt or-opt";
        andBtn.className = "logic-opt and-opt active-and";
        if(descEl) descEl.innerHTML = "Title <strong>AND</strong> Desc must both match";
      } else {
        orBtn.className  = "logic-opt or-opt active-or";
        andBtn.className = "logic-opt and-opt";
        if(descEl) descEl.innerHTML = "Title <strong>OR</strong> Desc can match";
      }
    }
    orBtn.addEventListener("click",  function(){ setVal("OR"); });
    andBtn.addEventListener("click", function(){ setVal("AND"); });
  }
  initToggle("add_logic_toggle");
  initToggle("edit_logic_toggle");

  // ── Copy button ─────────────────────────────────────────────────────────
  function fallbackCopy(url){ var ta=document.createElement("textarea"); ta.value=url; ta.style.cssText="position:fixed;opacity:0"; document.body.appendChild(ta); ta.focus(); ta.select(); try{document.execCommand("copy");}catch(e){} document.body.removeChild(ta); }
  document.querySelectorAll(".copy-btn").forEach(function(btn){
    btn.addEventListener("click",function(){
      var url=this.dataset.url, b=this;
      if(navigator.clipboard&&navigator.clipboard.writeText){ navigator.clipboard.writeText(url).then(function(){ b.textContent="Copied!"; setTimeout(function(){ b.textContent="Copy"; },2000); }).catch(function(){ fallbackCopy(url); b.textContent="Copied!"; setTimeout(function(){ b.textContent="Copy"; },2000); }); }
      else{ fallbackCopy(url); btn.textContent="Copied!"; setTimeout(function(){ btn.textContent="Copy"; },2000); }
    });
  });

  // ── Edit modal ───────────────────────────────────────────────────────────
  var modal = document.getElementById("editModal");
  function openModal(d){
    document.getElementById("edit_id").value       = d.id;
    document.getElementById("edit_name").value     = d.name;
    document.getElementById("edit_url").value      = d.url;
    document.getElementById("edit_title_kw").value = d.title_kw;
    document.getElementById("edit_desc_kw").value  = d.desc_kw;
    document.getElementById("edit_both_kw").value  = d.both_kw;
    // Sync toggle to saved logic value
    var tog = document.getElementById("edit_logic_toggle");
    if(tog){ tog.querySelector("input[type=hidden]").value = d.logic;
      tog.querySelector(".or-opt").className  = "logic-opt or-opt"  + (d.logic!=="AND" ? " active-or"  : "");
      tog.querySelector(".and-opt").className = "logic-opt and-opt" + (d.logic==="AND" ? " active-and" : "");
      var desc = document.getElementById("edit_logic_toggle_desc");
      if(desc) desc.innerHTML = d.logic==="AND" ? "Title <strong>AND</strong> Desc must both match" : "Title <strong>OR</strong> Desc can match";
    }
    modal.classList.add("open");
  }
  function closeModal(){ modal.classList.remove("open"); }
  document.querySelectorAll(".edit-btn").forEach(function(btn){ btn.addEventListener("click",function(){ openModal(this.dataset); }); });
  document.getElementById("modalClose").addEventListener("click", closeModal);
  document.getElementById("modalCancelBtn").addEventListener("click", closeModal);
  modal.addEventListener("click", function(e){ if(e.target===modal) closeModal(); });
  document.addEventListener("keydown", function(e){ if(e.key==="Escape") closeModal(); });
})();
</script></body></html>';
}
