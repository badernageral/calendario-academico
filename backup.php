<?php
require __DIR__ . '/lib/boot.php';

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
        mkdir(DIR_BACKUPS, 0775, true);
    }
    $bloqueio = DIR_BACKUPS . '/.htaccess';
    if (!is_file($bloqueio)) {
        file_put_contents($bloqueio, "Require all denied\n");
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

// ── Exportar: gera o snapshot, guarda em backups/ e manda baixar ────────────
// Por POST, e não por link: a exportação grava um arquivo em backups/, e um
// GET desses qualquer página aberta em outra aba dispara — ela não lê a
// resposta, mas enche a pasta assim mesmo. Sendo POST, o token conferido em
// lib/boot.php vale também para cá.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('acao') === 'exportar') {
    // O arquivo não existia um instante atrás, então o unlink de baixo só
    // alcança o que este pedido criou.
    $arquivo = caminhoLivre('calendario_backup_');
    if (!snapshot(DB_PATH, $arquivo)) {
        @unlink($arquivo);
        flash('Falha ao gerar o backup do banco.', 'erro');
        redirect('backup.php');
    }

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($arquivo) . '"');
    header('Content-Length: ' . filesize($arquivo));
    readfile($arquivo);
    exit;
}

// ── Importar: substitui o banco atual pelo arquivo enviado ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('acao') === 'importar') {
    $up = $_FILES['arquivo'] ?? null;
    if (!$up || $up['error'] !== UPLOAD_ERR_OK) {
        flash('Selecione um arquivo .sqlite válido para importar.', 'erro');
        redirect('backup.php');
    }
    if (!preg_match('/\.sqlite$/i', (string) $up['name'])) {
        flash('O arquivo deve ter a extensão .sqlite.', 'erro');
        redirect('backup.php');
    }
    // Confere antes de tocar no banco atual: arquivo corrompido, de outro
    // sistema ou de outra versão derrubaria a aplicação inteira.
    if (($motivo = motivoParaRecusar($up['tmp_name'])) !== '') {
        flash($motivo, 'erro');
        redirect('backup.php');
    }

    // Rede de segurança: o estado atual vira um backup antes de ser sobrescrito.
    $seguranca = caminhoLivre('pre_import_');
    snapshot(DB_PATH, $seguranca);

    if (!copy($up['tmp_name'], DB_PATH)) {
        flash('Falha ao gravar o banco importado.', 'erro');
        redirect('backup.php');
    }
    // O WAL antigo é de outro banco: deixado para trás, corromperia o novo.
    @unlink(DB_PATH . '-wal');
    @unlink(DB_PATH . '-shm');

    // As migrações que faltam entram agora: o arquivo pode ser de uma versão
    // anterior a esta, e quem importou tem de sair daqui com o banco em dia.
    // A conexão aberta ainda aponta para o arquivo que foi substituído, então é
    // uma nova, direto no caminho do banco.
    $pdo = new PDO('sqlite:' . DB_PATH, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $faltavam = count(array_diff(array_keys(migracoes()), migracoesAplicadas($pdo)));
    migrar($pdo);
    $pdo = null;

    flash('Backup importado' . ($faltavam ? ", com $faltavam migração(ões) aplicada(s)" : '')
        . '. O estado anterior ficou guardado em backups/' . basename($seguranca) . '.');
    redirect('backup.php');
}

// Os dez mais recentes bastam na tela; o resto continua na pasta.
$arquivos = [];
foreach (glob(DIR_BACKUPS . '/*.sqlite') ?: [] as $f) {
    $arquivos[] = ['nome' => basename($f), 'tamanho' => (int) filesize($f), 'data' => (int) filemtime($f)];
}
usort($arquivos, static fn ($a, $b) => $b['data'] <=> $a['data']);
$arquivos = array_slice($arquivos, 0, 10);

$tamanhoBanco = is_file(DB_PATH) ? (int) filesize(DB_PATH) : 0;
$emKb = static fn (int $bytes): string => number_format($bytes / 1024, 1, ',', '.') . ' KB';

head('Backup', 'backup');
?>
<div class="row g-3">
  <div class="col-md-6">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-transparent fw-semibold">
        <i class="bi bi-download me-1 text-primary"></i>Exportar backup
      </div>
      <div class="card-body d-flex flex-column">
        <p class="small text-muted">
          Gera uma cópia consistente do banco (estrutura e dados), guarda em
          <code>backups/</code> e baixa o arquivo <code>.sqlite</code>.
        </p>
        <form method="post" class="mt-auto">
          <?= csrfCampo() ?>
          <input type="hidden" name="acao" value="exportar">
          <button class="btn btn-primary w-100">
            <i class="bi bi-download me-1"></i>Baixar backup agora
          </button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-md-6">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-transparent fw-semibold">
        <i class="bi bi-upload me-1 text-primary"></i>Importar backup
      </div>
      <div class="card-body">
        <div class="alert alert-warning small py-2">
          <i class="bi bi-exclamation-triangle me-1"></i>
          A importação <strong>substitui todos os dados atuais</strong> pelo conteúdo do arquivo.
          Por segurança, o estado atual é guardado em <code>backups/</code> antes de sobrescrever.
        </div>
        <form method="post" enctype="multipart/form-data"
              onsubmit="return confirm('Isto vai SUBSTITUIR todos os dados atuais pelo arquivo enviado. Continuar?')">
          <?= csrfCampo() ?>
          <input type="hidden" name="acao" value="importar">
          <div class="mb-3">
            <label class="form-label">Arquivo de backup (.sqlite)</label>
            <input type="file" name="arquivo" accept=".sqlite" class="form-control" required>
          </div>
          <button class="btn btn-danger"><i class="bi bi-upload me-1"></i>Importar e substituir</button>
        </form>
      </div>
    </div>
  </div>
</div>

<div class="card border-0 shadow-sm mt-3">
  <div class="card-header bg-transparent fw-semibold">
    <i class="bi bi-clock-history me-1 text-primary"></i>Backups recentes em <code>backups/</code>
  </div>
  <div class="card-body p-0">
    <?php if (!$arquivos): ?>
      <div class="text-center text-muted py-5">
        <i class="bi bi-archive display-6 d-block mb-2"></i>Nenhum backup gerado ainda.
      </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr><th>Arquivo</th><th class="text-end">Tamanho</th><th>Data</th></tr>
        </thead>
        <tbody>
        <?php foreach ($arquivos as $a): ?>
          <tr>
            <td><code><?= e($a['nome']) ?></code>
              <?= str_starts_with($a['nome'], 'pre_import_')
                  ? '<span class="badge bg-light text-secondary border ms-1">antes de uma importação</span>' : '' ?>
            </td>
            <td class="text-end"><?= $emKb($a['tamanho']) ?></td>
            <td class="text-muted"><?= date('d/m/Y H:i', $a['data']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php foot(); ?>
