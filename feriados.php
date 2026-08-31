<?php
require __DIR__ . '/lib/boot.php';
require __DIR__ . '/lib/feriados_crud.php';

$db = db();

// A grade mostra as datas do ano em foco, para conferir de bate-pronto.
$ano = anoDaTela(
    getInt('ano') ?: (int) ($db->query('SELECT MAX(ano) FROM eventos')->fetchColumn() ?: date('Y')),
    (int) date('Y')
);

// Tudo volta para esta tela, no ano em que se estava.
$voltarPara = 'feriados.php?ano=' . $ano;
tratarPostFeriado($db, $voltarPara);

$feriadoEdit = feriadoEmEdicao($db);
// Dia clicado na grade ou botão "Novo feriado": o modal abre já vazio, com a
// data do dia que se clicou.
$feriadoNovo = get('novo') !== '' || get('novo_feriado') !== '';

$quantos = (int) $db->query('SELECT COUNT(*) FROM feriados')->fetchColumn();

$eng = Engine::paraFeriados($db, $ano);

// Com o modal reabrindo, o erro aparece dentro do formulário.
$erroModal = modalAbrindo() ? erroParaModal() : '';

head('Feriados', 'feriados');
?>
<div class="card border-0 shadow-sm mb-3">
  <div class="card-body d-flex flex-wrap align-items-end gap-3">
    <form method="get">
      <label class="form-label mb-1">Ano</label>
      <input type="number" name="ano" class="form-control form-control-sm" style="width:110px"
             value="<?= $ano ?>" min="<?= ANO_MIN ?>" max="<?= ANO_MAX ?>" onchange="this.form.submit()">
    </form>

    <?php if ($feriadoEdit): ?>
      <a class="btn btn-sm btn-primary" href="feriados.php?ano=<?= $ano ?>&novo=1"><i class="bi bi-plus-lg me-1"></i>Novo feriado</a>
    <?php else: ?>
      <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modalFeriado">
        <i class="bi bi-plus-lg me-1"></i>Novo feriado
      </button>
    <?php endif; ?>

    <div class="ms-auto text-muted small">
      <span class="badge bg-light text-secondary border">
        <?= $quantos ?> feriado<?= $quantos === 1 ? '' : 's' ?> cadastrado<?= $quantos === 1 ? '' : 's' ?>
      </span>
    </div>
  </div>
</div>

<?php $gradeFeriados = true; require __DIR__ . '/lib/grade_calendario.php'; ?>

<?php require __DIR__ . '/lib/form_feriado.php'; ?>

<?php foot(); ?>
