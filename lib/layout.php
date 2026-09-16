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
        ['usuarios.php',      'Usuários',        'bi-people',         'usuarios'],
        ['backup.php',        'Backup',          'bi-shield-check',   'backup'],
        ['atualizacoes.php',  'Atualizações',    'bi-arrow-repeat',   'atualizacoes'],
    ];
    $atualizacao = atualizacaoDisponivel();
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
        <?php if ($chave === 'atualizacoes' && $atualizacao): ?>
          <i class="bi bi-circle-fill text-warning ms-1" style="font-size:.4rem;vertical-align:middle" title="Nova versão disponível"></i>
        <?php endif; ?>
      </a>
    </li>
    <?php endforeach; ?>
  </ul>

  <div class="px-3 py-2 mt-auto sidebar-footer small">
    Calendário Acadêmico &bull; v<?= e(APP_VERSION) ?>
    <?php if ($atualizacao): ?>
      <a href="atualizacoes.php" class="d-block mt-1 text-decoration-none" title="Ver detalhes da atualização">
        <i class="bi bi-arrow-up-circle-fill me-1"></i>Nova versão v<?= e($atualizacao['versao_disponivel']) ?>
      </a>
    <?php endif; ?>
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
    <div class="d-flex align-items-center gap-3">
      <?php if ($atualizacao): ?>
        <a href="atualizacoes.php" class="small text-warning-emphasis text-decoration-none" title="Nova versão v<?= e($atualizacao['versao_disponivel']) ?> disponível">
          <i class="bi bi-arrow-up-circle-fill me-1"></i><span class="d-none d-md-inline">Nova versão disponível</span>
        </a>
      <?php endif; ?>
      <?php if (cfg('campus') !== ''): ?>
        <span class="small text-muted d-none d-md-inline"><i class="bi bi-building me-1"></i><?= e(cfg('campus')) ?></span>
      <?php endif; ?>
      <?php $u = usuarioAtual(); if ($u): ?>
        <?php // Sair por POST, com token: um GET numa página aberta em outra aba
              // derrubaria a sessão de quem nem clicou. ?>
        <form method="post" action="sair.php" class="d-flex align-items-center gap-2 mb-0">
          <?= csrfCampo() ?>
          <span class="small text-muted text-nowrap" title="<?= e($u['usuario']) ?>">
            <i class="bi bi-person-circle me-1"></i><?= e($u['nome']) ?>
          </span>
          <button class="btn btn-sm btn-outline-secondary border-0" title="Sair">
            <i class="bi bi-box-arrow-right"></i>
          </button>
        </form>
      <?php endif; ?>
    </div>
  </nav>

  <div class="content-wrapper p-3 p-lg-4">
<?php
    $f = flash();
    // O rodapé precisa saber: com um erro na tela, a rolagem não é restaurada —
    // a mensagem fica no topo, e voltar para o meio da página a esconderia
    // justamente quando ela é o que explica por que o modal reabriu.
    $GLOBALS['flash_erro'] = $f !== null && $f['tipo'] === 'erro';
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

/**
 * A rolagem sobrevive à ida e volta do modal.
 *
 * Abrir um evento ou um feriado da lista não é abrir uma caixa em cima da
 * página: é carregar a página de novo com o modal montado pronto, e salvar é um
 * POST que redireciona para ela outra vez. Documento novo começa no topo — quem
 * estava editando outubro voltava em janeiro e tinha de rolar de volta a cada
 * evento.
 *
 * Só se guarda o que volta para esta mesma tela: um link para outro pathname é
 * o menu lateral, e restaurar a posição de uma tela em outra não faria sentido.
 * O valor é lido uma vez e apagado, para uma volta pelo histórico não herdar a
 * posição de uma navegação anterior.
 *
 * A exceção é a tela que voltou com erro: aí a página fica no topo, que é onde
 * está a mensagem dizendo o que impediu de gravar.
 *
 * A volta é 'instant' de propósito. O Bootstrap declara `scroll-behavior:
 * smooth` no :root, e com ela um scrollTo comum vira animação: a página
 * aparecia no topo e descia deslizando até o lugar, toda vez, que é pior de
 * olhar do que o salto que isto veio consertar. 'auto' não serve — obedeceria à
 * regra do CSS; 'instant' é o que a ignora.
 */
(function () {
  var busca = new URLSearchParams(location.search);
  var chave = 'rolagem:' + location.pathname + ':' + (busca.get('id') || busca.get('ano') || '');

  var guardado = sessionStorage.getItem(chave);
  if (guardado !== null) {
    sessionStorage.removeItem(chave);
    if (!<?= !empty($GLOBALS['flash_erro']) ? 'true' : 'false' ?>) {
      window.scrollTo({ top: parseInt(guardado, 10) || 0, behavior: 'instant' });
    }
  }

  function guardar() {
    sessionStorage.setItem(chave, String(window.scrollY));
  }

  document.addEventListener('click', function (ev) {
    var a = ev.target.closest('a[href]');
    if (a && a.pathname === location.pathname) {
      guardar();
    }
  });
  // O submit borbulha: pega o formulário do modal, o × de excluir e as caixas
  // de filtro, que também recarregam a tela.
  document.addEventListener('submit', guardar);
})();

/**
 * Dropdown de cor: a linha clicada leva a cor e o nome para o botão do campo, e
 * o id para o campo escondido, que é o que vai no POST.
 *
 * Fica aqui, e não dentro de um dos formulários, porque as telas de calendário
 * e de eventos globais têm dois — o de categoria do evento e o de tipo do
 * feriado — e cada formulário registrando o seu ligaria só o primeiro da página.
 * Nada aqui usa id: tudo se resolve dentro da própria caixa.
 */
(function () {
  document.querySelectorAll('.seletor-categoria').forEach(function (caixa) {
    var botao  = caixa.querySelector('[data-bs-toggle="dropdown"]'),
        campo  = caixa.querySelector('input[type="hidden"]'),
        quadro = botao.querySelector('.retangulo-cor'),
        rotulo = botao.querySelector('.rotulo');

    caixa.querySelectorAll('.dropdown-item').forEach(function (item) {
      item.addEventListener('click', function () {
        var cor = item.dataset.cor || '';

        campo.value        = item.dataset.valor || '';
        // data-efeito é opcional: o seletor de tipo de feriado não tem, e aí o
        // rótulo continua sendo só o nome.
        rotulo.textContent = item.dataset.nome
            + (item.dataset.efeito ? ' — ' + item.dataset.efeito : '');
        quadro.style.background = cor;
        quadro.classList.toggle('sem-cor', cor === '');

        caixa.querySelectorAll('.dropdown-item').forEach(function (a) {
          a.classList.remove('active');
          a.setAttribute('aria-selected', 'false');
        });
        item.classList.add('active');
        item.setAttribute('aria-selected', 'true');
      });
    });
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
