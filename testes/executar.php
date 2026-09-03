<?php
declare(strict_types=1);

/**
 * Suíte do Calendário Acadêmico, sem dependência nenhuma:
 *
 *     php testes/executar.php
 *
 * O alvo é o motor — a única parte do sistema que decide alguma coisa sozinha.
 * As telas são CRUD e se conferem abrindo; a contagem de dias letivos, não: ela
 * sai de regras que se cruzam, e é o número que vai para o papel homologado.
 *
 * Cada teste monta o cenário do zero num banco temporário, criado do próprio
 * schema.sql com o seed de fábrica. A base do site não é tocada.
 */

$tmp = sys_get_temp_dir() . '/calendario-teste-' . getmypid() . '.sqlite';
foreach ([$tmp, "$tmp-wal", "$tmp-shm"] as $f) {
    @unlink($f);
}
putenv('CALENDARIO_DB=' . $tmp);
register_shutdown_function(static function () use ($tmp): void {
    foreach ([$tmp, "$tmp-wal", "$tmp-shm"] as $f) {
        @unlink($f);
    }
});

require __DIR__ . '/../lib/boot.php';
// O boot carrega o que toda tela precisa; os tratadores de POST cada tela pede
// por conta. A suíte pede aqui os que ela testa por fora da tela.
require __DIR__ . '/../lib/eventos_crud.php';

// ─────────────────────────────────────────────────────────── mínimo de suíte

$GLOBALS['passou'] = 0;
$GLOBALS['falhou'] = [];

function confere(string $titulo, mixed $obtido, mixed $esperado): void
{
    if ($obtido === $esperado) {
        $GLOBALS['passou']++;
        echo "  \033[32m✓\033[0m $titulo\n";
        return;
    }
    $GLOBALS['falhou'][] = $titulo;
    echo "  \033[31m✗\033[0m $titulo\n";
    echo "      esperado: " . mostrar($esperado) . "\n";
    echo "      obtido:   " . mostrar($obtido) . "\n";
}

function mostrar(mixed $v): string
{
    return match (true) {
        is_bool($v)  => $v ? 'true' : 'false',
        is_null($v)  => 'null',
        is_scalar($v) => var_export($v, true),
        default      => json_encode($v, JSON_UNESCAPED_UNICODE),
    };
}

function grupo(string $nome): void
{
    echo "\n\033[1m$nome\033[0m\n";
}

// ───────────────────────────────────────────────────── montagem de cenários

const ANO = 2026;

function categoriaId(PDO $db, string $nome): int
{
    $st = $db->prepare('SELECT id FROM categorias WHERE nome = ?');
    $st->execute([$nome]);
    $id = $st->fetchColumn();
    if ($id === false) {
        throw new RuntimeException("categoria de fábrica ausente: $nome");
    }
    return (int) $id;
}

/**
 * Grava só os dois semestres de um calendário — o formato de antes dos
 * bimestres. A aplicação não faz mais isso em lugar nenhum: quem manda são os
 * quatro bimestres, e os semestres saem deles. Fica aqui porque os testes
 * precisam montar o calendário legado para conferir que ele continua sendo
 * lido.
 *
 * @param array<int, array{0: string, 1: string}> $semestres numero => [inicio, fim]
 */
function salvarSemestres(PDO $db, int $calendarioId, array $semestres): void
{
    $ins = $db->prepare("INSERT INTO periodos (calendario_id, tipo, numero, inicio, fim) VALUES (?, 'semestre', ?, ?, ?)");
    $db->prepare("DELETE FROM periodos WHERE calendario_id = ? AND tipo = 'semestre'")->execute([$calendarioId]);
    foreach ($semestres as $numero => [$inicio, $fim]) {
        $ins->execute([$calendarioId, $numero, $inicio, $fim]);
    }
}

/** Curso + calendário + os dois semestres. Devolve o id do calendário. */
function calendarioDeTeste(
    PDO $db,
    string $curso = 'CURSO DE TESTE',
    string $nivel = 'superior',
    string $sem1i = '2026-02-02',
    string $sem1f = '2026-06-30',
    string $sem2i = '2026-08-03',
    string $sem2f = '2026-12-18',
): int {
    $db->prepare('INSERT INTO cursos (nome, nivel, ativo) VALUES (?,?,1)')->execute([$curso, $nivel]);
    $cursoId = (int) $db->lastInsertId();

    $db->prepare('INSERT INTO calendarios (curso_id, ano) VALUES (?,?)')->execute([$cursoId, ANO]);
    $calId = (int) $db->lastInsertId();

    salvarSemestres($db, $calId, [1 => [$sem1i, $sem1f], 2 => [$sem2i, $sem2f]]);
    return $calId;
}

/**
 * Um evento com suas faixas. $campos aceita categoria_id, conta_letivo,
 * pinta_dias, nivel e repoe_dow; o resto vai no padrão.
 */
function evento(PDO $db, ?int $calId, string $descricao, array $faixas, array $campos = []): int
{
    $db->prepare(
        'INSERT INTO eventos (ano, calendario_id, categoria_id, descricao, pinta_dias, conta_letivo, nivel, repoe_dow)
         VALUES (?,?,?,?,?,?,?,?)'
    )->execute([
        ANO,
        $calId,
        $campos['categoria_id'] ?? null,
        $descricao,
        $campos['pinta_dias']   ?? 1,
        $campos['conta_letivo'] ?? null,
        $campos['nivel']        ?? null,
        $campos['repoe_dow']    ?? null,
    ]);
    $id = (int) $db->lastInsertId();
    salvarFaixas($db, $id, array_map(
        static fn ($f) => is_array($f) ? ['inicio' => $f[0], 'fim' => $f[1]] : ['inicio' => $f, 'fim' => $f],
        $faixas
    ));
    return $id;
}

/** Um banco de fábrica limpo, do schema + seed, para o cenário seguinte. */
function bancoLimpo(): PDO
{
    $db = db();
    // A ordem respeita as chaves estrangeiras; config, categorias, feriados e
    // níveis ficam como o seed os deixou — é o estado de instalação nova.
    foreach (['evento_datas', 'eventos', 'periodos', 'calendarios', 'cursos'] as $t) {
        $db->exec("DELETE FROM $t");
    }
    $db->exec("DELETE FROM feriados WHERE nome LIKE 'TESTE %'");
    return $db;
}

/** Quatro bimestres válidos, para quem só precisa de períodos gravados. */
const BIMESTRES_TESTE = [
    1 => ['2026-02-02', '2026-04-20'], 2 => ['2026-04-22', '2026-07-02'],
    3 => ['2026-07-30', '2026-10-08'], 4 => ['2026-10-09', '2026-12-18'],
];

// ══════════════════════════════════════════════════════════════════ testes

grupo('Dia letivo: a regra do dia da semana');
$db  = bancoLimpo();
$cal = calendarioDeTeste($db);
$eng = Engine::paraCalendario($db, $cal);

confere('quarta-feira dentro do semestre é letiva',  $eng->dia('2026-03-04')['letivo'], true);
confere('sábado sem evento não é letivo',            $eng->dia('2026-03-07')['letivo'], false);
confere('domingo sem evento não é letivo',           $eng->dia('2026-03-08')['letivo'], false);
confere('dia fora dos dois semestres não é letivo',  $eng->dia('2026-07-15')['letivo'], false);
confere('dia no recesso entre semestres não conta',  $eng->dia('2026-07-01')['letivo'], false);

grupo('Sábado letivo e a precedência do "não" sobre o "sim"');
$db     = bancoLimpo();
$cal    = calendarioDeTeste($db);
$base   = Engine::paraCalendario($db, $cal)->contagemSemestre(1)['total'];
$sabado = '2026-03-07';   // sábado dentro do 1º semestre

evento($db, $cal, 'Sábado letivo', [$sabado], ['conta_letivo' => 1]);
$eng = Engine::paraCalendario($db, $cal);
confere('sábado com conta_letivo=1 vira letivo',   $eng->dia($sabado)['letivo'], true);
confere('e o semestre ganha exatamente um dia',    $eng->contagemSemestre(1)['total'], $base + 1);
confere('que entra na coluna de sábado',           $eng->contagemSemestre(1)['por_dow'][6], 1);

// Um feriado em cima do mesmo sábado: o "não" da categoria vence o "sim" do
// evento, e o dia volta a não contar.
$db->prepare("INSERT INTO feriados (nome, tipo, dia, mes, categoria_id) VALUES ('TESTE Feriado no sábado','fixo',7,3,?)")
   ->execute([categoriaId($db, 'Feriado Nacional')]);
$eng = Engine::paraCalendario($db, $cal);
confere('feriado em cima do sábado letivo derruba o dia', $eng->dia($sabado)['letivo'], false);
confere('e o semestre volta ao total de antes',           $eng->contagemSemestre(1)['total'], $base);

grupo('Fora do semestre nada conta');
$db  = bancoLimpo();
$cal = calendarioDeTeste($db);
evento($db, $cal, 'Mutirão nas férias', ['2026-07-15'], ['conta_letivo' => 1]);
$eng = Engine::paraCalendario($db, $cal);
confere('conta_letivo=1 fora dos semestres não vale', $eng->dia('2026-07-15')['letivo'], false);

grupo('Cor do dia: vence a categoria de maior prioridade');
$db  = bancoLimpo();
$cal = calendarioDeTeste($db);
evento($db, $cal, 'Culminância', ['2026-03-10'], ['categoria_id' => categoriaId($db, 'Datas comemorativas')]);  // prioridade 45
evento($db, $cal, 'Exame final',  ['2026-03-10'], ['categoria_id' => categoriaId($db, 'Exame Final')]);                                     // prioridade 80
$eng = Engine::paraCalendario($db, $cal);
confere('a de prioridade 80 pinta o dia, não a de 45', $eng->dia('2026-03-10')['categoria']['nome'], 'Exame Final');

// Tiradentes: feriado nacional, prioridade 99 — acima do teto do formulário.
evento($db, $cal, 'Exame final', ['2026-04-21'], ['categoria_id' => categoriaId($db, 'Exame Final')]);
$eng = Engine::paraCalendario($db, $cal);
confere('feriado vence qualquer categoria do formulário', $eng->dia('2026-04-21')['categoria']['nome'], 'Feriado Nacional');
confere('e o dia do feriado não é letivo',                $eng->dia('2026-04-21')['letivo'], false);
confere('evento com pinta_dias=0 não disputa a cor',      (function () use ($db) {
    $cal = calendarioDeTeste($db, 'OUTRO CURSO');
    evento($db, $cal, 'Só na lista', ['2026-03-11'], [
        'categoria_id' => categoriaId($db, 'Exame Final'), 'pinta_dias' => 0,
    ]);
    return Engine::paraCalendario($db, $cal)->dia('2026-03-11')['categoria'];
})(), null);

grupo('Feriados móveis andam com a Páscoa');
$db = bancoLimpo();
$feriados = [];
foreach (feriadosDoAno($db, 2026) as $f) {
    $feriados[$f['nome']][] = $f['data'];
}
// Páscoa de 2026: 5 de abril. Carnaval = -48 e -47; Corpus Christi = +60.
confere('Carnaval de 2026 cai em 16 e 17/02',  $feriados['Carnaval'], ['2026-02-16', '2026-02-17']);
confere('Corpus Christi de 2026 cai em 04/06', $feriados['Corpus Christi'], ['2026-06-04']);
confere('Sexta-feira da Paixão em 03/04',      $feriados['Sexta-feira da Paixão'], ['2026-04-03']);
// A Páscoa de anos conhecidos, para o cálculo não sair da realidade em silêncio.
confere('Páscoa de anos conhecidos', array_map(
    static fn (int $a): string => domingoDePascoa($a)->format('Y-m-d'),
    [2024, 2025, 2026, 2027, 2028, 2030]
), ['2024-03-31', '2025-04-20', '2026-04-05', '2027-03-28', '2028-04-16', '2030-04-21']);

// Regressão: a Páscoa não pode depender do fuso. Com easter_date() dependia —
// o timestamp dela é meia-noite UTC em umas versões do PHP e meia-noite local
// em outras, e formatado em America/Araguaina (UTC-3) caía no dia anterior,
// levando junto Carnaval, Cinzas, Paixão e Corpus Christi. Passava no PHP 8.5
// do desenvolvimento e quebrava no 8.3, que é o que o modo desktop empacota.
confere('a Páscoa não anda com o fuso', (function (): array {
    $original = date_default_timezone_get();
    $out = [];
    foreach (['UTC', 'America/Araguaina', 'Pacific/Kiritimati', 'Pacific/Midway'] as $tz) {
        date_default_timezone_set($tz);
        $out[] = domingoDePascoa(2026)->format('Y-m-d');
    }
    date_default_timezone_set($original);
    return array_values(array_unique($out));
})(), ['2026-04-05']);

// Inativar saiu do cadastro: um feriado que não deve valer se exclui, e a
// coluna que o guardava não existe mais em banco nenhum.
confere('não há mais feriado inativo',
    in_array('ativo', $db->query('PRAGMA table_info(feriados)')->fetchAll(PDO::FETCH_COLUMN, 1), true),
    false);
confere('e todo feriado cadastrado entra no ano',
    count(feriadosDoAno($db, 2026)),
    (int) $db->query('SELECT COUNT(*) FROM feriados')->fetchColumn());

grupo('Eventos globais e os níveis de ensino');
$db   = bancoLimpo();
$sup  = calendarioDeTeste($db, 'CURSO SUPERIOR',   'superior');
$int  = calendarioDeTeste($db, 'CURSO INTEGRADO',  'integrado');
evento($db, null, 'Só para o integrado', ['2026-03-12'], ['nivel' => 'integrado']);
evento($db, null, 'Para todo mundo',     ['2026-03-13'], ['nivel' => null]);

$descricoes = static fn (Engine $e): array => array_values(array_unique(
    array_column(array_filter($e->eventos(), static fn ($ev) => !isset($ev['feriado_id'])), 'descricao')
));
confere('o curso superior não vê o evento do integrado', $descricoes(Engine::paraCalendario($db, $sup)), ['Para todo mundo']);
confere('o curso integrado vê os dois',                  $descricoes(Engine::paraCalendario($db, $int)), ['Só para o integrado', 'Para todo mundo']);

grupo('Eventos sem faixa de data não existem no calendário');
$db  = bancoLimpo();
$cal = calendarioDeTeste($db);
$db->prepare('INSERT INTO eventos (ano, calendario_id, descricao) VALUES (?,?,?)')->execute([ANO, $cal, 'Evento órfão']);
confere('evento sem data fica de fora', $descricoes(Engine::paraCalendario($db, $cal)), []);

grupo('Semestres implícitos quando não foram cadastrados');
$db = bancoLimpo();
$db->prepare('INSERT INTO cursos (nome, nivel, ativo) VALUES (?,?,1)')->execute(['SEM SEMESTRES', 'superior']);
$db->prepare('INSERT INTO calendarios (curso_id, ano) VALUES (?,?)')->execute([(int) $db->lastInsertId(), ANO]);
$eng = Engine::paraCalendario($db, (int) $db->lastInsertId());
confere('o motor avisa que os semestres não vieram', $eng->semestresImplicitos(), true);
confere('e o ano inteiro passa a contar',            $eng->dia('2026-07-15')['letivo'], true);

grupo('Rótulo das datas, como sai no papel');
$db  = bancoLimpo();
$cal = calendarioDeTeste($db);
$eng = Engine::paraCalendario($db, $cal);
$rot = static fn (array $faixas, ?string $rotulo = null): string => $eng->rotulo([
    'rotulo' => $rotulo,
    'datas'  => array_map(static fn ($f) => ['inicio' => $f[0], 'fim' => $f[1]], $faixas),
]);

confere('dia único',                     $rot([['2026-03-05', '2026-03-05']]), '5');
confere('faixa dentro do mês',           $rot([['2026-03-05', '2026-03-09']]), '5 a 9');
confere('duas faixas',                   $rot([['2026-03-14', '2026-03-16'], ['2026-03-19', '2026-03-20']]), '14 a 16 e 19 a 20');
confere('três faixas usam vírgula e "e"', $rot([['2026-03-05', '2026-03-08'], ['2026-03-20', '2026-03-20'], ['2026-02-10', '2026-02-12']]), '5 a 8, 20 e 10/2 a 12/2');
// Atenção: o docblock de Engine::rotulo() dá este caso como "30/9 a 24/10",
// mas o código omite o mês do início quando ele é o mês-base da faixa — e o
// mês-base é justamente o do início, então ele nunca aparece. O teste registra
// o que o sistema faz hoje; sob o nome do mês impresso, "30 a 24/10" se lê sem
// ambiguidade. Se o papel exigir o outro formato, é o exemplo que está certo e
// o código que precisa mudar.
confere('faixa que atravessa o mês',     $rot([['2026-09-30', '2026-10-24']]), '30 a 24/10');
confere('o rótulo digitado manda',       $rot([['2026-03-05', '2026-03-06']], '5 e 6'), '5 e 6');

grupo('Data que não existe é recusada, não corrigida em silêncio');
// "31/02" saía daqui como a cadeia "2026-02-31": o banco aceitava, o
// DateTimeImmutable lia como 3 de março, e o evento era listado em fevereiro e
// pintado em março. "99/99" era pior — virava "2026-99-99", que o
// DateTimeImmutable recusa com exceção, derrubando a tela em 500.
confere('31 de fevereiro não existe',      parseFaixas('31/02/2026', ANO), []);
confere('30 de fevereiro tampouco',        parseFaixas('30/02/2026', ANO), []);
confere('31 de abril tampouco',            parseFaixas('31/04/2026', ANO), []);
confere('99/99 não vira data nenhuma',     parseFaixas('99/99/2026', ANO), []);
confere('mês 13 não existe',               parseFaixas('15/13/2026', ANO), []);
confere('nem em ISO passa',                parseFaixas('2026-02-31', ANO), []);
// 29 de fevereiro existe no bissexto e não existe fora dele.
confere('29/02 em ano comum é recusado',   parseFaixas('29/02/2026', ANO), []);
confere('e em bissexto passa',             parseFaixas('29/02/2024', 2024),
    [['inicio' => '2024-02-29', 'fim' => '2024-02-29']]);
// O dia solto herda mês e ano da referência — e é conferido do mesmo jeito. Não
// existindo o fim, a linha inteira cai: "14/02 a 31" pede 14 a 31 de fevereiro,
// e virar só o dia 14 seria entregar calado menos do que foi pedido.
confere('dia solto impossível derruba a faixa toda', parseFaixas('14/02 a 31', ANO), []);
confere('e a linha é nomeada para a tela',  datasRecusadas('14/02 a 31', ANO), ['14/02 a 31']);
confere('dia solto possível entra',        parseFaixas('14/02 a 20', ANO),
    [['inicio' => '2026-02-14', 'fim' => '2026-02-20']]);

// A tela precisa dizer qual linha não entendeu: descartada em silêncio no meio
// de datas boas, quem cadastrou sai achando que gravou o que digitou.
confere('a linha recusada é nomeada',      datasRecusadas("10/03/2026\n31/02/2026", ANO), ['31/02/2026']);
confere('e as boas não entram na lista',   datasRecusadas('10/03/2026', ANO), []);
confere('texto solto também é recusado',   datasRecusadas('semana que vem', ANO), ['semana que vem']);

grupo('Períodos gravados de dentro de uma transação maior');
$db  = bancoLimpo();
$cal = calendarioDeTeste($db);
// A criação de um calendário abre a transação e chama salvarPeriodos() dentro
// dela; o PDO não aninha, então a função só abre a própria quando não há uma.
confere('salvarPeriodos não abre transação por cima da que já existe', (function () use ($db, $cal) {
    $db->beginTransaction();
    salvarPeriodos($db, $cal, BIMESTRES_TESTE);
    $dentro = $db->inTransaction();
    $db->commit();
    return [$dentro, $db->inTransaction()];
})(), [true, false]);
confere('e os períodos ficaram gravados',
    array_keys(bimestresDoCalendario($db, $cal)), [1, 2, 3, 4]);
confere('sozinha, ela ainda fecha a própria transação', (function () use ($db, $cal) {
    salvarPeriodos($db, $cal, BIMESTRES_TESTE);
    return $db->inTransaction();
})(), false);

grupo('O efeito da categoria na conta de dias, em uma palavra');
// A coluna `letivo` tem três estados e nenhum se lê no nome da categoria. Quem
// escolhe a cor de um evento precisa saber antes de escolher, não depois de ver
// o total mudar.
confere('1 obriga o dia a contar',        efeitoDaCategoria(1), 'Letivo');
confere('0 obriga a não contar',          efeitoDaCategoria(0), 'Não letivo');
confere('NULL não mexe na conta',         efeitoDaCategoria(null), 'Neutro');
// O SQLite devolve as colunas como texto: o rótulo não pode depender do tipo.
confere('e o texto do banco vale igual', [
    efeitoDaCategoria('1'), efeitoDaCategoria('0'),
], ['Letivo', 'Não letivo']);
confere('as de fábrica saem como se espera', (function () {
    $db = bancoLimpo();
    $out = [];
    foreach (['Recesso', 'Exame Final', 'Férias', CAT_SEMESTRE] as $nome) {
        $st = $db->prepare('SELECT letivo FROM categorias WHERE nome = ?');
        $st->execute([$nome]);
        $out[$nome] = efeitoDaCategoria($st->fetchColumn());
    }
    return $out;
})(), ['Recesso' => 'Não letivo', 'Exame Final' => 'Neutro',
       'Férias' => 'Não letivo', CAT_SEMESTRE => 'Neutro']);

grupo('Copiar eventos de um calendário para outro');
$db  = bancoLimpo();
$origem  = calendarioBimestral($db, 'anual', BIMESTRES_TESTE, 'CURSO ORIGEM');
$destino = calendarioBimestral($db, 'anual', BIMESTRES_TESTE, 'CURSO DESTINO');
evento($db, $origem, 'Reunião comum',  ['2026-03-10']);
evento($db, $origem, 'Sábado letivo',  ['2026-03-07'], ['conta_letivo' => 1, 'repoe_dow' => 1]);
evento($db, $origem, 'Sem data', []);

/** O que existe no calendário, por descrição. */
$copiados = static function (PDO $db, int $cal): array {
    $st = $db->prepare('SELECT descricao FROM eventos WHERE calendario_id = ? ORDER BY id');
    $st->execute([$cal]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
};

// Padrão: a reposição fica para trás. Um "sábado com horário de segunda" repõe
// uma segunda perdida num feriado daquele ano; no ano seguinte esse feriado cai
// noutro dia da semana, e o sábado copiado repõe um dia que não faltou.
confere('por padrão a reposição não vem junto', (function () use ($db, $origem, $destino, $copiados) {
    copiarEventos($db, $origem, $destino, 2027);
    return $copiados($db, $destino);
})(), ['Reunião comum']);

// Pedindo, ela vem — e traz o repoe_dow, senão viraria um sábado letivo mudo,
// que é o que a validação do formulário recusa.
$db      = bancoLimpo();
$origem  = calendarioBimestral($db, 'anual', BIMESTRES_TESTE, 'CURSO ORIGEM');
$destino = calendarioBimestral($db, 'anual', BIMESTRES_TESTE, 'CURSO DESTINO');
evento($db, $origem, 'Reunião comum', ['2026-03-10']);
evento($db, $origem, 'Sábado letivo', ['2026-03-07'], ['conta_letivo' => 1, 'repoe_dow' => 1]);
confere('marcado, ela vem', (function () use ($db, $origem, $destino, $copiados) {
    copiarEventos($db, $origem, $destino, 2027, true);
    return $copiados($db, $destino);
})(), ['Reunião comum', 'Sábado letivo']);
confere('e chega com a reposição preenchida', (function () use ($db, $destino) {
    $st = $db->prepare("SELECT repoe_dow, conta_letivo FROM eventos
                         WHERE calendario_id = ? AND descricao = 'Sábado letivo'");
    $st->execute([$destino]);
    $e = $st->fetch();
    return [(int) $e['repoe_dow'], (int) $e['conta_letivo']];
})(), [1, 1]);
// A data anda um ano, como a de qualquer evento copiado.
confere('com a data deslocada para o ano novo', (function () use ($db, $destino) {
    $st = $db->prepare("SELECT d.inicio FROM evento_datas d JOIN eventos e ON e.id = d.evento_id
                         WHERE e.calendario_id = ? AND e.descricao = 'Sábado letivo'");
    $st->execute([$destino]);
    return $st->fetchColumn();
})(), '2027-03-07');

grupo('Dia letivo que sobra entre os bimestres');
// O semestre vai do início do primeiro bimestre ao fim do segundo. Um vão entre
// os dois deixa dias contando no semestre e em bimestre nenhum, e a soma das
// duas linhas do resumo para de fechar sem nada dizer por quê.
$db = bancoLimpo();

// O 3º bimestre fecha na sexta 09/10 e o 4º abre na segunda 12/10: o vão é o fim
// de semana, e o sábado dele é letivo. É o caso real — um sábado de reposição
// entre o fim de um bimestre e o começo do outro.
$comVao = calendarioBimestral($db, 'anual', [
    1 => ['2026-02-02', '2026-04-20'], 2 => ['2026-04-22', '2026-07-02'],
    3 => ['2026-07-30', '2026-10-09'], 4 => ['2026-10-12', '2026-12-18'],
], 'CURSO COM VÃO');
evento($db, $comVao, 'Sábado letivo', ['2026-10-10'], ['conta_letivo' => 1, 'repoe_dow' => 2]);
$eng = Engine::paraCalendario($db, $comVao);
confere('o sábado do vão é apontado',
    $eng->diasForaDosBimestres(), ['2026-10-10']);
confere('e é ele que faz a soma não fechar', [
    $eng->contagemBimestre(3)['total'] + $eng->contagemBimestre(4)['total'],
    $eng->contagemSemestre(2)['total'],
], [$eng->contagemSemestre(2)['total'] - 1, $eng->contagemSemestre(2)['total']]);

// Vão sem dia letivo dentro não é problema, e é o caso comum: entre o 1º e o 2º
// bimestre costuma haver um feriado, que não conta para ninguém. 21/04 é
// Tiradentes, e o vão acima entre 20/04 e 22/04 é exatamente ele.
confere('vão só com feriado dentro não é apontado', (function () use ($db) {
    $cal = calendarioBimestral($db, 'anual', [
        1 => ['2026-02-02', '2026-04-20'], 2 => ['2026-04-22', '2026-07-02'],
        3 => ['2026-07-30', '2026-10-08'], 4 => ['2026-10-09', '2026-12-18'],
    ], 'CURSO SEM VÃO');
    return Engine::paraCalendario($db, $cal)->diasForaDosBimestres();
})(), []);

// Sem os quatro bimestres não há o que comparar: o calendário anterior a eles
// já tem o próprio aviso na tela.
confere('calendário sem bimestres não aponta nada',
    Engine::paraCalendario($db, calendarioDeTeste($db))->diasForaDosBimestres(), []);

grupo('A ordem da lista do mês');
$db  = bancoLimpo();
$cal = calendarioDeTeste($db);

/** Os rótulos do mês, só dos eventos cadastrados — sem os feriados de fábrica. */
$soMeus = static function (PDO $db, int $cal, int $mes, array $descricoes): array {
    $out = [];
    foreach (Engine::paraCalendario($db, $cal)->eventosDoMes($mes) as $item) {
        if (in_array($item['ev']['descricao'], $descricoes, true)) {
            $out[] = $item['rotulo'];
        }
    }
    return $out;
};

// O caso real: dois períodos começando no mesmo dia. O mais curto vem antes,
// que é como se lê — "3 a 5" antes de "3 a 7". Cadastrados na ordem inversa de
// propósito: antes disto mandava o id, e a lista saía na ordem do cadastro.
evento($db, $cal, 'Matrícula',    [['2026-08-03', '2026-08-07']]);
evento($db, $cal, 'Proficiência', [['2026-08-03', '2026-08-05']]);
confere('mesmo início, o que termina antes vem primeiro',
    $soMeus($db, $cal, 8, ['Matrícula', 'Proficiência']), ['3 a 5', '3 a 7']);

// Início e fim iguais: aí manda o id, para a ordem não depender de como o banco
// devolveu as linhas.
evento($db, $cal, 'Primeiro', ['2026-09-10']);
evento($db, $cal, 'Segundo',  ['2026-09-10']);
confere('empatando início e fim, vale a ordem de cadastro', (function () use ($db, $cal) {
    $out = [];
    foreach (Engine::paraCalendario($db, $cal)->eventosDoMes(9) as $item) {
        if (in_array($item['ev']['descricao'], ['Primeiro', 'Segundo'], true)) {
            $out[] = $item['ev']['descricao'];
        }
    }
    return $out;
})(), ['Primeiro', 'Segundo']);

// Feriado e marco de bimestre não têm id: no empate encabeçam o dia, porque são
// o motivo de o dia ser o que é.
evento($db, $cal, 'Evento de Tiradentes', ['2026-04-21']);
confere('no mesmo dia, o feriado vem antes do evento', array_map(
    static fn (array $i): string => $i['ev']['descricao'],
    array_values(array_filter(
        Engine::paraCalendario($db, $cal)->eventosDoMes(4),
        static fn (array $i): bool => $i['rotulo'] === '21'
    ))
), ['Tiradentes', 'Evento de Tiradentes']);

// A tela e o papel ordenam pela mesma função — a comparação é uma só.
confere('a comparação é pública e serve às duas listas', Engine::ordemNaLista(
    ['datas' => [['inicio' => '2026-08-03', 'fim' => '2026-08-05']], 'id' => 99],
    ['datas' => [['inicio' => '2026-08-03', 'fim' => '2026-08-07']], 'id' => 1]
) < 0, true);

grupo('Notas de reposição');
$db  = bancoLimpo();
$cal = calendarioDeTeste($db);
// Quatro sábados de março com horário de segunda (repoe_dow = 1).
evento($db, $cal, 'Sábados letivos', [
    '2026-03-07', '2026-03-14', '2026-03-21', '2026-03-28',
], ['conta_letivo' => 1, 'repoe_dow' => 1]);
confere('quatro sábados com horário de segunda',
    Engine::paraCalendario($db, $cal)->notasReposicao()[1],
    ['4 sábados letivos com horário de segunda']);

grupo('Dias letivos contados pelo horário que cumprem');
// O mesmo cenário dos quatro sábados de março repondo segunda. Contado pelo dia
// em que cai, o semestre tem 4 sábados; contado pelo horário, tem 4 segundas a
// mais e sábado nenhum.
$eng   = Engine::paraCalendario($db, $cal);
$porDia     = $eng->contagemSemestre(1);
$porHorario = $eng->contagemHorarioSemestre(1);
confere('pelo dia da semana, os quatro sábados aparecem como sábado',
    $porDia['por_dow'][6], 4);
confere('pelo horário, não há coluna de sábado',
    array_keys($porHorario['por_dow']), [1, 2, 3, 4, 5]);
confere('e as quatro viraram segundas',
    $porHorario['por_dow'][1] - $porDia['por_dow'][1], 4);
confere('nenhum outro dia da semana se mexeu', [
    $porHorario['por_dow'][2] - $porDia['por_dow'][2],
    $porHorario['por_dow'][3] - $porDia['por_dow'][3],
    $porHorario['por_dow'][4] - $porDia['por_dow'][4],
    $porHorario['por_dow'][5] - $porDia['por_dow'][5],
], [0, 0, 0, 0]);
confere('e o total continua o mesmo, porque todo sábado tinha horário',
    $porHorario['total'], $porDia['total']);

// Reposição não é só de sábado: uma quinta-feira pode cumprir horário de sexta,
// e aí ela sai da coluna da quinta e entra na da sexta.
$db2  = bancoLimpo();
$cal2 = calendarioDeTeste($db2);
evento($db2, $cal2, 'Quinta com horário de sexta', ['2026-03-05'], ['repoe_dow' => 5]);
$e2 = Engine::paraCalendario($db2, $cal2);
confere('a quinta reposta sai da quinta e entra na sexta', [
    $e2->contagemHorarioSemestre(1)['por_dow'][4] - $e2->contagemSemestre(1)['por_dow'][4],
    $e2->contagemHorarioSemestre(1)['por_dow'][5] - $e2->contagemSemestre(1)['por_dow'][5],
], [-1, 1]);

// Sábado letivo sem "repõe" preenchido não tem horário a cumprir: ele conta como
// dia letivo, mas fica fora da contagem por horário. A diferença entre os dois
// totais é o que denuncia o campo em branco.
$db3  = bancoLimpo();
$cal3 = calendarioDeTeste($db3);
evento($db3, $cal3, 'Sábado letivo sem reposição', ['2026-03-07'], ['conta_letivo' => 1]);
$e3 = Engine::paraCalendario($db3, $cal3);
confere('sábado sem reposição conta como dia letivo',
    $e3->contagemSemestre(1)['por_dow'][6], 1);
confere('mas fica de fora da contagem por horário',
    $e3->contagemSemestre(1)['total'] - $e3->contagemHorarioSemestre(1)['total'], 1);

grupo('O ano da tela fica lembrado na sessão');
// Quem está montando 2027 sai para conferir um curso e volta; voltar em 2026
// fazia trocar o ano de novo a cada ida e vinda.
$_SESSION = [];
$_GET = [];
confere('sem nada pedido nem lembrado, vale o padrão',
    anoLembrado('teste', 2026), 2026);
confere('o ano pedido pela URL passa a valer', (function () {
    $_GET['ano'] = '2030';
    return anoLembrado('teste', 2026);
})(), 2030);
confere('e fica lembrado depois que a URL não pede mais', (function () {
    $_GET = [];
    return anoLembrado('teste', 2026);
})(), 2030);
// URL com ano fora da faixa é engano de quem digitou; apagar por causa dela o
// ano em que a pessoa trabalhava seria trocar um engano pequeno por um estrago.
confere('ano inválido não vira o da tela', (function () {
    $_GET['ano'] = '2101';
    return anoLembrado('teste', 2026);
})(), 2030);
confere('nem apaga o que estava lembrado', (function () {
    $_GET = [];
    return anoLembrado('teste', 2026);
})(), 2030);
// Cada tela lembra o seu: eventos globais e feriados podem estar em anos
// diferentes sem se atrapalhar.
confere('cada tela guarda o próprio ano', [
    anoLembrado('outra', 2026), anoLembrado('teste', 2026),
], [2026, 2030]);
$_SESSION = [];
$_GET = [];

grupo('O formulário recusado volta com o que foi digitado');
// Um pedido recusado termina em redirect — é o que impede o F5 de regravar —, e
// o redirect joga fora o que foi digitado. O POST fica guardado na sessão, do
// mesmo jeito que o aviso, e some na primeira leitura.
$_SESSION = [];
confere('sem recusa, não há nada guardado', postGuardado(), []);
confere('o que foi guardado volta inteiro', (function () {
    $_POST = ['descricao' => 'Semana de provas', 'repoe_dow' => '3', 'nivel' => ['superior']];
    guardarPost();
    return postGuardado();
})(), ['descricao' => 'Semana de provas', 'repoe_dow' => '3', 'nivel' => ['superior']]);
confere('e some na segunda leitura, para não vazar na próxima tela',
    postGuardado(), []);

// A tela de um calendário desenha três modais na mesma página. Sem perguntar de
// quem é o guardado, a primeira a montar levava o que era da outra.
confere('só a modal dona do POST o recebe', (function () {
    $_POST = ['acao' => 'salvar_evento', 'descricao' => 'Semana de provas'];
    guardarPost();
    return [
        postGuardado('salvar_calendario'),                 // não é dela
        postGuardado('salvar_feriado'),                    // nem dela
        postGuardado('salvar_evento')['descricao'] ?? '',   // desta, sim
        postGuardado('salvar_evento'),                     // e já foi consumido
    ];
})(), [[], [], 'Semana de provas', []]);

// Senha não volta para a tela: guardá-la a poria em claro no arquivo de sessão
// e depois dentro do HTML.
confere('a senha não é guardada', (function () {
    $_POST = ['acao' => 'salvar', 'usuario' => 'maria', 'senha' => 'segredo', 'senha2' => 'segredo'];
    guardarPost();
    return array_keys(postGuardado('salvar'));
})(), ['acao', 'usuario']);
$_POST = [];

grupo('A legenda de reposição de horário');
$db = bancoLimpo();
// É a única do seed que obriga o dia a contar: o sábado que repõe uma segunda, a
// quinta que cumpre horário de terça. As outras ou tiram o dia da conta ou não
// mexem nela.
confere('vem de fábrica', (function () use ($db) {
    $st = $db->prepare('SELECT cor, letivo, prioridade, protegida, oculta FROM categorias WHERE nome = ?');
    $st->execute(['Reposição de horário']);
    $c = $st->fetch();
    return [$c['cor'], (int) $c['letivo'], (int) $c['prioridade'], (int) $c['protegida'], (int) $c['oculta']];
})(), ['#ffbe6f', 1, 49, 0, 0]);
confere('e é a única que obriga o dia a contar',
    $db->query('SELECT nome FROM categorias WHERE letivo = 1')->fetchAll(PDO::FETCH_COLUMN),
    ['Reposição de horário']);
// Escolhida num evento, ela sozinha faz o sábado contar — sem precisar de
// "conta como letivo" no evento.
confere('sozinha, ela faz o sábado contar', (function () use ($db) {
    $cal = calendarioDeTeste($db);
    evento($db, $cal, 'Sábado que repõe', ['2026-03-07'],
        ['categoria_id' => categoriaId($db, 'Reposição de horário'), 'repoe_dow' => 1]);
    return Engine::paraCalendario($db, $cal)->dia('2026-03-07')['letivo'];
})(), true);
// Caindo num dia de abertura ou fechamento de período, quem manda no dia é o
// marco: ele diz o que aquele dia é no calendário, e a reposição diz só que
// horário se cumpre nele. Em 50 as duas empatavam, e o motor pinta com `>`
// estrito — a cor saía da ordem de leitura, a favor da reposição.
confere('perde para o marco de início e fim de bimestre', (function () use ($db) {
    $marco = $db->prepare('SELECT prioridade FROM categorias WHERE nome = ?');
    $marco->execute([CAT_SEMESTRE]);
    $rep = $db->prepare('SELECT prioridade FROM categorias WHERE nome = ?');
    $rep->execute(['Reposição de horário']);
    return (int) $rep->fetchColumn() < (int) $marco->fetchColumn();
})(), true);
confere('e o dia de abertura sai com a cor do marco', (function () use ($db) {
    $cal = calendarioBimestral($db, 'anual', BIMESTRES_TESTE, 'CURSO MARCO');
    // 02/02 é o início do 1º bimestre, onde o motor escreve o marco
    evento($db, $cal, 'Segunda com horário de quarta', ['2026-02-02'],
        ['categoria_id' => categoriaId($db, 'Reposição de horário'), 'repoe_dow' => 3]);
    return Engine::paraCalendario($db, $cal)->dia('2026-02-02')['categoria']['nome'];
})(), CAT_SEMESTRE);

// Ela se cadastra e se edita como qualquer outra da tela de Legenda.
confere('aparece no cadastro de legendas',
    (int) $db->query("SELECT COUNT(*) FROM categorias
                       WHERE protegida = 0 AND nome = 'Reposição de horário'")->fetchColumn(), 1);

grupo('Reposição conta sempre como dia letivo');
// O par do grupo abaixo, na direção contrária: lá é fim de semana letivo sem
// reposição; aqui é reposição sem letivo. Um dia que cumpre o horário de outro é
// dia de aula — é para isso que ele existe.
$db  = bancoLimpo();
$cal = calendarioDeTeste($db);
$naoLetiva = categoriaId($db, 'Recesso');       // letivo = 0
$neutra    = categoriaId($db, 'Exame Final');   // letivo = NULL

// "Herdar da categoria" não basta: categoria nenhuma de fábrica obriga o dia a
// contar, e a herança devolve a decisão à regra do dia da semana — que num
// sábado responde "não", e o dia repunha sem contar.
confere('herdar da categoria não obriga o dia a contar',
    forcaDiaLetivo($db, null, $neutra), false);
confere('nem herdando de uma categoria não letiva',
    forcaDiaLetivo($db, null, $naoLetiva), false);
// A categoria pode ser não letiva à vontade: o conta_letivo do evento passa na
// frente dela, e é a mesma precedência que o motor aplica.
confere('mas o conta_letivo do evento passa na frente dela',
    forcaDiaLetivo($db, 1, $naoLetiva), true);
confere('e o motor concorda: o sábado conta mesmo com categoria de Recesso', (function () use ($db, $cal, $naoLetiva) {
    evento($db, $cal, 'Sábado que repõe', ['2026-03-07'],
        ['categoria_id' => $naoLetiva, 'conta_letivo' => 1, 'repoe_dow' => 1]);
    return Engine::paraCalendario($db, $cal)->dia('2026-03-07')['letivo'];
})(), true);

grupo('Fim de semana letivo tem de dizer que horário repõe');
$db  = bancoLimpo();
$cal = calendarioDeTeste($db);
// 07/03/2026 é sábado; 08/03 é domingo; 09/03 é segunda.
$faixa = static fn (string $i, string $f = ''): array => [['inicio' => $i, 'fim' => $f ?: $i]];

confere('sábado é achado na faixa',      fimDeSemanaEm($faixa('2026-03-07')), ['2026-03-07']);
confere('domingo também',                fimDeSemanaEm($faixa('2026-03-08')), ['2026-03-08']);
confere('segunda não',                   fimDeSemanaEm($faixa('2026-03-09')), []);
confere('a faixa é percorrida dia a dia', fimDeSemanaEm($faixa('2026-03-02', '2026-03-15')),
    ['2026-03-07', '2026-03-08', '2026-03-14', '2026-03-15']);
confere('faixas separadas se juntam em ordem',
    fimDeSemanaEm([['inicio' => '2026-03-14', 'fim' => '2026-03-14'],
                   ['inicio' => '2026-03-07', 'fim' => '2026-03-07']]),
    ['2026-03-07', '2026-03-14']);

// Quem obriga o dia a contar: o conta_letivo do evento e, sendo ele neutro, o
// letivo da categoria. É a mesma regra que o motor aplica.
$neutra  = categoriaId($db, 'Exame Final');                  // letivo NULL
$naoLetiva = categoriaId($db, 'Recesso');                    // letivo 0
// bancoLimpo() não mexe em `categorias` — ali fica o estado de instalação nova.
// Então esta sai no fim do grupo: deixada para trás, ela entraria na legenda de
// todos os grupos seguintes e mudaria a posição das linhas no papel.
$db->exec("INSERT INTO categorias (nome, cor, letivo) VALUES ('TESTE letiva', '#123456', 1)");
$letiva = categoriaId($db, 'TESTE letiva');

confere('conta_letivo = 1 obriga',           forcaDiaLetivo($db, 1, null), true);
confere('conta_letivo = 0 não',              forcaDiaLetivo($db, 0, null), false);
confere('e vence a categoria letiva',        forcaDiaLetivo($db, 0, $letiva), false);
confere('neutro sem categoria não obriga',   forcaDiaLetivo($db, null, null), false);
confere('neutro com categoria letiva, sim',  forcaDiaLetivo($db, null, $letiva), true);
confere('neutro com categoria neutra, não',  forcaDiaLetivo($db, null, $neutra), false);
confere('neutro com categoria não letiva, não', forcaDiaLetivo($db, null, $naoLetiva), false);
confere('categoria que não existe não obriga', forcaDiaLetivo($db, null, 99999), false);

// O motor tem de concordar: um sábado com categoria letiva conta mesmo.
evento($db, $cal, 'Sábado por categoria', ['2026-03-07'],
    ['categoria_id' => $letiva, 'repoe_dow' => 1]);
confere('o motor conta o sábado que a categoria tornou letivo',
    Engine::paraCalendario($db, $cal)->dia('2026-03-07')['letivo'], true);
$db->exec("DELETE FROM categorias WHERE nome = 'TESTE letiva'");
confere('e a categoria de teste não fica no banco',
    (int) $db->query("SELECT COUNT(*) FROM categorias WHERE nome LIKE 'TESTE %'")->fetchColumn(), 0);

grupo('Leitura das datas digitadas');
confere('ISO',                 parseFaixas('2026-03-07', ANO), [['inicio' => '2026-03-07', 'fim' => '2026-03-07']]);
confere('dd/mm com "a"',       parseFaixas('14/03 a 16/03', ANO), [['inicio' => '2026-03-14', 'fim' => '2026-03-16']]);
confere('só o dia herda o mês', parseFaixas('14/03 a 16', ANO), [['inicio' => '2026-03-14', 'fim' => '2026-03-16']]);
confere('fim antes do início vira faixa ao contrário',
    parseFaixas('16/03 a 14/03', ANO), [['inicio' => '2026-03-14', 'fim' => '2026-03-16']]);
confere('uma faixa por linha',  parseFaixas("07/03\n14/03 a 16/03", ANO), [
    ['inicio' => '2026-03-07', 'fim' => '2026-03-07'],
    ['inicio' => '2026-03-14', 'fim' => '2026-03-16'],
]);
confere('lixo é descartado',    parseFaixas('não é data', ANO), []);

grupo('Níveis gravados no evento');
$db = bancoLimpo();
confere('todos marcados vale como "sem restrição"', niveisParaBanco(['superior', 'integrado', 'concomitante', 'subsequente']), null);
confere('nenhum marcado também',                    niveisParaBanco([]), null);
confere('um subconjunto fica gravado',              niveisParaBanco(['superior', 'integrado']), 'superior,integrado');
confere('nível inventado é ignorado',               niveisParaBanco(['superior', 'inexistente']), 'superior');
confere('chave nasce do nome, sem acento',          chaveNivel('Técnico Integrado'), 'tecnico_integrado');

/**
 * Curso + calendário + os quatro bimestres. Devolve o id do calendário.
 *
 * O regime vai também no calendário, e não só no curso: desde que o
 * calendário passou a poder nascer sem curso (vínculo por nível), é o
 * `calendarios.regime` que o motor lê — gravá-lo só no curso deixaria o
 * regime do teste sempre em 'semestral', o padrão da coluna.
 */
function calendarioBimestral(PDO $db, string $regime, array $bimestres, string $curso = 'CURSO BIM'): int
{
    $db->prepare('INSERT INTO cursos (nome, nivel, regime, ativo) VALUES (?,?,?,1)')
       ->execute([$curso, 'superior', $regime]);
    $db->prepare('INSERT INTO calendarios (curso_id, ano, regime) VALUES (?,?,?)')
       ->execute([(int) $db->lastInsertId(), ANO, $regime]);
    $calId = (int) $db->lastInsertId();
    salvarPeriodos($db, $calId, $bimestres);
    return $calId;
}

/** Calendário vinculado a um nível inteiro, sem curso nenhum por trás. */
function calendarioPorNivel(PDO $db, string $nivelChave, string $regime, array $bimestres): int
{
    $db->prepare('INSERT INTO calendarios (nivel, ano, regime) VALUES (?,?,?)')
       ->execute([$nivelChave, ANO, $regime]);
    $calId = (int) $db->lastInsertId();
    salvarPeriodos($db, $calId, $bimestres);
    return $calId;
}

grupo('Calendário vinculado a um nível inteiro, sem curso por trás');
$db  = bancoLimpo();
$cal = calendarioPorNivel($db, 'integrado', 'anual', BIMESTRES_TESTE);
evento($db, null, 'Só para o integrado',    ['2026-03-12'], ['nivel' => 'integrado']);
evento($db, null, 'Só para o subsequente',  ['2026-03-13'], ['nivel' => 'subsequente']);

$eng = Engine::paraCalendario($db, $cal);
confere('o nome mostrado é o do nível',       $eng->cal['curso_nome'], 'Técnico Integrado');
confere('o nível gravado é a chave do nível', $eng->cal['curso_nivel'], 'integrado');
confere('o regime é o gravado no calendário, não um padrão', $eng->cal['curso_regime'], 'anual');
confere('o motor calcula os dias letivos normalmente', $eng->contagemSemestre(1)['total'] > 0, true);

$descricoes = array_values(array_unique(array_column(
    array_filter($eng->eventos(), static fn ($ev) => !isset($ev['feriado_id'])), 'descricao'
)));
confere(
    'o calendário do nível vê o evento do próprio nível e não o de outro',
    in_array('Só para o integrado', $descricoes, true) && !in_array('Só para o subsequente', $descricoes, true),
    true
);

grupo('Um calendário não pode ter os dois vínculos, nem nenhum');
$db = bancoLimpo();
$db->prepare('INSERT INTO cursos (nome, nivel, ativo) VALUES (?,?,1)')->execute(['CURSO X', 'superior']);
$curso = (int) $db->lastInsertId();
$falhouComOsDois = false;
try {
    $db->prepare('INSERT INTO calendarios (curso_id, nivel, ano) VALUES (?,?,?)')->execute([$curso, 'integrado', ANO]);
} catch (PDOException $ex) {
    $falhouComOsDois = true;
}
confere('curso_id e nivel juntos violam o CHECK', $falhouComOsDois, true);

$falhouSemNenhum = false;
try {
    $db->prepare('INSERT INTO calendarios (curso_id, nivel, ano) VALUES (NULL, NULL, ?)')->execute([ANO]);
} catch (PDOException $ex) {
    $falhouSemNenhum = true;
}
confere('nem curso_id nem nivel também viola o CHECK', $falhouSemNenhum, true);

grupo('Dois calendários do mesmo nível no mesmo ano não podem coexistir');
$db = bancoLimpo();
calendarioPorNivel($db, 'integrado', 'anual', BIMESTRES_TESTE);
$duplicouNivel = false;
try {
    $db->prepare('INSERT INTO calendarios (nivel, ano) VALUES (?,?)')->execute(['integrado', ANO]);
} catch (PDOException $ex) {
    $duplicouNivel = true;
}
confere('o mesmo nível e ano batem no índice único', $duplicouNivel, true);

grupo('Um nível em uso por um calendário aparece na contagem que bloqueia a exclusão');
$db = bancoLimpo();
calendarioPorNivel($db, 'integrado', 'anual', BIMESTRES_TESTE);
// A mesma consulta que usoDoNivel(), de niveis.php, faz para decidir se
// bloqueia a exclusão — replicada aqui porque aquele arquivo é uma tela
// inteira, não uma biblioteca, e não se inclui num teste sem executá-la.
$st = $db->prepare('SELECT COUNT(*) FROM calendarios WHERE nivel = ?');
$st->execute(['integrado']);
confere('o calendário vinculado ao nível entra na contagem de uso', (int) $st->fetchColumn(), 1);
// Sem isto, o cursor de uma consulta de uma linha só fica "ativo" até o fim do
// arquivo — nada mais reatribui $st depois daqui —, e trava de verdade o
// DROP TABLE de mais adiante, na suíte de migrações.
$st->closeCursor();

/** As datas com os textos que o motor escreveu nelas, na ordem do ano. */
function marcosDe(Engine $e): array
{
    $out = [];
    foreach ($e->eventos() as $ev) {
        if (!empty($ev['auto'])) {
            $out[$ev['datas'][0]['inicio']] = $ev['descricao'];
        }
    }
    ksort($out);
    return $out;
}

const BIMESTRES = [
    1 => ['2026-02-02', '2026-04-17'],
    2 => ['2026-04-20', '2026-06-30'],
    3 => ['2026-08-03', '2026-10-09'],
    4 => ['2026-10-13', '2026-12-18'],
];

grupo('Os semestres saem dos bimestres');
$db  = bancoLimpo();
$cal = calendarioBimestral($db, 'semestral', BIMESTRES);
$eng = Engine::paraCalendario($db, $cal);
confere('o 1º semestre vai do início do 1º bimestre ao fim do 2º',
    [$eng->semestres()[1]['inicio'], $eng->semestres()[1]['fim']], ['2026-02-02', '2026-06-30']);
confere('o 2º, do início do 3º ao fim do 4º',
    [$eng->semestres()[2]['inicio'], $eng->semestres()[2]['fim']], ['2026-08-03', '2026-12-18']);
confere('o intervalo entre o 2º e o 3º bimestre é recesso e não conta',
    $eng->dia('2026-07-15')['letivo'], false);
confere('e os quatro bimestres ficam gravados', array_keys($eng->bimestres()), [1, 2, 3, 4]);

grupo('Os marcos que o sistema escreve nos dias de bimestre');
confere('curso semestral: 1º e 2º bimestre em cada semestre', marcosDe($eng), [
    '2026-02-02' => 'Início do 1º semestre e 1º bimestre letivo de 2026/1',
    '2026-04-17' => 'Fim do 1º Bimestre',
    '2026-04-20' => 'Início do 2º Bimestre',
    '2026-06-30' => 'Fim do 2º Bimestre e Fim do 1º Semestre letivo 2026/1',
    '2026-08-03' => 'Início do 2º semestre e 1º bimestre letivo de 2026/2',
    '2026-10-09' => 'Fim do 1º Bimestre',
    '2026-10-13' => 'Início do 2º Bimestre',
    '2026-12-18' => 'Fim do 2º Bimestre e Fim do 2º Semestre letivo 2026/2',
]);

$db  = bancoLimpo();
$cal = calendarioBimestral($db, 'anual', BIMESTRES);
$eng = Engine::paraCalendario($db, $cal);
confere('curso anual: os bimestres correm de 1 a 4 no ano', marcosDe($eng), [
    '2026-02-02' => 'Início do 1º semestre e 1º bimestre letivo de 2026/1',
    '2026-04-17' => 'Fim do 1º Bimestre',
    '2026-04-20' => 'Início do 2º Bimestre',
    '2026-06-30' => 'Fim do 2º Bimestre e Fim do 1º Semestre letivo 2026/1',
    '2026-08-03' => 'Início do 2º semestre e 3º bimestre letivo de 2026/2',
    '2026-10-09' => 'Fim do 3º Bimestre',
    '2026-10-13' => 'Início do 4º Bimestre',
    '2026-12-18' => 'Fim do 4º Bimestre e Fim do 2º Semestre letivo 2026/2',
]);

confere('todos saem em negrito', array_values(array_unique(array_map(
    static fn ($ev) => (int) $ev['negrito'],
    array_filter($eng->eventos(), static fn ($ev) => !empty($ev['auto']))
))), [1]);
confere('e todos com a legenda de início/fim de período', array_values(array_unique(array_map(
    static fn ($ev) => $eng->categorias()[(int) $ev['categoria_id']]['nome'],
    array_filter($eng->eventos(), static fn ($ev) => !empty($ev['auto']))
))), [CAT_SEMESTRE]);

// Os marcos são neutros: o primeiro e o último dia de aula continuam contando
// pela regra do dia da semana, e nenhum deles tira ou põe dia letivo.
$semMarcos = (function () {
    $db  = bancoLimpo();
    $cal = calendarioBimestral($db, 'anual', BIMESTRES);
    salvarSemestres($db, $cal, semestresDosBimestres(BIMESTRES));
    $db->exec("DELETE FROM periodos WHERE tipo = 'bimestre'");
    $e = Engine::paraCalendario($db, $cal);
    return [$e->contagemSemestre(1)['total'], $e->contagemSemestre(2)['total']];
})();
confere('e não mexem na contagem de dias letivos',
    [$eng->contagemSemestre(1)['total'], $eng->contagemSemestre(2)['total']], $semMarcos);

// Um feriado em cima do primeiro dia de aula: quem pinta é o feriado, que tem
// prioridade 99 contra os 50 do marco.
confere('um feriado no dia do marco vence a cor', (function () {
    $db  = bancoLimpo();
    $cal = calendarioBimestral($db, 'anual', [
        1 => ['2026-04-21', '2026-05-30'],   // 21/4 é Tiradentes
        2 => ['2026-06-01', '2026-06-30'],
        3 => ['2026-08-03', '2026-10-09'],
        4 => ['2026-10-13', '2026-12-18'],
    ]);
    return Engine::paraCalendario($db, $cal)->dia('2026-04-21')['categoria']['nome'];
})(), 'Feriado Nacional');

grupo('A legenda que sai no papel');
$db  = bancoLimpo();
$cal = calendarioBimestral($db, 'semestral', BIMESTRES);
$leg = legendaDoCalendario(Engine::paraCalendario($db, $cal)->categorias());
// O dia letivo comum não é categoria: é a cor fixa da grade, de Configurações.
// Sem esta linha a legenda explicava todas as cores menos a mais frequente.
confere('o dia letivo comum entra na legenda, e por último',
    array_key_last($leg), 'dia_letivo');
confere('com a cor de Configurações', $leg['dia_letivo']['cor'], cfg('cor_dia_util'));
// Nacional, estadual e municipal saem do seed na mesma cor: no papel viram uma
// linha só. Ponto Facultativo tem cor própria e continua à parte.
confere('as quatro de feriado na mesma cor viram uma linha "Feriado"',
    array_values(array_filter(array_column($leg, 'nome'),
        static fn ($n) => str_contains($n, 'Feriado') || $n === 'Ponto Facultativo')),
    ['Feriado', 'Ponto Facultativo']);
// O Ponto Facultativo fica de fora da junção mesmo pintado do mesmo vermelho:
// ele não é feriado — é dia de expediente dispensável, não suprimido.
confere('o Ponto Facultativo não entra na junção nem com a cor dos feriados',
    (function () use ($db, $cal) {
        $db->exec("UPDATE categorias SET cor = '#ff0000' WHERE nome = 'Ponto Facultativo'");
        $l = legendaDoCalendario(Engine::paraCalendario($db, $cal)->categorias());
        $db->exec("UPDATE categorias SET cor = '#00b050' WHERE nome = 'Ponto Facultativo'");
        return array_values(array_filter(array_column($l, 'nome'),
            static fn ($n) => str_contains($n, 'Feriado') || $n === 'Ponto Facultativo'));
    })(), ['Feriado', 'Ponto Facultativo']);
// A posição vem de onde estava a primeira das quatro, e não de um número fixo:
// escrito à mão, este teste quebrava a cada legenda nova acrescentada ao seed,
// e o que ele guarda é a regra, não a contagem de linhas de hoje.
confere('e ela fica na posição da primeira delas', (function () use ($db, $cal) {
    $posicaoDe = static fn (string $nome) => array_search($nome, array_column(
        legendaDoCalendario(Engine::paraCalendario($db, $cal)->categorias()), 'nome'), true);

    $juntas = $posicaoDe('Feriado');
    $db->exec("UPDATE categorias SET cor = '#cc0000' WHERE nome = 'Feriado Escolar'");
    $separadas = $posicaoDe('Feriado Nacional');
    $db->exec("UPDATE categorias SET cor = '#ff0000' WHERE nome = 'Feriado Escolar'");

    return $juntas !== false && $juntas === $separadas;
})(), true);
// Dar cor própria a uma delas desfaz a junção: aí a distinção diz algo no papel.
// Tudo ou nada: quem diferenciou uma quis ver a diferença, e aí as quatro
// voltam separadas — não duas juntas e duas soltas.
confere('cor diferente em uma traz as quatro de volta', (function () use ($db, $cal) {
    $db->exec("UPDATE categorias SET cor = '#cc0000' WHERE nome = 'Feriado Municipal'");
    $l = legendaDoCalendario(Engine::paraCalendario($db, $cal)->categorias());
    $db->exec("UPDATE categorias SET cor = '#ff0000' WHERE nome = 'Feriado Municipal'");
    return array_values(array_filter(array_column($l, 'nome'),
        static fn ($n) => str_starts_with($n, 'Feriado')));
})(), ['Feriado Nacional', 'Feriado Estadual', 'Feriado Municipal', 'Feriado Escolar']);
confere('e vale para o escolar também', (function () use ($db, $cal) {
    $db->exec("UPDATE categorias SET cor = '#f4b183' WHERE nome = 'Feriado Escolar'");
    $l = legendaDoCalendario(Engine::paraCalendario($db, $cal)->categorias());
    $db->exec("UPDATE categorias SET cor = '#ff0000' WHERE nome = 'Feriado Escolar'");
    return array_values(array_filter(array_column($l, 'nome'),
        static fn ($n) => str_starts_with($n, 'Feriado')));
})(), ['Feriado Nacional', 'Feriado Estadual', 'Feriado Municipal', 'Feriado Escolar']);
// A origem da norma não se perde: ela continua na lista do mês.
confere('a lista do mês continua dizendo a origem', (function () use ($db, $cal) {
    $e = Engine::paraCalendario($db, $cal);
    foreach ($e->eventos() as $ev) {
        if (($ev['descricao'] ?? '') === 'Natal') {
            return $e->descricaoNaLista($ev);
        }
    }
    return 'não achei o Natal';
})(), 'Natal - Feriado Nacional');
// na_legenda = 0 tira a categoria do papel; a linha do dia letivo não depende
// disso, porque não é categoria.
confere('categoria fora da legenda não aparece', (function () use ($db, $cal) {
    $db->exec("UPDATE categorias SET na_legenda = 0 WHERE nome = 'Férias'");
    $l = legendaDoCalendario(Engine::paraCalendario($db, $cal)->categorias());
    $db->exec("UPDATE categorias SET na_legenda = 1 WHERE nome = 'Férias'");
    return in_array('Férias', array_column($l, 'nome'), true);
})(), false);

grupo('Feriados de fábrica');
$db = bancoLimpo();
$porNome = [];
foreach (feriadosDoAno($db, 2026) as $f) {
    $porNome[$f['nome']] = [$f['data'], $f['categoria_nome']];
}
// Os quatro estaduais do Tocantins entram de fábrica: valem igual em todo
// campus do estado, ao contrário dos municipais, que cada um cadastra.
confere('os estaduais do Tocantins', array_intersect_key($porNome, array_flip([
    'Dia da Autonomia do Estado do Tocantins',
    'Dia do Senhor do Bonfim',
    'Nossa Senhora da Natividade, padroeira do Tocantins',
    'Criação do Estado do Tocantins',
])), [
    'Dia da Autonomia do Estado do Tocantins'             => ['2026-03-18', 'Feriado Estadual'],
    'Dia do Senhor do Bonfim'                             => ['2026-08-15', 'Feriado Estadual'],
    'Nossa Senhora da Natividade, padroeira do Tocantins' => ['2026-09-08', 'Feriado Estadual'],
    'Criação do Estado do Tocantins'                      => ['2026-10-05', 'Feriado Estadual'],
]);

grupo('Modelo do título');
$db = bancoLimpo();
$cal = calendarioDeTeste($db, 'AGRICULTURA', 'integrado');
$eng = Engine::paraCalendario($db, $cal);
confere('o modelo de fábrica, com {nivel} em maiúsculas',
    strtr(cfgPadroes()['titulo_modelo'], Engine::trocasDoTitulo('AGRICULTURA', 'integrado', ANO)),
    'CALENDÁRIO DO CURSO TÉCNICO INTEGRADO EM AGRICULTURA / 2026');
confere('nível que não existe mais não quebra o título',
    strtr('CURSO {nivel} EM {curso}', Engine::trocasDoTitulo('X', 'inexistente', ANO)),
    'CURSO  EM X');
confere('o modelo antigo, sem {nivel}, continua valendo',
    strtr('CALENDÁRIO DO CURSO {curso} {ano}', Engine::trocasDoTitulo('AGRICULTURA', 'integrado', ANO)),
    'CALENDÁRIO DO CURSO AGRICULTURA 2026');
confere('um calendário por curso usa o modelo de curso',
    $eng->titulo(),
    strtr(cfgPadroes()['titulo_modelo'], Engine::trocasDoTitulo('AGRICULTURA', 'integrado', ANO)));

$calNivel = calendarioPorNivel($db, 'integrado', 'anual', BIMESTRES_TESTE);
$engNivel = Engine::paraCalendario($db, $calNivel);
confere('um calendário por nível usa o modelo de nível, não o de curso',
    $engNivel->titulo(),
    strtr(cfgPadroes()['titulo_modelo_nivel'], Engine::trocasDoTitulo('Técnico Integrado', 'integrado', ANO)));

grupo('Contagem por bimestre');
$db  = bancoLimpo();
$cal = calendarioBimestral($db, 'anual', BIMESTRES);
$eng = Engine::paraCalendario($db, $cal);
// A soma dos dois bimestres de um semestre tem de fechar com o total dele: os
// bimestres não se sobrepõem e cobrem o semestre inteiro.
confere('1º + 2º bimestre fecham o 1º semestre',
    $eng->contagemBimestre(1)['total'] + $eng->contagemBimestre(2)['total'],
    $eng->contagemSemestre(1)['total']);
confere('3º + 4º fecham o 2º semestre',
    $eng->contagemBimestre(3)['total'] + $eng->contagemBimestre(4)['total'],
    $eng->contagemSemestre(2)['total']);
confere('e por dia da semana também', array_map(
    static fn (int $dw): int => $eng->contagemBimestre(1)['por_dow'][$dw] + $eng->contagemBimestre(2)['por_dow'][$dw],
    range(1, 6)
), array_values($eng->contagemSemestre(1)['por_dow']));

// E a contagem por horário fecha do mesmo jeito: os bimestres continuam sem se
// sobrepor depois de o sábado ser realocado para o dia que ele repõe.
confere('1º + 2º bimestre fecham o 1º semestre também por horário',
    $eng->contagemHorarioBimestre(1)['total'] + $eng->contagemHorarioBimestre(2)['total'],
    $eng->contagemHorarioSemestre(1)['total']);
confere('e por horário de cada dia da semana', array_map(
    static fn (int $dw): int => $eng->contagemHorarioBimestre(1)['por_dow'][$dw]
                              + $eng->contagemHorarioBimestre(2)['por_dow'][$dw],
    range(1, 5)
), array_values($eng->contagemHorarioSemestre(1)['por_dow']));

// O caso real de quem monta o calendário: falta um dia no bimestre, e um sábado
// letivo entra para fechar a conta. O contador do bimestre tem de subir junto
// com o do semestre — é isso que a tela mostra no topo.
confere('um sábado letivo entra na conta do bimestre e na do semestre', (function () use ($db, $cal) {
    $antes = Engine::paraCalendario($db, $cal);
    $b1 = $antes->contagemBimestre(1)['total'];
    $s1 = $antes->contagemSemestre(1)['total'];
    evento($db, $cal, 'Sábado letivo', ['2026-03-07'], ['conta_letivo' => 1, 'repoe_dow' => 1]);
    $dep = Engine::paraCalendario($db, $cal);
    return [$dep->contagemBimestre(1)['total'] - $b1, $dep->contagemSemestre(1)['total'] - $s1];
})(), [1, 1]);
confere('e cai na coluna de sábado do bimestre',
    Engine::paraCalendario($db, $cal)->contagemBimestre(1)['por_dow'][6], 1);
// O 2º bimestre não é tocado por um sábado que caiu no 1º.
confere('sem mexer no bimestre seguinte',
    Engine::paraCalendario($db, $cal)->contagemBimestre(2)['por_dow'][6], 0);

// O resumo da tela soma as linhas de bimestre dentro de cada semestre: o que a
// tabela mostra tem de fechar coluna a coluna, senão ela mente por dia da
// semana mesmo com o total certo.
confere('no resumo, os bimestres fecham o semestre coluna a coluna', (function () use ($db, $cal) {
    $e = Engine::paraCalendario($db, $cal);
    $ok = [];
    foreach ([[1, [1, 2]], [2, [3, 4]]] as [$sem, $bims]) {
        $s = $e->contagemSemestre($sem);
        foreach (range(1, 6) as $dw) {
            $soma = array_sum(array_map(static fn ($b) => $e->contagemBimestre($b)['por_dow'][$dw], $bims));
            $ok["{$sem}-{$dw}"] = $soma === $s['por_dow'][$dw];
        }
    }
    return array_values(array_unique($ok));
})(), [true]);

grupo('As oito datas que chegam do formulário');
// A validação lê o $_POST direto, então o teste monta o $_POST e o desfaz.
$comPost = static function (array $datas, string $regime, int $ano): array {
    $_POST = $datas;
    $r = bimestresDoFormulario($regime, $ano);
    $_POST = [];
    return $r;
};
$oitoDatas = static fn (int $ano): array => [
    'bim1_inicio' => "$ano-02-02", 'bim1_fim' => "$ano-04-10",
    'bim2_inicio' => "$ano-04-13", 'bim2_fim' => "$ano-06-30",
    'bim3_inicio' => "$ano-08-03", 'bim3_fim' => "$ano-10-02",
    'bim4_inicio' => "$ano-10-05", 'bim4_fim' => "$ano-12-18",
];
confere('oito datas do ano certo passam', $comPost($oitoDatas(2026), 'anual', 2026)[1], '');
// Repetir as datas do calendário anterior era o caminho fácil para um ano
// inteiro com zero dia letivo: os períodos existiam, mas fora da grade, e nem o
// aviso de "semestres não informados" aparecia, porque eles estavam lá.
confere('as do ano anterior não',
    $comPost($oitoDatas(2025), 'anual', 2026)[1],
    'No 1º bimestre, a data 02/02/2025 está fora de 2026.');
confere('nem uma só data escapando do ano', (function () use ($comPost, $oitoDatas) {
    $datas = $oitoDatas(2026);
    $datas['bim4_fim'] = '2027-01-15';
    return $comPost($datas, 'anual', 2026)[1];
})(), 'No 4º bimestre, a data 15/01/2027 está fora de 2026.');
confere('e nada é devolvido para gravar quando há erro',
    $comPost($oitoDatas(2025), 'anual', 2026)[0], []);
// O rótulo do erro fala o vocabulário do regime: no semestral o número do
// bimestre se repete, e sem dizer o semestre a mensagem apontaria para dois
// campos ao mesmo tempo.
confere('o erro nomeia o bimestre pelo regime do curso',
    $comPost($oitoDatas(2025), 'semestral', 2026)[1],
    'No 1º bimestre do 1º semestre, a data 02/02/2025 está fora de 2026.');

grupo('Datas que andam de um ano para outro');
confere('dia e mês ficam onde estavam', deslocarAno('2026-03-15', 1), '2027-03-15');
confere('e voltam também', deslocarAno('2026-03-15', -1), '2025-03-15');
// 29 de fevereiro não existe fora do bissexto. O modify('+1 year') do PHP
// mandava o evento para 1º de março — outro mês, outra semana da grade.
confere('29 de fevereiro encosta no 28 fora do bissexto', deslocarAno('2024-02-29', 1), '2025-02-28');
confere('e continua 29 quando o ano de destino é bissexto', deslocarAno('2024-02-29', 4), '2028-02-29');

grupo('O que chega dos formulários');
// O <textarea> manda \r\n. Sem tirar o \r, quem parte por \n fica com ele
// pendurado no fim de cada linha — inclusive no cabeçalho impresso.
confere('o fim de linha do navegador vira só \\n', (function () {
    $_POST['t'] = "uma\r\nduas\r\ntrês";
    $v = post('t');
    $_POST = [];
    return [$v, substr_count($v, "\r")];
})(), ["uma\nduas\ntrês", 0]);

grupo('Entrar no sistema');
$db = bancoLimpo();
$db->exec('DELETE FROM usuarios');
confere('sem ninguém cadastrado, é primeira abertura', semUsuarios($db), true);
$novo = criarUsuario($db, 'José Robson', 'jose', 'senha12345');
confere('e deixa de ser depois do primeiro', semUsuarios($db), false);
// A senha não fica no banco: só o hash, e ele muda a cada gravação por causa do
// sal, então comparar com a senha nunca dá certo — quem confere é o verify.
confere('a senha não é guardada', str_contains((string) $novo['senha_hash'], 'senha12345'), false);
confere('e o hash é o do password_hash', password_verify('senha12345', (string) $novo['senha_hash']), true);

confere('login e senha certos entram',
    autenticar($db, 'jose', 'senha12345')['usuario'] ?? null, 'jose');
confere('senha errada, não', autenticar($db, 'jose', 'senha1234'), null);
confere('usuário que não existe, não', autenticar($db, 'ninguem', 'senha12345'), null);
// Inativo é diferente de excluído: o cadastro fica, o acesso não.
confere('usuário inativo, não', (function () use ($db) {
    $db->exec("UPDATE usuarios SET ativo = 0 WHERE usuario = 'jose'");
    $r = autenticar($db, 'jose', 'senha12345');
    $db->exec("UPDATE usuarios SET ativo = 1 WHERE usuario = 'jose'");
    return $r;
})(), null);
confere('e dois usuários não dividem o mesmo login', (function () use ($db) {
    try {
        criarUsuario($db, 'Outro', 'jose', 'senha12345');
        return true;
    } catch (PDOException $e) {
        return false;
    }
})(), false);

// Não há tamanho mínimo: a senha é a que quem cadastra escolher. O usuário sai
// no fim para as contagens abaixo continuarem falando de quem elas esperam.
confere('uma senha curta serve como qualquer outra', (function () use ($db) {
    criarUsuario($db, 'Curta', 'curta', '1');
    $r = autenticar($db, 'curta', '1')['usuario'] ?? null;
    $db->exec("DELETE FROM usuarios WHERE usuario = 'curta'");
    return $r;
})(), 'curta');

// O hash contra o qual se confere um login que não existe tem de custar o mesmo
// que os hashes que estão mesmo no banco. Escrito à mão ele envelhece: o custo
// padrão do password_hash() subiu de 10 para 12 no PHP 8.4, e um dummy fixo em
// 12 sobre um banco gravado no 8.3 respondia quatro vezes mais devagar para o
// login inexistente — dizendo, pelo relógio, quais logins são válidos.
$custoDe = static fn (string $h): string => substr($h, 0, 7);
confere('o hash de mentira acompanha o custo do banco', (function () use ($db, $custoDe) {
    $db->exec('DELETE FROM usuarios');
    $out = [];
    foreach ([10, 12] as $custo) {
        $db->prepare('INSERT INTO usuarios (nome, usuario, senha_hash) VALUES (?,?,?)')
           ->execute(['A', 'a', password_hash('x', PASSWORD_BCRYPT, ['cost' => $custo])]);
        $out[] = $custoDe(hashDeMentira($db));
        $db->exec('DELETE FROM usuarios');
    }
    return $out;
})(), ['$2y$10$', '$2y$12$']);
// Bem-formado, senão o password_verify recusa de saída e não gasta tempo nenhum
// — que é justamente o que este hash existe para gastar.
confere('e é um bcrypt que o password_verify percorre inteiro', (function () use ($db) {
    $db->prepare('INSERT INTO usuarios (nome, usuario, senha_hash) VALUES (?,?,?)')
       ->execute(['A', 'a', password_hash('x', PASSWORD_BCRYPT, ['cost' => 4])]);
    $h = hashDeMentira($db);
    $db->exec('DELETE FROM usuarios');
    return [strlen($h), password_get_info($h)['algoName'], password_verify('x', $h)];
})(), [60, 'bcrypt', false]);

// O usuário das contagens abaixo volta ao banco: os testes de cima o esvaziaram
// para falar de custo de hash. O segundo é criado pela própria contagem.
$novo = criarUsuario($db, 'José', 'jose', 'senha12345');

// A caixa "Ativo" do formulário vale também na criação. Antes o INSERT omitia a
// coluna e o DEFAULT 1 do schema decidia: desmarcá-la criava um usuário ativo
// assim mesmo, e a caixa mentia sobre o que fazia.
confere('nasce ativo quando não se diz nada', (function () use ($db) {
    $u = criarUsuario($db, 'Padrão', 'padrao', 'senha12345');
    $db->exec("DELETE FROM usuarios WHERE usuario = 'padrao'");
    return (int) $u['ativo'];
})(), 1);
confere('e nasce inativo quando a caixa vem desmarcada', (function () use ($db) {
    $u = criarUsuario($db, 'Desligado', 'desligado', 'senha12345', false);
    $db->exec("DELETE FROM usuarios WHERE usuario = 'desligado'");
    return (int) $u['ativo'];
})(), 0);
// Inativo não entra, mesmo com a senha certa — é o que a caixa promete.
confere('e o inativo não consegue entrar', (function () use ($db) {
    criarUsuario($db, 'Desligado', 'desligado', 'senha12345', false);
    $r = autenticar($db, 'desligado', 'senha12345');
    $db->exec("DELETE FROM usuarios WHERE usuario = 'desligado'");
    return $r;
})(), null);

// Trancar o sistema por fora é o único estrago que esta tela pode fazer: é o
// que a contagem de outros ativos existe para impedir.
confere('com um usuário só, não há outro ativo para segurar a porta',
    outrosUsuariosAtivos($db, (int) $novo['id']), 0);
$outro = criarUsuario($db, 'Maria', 'maria', 'senha12345');
confere('com dois, cada um tem o outro', [
    outrosUsuariosAtivos($db, (int) $novo['id']),
    outrosUsuariosAtivos($db, (int) $outro['id']),
], [1, 1]);
// Inativo não segura porta nenhuma: quem está desativado não consegue entrar.
confere('mas um inativo não conta', (function () use ($db, $novo, $outro) {
    $db->prepare('UPDATE usuarios SET ativo = 0 WHERE id = ?')->execute([(int) $outro['id']]);
    $n = outrosUsuariosAtivos($db, (int) $novo['id']);
    $db->prepare('UPDATE usuarios SET ativo = 1 WHERE id = ?')->execute([(int) $outro['id']]);
    return $n;
})(), 0);

// Senha vazia na edição quer dizer "não mexe": é como se troca o nome de alguém
// sem saber a senha dele.
confere('salvar sem senha mantém a que havia', (function () use ($db, $outro) {
    salvarUsuario($db, (int) $outro['id'], 'Maria Silva', 'maria', '', true);
    return [
        autenticar($db, 'maria', 'senha12345')['nome'] ?? null,
        password_verify('', (string) $db->query("SELECT senha_hash FROM usuarios WHERE usuario='maria'")->fetchColumn()),
    ];
})(), ['Maria Silva', false]);
confere('e com senha, a antiga deixa de valer', (function () use ($db, $outro) {
    salvarUsuario($db, (int) $outro['id'], 'Maria Silva', 'maria', 'outrasenha12', true);
    return [autenticar($db, 'maria', 'senha12345'), autenticar($db, 'maria', 'outrasenha12')['usuario'] ?? null];
})(), [null, 'maria']);

grupo('Migrações do banco');
$db = bancoLimpo();
confere('um banco novo tem a tabela do histórico',
    (int) $db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='migracoes'")->fetchColumn(), 1);
// O schema.sql já descreve o banco depois de todas elas: rodá-las num banco
// recém-criado repetiria o que acabou de ser criado, e a primeira com um ALTER
// falharia. Por isso nascem marcadas.
// Ordenados dos dois lados: o histórico sai em ordem alfabética, o registro em
// ordem de declaração, e o que importa aqui é o conjunto.
confere('e nasce com as declaradas marcadas, sem rodar nenhuma',
    $db->query('SELECT nome FROM migracoes ORDER BY nome')->fetchAll(PDO::FETCH_COLUMN),
    (function () { $n = array_keys(migracoes()); sort($n); return $n; })());

// Daqui para baixo, um registro de mentira: o de verdade fica vazio até a
// primeira mudança de schema depois da 1.0, e o mecanismo tem de estar coberto
// antes disso.
$fingidas = [
    'a_que_passa'  => static fn (PDO $p) => $p->exec("ALTER TABLE cursos ADD COLUMN teste TEXT NOT NULL DEFAULT 'x'"),
    'a_que_falha'  => static fn (PDO $p) => $p->exec('ALTER TABLE nao_existe ADD COLUMN y TEXT'),
];
$db->exec('DROP TABLE migracoes');   // como chega um banco anterior ao histórico

confere('a que falha sobe o erro, com o nome dentro', (function () use ($db, $fingidas) {
    try {
        migrar($db, $fingidas);
        return '(não lançou)';
    } catch (RuntimeException $e) {
        return substr($e->getMessage(), 0, strpos($e->getMessage(), ':'));
    }
})(), "Falha na migração 'a_que_falha'");
confere('só a que passou fica registrada',
    $db->query('SELECT nome FROM migracoes')->fetchAll(PDO::FETCH_COLUMN), ['a_que_passa']);
confere('e o que ela fez está no banco',
    in_array('teste', $db->query('PRAGMA table_info(cursos)')->fetchAll(PDO::FETCH_COLUMN, 1), true), true);
// Roda de novo: a registrada não repete — repetir o ALTER dela daria erro.
confere('rodar de novo não repete a que já passou', (function () use ($db, $fingidas) {
    try { migrar($db, $fingidas); } catch (RuntimeException $e) {}
    return $db->query('SELECT nome FROM migracoes')->fetchAll(PDO::FETCH_COLUMN);
})(), ['a_que_passa']);
$db->exec('ALTER TABLE cursos DROP COLUMN teste');
$db->exec('DELETE FROM migracoes');
marcarMigracoesComoAplicadas($db);

grupo('O que a importação de backup aceita');
// motivoParaRecusar() vive em backup.php, que é uma tela: o teste lê o trecho
// das funções e o avalia, para não disparar a tela inteira.
(function () {
    $src = (string) file_get_contents(__DIR__ . '/../backup.php');
    $ini = strpos($src, 'const TABELAS_ESPERADAS');
    eval(substr($src, $ini, strpos($src, '// ── Exportar') - $ini));
})();
$arquivo = static function (callable $ajusta): string {
    $caminho = sys_get_temp_dir() . '/calendario-import-' . getmypid() . '-' . uniqid() . '.sqlite';
    $antigo = getenv('CALENDARIO_DB');
    putenv('CALENDARIO_DB=' . $caminho);
    $GLOBALS['pdo_teste'] = null;
    (new PDO('sqlite:' . $caminho))->exec('SELECT 1');
    // monta um banco completo com o mesmo schema e seed da aplicação
    $p = new PDO('sqlite:' . $caminho, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $p->exec((string) file_get_contents(__DIR__ . '/../lib/schema.sql'));
    $p->exec("CREATE TABLE IF NOT EXISTS migracoes (nome TEXT PRIMARY KEY, aplicada_em TEXT NOT NULL DEFAULT (datetime('now')))");
    $st = $p->prepare('INSERT INTO migracoes (nome) VALUES (?)');
    foreach (array_keys(migracoes()) as $n) { $st->execute([$n]); }
    $ajusta($p);
    $p = null;
    putenv('CALENDARIO_DB=' . $antigo);
    register_shutdown_function(static fn () => @unlink($caminho));
    return $caminho;
};

confere('um banco desta versão passa',
    motivoParaRecusar($arquivo(static fn (PDO $p) => null)), '');
// Sem a tabela do histórico não dá para saber de que versão o arquivo veio, e
// todo banco criado da 1.0 em diante nasce com ela.
confere('sem a tabela de migrações, não',
    motivoParaRecusar($arquivo(static fn (PDO $p) => $p->exec('DROP TABLE migracoes'))),
    'O arquivo é de uma versão anterior ao histórico de migrações e não pode mais ser restaurado.');
// Nome que este código não conhece = backup de uma versão à frente. Restaurá-lo
// poria um schema mais novo debaixo de uma aplicação mais velha.
confere('com migração desconhecida, também não', str_starts_with(
    motivoParaRecusar($arquivo(static fn (PDO $p) => $p->exec("INSERT INTO migracoes (nome) VALUES ('9999_futuro')"))),
    'O arquivo vem de uma versão mais nova'), true);
confere('e um sqlite de outro sistema, muito menos', str_starts_with(
    motivoParaRecusar($arquivo(static fn (PDO $p) => $p->exec('DROP TABLE eventos'))),
    'O arquivo não é um banco de calendário'), true);

grupo('Negrito dos marcos de bimestre');
$db  = bancoLimpo();
$cal = calendarioBimestral($db, 'anual', BIMESTRES);
/** Os marcos como o motor os monta, não só o texto deles. */
$marcosCrus = static function (PDO $db, int $cal): array {
    $e = Engine::paraCalendario($db, $cal);
    return array_values(array_filter($e->eventos(), static fn ($ev) => !empty($ev['auto'])));
};
confere('de fábrica os quatro marcos saem em negrito',
    array_values(array_unique(array_map(
        static fn ($m) => (int) $m['negrito'],
        $marcosCrus($db, $cal)
    ))), [1]);
// Desmarcar em Configurações tira só o negrito: os oito marcos continuam lá e
// continuam pintando o dia com a cor da categoria.
confere('desmarcado em Configurações, saem com o peso dos outros',
    (function () use ($db, $cal, $marcosCrus) {
        cfgSalvar($db, 'negrito_periodo', '0');
        cfgEsquecer();
        $m = $marcosCrus($db, $cal);
        $out = [
            array_values(array_unique(array_map(static fn ($x) => (int) $x['negrito'], $m))),
            array_values(array_unique(array_map(static fn ($x) => (int) $x['pinta_dias'], $m))),
            count($m),
        ];
        cfgSalvar($db, 'negrito_periodo', '1');
        cfgEsquecer();
        return $out;
    })(), [[0], [1], 8]);

grupo('Níveis de ensino em ordem alfabética');
$db = bancoLimpo();
// A posição era digitada em cada nível; agora sai do nome. Os quatro de fábrica
// caem na mesma ordem de antes, que é o que já se esperava ver.
confere('os quatro de fábrica saem em ordem',
    array_values(niveisCurso()),
    ['Superior', 'Técnico Concomitante', 'Técnico Integrado', 'Técnico Subsequente']);
confere('a coluna ordem não existe mais',
    in_array('ordem', $db->query('PRAGMA table_info(niveis)')->fetchAll(PDO::FETCH_COLUMN, 1), true),
    false);
// O ORDER BY do SQLite compara byte a byte, e em UTF-8 o "ó" começa num byte
// maior que qualquer letra sem acento: "Pós" cairia depois de "Pré", e os dois
// depois de "Superior".
confere('o acento não joga o nome para o fim da lista', (function () {
    $nomes = ['Superior', 'Pré-vestibular', 'Pós-graduação', 'Ensino Médio'];
    usort($nomes, 'compararNomes');
    return $nomes;
})(), ['Ensino Médio', 'Pós-graduação', 'Pré-vestibular', 'Superior']);
confere('e maiúscula não separa nomes iguais', compararNomes('técnico', 'TÉCNICO'), 0);

grupo('Regime das disciplinas do curso');
$db = bancoLimpo();
confere('só existem os dois regimes', array_keys(regimesCurso()), ['semestral', 'anual']);
calendarioDeTeste($db);
confere('curso cadastrado sem dizer nada nasce semestral',
    $db->query('SELECT regime FROM cursos')->fetchColumn(), 'semestral');
confere('e o anual é gravado como veio', (function () use ($db) {
    $db->exec("INSERT INTO cursos (nome, nivel, regime) VALUES ('CURSO ANUAL', 'integrado', 'anual')");
    $st = $db->prepare('SELECT regime FROM cursos WHERE nome = ?');
    $st->execute(['CURSO ANUAL']);
    return $st->fetchColumn();
})(), 'anual');
// O CHECK da coluna é a última barreira. A tela de Cursos peneira antes, mas um
// valor fora dos dois não pode entrar no banco por caminho nenhum.
confere('o banco recusa um regime inventado', (function () use ($db) {
    try {
        $db->exec("INSERT INTO cursos (nome, nivel, regime) VALUES ('X', 'superior', 'trimestral')");
        return true;
    } catch (PDOException $e) {
        return false;
    }
})(), false);

grupo('As categorias de feriado, agora fora da tela de Legenda');
$db = bancoLimpo();
confere('os cinco tipos saem resolvidos pelo nome, na ordem de alcance',
    array_keys(categoriasDeFeriado($db)),
    ['Feriado Nacional', 'Feriado Estadual', 'Feriado Municipal', 'Ponto Facultativo', 'Feriado Escolar']);
confere('e as cinco são protegidas', array_values(array_unique(
    array_map(static fn ($c) => (int) $c['protegida'], categoriasDeFeriado($db))
)), [1]);
// Elas saíram da tela de Legenda, mas não do papel: é `na_legenda` que põe cada
// uma na legenda do calendário impresso, e some daqui é sumir do documento
// homologado sem ninguém notar.
confere('e as cinco continuam na legenda impressa', array_values(array_unique(
    array_map(static fn ($c) => (int) $c['na_legenda'], categoriasDeFeriado($db))
)), [1]);
// A tela de Legenda lista só o que se cadastra — o mesmo filtro que ela usa.
confere('e nenhuma aparece no cadastro de legendas',
    (int) $db->query('SELECT COUNT(*) FROM categorias WHERE protegida = 0 AND nome IN
        ("Feriado Nacional","Feriado Estadual","Feriado Municipal","Ponto Facultativo",
         "Feriado Escolar")')->fetchColumn(), 0);
// Nenhuma das quatro se escolhe num evento: quem as aplica é o cadastro de
// feriados, e as emendas do ano entram lá como Ponto Facultativo.
confere('nenhuma delas é escolhível num evento', array_keys(array_filter(
    categoriasDeFeriado($db), static fn ($c) => (int) $c['oculta'] === 0
)), []);

// O Feriado Escolar é o tipo da instituição — o Dia do Professor é o caso —, e
// não da norma civil. Entrou depois dos outros quatro e por isso ficou com a
// prioridade logo abaixo deles.
confere('o Feriado Escolar é um tipo cadastrável de feriado',
    in_array('Feriado Escolar', nomesDeFeriado(), true), true);
confere('com prioridade abaixo dos civis e do ponto facultativo', array_map(
    static fn (array $c): int => (int) $c['prioridade'], categoriasDeFeriado($db)
), ['Feriado Nacional' => 99, 'Feriado Estadual' => 98, 'Feriado Municipal' => 97,
    'Ponto Facultativo' => 96, 'Feriado Escolar' => 95]);
confere('não conta como dia letivo, como todo feriado',
    (int) categoriasDeFeriado($db)['Feriado Escolar']['letivo'], 0);
// O teto das editáveis desceu de 95 para 94 justamente para não empatar com ele:
// no empate a cor do dia sairia da ordem de leitura, não de uma regra.
confere('e nenhuma categoria editável alcança a prioridade dele',
    PRIORIDADE_MAX < (int) categoriasDeFeriado($db)['Feriado Escolar']['prioridade'], true);
confere('o seed não deixa nenhuma editável acima do teto',
    (int) $db->query('SELECT COUNT(*) FROM categorias WHERE protegida = 0 AND prioridade > '
        . PRIORIDADE_MAX)->fetchColumn(), 0);

// Um feriado escolar pintando o dia: é o motor que aplica a cor a partir do
// cadastro, como faz com os outros tipos.
confere('o motor pinta o dia com a cor do tipo escolhido', (function () use ($db) {
    $cat = categoriaId($db, 'Feriado Escolar');
    $db->prepare('INSERT INTO feriados (nome, tipo, dia, mes, categoria_id) VALUES (?,?,?,?,?)')
       ->execute(['TESTE Dia do Professor', 'fixo', 15, 10, $cat]);
    $cal = calendarioDeTeste($db, 'CURSO ESCOLAR');
    $d   = Engine::paraCalendario($db, $cal)->dia('2026-10-15');
    $db->exec("DELETE FROM feriados WHERE nome LIKE 'TESTE %'");
    return [$d['categoria']['nome'], $d['letivo']];
})(), ['Feriado Escolar', false]);
// Regressão: o tipo era achado por LIKE 'Feriado%', então renomear a categoria
// na tela de Legenda sumia com ele da lista e o feriado era reatribuído em
// silêncio ao primeiro tipo restante. Agora a tela de Legenda não renomeia, e
// aqui fica registrado que a busca é por nome exato.
confere('um nome parecido não entra na lista de tipos', (function () use ($db) {
    $db->exec("INSERT INTO categorias (nome, cor, cor_texto, letivo, prioridade, na_legenda, ordem)
               VALUES ('Feriado da Padroeira', '#ff0000', '#000000', 0, 40, 1, 30)");
    $tipos = array_keys(categoriasDeFeriado($db));
    $db->exec("DELETE FROM categorias WHERE nome = 'Feriado da Padroeira'");
    return in_array('Feriado da Padroeira', $tipos, true);
})(), false);

grupo('Cor do texto sobre a cor escolhida para o feriado');
// A cor do texto não se escolhe em lugar nenhum: só o fundo vai para
// Configurações, e o número dentro do quadrado precisa continuar legível.
confere('vermelho de feriado pede texto preto',  corDeTexto('#ff0000'), '#000000');
confere('verde de ponto facultativo, idem',      corDeTexto('#00b050'), '#000000');
confere('azul escuro pede texto branco',         corDeTexto('#1155cc'), '#ffffff');
confere('preto pede texto branco',               corDeTexto('#000000'), '#ffffff');
confere('branco pede texto preto',               corDeTexto('#ffffff'), '#000000');

grupo('Cor que chega pelo formulário');
confere('#rrggbb passa, em minúsculas', (function () { $_POST['c'] = '#FF00AA'; return postCor('c', '#000000'); })(), '#ff00aa');
confere('cor com aspas cai no padrão',  (function () { $_POST['c'] = '#fff" onmouseover="alert(1)'; return postCor('c', '#000000'); })(), '#000000');
confere('nome de cor cai no padrão',    (function () { $_POST['c'] = 'red'; return postCor('c', '#ffffff'); })(), '#ffffff');
confere('três dígitos não bastam',      (function () { $_POST['c'] = '#fff'; return postCor('c', '#ffffff'); })(), '#ffffff');

// ══════════════════════════════════════════════════════════════════ resumo

$falhou = $GLOBALS['falhou'];
echo "\n" . str_repeat('─', 60) . "\n";
if ($falhou === []) {
    echo "\033[32m{$GLOBALS['passou']} testes, todos passaram.\033[0m\n";
    exit(0);
}
echo "\033[31m" . count($falhou) . ' de ' . ($GLOBALS['passou'] + count($falhou)) . " testes falharam:\033[0m\n";
foreach ($falhou as $t) {
    echo "  · $t\n";
}
exit(1);
