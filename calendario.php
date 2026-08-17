<?php
require __DIR__ . '/lib/boot.php';
require __DIR__ . '/lib/eventos_crud.php';

$db = db();
$id = getInt('id') ?: postInt('cal_id', 0);

$st = $db->prepare(
    'SELECT c.*, cu.nome AS curso_nome, cu.nivel AS curso_nivel
     FROM calendarios c JOIN cursos cu ON cu.id = c.curso_id WHERE c.id = ?'
);
$st->execute([$id]);
$cal = $st->fetch();
if (!$cal) {
    flash('Calendário não encontrado.', 'erro');
    redirect('calendarios.php');
}
$ano = (int) $cal['ano'];

// Caixas "Feriados" e "Eventos globais": filtro só de exibição da grade, que
// nasce com as duas marcadas. Desmarcadas, sobra à vista o que é deste
// calendário. Como caixa desmarcada não é enviada, "filtros=1" é a marca de que
// a resposta veio do formulário — sem ela, é a primeira entrada na tela.
$filtrou     = get('filtros') === '1';
$verFeriados = !$filtrou || get('feriados') === '1';
$verGlobais  = !$filtrou || get('globais') === '1';

// Vai em toda volta ao calendário (salvar, cancelar, editar) para as caixas
// continuarem como estavam.
$voltarPara = 'calendario.php?id=' . $id
    . '&filtros=1&feriados=' . ($verFeriados ? '1' : '0')
    . '&globais=' . ($verGlobais ? '1' : '0');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tratarPostEvento($db, $ano, $id, $voltarPara);
}

$cats   = $db->query('SELECT * FROM categorias ORDER BY ordem, nome')->fetchAll();
$ev     = ($idEv = getInt('editar_evento')) ? carregarEvento($db, $idEv) : null;
// Pela URL dá para pedir qualquer id: aceita só os deste calendário e os
// globais do mesmo ano, que são os que aparecem na grade.
if ($ev && ((int) $ev['ano'] !== $ano
        || ($ev['calendario_id'] !== null && (int) $ev['calendario_id'] !== $id))) {
    $ev = null;
}
$eng    = Engine::paraCalendario($db, $id);
// data clicada na grade: só aceita se for um dia do ano deste calendário
$novaData = get('nova_data');
$novaData = ($novaData !== '' && $eng->dia($novaData) !== null) ? $novaData : '';

// Conta pelo motor, e não pelo banco, para bater com o que a grade mostra:
// entram os globais que valem para o nível deste curso e ficam de fora os
// eventos sem faixa de datas.
$evLocais = $evGlobais = 0;
foreach ($eng->eventos() as $evConta) {
    $evConta['calendario_id'] === null ? $evGlobais++ : $evLocais++;
}
$total = $evLocais + $evGlobais;

head($cal['curso_nome'] . ' · ' . $ano, 'calendarios');

$c1 = $eng->contagemSemestre(1);
$c2 = $eng->contagemSemestre(2);
$m1 = (int) $cal['meta_letivos_s1'];
$m2 = (int) $cal['meta_letivos_s2'];

/** "faltam 3" quando está abaixo da meta, "3 a mais" quando passou dela. */
$diferenca = static function (int $total, int $meta): string {
    $d = $meta - $total;
    return $d === 0 ? '' : ($d > 0 ? " · faltam $d" : ' · ' . abs($d) . ' a mais');
};
?>
<div class="d-flex flex-wrap gap-2 mb-3">
  <a class="btn btn-sm btn-outline-secondary" href="calendarios.php"><i class="bi bi-arrow-left me-1"></i>Calendários</a>
  <a class="btn btn-sm btn-outline-primary" href="editar_calendario.php?id=<?= $id ?>"><i class="bi bi-sliders me-1"></i>Dados e semestres</a>
  <a class="btn btn-sm btn-outline-dark" href="gerar.php?id=<?= $id ?>" target="_blank"><i class="bi bi-printer me-1"></i>Gerar calendário</a>
  <?php if ($ev): ?>
    <a class="btn btn-sm btn-primary" href="<?= e($voltarPara) ?>&novo=1"><i class="bi bi-plus-lg me-1"></i>Novo evento</a>
  <?php else: ?>
    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modalEvento"><i class="bi bi-plus-lg me-1"></i>Novo evento</button>
  <?php endif; ?>
</div>

<?php if ($eng->semestresImplicitos()): ?>
  <div class="alert alert-warning d-flex" role="alert">
    <i class="bi bi-exclamation-triangle-fill me-2 mt-1"></i>
    <div>
      Os semestres ainda não foram informados, então <strong>o ano inteiro está contando como letivo</strong> —
      só feriados, férias e recessos tiram dias. O resumo abaixo divide o ano ao meio (jan–jun e jul–dez).
      Informe as datas em <a href="editar_calendario.php?id=<?= $id ?>">Dados e semestres</a> para delimitar o período letivo.
    </div>
  </div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <?php
  $resumo = [
    ['1º semestre', $c1['total'], 'meta ' . $m1 . $diferenca($c1['total'], $m1), $c1['total'] === $m1, 'bi-1-circle-fill'],
    ['2º semestre', $c2['total'], 'meta ' . $m2 . $diferenca($c2['total'], $m2), $c2['total'] === $m2, 'bi-2-circle-fill'],
  ];
  foreach ($resumo as [$rot, $val, $meta, $bate, $ico]): ?>
  <div class="col-md-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="stat-icon <?= $bate ? 'bg-success' : 'bg-warning' ?> text-white fs-4"><i class="bi <?= $ico ?>"></i></div>
        <div>
          <div class="fw-bold fs-4"><?= $val ?> <span class="fs-6 fw-normal text-muted"><?= $val === 1 ? 'dia' : 'dias' ?></span></div>
          <div class="text-muted small"><?= e($rot) ?> · <?= e($meta) ?></div>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  <div class="col-md-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="stat-icon bg-secondary text-white fs-4"><i class="bi bi-list-ul"></i></div>
        <div>
          <div class="fw-bold fs-4"><?= $total ?> <span class="fs-6 fw-normal text-muted"><?= $total === 1 ? 'evento' : 'eventos' ?></span></div>
          <div class="text-muted small"><?= $evGlobais ?> globais · <?= $evLocais ?> locais</div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php $baseComum = false; $dataPadrao = $novaData; require __DIR__ . '/lib/form_evento.php'; ?>

<?php
$gradeVerFeriados = $verFeriados;
$gradeVerGlobais  = $verGlobais;
require __DIR__ . '/lib/grade_calendario.php';
?>

<div class="card border-0 shadow-sm mb-4">
  <div class="card-header bg-transparent fw-semibold">
    <i class="bi bi-calculator me-2 text-primary"></i>Resumo dos semestres
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0 text-center">
        <thead class="table-light">
          <tr>
            <th class="text-start">Semestre</th>
            <?php foreach (['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'] as $dia): ?>
              <th><?= $dia ?></th>
            <?php endforeach; ?>
            <th>Dias letivos</th>
            <th>Meta</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ([[1, $c1, $m1], [2, $c2, $m2]] as [$n, $c, $meta]): ?>
          <tr>
            <td class="text-start fw-semibold"><?= $n ?>º semestre</td>
            <?php for ($dw = 1; $dw <= 6; $dw++): ?>
              <td><?= $c['por_dow'][$dw] ?></td>
            <?php endfor; ?>
            <td>
              <span class="badge <?= $c['total'] === $meta ? 'bg-success-subtle text-success-emphasis' : 'bg-warning-subtle text-warning-emphasis' ?>">
                <?= $c['total'] ?><?= $diferenca($c['total'], $meta) ?>
              </span>
            </td>
            <td class="text-muted"><?= $meta ?></td>
          </tr>
          <?php endforeach; ?>
          <tr class="fw-semibold table-light">
            <td class="text-start">Ano</td>
            <?php for ($dw = 1; $dw <= 6; $dw++): ?>
              <td><?= $c1['por_dow'][$dw] + $c2['por_dow'][$dw] ?></td>
            <?php endfor; ?>
            <td><?= $c1['total'] + $c2['total'] ?></td>
            <td class="text-muted"><?= $m1 + $m2 ?></td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
  <?php if ($eng->notasReposicao()): ?>
  <div class="card-footer bg-transparent small text-muted">
    <?php foreach ($eng->notasReposicao() as $sem => $linhas): ?>
      <div><strong><?= (int) $sem ?>º semestre:</strong> <?= e(implode('; ', $linhas)) ?></div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<?php foot(); ?>
