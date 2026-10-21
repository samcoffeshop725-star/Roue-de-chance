<?php
/* Roue de la Chance — espace livreur : un seul outil pour vérifier les tickets de tous les projets */
define('RDC_DEPTH', 1);
require dirname(__DIR__) . '/app/bootstrap.php';
require RDC_APP . '/render.php';
try { db(); } catch (Throwable $e) { render_fatal($e); }

shell_page('livreur', 'Espace livreur — Roue de la chance',
    ['assets/css/base.css', 'assets/css/livreur.css'],
    ['assets/js/core.js', 'assets/js/sfx.js', 'assets/js/scanner.js', 'assets/js/livreur.js'],
    [],
    ['manifest' => base_path() . 'livreur/manifest.webmanifest', 'icon' => base_path() . 'assets/img/icon-192.png']
);
