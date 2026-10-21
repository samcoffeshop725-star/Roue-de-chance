<?php
/*
 * Roue de la Chance — amorçage commun à toutes les pages.
 * Chaque point d'entrée définit RDC_DEPTH (0 = racine, 1 = sous-dossier) avant d'inclure ce fichier.
 */
if (!defined('RDC_DEPTH')) { http_response_code(403); exit; }

define('RDC', '1.0.0');
define('RDC_ROOT', dirname(__DIR__));
define('RDC_APP', __DIR__);

$GLOBALS['RDC_CFG'] = require RDC_ROOT . '/config.php';

// Hébergement en conteneur (Render…) : les réglages sensibles viennent des variables d'environnement,
// jamais du dépôt de code. Elles priment sur config.php quand elles sont définies.
(function () {
    $env = function (string $k): ?string {
        $v = getenv($k);
        return ($v === false || trim((string)$v) === '') ? null : trim((string)$v);
    };
    $c = &$GLOBALS['RDC_CFG'];
    foreach (['admin_phone' => 'RDC_ADMIN_PHONE', 'admin_password' => 'RDC_ADMIN_PASSWORD',
              'install_key' => 'RDC_INSTALL_KEY', 'client_ip_header' => 'RDC_CLIENT_IP_HEADER'] as $k => $var) {
        if (($v = $env($var)) !== null) $c[$k] = $v;
    }
    if (($dir = $env('RDC_DATA_DIR')) !== null) {
        // Base de données sur le disque permanent (les photos y sont aussi, voir docker/start.sh).
        $c['db']['driver'] = 'sqlite';
        $c['db']['sqlite_path'] = rtrim($dir, '/') . '/roue.sqlite';
    }
    $yes = ['1', 'true', 'oui', 'yes'];
    if (in_array(strtolower((string)$env('RDC_RESET_ADMIN')), $yes, true)) $c['reset_admin'] = true;
    if (in_array(strtolower((string)$env('RDC_DEBUG')), $yes, true)) $c['debug'] = true;
})();

if (!empty($GLOBALS['RDC_CFG']['debug'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
    ini_set('display_errors', '0');
}
date_default_timezone_set('UTC');

// Extensions PHP indispensables : message clair plutôt qu'une page blanche.
$rdcMissing = [];
foreach (['mbstring' => 'mb_strlen', 'json' => 'json_encode', 'PDO' => 'class:PDO'] as $ext => $probe) {
    $ok = strpos($probe, 'class:') === 0 ? class_exists(substr($probe, 6)) : function_exists($probe);
    if (!$ok) $rdcMissing[] = $ext;
}
if ($rdcMissing || version_compare(PHP_VERSION, '8.0.0', '<')) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Configuration requise</title><body style="font:16px/1.5 system-ui;padding:32px;max-width:640px;margin:auto">'
        . '<h1>Votre hébergement doit être ajusté</h1><p>Ce site demande PHP 8.0 ou plus récent'
        . ($rdcMissing ? ' avec les extensions : <strong>' . htmlspecialchars(implode(', ', $rdcMissing)) . '</strong>' : '')
        . '. Version actuelle : ' . htmlspecialchars(PHP_VERSION) . '. Ces réglages se changent dans le panneau de votre hébergeur (rubrique « PHP »).</p></body>';
    exit;
}
mb_internal_encoding('UTF-8');

require RDC_APP . '/util.php';
require RDC_APP . '/db.php';
require RDC_APP . '/auth.php';
require RDC_APP . '/game.php';

// En-têtes de sécurité communs
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: SAMEORIGIN');
header('Permissions-Policy: camera=(self), microphone=(), geolocation=()');
