<?php
require __DIR__ . '/lib/boot.php';
require __DIR__ . '/lib/eventos_crud.php';

$db  = db();
$ano = getInt('ano') ?: (int) (postInt('ano') ?: (int) date('Y'));

$voltarPara = 'eventos.php?ano=' . $ano;
tratarPostEvento($db, $ano, null, $voltarPara);

if (post('acao') === 'copiar_ano') {
    $origem = postInt('origem', 0);
    if (!$origem || $origem === $ano) {
        flash('Escolha o ano de origem.', 'erro');
        redirect($voltarPara);
    }
    [$copiados, $repetidos] = copiarEventosGlobais($db, $origem, $ano);
    flash($copiados > 0
        ? "$copiados eventos globais copiados de $origem para $ano."
            . ($repetidos > 0 ? " Outros $repetidos já estavam lá." : '')
            . ' Confira as datas: elas vieram deslocadas um ano de cada vez.'
        : "Nada a copiar: os eventos de $origem já estavam em $ano.");
    redirect($voltarPara);
}

if (post('acao') === 'limpar_ano') {
    // Só os globais do ano: os de calendário são de outra tela, e as faixas de
    // data saem junto pelo ON DELETE CASCADE de evento_datas.
    $st = $db->prepare('DELETE FROM eventos WHERE calendario_id IS NULL AND ano = ?');
    $st->execute([$ano]);
    $n = $st->rowCount();
    flash($n > 0
        ? "$n eventos globais de $ano excluídos."
        : "Não havia eventos globais em $ano.");
    redirect($voltarPara);
}

$cats = $db->query('SELECT * FROM categorias ORDER BY ordem, nome')->fetchAll();
$ev   = ($idEv = getInt('editar_evento')) ? carregarEvento($db, $idEv) : null;
// Aqui só se editam globais do ano em foco; qualquer outro id vira "novo".
if ($ev && ((int) $ev['ano'] !== $ano || $ev['calendario_id'] !== null)) {
    $ev = null;
}

// A caixa "Mostrar feriados" não fica guardada: nasce desmarcada a cada
// entrada na tela, e só o que está na URL a mantém enquanto se navega por aqui.
$comFeriados = get('feriados') === '1';

$eng = Engine::paraAnoGlobal($db, $ano, $comFeriados);

// data clicada na grade
$novaData = get('nova_data');
$novaData = ($novaData !== '' && $eng->dia($novaData) !== null) ? $novaData : '';

$anos = $db->query('SELECT DISTINCT ano FROM eventos WHERE calendario_id IS NULL ORDER BY ano DESC')->fetchAll(PDO::FETCH_COLUMN);

// Anos que servem de origem para copiar: os que têm evento global, menos este.
$origens = $db->query(
    'SELECT ano, COUNT(*) AS n FROM eventos WHERE calendario_id IS NULL GROUP BY ano ORDER BY ano DESC'
)->fetchAll();
$origens = array_values(array_filter($origens, static fn ($o) => (int) $o['ano'] !== $ano));

// Conta os cadastrados de verdade: os feriados aparecem na grade, mas vêm do
// cadastro próprio e não são eventos deste ano.
$st = $db->prepare('SELECT COUNT(*) FROM eventos WHERE calendario_id IS NULL AND ano = ?');
$st->execute([$ano]);
$total = (int) $st->fetchColumn();

head('Eventos globais', 'base');
?>
<div class="card border-0 shadow-sm mb-3">
  <div class="card-body d-flex flex-wrap align-items-end gap-3">
    <form method="get" class="d-flex align-items-end gap-3">
      <div>
        <label class="form-label mb-1">Ano</label>
        <input type="number" name="ano" class="form-control form-control-sm" style="width:110px"
               value="<?= $ano ?>" min="2000" max="2100" onchange="this.form.submit()">
      </div>
      <div class="form-check mb-1">
        <input class="form-check-input" type="checkbox" name="feriados" value="1" id="verFeriados"
               <?= $comFeriados ? 'checked' : '' ?> onchange="this.form.submit()">
        <label class="form-check-label" for="verFeriados">Mostrar feriados</label>
      </div>
    </form>

    <?php if ($ev): ?>
    <a class="btn btn-sm btn-primary" href="<?= e($voltarPara) ?>&novo=1"><i class="bi bi-plus-lg me-1"></i>Novo evento</a>
  <?php else: ?>
    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modalEvento"><i class="bi bi-plus-lg me-1"></i>Novo evento</button>
  <?php endif; ?>

    <?php if ($origens): ?>
    <form method="post" class="d-flex align-items-end gap-2">
      <input type="hidden" name="acao" value="copiar_ano">
      <input type="hidden" name="ano" value="<?= $ano ?>">
      <div>
        <label class="form-label mb-1">Copiar eventos globais de</label>
        <select name="origem" class="form-select form-select-sm">
          <?php foreach ($origens as $o): ?>
            <option value="<?= (int) $o['ano'] ?>"><?= (int) $o['ano'] ?> (<?= (int) $o['n'] ?> eventos)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn btn-sm btn-outline-secondary"
              title="As datas vêm deslocadas para <?= $ano ?>; o que já existe não é duplicado">
        <i class="bi bi-files me-1"></i>Copiar para <?= $ano ?>
      </button>
    </form>
    <?php endif; ?>

    <?php if ($total): ?>
    <form method="post"
          onsubmit="return confirm('Excluir os <?= $total ?> eventos globais de <?= $ano ?>? Os eventos próprios dos calendários não são tocados.')">
      <input type="hidden" name="acao" value="limpar_ano">
      <input type="hidden" name="ano" value="<?= $ano ?>">
      <button class="btn btn-sm btn-outline-danger" title="Apaga só os eventos globais deste ano">
        <i class="bi bi-trash me-1"></i>Excluir os de <?= $ano ?>
      </button>
    </form>
    <?php endif; ?>

    <div class="ms-auto text-muted small">
      <span class="badge bg-light text-secondary border"><?= $total ?> evento<?= $total === 1 ? '' : 's' ?> em <?= $ano ?></span>
      <?php if ($anos): ?>
        <span class="ms-2">outros anos:</span>
        <?php foreach ($anos as $a): if ((int) $a === $ano) continue; ?>
          <a class="ms-1" href="eventos.php?ano=<?= (int) $a ?>"><?= (int) $a ?></a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php $baseComum = true; $dataPadrao = $novaData; require __DIR__ . '/lib/form_evento.php'; ?>
<?php $gradeGlobal = true; require __DIR__ . '/lib/grade_calendario.php'; ?>

<?php foot(); ?>
