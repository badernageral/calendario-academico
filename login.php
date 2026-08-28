<?php
require __DIR__ . '/lib/boot.php';

$db = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('acao') === 'entrar') {
    $u = autenticar($db, post('usuario'), (string) ($_POST['senha'] ?? ''));
    if ($u === null) {
        // Uma mensagem só para os três casos — login errado, senha errada e
        // usuário inativo. Dizer qual deles é conta a quem tenta o que ele
        // ainda não sabe.
        flash('Usuário ou senha inválidos.', 'erro');
        redirect('login.php');
    }
    entrar($u);
    redirect('index.php');
}

$titulo    = 'Entrar';
$subtitulo = 'Acesso ao sistema';
ob_start();
?>
<form method="post">
  <?= csrfCampo() ?>
  <input type="hidden" name="acao" value="entrar">
  <div class="mb-3">
    <label class="form-label small fw-semibold" for="usuario">Usuário</label>
    <div class="input-group">
      <span class="input-group-text"><i class="bi bi-person"></i></span>
      <input type="text" name="usuario" id="usuario" class="form-control" required autofocus
             autocomplete="username">
    </div>
  </div>
  <div class="mb-3">
    <label class="form-label small fw-semibold" for="senha">Senha</label>
    <div class="input-group">
      <span class="input-group-text"><i class="bi bi-lock"></i></span>
      <input type="password" name="senha" id="senha" class="form-control" required
             autocomplete="current-password">
    </div>
  </div>
  <button class="btn btn-primary w-100"><i class="bi bi-box-arrow-in-right me-1"></i>Entrar</button>
</form>
<?php
$corpo = (string) ob_get_clean();
require __DIR__ . '/lib/tela_acesso.php';
