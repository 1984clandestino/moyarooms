<?php
/*
 * Moya Rooms – modulo proprietari.
 * Riceve le risposte e le foto, le salva in _zgloszenia/ (non accessibile dal web)
 * e invia un'email a info@moyarooms.com con risposte, foto in allegato e link allo ZIP.
 *
 * Azioni (POST): start, photo, finish.  Azioni (GET): zip, ping, csv.
 * csv = elenco di tutti i moduli per il foglio Google «Proprietari case vacanze» (IMPORTDATA).
 * La chiave dell'elenco sta solo nella formula del foglio: qui c'è soltanto la sua impronta SHA-256.
 */
declare(strict_types=1);
date_default_timezone_set('Europe/Rome');

const TO_EMAIL   = 'info@moyarooms.com';
const FROM_EMAIL = 'info@moyarooms.com';
const SITE       = 'https://moyarooms.com';
const MAX_PHOTOS = 30;
const MAX_PHOTO_BYTES = 12 * 1024 * 1024;   // 12 MB per foto (il browser le riduce prima)
const MAX_ATTACH_BYTES = 15 * 1024 * 1024;  // oltre questo, nell'email solo il link allo ZIP
const MIN_FILL_SECONDS = 20;
const MAX_STARTS_PER_HOUR_PER_IP = 6;
const LIST_KEY_HASH = '676f50bafee716dc5d8f4e53b68a8e003e67c0a26b027cdffe48573598af498b';

$STORE = dirname(__DIR__) . '/_zgloszenia';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');

function out(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function clean(string $s, int $max = 4000): string {
    $s = str_replace("\0", '', $s);
    $s = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '');
    return mb_substr($s, 0, $max);
}

function sub_dir(string $store, string $id): string {
    if (!preg_match('/^\d{8}-\d{6}-[a-f0-9]{6}$/', $id)) out(['ok' => false, 'error' => 'bad_id'], 400);
    $d = $store . '/' . $id;
    if (!is_dir($d)) out(['ok' => false, 'error' => 'not_found'], 404);
    return $d;
}

function check_token(string $dir, string $k): array {
    $meta = json_decode((string)@file_get_contents($dir . '/meta.json'), true) ?: [];
    if (!isset($meta['khash']) || !hash_equals($meta['khash'], hash('sha256', $k))) out(['ok' => false, 'error' => 'bad_token'], 403);
    return $meta;
}

// Etichette in italiano per l'email (le risposte arrivano con valori in italiano)
const LABELS = [
    'lingua' => 'Lingua del modulo', 'nome' => 'Nome e cognome', 'telefono' => 'Telefono / WhatsApp', 'email' => 'E-mail',
    'ruolo' => 'Ruolo', 'tipo' => 'Tipo di alloggio', 'localita' => 'Comune e provincia', 'regione' => 'Regione',
    'indirizzo' => 'Indirizzo (non pubblico)', 'cin' => 'CIN', 'mq' => 'Metri quadrati', 'ospiti' => 'Ospiti massimi / letti aggiuntivi',
    'letti' => 'Camere e letti', 'bagni' => 'Bagni', 'piano' => 'Piano, ascensore, scale', 'descrizione' => 'Descrizione del proprietario',
    'dotazioni' => 'Dotazioni', 'dotazioni_altro' => 'Altre dotazioni', 'piscina' => 'Piscina (mesi, privata/comune)',
    'checkin' => 'Check-in', 'checkout' => 'Check-out', 'minimo' => 'Soggiorno minimo', 'apertura' => 'Mesi di apertura',
    'min_bassa' => 'Notti minime – bassa stagione', 'min_alta' => 'Notti minime – alta stagione', 'alta_periodo' => 'Periodo di alta stagione',
    'prezzi' => 'Prezzi a notte, alloggio intero (tassa di soggiorno inclusa)', 'prezzi_note' => 'Altre informazioni sui prezzi',
    'animali' => 'Animali', 'fumo_feste' => 'Fumo e feste', 'tassa' => 'Tassa di soggiorno', 'prezzo' => 'Prezzo indicativo (non pubblico)',
    'lingue' => 'Lingue parlate', 'parcheggio' => 'Parcheggio', 'vicino' => 'Cosa c\'è vicino', 'consiglio' => 'Consiglio da locale',
    'copertina' => 'Foto di copertina', 'video' => 'Video / tour', 'wa_turisti' => 'WhatsApp per le richieste dei turisti',
    'contatto_pref' => 'Contatto preferito per le richieste', 'link_annunci' => 'Link ad altri annunci (Booking, Airbnb…)',
    'consensi' => 'Consensi',
];

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'ping') {
    out(['ok' => true, 'php' => PHP_VERSION, 'upload_max_filesize' => ini_get('upload_max_filesize'),
         'post_max_size' => ini_get('post_max_size'), 'max_file_uploads' => ini_get('max_file_uploads'),
         'mail' => function_exists('mail'), 'zip' => class_exists('ZipArchive'), 'gd' => function_exists('getimagesize')]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $action !== 'zip' && $action !== 'csv') out(['ok' => false, 'error' => 'method'], 405);
if (!is_dir($STORE) && !@mkdir($STORE, 0750, true)) out(['ok' => false, 'error' => 'storage'], 500);

// ---------- 1. start: salva le risposte ----------
if ($action === 'start') {
    if (!empty($_POST['website'])) out(['ok' => true, 'id' => '00000000-000000-000000', 'k' => 'x']); // trappola per bot
    $t0 = (int)($_POST['t0'] ?? 0);
    if ($t0 <= 0 || (time() - intdiv($t0, 1000)) < MIN_FILL_SECONDS) out(['ok' => false, 'error' => 'too_fast'], 400);

    // limite per IP (salvato solo come hash)
    $iph = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|moyarooms');
    $recent = 0;
    foreach (glob($STORE . '/*/meta.json') ?: [] as $m) {
        if (filemtime($m) < time() - 3600) continue;
        $mm = json_decode((string)file_get_contents($m), true) ?: [];
        if (($mm['iph'] ?? '') === $iph) $recent++;
    }
    if ($recent >= MAX_STARTS_PER_HOUR_PER_IP) out(['ok' => false, 'error' => 'rate'], 429);

    $a = [];
    foreach (array_keys(LABELS) as $key) {
        if (!isset($_POST[$key])) continue;
        $v = $_POST[$key];
        $a[$key] = is_array($v) ? implode(', ', array_map(fn($x) => clean((string)$x, 200), $v)) : clean((string)$v);
    }
    foreach (['nome', 'telefono', 'email', 'localita', 'indirizzo', 'cin'] as $req) {
        if (($a[$req] ?? '') === '') out(['ok' => false, 'error' => 'missing', 'field' => $req], 400);
    }
    if (!filter_var($a['email'], FILTER_VALIDATE_EMAIL)) out(['ok' => false, 'error' => 'email'], 400);

    $id = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
    $k = bin2hex(random_bytes(16));
    $dir = $STORE . '/' . $id;
    mkdir($dir . '/foto', 0750, true);
    file_put_contents($dir . '/risposte.json', json_encode($a, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    file_put_contents($dir . '/meta.json', json_encode(['khash' => hash('sha256', $k), 'iph' => $iph, 'created' => date('c'), 'photos' => 0, 'done' => false]));
    out(['ok' => true, 'id' => $id, 'k' => $k]);
}

// ---------- 2. photo: una foto per volta ----------
if ($action === 'photo') {
    $dir = sub_dir($STORE, (string)($_POST['id'] ?? ''));
    $meta = check_token($dir, (string)($_POST['k'] ?? ''));
    if (!empty($meta['done'])) out(['ok' => false, 'error' => 'closed'], 400);
    if (($meta['photos'] ?? 0) >= MAX_PHOTOS) out(['ok' => false, 'error' => 'too_many'], 400);
    $f = $_FILES['foto'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) out(['ok' => false, 'error' => 'upload', 'code' => $f['error'] ?? -1], 400);
    if ($f['size'] > MAX_PHOTO_BYTES) out(['ok' => false, 'error' => 'too_big'], 400);
    $info = @getimagesize($f['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
    if (!$ext) out(['ok' => false, 'error' => 'not_image'], 400);
    $n = ($meta['photos'] ?? 0) + 1;
    $orig = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename((string)($_POST['nome_file'] ?? $f['name'])));
    $name = sprintf('%02d', $n) . '-' . substr(pathinfo($orig, PATHINFO_FILENAME), 0, 40) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . '/foto/' . $name)) out(['ok' => false, 'error' => 'save'], 500);
    $meta['photos'] = $n;
    file_put_contents($dir . '/meta.json', json_encode($meta));
    out(['ok' => true, 'n' => $n]);
}

// ---------- 3. finish: invia l'email ----------
if ($action === 'finish') {
    $id = (string)($_POST['id'] ?? '');
    $k = (string)($_POST['k'] ?? '');
    $dir = sub_dir($STORE, $id);
    $meta = check_token($dir, $k);
    if (!empty($meta['done'])) out(['ok' => true, 'already' => true]);
    $a = json_decode((string)file_get_contents($dir . '/risposte.json'), true) ?: [];
    $photos = glob($dir . '/foto/*') ?: [];
    sort($photos);

    $zipLink = SITE . '/modulo/api.php?action=zip&id=' . rawurlencode($id) . '&k=' . rawurlencode($k);
    $lines = ["Nuovo modulo proprietario su moyarooms.com", "Codice: $id", ''];
    foreach (LABELS as $key => $label) {
        if (($a[$key] ?? '') === '') continue;
        $lines[] = $label . ":\n" . $a[$key] . "\n";
    }
    $lines[] = 'Foto ricevute: ' . count($photos);
    $lines[] = "Tutte le foto in un file ZIP: $zipLink";

    $total = array_sum(array_map('filesize', $photos));
    $attach = $total <= MAX_ATTACH_BYTES ? $photos : [];
    if (!$attach && $photos) $lines[] = '(Foto troppo pesanti per l\'allegato: scaricale dal link ZIP.)';

    $subject = 'Modulo proprietario: ' . ($a['localita'] ?? '') . ' – ' . ($a['nome'] ?? '');
    $sent = send_mail(TO_EMAIL, $subject, implode("\n", $lines), $attach, $a['email'] ?? '');

    $meta['done'] = true;
    $meta['mail_sent'] = $sent;
    file_put_contents($dir . '/meta.json', json_encode($meta));
    out(['ok' => true, 'mail' => $sent]);
}

// ---------- 4. zip: scarica tutto (link segreto nell'email) ----------
if ($action === 'zip') {
    $id = (string)($_GET['id'] ?? '');
    $dir = sub_dir($STORE, $id);
    if (!is_master((string)($_GET['mk'] ?? ''))) check_token($dir, (string)($_GET['k'] ?? ''));
    if (!class_exists('ZipArchive')) out(['ok' => false, 'error' => 'no_zip'], 500);
    $tmp = tempnam(sys_get_temp_dir(), 'mr');
    $z = new ZipArchive();
    $z->open($tmp, ZipArchive::OVERWRITE);
    $z->addFile($dir . '/risposte.json', 'risposte.json');
    foreach (glob($dir . '/foto/*') ?: [] as $p) $z->addFile($p, 'foto/' . basename($p));
    $z->close();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="moyarooms-' . $id . '.zip"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit;
}

// ---------- 5. csv: elenco per il foglio Google (chiave dell'elenco) ----------
if ($action === 'csv') {
    if (!is_master((string)($_GET['mk'] ?? ''))) out(['ok' => false, 'error' => 'bad_token'], 403);
    $cols = [
        'Data' => null, 'Codice' => null, 'Lingua modulo' => 'lingua', 'Nome e cognome' => 'nome', 'Telefono' => 'telefono',
        'E-mail' => 'email', 'Ruolo' => 'ruolo', 'Contatto preferito' => 'contatto_pref', 'Tipo di alloggio' => 'tipo',
        'Regione' => 'regione', 'Località' => 'localita', 'Indirizzo (non pubblico)' => 'indirizzo', 'CIN' => 'cin',
        'Ospiti' => 'ospiti', 'Camere e letti' => 'letti', 'Bagni' => 'bagni', 'Notti min. bassa stagione' => 'min_bassa',
        'Notti min. alta stagione' => 'min_alta', 'Alta stagione' => 'alta_periodo',
        'Prezzi a notte (tassa di soggiorno inclusa)' => 'prezzi', 'Altre informazioni sui prezzi' => 'prezzi_note',
        'Altri annunci' => 'link_annunci', 'Foto ricevute' => null, 'Foto (ZIP)' => null, 'Modulo completo' => null,
    ];
    header('Content-Type: text/csv; charset=utf-8');
    header('Cache-Control: no-store');
    $f = fopen('php://output', 'w');
    fputcsv($f, array_keys($cols), ',', '"', '');
    $dirs = glob($STORE . '/*', GLOB_ONLYDIR) ?: [];
    sort($dirs);
    foreach ($dirs as $d) {
        $id = basename($d);
        $a = json_decode((string)@file_get_contents($d . '/risposte.json'), true);
        $m = json_decode((string)@file_get_contents($d . '/meta.json'), true);
        if (!is_array($a) || !is_array($m)) continue;
        $row = [];
        foreach ($cols as $label => $key) {
            if ($label === 'Data') { $t = strtotime((string)($m['created'] ?? '')); $row[] = $t ? date('d/m/Y H:i', $t) : ''; continue; }
            if ($label === 'Codice') { $row[] = $id; continue; }
            if ($label === 'Foto ricevute') { $row[] = (string)count(glob($d . '/foto/*') ?: []); continue; }
            if ($label === 'Foto (ZIP)') { $row[] = SITE . '/modulo/api.php?action=zip&id=' . rawurlencode($id) . '&mk=' . rawurlencode((string)$_GET['mk']); continue; }
            if ($label === 'Modulo completo') { $row[] = !empty($m['done']) ? 'sì' : 'no'; continue; }
            $v = str_replace(["\r\n", "\n", "\r"], ' | ', (string)($a[$key] ?? ''));
            if ($key === 'telefono' && preg_match('/^\d{6,}$/', $v)) $v = substr($v, 0, 3) . ' ' . substr($v, 3); // resta testo nel foglio
            $row[] = $v;
        }
        fputcsv($f, $row, ',', '"', '');
    }
    fclose($f);
    exit;
}

out(['ok' => false, 'error' => 'action'], 400);

function is_master(string $mk): bool {
    return $mk !== '' && hash_equals(LIST_KEY_HASH, hash('sha256', $mk));
}

function send_mail(string $to, string $subject, string $text, array $files, string $replyTo): bool {
    $b = 'mr' . bin2hex(random_bytes(8));
    $h = [];
    $h[] = 'From: Moya Rooms <' . FROM_EMAIL . '>';
    if (filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $h[] = 'Reply-To: ' . $replyTo;
    $h[] = 'MIME-Version: 1.0';
    $h[] = 'Content-Type: multipart/mixed; boundary="' . $b . '"';
    $body = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
          . chunk_split(base64_encode($text)) . "\r\n";
    foreach ($files as $p) {
        $mime = mime_content_type($p) ?: 'application/octet-stream';
        $name = basename($p);
        $body .= "--$b\r\nContent-Type: $mime; name=\"$name\"\r\nContent-Transfer-Encoding: base64\r\n"
               . "Content-Disposition: attachment; filename=\"$name\"\r\n\r\n" . chunk_split(base64_encode((string)file_get_contents($p))) . "\r\n";
    }
    $body .= "--$b--\r\n";
    $subj = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $ok = @mail($to, $subj, $body, implode("\r\n", $h), '-f' . FROM_EMAIL);
    if (!$ok) $ok = @mail($to, $subj, $body, implode("\r\n", $h));
    return (bool)$ok;
}
