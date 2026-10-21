<?php
/* Roue de la Chance — authentification (administrateur et livreurs) par jetons en cookie HttpOnly */
defined('RDC') || exit;

const ADMIN_COOKIE = 'rdc_adm';
const LIVREUR_COOKIE = 'rdc_liv';
const ADMIN_TTL = 2592000;    // 30 jours
const LIVREUR_TTL = 7776000;  // 90 jours

function token_hash(string $t): string
{
    return hash('sha256', $t);
}

function cookie_set(string $name, string $value, int $ttl): void
{
    setcookie($name, $value, [
        'expires' => $ttl > 0 ? time() + $ttl : time() - 3600,
        'path' => base_path(),
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function token_create(string $role, ?int $livreurId, int $ttl): string
{
    $t = random_token(32);
    insert('tokens', [
        'token_hash' => token_hash($t), 'role' => $role, 'livreur_id' => $livreurId,
        'created_at' => now(), 'expires_at' => gmdate('Y-m-d H:i:s', time() + $ttl), 'last_used_at' => now(),
    ]);
    if (random_int(1, 20) === 1) q('DELETE FROM tokens WHERE expires_at < ?', [now()]);
    return $t;
}

function token_lookup(string $cookie, string $role): ?array
{
    $t = $_COOKIE[$cookie] ?? '';
    if (!is_string($t) || strlen($t) < 20 || strlen($t) > 100) return null;
    $r = row('SELECT * FROM tokens WHERE token_hash = ? AND role = ?', [token_hash($t), $role]);
    if (!$r) return null;
    if ($r['expires_at'] < now()) {
        q('DELETE FROM tokens WHERE id = ?', [$r['id']]);
        return null;
    }
    if (!$r['last_used_at'] || strtotime($r['last_used_at'] . ' UTC') < time() - 600) {
        q('UPDATE tokens SET last_used_at = ? WHERE id = ?', [now(), $r['id']]);
    }
    return $r;
}

function token_revoke(string $cookie): void
{
    $t = $_COOKIE[$cookie] ?? '';
    if (is_string($t) && $t !== '') q('DELETE FROM tokens WHERE token_hash = ?', [token_hash($t)]);
    cookie_set($cookie, '', -1);
}

/* ───────────── Administrateur ───────────── */

function admin_password_set(): bool
{
    return (bool)meta_get('admin_hash');
}

/** Numéro de téléphone qui sert d'identifiant à l'administrateur ('' : connexion avec le mot de passe seul). */
function admin_phone(): string
{
    return (string)(meta_get('admin_phone') ?? '');
}

/** Réglage de config.php, en ignorant un modèle non rempli (« __ADMIN_PHONE__ »). */
function cfg_filled(string $k): string
{
    $v = trim((string)cfg($k, ''));
    return strpos($v, '__') === 0 ? '' : $v;
}

/**
 * Accès de départ : à la première ouverture, le numéro et le mot de passe de config.php créent l'accès
 * administrateur (sans écran d'installation). Ensuite, ils se changent depuis l'administration.
 */
function admin_ensure_default(): void
{
    if (admin_password_set()) return;
    $pw = cfg_filled('admin_password');
    if (mb_strlen($pw) < 8) return;
    $phone = admin_phone_clean(cfg_filled('admin_phone'));
    tx_write(function () use ($pw, $phone) {
        if (admin_password_set()) return;
        q("DELETE FROM meta WHERE k IN ('admin_hash', 'admin_phone', 'admin_default')");
        q("INSERT INTO meta (k, v) VALUES ('admin_hash', ?)", [password_hash($pw, PASSWORD_DEFAULT)]);
        if ($phone !== '') q("INSERT INTO meta (k, v) VALUES ('admin_phone', ?)", [$phone]);
        q("INSERT INTO meta (k, v) VALUES ('admin_default', ?)", [now()]);
    });
}

/** État public de l'administration (écran à afficher), sans rien révéler du numéro. */
function admin_public_state(): array
{
    admin_ensure_default();
    return ['setup' => admin_setup_open(), 'authed' => is_admin(), 'phone' => admin_phone() !== '', 'reset' => admin_password_set()];
}

/** L'écran d'installation est proposé tant qu'aucun mot de passe n'existe, ou une fois si reset_admin est activé. */
function admin_setup_open(): bool
{
    if (!admin_password_set()) return true;
    if (cfg('reset_admin') !== true) {
        if (meta_get('reset_done') !== null) q("DELETE FROM meta WHERE k = 'reset_done'");
        return false;
    }
    return meta_get('reset_done') === null; // une seule réinitialisation tant que reset_admin reste à true
}

function is_admin(): bool
{
    static $v = null;
    if ($v === null) $v = (bool)token_lookup(ADMIN_COOKIE, 'admin');
    return $v;
}

function require_admin(): void
{
    if (!is_admin()) fail('auth', 'Votre session a expiré. Reconnectez-vous.', 401);
}

function admin_open_session(): void
{
    $t = token_create('admin', null, ADMIN_TTL);
    cookie_set(ADMIN_COOKIE, $t, ADMIN_TTL);
}

/* ───────────── Livreurs ───────────── */

function pin_hash(string $pin): string
{
    return hash_hmac('sha256', 'pin:' . $pin, app_secret());
}

function current_livreur(): ?array
{
    static $cache = false;
    if ($cache !== false) return $cache;
    $cache = null;
    $tok = token_lookup(LIVREUR_COOKIE, 'livreur');
    if ($tok && $tok['livreur_id']) {
        $r = row('SELECT * FROM livreurs WHERE id = ? AND active = 1', [(int)$tok['livreur_id']]);
        if ($r) $cache = $r;
    }
    return $cache;
}

/** Livreur connecté ; l'administrateur connecté a aussi accès à l'espace livreur. */
function require_livreur(): array
{
    $l = current_livreur();
    if ($l) return ['id' => (int)$l['id'], 'name' => (string)$l['name'], 'admin' => false];
    if (is_admin()) return ['id' => null, 'name' => 'Administrateur', 'admin' => true];
    fail('auth', 'Connectez-vous avec votre code livreur.', 401);
    return [];
}
