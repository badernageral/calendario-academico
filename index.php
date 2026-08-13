<?php
require __DIR__ . '/lib/boot.php';

$db = db();

// O painel fala do ano em foco: o mais recente que já tem calendário, ou o ano
// corrente enquanto não há nenhum — o mesmo que a tela de eventos globais abre.
$anoFoco = (int) ($db->query('SELECT MAX(ano) FROM calendarios')->fetchColumn() ?: 0)
        ?: (int) date('Y');

$num = static fn (string $sql): int => (int) db()->query($sql)->fetchColumn();

$nCursos = $num('SELECT COUNT(*) FROM cursos WHERE ativo = 1');
$nCals   = $num('SELECT COUNT(*) FROM calendarios');
$nBase   = $num("SELECT COUNT(*) FROM eventos WHERE calendario_id IS NULL AND ano = $anoFoco");
// Feriado não é evento de um ano: é cadastro, e vale para todos.
$nFer    = $num('SELECT COUNT(*) FROM feriados WHERE ativo = 1');

$cals = $db->query(
    'SELECT c.*, cu.nome AS curso_nome,
            (SELECT COUNT(*) FROM eventos e WHERE e.calendario_id = c.id) AS n_eventos
     FROM calendarios c JOIN cursos cu ON cu.id = c.curso_id
     ORDER BY c.ano DESC, cu.nome LIMIT 8'
)->fetchAll();

$cartoes = [
    ['Cursos ativos',               $nCursos, 'bi-mortarboard-fill',    'bg-primary', 'cursos.php'],
    ['Calendários',                 $nCals,   'bi-calendar3',           'bg-success', 'calendarios.php'],
    ["Eventos globais em $anoFoco", $nBase,   'bi-calendar-event-fill', 'bg-info',    'eventos.php?ano=' . $anoFoco],
    ['Feriados, em todos os anos',  $nFer,    'bi-flag-fill',           'bg-warning', 'feriados.php'],
];

// Ajuda: o que cada tela é e em que ordem se cadastra. Uma entrada por tela,
// sem repetir destino — o que se faz dentro de um calendário (semestres, metas,
// eventos do curso, geração) está dito na linha dele, não em passos à parte.
$ajuda = [
    ['niveis.php',      'bi-diagram-3',    'text-primary',   'Níveis de ensino',
        'Superior, técnico integrado, concomitante… É o que permite mandar um evento só para certos cursos.'],
    ['cursos.php',      'bi-mortarboard',  'text-success',   'Cursos',
        'Cada curso tem um nível e o nome que sai no título do documento.'],
    ['categorias.php',  'bi-palette',      'text-secondary', 'Legenda',
        'As cores do calendário e o efeito de cada uma na conta dos dias letivos.'],
    ['feriados.php',    'bi-flag',         'text-danger',    'Feriados',
        'Cadastro único, válido em todos os anos. Os móveis andam com a Páscoa sozinhos.'],
    ['eventos.php',     'bi-globe',        'text-info',      'Eventos globais',
        'Recessos, planejamento e prazos de um ano, valendo para todos os cursos.'],
    ['calendarios.php', 'bi-calendar3',    'text-warning',   'Calendários',
        'Um por curso e ano. Dentro dele ficam os semestres, as metas, os eventos do curso e a geração do PDF.'],
    ['backup.php',      'bi-shield-check', 'text-dark',      'Backup',
        'Uma cópia do banco inteiro — vale baixar antes de homologar o ano.'],
];

head('Painel', 'painel');
?>

<div class="row g-3 mb-4">
  <?php foreach ($cartoes as [$rot, $val, $ico, $bg, $url]): ?>
  <div class="col-6 col-xl-3">
    <a href="<?= e($url) ?>" class="card stat-card border-0 shadow-sm h-100 text-decoration-none">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="stat-icon <?= $bg ?> text-white fs-4"><i class="bi <?= $ico ?>"></i></div>
        <div>
          <div class="fw-bold fs-4 text-dark"><?= $val ?></div>
          <div class="text-muted small"><?= e($rot) ?></div>
        </div>
      </div>
    </a>
  </div>
  <?php endforeach; ?>
</div>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-transparent fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="bi bi-calendar3 me-2 text-primary"></i>Calendários</span>
        <?php if ($cals): /* sem nenhum, quem convida é o botão do estado vazio */ ?>
          <a href="calendarios.php" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Novo</a>
        <?php endif; ?>
      </div>
      <div class="card-body p-0">
        <?php if (!$cals): ?>
          <div class="text-center text-muted py-5">
            <i class="bi bi-calendar-x display-6 d-block mb-2"></i>
            Nenhum calendário cadastrado ainda.
            <div class="mt-3">
              <a href="<?= $nCursos ? 'calendarios.php' : 'cursos.php' ?>" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-lg me-1"></i><?= $nCursos ? 'Criar calendário' : 'Cadastrar curso' ?>
              </a>
            </div>
          </div>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
              <tr><th>Curso</th><th>Ano</th><th>Situação</th><th class="text-center">Eventos</th><th class="text-end">Ações</th></tr>
            </thead>
            <tbody>
            <?php foreach ($cals as $c): ?>
              <tr>
                <td class="fw-semibold"><?= e($c['curso_nome']) ?></td>
                <td><?= (int) $c['ano'] ?></td>
                <td><span class="badge bg-light text-secondary border"><?= e($c['situacao']) ?></span></td>
                <td class="text-center"><?= (int) $c['n_eventos'] ?></td>
                <td class="text-end text-nowrap">
                  <a class="btn btn-sm btn-outline-secondary" href="editar_calendario.php?id=<?= $c['id'] ?>" title="Dados e semestres"><i class="bi bi-pencil"></i></a>
                  <a class="btn btn-sm btn-outline-primary" href="calendario.php?id=<?= $c['id'] ?>" title="Gerenciar eventos"><i class="bi bi-grid-3x3"></i></a>
                  <a class="btn btn-sm btn-outline-dark" href="gerar.php?id=<?= $c['id'] ?>" target="_blank" title="Gerar"><i class="bi bi-printer"></i></a>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-transparent fw-semibold">
        <i class="bi bi-question-circle-fill me-2 text-primary"></i>Ajuda: a ordem do cadastro
      </div>
      <div class="card-body p-0">
        <?php foreach ($ajuda as $i => [$url, $ico, $cor, $titulo, $texto]): ?>
        <a href="<?= e($url) ?>" class="d-flex align-items-start gap-3 px-3 py-2 text-decoration-none border-bottom quick-step">
          <span class="badge rounded-pill bg-light text-dark border fw-semibold mt-1"
                style="width:24px;height:24px;line-height:16px;font-size:11px"><?= $i + 1 ?></span>
          <i class="bi <?= $ico ?> <?= $cor ?> fs-5"></i>
          <span>
            <span class="small fw-semibold text-dark d-block"><?= e($titulo) ?></span>
            <span class="small text-muted"><?= e($texto) ?></span>
          </span>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<?php foot(); ?>
