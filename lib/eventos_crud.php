<?php
declare(strict_types=1);

/**
 * O evento obriga os seus dias a contarem como letivos?
 *
 * É a mesma regra do motor: o `conta_letivo` do evento manda e, quando ele é
 * neutro, vale o `letivo` da categoria. Fora isso o dia decide pelo dia da
 * semana — e aí um sábado não conta, que é o caso comum.
 */
function forcaDiaLetivo(PDO $db, ?int $contaLetivo, ?int $categoriaId): bool
{
    if ($contaLetivo !== null) {
        return $contaLetivo === 1;
    }
    if ($categoriaId === null) {
        return false;
    }
    $st = $db->prepare('SELECT letivo FROM categorias WHERE id = ?');
    $st->execute([$categoriaId]);
    return (int) $st->fetchColumn() === 1;
}

/**
 * Os sábados e domingos que as faixas cobrem, em Y-m-d e na ordem do ano.
 *
 * @param array<array{inicio:string,fim:string}> $faixas
 * @return string[]
 */
function fimDeSemanaEm(array $faixas): array
{
    $out = [];
    foreach ($faixas as $f) {
        $fim = new DateTimeImmutable($f['fim']);
        for ($d = new DateTimeImmutable($f['inicio']); $d <= $fim; $d = $d->modify('+1 day')) {
            $dow = (int) $d->format('w');
            if ($dow === 0 || $dow === 6) {
                $out[$d->format('Y-m-d')] = true;
            }
        }
    }
    $dias = array_keys($out);
    sort($dias);
    return $dias;
}

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
        // O id vem de um formulário da própria tela, mas a tela pode estar
        // velha: o evento pode ter virado global depois que a página foi
        // montada, e a grade de um calendário esconde o × dos globais de
        // propósito — apagá-los ali afetaria todos os calendários do ano.
        // Então o DELETE só alcança o que esta tela realmente manda.
        $st = $calendarioId === null
            ? $db->prepare('DELETE FROM eventos WHERE id = ? AND ano = ? AND calendario_id IS NULL')
            : $db->prepare('DELETE FROM eventos WHERE id = ? AND ano = ? AND calendario_id = ?');
        $st->execute($calendarioId === null ? [$id, $ano] : [$id, $ano, $calendarioId]);

        $st->rowCount() > 0
            ? flash('Evento excluído.')
            : flash('O evento não foi excluído: ele não é desta tela. Recarregue a página.', 'erro');
        redirect($voltarPara);
    }

    if ($acao !== 'salvar_evento') {
        return;
    }

    // Para onde volta um pedido recusado. Sempre com o parâmetro que reabre o
    // modal — 'editar_evento' no que já existe, 'novo' no que ainda não —,
    // porque é dentro dele que o erro aparece. Sem isso a mensagem ia parar no
    // topo da página, atrás do modal fechado, e o formulário voltava mudo.
    $volta = $voltarPara . ($id ? '&editar_evento=' . $id : '&novo=1');

    // Linha que não virou data nenhuma — "31/02", "99/99", um texto solto. Antes
    // de conferir o resto, porque descartá-la em silêncio no meio de datas boas
    // faz quem cadastrou sair achando que gravou o que digitou.
    if ($ruins = datasRecusadas(post('datas'), $ano)) {
        flash(sprintf('Não entendi %s: %s. Use 07/03/2026 ou 14/03 a 16/03.',
            count($ruins) === 1 ? 'esta data' : 'estas datas',
            implode(', ', array_map(static fn (string $l): string => '“' . $l . '”', $ruins))), 'erro');
        redirect($volta);
    }

    $faixas = parseFaixas(post('datas'), $ano);
    if ($faixas === []) {
        flash('Informe ao menos uma data válida (ex.: 07/03/2026 ou 14/03 a 16/03).', 'erro');
        redirect($volta);
    }

    // Toda data tem de cair no ano do calendário. A grade só desenha esse ano e
    // o evento é buscado por ele, então uma data de outro ano gravava um evento
    // que existe no banco e não aparece em lugar nenhum — nem na lista do mês,
    // nem pintando dia. É a mesma conferência que bimestresDoFormulario() já faz
    // com as oito datas do período, e pelo mesmo motivo.
    foreach ($faixas as $f) {
        foreach ([$f['inicio'], $f['fim']] as $data) {
            if ((int) substr($data, 0, 4) !== $ano) {
                flash('A data ' . dataBr($data) . " está fora de {$ano}, o ano deste calendário.", 'erro');
                redirect($volta);
            }
        }
    }
    if (post('descricao') === '') {
        flash('A descrição é obrigatória.', 'erro');
        redirect($volta);
    }

    // Global = calendario_id NULL, vale para todos os calendários do ano.
    // Na tela de eventos globais não há escolha: tudo o que entra ali é global.
    $global  = post('escopo', $calendarioId === null ? 'global' : 'local') === 'global';
    $destino = $global ? null : $calendarioId;

    $categoria = postInt('categoria_id') ?: null;
    $letivo    = post('conta_letivo') === '' ? null : (int) post('conta_letivo');

    // O <select> só oferece de segunda a sexta — reposição repõe aula de dia
    // útil —, mas um POST à mão mandaria 0 ou 6. O que não é dia de aula não é
    // horário a repor e vira "não é reposição".
    $repoe = post('repoe_dow') === '' ? null : (int) post('repoe_dow');
    if ($repoe !== null && ($repoe < 1 || $repoe > 5)) {
        $repoe = null;
    }

    // Sábado ou domingo que conta como letivo precisa dizer que horário cumpre.
    // Sem isso o dia entra no total do semestre sem entrar em coluna nenhuma da
    // contagem por horário, e não gera a nota "4 sábados letivos com horário de
    // segunda" — o calendário fica com um dia que ninguém sabe repor o quê.
    if ($repoe === null && forcaDiaLetivo($db, $letivo, $categoria)) {
        $fds = fimDeSemanaEm($faixas);
        if ($fds !== []) {
            flash(sprintf(
                '%s %s de fim de semana. Um sábado ou domingo que conta como letivo precisa dizer '
                . 'com que horário funciona — preencha “Funciona com horário de”.',
                implode(', ', array_map('dataBr', array_slice($fds, 0, 4)))
                    . (count($fds) > 4 ? ' e mais ' . (count($fds) - 4) : ''),
                count($fds) === 1 ? 'é um dia' : 'são dias'
            ), 'erro');
            redirect($volta);
        }
    }

    $campos = [
        $ano,
        $destino,
        $categoria,
        post('descricao'),
        isset($_POST['pinta_dias']) ? 1 : 0,
        isset($_POST['negrito']) ? 1 : 0,
        $letivo,
        post('rotulo') ?: null,
        $global ? niveisParaBanco((array) ($_POST['nivel'] ?? [])) : null,   // só faz sentido no global
        $repoe,
    ];

    if ($id) {
        // A mesma guarda do excluir_evento, e pelo mesmo motivo: o id vem de um
        // formulário da própria tela, mas a tela pode estar velha. Sem ela, um
        // POST com o id de um evento de outro calendário — ou de outro ano —
        // trazia o evento para cá, com a descrição sobrescrita e o dono trocado.
        //
        // O alcance é o mesmo que a tela usa para abrir o formulário: na grade
        // de um calendário editam-se os locais dele e os globais do ano; na tela
        // de eventos globais, só os globais.
        $onde = $calendarioId === null
            ? 'calendario_id IS NULL'
            : '(calendario_id IS NULL OR calendario_id = ?)';
        $st = $db->prepare(
            'UPDATE eventos SET ano=?, calendario_id=?, categoria_id=?, descricao=?, pinta_dias=?,
                    negrito=?, conta_letivo=?, rotulo=?, nivel=?, repoe_dow=?
              WHERE id = ? AND ano = ? AND ' . $onde
        );
        $st->execute($calendarioId === null
            ? [...$campos, $id, $ano]
            : [...$campos, $id, $ano, $calendarioId]);

        if ($st->rowCount() === 0) {
            flash('O evento não foi salvo: ele não é desta tela. Recarregue a página.', 'erro');
            redirect($voltarPara);
        }
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
                'inicio' => deslocarAno($d['inicio'], $delta),
                'fim'    => deslocarAno($d['fim'], $delta),
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


