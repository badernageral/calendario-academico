<?php
require __DIR__ . '/lib/boot.php';

$db = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = post('acao');

    if ($acao === 'novo') {
        $curso = postInt('curso_id');
        $ano   = postInt('ano');
        if (!$curso || !$ano) {
            flash('Escolha o curso e informe o ano.', 'erro');
            redirect('calendarios.php?novo=1');
        }
        // A mesma faixa do campo do formulário: um ano fora dela geraria um
        // calendário que nenhuma tela por ano alcança depois.
        if ($ano !== anoDaTela($ano, 0)) {
            flash('O ano precisa ficar entre 2000 e 2100.', 'erro');
            redirect('calendarios.php?novo=1');
        }
        // Os rótulos do erro dependem do regime do curso escolhido.
        [$bimestres, $erro] = bimestresDoFormulario(regimeDoCurso($db, $curso), $ano);
        if ($erro !== '') {
            flash($erro, 'erro');
            redirect('calendarios.php?novo=1');
        }

        try {
            $st = $db->prepare(
                'INSERT INTO calendarios (curso_id, ano, situacao, local_texto, observacoes)
                 VALUES (?,?,?,?,?)'
            );
            $st->execute([
                $curso, $ano,
                post('situacao', cfg('situacao')),
                post('local_texto'),
                post('observacoes'),
            ]);
            $novoId = (int) $db->lastInsertId();
        } catch (PDOException $ex) {
            flash('Já existe um calendário desse curso para ' . $ano . '.', 'erro');
            redirect('calendarios.php?novo=1');
        }

        salvarPeriodos($db, $novoId, $bimestres);

        $origem = postInt('copiar_de');
        if ($origem) {
            copiarEventos($db, $origem, $novoId, $ano);
        }
        // O formulário de criação já pede tudo o que a tela de dados pede, então
        // o passo seguinte é a grade: cadastrar os eventos do curso.
        flash('Calendário criado.');
        redirect('calendario.php?id=' . $novoId);
    }

    if ($acao === 'excluir') {
        $db->prepare('DELETE FROM calendarios WHERE id = ?')->execute([postInt('id')]);
        flash('Calendário excluído.');
        redirect('calendarios.php');
    }
}

/** Duplica os eventos próprios de um calendário para outro, deslocando o ano. */
function copiarEventos(PDO $db, int $de, int $para, int $anoDestino): void
{
    $st = $db->prepare('SELECT * FROM eventos WHERE calendario_id = ?');
    $st->execute([$de]);
    $origem = $st->fetchAll();
    if (!$origem) {
        return;
    }
    $ins = $db->prepare(
        'INSERT INTO eventos (ano, calendario_id, categoria_id, descricao, pinta_dias, negrito, conta_letivo, rotulo, nivel, repoe_dow)
         VALUES (?,?,?,?,?,?,?,?,?,?)'
    );
    $sel = $db->prepare('SELECT inicio, fim FROM evento_datas WHERE evento_id = ?');
    foreach ($origem as $ev) {
        $ins->execute([
            $anoDestino, $para, $ev['categoria_id'], $ev['descricao'], $ev['pinta_dias'],
            $ev['negrito'], $ev['conta_letivo'], $ev['rotulo'], $ev['nivel'], $ev['repoe_dow'],
        ]);
        $novo = (int) $db->lastInsertId();
        $sel->execute([$ev['id']]);
        $faixas = [];
        $delta  = $anoDestino - (int) $ev['ano'];
        foreach ($sel as $d) {
            $faixas[] = [
                'inicio' => deslocarAno($d['inicio'], $delta),
                'fim'    => deslocarAno($d['fim'], $delta),
            ];
        }
        salvarFaixas($db, $novo, $faixas);
    }
}

$cursos = $db->query('SELECT * FROM cursos WHERE ativo = 1 ORDER BY nome')->fetchAll();
$cals   = $db->query(
    'SELECT c.*, cu.nome AS curso_nome,
            (SELECT COUNT(*) FROM eventos e WHERE e.calendario_id = c.id) AS n_eventos
     FROM calendarios c JOIN cursos cu ON cu.id = c.curso_id
     ORDER BY c.ano DESC, cu.nome'
)->fetchAll();

// Sem curso não há o que agendar: nesse caso a tela só convida a cadastrar um.
$abrirModal = $cursos && get('novo') !== '';

// O formulário abre com as datas prováveis dos bimestres já preenchidas. Como
// elas dependem do ano — e dos feriados dele —, vai uma sugestão por ano à mão
// do formulário, para as datas acompanharem a troca do ano sem recarregar.
$anoPadrao = (int) date('Y');
$sugestoes = [];
for ($a = $anoPadrao - 1; $a <= $anoPadrao + 5; $a++) {
    $sugestoes[$a] = bimestresSugeridos($db, $a);
}

// O rótulo de cada campo depende do regime do curso, e aqui o curso ainda muda
// no <select>: vai o regime de cada um e os rótulos dos dois regimes, para o
// script trocar os textos sem ida ao servidor.
$regimePorCurso = [];
foreach ($cursos as $c) {
    $regimePorCurso[(int) $c['id']] = $c['regime'];
}
$rotulosPorRegime = [];
foreach (array_keys(regimesCurso()) as $r) {
    $rotulosPorRegime[$r] = rotulosBimestre($r);
}

head('Calendários', 'calendarios');
?>

<?php if (!$cursos): ?>
  <div class="card border-0 shadow-sm">
    <div class="card-body text-center text-muted py-5">
      <i class="bi bi-mortarboard display-6 d-block mb-2"></i>
      Nenhum curso cadastrado ainda.
      <div class="mt-3"><a href="cursos.php" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i>Cadastrar o primeiro curso</a></div>
    </div>
  </div>
<?php else: ?>

<div class="card border-0 shadow-sm">
  <div class="card-header bg-transparent fw-semibold d-flex justify-content-between align-items-center">
    <span><i class="bi bi-calendar3 me-2 text-primary"></i>Calendários</span>
    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modalCalendario">
      <i class="bi bi-plus-lg me-1"></i>Novo calendário
    </button>
  </div>
  <div class="card-body p-0">
    <?php if (!$cals): ?>
      <div class="text-center text-muted py-5">
        <i class="bi bi-calendar-x display-6 d-block mb-2"></i>Nenhum calendário cadastrado ainda.
        <div class="mt-3">
          <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalCalendario">
            <i class="bi bi-plus-lg me-1"></i>Criar o primeiro calendário
          </button>
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
              <a class="btn btn-sm btn-outline-secondary" href="editar_calendario.php?id=<?= $c['id'] ?>" title="Dados e bimestres"><i class="bi bi-pencil me-1"></i>Editar</a>
              <a class="btn btn-sm btn-outline-primary" href="calendario.php?id=<?= $c['id'] ?>" title="Grade e eventos"><i class="bi bi-grid-3x3 me-1"></i>Gerenciar</a>
              <a class="btn btn-sm btn-outline-dark" href="gerar.php?id=<?= $c['id'] ?>" target="_blank"><i class="bi bi-printer me-1"></i>Gerar</a>
              <form method="post" class="d-inline" onsubmit="return confirm('Excluir o calendário e todos os seus eventos?')">
                <?= csrfCampo() ?>
                <input type="hidden" name="acao" value="excluir">
                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                <button class="btn btn-sm btn-outline-danger" title="Excluir"><i class="bi bi-trash"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="modal fade" id="modalCalendario" tabindex="-1" aria-labelledby="tituloModalCalendario" <?= $abrirModal ? 'data-abrir="1"' : '' ?>>
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <form method="post">
        <?= csrfCampo() ?>
        <div class="modal-header">
          <h5 class="modal-title" id="tituloModalCalendario">
            <i class="bi bi-calendar-plus me-2 text-primary"></i>Novo calendário
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
        </div>

        <div class="modal-body">
          <input type="hidden" name="acao" value="novo">
          <div class="alert alert-danger d-none erro-periodos" role="alert"></div>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Curso</label>
              <select name="curso_id" class="form-select" required>
                <?php foreach ($cursos as $c): ?>
                  <option value="<?= $c['id'] ?>"><?= e($c['nome']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label">Ano</label>
              <input type="number" name="ano" id="anoCalendario" class="form-control"
                     value="<?= $anoPadrao ?>" min="2000" max="2100" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Situação</label>
              <input name="situacao" class="form-control" value="<?= e(cfg('situacao')) ?>">
            </div>
            <div class="col-12">
              <label class="form-label">Local e data</label>
              <input name="local_texto" class="form-control" value="<?= e(localEData()) ?>">
            </div>

            <?php
            $valores = $sugestoes[$anoPadrao];
            $regime  = $cursos ? $cursos[0]['regime'] : 'semestral';
            require __DIR__ . '/lib/campos_bimestres.php';
            ?>

            <div class="col-12">
              <label class="form-label">Observações</label>
              <textarea name="observacoes" class="form-control" rows="3"></textarea>
              <div class="form-text">Cada linha vira uma nota na página de resumo do calendário impresso.</div>
            </div>

            <div class="col-12"><hr class="my-1"></div>
            <div class="col-md-6">
              <label class="form-label">Copiar eventos de</label>
              <select name="copiar_de" class="form-select">
                <option value="">— começar vazio —</option>
                <?php foreach ($cals as $c): ?>
                  <option value="<?= $c['id'] ?>"><?= e($c['curso_nome']) ?> · <?= $c['ano'] ?> (<?= $c['n_eventos'] ?> eventos)</option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">As datas entram deslocadas para o ano novo. Só na criação.</div>
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Criar calendário</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
/**
 * Duas coisas mudam sozinhas neste formulário:
 *
 * - trocar o **ano** troca as datas sugeridas dos bimestres. As sugestões vêm
 *   prontas do servidor, que é quem sabe onde caem os feriados de cada ano; se
 *   o ano digitado estiver fora da lista, as datas ficam como estão;
 * - trocar o **curso** troca os rótulos dos campos, porque um curso anual tem
 *   1º a 4º bimestre e um semestral tem 1º e 2º em cada semestre. As datas não
 *   se mexem: o que muda é só como cada campo se chama.
 */
(function () {
  var SUGESTOES = <?= json_encode($sugestoes, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var REGIMES   = <?= json_encode($regimePorCurso, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var ROTULOS   = <?= json_encode($rotulosPorRegime, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

  var ano   = document.getElementById('anoCalendario');
  var curso = document.querySelector('#modalCalendario [name="curso_id"]');
  if (!ano || !curso) { return; }

  ano.addEventListener('change', function () {
    var s = SUGESTOES[ano.value];
    if (!s) { return; }
    Object.keys(s).forEach(function (campo) {
      var el = ano.form.querySelector('[name="' + campo + '"]');
      if (el) { el.value = s[campo]; }
    });
  });

  function rotular() {
    var r = ROTULOS[REGIMES[curso.value] || 'semestral'];
    if (!r) { return; }
    curso.form.querySelectorAll('[data-rotulo]').forEach(function (el) {
      if (r[el.dataset.rotulo]) { el.textContent = r[el.dataset.rotulo]; }
    });
  }
  curso.addEventListener('change', rotular);
  rotular();
})();
</script>

<?php require __DIR__ . '/lib/valida_bimestres.php'; ?>
<?php endif; ?>

<?php foot(); ?>
