<?php
require __DIR__ . '/lib/boot.php';

$db     = db();
$niveis = niveisCurso();   // cadastrados em niveis.php

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = post('acao');
    $id   = postInt('id');

    if ($acao === 'salvar') {
        $dados = [
            maiusculas(post('nome')),
            post('nivel', (string) array_key_first($niveis)),
            isset($_POST['ativo']) ? 1 : 0,
        ];
        if ($id) {
            $st = $db->prepare('UPDATE cursos SET nome=?, nivel=?, ativo=? WHERE id=?');
            $st->execute([...$dados, $id]);
            flash('Curso atualizado.');
        } else {
            $db->prepare('INSERT INTO cursos (nome, nivel, ativo) VALUES (?,?,?)')->execute($dados);
            flash('Curso cadastrado.');
        }
        redirect('cursos.php');
    }

    if ($acao === 'excluir') {
        $db->prepare('DELETE FROM cursos WHERE id = ?')->execute([$id]);
        flash('Curso excluído.');
        redirect('cursos.php');
    }
}

$edit = null;
if ($idEdit = getInt('editar')) {
    $st = $db->prepare('SELECT * FROM cursos WHERE id = ?');
    $st->execute([$idEdit]);
    $edit = $st->fetch() ?: null;
}
$cursos = $db->query('SELECT c.*, (SELECT COUNT(*) FROM calendarios k WHERE k.curso_id=c.id) n FROM cursos c ORDER BY ativo DESC, nome')->fetchAll();

// O modal já vem aberto quando a página é de edição ou veio do botão "novo".
$abrirModal = $edit !== null || get('novo') !== '';

head('Cursos', 'cursos');
?>

<div class="card border-0 shadow-sm">
  <div class="card-header bg-transparent fw-semibold d-flex justify-content-between align-items-center">
    <span><i class="bi bi-mortarboard me-2 text-primary"></i>Cursos</span>
    <?php if ($edit): ?>
      <a class="btn btn-sm btn-primary" href="cursos.php?novo=1"><i class="bi bi-plus-lg me-1"></i>Novo curso</a>
    <?php else: ?>
      <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modalCurso">
        <i class="bi bi-plus-lg me-1"></i>Novo curso
      </button>
    <?php endif; ?>
  </div>
  <div class="card-body p-0">
    <?php if (!$cursos): ?>
      <div class="text-center text-muted py-5">
        <i class="bi bi-mortarboard display-6 d-block mb-2"></i>Nenhum curso cadastrado.
        <div class="mt-3">
          <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalCurso">
            <i class="bi bi-plus-lg me-1"></i>Cadastrar o primeiro curso
          </button>
        </div>
      </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr><th>Nome</th><th>Nível</th><th class="text-center">Calendários</th><th class="text-end">Ações</th></tr>
        </thead>
        <tbody>
        <?php foreach ($cursos as $c): ?>
          <tr<?= (int) $c['ativo'] === 0 ? ' class="opacity-50"' : '' ?>>
            <td class="fw-semibold"><?= e($c['nome']) ?>
              <?= (int) $c['ativo'] === 0 ? '<span class="badge bg-light text-secondary border ms-1">inativo</span>' : '' ?>
            </td>
            <td><span class="badge bg-light text-secondary border"><?= e($niveis[$c['nivel']] ?? $c['nivel']) ?></span></td>
            <td class="text-center"><?= (int) $c['n'] ?></td>
            <td class="text-end text-nowrap">
              <a class="btn btn-sm btn-outline-primary" href="cursos.php?editar=<?= $c['id'] ?>"><i class="bi bi-pencil me-1"></i>Editar</a>
              <form method="post" class="d-inline" onsubmit="return confirm('Excluir o curso apaga também seus calendários. Continuar?')">
                <input type="hidden" name="acao" value="excluir">
                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                <button class="btn btn-sm btn-outline-danger" title="Excluir"><i class="bi bi-trash"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="modal fade" id="modalCurso" tabindex="-1" aria-labelledby="tituloModalCurso" <?= $abrirModal ? 'data-abrir="1"' : '' ?>>
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <form method="post">
        <div class="modal-header">
          <h5 class="modal-title" id="tituloModalCurso">
            <i class="bi bi-mortarboard me-2 text-primary"></i><?= $edit ? 'Editando: ' . e($edit['nome']) : 'Novo curso' ?>
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
        </div>

        <div class="modal-body">
          <input type="hidden" name="acao" value="salvar">
          <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label">Nome do curso</label>
              <input name="nome" class="form-control" required value="<?= e($edit['nome'] ?? '') ?>"
                     placeholder="SUPERIOR EM ENGENHARIA AGRONÔMICA">
              <div class="form-text">Entra no título como “CALENDÁRIO DO CURSO <em>nome</em> ANO”.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Nível</label>
              <select name="nivel" class="form-select">
                <?php foreach ($niveis as $k => $v): ?>
                  <option value="<?= $k ?>" <?= ($edit['nivel'] ?? '') === $k ? 'selected' : '' ?>><?= $v ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">
                Permite direcionar um evento global a um nível só. A lista se cadastra em
                <a href="niveis.php">Níveis</a>.
              </div>
            </div>
            <div class="col-md-6 d-flex align-items-center">
              <div class="form-check mt-3">
                <input class="form-check-input" type="checkbox" name="ativo" id="ativo"
                       <?= ($edit === null || (int) $edit['ativo'] === 1) ? 'checked' : '' ?>>
                <label class="form-check-label" for="ativo">Curso ativo</label>
              </div>
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <?php if ($edit): ?>
            <a class="btn btn-outline-secondary" href="cursos.php">Cancelar</a>
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
