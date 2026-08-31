<?php
declare(strict_types=1);

/**
 * Tratamento POST dos dados de um calendário: situação, local, observações e as
 * oito datas dos bimestres, de onde saem os dois semestres.
 *
 * Vive fora da tela porque o formulário virou modal e abre de dentro da grade —
 * inclusive clicando num marco de início ou fim de bimestre, que é escrito a
 * partir justamente destas datas.
 *
 * $voltarPara é a URL da tela que chamou, com o estado dela.
 */
function tratarPostCalendario(PDO $db, array $cal, string $voltarPara): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || post('acao') !== 'salvar_calendario') {
        return;
    }
    $id = (int) $cal['id'];

    [$bimestres, $erro] = bimestresDoFormulario((string) $cal['curso_regime'], (int) $cal['ano']);
    if ($erro !== '') {
        guardarPost();
        flash($erro, 'erro');
        // Reabre o modal: o erro é das datas, e é nelas que se mexe.
        redirect($voltarPara . '&editar_cal=1');
    }

    $db->prepare('UPDATE calendarios SET situacao=?, local_texto=?, observacoes=? WHERE id=?')
       ->execute([post('situacao'), post('local_texto'), post('observacoes'), $id]);

    salvarPeriodos($db, $id, $bimestres);
    flash('Calendário atualizado.');
    redirect($voltarPara);
}
