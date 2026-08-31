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
            flash('O ano precisa ficar entre ' . ANO_MIN . ' e ' . ANO_MAX . '.', 'erro');
            redirect('calendarios.php?novo=1');
        }
        // Os rótulos do erro dependem do regime do curso escolhido.
        [$bimestres, $erro] = bimestresDoFormulario(regimeDoCurso($db, $curso), $ano);
        if ($erro !== '') {
            flash($erro, 'erro');
            redirect('calendarios.php?novo=1');
        }

        // O calendário e os períodos dele vão juntos: um calendário sem período
        // conta o ano inteiro como letivo, e é um estado que ninguém pediu.
        $db->beginTransaction();
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
            salvarPeriodos($db, $novoId, $bimestres);
            $db->commit();
        } catch (PDOException $ex) {
            $db->rollBack();
            flash('Já existe um calendário desse curso para ' . $ano . '.', 'erro');
            redirect('calendarios.php?novo=1');
        } catch (Throwable $ex) {
            $db->rollBack();
            throw $ex;
        }

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
        $sel->execute([$ev['id']]);
        $faixas = [];
        $delta  = $anoDestino - (int) $ev['ano'];
        foreach ($sel as $d) {
            $faixas[] = [
                'inicio' => deslocarAno($d['inicio'], $delta),
                'fim'    => deslocarAno($d['fim'], $delta),
            ];
        }
        // Evento sem faixa não existe no calendário — o motor o descarta. Copiá-lo
        // só deixaria uma linha invisível no banco. É o que copiarEventosGlobais()
        // já fazia; aqui faltava.
        if (!$faixas) {
            continue;
        }
        $ins->execute([
            $anoDestino, $para, $ev['categoria_id'], $ev['descricao'], $ev['pinta_dias'],
            $ev['negrito'], $ev['conta_letivo'], $ev['rotulo'], $ev['nivel'], $ev['repoe_dow'],
        ]);
        salvarFaixas($db, (int) $db->lastInsertId(), $faixas);
    }
}

$cursos = $db->query('SELECT * FROM cursos WHERE ativo = 1 ORDER BY nome')->fetchAll();
$cals   = $db->query(
    'SELECT c.*, cu.nome AS curso_nome,
            (SELECT COUNT(*) FROM eventos e WHERE e.calendario_id = c.id) AS n_eventos
     FROM calendarios c JOIN cursos cu ON cu.id = c.curso_id
     ORDER BY c.ano DESC, cu.nome'
)->fetchAll();

// O formulário abre com as datas prováveis dos bimestres já preenchidas. Como
// elas dependem do ano — e dos feriados dele —, vai uma sugestão por ano à mão
// do formulário, para as datas acompanharem a troca do ano sem recarregar.
//
// Cobre exatamente a faixa que o campo de ano aceita, e não uma janela em volta
// do ano corrente: com sete anos, quem passasse do último via as datas pararem
// de acompanhar sem nada dizer por quê. São 101 anos, 5 ms e 21 KB — e some a
// borda, porque não existe ano digitável que fique de fora.
$anoPadrao = (int) date('Y');
$sugestoes = [];
for ($a = ANO_MIN; $a <= ANO_MAX; $a++) {
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

// Com o modal abrindo, o erro vai para dentro dele; o head() imprime o que
// sobrar, que é o caso de um "Calendário excluído.".
$erroModal = modalAbrindo() ? erroParaModal() : '';

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
          <tr><th>Curso</th><th>Ano</th><th>Situação</th><th class="text-center">Eventos locais</th><th class="text-end">Ações</th></tr>
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

<?php $calEdit = null; require __DIR__ . '/lib/form_calendario.php'; ?>

<script>
/**
 * O que muda sozinho neste formulário:
 *
 * - trocar o **ano** troca as datas sugeridas dos bimestres. As sugestões vêm
 *   prontas do servidor, que é quem sabe onde caem os feriados de cada ano, e
 *   cobrem toda a faixa que o campo aceita — nenhum ano digitável fica de fora;
 * - trocar o **ano** acerta também o ano do "local e data", que é texto livre e
 *   ficava para trás;
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
    // Sem sugestão o ano está fora da faixa que o campo aceita, e aí nada se
    // mexe: acertar o texto e deixar as datas do ano anterior seria pior do que
    // não acertar nada, porque as duas coisas passariam a discordar na tela.
    var s = SUGESTOES[ano.value];
    if (!s) { return; }

    acertarLocalEData();
    Object.keys(s).forEach(function (campo) {
      var el = ano.form.querySelector('[name="' + campo + '"]');
      if (el) { el.value = s[campo]; }
    });
  });

  /**
   * O "local e data" acompanha o ano digitado: trocar 2026 por 2027 no campo do
   * ano deixava "Lagoa da Confusão, agosto de 2026" para trás, e quem cadastrava
   * tinha de corrigir à mão — quando lembrava.
   *
   * Troca só o ano, e o último que houver no texto: o resto é a cidade e o mês,
   * que quem cadastra pode ter ajustado e não são nossos para reescrever. Texto
   * sem ano nenhum fica como está, porque aí não há o que acertar.
   */
  function acertarLocalEData() {
    var campo = ano.form.querySelector('[name="local_texto"]');
    if (!campo || !/\d{4}/.test(campo.value)) { return; }
    campo.value = campo.value.replace(/(\d{4})(?!.*\d{4})/, ano.value);
  }

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

<?php endif; ?>

<?php foot(); ?>
