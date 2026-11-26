<?php
/*
 * Moya Rooms – calendari dei proprietari.
 * Dati in _calendari/ (non accessibile dal web): o/<proprietario>.json, a/<appartamento>.json
 * Ogni giorno del calendario = la notte che inizia quel giorno (il giorno di partenza resta libero).
 *
 * GET  load (o,k | mk)          calendario del proprietario (aggiorna i link iCal importati ogni 3 ore)
 * POST set (o,k | mk)           chiudi / riapri notti; mk anche prenotazioni Moya
 * POST import (o,k | mk)        link iCal di Booking/Airbnb da leggere
 * GET  ics (a,t)                calendario da importare su Booking/Airbnb
 * GET  admin_list (mk)          elenco proprietari
 * POST admin_new (mk)           nuovo proprietario (appartamenti uno per riga, «id | Nome» facoltativo)
 * mk = chiave di Moya Rooms (la stessa del foglio «Proprietari case vacanze»): qui solo la sua impronta.
 */
declare(strict_types=1);
date_default_timezone_set('Europe/Rome');

const SITE = 'https://moyarooms.com';
const ADMIN_KEY_HASH = '676f50bafee716dc5d8f4e53b68a8e003e67c0a26b027cdffe48573598af498b';
const MAX_IMPORTS = 3;
const IMPORT_EVERY = 3 * 3600;

$DIR = dirname(__DIR__) . '/_calendari';
header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');

function out(array $d, int $c = 200): void { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function rnd(int $n): string { return bin2hex(random_bytes($n)); }
function is_admin(string $mk): bool { return $mk !== '' && hash_equals(ADMIN_KEY_HASH, hash('sha256', $mk)); }
function okid($s): bool { return is_string($s) && (bool)preg_match('/^[a-z0-9][a-z0-9-]{1,40}$/', $s); }
function okdate($s): bool {
    return is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && checkdate((int)substr($s, 5, 2), (int)substr($s, 8, 2), (int)substr($s, 0, 4));
}
function day(string $d, int $n): string { return date('Y-m-d', strtotime($d . ' ' . ($n >= 0 ? '+' : '') . $n . ' day')); }
function jread(string $p): ?array { $s = @file_get_contents($p); $d = $s ? json_decode($s, true) : null; return is_array($d) ? $d : null; }
function jwrite(string $p, array $d): void {
    $t = $p . '.tmp' . rnd(3);
    file_put_contents($t, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    rename($t, $p);
}
function apath(string $id): string { global $DIR; return "$DIR/a/$id.json"; }
function opath(string $id): string { global $DIR; return "$DIR/o/$id.json"; }
function slug(string $s): string {
    $s = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: '');
    $s = trim(preg_replace('/[^a-z0-9]+/', '-', $s) ?? '', '-');
    return substr($s !== '' ? $s : 'p', 0, 24);
}

foreach (['', '/a', '/o'] as $sub) if (!is_dir($DIR . $sub) && !@mkdir($DIR . $sub, 0750, true)) out(['ok' => false, 'error' => 'storage'], 500);

// ---------- iCal ----------
function fetch_url(string $u): ?string {
    if (function_exists('curl_init')) {
        $c = curl_init($u);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_USERAGENT => 'MoyaRooms-Calendario/1.0',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS]);
        $r = curl_exec($c); $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
        return ($r !== false && $code >= 200 && $code < 300) ? (string)$r : null;
    }
    $ctx = stream_context_create(['http' => ['timeout' => 10, 'user_agent' => 'MoyaRooms-Calendario/1.0']]);
    $r = @file_get_contents($u, false, $ctx);
    return $r === false ? null : $r;
}
function url_ok(string $u): bool {
    if (strlen($u) > 1000 || !preg_match('#^https?://#i', $u)) return false;
    $h = (string)parse_url($u, PHP_URL_HOST);
    if ($h === '' || strcasecmp($h, 'localhost') === 0) return getenv('MR_TEST') === '1';
    $ip = filter_var($h, FILTER_VALIDATE_IP) ? $h : gethostbyname($h);
    if (getenv('MR_TEST') === '1') return true;
    return (bool)filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}
function ics_date(string $v): string { return substr($v, 0, 4) . '-' . substr($v, 4, 2) . '-' . substr($v, 6, 2); }
function parse_ics(string $s): array {
    $s = preg_replace('/\r?\n[ \t]/', '', $s) ?? '';
    if (!preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $s, $m)) return [];
    $min = day(date('Y-m-d'), -1); $max = date('Y-m-d', strtotime('+18 months'));
    $out = [];
    foreach ($m[1] as $ev) {
        if (!preg_match('/^DTSTART[^:\r\n]*:(\d{8})/m', $ev, $a)) continue;
        $st = ics_date($a[1]);
        $en = preg_match('/^DTEND[^:\r\n]*:(\d{8})/m', $ev, $b) ? ics_date($b[1]) : day($st, 1);
        if (!okdate($st) || !okdate($en)) continue;
        if ($en <= $st) $en = day($st, 1);
        if ($en > $min && $st < $max) $out[] = [$st, $en];
    }
    usort($out, fn($x, $y) => strcmp($x[0], $y[0]));
    return array_slice($out, 0, 500);
}
function refresh_import(array &$a, bool $force = false): bool {
    $urls = $a['import_urls'] ?? [];
    if (!$urls) { if (!empty($a['ext'])) { $a['ext'] = []; return true; } return false; }
    if (!$force && (time() - (int)($a['ext_at'] ?? 0)) < IMPORT_EVERY) return false;
    $ext = []; $err = [];
    foreach ($urls as $u) {
        $r = url_ok($u) ? fetch_url($u) : null;
        if ($r === null || stripos($r, 'BEGIN:VCALENDAR') === false) { $err[] = $u; continue; }
        $ext = array_merge($ext, parse_ics($r));
    }
    usort($ext, fn($x, $y) => strcmp($x[0], $y[0]));
    $a['ext'] = $ext; $a['ext_err'] = $err; $a['ext_at'] = time();
    return true;
}
// notti chiuse -> intervalli [inizio, fine esclusa)
function ranges(array $dates): array {
    sort($dates); $r = [];
    foreach ($dates as $d) {
        if ($r && $r[count($r) - 1][1] === $d) $r[count($r) - 1][1] = day($d, 1);
        else $r[] = [$d, day($d, 1)];
    }
    return $r;
}

// ---------- accesso ----------
function owner_auth(): array {
    $admin = is_admin((string)($_REQUEST['mk'] ?? ''));
    $o = (string)($_REQUEST['o'] ?? '');
    if (!okid($o)) out(['ok' => false, 'error' => 'bad_owner'], 400);
    $ow = jread(opath($o));
    if (!$ow) out(['ok' => false, 'error' => 'not_found'], 404);
    if (!$admin && !hash_equals((string)$ow['key'], (string)($_REQUEST['k'] ?? ''))) out(['ok' => false, 'error' => 'bad_token'], 403);
    return [$ow, $admin];
}
function apt_view(array $a): array {
    return ['id' => $a['id'], 'name' => $a['name'], 'closed' => $a['closed'] ?? [], 'moya' => $a['moya'] ?? [],
        'ext' => $a['ext'] ?? [], 'ext_at' => (int)($a['ext_at'] ?? 0), 'ext_err' => $a['ext_err'] ?? [],
        'import_urls' => $a['import_urls'] ?? [], 'updated' => (int)($a['updated'] ?? 0),
        'export_url' => SITE . '/calendario/api.php?action=ics&a=' . $a['id'] . '&t=' . $a['export_token']];
}
function owner_apt(array $ow): array {
    $id = (string)($_POST['apt'] ?? '');
    if (!in_array($id, $ow['apts'], true)) out(['ok' => false, 'error' => 'bad_apt'], 400);
    $a = jread(apath($id));
    if (!$a) out(['ok' => false, 'error' => 'not_found'], 404);
    return $a;
}

$action = (string)($_REQUEST['action'] ?? '');
$post = $_SERVER['REQUEST_METHOD'] === 'POST';

if ($action === 'load') {
    [$ow, $admin] = owner_auth();
    $apts = [];
    foreach ($ow['apts'] as $id) {
        $a = jread(apath($id)); if (!$a) continue;
        if (refresh_import($a)) jwrite(apath($id), $a);
        $apts[] = apt_view($a);
    }
    out(['ok' => true, 'admin' => $admin, 'owner' => ['id' => $ow['id'], 'name' => $ow['name']], 'apts' => $apts, 'today' => date('Y-m-d')]);
}

if ($action === 'set' && $post) {
    [$ow, $admin] = owner_auth();
    $a = owner_apt($ow);
    $op = (string)($_POST['op'] ?? ''); $from = (string)($_POST['from'] ?? ''); $to = (string)($_POST['to'] ?? '');
    if (!okdate($from) || !okdate($to)) out(['ok' => false, 'error' => 'bad_date'], 400);
    if ($to < $from) [$from, $to] = [$to, $from];
    if ((strtotime($to) - strtotime($from)) / 86400 > 400) out(['ok' => false, 'error' => 'too_long'], 400);
    $days = []; for ($d = $from; $d <= $to; $d = day($d, 1)) $days[] = $d;
    $closed = array_flip($a['closed'] ?? []);
    if ($op === 'close') foreach ($days as $d) $closed[$d] = 1;
    elseif ($op === 'open') foreach ($days as $d) unset($closed[$d]);
    elseif ($op === 'moya' && $admin) {
        $a['moya'][] = ['from' => $from, 'to' => day($to, 1), 'note' => mb_substr(trim((string)($_POST['note'] ?? '')), 0, 60)];
        usort($a['moya'], fn($x, $y) => strcmp($x['from'], $y['from']));
    } elseif ($op === 'unmoya' && $admin) {
        $end = day($to, 1);
        $a['moya'] = array_values(array_filter($a['moya'] ?? [], fn($b) => !($b['from'] < $end && $b['to'] > $from)));
    } else out(['ok' => false, 'error' => 'bad_op'], 400);
    $past = day(date('Y-m-d'), -60);
    $a['closed'] = array_values(array_filter(array_keys($closed), fn($d) => $d >= $past));
    sort($a['closed']);
    $a['updated'] = time();
    jwrite(apath($a['id']), $a);
    out(['ok' => true, 'apt' => apt_view($a)]);
}

if ($action === 'import' && $post) {
    [$ow, $admin] = owner_auth();
    $a = owner_apt($ow);
    $urls = array_values(array_unique(array_filter(array_map('trim', preg_split('/\s+/', (string)($_POST['urls'] ?? '')) ?: []))));
    if (count($urls) > MAX_IMPORTS) out(['ok' => false, 'error' => 'too_many'], 400);
    foreach ($urls as $u) if (!url_ok($u)) out(['ok' => false, 'error' => 'bad_url'], 400);
    $a['import_urls'] = $urls;
    refresh_import($a, true);
    $a['updated'] = time();
    jwrite(apath($a['id']), $a);
    out(['ok' => true, 'apt' => apt_view($a)]);
}

if ($action === 'ics') {
    $id = (string)($_GET['a'] ?? '');
    $a = okid($id) ? jread(apath($id)) : null;
    if (!$a || !hash_equals((string)$a['export_token'], (string)($_GET['t'] ?? ''))) out(['ok' => false, 'error' => 'not_found'], 404);
    $now = gmdate('Ymd\THis\Z'); $from = day(date('Y-m-d'), -30);
    $ev = [];
    foreach (ranges($a['closed'] ?? []) as [$s, $e]) if ($e > $from) $ev[] = [$s, $e, 'Chiuso / Not available', 'c'];
    foreach ($a['moya'] ?? [] as $b) if ($b['to'] > $from) $ev[] = [$b['from'], $b['to'], 'Moya Rooms', 'm'];
    $L = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Moya Rooms//Calendario//IT', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
        'X-WR-CALNAME:Moya Rooms – ' . $a['name']];
    foreach ($ev as [$s, $e, $sum, $t]) {
        $L[] = 'BEGIN:VEVENT';
        $L[] = 'UID:' . $a['id'] . '-' . $t . '-' . str_replace('-', '', $s) . '@moyarooms.com';
        $L[] = 'DTSTAMP:' . $now;
        $L[] = 'DTSTART;VALUE=DATE:' . str_replace('-', '', $s);
        $L[] = 'DTEND;VALUE=DATE:' . str_replace('-', '', $e);
        $L[] = 'SUMMARY:' . $sum;
        $L[] = 'END:VEVENT';
    }
    $L[] = 'END:VCALENDAR';
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: inline; filename="moyarooms-' . $a['id'] . '.ics"');
    echo implode("\r\n", $L) . "\r\n";
    exit;
}

if ($action === 'admin_list') {
    if (!is_admin((string)($_GET['mk'] ?? ''))) out(['ok' => false, 'error' => 'bad_token'], 403);
    $list = [];
    foreach (glob($DIR . '/o/*.json') ?: [] as $p) {
        $ow = jread($p); if (!$ow) continue;
        $names = [];
        foreach ($ow['apts'] as $id) { $a = jread(apath($id)); if ($a) $names[] = $a['name']; }
        $list[] = ['id' => $ow['id'], 'name' => $ow['name'], 'apts' => $names, 'created' => $ow['created'] ?? 0,
            'link' => SITE . '/calendario/?o=' . $ow['id'] . '&k=' . $ow['key']];
    }
    usort($list, fn($x, $y) => strcmp($x['name'], $y['name']));
    out(['ok' => true, 'owners' => $list]);
}

if ($action === 'admin_new' && $post) {
    if (!is_admin((string)($_POST['mk'] ?? ''))) out(['ok' => false, 'error' => 'bad_token'], 403);
    $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 80);
    $lines = array_values(array_filter(array_map('trim', explode("\n", (string)($_POST['apts'] ?? '')))));
    if ($name === '' || !$lines || count($lines) > 30) out(['ok' => false, 'error' => 'missing'], 400);
    $oid = slug($name) . '-' . rnd(2);
    $ids = [];
    foreach ($lines as $ln) {
        [$id, $an] = str_contains($ln, '|') ? array_map('trim', explode('|', $ln, 2)) : ['a-' . rnd(4), $ln];
        if (!okid($id) || $an === '') out(['ok' => false, 'error' => 'bad_apt', 'line' => $ln], 400);
        if (!jread(apath($id))) jwrite(apath($id), ['id' => $id, 'name' => mb_substr($an, 0, 80), 'owner' => $oid,
            'closed' => [], 'moya' => [], 'import_urls' => [], 'ext' => [], 'export_token' => rnd(12), 'updated' => time()]);
        $ids[] = $id;
    }
    $ow = ['id' => $oid, 'name' => $name, 'key' => rnd(16), 'apts' => $ids, 'created' => time()];
    jwrite(opath($oid), $ow);
    out(['ok' => true, 'owner' => $oid, 'link' => SITE . '/calendario/?o=' . $oid . '&k=' . $ow['key']]);
}

out(['ok' => false, 'error' => 'action'], 400);
