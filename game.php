<?php
/*
 * Roue de la Chance — logique du jeu.
 * Règle d'or : un ticket = une seule partie, tous projets confondus.
 * Garanties : 1) passage du code de « new » à « used » par une mise à jour conditionnelle dans une
 * transaction exclusive ; 2) contrainte d'unicité sur plays.code_id au niveau de la base.
 */
defined('RDC') || exit;

class CodeRace extends Exception {}
class NoOutcome extends Exception {}

/* ───────────── Projets ───────────── */

function project_by_slug(string $slug): ?array
{
    return row('SELECT * FROM projects WHERE slug = ?', [$slug]);
}

function project_by_id(int $id): ?array
{
    return row('SELECT * FROM projects WHERE id = ?', [$id]);
}

function project_by_domain(string $host): ?array
{
    $h = preg_replace('/^www\./', '', strtolower($host));
    if ($h === '') return null;
    foreach (rows("SELECT * FROM projects WHERE domain <> ''") as $p) {
        $d = preg_replace('/^www\./', '', strtolower(trim((string)$p['domain'])));
        if ($d !== '' && $d === $h) return $p;
    }
    return null;
}

function projects_list(bool $activeOnly = false): array
{
    return rows('SELECT * FROM projects' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY sort, id');
}

function project_settings(array $p): array
{
    $s = json_decode((string)($p['settings'] ?? ''), true);
    return deep_merge(defaults()['project'], is_array($s) ? $s : []);
}

function asset_abs($p): string
{
    $p = (string)$p;
    if ($p === '') return '';
    if (preg_match('#^(https?:|data:|/)#i', $p)) return $p;
    return base_path() . $p;
}

function project_public(array $p): array
{
    $s = project_settings($p);
    return [
        'id' => (int)$p['id'], 'slug' => (string)$p['slug'], 'name' => (string)$p['name'],
        'logo' => asset_abs($s['brand']['logo']), 'tagline' => (string)$s['brand']['tagline'],
        'c1' => $s['theme']['c1'], 'c2' => $s['theme']['c2'], 'c3' => $s['theme']['c3'], 'bg1' => $s['theme']['bg1'], 'bg2' => $s['theme']['bg2'],
    ];
}

/** Projet jouable (actif) ou erreur explicite. */
function game_project(string $slug): array
{
    $p = $slug !== '' ? project_by_slug($slug) : null;
    if (!$p) fail('closed', 'Ce jeu est introuvable.');
    if (!(int)$p['active']) fail('closed', 'Ce jeu est fermé pour le moment. Revenez bientôt !');
    return $p;
}

/* ───────────── Roue & lots (données publiques) ───────────── */

function product_available(array $p): bool
{
    return (int)$p['active'] === 1 && ($p['stock'] === null || (int)$p['stock'] > 0);
}

function public_segments(int $pid): array
{
    $rows = rows('SELECT s.*, p.name AS p_name, p.weight AS p_weight, p.photo AS p_photo, p.value_text AS p_value,
                         p.active AS p_active, p.stock AS p_stock
                  FROM segments s LEFT JOIN products p ON p.id = s.product_id
                  WHERE s.project_id = ? ORDER BY s.position, s.id', [$pid]);
    $out = [];
    foreach ($rows as $r) {
        $seg = [
            'id' => (int)$r['id'], 'kind' => (string)$r['kind'], 'label' => (string)$r['label'], 'sub' => (string)$r['sub'],
            'color' => (string)$r['color'], 'product' => null, 'available' => true,
        ];
        if ($r['kind'] !== 'lose') {
            if ($r['p_name'] === null) continue;
            $seg['product'] = [
                'name' => (string)$r['p_name'], 'weight' => (string)$r['p_weight'],
                'photo' => asset_abs($r['p_photo']), 'value' => (string)$r['p_value'],
            ];
            $seg['available'] = product_available(['active' => $r['p_active'], 'stock' => $r['p_stock']]);
        }
        $out[] = $seg;
    }
    return $out;
}

function public_prizes(int $pid): array
{
    $rows = rows("SELECT s.kind, p.* FROM segments s JOIN products p ON p.id = s.product_id
                  WHERE s.project_id = ? AND s.kind <> 'lose' AND p.active = 1 ORDER BY p.sort, p.id", [$pid]);
    $map = [];
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        if (!isset($map[$id])) {
            $map[$id] = [
                'name' => (string)$r['name'], 'weight' => (string)$r['weight'], 'photo' => asset_abs($r['photo']),
                'value' => (string)$r['value_text'], 'description' => (string)$r['description'],
                'jackpot' => false, 'available' => product_available($r), 'sort' => (int)$r['sort'],
            ];
        }
        if ($r['kind'] === 'jackpot') $map[$id]['jackpot'] = true;
    }
    $list = array_values($map);
    usort($list, function ($a, $b) {
        if ($a['jackpot'] !== $b['jackpot']) return $a['jackpot'] ? -1 : 1;
        return $a['sort'] <=> $b['sort'];
    });
    foreach ($list as &$it) unset($it['sort']);
    return $list;
}

function public_boot(array $project, string $code = ''): array
{
    $s = project_settings($project);
    $theme = $s['theme'];
    $theme['bg_image'] = asset_abs($theme['bg_image']);
    return [
        'mode' => 'game',
        'base' => base_path(),
        'api' => base_path() . 'api/',
        'project' => [
            'slug' => (string)$project['slug'], 'name' => (string)$project['name'],
            'logo' => asset_abs($s['brand']['logo']), 'tagline' => (string)$s['brand']['tagline'],
        ],
        'theme' => $theme,
        'texts' => $s['texts'],
        'options' => $s['options'],
        'contact' => $s['contact'],
        'segments' => public_segments((int)$project['id']),
        'prizes' => public_prizes((int)$project['id']),
        'code' => $code,
        'fonts' => defaults()['fonts'],
        'qr_base' => public_base_url(),
    ];
}

/* ───────────── Résultats ───────────── */

function play_by_code_id(int $codeId): ?array
{
    return row('SELECT pl.*, c.code FROM plays pl JOIN codes c ON c.id = pl.code_id WHERE pl.code_id = ?', [$codeId]);
}

function play_by_id(int $id): ?array
{
    return row('SELECT pl.*, c.code FROM plays pl JOIN codes c ON c.id = pl.code_id WHERE pl.id = ?', [$id]);
}

function result_payload(array $play, ?array $project, bool $private = false): array
{
    $out = [
        'code' => code_format((string)$play['code']),
        'kind' => (string)$play['kind'],
        'segment_id' => $play['segment_id'] !== null ? (int)$play['segment_id'] : null,
        'prize' => $play['kind'] === 'lose' ? null : [
            'name' => (string)$play['prize_name'], 'weight' => (string)$play['prize_weight'],
            'photo' => asset_abs($play['prize_photo']), 'value' => (string)$play['prize_value'],
        ],
        'status' => (string)$play['status'],
        'played_at' => iso($play['created_at']),
        'project' => $project ? project_public($project) : null,
        'claimed' => $play['customer_name'] !== '' || $play['customer_phone'] !== '',
    ];
    if ($private) {
        $out['id'] = (int)$play['id'];
        $out['customer'] = [
            'name' => (string)$play['customer_name'], 'phone' => (string)$play['customer_phone'], 'note' => (string)$play['customer_note'],
        ];
        $out['claimed_at'] = iso($play['claimed_at']);
        $out['delivered_at'] = iso($play['delivered_at']);
        $out['delivered_by'] = (string)$play['delivered_by_name'];
    }
    return $out;
}

const MSG_FORMAT = 'Ce code n’est pas valide. Il compte 10 caractères (lettres et chiffres, sans O, I, L, 0 ni 1).';
const MSG_INVALID = 'Ce code n’existe pas. Vérifiez votre ticket.';
const MSG_VOID = 'Ce ticket a été désactivé et ne peut plus être joué.';
const MSG_USED = 'Ce ticket a déjà été joué. Chaque ticket ne donne droit qu’à un seul tour, tous jeux confondus.';

/**
 * Ticket déjà joué ouvert sur la page d'accueil : adresse du jeu où il a été utilisé (le client y retrouve
 * son résultat et ne revoit pas la page d'accueil). Null si le ticket n'a pas encore été joué.
 */
function played_game_url(string $raw): ?string
{
    $code = code_normalize($raw);
    if (!$code) return null;
    $key = 'code:' . rate_ip();
    if (rate_count($key, 900) >= 30) return null; // même limite d'essais que le jeu
    $c = row('SELECT id, status FROM codes WHERE code = ?', [$code]);
    if (!$c) {
        rate_hit($key);
        return null;
    }
    if ($c['status'] !== 'used') return null;
    $play = play_by_code_id((int)$c['id']);
    $p = $play ? project_by_id((int)$play['project_id']) : null;
    if (!$p) return null;
    return base_path() . '?p=' . rawurlencode((string)$p['slug']) . '&c=' . rawurlencode(code_format($code));
}

/* ───────────── Vérification d'un ticket (sans le consommer) ───────────── */

function game_check(string $slug, $raw): array
{
    $project = game_project($slug);
    $code = code_normalize($raw);
    if (!$code) fail('format', MSG_FORMAT);
    $key = 'code:' . rate_ip();
    rate_check($key, 30, 900);
    $c = row('SELECT * FROM codes WHERE code = ?', [$code]);
    if (!$c) {
        rate_hit($key);
        fail('invalid', MSG_INVALID);
    }
    if ($c['status'] === 'void') fail('void', MSG_VOID);
    if ($c['status'] === 'used') {
        $play = play_by_code_id((int)$c['id']);
        if ($play && (int)$play['project_id'] === (int)$project['id']) {
            return ['state' => 'played', 'code' => code_format($code), 'result' => result_payload($play, $project)];
        }
        fail('used', MSG_USED);
    }
    return ['state' => 'ready', 'code' => code_format($code)];
}

/* ───────────── Partie : consommation atomique du ticket + tirage ───────────── */

function game_play(string $slug, $raw): array
{
    $project = game_project($slug);
    $code = code_normalize($raw);
    if (!$code) fail('format', MSG_FORMAT);
    $key = 'code:' . rate_ip();
    rate_check($key, 30, 900);
    $pid = (int)$project['id'];
    $ip = client_ip();

    try {
        $outcome = tx_write(function () use ($code, $pid, $ip) {
            $c = row('SELECT * FROM codes WHERE code = ?' . for_update(), [$code]);
            if (!$c) return ['error' => 'invalid'];
            if ($c['status'] === 'void') return ['error' => 'void'];
            if ($c['status'] !== 'new') return ['error' => 'used', 'code_id' => (int)$c['id']];

            $t = now();
            // 1) Le ticket passe à « utilisé » — une seule requête peut réussir.
            $n = q("UPDATE codes SET status = 'used', project_id = ?, used_at = ? WHERE id = ? AND status = 'new'", [$pid, $t, (int)$c['id']])->rowCount();
            if ($n !== 1) throw new CodeRace();

            // 2) Tirage pondéré parmi les cases gagnables (stock disponible).
            $segs = rows('SELECT * FROM segments WHERE project_id = ? ORDER BY position, id', [$pid]);
            $prods = [];
            foreach (rows('SELECT * FROM products WHERE project_id = ?', [$pid]) as $p) $prods[(int)$p['id']] = $p;
            $cands = [];
            foreach ($segs as $s) {
                $w = max(0, (int)$s['chance']);
                if ($w <= 0) continue;
                if ($s['kind'] !== 'lose') {
                    $p = $prods[(int)$s['product_id']] ?? null;
                    if (!$p || !product_available($p)) continue;
                }
                $cands[] = ['seg' => $s, 'w' => $w];
            }
            while ($cands) {
                $total = 0;
                foreach ($cands as $cd) $total += $cd['w'];
                $r = random_int(1, $total);
                $idx = 0;
                foreach ($cands as $i => $cd) {
                    $r -= $cd['w'];
                    if ($r <= 0) { $idx = $i; break; }
                }
                $seg = $cands[$idx]['seg'];
                $prod = $seg['kind'] !== 'lose' ? ($prods[(int)$seg['product_id']] ?? null) : null;
                if ($prod && $prod['stock'] !== null) {
                    // 3) Décrément du stock, lui aussi conditionnel (jamais négatif).
                    $ok = q('UPDATE products SET stock = stock - 1 WHERE id = ? AND stock > 0', [(int)$prod['id']])->rowCount();
                    if ($ok !== 1) { array_splice($cands, $idx, 1); continue; }
                }
                // 4) Enregistrement de la partie (contrainte UNIQUE sur code_id).
                $playId = insert('plays', [
                    'code_id' => (int)$c['id'], 'project_id' => $pid, 'segment_id' => (int)$seg['id'], 'kind' => $seg['kind'],
                    'product_id' => $prod ? (int)$prod['id'] : null,
                    'prize_name' => $prod ? (string)$prod['name'] : '', 'prize_weight' => $prod ? (string)$prod['weight'] : '',
                    'prize_photo' => $prod ? (string)$prod['photo'] : '', 'prize_value' => $prod ? (string)$prod['value_text'] : '',
                    'status' => $seg['kind'] === 'lose' ? 'none' : 'pending',
                    'customer_name' => '', 'customer_phone' => '', 'customer_note' => '',
                    'claimed_at' => null, 'delivered_at' => null, 'delivered_by' => null, 'delivered_by_name' => '',
                    'ip' => substr($ip, 0, 64), 'created_at' => $t,
                ]);
                return ['play_id' => $playId];
            }
            throw new NoOutcome(); // annule tout : le ticket reste jouable
        });
    } catch (CodeRace $e) {
        $outcome = ['error' => 'used', 'code_id' => (int)val('SELECT id FROM codes WHERE code = ?', [$code])];
    } catch (NoOutcome $e) {
        fail('unavailable', 'Le jeu est momentanément indisponible. Votre ticket reste valable : réessayez plus tard.');
    }

    if (isset($outcome['error'])) {
        switch ($outcome['error']) {
            case 'invalid':
                rate_hit($key);
                fail('invalid', MSG_INVALID);
                break;
            case 'void':
                fail('void', MSG_VOID);
                break;
            default:
                $play = play_by_code_id((int)$outcome['code_id']);
                if ($play && (int)$play['project_id'] === $pid) {
                    return ['replay' => true, 'result' => result_payload($play, $project), 'segments' => public_segments($pid)];
                }
                fail('used', MSG_USED);
        }
    }
    $play = play_by_id((int)$outcome['play_id']);
    return ['replay' => false, 'result' => result_payload($play, $project), 'segments' => public_segments($pid)];
}

/* ───────────── Coordonnées du gagnant ───────────── */

function clean_phone($v): string
{
    $s = clean_str($v, 30);
    return trim((string)preg_replace('/[^0-9+ ().\-]/', '', $s));
}

function game_claim(string $slug, $raw, array $in): array
{
    $project = game_project($slug);
    $code = code_normalize($raw);
    if (!$code) fail('format', MSG_FORMAT);
    $key = 'code:' . rate_ip();
    rate_check($key, 30, 900);
    $c = row('SELECT * FROM codes WHERE code = ?', [$code]);
    if (!$c) {
        rate_hit($key);
        fail('invalid', MSG_INVALID);
    }
    $play = play_by_code_id((int)$c['id']);
    if (!$play || (int)$play['project_id'] !== (int)$project['id']) fail('invalid', 'Aucune partie trouvée pour ce ticket sur ce jeu.');
    if ($play['kind'] === 'lose') fail('lose', 'Aucun lot n’est associé à ce ticket.');
    if ($play['status'] === 'delivered') fail('closed', 'Ce lot a déjà été remis.');
    if ($play['status'] === 'cancelled') fail('closed', 'Ce gain a été annulé.');

    $opts = project_settings($project)['options'];
    if (isset($opts['ask_details']) && !$opts['ask_details']) fail('closed', 'Ce jeu ne demande pas de coordonnées.');
    $name = in_str($in, 'name', 80);
    $phone = clean_phone($in['phone'] ?? '');
    $note = in_str($in, 'note', 300, true);
    if ($name === '') fail('field', 'Indiquez votre nom.', 200, ['field' => 'name']);
    if (!empty($opts['require_phone']) && strlen((string)preg_replace('/\D/', '', $phone)) < 6) {
        fail('field', 'Indiquez un numéro de téléphone valide.', 200, ['field' => 'phone']);
    }
    // La première saisie fait foi : quelqu'un qui connaîtrait seulement le code ne peut pas détourner le lot.
    $n = q("UPDATE plays SET customer_name = ?, customer_phone = ?, customer_note = ?, claimed_at = ? WHERE id = ? AND status = 'pending' AND claimed_at IS NULL",
        [$name, $phone, $note, now(), (int)$play['id']])->rowCount();
    if ($n !== 1) fail('closed', 'Vos coordonnées sont déjà enregistrées. Pour les modifier, contactez-nous.');
    return ['saved' => true];
}

/* ───────────── Consultation centrale d'un ticket (livreur & admin) ───────────── */

function code_lookup($raw): array
{
    $code = code_normalize($raw);
    if (!$code) fail('format', 'Code illisible ou mal saisi : vérifiez les 10 caractères.');
    $c = row('SELECT c.*, b.label AS batch_label FROM codes c LEFT JOIN batches b ON b.id = c.batch_id WHERE c.code = ?', [$code]);
    if (!$c) fail('invalid', 'Code inconnu : ce ticket n’a pas été émis par votre système.');
    $out = [
        'code' => code_format($code), 'status' => (string)$c['status'], 'batch' => (string)($c['batch_label'] ?? ''),
        'created_at' => iso($c['created_at']), 'used_at' => iso($c['used_at']), 'result' => null,
    ];
    if ($c['status'] === 'used') {
        $play = play_by_code_id((int)$c['id']);
        if ($play) $out['result'] = result_payload($play, project_by_id((int)$play['project_id']), true);
    }
    return $out;
}

/** Marque un lot comme remis. Atomique : un lot ne peut être remis qu'une fois. */
function deliver_play(int $playId, array $by): array
{
    $n = q("UPDATE plays SET status = 'delivered', delivered_at = ?, delivered_by = ?, delivered_by_name = ? WHERE id = ? AND status = 'pending'",
        [now(), $by['id'], (string)$by['name'], $playId])->rowCount();
    $play = play_by_id($playId);
    if (!$play) fail('invalid', 'Partie introuvable.');
    $project = project_by_id((int)$play['project_id']);
    if ($n !== 1) {
        $res = result_payload($play, $project, true);
        if ($play['status'] === 'delivered') {
            fail('already', 'Ce lot a déjà été remis.', 200, ['result' => $res]);
        }
        if ($play['status'] === 'cancelled') fail('cancelled', 'Ce gain a été annulé par l’administrateur.', 200, ['result' => $res]);
        fail('nothing', 'Aucun lot à remettre pour ce ticket.', 200, ['result' => $res]);
    }
    return result_payload($play, $project, true);
}

function pending_list(?int $projectId = null, int $limit = 300): array
{
    $sql = "SELECT pl.*, c.code FROM plays pl JOIN codes c ON c.id = pl.code_id WHERE pl.status = 'pending'";
    $params = [];
    if ($projectId) { $sql .= ' AND pl.project_id = ?'; $params[] = $projectId; }
    $sql .= ' ORDER BY pl.created_at DESC, pl.id DESC LIMIT ' . (int)$limit;
    $projects = [];
    foreach (projects_list() as $p) $projects[(int)$p['id']] = $p;
    $out = [];
    foreach (rows($sql, $params) as $pl) $out[] = result_payload($pl, $projects[(int)$pl['project_id']] ?? null, true);
    return $out;
}
