<?php
require __DIR__ . '/lib/boot.php';

$db = db();
$id = getInt('id') ?: postInt('cal_id', 0);

$st = $db->prepare(
    'SELECT c.*, cu.nome AS curso_nome
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
    [$semestres, $erro] = semestresDoFormulario();
    if ($erro !== '') {
        flash($erro, 'erro');
        redirect('editar_calendario.php?id=' . $id);
    }

    $db->prepare('UPDATE calendarios SET situacao=?, local_texto=?, meta_letivos_s1=?, meta_letivos_s2=?, observacoes=? WHERE id=?')
       ->execute([post('situacao'), post('local_texto'), postInt('meta_letivos_s1', 100),
                  postInt('meta_letivos_s2', 100), post('observacoes'), $id]);

    salvarSemestres($db, $id, $semestres);
    flash('Calendário atualizado.');
    redirect('editar_calendario.php?id=' . $id);
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

<?php if (!$semestres): ?>
  <div class="alert alert-warning d-flex" role="alert">
    <i class="bi bi-exclamation-triangle-fill me-2 mt-1"></i>
    <div>
      Os semestres ainda não foram informados, então <strong>o ano inteiro está contando como letivo</strong> —
      só feriados, férias e recessos tiram dias. Informe as datas abaixo para delimitar o período letivo.
    </div>
  </div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
  <div class="card-header bg-transparent fw-semibold">
    <i class="bi bi-sliders me-2 text-primary"></i>Dados do calendário e semestres
  </div>
  <div class="card-body">
    <form method="post" class="row g-3">
      <input type="hidden" name="acao" value="salvar_cal">
      <input type="hidden" name="cal_id" value="<?= $id ?>">
      <div class="col-12">
        <div class="alert alert-danger d-none mb-0 erro-semestres" role="alert"></div>
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

      <div class="col-12"><hr class="my-1"></div>
      <div class="col-12">
        <div class="form-text mt-0">
          As quatro datas são obrigatórias: são elas que delimitam o período letivo do ano.
        </div>
      </div>

      <div class="col-md-3">
        <label class="form-label">1º semestre — início</label>
        <input type="date" name="sem1_inicio" class="form-control" required value="<?= e($semestres[1]['inicio'] ?? '') ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">1º semestre — fim</label>
        <input type="date" name="sem1_fim" class="form-control" required value="<?= e($semestres[1]['fim'] ?? '') ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">2º semestre — início</label>
        <input type="date" name="sem2_inicio" class="form-control" required value="<?= e($semestres[2]['inicio'] ?? '') ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">2º semestre — fim</label>
        <input type="date" name="sem2_fim" class="form-control" required value="<?= e($semestres[2]['fim'] ?? '') ?>">
      </div>

      <div class="col-md-3">
        <label class="form-label">Meta de dias letivos — 1º sem.</label>
        <input type="number" name="meta_letivos_s1" class="form-control" value="<?= (int) $cal['meta_letivos_s1'] ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Meta de dias letivos — 2º sem.</label>
        <input type="number" name="meta_letivos_s2" class="form-control" value="<?= (int) $cal['meta_letivos_s2'] ?>">
      </div>

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

<?php require __DIR__ . '/lib/valida_semestres.php'; ?>

<?php foot(); ?>
