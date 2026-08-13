<?php
declare(strict_types=1);

function head(string $titulo, string $ativo = ''): void
{
    // Na ordem de uso: o painel, o que se cadastra no dia a dia e, no fim, o
    // que se mexe de vez em quando.
    $menu = [
        ['index.php',         'Painel',          'bi-speedometer2',   'painel'],
        ['calendarios.php',   'Calendários',     'bi-calendar3',      'calendarios'],
        ['eventos.php',       'Eventos globais', 'bi-globe',          'base'],
        ['feriados.php',      'Feriados',        'bi-flag',           'feriados'],
        ['cursos.php',        'Cursos',          'bi-mortarboard',    'cursos'],
        ['niveis.php',        'Níveis',          'bi-diagram-3',      'niveis'],
        ['categorias.php',    'Legenda',         'bi-palette',        'categorias'],
        ['configuracoes.php', 'Configurações',   'bi-gear',           'configuracoes'],
        ['backup.php',        'Backup',          'bi-shield-check',   'backup'],
    ];
    ?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titulo) ?> · Calendário Acadêmico</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📅</text></svg>">
<link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
<link href="assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
<!-- A versão é a data do arquivo: sem ela, uma mudança de cor ou de estilo só
     aparece depois que o navegador resolve largar a folha que tem em cache. -->
<link href="assets/app.css?v=<?= (int) @filemtime(APP_ROOT . '/assets/app.css') ?>" rel="stylesheet">
</head>
<body>

<div class="d-flex" id="wrapper">
<nav id="sidebar" class="d-flex flex-column flex-shrink-0 p-0">
  <a href="index.php" class="sidebar-brand d-flex align-items-center px-3 py-3 text-decoration-none">
    <i class="bi bi-calendar2-week-fill me-2 fs-4"></i>
    <span class="fw-bold" style="line-height:1.1">Calendário<br>Acadêmico</span>
  </a>
  <hr class="sidebar-divider m-0">

  <ul class="nav flex-column px-2 mt-2 flex-grow-1">
    <?php foreach ($menu as [$url, $rot, $ico, $chave]): ?>
    <li class="nav-item">
      <a href="<?= $url ?>" class="nav-link <?= $ativo === $chave ? 'active' : '' ?>">
        <i class="bi <?= $ico ?> me-2"></i> <span><?= e($rot) ?></span>
      </a>
    </li>
    <?php endforeach; ?>
  </ul>

  <div class="px-3 py-2 mt-auto sidebar-footer small">
    Calendário Acadêmico &bull; v1.0
  </div>
</nav>

<div id="page-content" class="flex-grow-1">
  <nav class="navbar topbar px-3 py-2 d-flex justify-content-between align-items-center">
    <div class="d-flex align-items-center gap-2">
      <button class="btn btn-sm btn-outline-secondary border-0" id="alternar" type="button" title="Recolher menu">
        <i class="bi bi-list fs-5"></i>
      </button>
      <span class="navbar-brand mb-0 fw-semibold text-dark"><?= e($titulo) ?></span>
    </div>
    <?php if (cfg('campus') !== ''): ?>
      <span class="small text-muted d-none d-md-inline"><i class="bi bi-building me-1"></i><?= e(cfg('campus')) ?></span>
    <?php endif; ?>
  </nav>

  <div class="content-wrapper p-3 p-lg-4">
<?php
    $f = flash();
    if ($f) {
        $classe = $f['tipo'] === 'erro' ? 'danger' : 'success';
        $icone  = $f['tipo'] === 'erro' ? 'exclamation-triangle-fill' : 'check-circle-fill';
        echo '<div class="alert alert-' . $classe . ' alert-dismissible fade show d-flex align-items-center" role="alert">'
           . '<i class="bi bi-' . $icone . ' me-2"></i>' . e($f['msg'])
           . '<button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button></div>';
    }
}

function foot(): void
{
    ?>
  </div><!-- /.content-wrapper -->
</div><!-- /#page-content -->
</div><!-- /#wrapper -->

<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script>
// Menu recolhido: a escolha fica guardada no próprio navegador.
(function () {
  var barra = document.getElementById('sidebar');
  if (localStorage.getItem('menu') === 'estreito') { barra.classList.add('collapsed'); }
  document.getElementById('alternar').addEventListener('click', function () {
    barra.classList.toggle('collapsed');
    localStorage.setItem('menu', barra.classList.contains('collapsed') ? 'estreito' : 'largo');
  });
})();

// Modal que já nasce aberto: o servidor marca data-abrir quando a página vem de
// "editar" ou do botão "novo", com o formulário montado pronto.
(function () {
  var modal = document.querySelector('.modal[data-abrir]');
  if (!modal) { return; }
  new bootstrap.Modal(modal).show();
  modal.addEventListener('shown.bs.modal', function () {
    var campo = modal.querySelector('input:not([type=hidden]):not([type=checkbox]), select');
    if (campo) { campo.focus(); }
  });
})();
</script>
</body>
</html>
<?php
}
