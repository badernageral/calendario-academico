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
evento($db, $cal, 'Culminância', ['2026-03-10'], ['categoria_id' => categoriaId($db, 'Período de culminância de Projetos Pedagógicos')]);  // prioridade 45
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

confere('feriado inativo sai de circulação',   (function () use ($db) {
    $db->exec("UPDATE feriados SET ativo = 0 WHERE nome = 'Tiradentes'");
    $nomes = array_column(feriadosDoAno($db, 2026), 'nome');
    $db->exec("UPDATE feriados SET ativo = 1 WHERE nome = 'Tiradentes'");
    return in_array('Tiradentes', $nomes, true);
})(), false);

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
