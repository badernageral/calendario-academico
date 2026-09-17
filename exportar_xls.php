<?php
require __DIR__ . '/lib/boot.php';
require __DIR__ . '/lib/xlsx.php';

/**
 * A mesma grade do calendário impresso (gerar.php), em planilha: uma aba por
 * trimestre e uma de resumo, para quem precisa abrir no Excel — conferir
 * dados, adaptar para outro uso — em vez de só imprimir.
 */

$db  = db();
$eng = Engine::paraCalendario($db, getInt('id'));
if (!$eng) {
    http_response_code(404);
    exit('Calendário não encontrado.');
}
$cal = $eng->cal;

$legenda    = legendaDoCalendario($eng->categorias());
$notas      = $eng->notasReposicao();
$trimestres = [
    'Jan-Mar' => [1, 2, 3],
    'Abr-Jun' => [4, 5, 6],
    'Jul-Set' => [7, 8, 9],
    'Out-Dez' => [10, 11, 12],
];
$titulo = $eng->titulo();

$xl = new Xlsx();

// ── Estilos, um por combinação — repetidos entre as abas mas registrados uma
// única vez, porque estilo() devolve sempre o mesmo índice para os mesmos
// parâmetros ─────────────────────────────────────────────────────────────
$eTitulo    = $xl->estilo(['negrito' => true, 'tamanho' => 13, 'centro' => true]);
$eCabecalho = $xl->estilo(['tamanho' => 9, 'centro' => true]);
$eMes       = $xl->estilo(['bg' => cfg('cor_mes'), 'negrito' => true, 'tamanho' => 12, 'centro' => true, 'borda' => true]);
$eDowUtil   = $xl->estilo(['bg' => cfg('cor_dow'), 'centro' => true, 'borda' => true]);
$eDowFds    = $xl->estilo(['bg' => cfg('cor_dia_fds'), 'centro' => true, 'borda' => true]);
$eDiaUtil   = $xl->estilo(['bg' => cfg('cor_dia_util'), 'centro' => true, 'borda' => true]);
$eDiaFds    = $xl->estilo(['bg' => cfg('cor_dia_fds'), 'centro' => true, 'borda' => true]);
$eVazio     = $xl->estilo(['borda' => true]);
$eContagem  = $xl->estilo(['bg' => '#ccc1da', 'tamanho' => 9, 'centro' => true, 'borda' => true]);
$eTotalNum  = $xl->estilo(['negrito' => true, 'tamanho' => 9.5, 'centro' => true, 'borda' => true]);
$eTotalTxt  = $xl->estilo(['negrito' => true, 'tamanho' => 9.5, 'borda' => true]);
$eEvento    = $xl->estilo(['tamanho' => 8, 'borda' => true, 'quebra' => true]);
$eEventoNeg = $xl->estilo(['tamanho' => 8, 'negrito' => true, 'borda' => true, 'quebra' => true]);
$eNota      = $xl->estilo(['tamanho' => 8, 'quebra' => true]);

/** Estilo de uma célula com a cor da categoria do dia, criado sob demanda. */
$estiloCategoria = [];
$eDeCategoria = static function (?array $cat) use (&$estiloCategoria, $xl): ?int {
    if (!$cat) {
        return null;
    }
    $chave = $cat['cor'] . '|' . $cat['cor_texto'];
    if (!isset($estiloCategoria[$chave])) {
        $estiloCategoria[$chave] = $xl->estilo([
            'bg' => $cat['cor'], 'cor' => $cat['cor_texto'], 'centro' => true, 'borda' => true,
        ]);
    }
    return $estiloCategoria[$chave];
};

// ── Uma aba por trimestre, 3 meses lado a lado (colunas 1-7, 9-15, 17-23) ───
foreach ($trimestres as $nomeAba => $meses) {
    $folha = $xl->folha($nomeAba);

    $linha = 1;
    foreach (explode("\n", cfg('orgao')) as $l) {
        $xl->celula($folha, $linha, 1, $l, $eCabecalho);
        $xl->mesclar($folha, $linha, 1, $linha, 23);
        $linha++;
    }
    if (cfg('campus') !== '') {
        $xl->celula($folha, $linha, 1, cfg('campus'), $eCabecalho);
        $xl->mesclar($folha, $linha, 1, $linha, 23);
        $linha++;
    }
    $xl->celula($folha, $linha, 1, $titulo, $eTitulo);
    $xl->mesclar($folha, $linha, 1, $linha, 23);
    $linha += 2;

    $inicioGrade = $linha;
    $maxSemanas  = max(array_map(static fn (int $m) => count($eng->semanas($m)), $meses));

    foreach ($meses as $i => $mes) {
        $col0  = $i * 8 + 1; // 1, 9, 17 — sete colunas de mês e uma de vão
        $linha = $inicioGrade;
        $cont  = $eng->contagemMes($mes);

        $xl->celula($folha, $linha, $col0, Engine::MESES[$mes], $eMes);
        $xl->mesclar($folha, $linha, $col0, $linha, $col0 + 6);
        $linha++;

        foreach (Engine::DOW_INICIAL as $d => $ini) {
            $fds = ($d === 0 || $d === 6);
            $xl->celula($folha, $linha, $col0 + $d, $ini, $fds ? $eDowFds : $eDowUtil);
        }
        $linha++;

        $semanas = $eng->semanas($mes);
        for ($s = 0; $s < $maxSemanas; $s++) {
            $semana = $semanas[$s] ?? array_fill(0, 7, null);
            foreach ($semana as $d => $iso) {
                $col = $col0 + $d;
                $fdsCol = ($d === 0 || $d === 6);
                if ($iso === null) {
                    $xl->celula($folha, $linha, $col, '', $eVazio);
                    continue;
                }
                $dia = $eng->dia($iso);
                $estiloDia = $eDeCategoria($dia['categoria']) ?? ($fdsCol && !$dia['letivo'] ? $eDiaFds : $eDiaUtil);
                $xl->celula($folha, $linha, $col, (int) substr($iso, 8, 2), $estiloDia);
            }
            $linha++;
        }

        $xl->celula($folha, $linha, $col0, '', $eContagem);
        for ($d = 1; $d <= 6; $d++) {
            $xl->celula($folha, $linha, $col0 + $d, (int) $cont['por_dow'][$d], $eContagem);
        }
        $linha++;

        $xl->celula($folha, $linha, $col0, (int) $cont['total'], $eTotalNum);
        $xl->celula($folha, $linha, $col0 + 1, 'Dias letivos', $eTotalTxt);
        $xl->mesclar($folha, $linha, $col0 + 1, $linha, $col0 + 6);
        $linha += 2;

        foreach ($eng->eventosDoMes($mes) as $item) {
            $texto = $item['rotulo'] . ' - ' . $eng->descricaoNaLista($item['ev']);
            $xl->celula($folha, $linha, $col0, $texto, (int) $item['ev']['negrito'] === 1 ? $eEventoNeg : $eEvento);
            $xl->mesclar($folha, $linha, $col0, $linha, $col0 + 6);
            // As sete colunas do mês (4.5 cada, ajustadas mais abaixo) são a
            // largura real da célula mesclada — sem calcular a altura pelo
            // texto, uma descrição longa ficava cortada, escondida atrás da
            // linha seguinte.
            $xl->altura($folha, $linha, Xlsx::alturaParaTexto($texto, 4.5 * 7, 10));
            $linha++;
        }

        for ($c = $col0; $c <= $col0 + 6; $c++) {
            $xl->largura($folha, $c, 4.5);
        }
        $xl->largura($folha, $col0 + 7, 2);
    }
}

// ── Aba de resumo: os dois semestres, notas de reposição, observações e a
// legenda de cores ───────────────────────────────────────────────────────
$folha = $xl->folha('Resumo');
$linha = 1;
$xl->celula($folha, $linha, 1, $titulo, $eTitulo);
$xl->mesclar($folha, $linha, 1, $linha, 8);
$linha += 2;

foreach ([1 => '1º Semestre', 2 => '2º Semestre'] as $n => $rot) {
    $c = $eng->contagemSemestre($n);
    $xl->celula($folha, $linha, 1, $rot, $eTotalTxt);
    $xl->mesclar($folha, $linha, 1, $linha, 7);
    $linha++;
    foreach (['S', 'T', 'Q', 'Q', 'S', 'Sáb', 'Dias letivos'] as $i => $rotCol) {
        $xl->celula($folha, $linha, $i + 1, $rotCol, $i >= 5 ? $eDowFds : $eDowUtil);
    }
    $linha++;
    for ($d = 1; $d <= 6; $d++) {
        $xl->celula($folha, $linha, $d, (int) $c['por_dow'][$d], $eDiaUtil);
    }
    $xl->celula($folha, $linha, 7, (int) $c['total'], $eTotalNum);
    $linha += 2;
}

if ($notas) {
    foreach ($notas as $sem => $linhas) {
        $xl->celula($folha, $linha, 1, $sem . 'º Semestre', $eTotalTxt);
        $linha++;
        foreach ($linhas as $l) {
            $xl->celula($folha, $linha, 1, $l, $eNota);
            $xl->mesclar($folha, $linha, 1, $linha, 8);
            $xl->altura($folha, $linha, Xlsx::alturaParaTexto($l, 14 * 8, 10));
            $linha++;
        }
    }
    $linha++;
}

if (trim((string) $cal['observacoes']) !== '') {
    foreach (explode("\n", $cal['observacoes']) as $l) {
        $xl->celula($folha, $linha, 1, $l, $eNota);
        $xl->mesclar($folha, $linha, 1, $linha, 8);
        $xl->altura($folha, $linha, Xlsx::alturaParaTexto($l, 14 * 8, 10));
        $linha++;
    }
    $linha++;
}

$xl->celula($folha, $linha, 1, 'Legenda', $eTotalTxt);
$linha++;
foreach ($legenda as $c) {
    $xl->celula($folha, $linha, 1, '', $xl->estilo(['bg' => $c['cor'], 'borda' => true]));
    $xl->celula($folha, $linha, 2, $c['nome'], $eNota);
    $xl->mesclar($folha, $linha, 2, $linha, 8);
    $xl->altura($folha, $linha, Xlsx::alturaParaTexto($c['nome'], 14 * 7, 10));
    $linha++;
}
for ($c = 1; $c <= 8; $c++) {
    $xl->largura($folha, $c, 14);
}

// chaveDeOrdem() já existe para ordenar sem acento; serve igual aqui, para o
// nome do arquivo não perder as letras acentuadas do título em vez de só
// descartá-las.
$nomeArquivo = trim((string) preg_replace('/[^a-z0-9]+/', '_', chaveDeOrdem($titulo)), '_') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $nomeArquivo . '"');
$bytes = $xl->bytes();
header('Content-Length: ' . strlen($bytes));
echo $bytes;
