<?php
// Kondo — ponto de entrada. Todos os pedidos passam por aqui (ver .htaccess / Caddyfile).
declare(strict_types=1);

// Servidor embutido do PHP (php -S): deixa servir os ficheiros estáticos (style.css).
if (PHP_SAPI === 'cli-server') {
    $f = __DIR__ . rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
    if ($f !== __FILE__ && is_file($f)) return false;
}

require __DIR__ . '/../src/lib.php';
require __DIR__ . '/../src/routes.php';
require __DIR__ . '/../src/seed.php';

initDb();
seedRun();
if (random_int(1, 100) === 1) limparSessoes();
dispatch();
