<?php
/* Roue de la Chance — impression des tickets QR */
define('RDC_DEPTH', 1);
require dirname(__DIR__) . '/app/bootstrap.php';
require RDC_APP . '/render.php';
try { db(); } catch (Throwable $e) { render_fatal($e); }

if (!is_admin()) {
    header('Location: ' . base_path() . 'admin/');
    exit;
}
shell_page('print', 'Impression des tickets — Roue de la chance',
    ['assets/css/base.css', 'assets/css/print.css'],
    ['assets/js/core.js', 'assets/js/qr.js', 'assets/js/print.js'],
    ['batch' => (int)($_GET['batch'] ?? 0)]
);
