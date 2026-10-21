<?php
/* Roue de la Chance — API de l'espace livreur (un seul espace pour tous les projets) */
defined('RDC') || exit;

function liv_projects(): array
{
    $out = [];
    foreach (projects_list() as $p) $out[] = project_public($p);
    return $out;
}

function liv_login(array $in): array
{
    $att = rate_take('pin:' . rate_ip(), 10, 900);
    // Plafond global : au-delà de 100 codes faux en une heure (toutes adresses confondues), connexion suspendue.
    try {
        $global = rate_take('pin-all', 100, 3600);
    } catch (ApiError $e) {
        app_alert('Plus de 100 codes livreur faux en une heure : les nouvelles connexions livreur sont suspendues pendant une heure (les livreurs déjà connectés ne sont pas touchés).');
        throw $e;
    }
    $pin = (string)preg_replace('/\D/', '', (string)($in['pin'] ?? ''));
    if (strlen($pin) < 4) fail('pin', 'Saisissez votre code livreur (6 à 8 chiffres).');
    $l = row('SELECT * FROM livreurs WHERE pin_hash = ? AND active = 1', [pin_hash($pin)]);
    if (!$l) fail('pin', 'Code incorrect. Demandez votre code à l’administrateur.');
    rate_release($att);
    rate_release($global);
    $t = token_create('livreur', (int)$l['id'], LIVREUR_TTL);
    cookie_set(LIVREUR_COOKIE, $t, LIVREUR_TTL);
    q('UPDATE livreurs SET last_seen_at = ? WHERE id = ?', [now(), (int)$l['id']]);
    return ['livreur' => ['id' => (int)$l['id'], 'name' => (string)$l['name'], 'admin' => false], 'projects' => liv_projects()];
}

function liv_me(): array
{
    if (!current_livreur() && !is_admin()) return ['livreur' => null];
    $l = require_livreur();
    if ($l['id']) q('UPDATE livreurs SET last_seen_at = ? WHERE id = ?', [now(), $l['id']]);
    return ['livreur' => $l, 'projects' => liv_projects(), 'pending' => (int)val("SELECT COUNT(*) FROM plays WHERE status = 'pending'")];
}

function liv_logout(): array
{
    token_revoke(LIVREUR_COOKIE);
    return [];
}

function liv_lookup(array $in): array
{
    require_livreur();
    return code_lookup($in['code'] ?? '');
}

function liv_deliver(array $in): array
{
    $l = require_livreur();
    $info = code_lookup($in['code'] ?? '');
    if (!$info['result']) {
        fail('nothing', $info['status'] === 'new' ? 'Ce ticket n’a pas encore été joué : aucun lot à remettre.' : 'Aucun lot à remettre pour ce ticket.');
    }
    if ($info['result']['kind'] === 'lose') fail('nothing', 'Ce ticket est perdant : aucun lot à remettre.');
    return ['result' => deliver_play((int)$info['result']['id'], $l)];
}

function liv_pending(array $in): array
{
    require_livreur();
    $pid = in_int($in, 'project_id');
    return ['items' => pending_list($pid ?: null)];
}
