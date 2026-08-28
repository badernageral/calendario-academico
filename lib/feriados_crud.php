<?php
declare(strict_types=1);

/**
 * Tratamento POST do cadastro de feriados, compartilhado pelas três telas que
 * mostram a grade do ano: o cadastro de Feriados, a de Eventos globais e a de
 * um calendário. O feriado aparece nas três, e alterá-lo tem que ser possível
 * de onde se está — sem trocar de tela e perder o ano, os filtros e o lugar da
 * rolagem.
 *
 * $voltarPara é a URL da tela que chamou, já com o estado dela. Tudo volta para
 * lá: o que salvou, o que apagou e o que errou.
 */
function tratarPostFeriado(PDO $db, string $voltarPara): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    $acao = post('acao');
    $id   = postInt('id');

    if ($acao === 'excluir_feriado') {
        $db->prepare('DELETE FROM feriados WHERE id = ?')->execute([$id]);
        flash('Feriado excluído. Ele sai dos calendários de todos os anos.');
        redirect($voltarPara);
    }

    if ($acao !== 'salvar_feriado') {
        return;
    }

    // O erro reabre o formulário na mesma tela, com o feriado que se editava.
    $volta = $voltarPara . ($id ? '&editar_feriado=' . $id : '&novo_feriado=1');

    $nome = post('nome');
    $tipo = post('tipo') === 'movel' ? 'movel' : 'fixo';
    $cat  = postInt('categoria_id') ?: null;

    // O tipo precisa ser um dos quatro: é dele que saem a prioridade, a cor e a
    // origem impressa depois do nome. Cair num padrão em silêncio era justamente
    // como um feriado nacional virava estadual sem ninguém ver.
    $tipos = array_map(static fn ($c) => (int) $c['id'], categoriasDeFeriado($db));
    if (!in_array($cat, $tipos, true)) {
        flash('Escolha o tipo do feriado.', 'erro');
        redirect($volta);
    }
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
        $db->prepare('UPDATE feriados SET nome=?, tipo=?, dia=?, mes=?, deslocamento=?, categoria_id=? WHERE id=?')
           ->execute([...$campos, $id]);
        flash('Feriado atualizado. Ele vale para os calendários de todos os anos.');
    } else {
        $db->prepare('INSERT INTO feriados (nome, tipo, dia, mes, deslocamento, categoria_id) VALUES (?,?,?,?,?,?)')
           ->execute($campos);
        flash('Feriado cadastrado.');
    }
    redirect($voltarPara);
}

/** O feriado que a URL pediu para editar, ou null. */
function feriadoEmEdicao(PDO $db): ?array
{
    $id = getInt('editar_feriado');
    if (!$id) {
        return null;
    }
    $st = $db->prepare('SELECT * FROM feriados WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}
