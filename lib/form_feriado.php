<?php
/**
 * Formulário de feriado, em modal, o mesmo nas três telas que mostram a grade
 * do ano. Espera: $db, $ano, $voltarPara. Opcional: $feriadoEdit (o feriado em
 * edição, ou null) e $feriadoNovo (true = abrir já vazio, com o dia clicado na
 * grade em $diaPadrao/$mesPadrao).
 *
 * Fica em modal, e não numa tela própria, porque o feriado se altera de onde
 * ele está à vista: no calendário de um curso, na lista de eventos globais ou
 * no cadastro. Sair da tela para editá-lo custava o ano em foco e os filtros.
 *
 * O que se grava vale para todos os anos e para todos os cursos — o formulário
 * diz isso, para ninguém achar que está mexendo só no calendário aberto.
 */
$feriadoEdit ??= null;
$feriadoNovo ??= false;
$f_abrir     = $feriadoEdit !== null || $feriadoNovo;
$f_cats      = categoriasDeFeriado($db);
$f_tipo      = $feriadoEdit['tipo'] ?? 'fixo';
$f_dia       = $feriadoEdit['dia'] ?? (getInt('dia') ?: (int) date('j'));
$f_mes       = $feriadoEdit['mes'] ?? (getInt('mes') ?: (int) date('n'));
?>
<div class="modal fade" id="modalFeriado" tabindex="-1" aria-labelledby="tituloModalFeriado">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <form method="post">
        <?= csrfCampo() ?>
        <div class="modal-header">
          <h5 class="modal-title" id="tituloModalFeriado">
            <i class="bi bi-flag me-2 text-primary"></i><?= $feriadoEdit ? 'Editando: ' . e($feriadoEdit['nome']) : 'Novo feriado' ?>
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
        </div>

        <div class="modal-body">
          <input type="hidden" name="acao" value="salvar_feriado">
          <input type="hidden" name="id" value="<?= (int) ($feriadoEdit['id'] ?? 0) ?>">

          <div class="alert alert-light border d-flex mb-3" role="alert">
            <i class="bi bi-info-circle me-2 mt-1 text-primary"></i>
            <div class="small">
              O feriado é do cadastro, não deste calendário: o que mudar aqui vale para
              <strong>todos os cursos e todos os anos</strong>.
            </div>
          </div>

          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label">Nome</label>
              <input name="nome" class="form-control" required value="<?= e($feriadoEdit['nome'] ?? '') ?>"
                     placeholder="Aniversário da cidade">
              <div class="form-text">Sai assim na lista do mês e no calendário impresso.</div>
            </div>
            <div class="col-md-4">
              <?php
              // O mesmo dropdown do campo de categoria do evento: a cor de uma
              // <option> é sugestão que o Firefox no Linux ignora, então cada
              // linha é HTML de verdade e o retângulo aparece em todo navegador.
              // O tipo é o que decide a cor do dia na grade — vê-la aqui evita
              // escolher a origem errada da norma e só descobrir no papel.
              //
              // Sem linha "sem cor": o tipo é obrigatório, e o primeiro dos
              // quatro é o que vale quando o feriado é novo.
              $f_atual = $f_cats[array_key_first($f_cats)] ?? null;
              foreach ($f_cats as $f_c) {
                  if ((int) ($feriadoEdit['categoria_id'] ?? 0) === (int) $f_c['id']) {
                      $f_atual = $f_c;
                  }
              }
              ?>
              <label class="form-label" for="botaoTipoFeriado">Tipo</label>
              <div class="dropdown seletor-categoria">
                <button type="button" class="form-select text-start dropdown-toggle-sem-seta"
                        id="botaoTipoFeriado" data-bs-toggle="dropdown" data-bs-display="static"
                        aria-haspopup="listbox" aria-expanded="false">
                  <span class="retangulo-cor" style="background:<?= e($f_atual['cor'] ?? '#ffffff') ?>"></span>
                  <span class="rotulo"><?= e($f_atual['nome'] ?? 'Feriado Nacional') ?></span>
                </button>
                <ul class="dropdown-menu w-100" role="listbox" aria-labelledby="botaoTipoFeriado">
                  <?php foreach ($f_cats as $f_nome => $f_c): ?>
                    <?php $f_eh = (int) ($f_atual['id'] ?? 0) === (int) $f_c['id']; ?>
                    <li>
                      <button type="button" class="dropdown-item<?= $f_eh ? ' active' : '' ?>"
                              role="option" aria-selected="<?= $f_eh ? 'true' : 'false' ?>"
                              data-valor="<?= (int) $f_c['id'] ?>" data-cor="<?= e($f_c['cor']) ?>"
                              data-nome="<?= e($f_nome) ?>">
                        <span class="retangulo-cor" style="background:<?= e($f_c['cor']) ?>"></span>
                        <span><?= e($f_nome) ?></span>
                      </button>
                    </li>
                  <?php endforeach; ?>
                </ul>
                <input type="hidden" name="categoria_id" value="<?= (int) ($f_atual['id'] ?? 0) ?>">
              </div>
              <div class="form-text">
                A <a href="configuracoes.php">cor de cada tipo</a> fica em Configurações.
              </div>
            </div>

            <div class="col-12">
              <label class="form-label">Tipo de data</label>
              <div class="d-flex flex-wrap gap-4">
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="tipo" value="fixo" id="tipo_fixo"
                         <?= $f_tipo === 'fixo' ? 'checked' : '' ?>>
                  <label class="form-check-label" for="tipo_fixo">Data fixa — cai no mesmo dia todo ano</label>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="tipo" value="movel" id="tipo_movel"
                         <?= $f_tipo === 'movel' ? 'checked' : '' ?>>
                  <label class="form-check-label" for="tipo_movel">Móvel — anda com a Páscoa</label>
                </div>
              </div>
            </div>

            <div class="col-12" id="camposFixo">
              <label class="form-label">Dia e mês</label>
              <?php
              // Calendário, não dois selects: o feriado é um dia do ano, e é
              // assim que ele se procura. Só dia e mês entram no cadastro — o
              // ano não, porque a data se repete todo ano. A grade usa os dias
              // da semana do ano em foco, para "21 de abril" aparecer onde ele
              // vai cair de verdade no calendário que se está montando.
              ?>
              <div class="seletor-datas" id="seletorFeriado">
                <div class="topo-seletor">
                  <button type="button" class="btn btn-sm btn-outline-secondary" data-nav-mes="-1" aria-label="Mês anterior">
                    <i class="bi bi-chevron-left"></i>
                  </button>
                  <span class="mes-atual" id="feriadoMesNome"></span>
                  <button type="button" class="btn btn-sm btn-outline-secondary" data-nav-mes="1" aria-label="Próximo mês">
                    <i class="bi bi-chevron-right"></i>
                  </button>
                </div>
                <div class="semana-cabecalho">
                  <?php foreach (Engine::DOW_INICIAL as $f_ini): ?><span><?= $f_ini ?></span><?php endforeach; ?>
                </div>
                <div class="dias-seletor" id="feriadoDias"></div>
              </div>
              <div class="form-text" id="feriadoDica"></div>
              <input type="hidden" name="dia" id="feriadoDia" value="<?= (int) $f_dia ?>">
              <input type="hidden" name="mes" id="feriadoMes" value="<?= (int) $f_mes ?>">
            </div>

            <div class="col-12" id="camposMovel">
              <label class="form-label">Dias a contar do domingo de Páscoa</label>
              <input type="number" name="deslocamento" class="form-control" style="max-width:200px"
                     value="<?= (int) ($feriadoEdit['deslocamento'] ?? 0) ?>" min="-200" max="200">
              <div class="form-text">
                Negativo é antes, positivo é depois: Carnaval <code>-48</code> e <code>-47</code>,
                Quarta-feira de Cinzas <code>-46</code>, Sexta-feira da Paixão <code>-2</code>,
                Corpus Christi <code>60</code>.
              </div>
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <?php if ($feriadoEdit): ?>
            <a class="btn btn-outline-secondary" href="<?= e($voltarPara) ?>">Cancelar</a>
          <?php else: ?>
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <?php endif; ?>
          <button class="btn btn-primary"><i class="bi bi-check-lg me-1"></i><?= $feriadoEdit ? 'Salvar' : 'Cadastrar' ?></button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
/**
 * O calendário do dia fixo. Um mês por vez, sem ano à vista: o que se grava é
 * dia e mês, e a data se repete todo ano.
 *
 * Fevereiro sempre mostra 29 dias. O cadastro aceita 29 de fevereiro de
 * propósito — em ano comum o feriado simplesmente não aparece —, e sem a célula
 * não haveria como escolhê-lo num ano que não é bissexto. Ela entra logo depois
 * do dia 28, que é exatamente onde cai num ano bissexto.
 */
(function () {
  var MESES = <?= json_encode(array_map('ucfirst', array_map('mesExtenso', range(1, 12))), JSON_UNESCAPED_UNICODE) ?>;
  var ANO   = <?= (int) $ano ?>;

  var caixa  = document.getElementById('feriadoDias'),
      titulo = document.getElementById('feriadoMesNome'),
      dica   = document.getElementById('feriadoDica'),
      campoDia = document.getElementById('feriadoDia'),
      campoMes = document.getElementById('feriadoMes');
  if (!caixa) { return; }

  var mesAberto = parseInt(campoMes.value, 10) || 1;

  function ultimoDia(mes) {
    return mes === 2 ? 29 : new Date(ANO, mes, 0).getDate();
  }

  function desenhar() {
    titulo.textContent = MESES[mesAberto - 1];
    caixa.innerHTML = '';

    // Casas vazias até o dia 1 cair no seu dia da semana.
    var vazias = new Date(ANO, mesAberto - 1, 1).getDay();
    for (var v = 0; v < vazias; v++) {
      caixa.appendChild(document.createElement('span'));
    }

    var escolhido = parseInt(campoDia.value, 10);
    for (var d = 1; d <= ultimoDia(mesAberto); d++) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'dia-seletor';
      b.textContent = d;
      b.dataset.dia = d;
      if (d === escolhido && mesAberto === parseInt(campoMes.value, 10)) {
        b.classList.add('inicio');
      }
      caixa.appendChild(b);
    }
    dica.textContent = campoDia.value
      ? 'Todo ano em ' + campoDia.value + ' de ' + MESES[parseInt(campoMes.value, 10) - 1].toLowerCase() + '.'
      : 'Clique no dia do mês.';
  }

  caixa.addEventListener('click', function (ev) {
    var alvo = ev.target.closest('.dia-seletor');
    if (!alvo) { return; }
    campoDia.value = alvo.dataset.dia;
    campoMes.value = mesAberto;
    desenhar();
  });

  document.querySelectorAll('[data-nav-mes]').forEach(function (b) {
    b.addEventListener('click', function () {
      mesAberto += parseInt(b.dataset.navMes, 10);
      if (mesAberto < 1)  { mesAberto = 12; }
      if (mesAberto > 12) { mesAberto = 1; }
      desenhar();
    });
  });

  desenhar();
})();

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

<?php if ($f_abrir): ?>
<script>
// O bundle do Bootstrap só é lido no fim da página.
document.addEventListener('DOMContentLoaded', function () {
  var m = document.getElementById('modalFeriado');
  new bootstrap.Modal(m).show();
  m.addEventListener('shown.bs.modal', function () {
    var campo = m.querySelector('input[name="nome"]');
    if (campo) { campo.focus(); }
  });
});
</script>
<?php endif; ?>
<?php unset($f_abrir, $f_cats, $f_tipo, $f_dia, $f_mes, $f_nome, $f_c, $f_ini, $f_atual, $f_eh); ?>
