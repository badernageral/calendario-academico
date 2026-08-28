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
    // A legenda de início/fim de semestre e bimestre nasceu protegida: o motor
    // é que a aplica, e a cor dela mora em Configurações. Num banco anterior
    // ela ou não existe, ou existe com o nome velho ("Início ou Fim de semestre
    // letivo"), de quando era uma legenda comum — nesse caso é a mesma coisa
    // com outro nome, e renomear preserva a cor que o campus já tinha escolhido.
    $temNova = (int) $pdo->query('SELECT COUNT(*) FROM categorias WHERE nome = ' . $pdo->quote(CAT_SEMESTRE))->fetchColumn();
    if ($temNova === 0) {
        $velha = $pdo->query("SELECT id FROM categorias WHERE nome = 'Início ou Fim de semestre letivo'")->fetchColumn();
        if ($velha !== false) {
            $pdo->prepare('UPDATE categorias SET nome = ? WHERE id = ?')->execute([CAT_SEMESTRE, $velha]);
        } else {
            $pdo->prepare(
                'INSERT INTO categorias (nome, cor, cor_texto, letivo, prioridade, na_legenda, ordem)
                 VALUES (?,?,?,NULL,?,1,?)'
            )->execute([CAT_SEMESTRE, '#9bc2e6', '#000000', 50, 11]);
        }
    }

    // A lista de categorias fixas mudou com o tempo; basta uma fora do lugar
    // para valer a pena reaplicar todas — são quatro UPDATEs por nome.
    $fora = $pdo->prepare(
        'SELECT COUNT(*) FROM categorias
          WHERE nome = ? AND (protegida = 0 OR prioridade <> ? OR oculta <> ?)'
    );
    foreach (categoriasFixas() as $nome => [$prio, $oculta]) {
        $fora->execute([$nome, $prio, $oculta]);
        if ((int) $fora->fetchColumn() > 0) {
            aplicarCategoriasFixas($pdo);
            break;
        }
    }

    // A meta também tinha um padrão em `config`, que ficou órfão quando o campo
    // saiu de Configurações: nada mais o lê.
    $pdo->exec("DELETE FROM config WHERE chave = 'meta_letivos'");

    // A meta de dias letivos saiu: quem confere o número agora são os seis
    // contadores no topo da tela do calendário — dois de semestre e quatro de
    // bimestre —, e não um alvo digitado a mais em cada calendário. DROP COLUMN
    // existe no SQLite desde a 3.35; num mais antigo as colunas ficam onde
    // estão, sem uso, que é inofensivo.
    $colunasCal = $pdo->query('PRAGMA table_info(calendarios)')->fetchAll(PDO::FETCH_COLUMN, 1);
    foreach (['meta_letivos_s1', 'meta_letivos_s2'] as $morta) {
        if (in_array($morta, $colunasCal, true)) {
            try {
                $pdo->exec("ALTER TABLE calendarios DROP COLUMN $morta");
            } catch (PDOException $e) {
                // SQLite velho demais: deixa a coluna quieta.
            }
        }
    }

    // O curso passou a dizer se as disciplinas dele são anuais ou semestrais.
    // Quem já tinha banco entra como 'semestral', que é o caso comum e o mesmo
    // padrão do schema. O ALTER do SQLite não carrega o CHECK da tabela nova —
    // quem peneira o valor é a tela de Cursos, e ela aceita só os dois.
    $colunasCursos = $pdo->query('PRAGMA table_info(cursos)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('regime', $colunasCursos, true)) {
        $pdo->exec("ALTER TABLE cursos ADD COLUMN regime TEXT NOT NULL DEFAULT 'semestral'");
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
 * Legendas de que o sistema depende: nome, prioridade e cor fora do alcance da
 * tela de Legenda.
 *
 * Feriado sempre vence a cor do dia — entre eles, quem tem alcance maior vence
 * (nacional > estadual > municipal > ponto facultativo). Por isso ficam acima
 * do alcance do formulário, que vai de 1 a PRIORIDADE_MAX.
 *
 * As quatro são `oculta`: não se escolhem como categoria de um evento, porque
 * quem as aplica é o cadastro de feriados — inclusive as emendas, que entram lá
 * como Ponto Facultativo.
 *
 * As quatro continuam saindo na legenda impressa, e a cor de cada uma se troca
 * em Configurações — é a única coisa delas que se ajusta.
 *
 * O nome é a identidade: é por ele que o cadastro de feriados encontra o tipo e
 * que migrar() reconhece as quatro. Por isso a tela de Legenda não o edita.
 *
 * nome => [prioridade, oculta]
 */
function categoriasFixas(): array
{
    return [
        'Feriado Nacional'            => [99, 1],
        'Feriado Estadual'            => [98, 1],
        'Feriado Municipal'           => [97, 1],
        'Ponto Facultativo'           => [96, 1],
        // Aplicada pelo motor nos dias de início e fim de cada bimestre e de
        // cada semestre, a partir das datas do próprio calendário. Prioridade
        // baixa de propósito: um feriado em cima do primeiro dia de aula pinta
        // de vermelho, que é o que manda no dia.
        CAT_SEMESTRE                  => [50, 1],
    ];
}

/** A legenda dos dias de início e fim de semestre e de bimestre. */
const CAT_SEMESTRE = 'Início ou Fim de semestre/bimestre letivo';

/**
 * Nacional, estadual e municipal viram uma linha só, "Feriado", quando as três
 * estão na mesma cor — que é como o seed as entrega.
 *
 * A legenda existe para explicar as cores do calendário: três linhas com o
 * mesmo vermelho não explicam nada, só ocupam espaço numa página que já é
 * apertada. O que distingue as três é a origem da norma, e isso continua dito
 * onde importa — na lista do mês, em "25 - Natal - Feriado Nacional".
 *
 * Basta um campus dar cor própria a uma delas para as três voltarem a aparecer
 * separadas, que é quando a distinção passa a valer alguma coisa no papel.
 *
 * A linha que fica herda a posição da primeira das três na ordem da legenda.
 */
function juntarFeriadosDaLegenda(array $legenda): array
{
    $trio  = ['Feriado Nacional', 'Feriado Estadual', 'Feriado Municipal'];
    $cores = [];
    foreach ($legenda as $c) {
        if (in_array($c['nome'], $trio, true)) {
            $cores[$c['nome']] = strtolower((string) $c['cor']);
        }
    }
    // Faltando alguma, ou havendo cor diferente entre elas, não há o que juntar.
    if (count($cores) !== 3 || count(array_unique($cores)) !== 1) {
        return $legenda;
    }

    $primeira = true;
    foreach ($legenda as $chave => $c) {
        if (!in_array($c['nome'], $trio, true)) {
            continue;
        }
        if ($primeira) {
            $legenda[$chave]['nome'] = 'Feriado';
            $primeira = false;
            continue;
        }
        unset($legenda[$chave]);
    }
    return $legenda;
}

/**
 * A legenda inteira, na ordem em que ela sai no papel: as categorias marcadas
 * para aparecer, mais o dia letivo comum no fim.
 *
 * O dia letivo não é categoria — é a cor fixa da grade, escolhida em
 * Configurações, que fica no quadrado que evento nenhum pintou. Sem esta linha
 * a legenda impressa explicava todas as cores menos a mais frequente do
 * calendário, que é justamente a do dia de aula normal.
 *
 * @param array $categorias as de Engine::categorias()
 */
function legendaDoCalendario(array $categorias): array
{
    $legenda = array_filter($categorias, static fn ($c) => (int) $c['na_legenda'] === 1);
    uasort($legenda, static fn ($a, $b) => [(int) $a['ordem'], $a['nome']] <=> [(int) $b['ordem'], $b['nome']]);
    $legenda = juntarFeriadosDaLegenda($legenda);

    $legenda['dia_letivo'] = ['nome' => 'Representação de Dia Letivo', 'cor' => cfg('cor_dia_util')];
    return $legenda;
}

/** Só as de feriado, que são as que a tela de Feriados oferece como tipo. */
function nomesDeFeriado(): array
{
    return ['Feriado Nacional', 'Feriado Estadual', 'Feriado Municipal', 'Ponto Facultativo'];
}

/**
 * As quatro categorias de feriado, resolvidas pelo nome e na ordem de alcance.
 * Chave = nome, valor = a linha de `categorias`.
 *
 * É daqui que o cadastro de feriados monta a lista de tipos e que Configurações
 * mostra a cor de cada um: as duas telas falam da mesma coisa e não podem
 * discordar. Resolver pelo nome — e não por um id gravado — é o que mantém isso
 * verdadeiro mesmo num banco vindo de uma versão anterior.
 */
function categoriasDeFeriado(PDO $db): array
{
    $st  = $db->prepare('SELECT * FROM categorias WHERE nome = ?');
    $out = [];
    foreach (nomesDeFeriado() as $nome) {
        $st->execute([$nome]);
        if ($linha = $st->fetch()) {
            $out[$nome] = $linha;
        }
    }
    return $out;
}

/**
 * Todas as protegidas — as quatro de feriado e a de início/fim de período —, na
 * ordem em que categoriasFixas() as declara. É a lista de cores que
 * Configurações mostra: são as legendas que o motor aplica sozinho, e a cor é a
 * única coisa ajustável nelas.
 */
function categoriasAutomaticas(PDO $db): array
{
    $st  = $db->prepare('SELECT * FROM categorias WHERE nome = ?');
    $out = [];
    foreach (array_keys(categoriasFixas()) as $nome) {
        $st->execute([$nome]);
        if ($linha = $st->fetch()) {
            $out[$nome] = $linha;
        }
    }
    return $out;
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

/**
 * Feriados de fábrica: nacionais, estaduais do Tocantins e pontos facultativos
 * federais — o que vale igual em todo campus do IFTO.
 *
 * Municipais não entram de propósito: mudam de cidade para cidade, e cada
 * campus cadastra os seus na tela de Feriados. A categoria *Feriado Municipal*
 * já vem criada, esperando por eles.
 */
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

        ['Dia da Autonomia do Estado do Tocantins',             'fixo',  18, 3,  null, 'Feriado Estadual'],
        ['Dia do Senhor do Bonfim',                             'fixo',  15, 8,  null, 'Feriado Estadual'],
        ['Nossa Senhora da Natividade, padroeira do Tocantins', 'fixo',  8,  9,  null, 'Feriado Estadual'],
        ['Criação do Estado do Tocantins',                      'fixo',  5,  10, null, 'Feriado Estadual'],

        ['Carnaval',                                            'movel', null, null, -48, 'Ponto Facultativo'],
        ['Carnaval',                                            'movel', null, null, -47, 'Ponto Facultativo'],
        ['Quarta-feira de Cinzas (até às 14 horas)',            'movel', null, null, -46, 'Ponto Facultativo'],
        ['Corpus Christi',                                      'movel', null, null,  60, 'Ponto Facultativo'],
        ['Dia do Servidor Público federal',                     'fixo',  28, 10, null, 'Ponto Facultativo'],
        ['Véspera do Natal (após as 13 horas)',                 'fixo',  24, 12, null, 'Ponto Facultativo'],
        ['Véspera do Ano Novo (após as 13 horas)',              'fixo',  31, 12, null, 'Ponto Facultativo'],
    ];
    // As emendas — a segunda antes de um feriado de terça, a sexta depois de
    // Corpus Christi — não vêm de fábrica: a portaria anual do MGI as declara
    // ano a ano, e em 2026 são 20/4 e 5/6. Quem quiser cadastra as suas aqui,
    // como Ponto Facultativo. Atenção ao que este cadastro é: uma regra que
    // vale para todos os anos. Uma emenda lançada como data fixa vai repetir
    // em 2027, quando o feriado cai noutro dia da semana e emenda nenhuma foi
    // declarada — é desmarcar *Ativo* no ano em que ela não valer.
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
        // Recesso: cinza como os Dias Escolares Não Letivos, e como eles não
        // conta. Vem com ordem 0 porque abre a legenda impressa.
        ['Recesso',                                       '#d0cece', '#000000', 0,    80, 1, 0],
        // Neutra: o primeiro e o último dia de um bimestre são dias de aula
        // normais — a categoria só os pinta. A cor se troca em Configurações.
        [CAT_SEMESTRE,                                    '#9bc2e6', '#000000', null, 50, 1, 11],
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
        // O órgão é o mesmo em todo o IFTO, então vem pronto. O campus e a
        // cidade são de exemplo: cada instalação troca os dois em Configurações
        // antes de gerar o primeiro calendário, porque é este par que sai no
        // cabeçalho impresso.
        'orgao'          => "MINISTÉRIO DA EDUCAÇÃO\nSECRETARIA DE EDUCAÇÃO PROFISSIONAL E TECNOLÓGICA\nINSTITUTO FEDERAL DE EDUCAÇÃO, CIÊNCIA E TECNOLOGIA DO TOCANTINS",
        'campus'         => 'CAMPUS MIDGARD',
        'cidade'         => 'Midgard',
        'titulo_modelo'  => 'CALENDÁRIO DO CURSO {nivel} EM {curso} / {ano}',
        'situacao'       => 'Aguardando homologação',
        // Os quatro textos que o motor escreve sozinho nos dias de início e fim
        // de bimestre e de semestre. {ano} é o ano do calendário, {semestre} é
        // 1 ou 2 e {bimestre} é o número do bimestre como ele se chama naquele
        // curso — 1 a 4 no anual, 1 ou 2 dentro de cada semestre no semestral.
        'texto_inicio_semestre' => 'Início do {semestre}º semestre e {bimestre}º bimestre letivo de {ano}/{semestre}',
        'texto_fim_bimestre'    => 'Fim do {bimestre}º Bimestre',
        'texto_inicio_bimestre' => 'Início do {bimestre}º Bimestre',
        'texto_fim_semestre'    => 'Fim do {bimestre}º Bimestre e Fim do {semestre}º Semestre letivo {ano}/{semestre}',
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
