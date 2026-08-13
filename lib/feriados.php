<?php
declare(strict_types=1);

/**
 * Feriados: um cadastro só, que vale para todos os anos.
 *
 * Cada feriado é uma regra, não uma data — os de data fixa guardam dia e mês; os
 * móveis, a distância em dias até o domingo de Páscoa (Carnaval = -48, Corpus
 * Christi = +60). Daí sai a data de qualquer ano, sem ninguém precisar inserir
 * nada ano a ano.
 *
 * Eles não viram linhas em `eventos`: o motor os monta na hora de desenhar o
 * calendário. Assim, corrigir um feriado no cadastro conserta todos os anos de
 * uma vez, e nenhum ano fica com uma cópia velha.
 */

/** Domingo de Páscoa do ano, base dos feriados móveis. */
function domingoDePascoa(int $ano): DateTimeImmutable
{
    return new DateTimeImmutable(date('Y-m-d', easter_date($ano)));
}

/** Os feriados cadastrados e ativos, na ordem em que a tela mostra. */
function feriadosCadastrados(PDO $db): array
{
    return $db->query(
        "SELECT f.*, c.nome AS categoria_nome, c.cor, c.cor_texto, c.prioridade, c.letivo
           FROM feriados f LEFT JOIN categorias c ON c.id = f.categoria_id
          WHERE f.ativo = 1
          ORDER BY CASE f.tipo WHEN 'fixo' THEN f.mes ELSE 0 END, f.dia, f.deslocamento, f.nome"
    )->fetchAll();
}

/** Data que um feriado cadastrado ocupa em determinado ano (Y-m-d). */
function dataDoFeriado(array $f, int $ano): ?string
{
    if ($f['tipo'] === 'movel') {
        $d = (int) $f['deslocamento'];
        return domingoDePascoa($ano)->modify(($d >= 0 ? '+' : '') . $d . ' days')->format('Y-m-d');
    }
    // 29/02 em ano comum simplesmente não acontece.
    if (!checkdate((int) $f['mes'], (int) $f['dia'], $ano)) {
        return null;
    }
    return sprintf('%04d-%02d-%02d', $ano, (int) $f['mes'], (int) $f['dia']);
}

/**
 * Os feriados de um ano já com a data resolvida, na ordem do calendário.
 * Cada item é o cadastro somado a 'data'.
 */
function feriadosDoAno(PDO $db, int $ano): array
{
    $out = [];
    foreach (feriadosCadastrados($db) as $f) {
        $data = dataDoFeriado($f, $ano);
        if ($data === null) {
            continue;
        }
        $f['data'] = $data;
        $out[] = $f;
    }
    usort($out, static fn ($a, $b) => [$a['data'], $a['nome']] <=> [$b['data'], $b['nome']]);
    return $out;
}

/**
 * Datas prováveis dos dois semestres de um ano, para abrir o formulário de um
 * calendário novo já preenchido. É palpite, não regra: quem monta o calendário
 * ajusta o que a instituição decidir.
 *
 * 1º semestre: do primeiro dia útil de fevereiro ao último de junho.
 * 2º semestre: do primeiro dia útil de agosto ao fim da semana anterior à
 * semana do Natal — a sexta antes da segunda-feira da semana em que cai 25/12.
 *
 * Dia útil aqui é dia de semana que não é feriado nem ponto facultativo, então
 * o palpite já desvia do Carnaval, da Sexta-feira da Paixão e afins.
 */
function semestresSugeridos(PDO $db, int $ano): array
{
    $feriados = [];
    foreach (feriadosDoAno($db, $ano) as $f) {
        $feriados[$f['data']] = true;
    }
    $ehUtil = static function (DateTimeImmutable $d) use ($feriados): bool {
        $dow = (int) $d->format('w');
        return $dow !== 0 && $dow !== 6 && !isset($feriados[$d->format('Y-m-d')]);
    };
    $ate = static function (DateTimeImmutable $d, string $passo) use ($ehUtil): string {
        for ($i = 0; $i < 40 && !$ehUtil($d); $i++) {
            $d = $d->modify($passo);
        }
        return $d->format('Y-m-d');
    };

    $natal = new DateTimeImmutable("$ano-12-25");
    $sexta = $natal->modify('monday this week')->modify('-3 days');

    return [
        'sem1_inicio' => $ate(new DateTimeImmutable("$ano-02-01"), '+1 day'),
        'sem1_fim'    => $ate(new DateTimeImmutable("$ano-06-30"), '-1 day'),
        'sem2_inicio' => $ate(new DateTimeImmutable("$ano-08-01"), '+1 day'),
        'sem2_fim'    => $ate($sexta, '-1 day'),
    ];
}

/** "25 de dezembro" ou "60 dias depois da Páscoa" — como a regra se lê na tela. */
function regraDoFeriado(array $f): string
{
    if ($f['tipo'] === 'fixo') {
        return sprintf('%d de %s', (int) $f['dia'], mesExtenso((int) $f['mes']));
    }
    $d = (int) $f['deslocamento'];
    if ($d === 0) {
        return 'domingo de Páscoa';
    }
    return abs($d) . ' dia' . (abs($d) === 1 ? '' : 's')
         . ($d < 0 ? ' antes' : ' depois') . ' da Páscoa';
}
