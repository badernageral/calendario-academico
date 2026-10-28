<?php
/**
 * Router do servidor embutido do PHP (php -S) para o modo desktop.
 *
 * O sistema não tem front controller: cada tela é um arquivo .php na raiz. O
 * papel daqui é servir o que existe, mandar "/" para o painel e negar o que os
 * .htaccess negam no Apache — o banco, os backups, lib/, ferramentas/ e testes/.
 * A lista tem de acompanhar os .htaccess: quem estiver com o .htaccess e sem a
 * entrada aqui fica aberto no modo desktop, e vice-versa.
 *
 * Uso: php -S 127.0.0.1:<porta> -t <raiz-da-app> desktop/router.php
 */
/**
 * Erro fatal vira página com a mensagem, e não tela em branco.
 *
 * No modo desktop o display_errors fica desligado — um aviso no meio da página
 * quebraria o redirect —, então um fatal sairia como 500 sem corpo, e a janela
 * do Electron mostraria só branco, sem pista nenhuma para quem está do outro
 * lado. O handler registrado aqui vale também para o .php que o servidor
 * executa depois do `return false`: é a mesma requisição.
 */
register_shutdown_function(static function (): void {
    $e = error_get_last();
    if ($e === null || !($e['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR))) {
        return;
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
    }
    $h   = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $log = (string) getenv('CALENDARIO_LOG');
    echo '<!doctype html><meta charset="utf-8"><title>Erro · Calendário Acadêmico</title>'
       . '<body style="font-family:system-ui,sans-serif;max-width:52rem;margin:3rem auto;padding:0 1rem;color:#222">'
       . '<h1 style="font-size:1.4rem">O Calendário Acadêmico encontrou um erro</h1>'
       . '<p>Envie esta mensagem para quem dá suporte ao sistema:</p>'
       . '<pre style="white-space:pre-wrap;background:#f4f4f4;padding:1rem;border-radius:6px">'
       . $h($e['message']) . "\n\nem " . $h($e['file']) . ':' . (int) $e['line'] . '</pre>'
       . ($log !== '' ? '<p>O registro completo fica em <code>' . $h($log) . '</code>.</p>' : '')
       . '</body>';
});

$raiz = $_SERVER['DOCUMENT_ROOT'] ?: dirname(__DIR__);
$uri  = urldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// O que o Apache bloqueia por .htaccess.
if (preg_match('#^/(data|backups|lib|ferramentas|testes)(/|$)#', $uri)
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
