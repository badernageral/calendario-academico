<?php
require __DIR__ . '/lib/boot.php';

$db = db();

// O portão em boot.php já manda para cá quando não há usuário nenhum, e daqui
// para o login quando há. Esta conferência é a que impede um POST direto de
// criar um segundo usuário por este caminho, que não pede senha de ninguém.
if (!semUsuarios($db)) {
    redirect('login.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('acao') === 'criar') {
    $nome    = post('nome');
    $usuario = post('usuario');
    $senha   = (string) ($_POST['senha'] ?? '');

    $erro = match (true) {
        $nome === '' || $usuario === ''     => 'Informe o nome e o usuário.',
        $senha === ''                       => 'Informe a senha.',
        $senha !== ($_POST['senha2'] ?? '') => 'A confirmação da senha não confere.',
        default                             => '',
    };
    if ($erro !== '') {
        flash($erro, 'erro');
        redirect('setup.php');
    }

    entrar(criarUsuario($db, $nome, $usuario, $senha));
    flash('Usuário criado. Bem-vindo!');
    redirect('index.php');
}

$titulo    = 'Primeiro acesso';
$subtitulo = 'Primeiro acesso';
ob_start();
?>
<p class="text-muted small mb-3">
  <i class="bi bi-info-circle me-1"></i>
  Ainda não há usuário nenhum. Crie o primeiro para começar — ele terá acesso a
  tudo, e esta tela não aparece de novo.
</p>
<form method="post">
  <?= csrfCampo() ?>
  <input type="hidden" name="acao" value="criar">
  <div class="mb-3">
    <label class="form-label small fw-semibold" for="nome">Nome</label>
    <div class="input-group">
      <span class="input-group-text"><i class="bi bi-person-badge"></i></span>
      <input type="text" name="nome" id="nome" class="form-control" required autofocus
             placeholder="Seu nome">
    </div>
  </div>
  <div class="mb-3">
    <label class="form-label small fw-semibold" for="usuario">Usuário</label>
    <div class="input-group">
      <span class="input-group-text"><i class="bi bi-person"></i></span>
      <input type="text" name="usuario" id="usuario" class="form-control" required
             autocomplete="username" placeholder="ex.: admin">
    </div>
  </div>
  <div class="mb-3">
    <label class="form-label small fw-semibold" for="senha">Senha</label>
    <div class="input-group">
      <span class="input-group-text"><i class="bi bi-lock"></i></span>
      <input type="password" name="senha" id="senha" class="form-control" required
             autocomplete="new-password">
    </div>
  </div>
  <div class="mb-3">
    <label class="form-label small fw-semibold" for="senha2">Repita a senha</label>
    <div class="input-group">
      <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
      <input type="password" name="senha2" id="senha2" class="form-control" required
             autocomplete="new-password">
    </div>
  </div>
  <button class="btn btn-primary w-100"><i class="bi bi-check-lg me-1"></i>Criar e entrar</button>
</form>
<?php
$corpo = (string) ob_get_clean();
require __DIR__ . '/lib/tela_acesso.php';
