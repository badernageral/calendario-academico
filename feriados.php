<?php
require __DIR__ . '/lib/boot.php';

$db = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = post('acao');
    $id   = postInt('id');

    if ($acao === 'salvar') {
        $nome = post('nome');
        $tipo = post('tipo') === 'movel' ? 'movel' : 'fixo';
        $cat  = postInt('categoria_id') ?: null;
        $volta = 'feriados.php' . ($id ? '?editar=' . $id : '?novo=1');

        if ($nome === '') {
            flash('Informe o nome do feriado.', 'erro');
            redirect($volta);
        }
        if ($tipo === 'fixo') {
            $dia = postInt('dia', 0);
            $mes = postInt('mes', 0);
            // Aceita 29/02: o dia simplesmente não aparece em ano comum.
            if ($mes < 1 || $mes > 12 || $dia < 1 || $dia > (int) date('t', mktime(0, 0, 0, $mes ?: 1, 1, 2024))) {
                flash('Dia ou mês inválido para uma data fixa.', 'erro');
                redirect($volta);
            }
            $campos = [$nome, 'fixo', $dia, $mes, null, $cat];
        } else {
            $desl = postInt('deslocamento', 0);
            if ($desl < -200 || $desl > 200) {
                flash('O deslocamento em relação à Páscoa precisa ficar entre -200 e 200 dias.', 'erro');
                redirect($volta);
            }
            $campos = [$nome, 'movel', null, null, $desl, $cat];
        }

        if ($id) {
            $db->prepare('UPDATE feriados SET nome=?, tipo=?, dia=?, mes=?, deslocamento=?, categoria_id=?, ativo=? WHERE id=?')
               ->execute([...$campos, isset($_POST['ativo']) ? 1 : 0, $id]);
            flash('Feriado atualizado.');
        } else {
            $db->prepare('INSERT INTO feriados (nome, tipo, dia, mes, deslocamento, categoria_id, ativo) VALUES (?,?,?,?,?,?,?)')
               ->execute([...$campos, isset($_POST['ativo']) ? 1 : 0]);
            flash('Feriado cadastrado.');
        }
        redirect('feriados.php');
    }

    if ($acao === 'excluir') {
        $db->prepare('DELETE FROM feriados WHERE id = ?')->execute([$id]);
        flash('Feriado excluído. Ele sai dos calendários de todos os anos.');
        redirect('feriados.php');
    }
}

$edit = null;
if ($idEdit = getInt('editar')) {
    $st = $db->prepare('SELECT * FROM feriados WHERE id = ?');
    $st->execute([$idEdit]);
    $edit = $st->fetch() ?: null;
}

// Só as categorias de feriado e ponto facultativo fazem sentido aqui.
$cats = $db->query(
    "SELECT * FROM categorias WHERE nome LIKE 'Feriado%' OR nome = 'Ponto Facultativo' ORDER BY prioridade DESC"
)->fetchAll();

// A grade mostra as datas do ano em foco, para conferir de bate-pronto.
$ano = getInt('ano') ?: (int) ($db->query('SELECT MAX(ano) FROM eventos')->fetchColumn() ?: date('Y'));

// Os inativos não entram na grade — não valem para ano nenhum —, então ficam
// listados à parte: sem isso, desativar um feriado o tornaria inalcançável.
$inativos = $db->query('SELECT id, nome FROM feriados WHERE ativo = 0 ORDER BY nome')->fetchAll();
$quantos  = (int) $db->query('SELECT COUNT(*) FROM feriados')->fetchColumn();

$abrirModal = $edit !== null || get('novo') !== '';

// Dia clicado na grade: abre o modal já com a data fixa preenchida.
$diaPadrao = $edit['dia'] ?? (getInt('dia') ?: (int) date('j'));
$mesPadrao = $edit['mes'] ?? (getInt('mes') ?: (int) date('n'));

$eng = Engine::paraFeriados($db, $ano);

head('Feriados', 'feriados');
?>
<div class="card border-0 shadow-sm mb-3">
  <div class="card-body d-flex flex-wrap align-items-end gap-3">
    <form method="get">
      <label class="form-label mb-1">Ano</label>
      <input type="number" name="ano" class="form-control form-control-sm" style="width:110px"
             value="<?= $ano ?>" min="2000" max="2100" onchange="this.form.submit()">
    </form>

    <?php if ($edit): ?>
      <a class="btn btn-sm btn-primary" href="feriados.php?novo=1"><i class="bi bi-plus-lg me-1"></i>Novo feriado</a>
    <?php else: ?>
      <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modalFeriado">
        <i class="bi bi-plus-lg me-1"></i>Novo feriado
      </button>
    <?php endif; ?>

    <div class="ms-auto text-muted small">
      <span class="badge bg-light text-secondary border">
        <?= $quantos ?> feriado<?= $quantos === 1 ? '' : 's' ?> cadastrado<?= $quantos === 1 ? '' : 's' ?>
      </span>
      <?php if ($inativos): ?>
        <span class="ms-2">inativos:</span>
        <?php foreach ($inativos as $i): ?>
          <a class="ms-1" href="feriados.php?ano=<?= $ano ?>&editar=<?= (int) $i['id'] ?>"><?= e($i['nome']) ?></a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php $gradeFeriados = true; require __DIR__ . '/lib/grade_calendario.php'; ?>

<div class="modal fade" id="modalFeriado" tabindex="-1" aria-labelledby="tituloModalFeriado" <?= $abrirModal ? 'data-abrir="1"' : '' ?>>
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <form method="post">
        <div class="modal-header">
          <h5 class="modal-title" id="tituloModalFeriado">
            <i class="bi bi-flag me-2 text-primary"></i><?= $edit ? 'Editando: ' . e($edit['nome']) : 'Novo feriado' ?>
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
        </div>

        <div class="modal-body">
          <input type="hidden" name="acao" value="salvar">
          <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label">Nome</label>
              <input name="nome" class="form-control" required value="<?= e($edit['nome'] ?? '') ?>"
                     placeholder="Aniversário da cidade">
              <div class="form-text">Sai assim na lista do mês e no calendário impresso.</div>
            </div>
            <div class="col-md-4">
              <label class="form-label">Categoria (cor)</label>
              <select name="categoria_id" class="form-select">
                <?php foreach ($cats as $c): ?>
                  <option value="<?= $c['id'] ?>" <?= (int) ($edit['categoria_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
                    <?= e($c['nome']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12">
              <label class="form-label">Tipo de data</label>
              <?php $tipo = $edit['tipo'] ?? 'fixo'; ?>
              <div class="d-flex flex-wrap gap-4">
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="tipo" value="fixo" id="tipo_fixo"
                         <?= $tipo === 'fixo' ? 'checked' : '' ?>>
                  <label class="form-check-label" for="tipo_fixo">Data fixa — cai no mesmo dia todo ano</label>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="tipo" value="movel" id="tipo_movel"
                         <?= $tipo === 'movel' ? 'checked' : '' ?>>
                  <label class="form-check-label" for="tipo_movel">Móvel — anda com a Páscoa</label>
                </div>
              </div>
            </div>

            <div class="col-12" id="camposFixo">
              <div class="row g-3">
                <div class="col-md-3">
                  <label class="form-label">Dia</label>
                  <input type="number" name="dia" class="form-control" min="1" max="31"
                         value="<?= (int) $diaPadrao ?>">
                </div>
                <div class="col-md-5">
                  <label class="form-label">Mês</label>
                  <select name="mes" class="form-select">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                      <option value="<?= $m ?>" <?= (int) $mesPadrao === $m ? 'selected' : '' ?>>
                        <?= e(ucfirst(mesExtenso($m))) ?>
                      </option>
                    <?php endfor; ?>
                  </select>
                </div>
              </div>
            </div>

            <div class="col-12" id="camposMovel">
              <label class="form-label">Dias a contar do domingo de Páscoa</label>
              <input type="number" name="deslocamento" class="form-control" style="max-width:200px"
                     value="<?= (int) ($edit['deslocamento'] ?? 0) ?>" min="-200" max="200">
              <div class="form-text">
                Negativo é antes, positivo é depois: Carnaval <code>-48</code> e <code>-47</code>,
                Quarta-feira de Cinzas <code>-46</code>, Sexta-feira da Paixão <code>-2</code>,
                Corpus Christi <code>60</code>.
              </div>
            </div>

            <div class="col-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="ativo" id="ativo"
                       <?= ($edit === null || (int) $edit['ativo'] === 1) ? 'checked' : '' ?>>
                <label class="form-check-label" for="ativo">Ativo</label>
              </div>
              <div class="form-text">
                Desmarcado, o feriado fica guardado mas não entra em calendário nenhum — serve para
                tirar um feriado de circulação sem perder o cadastro.
              </div>
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <?php if ($edit): ?>
            <!-- O botão manda no formulário de exclusão, que fica fora deste
                 (form dentro de form não existe em HTML). -->
            <button type="submit" form="formExcluirFeriado" class="btn btn-outline-danger me-auto">
              <i class="bi bi-trash me-1"></i>Excluir
            </button>
            <a class="btn btn-outline-secondary" href="feriados.php?ano=<?= $ano ?>">Cancelar</a>
          <?php else: ?>
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <?php endif; ?>
          <button class="btn btn-primary"><i class="bi bi-check-lg me-1"></i><?= $edit ? 'Salvar' : 'Cadastrar' ?></button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php if ($edit): ?>
<form method="post" id="formExcluirFeriado"
      onsubmit="return confirm('Excluir <?= e($edit['nome']) ?>? Ele sai dos calendários de todos os anos.')">
  <input type="hidden" name="acao" value="excluir">
  <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
</form>
<?php endif; ?>

<script>
// Só o par de campos do tipo escolhido fica em cena.
(function () {
  var fixo  = document.getElementById('tipo_fixo'),
      movel = document.getElementById('tipo_movel'),
      cf    = document.getElementById('camposFixo'),
      cm    = document.getElementById('camposMovel');
  function sincronizar() {
    cf.style.display = fixo.checked ? '' : 'none';
    cm.style.display = movel.checked ? '' : 'none';
  }
  fixo.addEventListener('change', sincronizar);
  movel.addEventListener('change', sincronizar);
  sincronizar();
})();
</script>

<?php foot(); ?>
