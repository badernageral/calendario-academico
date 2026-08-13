<?php
declare(strict_types=1);

/**
 * Tratamento POST compartilhado pelas telas de eventos.
 * $calendarioId null = tela de eventos globais; caso contrário, a tela de um
 * calendário — e ali o formulário deixa escolher, no campo "escopo", se o
 * evento é local (só daquele calendário) ou global (todos do ano).
 */
function tratarPostEvento(PDO $db, int $ano, ?int $calendarioId, string $voltarPara): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    $acao = post('acao');
    $id   = postInt('id');

    if ($acao === 'excluir_evento') {
        $db->prepare('DELETE FROM eventos WHERE id = ?')->execute([$id]);
        flash('Evento excluído.');
        redirect($voltarPara);
    }

    if ($acao !== 'salvar_evento') {
        return;
    }

    $faixas = parseFaixas(post('datas'), $ano);
    if ($faixas === []) {
        flash('Informe ao menos uma data válida (ex.: 07/03/2026 ou 14/03 a 16/03).', 'erro');
        redirect($voltarPara . ($id ? '&editar_evento=' . $id : ''));
    }
    if (post('descricao') === '') {
        flash('A descrição é obrigatória.', 'erro');
        redirect($voltarPara);
    }

    // Global = calendario_id NULL, vale para todos os calendários do ano.
    // Na tela de eventos globais não há escolha: tudo o que entra ali é global.
    $global  = post('escopo', $calendarioId === null ? 'global' : 'local') === 'global';
    $destino = $global ? null : $calendarioId;

    $campos = [
        $ano,
        $destino,
        postInt('categoria_id') ?: null,
        post('descricao'),
        isset($_POST['pinta_dias']) ? 1 : 0,
        isset($_POST['negrito']) ? 1 : 0,
        post('conta_letivo') === '' ? null : (int) post('conta_letivo'),
        post('rotulo') ?: null,
        $global ? niveisParaBanco((array) ($_POST['nivel'] ?? [])) : null,   // só faz sentido no global
        post('repoe_dow') === '' ? null : (int) post('repoe_dow'),
    ];

    if ($id) {
        $db->prepare(
            'UPDATE eventos SET ano=?, calendario_id=?, categoria_id=?, descricao=?, pinta_dias=?,
                    negrito=?, conta_letivo=?, rotulo=?, nivel=?, repoe_dow=? WHERE id=?'
        )->execute([...$campos, $id]);
    } else {
        $db->prepare(
            'INSERT INTO eventos (ano, calendario_id, categoria_id, descricao, pinta_dias,
                    negrito, conta_letivo, rotulo, nivel, repoe_dow) VALUES (?,?,?,?,?,?,?,?,?,?)'
        )->execute($campos);
        $id = (int) $db->lastInsertId();
    }
    salvarFaixas($db, $id, $faixas);
    flash('Evento salvo.');
    redirect($voltarPara);
}

/**
 * Copia os eventos globais de um ano para outro, deslocando as datas pela
 * diferença de anos. Devolve [copiados, repetidos].
 *
 * O que já existe no destino não entra de novo: repetido é o evento de mesma
 * descrição começando no mesmo dia. Assim o botão pode ser clicado duas vezes
 * sem encher o ano de duplicatas — e, quem já ajustou uma data à mão, não a
 * perde nem ganha um par.
 *
 * As datas andam pela diferença de anos, o que acerta dia e mês. O que é móvel
 * (ligado à Páscoa) precisa de conferência depois — feriado não entra aqui,
 * porque vem do cadastro próprio.
 */
function copiarEventosGlobais(PDO $db, int $anoOrigem, int $anoDestino): array
{
    $st = $db->prepare('SELECT * FROM eventos WHERE calendario_id IS NULL AND ano = ? ORDER BY id');
    $st->execute([$anoOrigem]);
    $origem = $st->fetchAll();
    if (!$origem) {
        return [0, 0];
    }

    $jaTem = $db->prepare(
        'SELECT COUNT(*) FROM eventos e JOIN evento_datas d ON d.evento_id = e.id
          WHERE e.calendario_id IS NULL AND e.ano = ? AND e.descricao = ? AND d.inicio = ?'
    );
    $ins = $db->prepare(
        'INSERT INTO eventos (ano, calendario_id, categoria_id, descricao, pinta_dias, negrito, conta_letivo, rotulo, nivel, repoe_dow)
         VALUES (?, NULL, ?,?,?,?,?,?,?,?)'
    );
    $sel   = $db->prepare('SELECT inicio, fim FROM evento_datas WHERE evento_id = ? ORDER BY inicio');
    $delta = $anoDestino - $anoOrigem;

    $copiados = 0;
    $repetidos = 0;
    foreach ($origem as $ev) {
        $sel->execute([$ev['id']]);
        $faixas = [];
        foreach ($sel as $d) {
            $faixas[] = [
                'inicio' => (new DateTimeImmutable($d['inicio']))->modify("$delta year")->format('Y-m-d'),
                'fim'    => (new DateTimeImmutable($d['fim']))->modify("$delta year")->format('Y-m-d'),
            ];
        }
        if (!$faixas) {
            continue;
        }
        $jaTem->execute([$anoDestino, $ev['descricao'], $faixas[0]['inicio']]);
        if ((int) $jaTem->fetchColumn() > 0) {
            $repetidos++;
            continue;
        }
        $ins->execute([
            $anoDestino, $ev['categoria_id'], $ev['descricao'], $ev['pinta_dias'],
            $ev['negrito'], $ev['conta_letivo'], $ev['rotulo'], $ev['nivel'], $ev['repoe_dow'],
        ]);
        salvarFaixas($db, (int) $db->lastInsertId(), $faixas);
        $copiados++;
    }
    return [$copiados, $repetidos];
}

/** Carrega um evento com suas faixas para preencher o formulário. */
function carregarEvento(PDO $db, int $id): ?array
{
    $st = $db->prepare('SELECT * FROM eventos WHERE id = ?');
    $st->execute([$id]);
    $ev = $st->fetch();
    if (!$ev) {
        return null;
    }
    $st = $db->prepare('SELECT inicio, fim FROM evento_datas WHERE evento_id = ? ORDER BY inicio');
    $st->execute([$id]);
    $ev['datas'] = $st->fetchAll();
    return $ev;
}


