<?php
/* Roue de la Chance — fonctions utilitaires */
defined('RDC') || exit;

/* ───────────── Configuration & données par défaut ───────────── */

function cfg(string $key, $default = null)
{
    $v = $GLOBALS['RDC_CFG'];
    foreach (explode('.', $key) as $part) {
        if (!is_array($v) || !array_key_exists($part, $v)) return $default;
        $v = $v[$part];
    }
    return $v;
}

function defaults(): array
{
    static $d = null;
    if ($d === null) {
        $d = json_decode((string)file_get_contents(RDC_APP . '/defaults.json'), true);
        if (!is_array($d)) throw new RuntimeException('defaults.json illisible');
    }
    return $d;
}

function is_list_array($a): bool
{
    if (!is_array($a)) return false;
    $i = 0;
    foreach ($a as $k => $_) { if ($k !== $i++) return false; }
    return true;
}

/** Fusion récursive : les tableaux associatifs sont fusionnés, les listes remplacées. */
function deep_merge(array $base, $over): array
{
    if (!is_array($over)) return $base;
    foreach ($over as $k => $v) {
        if (is_array($v) && isset($base[$k]) && is_array($base[$k]) && !is_list_array($v) && !is_list_array($base[$k])) {
            $base[$k] = deep_merge($base[$k], $v);
        } else {
            $base[$k] = $v;
        }
    }
    return $base;
}

/* ───────────── Dates ───────────── */

function now(): string { return gmdate('Y-m-d H:i:s'); }

function iso(?string $dt): ?string
{
    if (!$dt) return null;
    return str_replace(' ', 'T', $dt) . 'Z';
}

/* ───────────── HTML / JSON ───────────── */

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function json_enc($data): string
{
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}

/** JSON sûr à placer dans une balise <script>. */
function json_script($data): string
{
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
}

class ApiError extends Exception
{
    public $error;
    public $status;
    public $extra;
    public function __construct(string $error, string $message, int $status = 200, array $extra = [])
    {
        parent::__construct($message);
        $this->error = $error;
        $this->status = $status;
        $this->extra = $extra;
    }
}

/** Interrompt le traitement avec une erreur lisible par l'interface. */
function fail(string $error, string $message, int $status = 200, array $extra = []): void
{
    throw new ApiError($error, $message, $status, $extra);
}

function json_out(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_enc($data);
    exit;
}

function input(): array
{
    static $in = null;
    if ($in !== null) return $in;
    $in = [];
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $dec = json_decode((string)$raw, true);
        if (is_array($dec)) $in = $dec;
    } else {
        $in = $_POST;
    }
    return $in;
}

/** Nettoie une chaîne saisie : supprime caractères de contrôle, espaces superflus, limite la longueur. */
function clean_str($v, int $max = 500, bool $multiline = false): string
{
    if (is_array($v) || is_object($v)) return '';
    $s = (string)$v;
    if (!mb_check_encoding($s, 'UTF-8')) $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    $s = $multiline
        ? preg_replace('/[\x{0}-\x{8}\x{B}\x{C}\x{E}-\x{1F}\x{7F}]/u', '', str_replace(["\r\n", "\r"], "\n", $s))
        : preg_replace('/[\x{0}-\x{1F}\x{7F}]/u', ' ', $s);
    $s = trim((string)$s);
    if (mb_strlen($s) > $max) $s = mb_substr($s, 0, $max);
    return $s;
}

function in_str(array $in, string $key, int $max = 500, bool $multiline = false): string
{
    return clean_str($in[$key] ?? '', $max, $multiline);
}

function in_int(array $in, string $key, int $default = 0): int
{
    $v = $in[$key] ?? null;
    if (is_bool($v)) return $v ? 1 : 0;
    if (is_numeric($v)) return (int)$v;
    return $default;
}

function in_bool(array $in, string $key, bool $default = false): bool
{
    if (!array_key_exists($key, $in)) return $default;
    $v = $in[$key];
    if (is_bool($v)) return $v;
    if (is_numeric($v)) return (int)$v !== 0;
    return in_array(strtolower((string)$v), ['1', 'true', 'on', 'oui', 'yes'], true);
}

function clean_color($c, string $default): string
{
    $c = is_string($c) ? trim($c) : '';
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $c)) return strtoupper($c);
    if (preg_match('/^#([0-9a-fA-F])([0-9a-fA-F])([0-9a-fA-F])$/', $c, $m)) {
        return strtoupper('#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3]);
    }
    return $default;
}

/** Chemin d'image stocké : fichier envoyé, image d'exemple ou URL http(s). Tout le reste est refusé. */
function clean_asset($v): string
{
    $s = clean_str($v, 500);
    if ($s === '') return '';
    if (preg_match('#^(uploads|assets/img)/[A-Za-z0-9._/-]+$#', $s) && strpos($s, '..') === false) return $s;
    if (preg_match('#^https?://[^\s"\'<>]+$#i', $s)) return $s;
    return '';
}

function slugify(string $s): string
{
    $s = mb_strtolower($s);
    $tr = ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ì' => 'i', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ù' => 'u', 'û' => 'u',
        'ü' => 'u', 'ú' => 'u', 'ÿ' => 'y', 'ñ' => 'n', 'œ' => 'oe', 'æ' => 'ae'];
    $s = strtr($s, $tr);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim((string)$s, '-');
    return substr($s, 0, 40) ?: 'projet';
}

function random_token(int $bytes = 24): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

/* ───────────── URLs ───────────── */

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
    if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') return true;
    if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on') return true;
    return (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
}

function request_host(): string
{
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost'));
    $host = preg_replace('/[^a-z0-9.\-:\[\]]/', '', $host);
    return (string)$host;
}

function request_hostname(): string
{
    $h = request_host();
    if ($h !== '' && $h[0] === '[') return $h;
    return (string)preg_replace('/:\d+$/', '', $h);
}

/** Chemin URL de la racine du site, avec "/" final (ex. "/" ou "/jeu/"). */
function base_path(): string
{
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $dir = dirname($script);
    for ($i = 0; $i < RDC_DEPTH; $i++) $dir = dirname($dir);
    $dir = rtrim(str_replace('\\', '/', $dir), '/');
    return $dir . '/';
}

function base_url(): string
{
    return (is_https() ? 'https' : 'http') . '://' . request_host() . base_path();
}

/** URL publique utilisée dans les QR codes (réglable dans l'administration). */
function public_base_url(): string
{
    $set = setting('base_url');
    if (is_string($set) && preg_match('#^https?://#i', $set)) return rtrim($set, '/') . '/';
    return base_url();
}

/* ───────────── Codes des tickets ───────────── */

const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
const CODE_LEN = 10;

function code_checksum(string $body): string
{
    $sum = 0;
    $n = strlen($body);
    for ($i = 0; $i < $n; $i++) {
        $sum += ($i + 1) * strpos(CODE_ALPHABET, $body[$i]);
    }
    return CODE_ALPHABET[$sum % 31];
}

function code_generate(): string
{
    $body = '';
    for ($i = 0; $i < CODE_LEN - 1; $i++) $body .= CODE_ALPHABET[random_int(0, 30)];
    return $body . code_checksum($body);
}

/** Extrait un code d'un texte libre ou d'une URL scannée (paramètre c=). */
function code_extract($raw): string
{
    $raw = is_string($raw) ? trim($raw) : '';
    if ($raw === '') return '';
    if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $raw) || strpos($raw, '?') !== false || strpos($raw, '/') !== false) {
        $qs = parse_url($raw, PHP_URL_QUERY);
        if ($qs) {
            parse_str($qs, $q);
            foreach (['c', 'code'] as $k) {
                if (!empty($q[$k]) && is_string($q[$k])) return $q[$k];
            }
        }
        if (preg_match('#([A-Za-z0-9]{5}-?[A-Za-z0-9]{5})/?$#', $raw, $m)) return $m[1];
    }
    return $raw;
}

/**
 * Normalise un code saisi ou scanné.
 * @return string|null le code (10 caractères, sans tiret) ou null si le format/la clé de contrôle est invalide
 */
function code_normalize($raw): ?string
{
    $s = strtoupper(code_extract($raw));
    $s = preg_replace('/[^A-Z0-9]/', '', $s);
    if (strlen((string)$s) !== CODE_LEN) return null;
    if (strspn($s, CODE_ALPHABET) !== CODE_LEN) return null;
    if (code_checksum(substr($s, 0, CODE_LEN - 1)) !== $s[CODE_LEN - 1]) return null;
    return $s;
}

function code_format(string $c): string
{
    return substr($c, 0, 5) . '-' . substr($c, 5);
}

/* ───────────── Stockage permanent (hébergement en conteneur) ───────────── */

/**
 * Sur Render (ou tout conteneur), le dossier RDC_DATA_DIR doit être un disque permanent : sinon tickets,
 * parties et photos disparaissent au redémarrage. Null : sans objet (hébergement classique).
 */
function storage_persistent(): ?bool
{
    $dir = getenv('RDC_DATA_DIR');
    if ($dir === false || trim((string)$dir) === '') return null;
    $dir = rtrim(trim((string)$dir), '/');
    $a = @stat($dir);
    $b = @stat(dirname($dir) ?: '/');
    if (!$a || !$b) return false;
    return $a['dev'] !== $b['dev']; // un disque monté n'est pas sur le même périphérique que son dossier parent
}

/* ───────────── Numéro de téléphone de l'administrateur ───────────── */

/** Numéro nettoyé : chiffres, avec « + » pour un numéro international (00 devient +). '' si invalide. */
function admin_phone_clean($v): string
{
    $s = trim((string)$v);
    if ($s === '') return '';
    $intl = strpos($s, '+') === 0;
    $d = (string)preg_replace('/\D/', '', $s);
    if (!$intl && strpos($d, '00') === 0) {
        $d = substr($d, 2);
        $intl = true;
    }
    if (strlen($d) < 6 || strlen($d) > 15) return '';
    return ($intl ? '+' : '') . $d;
}

/** Même numéro, quelle que soit l'écriture : +212 6…, 00212 6…, 06… (le 0 national remplace l'indicatif). */
function phone_same(string $a, string $b): bool
{
    $a = admin_phone_clean($a);
    $b = admin_phone_clean($b);
    if ($a === '' || $b === '') return false;
    $ia = $a[0] === '+';
    $ib = $b[0] === '+';
    $na = ltrim($a, '+');
    $nb = ltrim($b, '+');
    if (!$ia && $na[0] === '0') $na = substr($na, 1);
    if (!$ib && $nb[0] === '0') $nb = substr($nb, 1);
    if ($na === $nb) return true;
    if ($ia === $ib) return false;
    [$long, $short] = $ia ? [$na, $nb] : [$nb, $na];
    $diff = strlen($long) - strlen($short);
    return strlen($short) >= 6 && $diff >= 1 && $diff <= 3 && substr($long, -strlen($short)) === $short;
}

/* ───────────── Limitation des tentatives ───────────── */

function client_ip(): string
{
    $hdr = (string)cfg('client_ip_header', '');
    if ($hdr !== '' && !empty($_SERVER[$hdr]) && is_string($_SERVER[$hdr])) {
        // Liste « client, proxy… » : on garde la dernière adresse, celle ajoutée par votre proxy.
        $parts = array_map('trim', explode(',', (string)$_SERVER[$hdr]));
        $ip = (string)end($parts);
        if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/** Adresse utilisée pour les limites d'essais : une adresse IPv6 est regroupée par /64 (un abonné en a des milliers). */
function rate_ip(): string
{
    $ip = client_ip();
    if (strpos($ip, ':') !== false) {
        $bin = @inet_pton($ip);
        if ($bin !== false && strlen($bin) === 16) return bin2hex(substr($bin, 0, 8)) . '::/64';
    }
    return $ip;
}

function rate_count(string $key, int $windowSec): int
{
    return (int)val('SELECT COUNT(*) FROM attempts WHERE k = ? AND at > ?', [$key, time() - $windowSec]);
}

function rate_hit(string $key): void
{
    q('INSERT INTO attempts (k, at) VALUES (?, ?)', [$key, time()]);
    if (random_int(1, 50) === 1) q('DELETE FROM attempts WHERE at < ?', [time() - 172800]);
}

function rate_fail(int $windowSec): void
{
    $min = max(1, (int)ceil($windowSec / 60));
    fail('rate', "Trop d’essais incorrects. Patientez $min minutes avant de réessayer.", 429);
}

function rate_check(string $key, int $max, int $windowSec): void
{
    if (rate_count($key, $windowSec) >= $max) rate_fail($windowSec);
}

/**
 * Compte une tentative AVANT de la vérifier : des requêtes simultanées ne peuvent pas dépasser la limite.
 * Retourne l'identifiant de la tentative, à effacer (rate_release) si elle réussit.
 */
function rate_take(string $key, int $max, int $windowSec): int
{
    q('INSERT INTO attempts (k, at) VALUES (?, ?)', [$key, time()]);
    $id = (int)db()->lastInsertId();
    if (random_int(1, 50) === 1) q('DELETE FROM attempts WHERE at < ?', [time() - 172800]);
    if (rate_count($key, $windowSec) > $max) rate_fail($windowSec);
    return $id;
}

function rate_release(int $id): void
{
    if ($id > 0) q('DELETE FROM attempts WHERE id = ?', [$id]);
}

/** Alerte de sécurité affichée sur le tableau de bord de l'administration (7 jours). */
function app_alert(string $msg): void
{
    $list = json_decode((string)meta_get('alerts'), true);
    $list = is_array($list) ? $list : [];
    foreach ($list as $a) {
        if (($a['msg'] ?? '') === $msg && strtotime(($a['at'] ?? '') . ' UTC') > time() - 3600) return;
    }
    array_unshift($list, ['msg' => $msg, 'at' => now()]);
    meta_set('alerts', json_enc(array_slice($list, 0, 20)));
}

/* ───────────── Envoi d'images ───────────── */

function upload_limits(): array
{
    return ['logo' => 900, 'product' => 1000, 'background' => 2400, 'portal' => 900];
}

function handle_upload($f, string $kind): string
{
    if (!is_array($f) || !isset($f['error']) || is_array($f['error'])) fail('upload', 'Aucune image reçue.');
    switch ($f['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            fail('upload', 'Image trop lourde pour votre hébergement (limite : ' . ini_get('upload_max_filesize') . ').');
            break;
        case UPLOAD_ERR_NO_FILE:
            fail('upload', 'Aucune image reçue.');
            break;
        default:
            fail('upload', 'L’envoi a échoué (code ' . (int)$f['error'] . '). Réessayez.');
    }
    if ((int)$f['size'] > 15 * 1024 * 1024) fail('upload', 'Image trop lourde : 15 Mo maximum.');
    $info = @getimagesize($f['tmp_name']);
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif'];
    if (defined('IMAGETYPE_WEBP')) $types[IMAGETYPE_WEBP] = 'webp';
    if (!$info || !isset($types[$info[2]])) fail('upload', 'Format non pris en charge. Utilisez une image JPG, PNG, WebP ou GIF.');
    $type = $info[2];
    if ((int)$info[0] * (int)$info[1] > 40000000) fail('upload', 'Image trop grande (plus de 40 mégapixels). Réduisez-la avant de l’envoyer.');
    $ext = $types[$type];
    // Une image GIF est convertie en PNG (première image) quand le serveur sait la lire.
    if ($type === IMAGETYPE_GIF && function_exists('imagecreatefromgif')) $ext = 'png';
    $limits = upload_limits();
    $max = $limits[$kind] ?? 1200;

    $dir = RDC_ROOT . '/uploads';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (!is_writable($dir)) fail('upload', 'Le dossier uploads/ n’est pas accessible en écriture sur le serveur.');

    $name = preg_replace('/[^a-z]/', '', $kind) . '-' . gmdate('Ymd') . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
    $dest = $dir . '/' . $name;

    $done = false;
    if (function_exists('imagecreatetruecolor')) {
        $done = image_process($f['tmp_name'], $dest, $type, $max, (int)$info[0], (int)$info[1]);
        if (!$done && $type === IMAGETYPE_GIF) fail('upload', 'Cette image GIF n’a pas pu être lue. Utilisez une image JPG ou PNG.');
    }
    if (!$done && !move_uploaded_file($f['tmp_name'], $dest)) {
        if (!@copy($f['tmp_name'], $dest)) fail('upload', 'Impossible d’enregistrer l’image sur le serveur.');
    }
    @chmod($dest, 0644);
    return 'uploads/' . $name;
}

/**
 * Réencode toujours l'image avec GD (réorientée, réduite si besoin) : seuls les pixels sont conservés,
 * jamais d'éventuelles données cachées dans le fichier. Retourne false si GD ne peut pas la lire.
 */
function image_process(string $src, string $dest, int $type, int $max, int $w, int $h): bool
{
    $orientation = 1;
    if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($src);
        if (!empty($exif['Orientation'])) $orientation = (int)$exif['Orientation'];
    }

    $img = null;
    if ($type === IMAGETYPE_JPEG && function_exists('imagecreatefromjpeg')) $img = @imagecreatefromjpeg($src);
    elseif ($type === IMAGETYPE_PNG && function_exists('imagecreatefrompng')) $img = @imagecreatefrompng($src);
    elseif ($type === IMAGETYPE_GIF && function_exists('imagecreatefromgif')) $img = @imagecreatefromgif($src);
    elseif (defined('IMAGETYPE_WEBP') && $type === IMAGETYPE_WEBP && function_exists('imagecreatefromwebp')) $img = @imagecreatefromwebp($src);
    if (!$img) return false;
    if (!imageistruecolor($img)) imagepalettetotruecolor($img);

    if ($orientation === 3) $img = imagerotate($img, 180, 0);
    elseif ($orientation === 6) $img = imagerotate($img, -90, 0);
    elseif ($orientation === 8) $img = imagerotate($img, 90, 0);
    $w = imagesx($img);
    $h = imagesy($img);

    $ratio = min(1, $max / max($w, $h));
    $nw = max(1, (int)round($w * $ratio));
    $nh = max(1, (int)round($h * $ratio));
    $out = imagecreatetruecolor($nw, $nh);
    if ($type !== IMAGETYPE_JPEG) {
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    }
    imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $ok = false;
    if ($type === IMAGETYPE_JPEG) $ok = imagejpeg($out, $dest, 86);
    elseif ($type === IMAGETYPE_PNG || $type === IMAGETYPE_GIF) $ok = imagepng($out, $dest, 6);
    elseif (function_exists('imagewebp')) $ok = imagewebp($out, $dest, 86);
    imagedestroy($img);
    imagedestroy($out);
    return (bool)$ok;
}
