<?php
/**
 * Formulário de calendário, em modal, o mesmo na criação e na edição. Espera:
 * $db e $calEdit (a linha do calendário em edição, ou null para um novo).
 *
 * Na edição, espera também $voltarPara — a URL da tela que abriu o modal — e o
 * vínculo (curso ou nível) e o ano aparecem travados: são eles que identificam
 * o calendário, e trocá-los faria dele outro. Na criação, espera $cursos,
 * $cals, $anoPadrao e $sugestoes, que é de onde saem as datas prováveis de
 * cada ano; o curso é um por um, mas o nível é um só para todos os cursos
 * daquele nível — a diferença entre um campus pequeno e um grande.
 *
 * Fica em modal porque as oito datas dos bimestres se conferem olhando a grade:
 * é dela que se abre, inclusive clicando num marco de início ou fim de bimestre,
 * que é escrito a partir justamente destas datas.
 */
$calEdit ??= null;
$erroModal ??= '';
$c_novo  = $calEdit === null;
// Recusado, o formulário volta com o que foi digitado. A ação distingue os dois
// usos deste mesmo modal: criar um calendário e editar os dados de um.
[$c_val, $c_marcada, $c_devolta] = formDeVolta($c_novo ? 'novo' : 'salvar_calendario');

if ($c_novo) {
    $c_ano     = (int) $c_val('ano', (string) $anoPadrao);
    $c_valores = $sugestoes[$c_ano] ?? $sugestoes[$anoPadrao];
    $c_regime  = $cursos ? $cursos[0]['regime'] : 'semestral';
    $c_abrir   = get('novo') !== '';
} else {
    $c_ano    = (int) $calEdit['ano'];
    $c_regime = (string) $calEdit['curso_regime'];
    $c_abrir  = get('editar_cal') !== '';

    // Um calendário anterior aos bimestres tem só os dois semestres gravados. Em
    // vez de abrir com oito campos vazios, o formulário sugere partir cada
    // semestre ao meio — o mesmo palpite da criação —, e quem edita ajusta.
    $c_salvos  = bimestresDoCalendario($db, (int) $calEdit['id']);
    $c_valores = [];
    foreach ($c_salvos as $c_n => [$c_i, $c_f]) {
        $c_valores["bim{$c_n}_inicio"] = $c_i;
        $c_valores["bim{$c_n}_fim"]    = $c_f;
    }
    if ($c_salvos === []) {
        $c_valores = bimestresSugeridos($db, $c_ano);
    }
}
?>
<div class="modal fade" id="modalCalendario" tabindex="-1" aria-labelledby="tituloModalCalendario">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <?php // data-ano: o ano fixo da edição, que a validação do navegador confere
            // contra as oito datas. Na criação quem manda é o campo do ano. ?>
      <form method="post" data-ano="<?= $c_ano ?>">
        <?= csrfCampo() ?>
        <div class="modal-header">
          <h5 class="modal-title" id="tituloModalCalendario">
            <i class="bi bi-calendar-plus me-2 text-primary"></i>
            <?= $c_novo ? 'Novo calendário' : 'Dados e bimestres · ' . e($calEdit['curso_nome']) . ' · ' . $c_ano ?>
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
        </div>

        <div class="modal-body">
          <input type="hidden" name="acao" value="<?= $c_novo ? 'novo' : 'salvar_calendario' ?>">
          <?php if (!$c_novo): ?>
            <input type="hidden" name="cal_id" value="<?= (int) $calEdit['id'] ?>">
          <?php endif; ?>
          <?php // A mesma caixa serve aos dois: a validação do navegador escreve
                // nela antes de enviar, e o que o servidor recusou já vem dentro. ?>
          <?php $c_erro = $c_abrir ? $erroModal : ''; ?>
          <div class="alert alert-danger<?= $c_erro === '' ? ' d-none' : '' ?> erro-periodos"
               role="alert"><?= e($c_erro) ?></div>

          <?php if (!$c_novo && $c_salvos === []): ?>
            <div class="alert alert-warning d-flex" role="alert">
              <i class="bi bi-exclamation-triangle-fill me-2 mt-1"></i>
              <div>
                Este calendário é de antes dos bimestres. As datas abaixo vêm <strong>sugeridas</strong>,
                partindo cada semestre ao meio — confira e salve para o calendário passar a marcar
                sozinho o início e o fim de cada um.
              </div>
            </div>
          <?php endif; ?>

          <div class="row g-3">
            <?php if ($c_novo): ?>
              <div class="col-md-3">
                <label class="form-label">Vincular por</label>
                <select name="vinculo" class="form-select">
                  <option value="curso" <?= $c_val('vinculo', 'curso') === 'curso' ? 'selected' : '' ?>>Curso</option>
                  <option value="nivel" <?= $c_val('vinculo') === 'nivel' ? 'selected' : '' ?>>Nível</option>
                </select>
                <div class="form-text">Um por curso, ou um só para todo o nível.</div>
              </div>
              <div class="col-md-5" data-bloco-vinculo="curso">
                <label class="form-label">Curso</label>
                <select name="curso_id" class="form-select">
                  <?php foreach ($cursos as $c_c): ?>
                    <option value="<?= $c_c['id'] ?>"
                            <?= $c_val('curso_id') === (string) $c_c['id'] ? 'selected' : '' ?>><?= e($c_c['nome']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-5 d-none" data-bloco-vinculo="nivel">
                <label class="form-label">Nível</label>
                <select name="nivel_chave" class="form-select" disabled>
                  <?php foreach (niveisCurso() as $c_k => $c_v): ?>
                    <option value="<?= e($c_k) ?>" <?= $c_val('nivel_chave') === $c_k ? 'selected' : '' ?>><?= e($c_v) ?></option>
                  <?php endforeach; ?>
                </select>
                <div class="form-text">Um calendário por nível é sempre anual: os bimestres correm de 1º a 4º, sem repetir por semestre.</div>
              </div>
            <?php else: ?>
              <div class="col-md-6">
                <label class="form-label"><?= $calEdit['curso_id'] !== null ? 'Curso' : 'Nível' ?></label>
                <input class="form-control" value="<?= e($calEdit['curso_nome']) ?>" disabled>
                <div class="form-text">O vínculo e o ano não mudam depois de criado.</div>
              </div>
            <?php endif; ?>
            <div class="col-md-2">
              <label class="form-label">Ano</label>
              <?php if ($c_novo): ?>
                <input type="number" name="ano" id="anoCalendario" class="form-control"
                       value="<?= $c_ano ?>" min="<?= ANO_MIN ?>" max="<?= ANO_MAX ?>" required>
              <?php else: ?>
                <input class="form-control" value="<?= $c_ano ?>" disabled>
              <?php endif; ?>
            </div>
            <div class="col-md-4">
              <label class="form-label">Situação</label>
              <input name="situacao" class="form-control"
                     value="<?= e($c_val('situacao', $c_novo ? cfg('situacao') : (string) $calEdit['situacao'])) ?>">
              <?php if (!$c_novo): ?>
                <div class="form-text">Sai no rodapé de cada página do calendário impresso.</div>
              <?php endif; ?>
            </div>

            <div class="col-12">
              <label class="form-label">Local e data</label>
              <input name="local_texto" class="form-control"
                     value="<?= e($c_val('local_texto', $c_novo ? localEData() : (string) $calEdit['local_texto'])) ?>">
            </div>

            <?php
            // Vindo de recusa, as oito datas são as que foram enviadas — inclusive
            // a errada, que é justamente a que se vai corrigir.
            if ($c_devolta) {
                foreach (array_keys($c_valores) as $c_k) {
                    $c_valores[$c_k] = $c_val($c_k, (string) $c_valores[$c_k]);
                }
            }
            $valores = $c_valores; $regime = $c_regime; require __DIR__ . '/campos_bimestres.php';
            ?>

            <div class="col-12">
              <label class="form-label">Observações</label>
              <textarea name="observacoes" class="form-control" rows="3"><?= e($c_val('observacoes', $c_novo ? '' : (string) $calEdit['observacoes'])) ?></textarea>
              <div class="form-text">Cada linha vira uma nota na página de resumo do calendário impresso.</div>
            </div>

            <?php if ($c_novo): ?>
              <div class="col-12"><hr class="my-1"></div>
              <div class="col-md-6">
                <label class="form-label">Copiar eventos de</label>
                <select name="copiar_de" class="form-select">
                  <option value="">— começar vazio —</option>
                  <?php foreach ($cals as $c_c): ?>
                    <option value="<?= $c_c['id'] ?>"
                            <?= $c_val('copiar_de') === (string) $c_c['id'] ? 'selected' : '' ?>><?= e($c_c['curso_nome']) ?> · <?= $c_c['ano'] ?> (<?= $c_c['n_eventos'] ?> eventos locais)</option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6 d-flex align-items-center">
                <div class="form-check mt-md-4">
                  <input class="form-check-input" type="checkbox" name="copiar_reposicoes" id="copiarReposicoes" value="1"
                         <?= $c_marcada('copiar_reposicoes', false) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="copiarReposicoes">
                    Copiar eventos de reposição de horário
                  </label>
                  <div class="form-text">Ex.: <em>Sábado letivo com horário de quarta</em>.</div>
                </div>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="modal-footer">
          <?php if ($c_novo): ?>
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <?php else: ?>
            <a class="btn btn-outline-secondary" href="<?= e($voltarPara) ?>">Cancelar</a>
          <?php endif; ?>
          <button class="btn btn-primary">
            <i class="bi bi-check-lg me-1"></i><?= $c_novo ? 'Criar calendário' : 'Salvar' ?>
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require __DIR__ . '/valida_bimestres.php'; ?>

<?php if ($c_abrir): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  new bootstrap.Modal(document.getElementById('modalCalendario')).show();
});
</script>
<?php endif; ?>
<?php unset($c_erro, $c_novo, $c_valores, $c_k, $c_v, $c_regime, $c_ano, $c_abrir, $c_salvos, $c_n, $c_i, $c_f, $c_c); ?>
