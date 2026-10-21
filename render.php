<?php
/* Roue de la Chance — gabarits HTML des pages */
defined('RDC') || exit;

function asset_url(string $rel): string
{
    $file = RDC_ROOT . '/' . $rel;
    $v = is_file($file) ? (string)filemtime($file) : RDC;
    return base_path() . $rel . '?v=' . $v;
}

function fonts_url(?array $theme = null): string
{
    $f = defaults()['fonts'];
    $title = $f['title'][$theme['font_title'] ?? 'Bungee'] ?? $f['title']['Bungee'];
    $body = $f['body'][$theme['font_body'] ?? 'Barlow'] ?? $f['body']['Barlow'];
    $fam = array_unique([$title['spec'], $body['spec'], $f['wheel'], $f['title']['Bungee']['spec']]);
    return 'https://fonts.googleapis.com/css2?family=' . implode('&family=', $fam) . '&display=swap';
}

function theme_css(array $t): string
{
    $f = defaults()['fonts'];
    $title = $t['font_title'] ?? 'Bungee';
    $weight = (int)($f['title'][$title]['weight'] ?? 400);
    $vars = [
        '--c1' => clean_color($t['c1'] ?? '', '#FF2E9A'), '--c2' => clean_color($t['c2'] ?? '', '#18E7FF'),
        '--c3' => clean_color($t['c3'] ?? '', '#FFD60A'), '--bg1' => clean_color($t['bg1'] ?? '', '#0E0630'),
        '--bg2' => clean_color($t['bg2'] ?? '', '#3B0A6B'), '--ink' => clean_color($t['text'] ?? '', '#FFF5FB'),
        '--font-title' => "'" . str_replace("'", '', $title) . "', 'Bungee', Impact, sans-serif",
        '--font-body' => "'" . str_replace("'", '', $t['font_body'] ?? 'Barlow') . "', 'Barlow', system-ui, sans-serif",
        '--title-weight' => (string)$weight,
    ];
    $css = ':root{';
    foreach ($vars as $k => $v) $css .= $k . ':' . $v . ';';
    return $css . '}';
}

/**
 * Affiche une page complète.
 * $o = [title, description, body_class, css[], js[], boot, theme, noindex, icon, head (html), manifest]
 */
function render_page(array $o): void
{
    $nonce = base64_encode(random_bytes(16));
    header('Content-Type: text/html; charset=utf-8');
    $img = "'self' data: blob: https:" . (is_https() ? '' : ' http:'); // essais en local sans https
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce' https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js; " .
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com data:; " .
        "img-src $img; media-src 'self' blob:; connect-src 'self'; worker-src 'self' blob:; " .
        "frame-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'; object-src 'none'");
    if (!empty($o['noindex'])) header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');

    $title = h($o['title'] ?? 'Roue de la chance');
    $desc = h($o['description'] ?? 'Scannez votre ticket et tentez votre chance.');
    $themeColor = h($o['theme']['bg1'] ?? '#0E0630');
    $icon = !empty($o['icon']) ? h($o['icon']) : h(base_path() . 'assets/img/favicon.svg');
    echo "<!doctype html>\n<html lang=\"fr\">\n<head>\n";
    echo "<meta charset=\"utf-8\">\n";
    echo "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1, viewport-fit=cover\">\n";
    echo "<title>$title</title>\n<meta name=\"description\" content=\"$desc\">\n";
    echo "<meta name=\"theme-color\" content=\"$themeColor\">\n";
    if (!empty($o['noindex'])) echo "<meta name=\"robots\" content=\"noindex, nofollow\">\n";
    echo "<link rel=\"icon\" href=\"$icon\">\n";
    if (!empty($o['og_image'])) {
        echo '<meta property="og:title" content="' . $title . "\">\n";
        echo '<meta property="og:description" content="' . $desc . "\">\n";
        echo '<meta property="og:image" content="' . h($o['og_image']) . "\">\n";
    }
    if (!empty($o['manifest'])) {
        echo '<link rel="manifest" href="' . h($o['manifest']) . "\">\n";
        echo "<meta name=\"apple-mobile-web-app-capable\" content=\"yes\">\n<meta name=\"mobile-web-app-capable\" content=\"yes\">\n";
        echo '<link rel="apple-touch-icon" href="' . h(base_path() . 'assets/img/icon-192.png') . "\">\n";
    }
    echo "<link rel=\"preconnect\" href=\"https://fonts.googleapis.com\">\n<link rel=\"preconnect\" href=\"https://fonts.gstatic.com\" crossorigin>\n";
    echo '<link rel="stylesheet" href="' . h(fonts_url($o['theme'] ?? null)) . "\">\n";
    foreach ($o['css'] ?? [] as $css) echo '<link rel="stylesheet" href="' . h(asset_url($css)) . "\">\n";
    if (!empty($o['theme'])) echo '<style id="theme-vars">' . theme_css($o['theme']) . "</style>\n";
    echo $o['head'] ?? '';
    echo "</head>\n<body class=\"" . h($o['body_class'] ?? '') . "\">\n";
    echo "<div id=\"app\" class=\"app-root\"></div>\n";
    echo "<noscript><p class=\"noscript\">Activez JavaScript dans votre navigateur pour utiliser ce site.</p></noscript>\n";
    echo "<script nonce=\"$nonce\">window.RDC_BOOT = " . json_script($o['boot'] ?? []) . ";</script>\n";
    foreach ($o['js'] ?? [] as $js) echo '<script src="' . h(asset_url($js)) . "\"></script>\n";
    echo "<script nonce=\"$nonce\">RDC.start(window.RDC_BOOT);</script>\n";
    echo "</body>\n</html>\n";
}

function render_fatal(Throwable $e): void
{
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    $detail = cfg('debug') ? '<pre>' . h($e->getMessage()) . '</pre>' : '';
    $hint = 'Vérifiez que le dossier <code>data/</code> existe et qu’il est accessible en écriture (droits 755), puis rechargez la page.';
    if (cfg('db.driver') === 'mysql') $hint = 'Vérifiez les identifiants MySQL dans le fichier <code>config.php</code>.';
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">' .
        '<title>Installation à terminer</title><style>body{font:16px/1.5 system-ui,sans-serif;background:#0E0630;color:#FFF5FB;display:grid;place-items:center;min-height:100vh;margin:0;padding:24px}' .
        'main{max-width:520px}h1{color:#FF2E9A;font-size:24px}code{background:#2B1257;padding:2px 6px;border-radius:6px}pre{white-space:pre-wrap;background:#1C0B40;padding:12px;border-radius:8px}</style></head>' .
        '<body><main><h1>La base de données n’est pas accessible</h1><p>' . $hint . '</p>' . $detail . '</main></body></html>';
    exit;
}

const GAME_JS = ['assets/js/core.js', 'assets/js/qr.js', 'assets/js/sfx.js', 'assets/js/fx.js', 'assets/js/wheel.js', 'assets/js/scanner.js', 'assets/js/game.js'];

function render_game(array $project, string $code): void
{
    $boot = public_boot($project, $code);
    $logo = $boot['project']['logo'];
    render_page([
        'title' => $project['name'] . ' — Roue de la chance',
        'description' => $boot['texts']['subtitle'],
        'body_class' => 'page-game',
        'css' => ['assets/css/base.css', 'assets/css/wheel.css', 'assets/css/game.css'],
        'js' => GAME_JS,
        'boot' => $boot,
        'theme' => $boot['theme'],
        'icon' => $logo ?: null,
        'og_image' => $logo ? (preg_match('#^https?://#', $logo) ? $logo : (is_https() ? 'https' : 'http') . '://' . request_host() . $logo) : null,
    ]);
}

function render_portal(string $code, bool $notFound = false): void
{
    $s = settings_all();
    $projects = [];
    foreach (projects_list(true) as $p) $projects[] = project_public($p);
    $theme = defaults()['project']['theme'];
    render_page([
        'title' => $s['portal']['title'] ?: 'Roue de la chance',
        'description' => $s['portal']['text'],
        'body_class' => 'page-portal',
        'css' => ['assets/css/base.css', 'assets/css/game.css'],
        'js' => ['assets/js/core.js', 'assets/js/portal.js'],
        'boot' => [
            'mode' => 'portal', 'base' => base_path(), 'api' => base_path() . 'api/', 'code' => $code, 'not_found' => $notFound,
            'portal' => ['title' => $s['portal']['title'], 'text' => $s['portal']['text'], 'logo' => asset_abs($s['portal']['logo'])],
            'projects' => $projects,
        ],
        'theme' => $theme,
    ]);
}

function render_closed(array $project): void
{
    $s = project_settings($project);
    render_page([
        'title' => $project['name'] . ' — Roue de la chance',
        'body_class' => 'page-portal',
        'css' => ['assets/css/base.css', 'assets/css/game.css'],
        'js' => ['assets/js/core.js', 'assets/js/portal.js'],
        'boot' => ['mode' => 'closed', 'base' => base_path(), 'project' => project_public($project)],
        'theme' => $s['theme'],
    ]);
}

function shell_page(string $mode, string $title, array $css, array $js, array $boot = [], array $extra = []): void
{
    render_page($extra + [
        'title' => $title,
        'body_class' => 'page-' . $mode,
        'css' => $css,
        'js' => $js,
        'boot' => ['mode' => $mode, 'base' => base_path(), 'api' => base_path() . 'api/', 'version' => RDC] + $boot,
        'noindex' => true,
        'theme' => ['bg1' => '#140A33'],
    ]);
}
