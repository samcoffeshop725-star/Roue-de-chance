<?php
/* Roue de la Chance — API d'administration */
defined('RDC') || exit;

/* ───────────── Accès ───────────── */

function adm_state(): array
{
    return admin_public_state();
}

/** Numéro saisi dans un formulaire : '' si vide, sinon numéro nettoyé (erreur s'il est invalide). */
function adm_phone_input(array $in, string $field = 'phone'): string
{
    $raw = trim((string)($in[$field] ?? ''));
    if ($raw === '') return '';
    $phone = admin_phone_clean($raw);
    if ($phone === '') fail('field', 'Numéro de téléphone invalide. Indiquez-le avec l’indicatif du pays, par exemple +212 6 12 34 56 78.', 200, ['field' => $field]);
    return $phone;
}

function adm_setup(array $in): array
{
    admin_ensure_default();
    if (!admin_setup_open()) fail('done', 'Le mot de passe administrateur est déjà défini. Connectez-vous.');
    $att = rate_take('setup:' . rate_ip(), 10, 900);
    $expected = (string)cfg('install_key', '');
    if ($expected === '' || strpos($expected, '__') === 0) {
        fail('key', 'Aucune clé d’installation n’est définie : renseignez « install_key » dans le fichier config.php (sur Render : la variable RDC_INSTALL_KEY).');
    }
    if (!hash_equals($expected, trim((string)($in['install_key'] ?? '')))) {
        fail('key', 'Clé d’installation incorrecte. Vous la trouverez dans le fichier config.php (ligne install_key).');
    }
    $pw = (string)($in['password'] ?? '');
    if (mb_strlen($pw) < 8) fail('weak', 'Choisissez un mot de passe d’au moins 8 caractères.');
    $phone = adm_phone_input($in);
    tx_write(function () use ($pw, $phone) {
        if (!admin_setup_open()) fail('done', 'Le mot de passe administrateur est déjà défini. Connectez-vous.');
        $hash = password_hash($pw, PASSWORD_DEFAULT);
        if (admin_password_set()) {
            // Réinitialisation (reset_admin) : une seule fois, toutes les sessions sont fermées.
            q("UPDATE meta SET v = ? WHERE k = 'admin_hash'", [$hash]);
            q("DELETE FROM tokens WHERE role = 'admin'");
            q("DELETE FROM meta WHERE k = 'reset_done'");
            q("INSERT INTO meta (k, v) VALUES ('reset_done', ?)", [now()]);
        } else {
            q("INSERT INTO meta (k, v) VALUES ('admin_hash', ?)", [$hash]);
        }
        // Numéro d'accès : remplacé s'il est indiqué (vide : on garde le numéro actuel).
        if ($phone !== '') {
            q("DELETE FROM meta WHERE k = 'admin_phone'");
            q("INSERT INTO meta (k, v) VALUES ('admin_phone', ?)", [$phone]);
        }
        q("DELETE FROM meta WHERE k = 'admin_default'");
    });
    rate_release($att);
    admin_open_session();
    return ['authed' => true];
}

function adm_login(array $in): array
{
    admin_ensure_default();
    $att = rate_take('login:' . rate_ip(), 8, 900);
    $hash = meta_get('admin_hash');
    $pw = (string)($in['password'] ?? '');
    $phone = admin_phone();
    // Le mot de passe est toujours vérifié, même si le numéro ne correspond pas (même temps de réponse).
    $okPw = $hash && password_verify($pw, $hash);
    $okPhone = $phone === '' || phone_same((string)($in['phone'] ?? ''), $phone);
    if (!$okPw || !$okPhone) fail('login', $phone !== '' ? 'Numéro ou mot de passe incorrect.' : 'Mot de passe incorrect.');
    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) meta_set('admin_hash', password_hash($pw, PASSWORD_DEFAULT));
    rate_release($att);
    admin_open_session();
    return ['authed' => true];
}

function adm_logout(): array
{
    token_revoke(ADMIN_COOKIE);
    return [];
}

/** Accès administrateur : numéro de téléphone et/ou mot de passe, modifiables à tout moment (mot de passe actuel exigé). */
function adm_password(array $in): array
{
    $hash = meta_get('admin_hash');
    if (!$hash || !password_verify((string)($in['current'] ?? ''), $hash)) fail('login', 'Le mot de passe actuel est incorrect.', 200, ['field' => 'current']);
    // 1) Vérifications d'abord : rien n'est enregistré si une valeur est refusée.
    $setPhone = null;
    if (array_key_exists('phone', $in)) {
        $phone = adm_phone_input($in);
        if ($phone === '' && admin_phone() !== '') fail('field', 'Indiquez le numéro de téléphone qui vous sert à vous connecter.', 200, ['field' => 'phone']);
        if ($phone !== '' && $phone !== admin_phone()) $setPhone = $phone;
    }
    $new = (string)($in['new'] ?? '');
    $setPw = $new !== '' || !array_key_exists('phone', $in);
    if ($setPw && mb_strlen($new) < 8) fail('weak', 'Le nouveau mot de passe doit compter au moins 8 caractères.', 200, ['field' => 'new']);
    if ($setPhone === null && !$setPw) fail('nothing', 'Aucune modification : le numéro est déjà celui-ci et aucun nouveau mot de passe n’est indiqué.');
    // 2) Enregistrement d'un bloc.
    $cur = (string)($_COOKIE[ADMIN_COOKIE] ?? '');
    tx_write(function () use ($setPhone, $setPw, $new, $cur) {
        if ($setPhone !== null) meta_set('admin_phone', $setPhone);
        if ($setPw) {
            meta_set('admin_hash', password_hash($new, PASSWORD_DEFAULT));
            q("DELETE FROM meta WHERE k = 'admin_default'");
            q("DELETE FROM tokens WHERE role = 'admin' AND token_hash <> ?", [token_hash($cur)]);
        }
    });
    $changed = array_values(array_filter([$setPhone !== null ? 'phone' : null, $setPw ? 'password' : null]));
    return ['changed' => $changed, 'phone' => admin_phone()];
}

/* ───────────── Démarrage & tableau de bord ───────────── */

function project_url(array $p): string
{
    $domain = trim((string)$p['domain']);
    if ($domain !== '') return 'https://' . $domain . '/';
    return public_base_url() . '?p=' . rawurlencode((string)$p['slug']);
}

function adm_project_summary(array $p): array
{
    $s = project_settings($p);
    return [
        'id' => (int)$p['id'], 'slug' => (string)$p['slug'], 'name' => (string)$p['name'], 'domain' => (string)$p['domain'],
        'active' => (bool)(int)$p['active'], 'sort' => (int)$p['sort'], 'logo' => (string)$s['brand']['logo'],
        'c1' => $s['theme']['c1'], 'c2' => $s['theme']['c2'], 'c3' => $s['theme']['c3'], 'bg1' => $s['theme']['bg1'], 'bg2' => $s['theme']['bg2'],
        'url' => project_url($p),
    ];
}

function adm_boot(): array
{
    $d = defaults();
    return [
        'projects' => array_map('adm_project_summary', projects_list()),
        'settings' => settings_all(),
        'defaults' => ['presets' => $d['presets'], 'fonts' => $d['fonts'], 'project' => $d['project']],
        'detected_base' => base_url(),
        'public_base' => public_base_url(),
        'version' => RDC,
        'driver' => is_mysql() ? 'mysql' : 'sqlite',
        'upload_max' => ini_get('upload_max_filesize'),
        'timezones' => timezone_identifiers_list(),
        'admin_phone' => admin_phone(),
        'default_password' => meta_get('admin_default') !== null,
    ];
}

function local_day_start_utc(int $daysAgo = 0): string
{
    $tz = new DateTimeZone(valid_tz((string)setting('timezone')));
    $d = new DateTime('today', $tz);
    if ($daysAgo) $d->modify("-$daysAgo days");
    $d->setTimezone(new DateTimeZone('UTC'));
    return $d->format('Y-m-d H:i:s');
}

function valid_tz(string $tz): string
{
    return in_array($tz, timezone_identifiers_list(), true) ? $tz : 'Europe/Paris';
}

function fmt_local(?string $utc, string $fmt = 'd/m/Y H:i'): string
{
    if (!$utc) return '';
    $d = new DateTime($utc, new DateTimeZone('UTC'));
    $d->setTimezone(new DateTimeZone(valid_tz((string)setting('timezone'))));
    return $d->format($fmt);
}

function adm_dashboard(): array
{
    $codes = row("SELECT COUNT(*) AS total,
        SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) AS fresh,
        SUM(CASE WHEN status = 'used' THEN 1 ELSE 0 END) AS used,
        SUM(CASE WHEN status = 'void' THEN 1 ELSE 0 END) AS void FROM codes");
    $today = local_day_start_utc(0);
    $plays = row("SELECT COUNT(*) AS total,
        SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) AS today,
        SUM(CASE WHEN kind <> 'lose' THEN 1 ELSE 0 END) AS wins,
        SUM(CASE WHEN kind = 'jackpot' THEN 1 ELSE 0 END) AS jackpots,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) AS delivered FROM plays", [$today]);
    $per = [];
    foreach (rows("SELECT project_id, COUNT(*) AS plays,
            SUM(CASE WHEN kind <> 'lose' THEN 1 ELSE 0 END) AS wins,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) AS today
            FROM plays GROUP BY project_id", [$today]) as $r) {
        $per[(int)$r['project_id']] = ['plays' => (int)$r['plays'], 'wins' => (int)$r['wins'], 'pending' => (int)$r['pending'], 'today' => (int)$r['today']];
    }
    $projects = [];
    $byId = [];
    foreach (projects_list() as $p) {
        $byId[(int)$p['id']] = $p;
        $projects[] = adm_project_summary($p) + ['stats' => $per[(int)$p['id']] ?? ['plays' => 0, 'wins' => 0, 'pending' => 0, 'today' => 0]];
    }
    // Parties par jour (14 derniers jours, fuseau de l'administration)
    $since = local_day_start_utc(13);
    $days = [];
    for ($i = 13; $i >= 0; $i--) $days[fmt_local(local_day_start_utc($i), 'Y-m-d')] = 0;
    foreach (rows('SELECT created_at FROM plays WHERE created_at >= ?', [$since]) as $r) {
        $k = fmt_local($r['created_at'], 'Y-m-d');
        if (isset($days[$k])) $days[$k]++;
    }
    $series = [];
    foreach ($days as $k => $n) $series[] = ['day' => $k, 'plays' => $n];

    $recent = [];
    foreach (rows('SELECT pl.*, c.code FROM plays pl JOIN codes c ON c.id = pl.code_id ORDER BY pl.id DESC LIMIT 12') as $pl) {
        $recent[] = result_payload($pl, $byId[(int)$pl['project_id']] ?? null, true);
    }
    $low = [];
    foreach (rows('SELECT pr.*, p.name AS project_name FROM products pr JOIN projects p ON p.id = pr.project_id
                   WHERE pr.stock IS NOT NULL AND pr.stock <= 3 AND pr.active = 1 ORDER BY pr.stock, pr.name LIMIT 20') as $r) {
        $low[] = ['id' => (int)$r['id'], 'project_id' => (int)$r['project_id'], 'project' => (string)$r['project_name'],
            'name' => (string)$r['name'], 'weight' => (string)$r['weight'], 'stock' => (int)$r['stock'], 'photo' => (string)$r['photo']];
    }
    return [
        'codes' => array_map('intval', $codes ?: []),
        'plays' => array_map('intval', $plays ?: []),
        'projects' => $projects,
        'series' => $series,
        'recent' => $recent,
        'low_stock' => $low,
        'default_password' => meta_get('admin_default') !== null,
        'storage' => storage_persistent(),
        'alerts' => array_values(array_filter((array)json_decode((string)meta_get('alerts'), true), function ($a) {
            return is_array($a) && strtotime(($a['at'] ?? '') . ' UTC') > time() - 7 * 86400;
        })),
    ];
}

/* ───────────── Projets ───────────── */

function adm_require_project($id): array
{
    $p = project_by_id((int)$id);
    if (!$p) fail('notfound', 'Projet introuvable.');
    return $p;
}

function adm_product_row(array $r): array
{
    return [
        'id' => (int)$r['id'], 'project_id' => (int)$r['project_id'], 'name' => (string)$r['name'], 'weight' => (string)$r['weight'],
        'value' => (string)$r['value_text'], 'description' => (string)$r['description'], 'photo' => (string)$r['photo'],
        'stock' => $r['stock'] === null ? null : (int)$r['stock'], 'active' => (bool)(int)$r['active'], 'sort' => (int)$r['sort'],
    ];
}

function adm_products(int $pid): array
{
    return array_map('adm_product_row', rows('SELECT * FROM products WHERE project_id = ? ORDER BY sort, id', [$pid]));
}

function adm_segments(int $pid): array
{
    $out = [];
    foreach (rows('SELECT * FROM segments WHERE project_id = ? ORDER BY position, id', [$pid]) as $s) {
        $out[] = [
            'id' => (int)$s['id'], 'kind' => (string)$s['kind'], 'product_id' => $s['product_id'] === null ? null : (int)$s['product_id'],
            'label' => (string)$s['label'], 'sub' => (string)$s['sub'], 'color' => (string)$s['color'], 'chance' => (int)$s['chance'],
        ];
    }
    return $out;
}

function adm_project_payload(array $p): array
{
    $pid = (int)$p['id'];
    $stats = row("SELECT COUNT(*) AS plays, SUM(CASE WHEN kind <> 'lose' THEN 1 ELSE 0 END) AS wins,
                         SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending FROM plays WHERE project_id = ?", [$pid]);
    return [
        'project' => adm_project_summary($p) + ['settings' => project_settings($p)],
        'products' => adm_products($pid),
        'segments' => adm_segments($pid),
        'stats' => array_map('intval', $stats ?: []),
    ];
}

function adm_project_get(array $in): array
{
    return adm_project_payload(adm_require_project(in_int($in, 'id')));
}

function clean_domain($v): string
{
    $s = strtolower(clean_str($v, 190));
    $s = preg_replace('#^[a-z]+://#', '', $s);
    $s = preg_replace('#[/?\#].*$#', '', (string)$s);
    $s = preg_replace('/:\d+$/', '', (string)$s);
    return preg_match('/^[a-z0-9.\-]+\.[a-z0-9\-]{2,}$/', (string)$s) ? (string)$s : '';
}

function clean_link($v, int $max = 200): string
{
    $s = clean_str($v, $max);
    if ($s === '') return '';
    if (preg_match('#^(javascript|data|vbscript):#i', $s)) return '';
    return $s;
}

/** Nettoie les réglages d'un projet envoyés par l'interface, champ par champ. */
function sanitize_project_settings($in, array $cur): array
{
    $d = defaults();
    $in = is_array($in) ? $in : [];
    $out = $cur;

    $b = is_array($in['brand'] ?? null) ? $in['brand'] : [];
    if (array_key_exists('logo', $b)) $out['brand']['logo'] = clean_asset($b['logo']);
    if (array_key_exists('tagline', $b)) $out['brand']['tagline'] = clean_str($b['tagline'], 120);

    $t = is_array($in['theme'] ?? null) ? $in['theme'] : [];
    foreach (['c1', 'c2', 'c3', 'bg1', 'bg2', 'text'] as $k) {
        if (array_key_exists($k, $t)) $out['theme'][$k] = clean_color($t[$k], $cur['theme'][$k]);
    }
    if (array_key_exists('preset', $t)) $out['theme']['preset'] = isset($d['presets'][$t['preset']]) ? (string)$t['preset'] : 'custom';
    if (isset($t['bg_mode']) && in_array($t['bg_mode'], ['gradient', 'solid', 'image'], true)) $out['theme']['bg_mode'] = $t['bg_mode'];
    if (array_key_exists('bg_image', $t)) $out['theme']['bg_image'] = clean_asset($t['bg_image']);
    if (array_key_exists('bg_overlay', $t)) $out['theme']['bg_overlay'] = max(0, min(90, (int)$t['bg_overlay']));
    foreach (['rays', 'sparkles', 'lights'] as $k) if (array_key_exists($k, $t)) $out['theme'][$k] = in_bool($t, $k);
    if (isset($t['font_title']) && isset($d['fonts']['title'][$t['font_title']])) $out['theme']['font_title'] = (string)$t['font_title'];
    if (isset($t['font_body']) && isset($d['fonts']['body'][$t['font_body']])) $out['theme']['font_body'] = (string)$t['font_body'];

    $x = is_array($in['texts'] ?? null) ? $in['texts'] : [];
    foreach ($d['project']['texts'] as $k => $def) {
        if (!array_key_exists($k, $x)) continue;
        if ($k === 'steps') {
            $steps = [];
            foreach (array_slice(is_array($x['steps']) ? $x['steps'] : [], 0, 3) as $st) {
                $steps[] = ['t' => clean_str($st['t'] ?? '', 40), 'd' => clean_str($st['d'] ?? '', 200)];
            }
            while (count($steps) < 3) $steps[] = $def[count($steps)];
            $out['texts']['steps'] = $steps;
        } elseif ($k === 'rules') {
            $out['texts'][$k] = clean_str($x[$k], 6000, true);
        } else {
            $out['texts'][$k] = clean_str($x[$k], in_array($k, ['subtitle', 'footer'], true) ? 400 : 200, $k === 'footer');
        }
    }

    $o = is_array($in['options'] ?? null) ? $in['options'] : [];
    foreach ($d['project']['options'] as $k => $def) {
        if (array_key_exists($k, $o)) $out['options'][$k] = in_bool($o, $k);
    }

    $c = is_array($in['contact'] ?? null) ? $in['contact'] : [];
    if (array_key_exists('phone', $c)) $out['contact']['phone'] = clean_phone($c['phone']);
    if (array_key_exists('whatsapp', $c)) $out['contact']['whatsapp'] = clean_phone($c['whatsapp']);
    foreach (['instagram', 'facebook', 'website'] as $k) {
        if (array_key_exists($k, $c)) $out['contact'][$k] = clean_link($c[$k]);
    }
    return $out;
}

function unique_slug(string $slug, int $exceptId = 0): string
{
    $base = $slug;
    for ($i = 2; ; $i++) {
        if (!val('SELECT COUNT(*) FROM projects WHERE slug = ? AND id <> ?', [$slug, $exceptId])) return $slug;
        $slug = substr($base, 0, 36) . '-' . $i;
    }
}

function adm_project_save(array $in): array
{
    $p = adm_require_project(in_int($in, 'id'));
    $pid = (int)$p['id'];
    $name = in_str($in, 'name', 80);
    if ($name === '') fail('field', 'Le nom du projet est obligatoire.', 200, ['field' => 'name']);
    $slugIn = in_str($in, 'slug', 60);
    $slug = slugify($slugIn !== '' ? $slugIn : $name);
    if (val('SELECT COUNT(*) FROM projects WHERE slug = ? AND id <> ?', [$slug, $pid])) {
        fail('field', 'Cette adresse courte est déjà utilisée par un autre projet.', 200, ['field' => 'slug']);
    }
    $domain = array_key_exists('domain', $in) ? clean_domain($in['domain']) : (string)$p['domain'];
    if ($domain !== '' && val('SELECT COUNT(*) FROM projects WHERE domain = ? AND id <> ?', [$domain, $pid])) {
        fail('field', 'Ce nom de domaine est déjà associé à un autre projet.', 200, ['field' => 'domain']);
    }
    $settings = sanitize_project_settings($in['settings'] ?? [], project_settings($p));
    update_row('projects', $pid, [
        'name' => $name, 'slug' => $slug, 'domain' => $domain,
        'active' => array_key_exists('active', $in) ? (in_bool($in, 'active') ? 1 : 0) : (int)$p['active'],
        'settings' => json_enc($settings), 'updated_at' => now(),
    ]);
    if ($slug !== $p['slug'] && setting('home') === $p['slug']) {
        settings_save(deep_merge(settings_all(), ['home' => $slug]));
    }
    return adm_project_payload(project_by_id($pid));
}

function copy_project_content(int $fromId, int $toId): void
{
    $map = [];
    $t = now();
    foreach (rows('SELECT * FROM products WHERE project_id = ? ORDER BY sort, id', [$fromId]) as $pr) {
        $map[(int)$pr['id']] = insert('products', [
            'project_id' => $toId, 'name' => $pr['name'], 'weight' => $pr['weight'], 'value_text' => $pr['value_text'],
            'description' => $pr['description'], 'photo' => $pr['photo'], 'stock' => $pr['stock'], 'active' => (int)$pr['active'],
            'sort' => (int)$pr['sort'], 'created_at' => $t, 'updated_at' => $t,
        ]);
    }
    foreach (rows('SELECT * FROM segments WHERE project_id = ? ORDER BY position, id', [$fromId]) as $s) {
        if ($s['kind'] !== 'lose' && !isset($map[(int)$s['product_id']])) continue;
        insert('segments', [
            'project_id' => $toId, 'position' => (int)$s['position'], 'kind' => $s['kind'],
            'product_id' => $s['kind'] === 'lose' ? null : $map[(int)$s['product_id']],
            'label' => $s['label'], 'sub' => $s['sub'], 'color' => $s['color'], 'chance' => (int)$s['chance'],
        ]);
    }
}

function adm_project_create(array $in): array
{
    $name = in_str($in, 'name', 80);
    if ($name === '') fail('field', 'Donnez un nom au nouveau projet.', 200, ['field' => 'name']);
    $from = in_int($in, 'copy_from');
    $src = $from ? project_by_id($from) : null;
    $pid = tx_write(function () use ($name, $src) {
        $d = defaults();
        $t = now();
        if ($src) {
            $settings = project_settings($src);
        } else {
            $preset = $d['presets']['nuit'];
            $settings = deep_merge($d['project'], ['theme' => [
                'preset' => 'nuit', 'c1' => $preset['c1'], 'c2' => $preset['c2'], 'c3' => $preset['c3'],
                'bg1' => $preset['bg1'], 'bg2' => $preset['bg2'], 'text' => $preset['text'],
            ]]);
        }
        $pid = insert('projects', [
            'slug' => unique_slug(slugify($name)), 'name' => $name, 'domain' => '', 'active' => 1,
            'sort' => (int)val('SELECT COALESCE(MAX(sort), 0) FROM projects') + 1,
            'settings' => json_enc($settings), 'created_at' => $t, 'updated_at' => $t,
        ]);
        if ($src) copy_project_content((int)$src['id'], $pid);
        else seed_project_content($pid);
        return $pid;
    });
    return adm_project_payload(project_by_id($pid)) + ['projects' => array_map('adm_project_summary', projects_list())];
}

function adm_project_delete(array $in): array
{
    $p = adm_require_project(in_int($in, 'id'));
    $pid = (int)$p['id'];
    tx_write(function () use ($pid) {
        // Vérification dans la transaction : aucune partie ne peut s'intercaler.
        if ((int)val('SELECT COUNT(*) FROM codes WHERE project_id = ?' . for_update(), [$pid]) > 0
            || (int)val('SELECT COUNT(*) FROM plays WHERE project_id = ?', [$pid]) > 0) {
            fail('inuse', 'Ce projet a déjà des parties enregistrées : désactivez-le plutôt que de le supprimer, pour garder l’historique.');
        }
        q('DELETE FROM segments WHERE project_id = ?', [$pid]);
        q('DELETE FROM products WHERE project_id = ?', [$pid]);
        q('DELETE FROM projects WHERE id = ?', [$pid]);
    });
    return ['projects' => array_map('adm_project_summary', projects_list())];
}

function adm_project_import(array $in): array
{
    $p = adm_require_project(in_int($in, 'id'));
    $src = adm_require_project(in_int($in, 'from_id'));
    if ((int)$p['id'] === (int)$src['id']) fail('same', 'Choisissez un autre projet comme source.');
    tx_write(function () use ($p, $src) {
        q('DELETE FROM segments WHERE project_id = ?', [(int)$p['id']]);
        q('DELETE FROM products WHERE project_id = ?', [(int)$p['id']]);
        copy_project_content((int)$src['id'], (int)$p['id']);
    });
    return adm_project_payload(project_by_id((int)$p['id']));
}

function adm_projects_reorder(array $in): array
{
    $ids = is_array($in['ids'] ?? null) ? $in['ids'] : [];
    tx_write(function () use ($ids) {
        foreach (array_values($ids) as $i => $id) q('UPDATE projects SET sort = ? WHERE id = ?', [$i, (int)$id]);
    });
    return ['projects' => array_map('adm_project_summary', projects_list())];
}

/* ───────────── Produits & lots ───────────── */

function adm_product_save(array $in): array
{
    $p = adm_require_project(in_int($in, 'project_id'));
    $pid = (int)$p['id'];
    $pr = is_array($in['product'] ?? null) ? $in['product'] : [];
    $id = in_int($pr, 'id');
    $name = in_str($pr, 'name', 80);
    if ($name === '') fail('field', 'Le nom du produit est obligatoire.', 200, ['field' => 'name']);
    $stockRaw = $pr['stock'] ?? null;
    $stock = ($stockRaw === null || $stockRaw === '' || $stockRaw === false) ? null : min(1000000, max(0, (int)$stockRaw));
    $data = [
        'name' => $name, 'weight' => in_str($pr, 'weight', 40), 'value_text' => in_str($pr, 'value', 40),
        'description' => in_str($pr, 'description', 300), 'photo' => clean_asset($pr['photo'] ?? ''),
        'stock' => $stock, 'active' => in_bool($pr, 'active', true) ? 1 : 0, 'updated_at' => now(),
    ];
    if ($id) {
        if (!val('SELECT COUNT(*) FROM products WHERE id = ? AND project_id = ?', [$id, $pid])) fail('notfound', 'Produit introuvable.');
        update_row('products', $id, $data);
    } else {
        $data['project_id'] = $pid;
        $data['sort'] = (int)val('SELECT COALESCE(MAX(sort), -1) FROM products WHERE project_id = ?', [$pid]) + 1;
        $data['created_at'] = now();
        $id = insert('products', $data);
    }
    return ['product' => adm_product_row(row('SELECT * FROM products WHERE id = ?', [$id])), 'products' => adm_products($pid), 'segments' => adm_segments($pid)];
}

function adm_product_delete(array $in): array
{
    $p = adm_require_project(in_int($in, 'project_id'));
    $pid = (int)$p['id'];
    $id = in_int($in, 'id');
    tx_write(function () use ($id, $pid) {
        q('DELETE FROM segments WHERE product_id = ? AND project_id = ?', [$id, $pid]);
        q('DELETE FROM products WHERE id = ? AND project_id = ?', [$id, $pid]);
    });
    return ['products' => adm_products($pid), 'segments' => adm_segments($pid)];
}

function adm_product_reorder(array $in): array
{
    $p = adm_require_project(in_int($in, 'project_id'));
    $pid = (int)$p['id'];
    $ids = is_array($in['ids'] ?? null) ? $in['ids'] : [];
    tx_write(function () use ($ids, $pid) {
        foreach (array_values($ids) as $i => $id) q('UPDATE products SET sort = ? WHERE id = ? AND project_id = ?', [$i, (int)$id, $pid]);
    });
    return ['products' => adm_products($pid)];
}

/* ───────────── Roue ───────────── */

function adm_wheel_save(array $in): array
{
    $p = adm_require_project(in_int($in, 'project_id'));
    $pid = (int)$p['id'];
    $segs = is_array($in['segments'] ?? null) ? array_values($in['segments']) : [];
    if (count($segs) < 2) fail('wheel', 'La roue doit compter au moins 2 cases.');
    if (count($segs) > 24) fail('wheel', 'La roue peut compter 24 cases au maximum.');
    $prodIds = array_map('intval', array_column(rows('SELECT id FROM products WHERE project_id = ?', [$pid]), 'id'));
    $clean = [];
    foreach ($segs as $i => $s) {
        $kind = in_array($s['kind'] ?? '', ['product', 'jackpot', 'lose'], true) ? $s['kind'] : 'lose';
        $prodId = null;
        if ($kind !== 'lose') {
            $prodId = (int)($s['product_id'] ?? 0);
            if (!in_array($prodId, $prodIds, true)) fail('wheel', 'Case ' . ($i + 1) . ' : choisissez le produit à gagner.', 200, ['index' => $i]);
        }
        $clean[] = [
            'id' => (int)($s['id'] ?? 0), 'kind' => $kind, 'product_id' => $prodId,
            'label' => clean_str($s['label'] ?? '', 40), 'sub' => clean_str($s['sub'] ?? '', 40),
            'color' => ($s['color'] ?? '') === '' ? '' : clean_color($s['color'], ''),
            'chance' => max(0, min(100000, (int)($s['chance'] ?? 0))),
        ];
    }
    tx_write(function () use ($clean, $pid) {
        $existing = array_map('intval', array_column(rows('SELECT id FROM segments WHERE project_id = ?', [$pid]), 'id'));
        $keep = [];
        foreach ($clean as $pos => $s) {
            $data = ['position' => $pos, 'kind' => $s['kind'], 'product_id' => $s['product_id'], 'label' => $s['label'],
                'sub' => $s['sub'], 'color' => $s['color'], 'chance' => $s['chance']];
            if ($s['id'] && in_array($s['id'], $existing, true)) {
                update_row('segments', $s['id'], $data);
                $keep[] = $s['id'];
            } else {
                $keep[] = insert('segments', $data + ['project_id' => $pid]);
            }
        }
        foreach (array_diff($existing, $keep) as $gone) q('DELETE FROM segments WHERE id = ?', [$gone]);
    });
    return ['segments' => adm_segments($pid)];
}

/* ───────────── Images ───────────── */

function adm_upload(): array
{
    $kind = (string)($_POST['kind'] ?? 'product');
    if (!in_array($kind, ['logo', 'product', 'background', 'portal'], true)) $kind = 'product';
    $path = handle_upload($_FILES['file'] ?? null, $kind);
    return ['path' => $path, 'url' => asset_abs($path)];
}

/* ───────────── Codes (centralisés pour tous les projets) ───────────── */

function adm_codes_summary(): array
{
    $tot = row("SELECT COUNT(*) AS total,
        SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) AS fresh,
        SUM(CASE WHEN status = 'used' THEN 1 ELSE 0 END) AS used,
        SUM(CASE WHEN status = 'void' THEN 1 ELSE 0 END) AS void FROM codes");
    $byProject = [];
    foreach (rows("SELECT project_id, COUNT(*) AS n FROM codes WHERE status = 'used' GROUP BY project_id") as $r) {
        $byProject[(string)(int)$r['project_id']] = (int)$r['n'];
    }
    $counts = [];
    foreach (rows('SELECT batch_id, status, COUNT(*) AS n FROM codes GROUP BY batch_id, status') as $r) {
        $counts[(int)$r['batch_id']][$r['status']] = (int)$r['n'];
    }
    $batches = [];
    foreach (rows('SELECT * FROM batches ORDER BY id DESC') as $b) {
        $c = $counts[(int)$b['id']] ?? [];
        $batches[] = [
            'id' => (int)$b['id'], 'label' => (string)$b['label'], 'created_at' => iso($b['created_at']),
            'total' => array_sum($c), 'fresh' => $c['new'] ?? 0, 'used' => $c['used'] ?? 0, 'void' => $c['void'] ?? 0,
        ];
    }
    return ['totals' => array_map('intval', $tot ?: []), 'by_project' => $byProject, 'batches' => $batches];
}

function adm_codes_generate(array $in): array
{
    $qty = in_int($in, 'quantity');
    if ($qty < 1 || $qty > 5000) fail('field', 'Indiquez une quantité entre 1 et 5 000 codes.', 200, ['field' => 'quantity']);
    $label = in_str($in, 'label', 80);
    if ($label === '') $label = 'Lot du ' . fmt_local(now(), 'd/m/Y');
    $t = now();
    $bid = tx_write(function () use ($label, $qty, $t) {
        return insert('batches', ['label' => $label, 'quantity' => $qty, 'created_at' => $t]);
    });
    for ($done = 0; $done < $qty;) {
        $chunk = min(500, $qty - $done);
        tx_write(function () use ($bid, $t, $chunk) {
            for ($i = 0; $i < $chunk; $i++) insert_unique_code($bid, $t);
        });
        $done += $chunk;
    }
    return ['batch_id' => $bid] + adm_codes_summary();
}

function adm_codes_list(array $in): array
{
    $where = [];
    $params = [];
    $bid = in_int($in, 'batch_id');
    if ($bid) { $where[] = 'c.batch_id = ?'; $params[] = $bid; }
    $status = (string)($in['status'] ?? '');
    if (in_array($status, ['new', 'used', 'void'], true)) { $where[] = 'c.status = ?'; $params[] = $status; }
    $pid = in_int($in, 'project_id');
    if ($pid) { $where[] = 'c.project_id = ?'; $params[] = $pid; }
    $qs = strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', in_str($in, 'q', 20)));
    if ($qs !== '') { $where[] = 'c.code LIKE ?'; $params[] = $qs . '%'; }
    $w = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $total = (int)val('SELECT COUNT(*) FROM codes c' . $w, $params);
    $per = 50;
    $page = max(1, in_int($in, 'page', 1));
    $items = [];
    $projects = [];
    foreach (projects_list() as $p) $projects[(int)$p['id']] = (string)$p['name'];
    foreach (rows('SELECT c.*, b.label AS batch_label, pl.kind AS play_kind, pl.prize_name, pl.status AS play_status
                   FROM codes c LEFT JOIN batches b ON b.id = c.batch_id LEFT JOIN plays pl ON pl.code_id = c.id' . $w .
        ' ORDER BY c.id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per), $params) as $r) {
        $items[] = [
            'code' => code_format((string)$r['code']), 'status' => (string)$r['status'], 'batch' => (string)($r['batch_label'] ?? ''),
            'project' => $r['project_id'] ? ($projects[(int)$r['project_id']] ?? '') : '', 'used_at' => iso($r['used_at']),
            'kind' => (string)($r['play_kind'] ?? ''), 'prize' => (string)($r['prize_name'] ?? ''), 'play_status' => (string)($r['play_status'] ?? ''),
        ];
    }
    return ['items' => $items, 'total' => $total, 'page' => $page, 'pages' => max(1, (int)ceil($total / $per))];
}

function adm_codes_set(array $in, string $from, string $to): array
{
    $code = code_normalize($in['code'] ?? '');
    if (!$code) fail('format', 'Code mal saisi.');
    $n = q('UPDATE codes SET status = ? WHERE code = ? AND status = ?', [$to, $code, $from])->rowCount();
    if ($n !== 1) {
        fail('state', $to === 'void' ? 'Seul un ticket encore jamais joué peut être désactivé.' : 'Ce ticket n’est pas désactivé.');
    }
    return code_lookup($code);
}

function adm_batch_void(array $in): array
{
    $bid = in_int($in, 'id');
    $n = q("UPDATE codes SET status = 'void' WHERE batch_id = ? AND status = 'new'", [$bid])->rowCount();
    return ['voided' => $n] + adm_codes_summary();
}

function adm_batch_delete(array $in): array
{
    $bid = in_int($in, 'id');
    tx_write(function () use ($bid) {
        // Vérification dans la transaction : aucune partie ne peut s'intercaler.
        if ((int)val("SELECT COUNT(*) FROM codes WHERE batch_id = ? AND status = 'used'" . for_update(), [$bid]) > 0) {
            fail('inuse', 'Des tickets de ce lot ont déjà été joués : désactivez plutôt les codes restants.');
        }
        q('DELETE FROM codes WHERE batch_id = ?', [$bid]);
        q('DELETE FROM batches WHERE id = ?', [$bid]);
    });
    return adm_codes_summary();
}

function adm_batch_rename(array $in): array
{
    $label = in_str($in, 'label', 80);
    if ($label === '') fail('field', 'Le nom du lot ne peut pas être vide.');
    q('UPDATE batches SET label = ? WHERE id = ?', [$label, in_int($in, 'id')]);
    return adm_codes_summary();
}

function adm_batch_codes(array $in): array
{
    $bid = in_int($in, 'id');
    $b = row('SELECT * FROM batches WHERE id = ?', [$bid]);
    if (!$b) fail('notfound', 'Lot introuvable.');
    $onlyNew = in_bool($in, 'only_new', true);
    $codes = [];
    foreach (rows('SELECT code, status FROM codes WHERE batch_id = ?' . ($onlyNew ? " AND status = 'new'" : '') . ' ORDER BY id', [$bid]) as $r) {
        $codes[] = ['code' => (string)$r['code'], 'status' => (string)$r['status']];
    }
    $projects = [];
    foreach (projects_list() as $p) $projects[] = adm_project_summary($p);
    return [
        'batch' => ['id' => (int)$b['id'], 'label' => (string)$b['label'], 'created_at' => iso($b['created_at'])],
        'codes' => $codes, 'projects' => $projects, 'settings' => settings_all(), 'public_base' => public_base_url(),
    ];
}

/* ───────────── Parties & gains ───────────── */

function plays_filter(array $in): array
{
    $where = [];
    $params = [];
    $pid = in_int($in, 'project_id');
    if ($pid) { $where[] = 'pl.project_id = ?'; $params[] = $pid; }
    $kind = (string)($in['kind'] ?? '');
    if ($kind === 'win') $where[] = "pl.kind <> 'lose'";
    elseif (in_array($kind, ['product', 'jackpot', 'lose'], true)) { $where[] = 'pl.kind = ?'; $params[] = $kind; }
    $status = (string)($in['status'] ?? '');
    if (in_array($status, ['pending', 'delivered', 'cancelled', 'none'], true)) { $where[] = 'pl.status = ?'; $params[] = $status; }
    $period = (string)($in['period'] ?? '');
    if ($period === 'today') { $where[] = 'pl.created_at >= ?'; $params[] = local_day_start_utc(0); }
    elseif ($period === '7d') { $where[] = 'pl.created_at >= ?'; $params[] = local_day_start_utc(6); }
    elseif ($period === '30d') { $where[] = 'pl.created_at >= ?'; $params[] = local_day_start_utc(29); }
    $qs = in_str($in, 'q', 60);
    if ($qs !== '') {
        $codeQ = strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', $qs));
        $where[] = '(c.code LIKE ? OR pl.customer_name LIKE ? OR pl.customer_phone LIKE ? OR pl.prize_name LIKE ?)';
        array_push($params, ($codeQ !== '' ? $codeQ : '#') . '%', '%' . $qs . '%', '%' . $qs . '%', '%' . $qs . '%');
    }
    return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $params];
}

function adm_plays_list(array $in): array
{
    [$w, $params] = plays_filter($in);
    $total = (int)val('SELECT COUNT(*) FROM plays pl JOIN codes c ON c.id = pl.code_id' . $w, $params);
    $per = 50;
    $page = max(1, in_int($in, 'page', 1));
    $projects = [];
    foreach (projects_list() as $p) $projects[(int)$p['id']] = $p;
    $items = [];
    foreach (rows('SELECT pl.*, c.code FROM plays pl JOIN codes c ON c.id = pl.code_id' . $w .
        ' ORDER BY pl.id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per), $params) as $pl) {
        $items[] = result_payload($pl, $projects[(int)$pl['project_id']] ?? null, true);
    }
    return ['items' => $items, 'total' => $total, 'page' => $page, 'pages' => max(1, (int)ceil($total / $per))];
}

function adm_plays_status(array $in): array
{
    $id = in_int($in, 'id');
    $status = (string)($in['status'] ?? '');
    $play = play_by_id($id);
    if (!$play) fail('notfound', 'Partie introuvable.');
    if ($play['kind'] === 'lose') fail('state', 'Aucun lot n’est associé à cette partie.');
    if ($status === 'delivered') {
        return ['result' => deliver_play($id, ['id' => null, 'name' => 'Administrateur'])];
    }
    if ($status === 'pending') {
        if (!in_array($play['status'], ['delivered', 'cancelled'], true)) fail('state', 'Ce lot est déjà à remettre.');
        q("UPDATE plays SET status = 'pending', delivered_at = NULL, delivered_by = NULL, delivered_by_name = '' WHERE id = ? AND status = ?", [$id, $play['status']]);
    } elseif ($status === 'cancelled') {
        if ($play['status'] !== 'pending') fail('state', $play['status'] === 'delivered' ? 'Ce lot a déjà été remis : il ne peut plus être annulé.' : 'Ce gain est déjà annulé.');
        q("UPDATE plays SET status = 'cancelled' WHERE id = ? AND status = 'pending'", [$id]);
    } else {
        fail('state', 'Statut inconnu.');
    }
    $play = play_by_id($id);
    return ['result' => result_payload($play, project_by_id((int)$play['project_id']), true)];
}

/* ───────────── Livreurs ───────────── */

function adm_livreurs_list(): array
{
    $out = [];
    foreach (rows('SELECT l.*, (SELECT COUNT(*) FROM plays pl WHERE pl.delivered_by = l.id) AS deliveries FROM livreurs l ORDER BY l.name') as $l) {
        $out[] = ['id' => (int)$l['id'], 'name' => (string)$l['name'], 'active' => (bool)(int)$l['active'],
            'created_at' => iso($l['created_at']), 'last_seen_at' => iso($l['last_seen_at']), 'deliveries' => (int)$l['deliveries']];
    }
    return ['livreurs' => $out, 'url' => public_base_url() . 'livreur/'];
}

function adm_livreurs_save(array $in): array
{
    $id = in_int($in, 'id');
    $name = in_str($in, 'name', 60);
    if ($name === '') fail('field', 'Indiquez le nom du livreur.', 200, ['field' => 'name']);
    $pin = (string)preg_replace('/\D/', '', (string)($in['pin'] ?? ''));
    $active = in_bool($in, 'active', true) ? 1 : 0;
    if (!$id || $pin !== '') {
        if (strlen($pin) < 6 || strlen($pin) > 8) fail('field', 'Le code du livreur doit compter de 6 à 8 chiffres.', 200, ['field' => 'pin']);
        if (val('SELECT COUNT(*) FROM livreurs WHERE pin_hash = ? AND id <> ?', [pin_hash($pin), $id])) {
            fail('field', 'Ce code est déjà attribué à un autre livreur. Choisissez-en un autre.', 200, ['field' => 'pin']);
        }
    }
    if ($id) {
        $data = ['name' => $name, 'active' => $active];
        if ($pin !== '') $data['pin_hash'] = pin_hash($pin);
        update_row('livreurs', $id, $data);
        if (!$active || $pin !== '') q("DELETE FROM tokens WHERE role = 'livreur' AND livreur_id = ?", [$id]);
    } else {
        insert('livreurs', ['name' => $name, 'pin_hash' => pin_hash($pin), 'active' => $active, 'created_at' => now(), 'last_seen_at' => null]);
    }
    return adm_livreurs_list();
}

function adm_livreurs_delete(array $in): array
{
    $id = in_int($in, 'id');
    q("DELETE FROM tokens WHERE role = 'livreur' AND livreur_id = ?", [$id]);
    q('DELETE FROM livreurs WHERE id = ?', [$id]);
    return adm_livreurs_list();
}

/* ───────────── Réglages généraux ───────────── */

function adm_settings_save(array $in): array
{
    $cur = settings_all();
    $s = is_array($in['settings'] ?? null) ? $in['settings'] : [];
    $out = $cur;
    if (array_key_exists('base_url', $s)) {
        $u = clean_str($s['base_url'], 200);
        if ($u !== '' && !preg_match('#^https?://[a-z0-9.\-]+(:\d+)?(/[^\s?\#]*)?$#i', $u)) {
            fail('field', 'Adresse invalide. Exemple : https://jeu.mondomaine.com/', 200, ['field' => 'base_url']);
        }
        $out['base_url'] = $u === '' ? '' : rtrim($u, '/') . '/';
    }
    if (array_key_exists('home', $s)) {
        $h = (string)$s['home'];
        $out['home'] = ($h === 'portal' || project_by_slug($h)) ? $h : 'portal';
    }
    if (array_key_exists('timezone', $s)) $out['timezone'] = valid_tz((string)$s['timezone']);
    $p = is_array($s['portal'] ?? null) ? $s['portal'] : [];
    if (array_key_exists('title', $p)) $out['portal']['title'] = clean_str($p['title'], 80);
    if (array_key_exists('text', $p)) $out['portal']['text'] = clean_str($p['text'], 300);
    if (array_key_exists('logo', $p)) $out['portal']['logo'] = clean_asset($p['logo']);
    $tk = is_array($s['ticket'] ?? null) ? $s['ticket'] : [];
    if (array_key_exists('title', $tk)) $out['ticket']['title'] = clean_str($tk['title'], 60);
    if (array_key_exists('subtitle', $tk)) $out['ticket']['subtitle'] = clean_str($tk['subtitle'], 120);
    if (array_key_exists('footer', $tk)) $out['ticket']['footer'] = clean_str($tk['footer'], 120);
    if (isset($tk['style']) && in_array($tk['style'], ['neon', 'light'], true)) $out['ticket']['style'] = $tk['style'];
    if (isset($tk['layout']) && in_array((string)$tk['layout'], ['8', '10', '12', '24'], true)) $out['ticket']['layout'] = (string)$tk['layout'];
    if (isset($tk['link'])) $out['ticket']['link'] = ($tk['link'] === 'portal' || project_by_slug((string)$tk['link'])) ? (string)$tk['link'] : 'portal';
    if (isset($tk['logo'])) $out['ticket']['logo'] = (in_array($tk['logo'], ['portal', 'none'], true) || project_by_slug((string)$tk['logo'])) ? (string)$tk['logo'] : 'portal';
    $saved = settings_save($out);
    return ['settings' => $saved, 'public_base' => public_base_url()];
}

/* ───────────── Diagnostic ───────────── */

function adm_diagnostic(): array
{
    $checks = [];
    $checks[] = ['label' => 'Version de PHP', 'ok' => version_compare(PHP_VERSION, '8.0.0', '>='), 'detail' => PHP_VERSION];
    if (is_mysql()) {
        $checks[] = ['label' => 'Base de données', 'ok' => true, 'detail' => 'MySQL ' . db()->getAttribute(PDO::ATTR_SERVER_VERSION)];
    } else {
        $path = (string)cfg('db.sqlite_path');
        $mode = (string)db()->query('PRAGMA journal_mode')->fetchColumn();
        $checks[] = ['label' => 'Base de données', 'ok' => is_writable($path), 'detail' => 'SQLite ' . db()->getAttribute(PDO::ATTR_SERVER_VERSION) . ', ' .
            round(filesize($path) / 1024) . ' Ko, journal ' . strtoupper($mode)];
    }
    // Test d'unicité réel : un code de test ne peut être consommé qu'une fois (puis tout est annulé).
    $uniq = ['label' => 'Ticket à usage unique (test réel sur la base)', 'ok' => false, 'detail' => ''];
    try {
        tx_write(function () use (&$uniq) {
            $code = 'TEST' . strtoupper(bin2hex(random_bytes(3)));
            $cid = insert('codes', ['code' => $code, 'batch_id' => null, 'status' => 'new', 'project_id' => null, 'used_at' => null, 'created_at' => now()]);
            $first = q("UPDATE codes SET status = 'used' WHERE id = ? AND status = 'new'", [$cid])->rowCount();
            $second = q("UPDATE codes SET status = 'used' WHERE id = ? AND status = 'new'", [$cid])->rowCount();
            $base = ['code_id' => $cid, 'project_id' => 0, 'segment_id' => null, 'kind' => 'lose', 'product_id' => null, 'prize_name' => '',
                'prize_weight' => '', 'prize_photo' => '', 'prize_value' => '', 'status' => 'none', 'customer_name' => '', 'customer_phone' => '',
                'customer_note' => '', 'claimed_at' => null, 'delivered_at' => null, 'delivered_by' => null, 'delivered_by_name' => '',
                'ip' => '', 'created_at' => now()];
            insert('plays', $base);
            $dupBlocked = false;
            try { insert('plays', $base); } catch (PDOException $e) { $dupBlocked = true; }
            $uniq['ok'] = $first === 1 && $second === 0 && $dupBlocked;
            $uniq['detail'] = $uniq['ok'] ? '1er passage accepté, 2e refusé, doublon bloqué par la base.' : 'Comportement inattendu de la base de données.';
            throw new NoOutcome(); // annule le test
        });
    } catch (NoOutcome $e) {
    } catch (Throwable $e) {
        $uniq['detail'] = $e->getMessage();
    }
    $checks[] = $uniq;
    $up = RDC_ROOT . '/uploads';
    $checks[] = ['label' => 'Envoi d’images (dossier uploads/)', 'ok' => is_dir($up) && is_writable($up), 'detail' => is_writable($up) ? 'Accessible en écriture' : 'Donnez les droits d’écriture (755) au dossier uploads/'];
    $checks[] = ['label' => 'Redimensionnement des photos (GD)', 'ok' => function_exists('imagecreatetruecolor'), 'detail' => function_exists('imagecreatetruecolor') ? 'Disponible' : 'Absent : les photos seront gardées telles quelles'];
    $persist = storage_persistent();
    if ($persist !== null) {
        $checks[] = ['label' => 'Stockage permanent des tickets', 'ok' => $persist,
            'detail' => $persist ? 'Disque permanent monté sur ' . getenv('RDC_DATA_DIR') . ' : tickets, parties et photos sont conservés.'
                : 'Aucun disque permanent sur ' . getenv('RDC_DATA_DIR') . ' : tout serait effacé au prochain redémarrage. Sur Render, ajoutez un disque (Disks) monté sur ce chemin.'];
    }
    $hdrName = (string)cfg('client_ip_header', '');
    if ($hdrName !== '') {
        $seen = !empty($_SERVER[$hdrName]) && is_string($_SERVER[$hdrName]);
        $checks[] = ['label' => 'Adresse des visiteurs', 'ok' => $seen,
            'detail' => $seen ? 'Lue dans l’en-tête ' . $hdrName . ' : ' . client_ip()
                : 'L’en-tête ' . $hdrName . ' n’est pas reçu : les limites d’essais visent l’adresse du serveur intermédiaire (' . client_ip() . ').'];
    }
    if ((string)cfg('client_ip_header', '') === '' && (!empty($_SERVER['HTTP_CF_CONNECTING_IP']) || !empty($_SERVER['HTTP_X_FORWARDED_FOR']))) {
        $checks[] = ['label' => 'Adresse des visiteurs', 'ok' => false,
            'detail' => !empty($_SERVER['HTTP_CF_CONNECTING_IP'])
                ? 'Votre site passe par Cloudflare : indiquez \'client_ip_header\' => \'HTTP_CF_CONNECTING_IP\' dans config.php, sinon un seul visiteur malveillant pourrait bloquer les autres.'
                : 'Votre site semble derrière un proxy : renseignez client_ip_header dans config.php (voir le guide), sinon un seul visiteur malveillant pourrait bloquer les autres.'];
    }
    $checks[] = ['label' => 'Connexion sécurisée (HTTPS)', 'ok' => is_https(), 'detail' => is_https() ? 'Active' : 'Nécessaire pour scanner avec la caméra depuis le site. Activez le certificat SSL de votre hébergement.'];
    $checks[] = ['label' => 'Taille maximale des envois', 'ok' => true, 'detail' => ini_get('upload_max_filesize')];
    $db = is_mysql() ? '' : 'data/' . basename((string)cfg('db.sqlite_path'));
    return ['checks' => $checks, 'protected' => array_values(array_filter([$db, 'app/defaults.json']))];
}

/* ───────────── Exports & sauvegarde ───────────── */

function csv_start(string $filename): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    echo "\xEF\xBB\xBF";
}

function csv_line(array $cols): void
{
    $out = [];
    foreach ($cols as $c) {
        $c = (string)$c;
        if ($c !== '' && strpos('=+-@', $c[0]) !== false) $c = "'" . $c; // neutralise les formules
        $out[] = '"' . str_replace('"', '""', $c) . '"';
    }
    echo implode(';', $out) . "\r\n";
}

function code_status_label(string $s): string
{
    return ['new' => 'Disponible', 'used' => 'Joué', 'void' => 'Désactivé'][$s] ?? $s;
}

function play_status_label(string $s): string
{
    return ['pending' => 'À remettre', 'delivered' => 'Remis', 'cancelled' => 'Annulé', 'none' => '—'][$s] ?? $s;
}

function kind_label(string $k): string
{
    return ['product' => 'Gagné', 'jackpot' => 'Grand prix', 'lose' => 'Perdu'][$k] ?? $k;
}

function adm_export_codes(): void
{
    $bid = (int)($_GET['batch'] ?? 0);
    $link = (string)($_GET['link'] ?? setting('ticket.link', 'portal'));
    $proj = $link !== 'portal' ? project_by_slug($link) : null;
    $projects = [];
    foreach (projects_list() as $p) $projects[(int)$p['id']] = (string)$p['name'];
    csv_start('codes' . ($bid ? '-lot-' . $bid : '') . '-' . gmdate('Ymd') . '.csv');
    csv_line(['Code', 'Lien du QR code', 'Lot', 'Statut', 'Projet', 'Joué le']);
    $sql = 'SELECT c.*, b.label AS batch_label FROM codes c LEFT JOIN batches b ON b.id = c.batch_id' . ($bid ? ' WHERE c.batch_id = ?' : '') . ' ORDER BY c.id';
    foreach (rows($sql, $bid ? [$bid] : []) as $r) {
        csv_line([code_format($r['code']), qr_link((string)$r['code'], $proj), $r['batch_label'], code_status_label($r['status']),
            $r['project_id'] ? ($projects[(int)$r['project_id']] ?? '') : '', fmt_local($r['used_at'])]);
    }
    exit;
}

function qr_link(string $code, ?array $project): string
{
    if ($project && trim((string)$project['domain']) !== '') return 'https://' . trim((string)$project['domain']) . '/?c=' . $code;
    if ($project) return public_base_url() . '?p=' . rawurlencode((string)$project['slug']) . '&c=' . $code;
    return public_base_url() . '?c=' . $code;
}

function adm_export_plays(): void
{
    [$w, $params] = plays_filter($_GET);
    $projects = [];
    foreach (projects_list() as $p) $projects[(int)$p['id']] = (string)$p['name'];
    csv_start('gains-' . gmdate('Ymd') . '.csv');
    csv_line(['Date', 'Projet', 'Code', 'Résultat', 'Lot', 'Grammage', 'Statut', 'Nom', 'Téléphone', 'Adresse / précisions', 'Remis le', 'Remis par']);
    foreach (rows('SELECT pl.*, c.code FROM plays pl JOIN codes c ON c.id = pl.code_id' . $w . ' ORDER BY pl.id DESC', $params) as $r) {
        csv_line([fmt_local($r['created_at']), $projects[(int)$r['project_id']] ?? '', code_format($r['code']), kind_label($r['kind']),
            $r['prize_name'], $r['prize_weight'], play_status_label($r['status']), $r['customer_name'], $r['customer_phone'],
            $r['customer_note'], fmt_local($r['delivered_at']), $r['delivered_by_name']]);
    }
    exit;
}

function adm_backup(): void
{
    if (is_mysql()) {
        header('Content-Type: text/plain; charset=utf-8');
        echo "Avec MySQL, utilisez l’outil de sauvegarde de votre hébergeur (phpMyAdmin > Exporter).";
        exit;
    }
    // Copie temporaire dans data/ (protégé), lisible par vous seul, effacée même si le téléchargement est interrompu.
    @ignore_user_abort(true);
    $tmp = RDC_ROOT . '/data/sauvegarde-' . bin2hex(random_bytes(8)) . '.tmp';
    register_shutdown_function(function () use ($tmp) { if (is_file($tmp)) @unlink($tmp); });
    $old = umask(0077);
    $ok = false;
    try {
        db()->exec('VACUUM INTO ' . db()->quote($tmp));
        $ok = is_file($tmp);
    } catch (Throwable $e) {
        try { db()->exec('PRAGMA wal_checkpoint(FULL)'); } catch (Throwable $e2) {}
    }
    umask($old);
    if ($ok) @chmod($tmp, 0600);
    $src = $ok ? $tmp : (string)cfg('db.sqlite_path');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="sauvegarde-roue-' . gmdate('Ymd-His') . '.sqlite"');
    header('Content-Length: ' . filesize($src));
    header('Cache-Control: no-store');
    readfile($src);
    if ($ok) @unlink($tmp);
    exit;
}
