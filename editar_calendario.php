<?php
require __DIR__ . '/lib/boot.php';

$db = db();
$id = getInt('id') ?: postInt('cal_id', 0);

$st = $db->prepare(
    'SELECT c.*, cu.nome AS curso_nome, cu.regime AS curso_regime
     FROM calendarios c JOIN cursos cu ON cu.id = c.curso_id WHERE c.id = ?'
);
$st->execute([$id]);
$cal = $st->fetch();
if (!$cal) {
    flash('Calendário não encontrado.', 'erro');
    redirect('calendarios.php');
}
$ano = (int) $cal['ano'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('acao') === 'salvar_cal') {
    [$bimestres, $erro] = bimestresDoFormulario((string) $cal['curso_regime'], $ano);
    if ($erro !== '') {
        flash($erro, 'erro');
        redirect('editar_calendario.php?id=' . $id);
    }

    $db->prepare('UPDATE calendarios SET situacao=?, local_texto=?, observacoes=? WHERE id=?')
       ->execute([post('situacao'), post('local_texto'), post('observacoes'), $id]);

    salvarPeriodos($db, $id, $bimestres);
    flash('Calendário atualizado.');
    // Salvou, acabou o que se faz aqui: o passo seguinte é a grade, que é onde
    // se vê o efeito das datas que acabaram de mudar. O caminho de erro acima
    // continua voltando para esta tela, senão o formulário se perderia com o
    // que foi digitado.
    redirect('calendario.php?id=' . $id);
}

// Um calendário anterior aos bimestres tem só os dois semestres gravados. Em
// vez de abrir a tela com oito campos vazios, o formulário sugere partir cada
// semestre ao meio — o mesmo palpite da criação —, e quem edita ajusta.
$bimestresSalvos = bimestresDoCalendario($db, $id);
$valores = [];
foreach ($bimestresSalvos as $n => [$inicio, $fim]) {
    $valores["bim{$n}_inicio"] = $inicio;
    $valores["bim{$n}_fim"]    = $fim;
}
$semBimestres = $bimestresSalvos === [];
if ($semBimestres) {
    $valores = bimestresSugeridos($db, $ano);
}

$semestres = [];
$st = $db->prepare("SELECT * FROM periodos WHERE calendario_id=? AND tipo='semestre'");
$st->execute([$id]);
foreach ($st as $p) {
    $semestres[(int) $p['numero']] = $p;
}

$nEventos = (int) $db->query("SELECT COUNT(*) FROM eventos WHERE calendario_id = $id")->fetchColumn();

head($cal['curso_nome'] . ' · ' . $ano, 'calendarios');
?>
<div class="d-flex flex-wrap gap-2 mb-3">
  <a class="btn btn-sm btn-outline-secondary" href="calendarios.php"><i class="bi bi-arrow-left me-1"></i>Calendários</a>
  <a class="btn btn-sm btn-outline-primary" href="calendario.php?id=<?= $id ?>">
    <i class="bi bi-grid-3x3 me-1"></i>Gerenciar eventos<span class="ms-1 text-muted">(<?= $nEventos ?>)</span>
  </a>
  <a class="btn btn-sm btn-primary" href="gerar.php?id=<?= $id ?>" target="_blank"><i class="bi bi-printer me-1"></i>Gerar calendário</a>
</div>

<?php if ($semBimestres): ?>
  <div class="alert alert-warning d-flex" role="alert">
    <i class="bi bi-exclamation-triangle-fill me-2 mt-1"></i>
    <div>
      <?php if ($semestres): ?>
        Este calendário é de antes dos bimestres: tem os dois semestres, mas não os quatro
        bimestres. As datas abaixo vêm <strong>sugeridas</strong>, partindo cada semestre ao meio —
        confira e salve para o calendário passar a marcar sozinho o início e o fim de cada bimestre.
      <?php else: ?>
        Os períodos ainda não foram informados, então <strong>o ano inteiro está contando como letivo</strong> —
        só feriados, férias e recessos tiram dias. Informe as datas abaixo para delimitar o período letivo.
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
  <div class="card-header bg-transparent fw-semibold">
    <i class="bi bi-sliders me-2 text-primary"></i>Dados do calendário e semestres
  </div>
  <div class="card-body">
    <form method="post" class="row g-3" data-ano="<?= $ano ?>">
      <?= csrfCampo() ?>
      <input type="hidden" name="acao" value="salvar_cal">
      <input type="hidden" name="cal_id" value="<?= $id ?>">
      <div class="col-12">
        <div class="alert alert-danger d-none mb-0 erro-periodos" role="alert"></div>
      </div>

      <div class="col-md-4">
        <label class="form-label">Curso</label>
        <input class="form-control" value="<?= e($cal['curso_nome']) ?>" disabled>
        <div class="form-text">O curso e o ano não mudam depois de criado.</div>
      </div>
      <div class="col-md-2">
        <label class="form-label">Ano</label>
        <input class="form-control" value="<?= $ano ?>" disabled>
      </div>
      <div class="col-md-6">
        <label class="form-label">Situação</label>
        <input name="situacao" class="form-control" value="<?= e($cal['situacao']) ?>">
        <div class="form-text">Sai no rodapé de cada página do calendário impresso.</div>
      </div>

      <div class="col-12">
        <label class="form-label">Local e data</label>
        <input name="local_texto" class="form-control" value="<?= e($cal['local_texto']) ?>">
      </div>

      <?php $regime = (string) $cal['curso_regime']; require __DIR__ . '/lib/campos_bimestres.php'; ?>

      <div class="col-12">
        <label class="form-label">Observações</label>
        <textarea name="observacoes" class="form-control" rows="3"><?= e($cal['observacoes']) ?></textarea>
        <div class="form-text">Cada linha vira uma nota na página de resumo do calendário impresso.</div>
      </div>

      <div class="col-12">
        <button class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Salvar</button>
        <a class="btn btn-outline-secondary" href="calendarios.php">Cancelar</a>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/lib/valida_bimestres.php'; ?>

<?php foot(); ?>
