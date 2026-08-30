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

/**
 * Domingo de Páscoa do ano, base dos feriados móveis.
 *
 * Sai de easter_days(), que devolve quantos dias a Páscoa cai depois de 21 de
 * março — um número puro, sem hora e sem fuso.
 *
 * easter_date() não serve aqui: ela devolve um timestamp, e a convenção dele
 * muda com a versão do PHP — em umas é meia-noite UTC, em outras é meia-noite
 * do fuso local. Como o sistema roda fixo em America/Araguaina (UTC-3),
 * formatar com date() um timestamp de meia-noite UTC devolve o dia anterior, e
 * a Páscoa inteira anda um dia para trás: Carnaval, Quarta-feira de Cinzas,
 * Sexta-feira da Paixão e Corpus Christi saem todos errados no calendário
 * impresso. O desenvolvimento aqui é em PHP 8.5 e não mostrava nada; o modo
 * desktop empacota o PHP 8.3, que mostrava.
 */
function domingoDePascoa(int $ano): DateTimeImmutable
{
    return (new DateTimeImmutable("$ano-03-21"))->modify('+' . easter_days($ano) . ' days');
}

/** Os feriados cadastrados, na ordem em que a tela mostra. */
function feriadosCadastrados(PDO $db): array
{
    return $db->query(
        "SELECT f.*, c.nome AS categoria_nome, c.cor, c.cor_texto, c.prioridade, c.letivo
           FROM feriados f LEFT JOIN categorias c ON c.id = f.categoria_id
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
    $ehUtil = ehDiaUtil($db, $ano);
    $ate = static fn (DateTimeImmutable $d, string $passo): string => ateDiaUtil($d, $passo, $ehUtil);

    $natal = new DateTimeImmutable("$ano-12-25");
    $sexta = $natal->modify('monday this week')->modify('-3 days');

    return [
        'sem1_inicio' => $ate(new DateTimeImmutable("$ano-02-01"), '+1 day'),
        'sem1_fim'    => $ate(new DateTimeImmutable("$ano-06-30"), '-1 day'),
        'sem2_inicio' => $ate(new DateTimeImmutable("$ano-08-01"), '+1 day'),
        'sem2_fim'    => $ate($sexta, '-1 day'),
    ];
}

/**
 * Diz se uma data é dia útil naquele ano: dia de semana que não é feriado nem
 * ponto facultativo. Os feriados do ano são resolvidos uma vez só.
 */
function ehDiaUtil(PDO $db, int $ano): Closure
{
    static $cache = [];
    // A chave leva a conexão junto do ano: num pedido web há um banco só, mas a
    // suíte troca de banco várias vezes dentro do mesmo processo, e por ano só o
    // segundo banco responderia com os feriados do primeiro.
    $chave = spl_object_id($db) . ':' . $ano;
    if (!isset($cache[$chave])) {
        $f = [];
        foreach (feriadosDoAno($db, $ano) as $x) {
            $f[$x['data']] = true;
        }
        $cache[$chave] = $f;
    }
    $feriados = $cache[$chave];
    return static function (DateTimeImmutable $d) use ($feriados): bool {
        $dow = (int) $d->format('w');
        return $dow !== 0 && $dow !== 6 && !isset($feriados[$d->format('Y-m-d')]);
    };
}

/** Anda de $passo em $passo até cair num dia útil. Devolve Y-m-d. */
function ateDiaUtil(DateTimeImmutable $d, string $passo, Closure $ehUtil): string
{
    for ($i = 0; $i < 40 && !$ehUtil($d); $i++) {
        $d = $d->modify($passo);
    }
    return $d->format('Y-m-d');
}

/**
 * Datas prováveis dos quatro bimestres, para abrir o formulário preenchido.
 * Sai dos semestres sugeridos, partindo cada um ao meio num dia útil: o 1º
 * bimestre vai do início do semestre até essa metade, e o 2º do dia útil
 * seguinte até o fim do semestre. É palpite, como o dos semestres — quem monta
 * o calendário ajusta.
 */
function bimestresSugeridos(PDO $db, int $ano): array
{
    $s      = semestresSugeridos($db, $ano);
    $ehUtil = ehDiaUtil($db, $ano);
    $out    = [];
    $n      = 1;

    foreach ([[$s['sem1_inicio'], $s['sem1_fim']], [$s['sem2_inicio'], $s['sem2_fim']]] as [$ini, $fim]) {
        $a     = new DateTimeImmutable($ini);
        $b     = new DateTimeImmutable($fim);
        $meio  = $a->modify('+' . intdiv((int) $a->diff($b)->days, 2) . ' days');
        $fimA  = ateDiaUtil($meio, '-1 day', $ehUtil);
        $iniB  = ateDiaUtil((new DateTimeImmutable($fimA))->modify('+1 day'), '+1 day', $ehUtil);

        $out["bim{$n}_inicio"] = $ini;
        $out["bim{$n}_fim"]    = $fimA;
        $n++;
        $out["bim{$n}_inicio"] = $iniB;
        $out["bim{$n}_fim"]    = $fim;
        $n++;
    }
    return $out;
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
