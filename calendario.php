<?php
require __DIR__ . '/lib/boot.php';
require __DIR__ . '/lib/eventos_crud.php';

$db = db();
$id = getInt('id') ?: postInt('cal_id', 0);

$st = $db->prepare(
    'SELECT c.*, cu.nome AS curso_nome, cu.nivel AS curso_nivel, cu.regime AS curso_regime
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
    // Os marcos de bimestre não são cadastro de ninguém: o motor os escreve das
    // datas do calendário, então não entram na conta de eventos.
    if (!empty($evConta['auto'])) {
        continue;
    }
    $evConta['calendario_id'] === null ? $evGlobais++ : $evLocais++;
}
$total = $evLocais + $evGlobais;

head($cal['curso_nome'] . ' · ' . $ano, 'calendarios');

$c1 = $eng->contagemSemestre(1);
$c2 = $eng->contagemSemestre(2);

// Os contadores do topo, numa faixa só: cada semestre seguido dos seus dois
// bimestres, e o de eventos no fim. Não há meta a bater — o número é o que o
// motor contou, já com os sábados letivos e o que mais o calendário tenha.
// Quem sabe quantos dias o período precisa ter é quem monta, e é olhando estes
// números que ele acrescenta ou tira um sábado.
//
// O rótulo do bimestre vai curto ("2º bimestre") mesmo no curso semestral, em
// que o número se repete: a posição na faixa já diz de que semestre ele é, e um
// traço mais forte separa um grupo do outro.
$regime  = (string) ($cal['curso_regime'] ?: 'semestral');
$curto   = static fn (int $n): string => rotuloBimestre($n, $regime) . 'º bimestre';
$contadores = [
    ['1º semestre', $c1['total'],                       'semestre'],
    [$curto(1),     $eng->contagemBimestre(1)['total'], ''],
    [$curto(2),     $eng->contagemBimestre(2)['total'], ''],
    ['2º semestre', $c2['total'],                       'semestre grupo'],
    [$curto(3),     $eng->contagemBimestre(3)['total'], ''],
    [$curto(4),     $eng->contagemBimestre(4)['total'], ''],
    ['eventos',     $total,                             'grupo'],
];
?>
<div class="d-flex flex-wrap gap-2 mb-3">
  <a class="btn btn-sm btn-outline-secondary" href="calendarios.php"><i class="bi bi-arrow-left me-1"></i>Calendários</a>
  <a class="btn btn-sm btn-outline-primary" href="editar_calendario.php?id=<?= $id ?>"><i class="bi bi-sliders me-1"></i>Dados e bimestres</a>
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
      Informe as datas em <a href="editar_calendario.php?id=<?= $id ?>">Dados e bimestres</a> para delimitar o período letivo.
    </div>
  </div>
<?php elseif (!$eng->bimestres()): ?>
  <div class="alert alert-warning d-flex" role="alert">
    <i class="bi bi-exclamation-triangle-fill me-2 mt-1"></i>
    <div>
      Este calendário é de antes dos bimestres: os semestres estão informados, mas os quatro
      bimestres não — por isso <strong>os contadores de bimestre estão zerados</strong> e a grade não
      marca sozinha o início e o fim de cada um. Abra
      <a href="editar_calendario.php?id=<?= $id ?>">Dados e bimestres</a>, confira as datas sugeridas
      e salve.
    </div>
  </div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-4">
  <div class="faixa-contadores">
    <?php foreach ($contadores as [$g_rot, $g_val, $g_classe]): ?>
    <div class="contador <?= $g_classe ?>"<?= $g_rot === 'eventos'
        ? ' title="' . $evGlobais . ' globais · ' . $evLocais . ' locais"' : '' ?>>
      <span class="numero"><?= $g_val ?></span>
      <span class="rotulo"><?= e($g_rot) ?></span>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php unset($g_rot, $g_val, $g_classe); ?>

<?php $baseComum = false; $dataPadrao = $novaData; require __DIR__ . '/lib/form_evento.php'; ?>

<?php
$gradeVerFeriados = $verFeriados;
$gradeVerGlobais  = $verGlobais;
require __DIR__ . '/lib/grade_calendario.php';
?>

<div class="card border-0 shadow-sm mb-4">
  <div class="card-header bg-transparent fw-semibold">
    <i class="bi bi-calculator me-2 text-primary"></i>Resumo dos semestres e bimestres
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0 text-center">
        <thead class="table-light">
          <tr>
            <th class="text-start">Período</th>
            <?php foreach (['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'] as $dia): ?>
              <th><?= $dia ?></th>
            <?php endforeach; ?>
            <th>Dias letivos</th>
          </tr>
        </thead>
        <tbody>
          <?php
          // Cada semestre e, logo abaixo, os seus dois bimestres. O bimestre
          // entra recuado e em cinza: é o detalhe da linha de cima, e a soma
          // dos dois fecha com ela. Num calendário anterior aos bimestres a
          // lista deles vem vazia, e a tabela fica só com os semestres.
          $temBimestres = $eng->bimestres() !== [];
          foreach ([[1, $c1, [1, 2]], [2, $c2, [3, 4]]] as [$n, $c, $doSemestre]):
          ?>
          <tr>
            <td class="text-start fw-semibold"><?= $n ?>º semestre</td>
            <?php for ($dw = 1; $dw <= 6; $dw++): ?>
              <td><?= $c['por_dow'][$dw] ?></td>
            <?php endfor; ?>
            <td><span class="badge bg-light text-secondary border"><?= $c['total'] ?></span></td>
          </tr>
            <?php if ($temBimestres): foreach ($doSemestre as $b): ?>
            <?php $cb = $eng->contagemBimestre($b); ?>
            <tr class="linha-bimestre">
              <td class="text-start text-muted ps-4"><?= rotuloBimestre($b, $regime) ?>º bimestre</td>
              <?php for ($dw = 1; $dw <= 6; $dw++): ?>
                <td class="text-muted"><?= $cb['por_dow'][$dw] ?></td>
              <?php endfor; ?>
              <td class="text-muted"><?= $cb['total'] ?></td>
            </tr>
            <?php endforeach; endif; ?>
          <?php endforeach; ?>
          <tr class="fw-semibold table-light">
            <td class="text-start">Ano</td>
            <?php for ($dw = 1; $dw <= 6; $dw++): ?>
              <td><?= $c1['por_dow'][$dw] + $c2['por_dow'][$dw] ?></td>
            <?php endfor; ?>
            <td><?= $c1['total'] + $c2['total'] ?></td>
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
