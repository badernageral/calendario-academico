<?php
/**
 * Router do servidor embutido do PHP (php -S) para o modo desktop.
 *
 * O sistema não tem front controller: cada tela é um arquivo .php na raiz. O
 * papel daqui é servir o que existe, mandar "/" para o painel e negar o que os
 * .htaccess negam no Apache — o banco, os backups e a pasta lib/.
 *
 * Uso: php -S 127.0.0.1:<porta> -t <raiz-da-app> desktop/router.php
 */
$raiz = $_SERVER['DOCUMENT_ROOT'] ?: dirname(__DIR__);
$uri  = urldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// O que o Apache bloqueia por .htaccess.
if (preg_match('#^/(data|backups|lib|ferramentas)(/|$)#', $uri)
 || preg_match('#\.(sqlite|sqlite-wal|sqlite-shm|sql|py)$#i', $uri)) {
    http_response_code(403);
    echo 'Acesso negado.';
    return true;
}

if ($uri === '/' || $uri === '') {
    require $raiz . '/index.php';
    return true;
}

$arquivo = realpath($raiz . $uri);
// realpath resolve ".." — sem isso, uma URL com ../ sairia da raiz da aplicação.
if ($arquivo === false || !str_starts_with($arquivo, (string) realpath($raiz))) {
    http_response_code(404);
    echo 'Não encontrado.';
    return true;
}

if (is_dir($arquivo) && is_file($arquivo . '/index.php')) {
    require $arquivo . '/index.php';
    return true;
}

// Arquivo estático (css, js, fontes) → o servidor embutido entrega direto;
// .php existente → o próprio servidor executa.
return is_file($arquivo) ? false : (http_response_code(404) && print('Não encontrado.'));
