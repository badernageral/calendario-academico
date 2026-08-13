<?php
require __DIR__ . '/lib/boot.php';

const DIR_BACKUPS = APP_ROOT . '/backups';

/** Tabelas que todo banco desta aplicação tem — serve de conferência na importação. */
const TABELAS_ESPERADAS = ['config', 'cursos', 'calendarios', 'categorias', 'eventos', 'evento_datas'];

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

/** Confere se o arquivo enviado é mesmo um banco desta aplicação. */
function bancoValido(string $caminho): bool
{
    try {
        $pdo = new PDO('sqlite:' . $caminho);
        if ($pdo->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
            return false;
        }
        $tabelas = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
        $pdo = null;
    } catch (Throwable $e) {
        return false;
    }
    return !array_diff(TABELAS_ESPERADAS, $tabelas);
}

// ── Exportar: gera o snapshot, guarda em backups/ e manda baixar ────────────
if (get('acao') === 'exportar') {
    $arquivo = dirBackups() . '/calendario_backup_' . date('Ymd_His') . '.sqlite';
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
    // Confere antes de tocar no banco atual: arquivo corrompido ou de outro
    // sistema derrubaria a aplicação inteira.
    if (!bancoValido($up['tmp_name'])) {
        flash('O arquivo enviado não é um banco de calendário válido.', 'erro');
        redirect('backup.php');
    }

    // Rede de segurança: o estado atual vira um backup antes de ser sobrescrito.
    $seguranca = dirBackups() . '/pre_import_' . date('Ymd_His') . '.sqlite';
    snapshot(DB_PATH, $seguranca);

    if (!copy($up['tmp_name'], DB_PATH)) {
        flash('Falha ao gravar o banco importado.', 'erro');
        redirect('backup.php');
    }
    // O WAL antigo é de outro banco: deixado para trás, corromperia o novo.
    @unlink(DB_PATH . '-wal');
    @unlink(DB_PATH . '-shm');

    flash('Backup importado. O estado anterior ficou guardado em backups/' . basename($seguranca) . '.');
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
        <a href="backup.php?acao=exportar" class="btn btn-primary mt-auto">
          <i class="bi bi-download me-1"></i>Baixar backup agora
        </a>
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
