<?php
require __DIR__ . '/lib/boot.php';

$db = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = post('acao');
    $id   = postInt('id');

    if ($acao === 'salvar') {
        $letivo = post('letivo');            // '', '0' ou '1'

        // Prioridade das protegidas não vem do formulário: feriado sempre no
        // topo, fim de semana sempre no piso. O resto fica abaixo dos feriados.
        $atual = null;
        if ($id) {
            $st = $db->prepare('SELECT * FROM categorias WHERE id = ?');
            $st->execute([$id]);
            $atual = $st->fetch() ?: null;
        }
        $prioridade = $atual && (int) $atual['protegida'] === 1
            ? (int) $atual['prioridade']
            : max(1, min(PRIORIDADE_MAX, postInt('prioridade', 50)));

        $dados = [
            post('nome'),
            post('cor', '#ffffff'),
            post('cor_texto', '#000000'),
            $letivo === '' ? null : (int) $letivo,
            $prioridade,
            isset($_POST['na_legenda']) ? 1 : 0,
            postInt('ordem', 0),
        ];
        if ($id) {
            $db->prepare('UPDATE categorias SET nome=?, cor=?, cor_texto=?, letivo=?, prioridade=?, na_legenda=?, ordem=? WHERE id=?')
               ->execute([...$dados, $id]);
            flash('Categoria atualizada.');
        } else {
            $db->prepare('INSERT INTO categorias (nome, cor, cor_texto, letivo, prioridade, na_legenda, ordem) VALUES (?,?,?,?,?,?,?)')
               ->execute($dados);
            flash('Categoria criada.');
        }
        redirect('categorias.php');
    }

    if ($acao === 'excluir') {
        $st = $db->prepare('SELECT protegida FROM categorias WHERE id = ?');
        $st->execute([$id]);
        if ((int) $st->fetchColumn() === 1) {
            flash('Essa categoria é parte do funcionamento do calendário e não pode ser excluída.', 'erro');
        } else {
            $db->prepare('DELETE FROM categorias WHERE id = ?')->execute([$id]);
            flash('Categoria excluída.');
        }
        redirect('categorias.php');
    }
}

$edit = null;
if ($idEdit = getInt('editar')) {
    $st = $db->prepare('SELECT * FROM categorias WHERE id = ?');
    $st->execute([$idEdit]);
    $edit = $st->fetch() ?: null;
}
// A tabela sai por prioridade, do maior para o menor: é a ordem em que uma
// categoria vence a outra na cor do dia. O campo "Ordem na legenda" continua
// mandando na legenda impressa, que é outra coisa.
$cats = $db->query('SELECT * FROM categorias ORDER BY prioridade DESC, nome')->fetchAll();

// O modal já vem aberto quando a página é de edição ou veio do botão "nova".
$abrirModal = $edit !== null || get('novo') !== '';

head('Legenda', 'categorias');
?>
<div class="card border-0 shadow-sm">
  <div class="card-header bg-transparent fw-semibold d-flex justify-content-between align-items-center">
    <span><i class="bi bi-palette me-2 text-primary"></i>Categorias</span>
    <?php if ($edit): ?>
      <a class="btn btn-sm btn-primary" href="categorias.php?novo=1"><i class="bi bi-plus-lg me-1"></i>Nova categoria</a>
    <?php else: ?>
      <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modalCategoria">
        <i class="bi bi-plus-lg me-1"></i>Nova categoria
      </button>
    <?php endif; ?>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr><th style="width:60px">Cor</th><th>Nome</th><th>Letivo</th><th class="text-center">Prior.</th>
              <th class="text-center">Legenda</th><th class="text-end">Ações</th></tr>
        </thead>
        <tbody>
        <?php foreach ($cats as $c): ?>
          <tr>
            <td><span class="amostra" style="background:<?= e($c['cor']) ?>"></span></td>
            <td class="fw-semibold"><?= e($c['nome']) ?>
              <?php if ((int) $c['protegida'] === 1): ?>
                <span class="badge bg-light text-secondary border ms-1"
                      title="<?= (int) $c['oculta'] === 1
                          ? 'O sistema aplica sozinho e não oferece para escolher num evento; prioridade fixa e sem exclusão'
                          : 'O sistema depende dela: prioridade fixa e sem exclusão' ?>">Sistema</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($c['letivo'] === null): ?>
                <span class="badge bg-secondary-subtle text-secondary-emphasis">neutro</span>
              <?php elseif ((int) $c['letivo'] === 1): ?>
                <span class="badge bg-success-subtle text-success-emphasis">sim</span>
              <?php else: ?>
                <span class="badge bg-danger-subtle text-danger-emphasis">não</span>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <?= (int) $c['prioridade'] ?>
              <?= (int) $c['protegida'] === 1 ? ' <i class="bi bi-lock-fill text-muted small" title="Prioridade fixa"></i>' : '' ?>
            </td>
            <td class="text-center"><?= (int) $c['na_legenda'] === 1 ? '<i class="bi bi-check-lg text-success"></i>' : '<span class="text-muted">—</span>' ?></td>
            <td class="text-end text-nowrap">
              <a class="btn btn-sm btn-outline-primary" href="categorias.php?editar=<?= $c['id'] ?>"><i class="bi bi-pencil me-1"></i>Editar</a>
              <?php if ((int) $c['protegida'] === 0): ?>
              <form method="post" class="d-inline" onsubmit="return confirm('Excluir esta categoria?')">
                <input type="hidden" name="acao" value="excluir">
                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                <button class="btn btn-sm btn-outline-danger" title="Excluir"><i class="bi bi-trash"></i></button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="modal fade" id="modalCategoria" tabindex="-1" aria-labelledby="tituloModalCategoria" <?= $abrirModal ? 'data-abrir="1"' : '' ?>>
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <form method="post">
        <div class="modal-header">
          <h5 class="modal-title" id="tituloModalCategoria">
            <i class="bi bi-palette me-2 text-primary"></i><?= $edit ? 'Editando: ' . e($edit['nome']) : 'Nova categoria' ?>
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
        </div>

        <div class="modal-body">
          <input type="hidden" name="acao" value="salvar">
          <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Nome</label>
              <input name="nome" class="form-control" required value="<?= e($edit['nome'] ?? '') ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Cor do fundo</label>
              <input type="color" name="cor" class="form-control form-control-color w-100" value="<?= e($edit['cor'] ?? '#ffff00') ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Cor do texto</label>
              <input type="color" name="cor_texto" class="form-control form-control-color w-100" value="<?= e($edit['cor_texto'] ?? '#000000') ?>">
            </div>
            <div class="col-md-5">
              <label class="form-label">Conta como letivo</label>
              <?php $lv = $edit ? ($edit['letivo'] === null ? '' : (string) $edit['letivo']) : ''; ?>
              <select name="letivo" class="form-select">
                <option value=""  <?= $lv === ''  ? 'selected' : '' ?>>Neutro — o dia da semana decide</option>
                <option value="1" <?= $lv === '1' ? 'selected' : '' ?>>Sim — sempre conta como letivo</option>
                <option value="0" <?= $lv === '0' ? 'selected' : '' ?>>Não — nunca conta como letivo</option>
              </select>
            </div>
            <?php $g_fixa = $edit && (int) $edit['protegida'] === 1; ?>
            <div class="col-md-2">
              <label class="form-label">Prioridade</label>
              <input type="number" name="prioridade" class="form-control" min="1" max="<?= PRIORIDADE_MAX ?>"
                     value="<?= (int) ($edit['prioridade'] ?? 50) ?>" <?= $g_fixa ? 'disabled' : '' ?>>
              <?php if ($g_fixa): ?>
                <div class="form-text">Fixa: esta categoria não disputa a cor do dia.</div>
              <?php endif; ?>
            </div>
            <div class="col-md-3">
              <label class="form-label">Ordem na legenda</label>
              <input type="number" name="ordem" class="form-control" value="<?= (int) ($edit['ordem'] ?? 0) ?>">
            </div>
            <div class="col-md-2 d-flex align-items-center">
              <div class="form-check mt-3">
                <input class="form-check-input" type="checkbox" name="na_legenda" id="na_legenda"
                       <?= ($edit === null || (int) $edit['na_legenda'] === 1) ? 'checked' : '' ?>>
                <label class="form-check-label" for="na_legenda">Na legenda</label>
              </div>
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <?php if ($edit): ?>
            <a class="btn btn-outline-secondary" href="categorias.php">Cancelar</a>
          <?php else: ?>
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <?php endif; ?>
          <button class="btn btn-primary"><i class="bi bi-check-lg me-1"></i><?= $edit ? 'Salvar' : 'Criar' ?></button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php foot(); ?>
