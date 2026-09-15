<?php
declare(strict_types=1);

// Mesma história do banco: no desktop as cópias vão para junto dele, fora da
// pasta de instalação.
define('DIR_BACKUPS', getenv('CALENDARIO_BACKUPS') ?: APP_ROOT . '/backups');

/**
 * Tabelas que todo banco desta aplicação tem — serve de conferência na
 * importação.
 *
 * `migracoes` está na lista, e é ela que diz de que versão o arquivo veio. Todo
 * banco criado da 1.0 em diante nasce com ela; um arquivo sem ela é de outro
 * sistema, ou de uma versão anterior ao lançamento, que não existe mais em lugar
 * nenhum. Recusar é melhor do que restaurar um schema que a aplicação de hoje
 * não sabe ler e quebrar na primeira tela.
 */
const TABELAS_ESPERADAS = [
    'config', 'cursos', 'calendarios', 'categorias', 'eventos', 'evento_datas',
    'periodos', 'niveis', 'feriados', 'migracoes',
];

/**
 * Pasta dos backups, criada na primeira vez junto do .htaccess: ela fica dentro
 * da raiz do site, e sem isso qualquer um baixaria o banco pela URL.
 */
function dirBackups(): string
{
    if (!is_dir(DIR_BACKUPS)) {
        if (!@mkdir(DIR_BACKUPS, 0775, true) && !is_dir(DIR_BACKUPS)) {
            throw new RuntimeException('Não foi possível criar a pasta de backups.');
        }
    }
    $bloqueio = DIR_BACKUPS . '/.htaccess';
    if (!is_file($bloqueio)) {
        if (file_put_contents($bloqueio, "Require all denied\n") === false) {
            throw new RuntimeException('Não foi possível proteger a pasta de backups.');
        }
    }
    return DIR_BACKUPS;
}

/**
 * Cópia consistente do banco. VACUUM INTO fecha a transação e já incorpora o
 * WAL, então o arquivo gerado é um banco inteiro — o que um copy() simples não
 * garante com o site em uso.
 */
function snapshot(string $origem, string $destino): bool
{
    try {
        $pdo = new PDO('sqlite:' . $origem);
        $pdo->exec('VACUUM INTO ' . $pdo->quote($destino));
        $pdo = null;
    } catch (Throwable $e) {
        return false;
    }
    return is_file($destino) && filesize($destino) > 0;
}

/**
 * Caminho ainda livre para uma cópia nova. O nome leva a data até o segundo, e
 * dois cliques dentro do mesmo segundo cairiam no mesmo arquivo: o VACUUM INTO
 * recusa destino que já existe, e a limpeza do erro apagaria a cópia que o
 * primeiro clique acabou de gravar — o usuário levava o download e ficava sem
 * nada em backups/. O sufixo tira essa coincidência do caminho.
 */
function caminhoLivre(string $prefixo): string
{
    $base = dirBackups() . '/' . $prefixo . date('Ymd_His');
    if (!is_file($base . '.sqlite')) {
        return $base . '.sqlite';
    }
    for ($n = 2; $n <= 99; $n++) {
        if (!is_file("{$base}_{$n}.sqlite")) {
            return "{$base}_{$n}.sqlite";
        }
    }
    return $base . '_' . bin2hex(random_bytes(4)) . '.sqlite';
}

/**
 * Confere se o arquivo enviado é mesmo um banco desta aplicação, e de uma versão
 * que esta aplicação sabe ler.
 *
 * Devolve string vazia quando serve; senão, o motivo, para a tela dizer qual é.
 */
function motivoParaRecusar(string $caminho): string
{
    try {
        $pdo = new PDO('sqlite:' . $caminho, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        if ($pdo->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
            return 'O arquivo está corrompido.';
        }
        $tabelas = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
        $faltam  = array_diff(TABELAS_ESPERADAS, $tabelas);
        if ($faltam) {
            return in_array('migracoes', $faltam, true) && count($faltam) === 1
                ? 'O arquivo é de uma versão anterior ao histórico de migrações e não pode mais ser restaurado.'
                : 'O arquivo não é um banco de calendário: faltam as tabelas ' . implode(', ', $faltam) . '.';
        }
        // Migração que este código não conhece = backup de uma versão mais nova.
        // Restaurá-lo poria um schema à frente da aplicação, que passaria a
        // gravar sem saber o que mudou.
        $adiante = array_diff(
            $pdo->query('SELECT nome FROM migracoes')->fetchAll(PDO::FETCH_COLUMN),
            array_keys(migracoes())
        );
        $pdo = null;
        if ($adiante) {
            return 'O arquivo vem de uma versão mais nova do sistema (' . implode(', ', $adiante)
                 . '). Atualize antes de restaurá-lo.';
        }
    } catch (Throwable $e) {
        return 'O arquivo não pôde ser lido como banco SQLite.';
    }
    return '';
}

/** Confere as colunas usadas pela aplicação e os vínculos depois das migrações. */
function conferirBancoPreparado(PDO $pdo): void
{
    $modelo = new PDO('sqlite::memory:');
    $modelo->exec((string) file_get_contents(__DIR__ . '/schema.sql'));
    foreach ($modelo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN) as $tabela) {
        $nome = '"' . str_replace('"', '""', $tabela) . '"';
        $esperadas = $modelo->query("PRAGMA table_info($nome)")->fetchAll(PDO::FETCH_COLUMN, 1);
        $presentes = $pdo->query("PRAGMA table_info($nome)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (array_diff($esperadas, $presentes)) {
            throw new RuntimeException("O backup tem estrutura incompatível na tabela $tabela.");
        }
    }
    if ($pdo->query('PRAGMA integrity_check')->fetchColumn() !== 'ok'
        || $pdo->query('PRAGMA foreign_key_check')->fetch() !== false) {
        throw new RuntimeException('O backup contém dados corrompidos ou vínculos inválidos.');
    }
}

/**
 * Prepara tudo antes da troca. A trava exclusiva permanece até o fim do pedido,
 * inclusive em erro. Não abre db(): sua conexão estática impediria a troca no
 * Windows. rename substitui o destino sem expor uma cópia parcialmente escrita;
 * se o sistema operacional recusar, o original permanece e o pedido falha.
 * O temporário fica no mesmo diretório para a troca não cruzar filesystems.
 *
 * @return array{seguranca:string,migracoes:int}
 */
function restaurarBackup(string $upload): array
{
    travarBanco(true);
    $temporario = tempnam(dirname(DB_PATH), '.restaurar-');
    if ($temporario === false) {
        throw new RuntimeException('Não foi possível preparar o arquivo temporário.');
    }
    $pdo = null;
    try {
        if (!@copy($upload, $temporario)) {
            throw new RuntimeException('Não foi possível copiar o arquivo enviado.');
        }
        if (($motivo = motivoParaRecusar($temporario)) !== '') {
            throw new RuntimeException($motivo);
        }
        $pdo = new PDO('sqlite:' . $temporario, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $faltavam = count(array_diff(array_keys(migracoes()), migracoesAplicadas($pdo)));
        migrar($pdo);
        conferirBancoPreparado($pdo);
        if (strtolower((string) $pdo->query('PRAGMA journal_mode = DELETE')->fetchColumn()) !== 'delete') {
            throw new RuntimeException('Não foi possível finalizar o banco preparado.');
        }
        $pdo = null;

        $seguranca = caminhoLivre('pre_import_');
        if (!snapshot(DB_PATH, $seguranca)) {
            throw new RuntimeException('Falha ao criar a cópia de segurança. O banco atual foi preservado.');
        }
        $pdo = new PDO('sqlite:' . $seguranca, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        if ($pdo->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
            throw new RuntimeException('A cópia de segurança não passou na verificação.');
        }
        $pdo = null;

        // O próprio SQLite consolida o WAL e remove os auxiliares, com seus
        // locks nativos. Uma conexão externa que impeça isso faz abortar.
        $pdo = new PDO('sqlite:' . DB_PATH, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        if (strtolower((string) $pdo->query('PRAGMA journal_mode = DELETE')->fetchColumn()) !== 'delete') {
            throw new RuntimeException('O banco ainda está em uso por outra conexão.');
        }
        $pdo = null;
        $permissoes = fileperms(DB_PATH);
        if ($permissoes === false || !@chmod($temporario, $permissoes & 0777)) {
            throw new RuntimeException('Não foi possível preservar as permissões do banco.');
        }
        if (!@rename($temporario, DB_PATH)) {
            throw new RuntimeException('Não foi possível substituir o banco. O original foi preservado; cópia em ' . basename($seguranca) . '.');
        }
        return ['seguranca' => $seguranca, 'migracoes' => $faltavam];
    } finally {
        $pdo = null;
        foreach ([$temporario, "$temporario-wal", "$temporario-shm", "$temporario-journal"] as $arquivo) {
            if (is_file($arquivo)) {
                @unlink($arquivo);
            }
        }
    }
}
