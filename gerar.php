<?php
require __DIR__ . '/lib/boot.php';

$db  = db();
$eng = Engine::paraCalendario($db, getInt('id'));
if (!$eng) {
    http_response_code(404);
    exit('Calendário não encontrado.');
}
$cal = $eng->cal;

$legenda = legendaDoCalendario($eng->categorias());

$notas      = $eng->notasReposicao();
$trimestres = [[1, 2, 3], [4, 5, 6], [7, 8, 9], [10, 11, 12]];
$titulo     = $eng->titulo();

/** Cabeçalho institucional repetido no topo de cada página. */
function cabecalho(string $titulo): void
{
    echo '<div class="cabecalho">';
    foreach (explode("\n", cfg('orgao')) as $linha) {
        echo '<div>' . e($linha) . '</div>';
    }
    if (cfg('campus') !== '') {
        echo '<div>' . e(cfg('campus')) . '</div>';
    }
    echo '<div class="titulo">' . e($titulo) . '</div>';
    echo '</div>';
}
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title><?= e($titulo) ?></title>
<link rel="stylesheet" href="assets/calendario.css?v=<?= (int) @filemtime(APP_ROOT . '/assets/calendario.css') ?>">
<style>
/* Cores fixas da grade, de Configurações — o que não vem da legenda. */
:root {
  --dia-util: <?= e(cfg('cor_dia_util')) ?>;
  --dia-fds:  <?= e(cfg('cor_dia_fds')) ?>;
  --mes:      <?= e(cfg('cor_mes')) ?>;
  --dow:      <?= e(cfg('cor_dow')) ?>;
}
</style>
</head>
<body>

<div class="barra-tela">
  <button class="btn primario" onclick="window.print()">Imprimir / salvar em PDF</button>
</div>

<?php foreach ($trimestres as $tri): ?>
<section class="pagina">
  <?php cabecalho($titulo); ?>

  <div class="trimestre">
    <?php foreach ($tri as $mes): ?>
      <?php $cont = $eng->contagemMes($mes); ?>
      <div class="coluna-mes">
        <table class="grade">
          <colgroup><?= str_repeat('<col>', 7) ?></colgroup>
          <tr class="nome-mes"><th colspan="7"><?= e(Engine::MESES[$mes]) ?></th></tr>
          <tr class="dow">
            <?php foreach (Engine::DOW_INICIAL as $i => $ini): ?>
              <th class="<?= ($i === 0 || $i === 6) ? 'fds' : 'util' ?>"><?= $ini ?></th>
            <?php endforeach; ?>
          </tr>
          <?php foreach ($eng->semanas($mes) as $semana): ?>
            <tr class="dias">
              <?php foreach ($semana as $i => $iso): ?>
                <?php
                $fdsCol = ($i === 0 || $i === 6);
                if ($iso === null) {
                    echo '<td class="' . ($fdsCol ? 'vazio-fds' : 'vazio') . '"></td>';
                    continue;
                }
                $d    = $eng->dia($iso);
                $cat  = $d['categoria'];
                $est  = $cat ? 'background:' . e($cat['cor']) . ';color:' . e($cat['cor_texto']) . ';' : '';
                $tit  = $cat ? $cat['nome'] : '';
                // A cor de fim de semana é do dia que não tem aula, não da
                // coluna: um sábado letivo é dia de aula e sai com a cor do dia
                // útil. Sem isto, o sábado que entrou para fechar a conta do
                // bimestre continuava pintado como se fosse folga.
                $fds  = $fdsCol && !$d['letivo'];
                ?>
                <td class="<?= $fds ? 'fds' : '' ?>" style="<?= $est ?>" title="<?= e($tit) ?>"><?= (int) substr($iso, 8, 2) ?></td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
          <?php // O zero é escrito, e não deixado em branco: a linha conta dias
                // letivos por dia da semana, e "nenhuma segunda" é informação —
                // célula vazia parece falta de dado. Em janeiro e julho, quando o
                // mês inteiro fica fora do semestre, a linha sai toda zerada, que
                // é o que a planilha do campus sempre imprimiu. ?>
          <tr class="contagem">
            <td></td>
            <?php for ($dw = 1; $dw <= 6; $dw++): ?>
              <td><?= (int) $cont['por_dow'][$dw] ?></td>
            <?php endfor; ?>
          </tr>
          <tr class="total">
            <td><?= $cont['total'] ?></td>
            <td colspan="6">Dias letivos</td>
          </tr>
        </table>

        <ul class="eventos">
          <?php foreach ($eng->eventosDoMes($mes) as $item): ?>
            <li<?= (int) $item['ev']['negrito'] === 1 ? ' class="negrito"' : '' ?>>
              <?= e($item['rotulo']) ?> - <?= e($eng->descricaoNaLista($item['ev'])) ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endforeach; ?>
  </div>

  <footer class="rodape">
    <span>Situação: <?= e($cal['situacao']) ?></span>
    <span><?= e($cal['local_texto']) ?></span>
    <span>Compilado em <?= date('d/m/Y') ?></span>
  </footer>
</section>
<?php endforeach; ?>

<section class="pagina">
  <?php cabecalho($titulo); ?>

  <div class="fecho">
    <div class="resumo">
      <table class="grade tabela-resumo">
        <tr class="nome-mes"><th colspan="7">RESUMO</th></tr>
        <?php foreach ([1 => '1º Semestre', 2 => '2º Semestre'] as $n => $rot): ?>
          <?php $c = $eng->contagemSemestre($n); ?>
          <tr class="sub"><th colspan="7"><?= $rot ?></th></tr>
          <tr class="dow">
            <th class="util">S</th><th class="util">T</th><th class="util">Q</th>
            <th class="util">Q</th><th class="util">S</th><th class="fds">Sab</th>
            <th class="fds">Dias letivos</th>
          </tr>
          <tr class="dias">
            <?php for ($dw = 1; $dw <= 6; $dw++): ?><td><?= $c['por_dow'][$dw] ?></td><?php endfor; ?>
            <td class="destaque"><?= $c['total'] ?></td>
          </tr>
        <?php endforeach; ?>
      </table>

      <?php if ($notas): ?>
        <div class="notas">
          <?php foreach ($notas as $sem => $linhas): ?>
            <p class="nota-titulo"><?= (int) $sem ?>º Semestre</p>
            <?php foreach ($linhas as $l): ?><p class="nota"><?= e($l) ?></p><?php endforeach; ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if (trim((string) $cal['observacoes']) !== ''): ?>
        <div class="notas"><?php foreach (explode("\n", $cal['observacoes']) as $l): ?>
          <p class="nota"><?= e($l) ?></p>
        <?php endforeach; ?></div>
      <?php endif; ?>
    </div>

    <ul class="legenda">
      <?php foreach ($legenda as $c): ?>
        <li><span class="amostra" style="background:<?= e($c['cor']) ?>"></span><?= e($c['nome']) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>

  <footer class="rodape">
    <span>Situação: <?= e($cal['situacao']) ?></span>
    <span><?= e($cal['local_texto']) ?></span>
    <span>Compilado em <?= date('d/m/Y') ?></span>
  </footer>
</section>

</body>
</html>
