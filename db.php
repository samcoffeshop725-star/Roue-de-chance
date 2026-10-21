<?php
/*
 * Roue de la Chance — base de données centrale.
 * Une seule base pour tous les projets : un code = une ligne ; l'utiliser = une transaction atomique.
 */
defined('RDC') || exit;

const SCHEMA_VERSION = 1;

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $driver = cfg('db.driver', 'sqlite');
    $opts = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    if ($driver === 'mysql') {
        $m = cfg('db.mysql', []);
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $m['host'] ?? 'localhost', (int)($m['port'] ?? 3306), $m['name'] ?? '');
        $opts[PDO::ATTR_EMULATE_PREPARES] = false;
        if (defined('PDO::MYSQL_ATTR_FOUND_ROWS')) $opts[PDO::MYSQL_ATTR_FOUND_ROWS] = true;
        $pdo = new PDO($dsn, (string)($m['user'] ?? ''), (string)($m['pass'] ?? ''), $opts);
        $pdo->exec("SET NAMES utf8mb4");
    } else {
        $path = (string)cfg('db.sqlite_path', RDC_ROOT . '/data/roue.sqlite');
        $dir = dirname($path);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $pdo = new PDO('sqlite:' . $path, null, null, $opts);
        $pdo->setAttribute(PDO::ATTR_TIMEOUT, 15);
        $pdo->exec('PRAGMA busy_timeout = 15000');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
    }
    $GLOBALS['RDC_PDO_DRIVER'] = $driver === 'mysql' ? 'mysql' : 'sqlite';
    migrate($pdo);
    return $pdo;
}

function is_mysql(): bool
{
    return ($GLOBALS['RDC_PDO_DRIVER'] ?? cfg('db.driver', 'sqlite')) === 'mysql';
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute(array_values($params));
    return $st;
}

function row(string $sql, array $params = []): ?array
{
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}

function rows(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function val(string $sql, array $params = [])
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

function insert(string $table, array $data): int
{
    $cols = array_keys($data);
    $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';
    q($sql, array_values($data));
    return (int)db()->lastInsertId();
}

function update_row(string $table, int $id, array $data): int
{
    $set = [];
    foreach (array_keys($data) as $c) $set[] = $c . ' = ?';
    $vals = array_values($data);
    $vals[] = $id;
    return q('UPDATE ' . $table . ' SET ' . implode(', ', $set) . ' WHERE id = ?', $vals)->rowCount();
}

/** Clause de verrouillage de ligne (MySQL). SQLite verrouille toute la base en écriture. */
function for_update(): string
{
    return is_mysql() ? ' FOR UPDATE' : '';
}

/**
 * Exécute $fn dans une transaction d'écriture exclusive.
 * SQLite : BEGIN IMMEDIATE (un seul écrivain à la fois) ; MySQL : transaction InnoDB + verrous de ligne.
 * Les conflits temporaires (base occupée, interblocage) sont rejoués automatiquement.
 */
function tx_write(callable $fn)
{
    static $depth = 0;
    if ($depth > 0) return $fn();
    $pdo = db();
    for ($attempt = 1; ; $attempt++) {
        $depth++;
        try {
            if (is_mysql()) $pdo->beginTransaction(); else $pdo->exec('BEGIN IMMEDIATE');
            $result = $fn();
            if (is_mysql()) $pdo->commit(); else $pdo->exec('COMMIT');
            $depth--;
            return $result;
        } catch (Throwable $e) {
            $depth--;
            try {
                if (is_mysql()) { if ($pdo->inTransaction()) $pdo->rollBack(); }
                else $pdo->exec('ROLLBACK');
            } catch (Throwable $ignored) {
            }
            if ($e instanceof PDOException && $attempt < 6 && db_is_retryable($e)) {
                usleep(random_int(15000, 90000) * $attempt);
                continue;
            }
            throw $e;
        }
    }
}

function db_is_retryable(PDOException $e): bool
{
    $code = (int)($e->errorInfo[1] ?? 0);
    if (is_mysql()) return in_array($code, [1205, 1213], true);
    return in_array($code, [5, 6], true) || stripos($e->getMessage(), 'locked') !== false || stripos($e->getMessage(), 'busy') !== false;
}

/* ───────────── Méta-données & réglages globaux ───────────── */

function meta_get(string $k): ?string
{
    $v = val('SELECT v FROM meta WHERE k = ?', [$k]);
    return $v === null ? null : (string)$v;
}

function meta_set(string $k, string $v): void
{
    tx_write(function () use ($k, $v) {
        if (val('SELECT COUNT(*) FROM meta WHERE k = ?', [$k])) q('UPDATE meta SET v = ? WHERE k = ?', [$v, $k]);
        else q('INSERT INTO meta (k, v) VALUES (?, ?)', [$k, $v]);
    });
}

function settings_all(bool $fresh = false): array
{
    static $cache = null;
    if ($cache === null || $fresh) {
        $stored = json_decode((string)meta_get('settings'), true);
        $cache = deep_merge(defaults()['global'], is_array($stored) ? $stored : []);
    }
    return $cache;
}

function setting(string $key, $default = null)
{
    $v = settings_all();
    foreach (explode('.', $key) as $p) {
        if (!is_array($v) || !array_key_exists($p, $v)) return $default;
        $v = $v[$p];
    }
    return $v;
}

function settings_save(array $settings): array
{
    meta_set('settings', json_enc($settings));
    return settings_all(true);
}

function app_secret(): string
{
    $s = meta_get('app_secret');
    if (!$s) {
        $s = random_token(32);
        meta_set('app_secret', $s);
    }
    return $s;
}

/* ───────────── Schéma ───────────── */

function schema_sql(): array
{
    if (is_mysql()) {
        $e = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        return [
            "CREATE TABLE IF NOT EXISTS meta (k VARCHAR(64) NOT NULL PRIMARY KEY, v MEDIUMTEXT)$e",
            "CREATE TABLE IF NOT EXISTS projects (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                slug VARCHAR(64) NOT NULL, name VARCHAR(120) NOT NULL, domain VARCHAR(190) NOT NULL DEFAULT '',
                active TINYINT NOT NULL DEFAULT 1, sort INT NOT NULL DEFAULT 0, settings MEDIUMTEXT NOT NULL,
                created_at VARCHAR(20) NOT NULL, updated_at VARCHAR(20) NOT NULL, UNIQUE KEY uq_slug (slug))$e",
            "CREATE TABLE IF NOT EXISTS products (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, project_id INT UNSIGNED NOT NULL,
                name VARCHAR(120) NOT NULL, weight VARCHAR(60) NOT NULL DEFAULT '', value_text VARCHAR(60) NOT NULL DEFAULT '',
                description VARCHAR(500) NOT NULL DEFAULT '', photo VARCHAR(500) NOT NULL DEFAULT '', stock INT NULL,
                active TINYINT NOT NULL DEFAULT 1, sort INT NOT NULL DEFAULT 0,
                created_at VARCHAR(20) NOT NULL, updated_at VARCHAR(20) NOT NULL, KEY idx_products_project (project_id))$e",
            "CREATE TABLE IF NOT EXISTS segments (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, project_id INT UNSIGNED NOT NULL,
                position INT NOT NULL DEFAULT 0, kind VARCHAR(16) NOT NULL, product_id INT UNSIGNED NULL,
                label VARCHAR(80) NOT NULL DEFAULT '', sub VARCHAR(80) NOT NULL DEFAULT '', color VARCHAR(9) NOT NULL DEFAULT '',
                chance INT NOT NULL DEFAULT 10, KEY idx_segments_project (project_id))$e",
            "CREATE TABLE IF NOT EXISTS batches (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, label VARCHAR(120) NOT NULL,
                quantity INT NOT NULL DEFAULT 0, created_at VARCHAR(20) NOT NULL)$e",
            "CREATE TABLE IF NOT EXISTS codes (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, code VARCHAR(16) NOT NULL, batch_id INT UNSIGNED NULL,
                status VARCHAR(8) NOT NULL DEFAULT 'new', project_id INT UNSIGNED NULL, used_at VARCHAR(20) NULL,
                created_at VARCHAR(20) NOT NULL, UNIQUE KEY uq_code (code), KEY idx_codes_batch (batch_id), KEY idx_codes_status (status))$e",
            "CREATE TABLE IF NOT EXISTS plays (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, code_id INT UNSIGNED NOT NULL, project_id INT UNSIGNED NOT NULL,
                segment_id INT UNSIGNED NULL, kind VARCHAR(16) NOT NULL, product_id INT UNSIGNED NULL,
                prize_name VARCHAR(120) NOT NULL DEFAULT '', prize_weight VARCHAR(60) NOT NULL DEFAULT '',
                prize_photo VARCHAR(500) NOT NULL DEFAULT '', prize_value VARCHAR(60) NOT NULL DEFAULT '',
                status VARCHAR(12) NOT NULL, customer_name VARCHAR(120) NOT NULL DEFAULT '', customer_phone VARCHAR(40) NOT NULL DEFAULT '',
                customer_note VARCHAR(400) NOT NULL DEFAULT '', claimed_at VARCHAR(20) NULL, delivered_at VARCHAR(20) NULL,
                delivered_by INT UNSIGNED NULL, delivered_by_name VARCHAR(120) NOT NULL DEFAULT '', ip VARCHAR(64) NOT NULL DEFAULT '',
                created_at VARCHAR(20) NOT NULL, UNIQUE KEY uq_play_code (code_id), KEY idx_plays_project (project_id),
                KEY idx_plays_status (status), KEY idx_plays_created (created_at))$e",
            "CREATE TABLE IF NOT EXISTS livreurs (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, pin_hash VARCHAR(80) NOT NULL,
                active TINYINT NOT NULL DEFAULT 1, created_at VARCHAR(20) NOT NULL, last_seen_at VARCHAR(20) NULL,
                UNIQUE KEY uq_pin (pin_hash))$e",
            "CREATE TABLE IF NOT EXISTS tokens (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, token_hash VARCHAR(80) NOT NULL, role VARCHAR(12) NOT NULL,
                livreur_id INT UNSIGNED NULL, created_at VARCHAR(20) NOT NULL, expires_at VARCHAR(20) NOT NULL,
                last_used_at VARCHAR(20) NULL, UNIQUE KEY uq_token (token_hash))$e",
            "CREATE TABLE IF NOT EXISTS attempts (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, k VARCHAR(120) NOT NULL, at BIGINT NOT NULL,
                KEY idx_attempts_k (k, at))$e",
        ];
    }
    return [
        "CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY, v TEXT)",
        "CREATE TABLE IF NOT EXISTS projects (
            id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT NOT NULL UNIQUE, name TEXT NOT NULL, domain TEXT NOT NULL DEFAULT '',
            active INTEGER NOT NULL DEFAULT 1, sort INTEGER NOT NULL DEFAULT 0, settings TEXT NOT NULL,
            created_at TEXT NOT NULL, updated_at TEXT NOT NULL)",
        "CREATE TABLE IF NOT EXISTS products (
            id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER NOT NULL, name TEXT NOT NULL,
            weight TEXT NOT NULL DEFAULT '', value_text TEXT NOT NULL DEFAULT '', description TEXT NOT NULL DEFAULT '',
            photo TEXT NOT NULL DEFAULT '', stock INTEGER NULL, active INTEGER NOT NULL DEFAULT 1, sort INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL, updated_at TEXT NOT NULL)",
        "CREATE INDEX IF NOT EXISTS idx_products_project ON products(project_id)",
        "CREATE TABLE IF NOT EXISTS segments (
            id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER NOT NULL, position INTEGER NOT NULL DEFAULT 0,
            kind TEXT NOT NULL, product_id INTEGER NULL, label TEXT NOT NULL DEFAULT '', sub TEXT NOT NULL DEFAULT '',
            color TEXT NOT NULL DEFAULT '', chance INTEGER NOT NULL DEFAULT 10)",
        "CREATE INDEX IF NOT EXISTS idx_segments_project ON segments(project_id)",
        "CREATE TABLE IF NOT EXISTS batches (
            id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT NOT NULL, quantity INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL)",
        "CREATE TABLE IF NOT EXISTS codes (
            id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL UNIQUE, batch_id INTEGER NULL,
            status TEXT NOT NULL DEFAULT 'new', project_id INTEGER NULL, used_at TEXT NULL, created_at TEXT NOT NULL)",
        "CREATE INDEX IF NOT EXISTS idx_codes_batch ON codes(batch_id)",
        "CREATE INDEX IF NOT EXISTS idx_codes_status ON codes(status)",
        "CREATE TABLE IF NOT EXISTS plays (
            id INTEGER PRIMARY KEY AUTOINCREMENT, code_id INTEGER NOT NULL UNIQUE, project_id INTEGER NOT NULL,
            segment_id INTEGER NULL, kind TEXT NOT NULL, product_id INTEGER NULL,
            prize_name TEXT NOT NULL DEFAULT '', prize_weight TEXT NOT NULL DEFAULT '', prize_photo TEXT NOT NULL DEFAULT '',
            prize_value TEXT NOT NULL DEFAULT '', status TEXT NOT NULL,
            customer_name TEXT NOT NULL DEFAULT '', customer_phone TEXT NOT NULL DEFAULT '', customer_note TEXT NOT NULL DEFAULT '',
            claimed_at TEXT NULL, delivered_at TEXT NULL, delivered_by INTEGER NULL, delivered_by_name TEXT NOT NULL DEFAULT '',
            ip TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL)",
        "CREATE INDEX IF NOT EXISTS idx_plays_project ON plays(project_id)",
        "CREATE INDEX IF NOT EXISTS idx_plays_status ON plays(status)",
        "CREATE INDEX IF NOT EXISTS idx_plays_created ON plays(created_at)",
        "CREATE TABLE IF NOT EXISTS livreurs (
            id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, pin_hash TEXT NOT NULL UNIQUE,
            active INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL, last_seen_at TEXT NULL)",
        "CREATE TABLE IF NOT EXISTS tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT, token_hash TEXT NOT NULL UNIQUE, role TEXT NOT NULL, livreur_id INTEGER NULL,
            created_at TEXT NOT NULL, expires_at TEXT NOT NULL, last_used_at TEXT NULL)",
        "CREATE TABLE IF NOT EXISTS attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, k TEXT NOT NULL, at INTEGER NOT NULL)",
        "CREATE INDEX IF NOT EXISTS idx_attempts_k ON attempts(k, at)",
    ];
}

function migrate(PDO $pdo): void
{
    try {
        $v = $pdo->query("SELECT v FROM meta WHERE k = 'schema_version'")->fetchColumn();
    } catch (PDOException $e) {
        $v = false;
    }
    if ($v !== false && (int)$v >= SCHEMA_VERSION) return;

    foreach (schema_sql() as $sql) $pdo->exec($sql);

    try {
        migrate_seed();
    } catch (PDOException $e) {
        // Deux toutes premières requêtes simultanées (MySQL) : l'autre a déjà tout installé.
        if ((string)$e->getCode() !== '23000') throw $e;
    }
}

function migrate_seed(): void
{
    tx_write(function () {
        if (!val("SELECT v FROM meta WHERE k = 'seeded'")) {
            seed_database();
            q("INSERT INTO meta (k, v) VALUES ('seeded', ?)", [now()]);
        }
        if (!val("SELECT COUNT(*) FROM meta WHERE k = 'app_secret'")) {
            q("INSERT INTO meta (k, v) VALUES ('app_secret', ?)", [random_token(32)]);
        }
        if (val("SELECT COUNT(*) FROM meta WHERE k = 'schema_version'")) {
            q("UPDATE meta SET v = ? WHERE k = 'schema_version'", [(string)SCHEMA_VERSION]);
        } else {
            q("INSERT INTO meta (k, v) VALUES ('schema_version', ?)", [(string)SCHEMA_VERSION]);
        }
    });
}

/** Données d'exemple : 4 projets prêts à personnaliser + un lot de codes d'essai. */
function seed_database(): void
{
    $d = defaults();
    $seed = $d['seed'];
    $t = now();
    q("INSERT INTO meta (k, v) VALUES ('settings', '{}')");
    foreach ($seed['projects'] as $i => $p) {
        $preset = $d['presets'][$p['preset']] ?? $d['presets']['nuit'];
        $settings = deep_merge($d['project'], [
            'brand' => ['logo' => $p['logo'], 'tagline' => $p['tagline']],
            'theme' => [
                'preset' => $p['preset'], 'c1' => $preset['c1'], 'c2' => $preset['c2'], 'c3' => $preset['c3'],
                'bg1' => $preset['bg1'], 'bg2' => $preset['bg2'], 'text' => $preset['text'],
            ],
        ]);
        $pid = insert('projects', [
            'slug' => $p['slug'], 'name' => $p['name'], 'domain' => '', 'active' => 1, 'sort' => $i,
            'settings' => json_enc($settings), 'created_at' => $t, 'updated_at' => $t,
        ]);
        seed_project_content($pid);
    }
    $bid = insert('batches', ['label' => 'Codes d’essai (à annuler avant le lancement)', 'quantity' => (int)$seed['codes'], 'created_at' => $t]);
    for ($i = 0; $i < (int)$seed['codes']; $i++) {
        insert_unique_code($bid, $t);
    }
}

function seed_project_content(int $pid): void
{
    $seed = defaults()['seed'];
    $t = now();
    $ids = [];
    foreach ($seed['products'] as $j => $pr) {
        $ids[$pr['key']] = insert('products', [
            'project_id' => $pid, 'name' => $pr['name'], 'weight' => $pr['weight'], 'value_text' => $pr['value'],
            'description' => '', 'photo' => $pr['photo'], 'stock' => $pr['stock'], 'active' => 1, 'sort' => $j,
            'created_at' => $t, 'updated_at' => $t,
        ]);
    }
    foreach ($seed['wheel'] as $k => $s) {
        insert('segments', [
            'project_id' => $pid, 'position' => $k, 'kind' => $s['kind'],
            'product_id' => isset($s['product']) ? $ids[$s['product']] : null,
            'label' => '', 'sub' => '', 'color' => '', 'chance' => (int)$s['chance'],
        ]);
    }
}

function insert_unique_code(?int $batchId, string $t): string
{
    for ($try = 0; $try < 20; $try++) {
        $c = code_generate();
        if (!val('SELECT COUNT(*) FROM codes WHERE code = ?', [$c])) {
            insert('codes', ['code' => $c, 'batch_id' => $batchId, 'status' => 'new', 'project_id' => null, 'used_at' => null, 'created_at' => $t]);
            return $c;
        }
    }
    throw new RuntimeException('Impossible de générer un code unique');
}
