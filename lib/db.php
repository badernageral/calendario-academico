<?php
declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';

// No Apache o banco fica em data/, dentro do site. No aplicativo desktop ele
// precisa ficar fora da pasta de instalação, para sobreviver a uma atualização
// — daí o caminho poder vir do ambiente.
define('DB_PATH', getenv('CALENDARIO_DB') ?: APP_ROOT . '/data/calendario.sqlite');

/** Teto da prioridade que o formulário de legenda aceita; acima ficam os feriados. */
const PRIORIDADE_MAX = 95;

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $novo = !file_exists(DB_PATH);
    if (!is_dir(dirname(DB_PATH))) {
        mkdir(dirname(DB_PATH), 0775, true);
    }

    $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');

    if ($novo) {
        $pdo->exec((string) file_get_contents(__DIR__ . '/schema.sql'));
        seed($pdo);
    } else {
        migrar($pdo);
    }

    return $pdo;
}

/**
 * Ajustes de bases criadas por versões anteriores. Cada bloco checa antes de
 * mexer, então rodar de novo não faz nada.
 */
function migrar(PDO $pdo): void
{
    // Os níveis de ensino eram uma lista fixa no código; agora têm tela própria.
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS niveis (
             id    INTEGER PRIMARY KEY AUTOINCREMENT,
             chave TEXT NOT NULL UNIQUE,
             nome  TEXT NOT NULL,
             ordem INTEGER NOT NULL DEFAULT 0
         )'
    );
    if ((int) $pdo->query('SELECT COUNT(*) FROM niveis')->fetchColumn() === 0) {
        semearNiveis($pdo);
    }

    // Categorias automáticas passaram a ser marcadas à parte das protegidas: a
    // protegida não se exclui e tem prioridade fixa; a oculta some da escolha
    // de categoria de um evento, porque quem a aplica é o motor.
    $colunas = $pdo->query('PRAGMA table_info(categorias)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('oculta', $colunas, true)) {
        $pdo->exec('ALTER TABLE categorias ADD COLUMN oculta INTEGER NOT NULL DEFAULT 0');
        $pdo->exec('UPDATE categorias SET oculta = 1 WHERE protegida = 1');
    }
    // A lista de categorias fixas mudou com o tempo; basta uma fora do lugar
    // para valer a pena reaplicar todas — são quatro UPDATEs por nome.
    $fora = $pdo->prepare('SELECT COUNT(*) FROM categorias WHERE nome = ? AND (protegida = 0 OR prioridade <> ?)');
    foreach (categoriasFixas() as $nome => [$prio, $oculta]) {
        $fora->execute([$nome, $prio]);
        if ((int) $fora->fetchColumn() > 0) {
            aplicarCategoriasFixas($pdo);
            break;
        }
    }

    // Fim de semana e sábado letivo deixaram de ser legenda. O primeiro virou
    // cor fixa da grade (em Configurações) e o segundo saiu: quem faz um sábado
    // contar é o campo "Conta como letivo" do próprio evento. A cor escolhida
    // para o fim de semana vira o valor inicial da configuração, para a grade
    // continuar igual ao que já estava na tela.
    $st = $pdo->query("SELECT cor FROM categorias WHERE nome = 'Fim de Semana'");
    $corFds = (string) ($st->fetchColumn() ?: '');
    if ($corFds !== '') {
        $pdo->prepare('INSERT INTO config (chave, valor) VALUES (?,?)
                       ON CONFLICT(chave) DO UPDATE SET valor = excluded.valor')
            ->execute(['cor_dia_fds', $corFds]);
        $pdo->exec("DELETE FROM categorias WHERE nome IN ('Fim de Semana', 'Representação de Dia Letivo')");
    }

    // Feriados deixaram de ser inseridos ano a ano: agora são um cadastro só,
    // que vale para todos os anos.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS feriados (
             id           INTEGER PRIMARY KEY AUTOINCREMENT,
             nome         TEXT NOT NULL,
             tipo         TEXT NOT NULL DEFAULT 'fixo',
             dia          INTEGER,
             mes          INTEGER,
             deslocamento INTEGER,
             categoria_id INTEGER REFERENCES categorias(id) ON DELETE SET NULL,
             ativo        INTEGER NOT NULL DEFAULT 1,
             CHECK (tipo IN ('fixo','movel')),
             CHECK ((tipo = 'fixo'  AND dia BETWEEN 1 AND 31 AND mes BETWEEN 1 AND 12)
                 OR (tipo = 'movel' AND deslocamento IS NOT NULL))
         )"
    );
    if ((int) $pdo->query('SELECT COUNT(*) FROM feriados')->fetchColumn() === 0) {
        semearFeriados($pdo);
    }

    // A categoria única "Feriado" virou três, por origem da norma.
    $tem = (int) $pdo->query("SELECT COUNT(*) FROM categorias WHERE nome = 'Feriado'")->fetchColumn();
    if ($tem === 0) {
        return;
    }

    $pdo->beginTransaction();
    $pdo->exec("UPDATE categorias SET ordem = ordem + 2 WHERE ordem > 2 AND protegida = 0");
    $pdo->exec("UPDATE categorias SET nome = 'Feriado Nacional' WHERE nome = 'Feriado'");
    $st = $pdo->prepare(
        'INSERT INTO categorias (nome, cor, cor_texto, letivo, prioridade, na_legenda, ordem, protegida)
         VALUES (?,?,?,?,?,?,?,?)'
    );
    $st->execute(['Feriado Estadual', '#ff0000', '#000000', 0, 94, 1, 3, 0]);
    $st->execute(['Feriado Municipal', '#ff0000', '#000000', 0, 93, 1, 4, 0]);

    // Reclassifica o que já estava cadastrado pela redação da descrição —
    // "(Feriado Estadual)", "(Feriado municipal)". O resto fica em Nacional.
    $pdo->exec(
        "UPDATE eventos SET categoria_id = (SELECT id FROM categorias WHERE nome = 'Feriado Estadual')
          WHERE categoria_id = (SELECT id FROM categorias WHERE nome = 'Feriado Nacional')
            AND descricao LIKE '%estadual%'"
    );
    $pdo->exec(
        "UPDATE eventos SET categoria_id = (SELECT id FROM categorias WHERE nome = 'Feriado Municipal')
          WHERE categoria_id = (SELECT id FROM categorias WHERE nome = 'Feriado Nacional')
            AND (descricao LIKE '%municipal%' OR descricao LIKE '%munic_pio%')"
    );
    $pdo->commit();
}

/**
 * Legendas de que o sistema depende: prioridade fixa, sem exclusão.
 *
 * Feriado sempre vence a cor do dia — entre eles, quem tem alcance maior vence
 * (nacional > estadual > municipal > ponto facultativo). Por isso ficam acima
 * do alcance do formulário, que vai de 1 a PRIORIDADE_MAX.
 *
 * nome => [prioridade, oculta]
 */
function categoriasFixas(): array
{
    return [
        'Feriado Nacional'            => [99, 0],
        'Feriado Estadual'            => [98, 0],
        'Feriado Municipal'           => [97, 0],
        'Ponto Facultativo'           => [96, 0],
    ];
}

function aplicarCategoriasFixas(PDO $pdo): void
{
    $st = $pdo->prepare('UPDATE categorias SET prioridade = ?, protegida = 1, oculta = ? WHERE nome = ?');
    foreach (categoriasFixas() as $nome => [$prio, $oculta]) {
        $st->execute([$prio, $oculta, $nome]);
    }
    $pdo->exec('UPDATE categorias SET prioridade = ' . PRIORIDADE_MAX
             . ' WHERE protegida = 0 AND prioridade > ' . PRIORIDADE_MAX);
}

/** Feriados de fábrica: nacionais, estaduais do Tocantins e pontos facultativos federais. */
function semearFeriados(PDO $pdo): void
{
    // nome, tipo, dia, mes, deslocamento, categoria
    $lista = [
        ['Confraternização Universal',                          'fixo',  1,  1,  null, 'Feriado Nacional'],
        ['Sexta-feira da Paixão',                               'movel', null, null, -2, 'Feriado Nacional'],
        ['Tiradentes',                                          'fixo',  21, 4,  null, 'Feriado Nacional'],
        ['Dia Mundial do Trabalho',                             'fixo',  1,  5,  null, 'Feriado Nacional'],
        ['Independência do Brasil',                             'fixo',  7,  9,  null, 'Feriado Nacional'],
        ['Nossa Senhora Aparecida, padroeira do Brasil',        'fixo',  12, 10, null, 'Feriado Nacional'],
        ['Finados',                                             'fixo',  2,  11, null, 'Feriado Nacional'],
        ['Proclamação da República',                            'fixo',  15, 11, null, 'Feriado Nacional'],
        ['Dia Nacional de Zumbi e da Consciência Negra',        'fixo',  20, 11, null, 'Feriado Nacional'],
        ['Natal',                                               'fixo',  25, 12, null, 'Feriado Nacional'],

        ['Dia do Senhor do Bonfim',                             'fixo',  15, 8,  null, 'Feriado Estadual'],
        ['Nossa Senhora da Natividade, padroeira do Tocantins', 'fixo',  8,  9,  null, 'Feriado Estadual'],
        ['Criação do Estado do Tocantins',                      'fixo',  5,  10, null, 'Feriado Estadual'],

        ['Carnaval',                                            'movel', null, null, -48, 'Ponto Facultativo'],
        ['Carnaval',                                            'movel', null, null, -47, 'Ponto Facultativo'],
        ['Quarta-feira de Cinzas (até às 14 horas)',            'movel', null, null, -46, 'Ponto Facultativo'],
        ['Corpus Christi',                                      'movel', null, null,  60, 'Ponto Facultativo'],
        ['Dia do Servidor Público',                             'fixo',  28, 10, null, 'Ponto Facultativo'],
        ['Véspera do Natal (após as 14 horas)',                 'fixo',  24, 12, null, 'Ponto Facultativo'],
        ['Véspera do Ano Novo (após as 14 horas)',              'fixo',  31, 12, null, 'Ponto Facultativo'],
    ];
    $st = $pdo->prepare(
        'INSERT INTO feriados (nome, tipo, dia, mes, deslocamento, categoria_id)
         VALUES (?,?,?,?,?, (SELECT id FROM categorias WHERE nome = ?))'
    );
    foreach ($lista as $f) {
        $st->execute($f);
    }
}

/** Os níveis que a instituição já usava antes de a tela existir. */
function semearNiveis(PDO $pdo): void
{
    $st = $pdo->prepare('INSERT INTO niveis (chave, nome, ordem) VALUES (?,?,?)');
    foreach ([
        ['superior',     'Superior',             1],
        ['integrado',    'Técnico Integrado',    2],
        ['concomitante', 'Técnico Concomitante', 3],
        ['subsequente',  'Técnico Subsequente',  4],
    ] as $n) {
        $st->execute($n);
    }
}

/** Dados iniciais: legenda oficial, configuração institucional e feriados fixos. */
function seed(PDO $pdo): void
{
    semearNiveis($pdo);

    $st = $pdo->prepare('INSERT INTO config (chave, valor) VALUES (?, ?)');
    foreach (cfgPadroes() as $k => $v) {
        $st->execute([$k, $v]);
    }

    // nome, cor, cor_texto, letivo, prioridade, na_legenda, ordem
    // As quatro de feriado têm a prioridade corrigida logo abaixo por
    // aplicarCategoriasFixas(), que é quem manda nelas.
    $cats = [
        // Exame Final é neutro: exames dentro do semestre continuam contando como
        // letivos; os que ficam fora já são excluídos pelos limites do semestre.
        ['Exame Final',                                   '#7767d7', '#ffffff', null, 80, 1, 1],
        // Mesma cor e mesmo efeito nos três: o que muda é a origem da norma —
        // federal, estadual ou municipal.
        ['Feriado Nacional',                              '#ff0000', '#000000', 0,    99, 1, 2],
        ['Feriado Estadual',                              '#ff0000', '#000000', 0,    98, 1, 3],
        ['Feriado Municipal',                             '#ff0000', '#000000', 0,    97, 1, 4],
        ['Férias',                                        '#e452cf', '#000000', 0,    90, 1, 5],
        ['Período de culminância de Projetos Pedagógicos','#ffff00', '#000000', null, 45, 1, 6],
        ['Dias Escolares Não Letivos',                    '#d0cece', '#000000', 0,    70, 1, 7],
        ['Ponto Facultativo',                             '#00b050', '#000000', 0,    96, 1, 8],
        ['Planejamento Pedagógico',                       '#1155cc', '#ffffff', 0,    65, 1, 10],
        ['Início ou Fim de semestre letivo',              '#9bc2e6', '#000000', null, 50, 1, 11],
    ];
    $st = $pdo->prepare(
        'INSERT INTO categorias (nome, cor, cor_texto, letivo, prioridade, na_legenda, ordem)
         VALUES (?,?,?,?,?,?,?)'
    );
    foreach ($cats as $c) {
        $st->execute($c);
    }
    aplicarCategoriasFixas($pdo);
    semearFeriados($pdo);
}

/**
 * Padrões de fábrica das configurações. Vale como documentação do que existe:
 * a tela de Configurações monta os campos a partir daqui, e cfg() cai nestes
 * valores enquanto a chave não estiver gravada.
 */
function cfgPadroes(): array
{
    return [
        'orgao'          => "MINISTÉRIO DA EDUCAÇÃO\nSECRETARIA DE EDUCAÇÃO PROFISSIONAL E TECNOLÓGICA\nINSTITUTO FEDERAL DE EDUCAÇÃO, CIÊNCIA E TECNOLOGIA DO TOCANTINS",
        'campus'         => 'CAMPUS MIDGARD',
        'cidade'         => 'Midgard',
        'titulo_modelo'  => 'CALENDÁRIO DO CURSO {curso} {ano}',
        'situacao'       => 'Aguardando homologação',
        'meta_letivos'   => '100',
        // Cores fixas da grade — as que não vêm da legenda.
        'cor_dia_util'   => '#ffffff',
        'cor_dia_fds'    => '#ccc1da',
        'cor_mes'        => '#92d050',
        'cor_dow'        => '#8eb4e3',
    ];
}

function cfg(string $chave, ?string $padrao = null): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT chave, valor FROM config') as $r) {
            $cache[$r['chave']] = $r['valor'];
        }
    }
    return $cache[$chave] ?? $padrao ?? (cfgPadroes()[$chave] ?? '');
}

/** Grava uma configuração. A tela é a única a chamar; o cache morre no redirect. */
function cfgSalvar(PDO $db, string $chave, string $valor): void
{
    $db->prepare('INSERT INTO config (chave, valor) VALUES (?,?)
                  ON CONFLICT(chave) DO UPDATE SET valor = excluded.valor')
       ->execute([$chave, $valor]);
}
