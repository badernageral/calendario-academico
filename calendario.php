<?php
require __DIR__ . '/lib/boot.php';
require __DIR__ . '/lib/eventos_crud.php';
require __DIR__ . '/lib/feriados_crud.php';
require __DIR__ . '/lib/calendario_crud.php';

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

// As quatro caixas de "Exibir" — Feriados, Eventos globais, Locais e
// Automáticos — são filtro só de exibição da grade, e nascem todas marcadas.
// Desmarcar tira o tipo da vista sem tirá-lo da conta: os dias letivos
// continuam contando com tudo. Como caixa desmarcada não é enviada, "filtros=1"
// é a marca de que a resposta veio do formulário — sem ela, é a primeira
// entrada na tela.
$filtrou     = get('filtros') === '1';
$verFeriados = !$filtrou || get('feriados') === '1';
$verGlobais  = !$filtrou || get('globais') === '1';
$verAuto     = !$filtrou || get('auto') === '1';
$verLocais   = !$filtrou || get('locais') === '1';

// Vai em toda volta ao calendário (salvar, cancelar, editar) para as caixas
// continuarem como estavam.
$voltarPara = 'calendario.php?id=' . $id
    . '&filtros=1&feriados=' . ($verFeriados ? '1' : '0')
    . '&globais=' . ($verGlobais ? '1' : '0')
    . '&auto=' . ($verAuto ? '1' : '0')
    . '&locais=' . ($verLocais ? '1' : '0');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    tratarPostEvento($db, $ano, $id, $voltarPara);
    // O feriado aparece na grade daqui, então também se altera daqui — e volta
    // para esta tela, com as caixas como estavam.
    tratarPostFeriado($db, $voltarPara);
    // As oito datas dos bimestres se conferem olhando a grade, então é dela que
    // se abre o formulário — inclusive clicando num marco de início ou fim de
    // bimestre, que sai justamente destas datas.
    tratarPostCalendario($db, $cal, $voltarPara);
}

$feriadoEdit = feriadoEmEdicao($db);

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

// Com um modal reabrindo, o erro aparece dentro do formulário, e não atrás
// dele. Qual dos três o recebe é cada include que decide, pelo que a URL pediu.
$erroModal = modalAbrindo() ? erroParaModal() : '';

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
  <a class="btn btn-sm btn-outline-primary" href="<?= e($voltarPara) ?>&editar_cal=1"><i class="bi bi-sliders me-1"></i>Dados e bimestres</a>
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
      Informe as datas em <a href="<?= e($voltarPara) ?>&editar_cal=1">Dados e bimestres</a> para delimitar o período letivo.
    </div>
  </div>
<?php elseif (!$eng->bimestres()): ?>
  <div class="alert alert-warning d-flex" role="alert">
    <i class="bi bi-exclamation-triangle-fill me-2 mt-1"></i>
    <div>
      Este calendário é de antes dos bimestres: os semestres estão informados, mas os quatro
      bimestres não — por isso <strong>os contadores de bimestre estão zerados</strong> e a grade não
      marca sozinha o início e o fim de cada um. Abra
      <a href="<?= e($voltarPara) ?>&editar_cal=1">Dados e bimestres</a>, confira as datas sugeridas
      e salve.
    </div>
  </div>
<?php endif; ?>

<?php
// Dia letivo dentro do semestre e fora dos dois bimestres dele. Acontece quando
// as oito datas deixam um vão com dia letivo dentro — um sábado de reposição
// entre o fim de um bimestre e o começo do outro, tipicamente. O semestre o
// conta, os bimestres não, e a soma para de fechar sem nada dizer por quê: era
// preciso somar as duas linhas à mão para desconfiar.
$foraDosBimestres = $eng->diasForaDosBimestres();
?>
<?php if ($foraDosBimestres): ?>
  <div class="alert alert-warning d-flex" role="alert">
    <i class="bi bi-exclamation-triangle-fill me-2 mt-1"></i>
    <div>
      <?= count($foraDosBimestres) === 1 ? 'Um dia letivo está' : count($foraDosBimestres) . ' dias letivos estão' ?>
      <strong>dentro do semestre e fora dos bimestres dele</strong>:
      <?= e(implode(', ', array_map('dataBr', array_slice($foraDosBimestres, 0, 6)))) ?><?=
          count($foraDosBimestres) > 6 ? ' e mais ' . (count($foraDosBimestres) - 6) : '' ?>.
      Por isso a soma dos dois bimestres não fecha com o total do semestre, no resumo abaixo.
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
<?php require __DIR__ . '/lib/form_feriado.php'; ?>
<?php $calEdit = $cal; require __DIR__ . '/lib/form_calendario.php'; ?>

<?php
$gradeVerFeriados = $verFeriados;
$gradeVerGlobais  = $verGlobais;
$gradeVerAuto     = $verAuto;
$gradeVerLocais   = $verLocais;
require __DIR__ . '/lib/grade_calendario.php';
?>

<?php
// As duas contagens do mesmo período, lado a lado: à esquerda pelo dia da semana
// em que o dia cai, à direita pelo horário que ele cumpre. Ver as duas juntas é
// o que mostra o sábado saindo de uma coluna e entrando na outra.
$h1 = $eng->contagemHorarioSemestre(1);
$h2 = $eng->contagemHorarioSemestre(2);
// Sábado letivo sem "repõe" não tem horário a cumprir e fica fora da direita.
$semHorario = ($c1['total'] + $c2['total']) - ($h1['total'] + $h2['total']);

// Num calendário anterior aos bimestres a lista vem vazia, e sobram os semestres.
$temBimestres = $eng->bimestres() !== [];

/**
 * Uma das duas tabelas. Têm o mesmo desenho — o semestre, os dois bimestres dele
 * recuados e o ano no fim — e só diferem em quantas colunas de dia da semana
 * mostram e de onde vêm os números.
 *
 * @param array<int, array>     $semestres  a contagem de cada semestre, por número
 * @param callable(int): array  $doBimestre a contagem de um bimestre, por número
 * @param int                   $ultimoDow  6 inclui sábado; 5 para no sexta
 */
$tabela = static function (array $semestres, callable $doBimestre, int $ultimoDow)
        use ($temBimestres, $regime): void {
    ?>
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0 text-center resumo-compacto">
        <thead class="table-light">
          <tr>
            <th class="text-start">Período</th>
            <?php foreach (array_slice(['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'], 0, $ultimoDow) as $d): ?>
              <th><?= $d ?></th>
            <?php endforeach; ?>
            <th>Total</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ([[1, [1, 2]], [2, [3, 4]]] as [$n, $doSemestre]): ?>
          <?php $c = $semestres[$n]; ?>
          <tr>
            <td class="text-start fw-semibold"><?= $n ?>º semestre</td>
            <?php for ($dw = 1; $dw <= $ultimoDow; $dw++): ?>
              <td><?= $c['por_dow'][$dw] ?></td>
            <?php endfor; ?>
            <td><span class="badge bg-light text-secondary border"><?= $c['total'] ?></span></td>
          </tr>
            <?php if ($temBimestres): foreach ($doSemestre as $b): ?>
            <?php $cb = $doBimestre($b); ?>
            <tr class="linha-bimestre">
              <td class="text-start text-muted ps-3"><?= rotuloBimestre($b, $regime) ?>º bim.</td>
              <?php for ($dw = 1; $dw <= $ultimoDow; $dw++): ?>
                <td class="text-muted"><?= $cb['por_dow'][$dw] ?></td>
              <?php endfor; ?>
              <td class="text-muted"><?= $cb['total'] ?></td>
            </tr>
            <?php endforeach; endif; ?>
          <?php endforeach; ?>
          <tr class="fw-semibold table-light">
            <td class="text-start">Ano</td>
            <?php for ($dw = 1; $dw <= $ultimoDow; $dw++): ?>
              <td><?= $semestres[1]['por_dow'][$dw] + $semestres[2]['por_dow'][$dw] ?></td>
            <?php endfor; ?>
            <td><?= $semestres[1]['total'] + $semestres[2]['total'] ?></td>
          </tr>
        </tbody>
      </table>
    </div>
    <?php
};
?>

<div class="card border-0 shadow-sm mb-4">
  <div class="card-header bg-transparent fw-semibold">
    <i class="bi bi-calculator me-2 text-primary"></i>Resumo dos dias letivos
  </div>
  <div class="card-body p-0">
    <div class="row g-0 resumo-par">
      <div class="col-xxl-6">
        <div class="px-3 py-2 small text-muted border-bottom">
          <i class="bi bi-calendar-week me-1"></i>Pelo dia da semana em que cai
        </div>
        <?php $tabela([1 => $c1, 2 => $c2], static fn (int $b): array => $eng->contagemBimestre($b), 6); ?>
      </div>
      <div class="col-xxl-6 coluna-horario">
        <div class="px-3 py-2 small text-muted border-bottom">
          <i class="bi bi-clock-history me-1"></i>Pelo horário que cumpre
          <span class="d-none d-sm-inline">— o sábado entra na coluna do dia que repõe</span>
        </div>
        <?php $tabela([1 => $h1, 2 => $h2], static fn (int $b): array => $eng->contagemHorarioBimestre($b), 5); ?>
      </div>
    </div>
  </div>
  <?php if ($semHorario > 0): ?>
  <div class="card-footer bg-transparent small">
    <span class="text-warning-emphasis">
      <i class="bi bi-exclamation-triangle-fill me-1"></i>
      <?= $semHorario ?> dia<?= $semHorario === 1 ? '' : 's' ?> letivo<?= $semHorario === 1 ? '' : 's' ?>
      de sábado <?= $semHorario === 1 ? 'está' : 'estão' ?> fora da contagem por horário por não
      <?= $semHorario === 1 ? 'ter' : 'terem' ?> o campo <strong>repõe o dia da semana</strong> preenchido.
    </span>
  </div>
  <?php endif; ?>
  <?php if ($eng->notasReposicao()): ?>
  <div class="card-footer bg-transparent small text-muted">
    <?php foreach ($eng->notasReposicao() as $sem => $linhas): ?>
      <div><strong><?= (int) $sem ?>º semestre:</strong> <?= e(implode('; ', $linhas)) ?></div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<?php foot(); ?>
