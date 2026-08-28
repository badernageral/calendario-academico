<?php
require __DIR__ . '/lib/boot.php';

$db  = db();
$eu  = (int) (usuarioAtual()['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = post('acao');
    $id   = postInt('id');

    if ($acao === 'salvar') {
        $nome    = post('nome');
        $usuario = post('usuario');
        $senha   = (string) ($_POST['senha'] ?? '');
        // Ninguém se desativa: seria sair pela porta e trancá-la por dentro,
        // sem nem perceber, já que a tela ainda está aberta.
        $ativo   = $id === $eu ? true : isset($_POST['ativo']);
        $volta   = 'usuarios.php' . ($id ? '?editar=' . $id : '?novo=1');

        $erro = match (true) {
            $nome === '' || $usuario === ''      => 'Informe o nome e o usuário.',
            // Na edição, senha vazia quer dizer "não mexe na que já existe";
            // na criação, não há o que manter.
            !$id && $senha === ''                => 'Informe a senha do novo usuário.',
            $senha !== '' && senhaFraca($senha)  => senhaFraca($senha),
            $senha !== '' && $senha !== ($_POST['senha2'] ?? '') => 'A confirmação da senha não confere.',
            $id && !$ativo && outrosUsuariosAtivos($db, $id) === 0
                => 'Este é o único usuário ativo: desativá-lo trancaria o sistema.',
            default => '',
        };
        if ($erro !== '') {
            flash($erro, 'erro');
            redirect($volta);
        }

        try {
            if ($id) {
                salvarUsuario($db, $id, $nome, $usuario, $senha, $ativo);
                // O nome na barra de cima vem da sessão, não do banco: sem isto,
                // quem se renomeia continua vendo o nome antigo até sair.
                if ($id === $eu) {
                    $st = $db->prepare('SELECT * FROM usuarios WHERE id = ?');
                    $st->execute([$id]);
                    $_SESSION['usuario'] = [
                        'id' => $id, 'nome' => $nome, 'usuario' => $usuario,
                    ];
                }
                flash('Usuário atualizado.');
            } else {
                criarUsuario($db, $nome, $usuario, $senha);
                flash('Usuário cadastrado.');
            }
        } catch (PDOException $e) {
            flash('Já existe um usuário com o login "' . $usuario . '".', 'erro');
            redirect($volta);
        }
        redirect('usuarios.php');
    }

    if ($acao === 'excluir') {
        $erro = match (true) {
            $id === $eu                             => 'Você não pode excluir a si mesmo.',
            outrosUsuariosAtivos($db, $id) === 0    => 'Este é o único usuário ativo: excluí-lo trancaria o sistema.',
            default                                 => '',
        };
        if ($erro !== '') {
            flash($erro, 'erro');
        } else {
            $db->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$id]);
            flash('Usuário excluído.');
        }
        redirect('usuarios.php');
    }
}

$edit = null;
if ($idEdit = getInt('editar')) {
    $st = $db->prepare('SELECT * FROM usuarios WHERE id = ?');
    $st->execute([$idEdit]);
    $edit = $st->fetch() ?: null;
}

$usuarios  = $db->query('SELECT * FROM usuarios ORDER BY nome')->fetchAll();
usort($usuarios, static fn ($a, $b) => compararNomes($a['nome'], $b['nome']));

$abrirModal = $edit !== null || get('novo') !== '';
$erroModal  = modalAbrindo() ? erroParaModal() : '';

head('Usuários', 'usuarios');
?>
<div class="card border-0 shadow-sm">
  <div class="card-header bg-transparent fw-semibold d-flex justify-content-between align-items-center">
    <span><i class="bi bi-people me-2 text-primary"></i>Usuários</span>
    <?php if ($edit): ?>
      <a class="btn btn-sm btn-primary" href="usuarios.php?novo=1"><i class="bi bi-plus-lg me-1"></i>Novo usuário</a>
    <?php else: ?>
      <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modalUsuario">
        <i class="bi bi-plus-lg me-1"></i>Novo usuário
      </button>
    <?php endif; ?>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr><th>Nome</th><th>Usuário</th><th class="text-center">Situação</th>
              <th>Desde</th><th class="text-end">Ações</th></tr>
        </thead>
        <tbody>
        <?php foreach ($usuarios as $u): ?>
          <tr>
            <td class="fw-semibold">
              <?= e($u['nome']) ?>
              <?php if ((int) $u['id'] === $eu): ?>
                <span class="badge bg-primary-subtle text-primary-emphasis ms-1">você</span>
              <?php endif; ?>
            </td>
            <td class="text-muted"><?= e($u['usuario']) ?></td>
            <td class="text-center">
              <?php if ((int) $u['ativo'] === 1): ?>
                <span class="badge bg-success-subtle text-success-emphasis">ativo</span>
              <?php else: ?>
                <span class="badge bg-secondary-subtle text-secondary-emphasis">inativo</span>
              <?php endif; ?>
            </td>
            <td class="text-muted small"><?= e(substr((string) $u['criado_em'], 0, 10)) ?></td>
            <td class="text-end text-nowrap">
              <a class="btn btn-sm btn-outline-primary" href="usuarios.php?editar=<?= (int) $u['id'] ?>">
                <i class="bi bi-pencil me-1"></i>Editar
              </a>
              <?php // Nem a si mesmo, nem o último que ainda pode entrar. ?>
              <?php if ((int) $u['id'] !== $eu && outrosUsuariosAtivos($db, (int) $u['id']) > 0): ?>
              <form method="post" class="d-inline" onsubmit="return confirm('Excluir <?= e($u['nome']) ?>?')">
                <?= csrfCampo() ?>
                <input type="hidden" name="acao" value="excluir">
                <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
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
  <div class="card-footer bg-transparent small text-muted">
    Todo usuário vê e altera os mesmos dados — a separação é só na entrada.
  </div>
</div>

<div class="modal fade" id="modalUsuario" tabindex="-1" aria-labelledby="tituloModalUsuario" <?= $abrirModal ? 'data-abrir="1"' : '' ?>>
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post">
        <?= csrfCampo() ?>
        <div class="modal-header">
          <h5 class="modal-title" id="tituloModalUsuario">
            <i class="bi bi-person-gear me-2 text-primary"></i><?= $edit ? 'Editando: ' . e($edit['nome']) : 'Novo usuário' ?>
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
        </div>

        <div class="modal-body">
          <input type="hidden" name="acao" value="salvar">
          <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
          <?php if ($abrirModal && $erroModal !== ''): ?>
            <div class="alert alert-danger" role="alert"><?= e($erroModal) ?></div>
          <?php endif; ?>
          <div class="row g-3">
            <div class="col-md-7">
              <label class="form-label" for="u_nome">Nome</label>
              <input name="nome" id="u_nome" class="form-control" required value="<?= e($edit['nome'] ?? '') ?>">
            </div>
            <div class="col-md-5">
              <label class="form-label" for="u_usuario">Usuário</label>
              <input name="usuario" id="u_usuario" class="form-control" required
                     autocomplete="username" value="<?= e($edit['usuario'] ?? '') ?>">
            </div>

            <div class="col-md-6">
              <label class="form-label" for="u_senha">Senha</label>
              <input type="password" name="senha" id="u_senha" class="form-control"
                     autocomplete="new-password" <?= $edit ? '' : 'required minlength="8"' ?>>
              <div class="form-text">
                <?= $edit ? 'Deixe em branco para manter a senha atual.' : 'Ao menos 8 caracteres.' ?>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="u_senha2">Repita a senha</label>
              <input type="password" name="senha2" id="u_senha2" class="form-control" autocomplete="new-password">
            </div>

            <?php if ($edit && (int) $edit['id'] !== $eu): ?>
            <div class="col-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="ativo" id="u_ativo"
                       <?= (int) $edit['ativo'] === 1 ? 'checked' : '' ?>>
                <label class="form-check-label" for="u_ativo">Ativo</label>
              </div>
              <div class="form-text">
                Desmarcado, o cadastro fica mas o login deixa de funcionar.
              </div>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="modal-footer">
          <?php if ($edit): ?>
            <a class="btn btn-outline-secondary" href="usuarios.php">Cancelar</a>
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
