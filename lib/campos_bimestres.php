<?php
/**
 * Os oito campos de data dos bimestres — os mesmos na criação de um calendário
 * e na tela de dados dele.
 *
 * Espera: $valores (bim1_inicio => 'Y-m-d', …, podendo vir vazio) e $regime
 * ('anual' ou 'semestral'). Os rótulos dependem do regime do curso, e na tela
 * de criação o curso ainda pode mudar — por isso cada <label> leva o nome do
 * campo em data-rotulo, e o script troca o texto sem recarregar a página.
 *
 * Os semestres não se digitam: saem daqui. O 1º vai do início do 1º bimestre
 * ao fim do 2º, e o 2º do início do 3º ao fim do 4º.
 */
$b_rotulos = rotulosBimestre($regime);
?>
<div class="col-12"><hr class="my-1"></div>
<div class="col-12">
  <div class="form-text mt-0">
    As oito datas são obrigatórias: são elas que delimitam o período letivo do ano.
    <strong>Os semestres saem dos bimestres</strong> — o 1º semestre vai do início do 1º
    bimestre ao fim do 2º, e o 2º do início do 3º ao fim do 4º; o intervalo entre eles é o
    recesso do meio do ano, que não conta dia letivo.
  </div>
</div>

<?php foreach ([1, 2, 3, 4] as $b_n): ?>
  <?php foreach (['inicio', 'fim'] as $b_parte): ?>
    <?php $b_campo = "bim{$b_n}_{$b_parte}"; ?>
    <div class="col-md-3">
      <label class="form-label" data-rotulo="<?= $b_campo ?>"><?= e($b_rotulos[$b_campo]) ?></label>
      <input type="date" name="<?= $b_campo ?>" class="form-control" required
             value="<?= e($valores[$b_campo] ?? '') ?>">
    </div>
  <?php endforeach; ?>
<?php endforeach; ?>
<?php unset($b_rotulos, $b_n, $b_parte, $b_campo); ?>
