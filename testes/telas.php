<?php
declare(strict_types=1);

/**
 * Teste de fumaça das telas:
 *
 *     php testes/telas.php
 *
 * Sobe um servidor embutido sobre um banco temporário, abre cada página e cobra
 * duas coisas: HTTP 200 e nenhum aviso do PHP no log do servidor.
 *
 * Existe por um motivo concreto. A suíte do motor não abre página nenhuma, e
 * por isso deixou passar duas falhas que o primeiro acesso mostraria: um
 * `require` fora de ordem que derrubava o cadastro de feriados em 500, e um
 * SELECT sem a coluna `regime` que fazia a tela do calendário rotular os
 * bimestres de um curso anual como se ele fosse semestral. Nenhuma das duas é
 * pegável por `php -l` nem por teste de unidade — só abrindo.
 */

$raiz = dirname(__DIR__);
$tmp  = sys_get_temp_dir() . '/calendario-telas-' . getmypid() . '.sqlite';
$log  = sys_get_temp_dir() . '/calendario-telas-' . getmypid() . '.log';

$limpar = static function () use ($tmp, $log): void {
    foreach ([$tmp, "$tmp-wal", "$tmp-shm", $log] as $f) {
        @unlink($f);
    }
};
$limpar();
register_shutdown_function($limpar);

// ── O banco que as telas vão encontrar: um curso anual e um calendário dele ──
// Anual de propósito: é o regime em que um rótulo errado aparece.
putenv('CALENDARIO_DB=' . $tmp);
require $raiz . '/lib/boot.php';

$db = db();
$db->prepare('INSERT INTO cursos (nome, nivel, regime, ativo) VALUES (?,?,?,1)')
   ->execute(['CURSO DE FUMAÇA', 'integrado', 'anual']);
$curso = (int) $db->lastInsertId();
$db->prepare('INSERT INTO calendarios (curso_id, ano, regime) VALUES (?,?,?)')->execute([$curso, 2026, 'anual']);
$cal = (int) $db->lastInsertId();
$feriado = (int) $db->query('SELECT MIN(id) FROM feriados')->fetchColumn();
// O sistema pede login: sem um usuário, tudo redireciona para o primeiro acesso
// e o teste mediria a tela de cadastro doze vezes.
criarUsuario($db, 'Teste', 'teste', 'senha-de-teste');
salvarPeriodos($db, $cal, [
    1 => ['2026-02-02', '2026-04-10'], 2 => ['2026-04-13', '2026-06-30'],
    3 => ['2026-08-03', '2026-10-02'], 4 => ['2026-10-05', '2026-12-18'],
]);
$db = null;

// ── Servidor embutido numa porta livre ──────────────────────────────────────
$sock = stream_socket_server('tcp://127.0.0.1:0', $err, $msg);
$porta = (int) explode(':', (string) stream_socket_get_name($sock, false))[1];
fclose($sock);

$cmd = sprintf(
    'CALENDARIO_DB=%s php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!',
    escapeshellarg($tmp),
    $porta,
    escapeshellarg($raiz),
    escapeshellarg($log)
);
$pid = (int) shell_exec($cmd);
register_shutdown_function(static function () use ($pid): void {
    if ($pid > 0) {
        @exec('kill ' . $pid . ' 2>/dev/null');
    }
});

// O servidor leva um instante para atender; sem esta espera o primeiro pedido
// falharia por conexão recusada, e não por defeito da tela.
$base = "http://127.0.0.1:$porta";
for ($i = 0; $i < 50; $i++) {
    if (@file_get_contents("$base/login.php", false, stream_context_create(
        ['http' => ['timeout' => 1, 'ignore_errors' => true]]
    )) !== false) {
        break;
    }
    usleep(100000);
}

/**
 * Um pedido com a sessão que o login abriu. $cookie é preenchido pelo primeiro
 * Set-Cookie que chegar e vai em todos os pedidos daí em diante — é o que um
 * navegador faz, e sem isso cada tela responderia como visitante.
 *
 * @return array{0: string|false, 1: int} corpo e código
 */
$cookie = '';
$pedir = static function (string $url, ?array $campos = null) use (&$cookie): array {
    $opcoes = ['timeout' => 10, 'ignore_errors' => true, 'follow_location' => 0];
    $cabecalhos = $cookie !== '' ? ["Cookie: $cookie"] : [];
    if ($campos !== null) {
        $opcoes['method']  = 'POST';
        $opcoes['content'] = http_build_query($campos);
        $cabecalhos[]      = 'Content-Type: application/x-www-form-urlencoded';
    }
    $opcoes['header'] = implode("\r\n", $cabecalhos);
    $corpo  = @file_get_contents($url, false, stream_context_create(['http' => $opcoes]));
    $codigo = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
            $codigo = (int) $m[1];
        }
        // Sempre o último: o login regenera o id da sessão, e ficar com o
        // primeiro deixaria o teste segurando um id que já foi destruído.
        if (preg_match('#^Set-Cookie:\s*([^;]+)#i', $h, $mc)) {
            $cookie = $mc[1];
        }
    }
    return [$corpo, $codigo];
};

// Entra no sistema. O token sai do próprio formulário, como no navegador.
[$corpoLogin] = $pedir("$base/login.php");
preg_match('/name="_csrf" value="([^"]+)"/', (string) $corpoLogin, $m);
$pedir("$base/login.php", ['_csrf' => $m[1] ?? '', 'acao' => 'entrar', 'usuario' => 'teste', 'senha' => 'senha-de-teste']);
[$corpoPainel, $codigoPainel] = $pedir("$base/index.php");
if ($codigoPainel !== 200 || !str_contains((string) $corpoPainel, 'Calendário Acadêmico')) {
    echo "\n\033[31mNão foi possível entrar no sistema; as telas não seriam testadas.\033[0m\n";
    echo "  login: código $codigoPainel, cookie " . ($cookie !== '' ? "'$cookie'" : '(nenhum)') . "\n";
    echo "  token lido do formulário: " . (($m[1] ?? '') !== '' ? 'sim' : 'NÃO') . "\n";
    echo "  primeiros 300 do painel: " . substr(strip_tags((string) $corpoPainel), 0, 300) . "\n";
    exit(1);
}

// ── As telas ────────────────────────────────────────────────────────────────
$telas = [
    'index.php',
    'login.php',
    'calendarios.php',
    'calendarios.php?novo=1',
    "calendario.php?id=$cal",
    "calendario.php?id=$cal&novo=1",
    // O feriado se edita das três telas, em modal, sem sair de onde se está.
    "calendario.php?id=$cal&editar_feriado=$feriado",
    "eventos.php?ano=2026&editar_feriado=$feriado",
    "feriados.php?ano=2026&editar_feriado=$feriado",
    'feriados.php?ano=2026&novo=1',
    // Os dados do calendário também: o formulário virou modal na própria grade.
    "calendario.php?id=$cal&editar_cal=1",
    "calendario.php?id=$cal&filtros=1&feriados=0&globais=0&auto=0&locais=0",
    'calendarios.php?novo=1',
    // A tela antiga virou caminho para o modal: responde 302.
    "editar_calendario.php?id=$cal",
    "gerar.php?id=$cal",
    "exportar_xls.php?id=$cal",
    'cursos.php',
    'eventos.php',
    'eventos.php?ano=2027',
    'feriados.php',
    'categorias.php',
    'configuracoes.php',
    'niveis.php',
    'usuarios.php',
    'usuarios.php?novo=1',
    'backup.php',
];

$falhou = [];
echo "\n\033[1mTelas: abrem sem erro?\033[0m\n";

foreach ($telas as $tela) {
    [$body, $codigo] = $pedir("$base/$tela");
    // 302 é resposta boa: é para onde uma tela manda quando o id não serve.
    $ok = $body !== false && in_array($codigo, [200, 302], true);
    if ($ok) {
        echo "  \033[32m✓\033[0m $tela\n";
    } else {
        $falhou[] = "$tela respondeu $codigo";
        echo "  \033[31m✗\033[0m $tela — HTTP $codigo\n";
    }
}

// ── O log do servidor: um aviso do PHP conta como falha ─────────────────────
// É aqui que a coluna esquecida no SELECT aparece: a página responde 200 e
// parece certa, e o "Undefined array key" fica só no log.
$saida  = (string) @file_get_contents($log);
$avisos = [];
foreach (explode("\n", $saida) as $linha) {
    if (preg_match('/PHP (Warning|Notice|Fatal error|Parse error|Deprecated)/', $linha)) {
        $avisos[] = trim(preg_replace('/^\[[^\]]*\]\s*/', '', $linha));
    }
}
$avisos = array_values(array_unique($avisos));

echo "\n\033[1mO log do servidor está limpo?\033[0m\n";
if ($avisos) {
    foreach ($avisos as $a) {
        echo "  \033[31m✗\033[0m $a\n";
        $falhou[] = $a;
    }
} else {
    echo "  \033[32m✓\033[0m nenhum aviso do PHP nas " . count($telas) . " telas\n";
}

echo "\n" . str_repeat('─', 60) . "\n";
if ($falhou) {
    echo "\033[31m" . count($falhou) . " problema(s).\033[0m\n";
    exit(1);
}
echo "\033[32m" . count($telas) . " telas, todas abrem limpas.\033[0m\n";
