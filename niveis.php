<?php
require __DIR__ . '/lib/boot.php';

$db = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = post('acao');
    $id   = postInt('id');

    if ($acao === 'salvar') {
        $nome = post('nome');
        if ($nome === '') {
            flash('Informe o nome do nível.', 'erro');
            redirect('niveis.php?novo=1');
        }

        if ($id) {
            // A chave fica como está: ela é a referência gravada nos cursos e
            // nos eventos, e trocá-la deixaria os dois apontando para o vazio.
            $db->prepare('UPDATE niveis SET nome=?, ordem=? WHERE id=?')
               ->execute([$nome, postInt('ordem', 0), $id]);
            flash('Nível atualizado.');
        } else {
            $chave = chaveNivel($nome);
            if ($chave === '') {
                flash('O nome precisa ter ao menos uma letra ou número.', 'erro');
                redirect('niveis.php?novo=1');
            }
            $st = $db->prepare('SELECT COUNT(*) FROM niveis WHERE chave = ?');
            $st->execute([$chave]);
            if ((int) $st->fetchColumn() > 0) {
                flash('Já existe um nível com esse nome.', 'erro');
                redirect('niveis.php?novo=1');
            }
            $db->prepare('INSERT INTO niveis (chave, nome, ordem) VALUES (?,?,?)')
               ->execute([$chave, $nome, postInt('ordem', 0)]);
            flash('Nível cadastrado.');
        }
        redirect('niveis.php');
    }

    if ($acao === 'excluir') {
        $st = $db->prepare('SELECT chave, nome FROM niveis WHERE id = ?');
        $st->execute([$id]);
        $nivel = $st->fetch();
        if (!$nivel) {
            redirect('niveis.php');
        }

        // Excluir um nível em uso deixaria cursos órfãos e eventos restritos a
        // um nível que não existe mais — some da tela e do cálculo sem aviso.
        $emUso  = usoDoNivel($db, $nivel['chave']);
        $partes = [];
        if ($emUso['cursos']) {
            $partes[] = $emUso['cursos'] . ' curso' . ($emUso['cursos'] === 1 ? '' : 's');
        }
        if ($emUso['eventos']) {
            $partes[] = $emUso['eventos'] . ' evento' . ($emUso['eventos'] === 1 ? '' : 's');
        }
        if ($partes) {
            flash(
                'O nível "' . $nivel['nome'] . '" está em uso por '
                . implode(' e ', $partes) . ' e não pode ser excluído.',
                'erro'
            );
        } else {
            $db->prepare('DELETE FROM niveis WHERE id = ?')->execute([$id]);
            flash('Nível excluído.');
        }
        redirect('niveis.php');
    }
}

/** Quantos cursos e eventos globais dependem de um nível. */
function usoDoNivel(PDO $db, string $chave): array
{
    $st = $db->prepare('SELECT COUNT(*) FROM cursos WHERE nivel = ?');
    $st->execute([$chave]);
    $cursos = (int) $st->fetchColumn();

    // O evento guarda as chaves separadas por vírgula; as vírgulas nas pontas
    // evitam que "superior" case com "superior_novo".
    $st = $db->prepare("SELECT COUNT(*) FROM eventos WHERE ',' || nivel || ',' LIKE '%,' || ? || ',%'");
    $st->execute([$chave]);

    return ['cursos' => $cursos, 'eventos' => (int) $st->fetchColumn()];
}

$edit = null;
if ($idEdit = getInt('editar')) {
    $st = $db->prepare('SELECT * FROM niveis WHERE id = ?');
    $st->execute([$idEdit]);
    $edit = $st->fetch() ?: null;
}

$niveis = $db->query('SELECT * FROM niveis ORDER BY ordem, nome')->fetchAll();
foreach ($niveis as &$n) {
    $n['uso'] = usoDoNivel($db, $n['chave']);
}
unset($n);

$abrirModal = $edit !== null || get('novo') !== '';

head('Níveis', 'niveis');
?>
<div class="card border-0 shadow-sm">
  <div class="card-header bg-transparent fw-semibold d-flex justify-content-between align-items-center">
    <span><i class="bi bi-diagram-3 me-2 text-primary"></i>Níveis de ensino</span>
    <?php if ($edit): ?>
      <a class="btn btn-sm btn-primary" href="niveis.php?novo=1"><i class="bi bi-plus-lg me-1"></i>Novo nível</a>
    <?php else: ?>
      <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modalNivel">
        <i class="bi bi-plus-lg me-1"></i>Novo nível
      </button>
    <?php endif; ?>
  </div>
  <div class="card-body p-0">
    <?php if (!$niveis): ?>
      <div class="text-center text-muted py-5">
        <i class="bi bi-diagram-3 display-6 d-block mb-2"></i>Nenhum nível cadastrado.
        <div class="mt-3">
          <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalNivel">
            <i class="bi bi-plus-lg me-1"></i>Cadastrar o primeiro nível
          </button>
        </div>
      </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr><th>Nome</th><th class="text-center">Ordem</th>
              <th class="text-center">Em uso</th><th class="text-end">Ações</th></tr>
        </thead>
        <tbody>
        <?php foreach ($niveis as $n): ?>
          <tr>
            <td class="fw-semibold"><?= e($n['nome']) ?></td>
            <td class="text-center"><?= (int) $n['ordem'] ?></td>
            <td class="text-center">
              <?php
              // Só o que existe: evento restrito a nível é a exceção, então na
              // maioria das linhas esse número seria um "0 eventos" à toa.
              $g_partes = [];
              if ($n['uso']['cursos']) {
                  $g_partes[] = $n['uso']['cursos'] . ' curso' . ($n['uso']['cursos'] === 1 ? '' : 's');
              }
              if ($n['uso']['eventos']) {
                  $g_partes[] = $n['uso']['eventos'] . ' evento' . ($n['uso']['eventos'] === 1 ? '' : 's');
              }
              ?>
              <?php if ($g_partes): ?>
                <span class="badge bg-light text-secondary border"><?= e(implode(' · ', $g_partes)) ?></span>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td class="text-end text-nowrap">
              <a class="btn btn-sm btn-outline-primary" href="niveis.php?editar=<?= $n['id'] ?>"><i class="bi bi-pencil me-1"></i>Editar</a>
              <?php if (!$n['uso']['cursos'] && !$n['uso']['eventos']): ?>
              <form method="post" class="d-inline" onsubmit="return confirm('Excluir este nível?')">
                <input type="hidden" name="acao" value="excluir">
                <input type="hidden" name="id" value="<?= $n['id'] ?>">
                <button class="btn btn-sm btn-outline-danger" title="Excluir"><i class="bi bi-trash"></i></button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="modal fade" id="modalNivel" tabindex="-1" aria-labelledby="tituloModalNivel" <?= $abrirModal ? 'data-abrir="1"' : '' ?>>
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post">
        <div class="modal-header">
          <h5 class="modal-title" id="tituloModalNivel">
            <i class="bi bi-diagram-3 me-2 text-primary"></i><?= $edit ? 'Editando: ' . e($edit['nome']) : 'Novo nível' ?>
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
        </div>

        <div class="modal-body">
          <input type="hidden" name="acao" value="salvar">
          <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label">Nome</label>
              <input name="nome" class="form-control" required value="<?= e($edit['nome'] ?? '') ?>"
                     placeholder="Técnico Integrado">
              <?php if ($edit): ?>
                <div class="form-text">Renomear é seguro: os cursos e eventos ligados a ele continuam iguais.</div>
              <?php endif; ?>
            </div>
            <div class="col-md-4">
              <label class="form-label">Ordem</label>
              <input type="number" name="ordem" class="form-control" value="<?= (int) ($edit['ordem'] ?? 0) ?>">
              <div class="form-text">Posição nas listas.</div>
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <?php if ($edit): ?>
            <a class="btn btn-outline-secondary" href="niveis.php">Cancelar</a>
          <?php else: ?>
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <?php endif; ?>
          <button class="btn btn-primary"><i class="bi bi-check-lg me-1"></i><?= $edit ? 'Salvar' : 'Cadastrar' ?></button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php foot(); ?>
