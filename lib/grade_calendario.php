<?php
/**
 * Grade anual clicável: a mesma cara da impressão — nome do mês em verde,
 * cabeçalho dos dias úteis em azul, dias pintados pela categoria e a contagem
 * de dias letivos no rodapé de cada mês — só que cada dia abre o modal de
 * edição dos eventos daquele dia.
 *
 * Espera: $eng (Engine) e $ano. Em um calendário, mais $id; na tela de eventos
 * globais, $gradeGlobal = true — ali não há semestres, então as linhas de
 * contagem de dias letivos ficam de fora, e todo evento é editável e apagável.
 * Na tela de feriados, $gradeFeriados = true: a grade mostra só os feriados e
 * tudo aponta para o cadastro deles.
 *
 * Todas as variáveis daqui usam o prefixo g_ de propósito: este arquivo roda
 * no mesmo escopo da página, e nomes curtos como $ev ou $cats atropelariam os
 * do formulário e da lista de eventos, que são incluídos logo depois.
 */

$g_feriados = !empty($gradeFeriados);
$g_global   = !empty($gradeGlobal) || $g_feriados;   // as duas telas são de um ano, sem curso
$g_url      = $g_feriados ? 'feriados.php?ano=' . $ano . '&'
            : ($g_global ? 'eventos.php?ano=' . $ano . '&' : 'calendario.php?id=' . $id . '&');
$g_coisa    = $g_feriados ? 'feriado' : 'evento';

$g_cats = $eng->categorias();

// A legenda embaixo da grade segue a mesma ordem da impressa. Na tela de
// feriados só entram as cores que podem aparecer ali.
$g_legenda = array_filter($g_cats, static fn ($c) => (int) $c['na_legenda'] === 1
    && (!$g_feriados || str_starts_with($c['nome'], 'Feriado') || $c['nome'] === 'Ponto Facultativo'));
uasort($g_legenda, static fn ($a, $b) => [(int) $a['ordem'], $a['nome']] <=> [(int) $b['ordem'], $b['nome']]);

/** Tudo o que o modal precisa saber, por dia. Vai para o JS como JSON. */
$g_dias = [];
foreach (array_keys(Engine::MESES) as $g_mes) {
    foreach ($eng->semanas($g_mes) as $g_semana) {
        foreach ($g_semana as $g_iso) {
            if ($g_iso === null) {
                continue;
            }
            $g_dia = $eng->dia($g_iso);
            $g_eventos = [];
            foreach ($g_dia['eventos'] as $g_eid) {
                $g_ev = $eng->eventos()[$g_eid] ?? null;
                if (!$g_ev) {
                    continue;
                }
                $g_cat = $g_ev['categoria_id'] !== null ? ($g_cats[(int) $g_ev['categoria_id']] ?? null) : null;
                $g_eventos[] = [
                    'id'      => $g_ev['id'] === null ? null : (int) $g_ev['id'],
                    'desc'    => $g_ev['descricao'],
                    'cat'     => $g_cat['nome'] ?? null,
                    'cor'     => $g_cat['cor'] ?? null,
                    'base'    => $g_ev['calendario_id'] === null,
                    'feriado' => $g_ev['feriado_id'] ?? null,   // id do cadastro, quando é feriado
                ];
            }
            $g_dias[$g_iso] = [
                'letivo'  => (bool) $g_dia['letivo'],
                'cat'     => $g_dia['categoria']['nome'] ?? null,
                'eventos' => $g_eventos,
            ];
        }
    }
}
unset($g_mes, $g_semana, $g_iso, $g_dia, $g_eventos, $g_eid, $g_ev, $g_cat);

/** Eventos por mês do primeiro dia, na mesma ordem da impressão. */
$g_porMes = [];
foreach ($eng->eventos() as $g_ev) {
    $g_ini = $g_ev['datas'][0]['inicio'];
    if ((int) substr($g_ini, 0, 4) !== (int) $ano) {
        continue;
    }
    $g_porMes[(int) substr($g_ini, 5, 2)][] = ['ini' => $g_ini, 'ev' => $g_ev];
}
foreach ($g_porMes as &$g_lista) {
    usort($g_lista, static fn ($a, $b) => [$a['ini'], $a['ev']['id']] <=> [$b['ini'], $b['ev']['id']]);
}
unset($g_lista, $g_ev, $g_ini);
?>
<style>
/* Cores fixas da grade, de Configurações — o que não vem da legenda. */
.grade-anual {
  --dia-util: <?= e(cfg('cor_dia_util')) ?>;
  --dia-fds:  <?= e(cfg('cor_dia_fds')) ?>;
  --mes:      <?= e(cfg('cor_mes')) ?>;
  --dow:      <?= e(cfg('cor_dow')) ?>;
}
</style>
<div class="card border-0 shadow-sm mb-4">
  <div class="card-header bg-transparent fw-semibold d-flex justify-content-between align-items-center">
    <span><i class="bi bi-grid-3x3 me-2 text-primary"></i><?php
      echo $g_feriados ? 'Feriados em ' : ($g_global ? 'Eventos globais de ' : 'Calendário de '); ?><?= (int) $ano ?></span>
    <span class="small text-muted d-none d-md-inline">
      Clique em um dia para ver e alterar os <?= $g_coisa ?>s
    </span>
  </div>
  <div class="card-body">
    <div class="d-flex flex-wrap gap-3 mb-3 pb-3 border-bottom legenda-grade">
      <?php foreach ($g_legenda as $g_c): ?>
        <span class="small text-muted d-inline-flex align-items-center">
          <span class="amostra me-1" style="background:<?= e($g_c['cor']) ?>"></span><?= e($g_c['nome']) ?>
        </span>
      <?php endforeach; ?>
    </div>

    <div class="grade-anual">
      <?php foreach (Engine::MESES as $g_mes => $g_nomeMes): ?>
        <?php $g_cont = $g_global ? null : $eng->contagemMes($g_mes); ?>
        <div class="mes-coluna">
        <table class="mes-grade">
          <tr class="nome-mes"><th colspan="7"><?= e($g_nomeMes) ?></th></tr>
          <tr class="dow">
            <?php foreach (Engine::DOW_INICIAL as $g_i => $g_ini): ?>
              <th class="<?= ($g_i === 0 || $g_i === 6) ? 'fds' : 'util' ?>"><?= $g_ini ?></th>
            <?php endforeach; ?>
          </tr>
          <?php foreach ($eng->semanas($g_mes) as $g_semana): ?>
            <tr>
              <?php foreach ($g_semana as $g_i => $g_iso): ?>
                <?php
                if ($g_iso === null) {
                    echo '<td class="' . (($g_i === 0 || $g_i === 6) ? 'vazio-fds' : 'vazio') . '"></td>';
                    continue;
                }
                $g_dia  = $eng->dia($g_iso);
                $g_cat  = $g_dia['categoria'];
                $g_est  = $g_cat ? 'background:' . e($g_cat['cor']) . ';color:' . e($g_cat['cor_texto']) . ';' : '';
                $g_tem  = $g_dias[$g_iso]['eventos'] !== [];
                $g_tit  = $g_tem
                    ? count($g_dias[$g_iso]['eventos']) . ' evento(s)'
                    : ($g_cat['nome'] ?? '');
                ?>
                <td class="dia<?= ($g_i === 0 || $g_i === 6) ? ' fds' : '' ?><?= $g_tem ? ' tem-evento' : '' ?>" style="<?= $g_est ?>"
                    data-dia="<?= $g_iso ?>" title="<?= e($g_tit) ?>"
                    data-bs-toggle="modal" data-bs-target="#modalDia"><?= (int) substr($g_iso, 8, 2) ?></td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
          <?php if (!$g_global): ?>
          <tr class="contagem">
            <td></td>
            <?php for ($g_dw = 1; $g_dw <= 6; $g_dw++): ?><td><?= $g_cont['por_dow'][$g_dw] ?: '' ?></td><?php endfor; ?>
          </tr>
          <tr class="total">
            <td><?= $g_cont['total'] ?></td>
            <td colspan="6">Dias letivos</td>
          </tr>
          <?php endif; ?>
        </table>

        <ul class="eventos-mes">
          <?php if (empty($g_porMes[$g_mes])): ?>
            <li class="sem-evento">sem <?= $g_coisa ?>s neste mês</li>
          <?php endif; ?>
          <?php foreach ($g_porMes[$g_mes] ?? [] as $g_item): ?>
            <?php
            $g_ev   = $g_item['ev'];
            $g_base = $g_ev['calendario_id'] === null;
            $g_cat  = $g_ev['categoria_id'] !== null ? ($g_cats[(int) $g_ev['categoria_id']] ?? null) : null;
            // Feriado vem do cadastro e vale para todo ano: a edição dele é lá.
            $g_feriado = isset($g_ev['feriado_id']);
            $g_link = $g_feriado
                ? 'feriados.php?editar=' . (int) $g_ev['feriado_id']
                : $g_url . 'editar_evento=' . (int) $g_ev['id'];
            ?>
            <li>
              <span class="chip" style="background:<?= e($g_cat['cor'] ?? 'transparent') ?>"
                    title="<?= e($g_cat['nome'] ?? 'sem categoria') ?>"></span>
              <a href="<?= e($g_link) ?>" class="<?= (int) $g_ev['negrito'] === 1 ? 'negrito' : '' ?>"
                 title="<?= e(($g_cat['nome'] ?? 'sem categoria') . ($g_feriado ? ' · feriado, vale para todos os anos' : (!$g_global && $g_base ? ' · evento global' : ''))) ?>">
                <?= e($eng->rotulo($g_ev)) ?> - <?= e($eng->descricaoNaLista($g_ev)) ?>
                <?= $g_feriado && !$g_feriados ? '<span class="marca">feriado</span>' : '' ?>
                <?= (!$g_global && $g_base && !$g_feriado) ? '<span class="marca">global</span>' : '' ?>
                <?php if ($g_global && !$g_feriado && niveisRotulo($g_ev['nivel']) !== ''): ?>
                  <span class="marca"><?= e(niveisRotulo($g_ev['nivel'])) ?></span>
                <?php endif; ?>
              </a>
              <?php if (!$g_feriado && ($g_global || !$g_base)): ?>
              <form method="post" onsubmit="return confirm('Excluir este evento?')">
                <input type="hidden" name="acao" value="excluir_evento">
                <input type="hidden" name="id" value="<?= (int) $g_ev['id'] ?>">
                <button class="apagar" title="Excluir"><i class="bi bi-x-lg"></i></button>
              </form>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="modal fade" id="modalDia" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="modalDiaTitulo">Dia</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="modalDiaCorpo"></div>
      <div class="modal-footer">
        <a href="#" class="btn btn-primary btn-sm" id="modalDiaNovo">
          <i class="bi bi-plus-lg me-1"></i>Novo <?= $g_coisa ?> neste dia
        </a>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Fechar</button>
      </div>
    </div>
  </div>
</div>

<script type="application/json" id="dados-dias"><?= json_encode($g_dias, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script>
(function () {
  var DIAS = JSON.parse(document.getElementById('dados-dias').textContent);
  var URL = <?= json_encode($g_url) ?>;
  var SEMANA = ['domingo','segunda-feira','terça-feira','quarta-feira','quinta-feira','sexta-feira','sábado'];

  function esc(s) {
    return String(s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  document.getElementById('modalDia').addEventListener('show.bs.modal', function (ev) {
    var cel = ev.relatedTarget;
    if (!cel) { return; }
    var iso = cel.dataset.dia, dia = DIAS[iso] || { eventos: [], letivo: false };
    var p = iso.split('-'), data = new Date(+p[0], +p[1] - 1, +p[2]);

    document.getElementById('modalDiaTitulo').textContent =
      p[2] + '/' + p[1] + '/' + p[0] + ' · ' + SEMANA[data.getDay()];

    var html = '<p class="mb-3">' +
      (dia.letivo ? '<span class="badge bg-success-subtle text-success-emphasis">dia letivo</span>'
                  : '<span class="badge bg-danger-subtle text-danger-emphasis">não letivo</span>') +
      (dia.cat ? ' <span class="badge bg-light text-secondary border">' + esc(dia.cat) + '</span>' : '') +
      '</p>';

    if (!dia.eventos.length) {
      html += '<p class="text-muted mb-0">Nenhum <?= $g_coisa ?> neste dia.</p>';
    } else {
      html += '<ul class="list-group list-group-flush">';
      dia.eventos.forEach(function (e) {
        // Feriado não se edita como evento: o lápis leva ao cadastro dele.
        var url = e.feriado ? 'feriados.php?editar=' + e.feriado : URL + 'editar_evento=' + e.id;
        html += '<li class="list-group-item d-flex align-items-start gap-2 px-0">' +
          '<span class="amostra mt-1" style="background:' + (e.cor || 'transparent') + '"></span>' +
          '<span class="flex-grow-1"><span class="d-block">' + esc(e.desc) + '</span>' +
          '<small class="text-muted">' + esc(e.cat || 'sem categoria') +
          (e.feriado && !<?= $g_feriados ? 'true' : 'false' ?> ? ' · <span class="badge bg-light text-secondary border">feriado</span>' : '') +
          (e.base && !e.feriado && !<?= $g_global ? 'true' : 'false' ?> ? ' · <span class="badge bg-light text-secondary border">global</span>' : '') +
          '</small></span>' +
          '<a class="btn btn-sm btn-outline-primary" href="' + url + '" title="' +
          (e.feriado ? 'Abrir no cadastro de feriados' : 'Editar') + '"><i class="bi bi-pencil"></i></a>' +
          '</li>';
      });
      html += '</ul>';
    }
    document.getElementById('modalDiaCorpo').innerHTML = html;
    // URL já é a da tela em que a grade está. No cadastro de feriados o dia
    // clicado vira dia e mês de um feriado novo, de data fixa.
    document.getElementById('modalDiaNovo').href = <?= $g_feriados ? 'true' : 'false' ?>
      ? URL + 'novo=1&dia=' + parseInt(p[2], 10) + '&mes=' + parseInt(p[1], 10)
      : URL + 'nova_data=' + iso;
  });
})();
</script>
