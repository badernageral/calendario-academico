<?php
/**
 * Formulário de evento, em modal, o mesmo nas duas telas. Espera: $db, $cats,
 * $ev (array|null), $baseComum (bool), $ano, $voltarPara. Opcional:
 * $dataPadrao (dia clicado na grade).
 *
 * $baseComum = true é a tela de eventos globais, onde tudo o que se cadastra é
 * global. Dentro de um calendário o campo "Abrangência" decide: local vale só
 * para aquele calendário, global vale para todos os calendários do ano.
 *
 * O modal abre sozinho quando a página vem de "editar", de um dia clicado na
 * grade ou do botão "Novo evento" — nesses casos a URL traz o que preencher, e
 * é o PHP que monta o formulário já pronto.
 */
$ev ??= null;
$dataPadrao ??= '';
$abrirModal = $ev !== null || $dataPadrao !== '' || get('novo') !== '';
?>
<div class="modal fade" id="modalEvento" tabindex="-1" aria-labelledby="tituloModalEvento">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <form method="post">
        <div class="modal-header">
          <h5 class="modal-title" id="tituloModalEvento">
            <i class="bi bi-calendar-plus me-2 text-primary"></i><?= $ev ? 'Editando evento' : 'Novo evento' ?>
            <?= $dataPadrao ? '<span class="badge bg-primary-subtle text-primary-emphasis ms-1">' . e(dataBr($dataPadrao)) . '</span>' : '' ?>
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
        </div>

        <div class="modal-body">
          <input type="hidden" name="acao" value="salvar_evento">
          <input type="hidden" name="id" value="<?= (int) ($ev['id'] ?? 0) ?>">
          <?php if (!$baseComum): ?>
            <input type="hidden" name="cal_id" value="<?= (int) ($cal['id'] ?? 0) ?>">
          <?php endif; ?>

          <div class="row g-3">
            <div class="col-12">
              <label class="form-label">Descrição</label>
              <input name="descricao" class="form-control" required value="<?= e($ev['descricao'] ?? '') ?>"
                     placeholder="Início do 1º bimestre e 1º semestre letivo">
              <div class="form-text">Sem o prefixo de data — ele é gerado sozinho a partir das datas abaixo.</div>
            </div>

            <?php
            // Faixas que o seletor já mostra ao abrir: as do evento em edição
            // ou o dia clicado na grade.
            $faixasIniciais = $ev
                ? array_map(static fn ($d) => ['inicio' => $d['inicio'], 'fim' => $d['fim']], $ev['datas'])
                : ($dataPadrao !== '' ? [['inicio' => $dataPadrao, 'fim' => $dataPadrao]] : []);
            ?>
            <div class="col-md-6">
              <label class="form-label">Período</label>
              <div class="seletor-datas" id="seletorDatas">
                <div class="topo-seletor">
                  <button type="button" class="btn btn-sm btn-outline-secondary" data-nav="-1" aria-label="Mês anterior">
                    <i class="bi bi-chevron-left"></i>
                  </button>
                  <span class="mes-atual" id="seletorMes"></span>
                  <button type="button" class="btn btn-sm btn-outline-secondary" data-nav="1" aria-label="Próximo mês">
                    <i class="bi bi-chevron-right"></i>
                  </button>
                </div>
                <div class="semana-cabecalho">
                  <?php foreach (Engine::DOW_INICIAL as $ini): ?><span><?= $ini ?></span><?php endforeach; ?>
                </div>
                <div class="dias-seletor" id="seletorDias"></div>
              </div>
              <div class="form-text" id="seletorDica">Clique no primeiro dia e depois no último.</div>
              <input type="hidden" name="datas" id="campoDatas" value="<?= e(faixasParaTexto($faixasIniciais)) ?>">
            </div>

            <div class="col-md-6">
              <div class="row g-3">
                <div class="col-12">
                  <label class="form-label">Períodos escolhidos</label>
                  <ul class="faixas-escolhidas" id="faixasEscolhidas"></ul>
                  <div class="form-text">Dá para escolher mais de um: o rótulo vira “14 a 16 e 19 a 20”.</div>
                </div>
                <div class="col-12">
                  <label class="form-label">Categoria (cor)</label>
                  <select name="categoria_id" class="form-select">
                    <option value="">— sem cor, só na lista —</option>
                    <?php foreach ($cats as $c): ?>
                      <?php if ((int) $c['oculta'] === 1) continue; ?>
                      <option value="<?= $c['id'] ?>" <?= (int) ($ev['categoria_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
                        <?= e($c['nome']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-12">
                  <label class="form-label">Rótulo de datas</label>
                  <input name="rotulo" class="form-control" value="<?= e($ev['rotulo'] ?? '') ?>" placeholder="automático">
                  <div class="form-text">Só se precisar fugir do padrão, ex.: <code>5 e 6</code> no lugar de <code>5 a 6</code>.</div>
                </div>
              </div>
            </div>

            <div class="col-md-4">
              <label class="form-label">Conta como letivo</label>
              <?php $cl = $ev ? ($ev['conta_letivo'] === null ? '' : (string) $ev['conta_letivo']) : ''; ?>
              <select name="conta_letivo" class="form-select">
                <option value=""  <?= $cl === ''  ? 'selected' : '' ?>>Herdar da categoria</option>
                <option value="1" <?= $cl === '1' ? 'selected' : '' ?>>Sim — conta como letivo</option>
                <option value="0" <?= $cl === '0' ? 'selected' : '' ?>>Não — não conta como letivo</option>
              </select>
            </div>

            <div class="col-md-4">
              <label class="form-label">Funciona com horário de</label>
              <?php $rd = $ev && $ev['repoe_dow'] !== null ? (string) $ev['repoe_dow'] : ''; ?>
              <select name="repoe_dow" class="form-select">
                <option value="">— não é reposição —</option>
                <?php foreach (Engine::DOW_NOME as $i => $nome): ?>
                  <?php
                  // Reposição repõe aula de dia útil: horário de sábado ou de
                  // domingo não existe na grade de aulas.
                  if ($i === 0 || $i === 6) {
                      continue;
                  }
                  ?>
                  <option value="<?= $i ?>" <?= $rd === (string) $i ? 'selected' : '' ?>><?= e(ucfirst($nome)) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">Alimenta as notas “4 sábados letivos com horário de segunda”.</div>
            </div>

            <?php
            // Editando: o escopo vem do próprio evento. Novo: local, que é o
            // caso comum de quem está dentro de um calendário.
            $ehGlobal = $baseComum || ($ev && $ev['calendario_id'] === null);
            ?>
            <?php if (!$baseComum): ?>
            <div class="col-md-4">
              <label class="form-label">Abrangência</label>
              <select name="escopo" id="escopoEvento" class="form-select">
                <option value="local"  <?= $ehGlobal ? '' : 'selected' ?>>Local — só deste calendário</option>
                <option value="global" <?= $ehGlobal ? 'selected' : '' ?>>Global — todos os calendários de <?= (int) $ano ?></option>
              </select>
              <div class="form-text">Global é o mesmo que cadastrar em <em>Eventos globais</em>.</div>
            </div>
            <?php else: ?>
              <input type="hidden" name="escopo" value="global">
            <?php endif; ?>

            <div class="col-12" id="campoNivel">
              <label class="form-label">Vale para os níveis</label>
              <?php
              // Sem restrição no banco (evento novo ou global de todos os cursos)
              // = todos marcados na tela. Desmarcar é que restringe.
              $niveisMarcados = niveisDoEvento($ev['nivel'] ?? null) ?: array_keys(niveisCurso());
              ?>
              <div class="d-flex flex-wrap align-items-center gap-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="nivel_todos">
                  <label class="form-check-label fw-semibold" for="nivel_todos">Todos</label>
                </div>
                <span class="vr"></span>
                <?php foreach (niveisCurso() as $k => $v): ?>
                  <div class="form-check">
                    <input class="form-check-input nivel-item" type="checkbox" name="nivel[]" value="<?= $k ?>"
                           id="nivel_<?= $k ?>" <?= in_array($k, $niveisMarcados, true) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="nivel_<?= $k ?>"><?= $v ?></label>
                  </div>
                <?php endforeach; ?>
              </div>
              <div class="form-text">
                Todos marcados = vale para todos os cursos. Desmarque para restringir o evento
                a certos níveis. Só tem efeito em evento global; os níveis se cadastram
                em <a href="niveis.php">Níveis</a>.
              </div>
            </div>

            <div class="col-12 d-flex flex-wrap gap-4">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="pinta_dias" id="pinta_dias"
                       <?= ($ev === null || (int) $ev['pinta_dias'] === 1) ? 'checked' : '' ?>>
                <label class="form-check-label" for="pinta_dias">Pintar os dias na grade</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="negrito" id="negrito"
                       <?= ($ev && (int) $ev['negrito'] === 1) ? 'checked' : '' ?>>
                <label class="form-check-label" for="negrito">Negrito na lista</label>
              </div>
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <?php if ($ev): ?>
            <a class="btn btn-outline-secondary" href="<?= e($voltarPara) ?>">Cancelar</a>
          <?php else: ?>
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <?php endif; ?>
          <button class="btn btn-primary"><i class="bi bi-check-lg me-1"></i><?= $ev ? 'Salvar evento' : 'Adicionar evento' ?></button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
/**
 * Seletor de período: um calendário só, dentro do ano do evento. O primeiro
 * clique marca o início, o segundo fecha a faixa — clicar duas vezes no mesmo
 * dia vale como dia único. As faixas escolhidas viram texto no campo oculto
 * "datas", que é o mesmo formato que o servidor já sabia ler.
 */
(function () {
  var ANO    = <?= (int) $ano ?>;
  var faixas = <?= json_encode($faixasIniciais, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var MESES  = ['Janeiro','Fevereiro','Março','Abril','Maio','Junho',
                'Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];

  var caixaDias = document.getElementById('seletorDias'),
      rotuloMes = document.getElementById('seletorMes'),
      campo     = document.getElementById('campoDatas'),
      lista     = document.getElementById('faixasEscolhidas'),
      dica      = document.getElementById('seletorDica'),
      seletor   = document.getElementById('seletorDatas');

  // Começa no mês da primeira faixa; sem faixa nenhuma, em janeiro.
  var mes = faixas.length ? parseInt(faixas[0].inicio.substr(5, 2), 10) - 1 : 0;
  var inicioPendente = null, sobre = null;

  function iso(a, m, d) {
    return a + '-' + String(m + 1).padStart(2, '0') + '-' + String(d).padStart(2, '0');
  }
  function br(s) { var p = s.split('-'); return p[2] + '/' + p[1] + '/' + p[0]; }
  function dentro(dia, a, b) { return dia >= (a < b ? a : b) && dia <= (a < b ? b : a); }
  function emFaixa(dia) {
    return faixas.some(function (f) { return dia >= f.inicio && dia <= f.fim; });
  }

  function sincronizar() {
    campo.value = faixas.map(function (f) {
      return f.inicio === f.fim ? br(f.inicio) : br(f.inicio) + ' a ' + br(f.fim);
    }).join('\n');

    lista.innerHTML = '';
    if (!faixas.length) {
      lista.innerHTML = '<li class="vazia">Nenhum período escolhido ainda.</li>';
      return;
    }
    faixas.forEach(function (f, i) {
      var li = document.createElement('li');
      li.innerHTML = '<span>' + (f.inicio === f.fim ? br(f.inicio) : br(f.inicio) + ' a ' + br(f.fim)) + '</span>';
      var x = document.createElement('button');
      x.type = 'button';
      x.className = 'btn-close btn-close-sm';
      x.title = 'Remover';
      x.addEventListener('click', function () { faixas.splice(i, 1); sincronizar(); desenhar(); });
      li.appendChild(x);
      lista.appendChild(li);
    });
  }

  function desenhar() {
    rotuloMes.textContent = MESES[mes] + ' de ' + ANO;
    seletor.querySelector('[data-nav="-1"]').disabled = mes === 0;
    seletor.querySelector('[data-nav="1"]').disabled  = mes === 11;

    caixaDias.innerHTML = '';
    var primeiro = new Date(ANO, mes, 1).getDay();
    var ultimo   = new Date(ANO, mes + 1, 0).getDate();

    for (var v = 0; v < primeiro; v++) {
      caixaDias.appendChild(document.createElement('span'));
    }
    for (var d = 1; d <= ultimo; d++) {
      var data = iso(ANO, mes, d);
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'dia-seletor';
      b.textContent = d;
      b.dataset.data = data;
      if (emFaixa(data)) { b.classList.add('escolhido'); }
      if (inicioPendente === data) { b.classList.add('inicio'); }
      if (inicioPendente && sobre && dentro(data, inicioPendente, sobre)) { b.classList.add('previa'); }
      caixaDias.appendChild(b);
    }
  }

  caixaDias.addEventListener('click', function (ev) {
    var alvo = ev.target.closest('.dia-seletor');
    if (!alvo) { return; }
    var data = alvo.dataset.data;

    if (!inicioPendente) {
      inicioPendente = data;
      dica.textContent = 'Agora clique no último dia do período.';
    } else {
      var a = inicioPendente, b = data;
      faixas.push({ inicio: a < b ? a : b, fim: a < b ? b : a });
      faixas.sort(function (x, y) { return x.inicio < y.inicio ? -1 : 1; });
      inicioPendente = null;
      sobre = null;
      dica.textContent = 'Clique no primeiro dia e depois no último.';
      sincronizar();
    }
    desenhar();
  });

  caixaDias.addEventListener('mouseover', function (ev) {
    var alvo = ev.target.closest('.dia-seletor');
    if (alvo && inicioPendente) { sobre = alvo.dataset.data; desenhar(); }
  });

  seletor.addEventListener('click', function (ev) {
    var nav = ev.target.closest('[data-nav]');
    if (!nav) { return; }
    mes = Math.min(11, Math.max(0, mes + parseInt(nav.dataset.nav, 10)));
    desenhar();
  });

  // Sem período escolhido não há evento: avisa antes de mandar ao servidor.
  campo.form.addEventListener('submit', function (ev) {
    if (!faixas.length) {
      ev.preventDefault();
      dica.textContent = 'Escolha ao menos um período no calendário.';
      dica.classList.add('text-danger');
    }
  });

  sincronizar();
  desenhar();
})();
</script>

<script>
/**
 * "Todos" marca e desmarca os quatro níveis de uma vez. Ele fica meio marcado
 * (indeterminate) quando só parte está escolhida, e nunca é enviado ao
 * servidor — quem vale é a marcação de cada nível.
 */
(function () {
  var todos = document.getElementById('nivel_todos'),
      itens = Array.prototype.slice.call(document.querySelectorAll('.nivel-item'));

  function sincronizarTodos() {
    var marcados = itens.filter(function (i) { return i.checked; }).length;
    todos.checked       = marcados === itens.length;
    todos.indeterminate = marcados > 0 && marcados < itens.length;
  }

  todos.addEventListener('change', function () {
    itens.forEach(function (i) { i.checked = todos.checked; });
    todos.indeterminate = false;
  });
  itens.forEach(function (i) { i.addEventListener('change', sincronizarTodos); });
  sincronizarTodos();
})();
</script>

<?php if (!$baseComum): ?>
<script>
// O nível restringe um evento global a um tipo de curso; num evento local ele
// não teria efeito nenhum, então some de cena.
(function () {
  var escopo = document.getElementById('escopoEvento'),
      campo  = document.getElementById('campoNivel');
  function sincronizar() { campo.style.display = escopo.value === 'global' ? '' : 'none'; }
  escopo.addEventListener('change', sincronizar);
  sincronizar();
})();
</script>
<?php endif; ?>

<?php if ($abrirModal): ?>
<script>
// O bundle do Bootstrap é carregado no fim da página, então só dá para abrir
// o modal depois que o HTML terminou de ser lido.
document.addEventListener('DOMContentLoaded', function () {
  var m = new bootstrap.Modal(document.getElementById('modalEvento'));
  m.show();
  var campo = document.querySelector('#modalEvento input[name="descricao"]');
  document.getElementById('modalEvento').addEventListener('shown.bs.modal', function () {
    if (campo) { campo.focus(); }
  });
});
</script>
<?php endif; ?>
