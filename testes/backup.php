<?php
declare(strict_types=1);

// Cada restauração roda em outro processo: a trava dura a requisição inteira.
if (($argv[1] ?? '') === 'restaurar') {
    require __DIR__ . '/../lib/db.php';
    require __DIR__ . '/../lib/backup.php';
    try {
        echo json_encode(restaurarBackup($argv[2]));
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage());
        exit(1);
    }
}
if (($argv[1] ?? '') === 'abrir') {
    require __DIR__ . '/../lib/db.php';
    travarBanco();
    echo 'aberto';
    exit;
}

$dir = sys_get_temp_dir() . '/calendario-backup-' . bin2hex(random_bytes(6));
mkdir($dir);
mkdir($dir . '/backups');
file_put_contents($dir . '/backups/.htaccess', "Require all denied\n");
putenv('CALENDARIO_DB=' . $dir . '/atual.sqlite');
putenv('CALENDARIO_BACKUPS=' . $dir . '/backups');
require __DIR__ . '/../lib/db.php';
require __DIR__ . '/../lib/backup.php';
register_shutdown_function(static function () use ($dir): void {
    chmod($dir . '/backups', 0700);
    foreach (glob($dir . '/backups/*') as $f) { unlink($f); }
    unlink($dir . '/backups/.htaccess');
    rmdir($dir . '/backups');
    foreach (array_merge(glob($dir . '/*'), glob($dir . '/.restaurar-*')) as $f) { unlink($f); }
    rmdir($dir);
});
function banco(string $caminho, string $valor): void
{
    $p = new PDO('sqlite:' . $caminho);
    $p->exec((string) file_get_contents(__DIR__ . '/../lib/schema.sql'));
    marcarMigracoesComoAplicadas($p);
    $p->prepare('INSERT INTO config VALUES (?,?)')->execute(['teste', $valor]);
}
function valor(string $caminho): string
{
    return (string) (new PDO('sqlite:' . $caminho))->query("SELECT valor FROM config WHERE chave='teste'")->fetchColumn();
}
function iniciar(string $acao, string $arquivo = ''): array
{
    $p = proc_open([PHP_BINARY, __FILE__, $acao, $arquivo], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    return [$p, $pipes];
}
function terminar(array $processo): array
{
    [$p, $pipes] = $processo;
    $saida = stream_get_contents($pipes[1]);
    $erro = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($p), $saida, $erro];
}
function verificar(bool $ok, string $nome): void
{
    if (!$ok) { throw new RuntimeException('FALHOU: ' . $nome); }
    echo "✓ $nome\n";
}
banco(DB_PATH, 'original');
$upload = $dir . '/enviado.sqlite';
banco($upload, 'importado');

$invalido = $dir . '/invalido.sqlite';
file_put_contents($invalido, 'não é SQLite');
[$codigo] = terminar(iniciar('restaurar', $invalido));
verificar($codigo === 1 && valor(DB_PATH) === 'original', 'arquivo inválido preserva o banco');

$incompleto = $dir . '/incompleto.sqlite';
copy($upload, $incompleto);
(new PDO('sqlite:' . $incompleto))->exec('ALTER TABLE config DROP COLUMN valor');
[$codigo, , $erro] = terminar(iniciar('restaurar', $incompleto));
verificar($codigo === 1 && str_contains($erro, 'estrutura incompatível') && valor(DB_PATH) === 'original', 'estrutura inválida é recusada antes da troca');

// Um caminho que não pode ser uma pasta funciona também no Windows.
$bloqueado = $dir . '/nao-e-pasta';
file_put_contents($bloqueado, 'arquivo');
putenv('CALENDARIO_BACKUPS=' . $bloqueado . '/backups');
[$codigo, , $erro] = terminar(iniciar('restaurar', $upload));
putenv('CALENDARIO_BACKUPS=' . $dir . '/backups');
verificar($codigo === 1 && valor(DB_PATH) === 'original', 'pasta de backup indisponível impede a restauração');
if (PHP_OS_FAMILY !== 'Windows') {
    chmod($dir . '/backups', 0500);
    [$codigo, , $erro] = terminar(iniciar('restaurar', $upload));
    chmod($dir . '/backups', 0700);
    verificar($codigo === 1 && str_contains($erro, 'cópia de segurança') && valor(DB_PATH) === 'original', 'falha no snapshot obrigatório impede a restauração');
}

// Conexão fora do protocolo de flock: o lock nativo do SQLite impede a troca.
$externo = new PDO('sqlite:' . DB_PATH);
$externo->exec('PRAGMA journal_mode=WAL');
$externo->beginTransaction();
$externo->query('SELECT * FROM config')->fetchAll();
[$codigo] = terminar(iniciar('restaurar', $upload));
verificar($codigo === 1 && valor(DB_PATH) === 'original', 'conexão SQLite externa impede substituição insegura');
$externo->rollBack();
$externo = null;

// Restauração espera leitores da aplicação; novas requisições esperam a exclusiva.
$lock = fopen(DB_PATH . '.lock', 'c');
flock($lock, LOCK_SH);
$filho = iniciar('restaurar', $upload);
usleep(200000);
verificar(proc_get_status($filho[0])['running'] && valor(DB_PATH) === 'original', 'restauração espera o acesso em andamento');
flock($lock, LOCK_UN);
[$codigo, $saida, $erro] = terminar($filho);
verificar($codigo === 0 && valor(DB_PATH) === 'importado', 'restauração conclui após liberar o acesso: ' . $erro);
$retorno = json_decode($saida, true);
verificar(valor($retorno['seguranca']) === 'original', 'cópia de segurança contém o estado anterior');

flock($lock, LOCK_EX);
$filho = iniciar('abrir');
usleep(200000);
verificar(proc_get_status($filho[0])['running'], 'novo acesso espera a restauração');
flock($lock, LOCK_UN);
[$codigo, $saida] = terminar($filho);
fclose($lock);
verificar($codigo === 0 && $saida === 'aberto', 'acesso prossegue após a restauração');
verificar(glob($dir . '/.restaurar-*') === [], 'temporários removidos nos caminhos de sucesso e falha');
echo "Testes de restauração passaram.\n";
