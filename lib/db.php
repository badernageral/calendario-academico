<?php
declare(strict_types=1);

require_once __DIR__ . '/migracoes.php';

const APP_ROOT = __DIR__ . '/..';

// No Apache o banco fica em data/, dentro do site. No aplicativo desktop ele
// precisa ficar fora da pasta de instalação, para sobreviver a uma atualização
// — daí o caminho poder vir do ambiente.
define('DB_PATH', getenv('CALENDARIO_DB') ?: APP_ROOT . '/data/calendario.sqlite');

/**
 * Teto da prioridade que o formulário de legenda aceita; acima ficam os feriados.
 *
 * Era 95 até o Feriado Escolar existir. Ele ficou com 95 — logo abaixo dos
 * quatro que já havia —, e o teto desceu para não empatar com ele: o motor pinta
 * com `>` estrito, e no empate a cor do dia sairia da ordem em que os eventos
 * foram lidos, não de uma regra.
 */
const PRIORIDADE_MAX = 94;

/**
 * Trava cooperativa, mantida até o fim da requisição. Nunca apagar este arquivo:
 * todos os processos precisam disputar o mesmo inode, inclusive após a troca.
 * Ferramentas externas devem parar durante uma restauração.
 */
function travarBanco(bool $exclusivo = false): void
{
    static $trava = null;
    static $exclusiva = false;
    if (is_resource($trava)) {
        if ($exclusivo && !$exclusiva) {
            throw new RuntimeException('A restauração deve começar antes de abrir o banco.');
        }
        return;
    }
    if (!is_dir(dirname(DB_PATH)) && !mkdir(dirname(DB_PATH), 0775, true) && !is_dir(dirname(DB_PATH))) {
        throw new RuntimeException('Não foi possível criar a pasta do banco.');
    }
    $arquivo = @fopen(DB_PATH . '.lock', 'c');
    if ($arquivo === false) {
        throw new RuntimeException('Não foi possível abrir a trava do banco.');
    }
    $limite = microtime(true) + 5;
    do {
        if (flock($arquivo, ($exclusivo ? LOCK_EX : LOCK_SH) | LOCK_NB)) {
            $trava = $arquivo;
            $exclusiva = $exclusivo;
            return;
        }
        usleep(50000);
    } while (microtime(true) < $limite);
    fclose($arquivo);
    throw new RuntimeException('O banco está em uso. Aguarde alguns instantes e tente novamente.');
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    travarBanco();
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
    // Sem isto, quem esbarra numa escrita em curso recebe "database is locked"
    // na hora, em vez de esperar. O caso que interessa é o da atualização: duas
    // abas abertas ao mesmo tempo num banco que ainda não migrou, uma migrando e
    // a outra levando erro na cara. Cinco segundos é muito mais do que qualquer
    // escrita daqui leva.
    $pdo->exec('PRAGMA busy_timeout = 5000');

    // O schema é todo CREATE ... IF NOT EXISTS, então aplicá-lo sempre não mexe
    // no que já existe e cria o que passou a existir. É o que faz uma tabela
    // nova chegar a um banco antigo sem precisar de migração: migração é para o
    // que o CREATE não resolve — coluna que muda, dado que se converte.
    $pdo->exec((string) file_get_contents(__DIR__ . '/schema.sql'));

    if ($novo) {
        seed($pdo);
        // O schema.sql já descreve o banco depois de todas as migrações, então
        // elas nascem marcadas como aplicadas — rodá-las aqui seria repetir o
        // que acabou de ser criado, e a primeira que fizesse um ALTER falharia.
        marcarMigracoesComoAplicadas($pdo);
    } else {
        migrar($pdo);
    }

    return $pdo;
}

/** A tabela do histórico, criada na primeira vez que alguém pergunta por ele. */
function tabelaDeMigracoes(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS migracoes (
             nome        TEXT PRIMARY KEY,
             aplicada_em TEXT NOT NULL DEFAULT (datetime('now','localtime'))
         )"
    );
}

/** Os nomes já aplicados neste banco. */
function migracoesAplicadas(PDO $pdo): array
{
    tabelaDeMigracoes($pdo);
    return $pdo->query('SELECT nome FROM migracoes ORDER BY nome')->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Registra todas sem rodar nenhuma: é o marco zero de um banco recém-criado.
 *
 * $lista existe para os testes poderem passar um registro de mentira; em uso
 * normal fica de fora e vale o de lib/migracoes.php.
 */
function marcarMigracoesComoAplicadas(PDO $pdo, ?array $lista = null): void
{
    tabelaDeMigracoes($pdo);
    $st = $pdo->prepare('INSERT OR IGNORE INTO migracoes (nome) VALUES (?)');
    foreach (array_keys($lista ?? migracoes()) as $nome) {
        $st->execute([$nome]);
    }
}

/**
 * Aplica no banco as migrações que ainda faltam, na ordem em que estão
 * declaradas em lib/migracoes.php, e grava o nome de cada uma.
 *
 * Roda em toda requisição sobre um banco existente. Num banco em dia isso é uma
 * consulta a uma tabela de poucas linhas; havendo o que fazer, cada migração vai
 * numa transação com o próprio registro, então uma que falhe no meio não deixa
 * metade aplicada nem se dá por feita.
 *
 * Um erro aqui não é escondido: sobe. Um banco meio migrado é pior do que uma
 * tela que não abre — a tela avisa, o banco silencioso, não.
 */
function migrar(PDO $pdo, ?array $lista = null): void
{
    $feitas = migracoesAplicadas($pdo);
    $registra = $pdo->prepare('INSERT INTO migracoes (nome) VALUES (?)');

    foreach ($lista ?? migracoes() as $nome => $passo) {
        if (in_array($nome, $feitas, true)) {
            continue;
        }
        $pdo->beginTransaction();
        try {
            $passo($pdo);
            $registra->execute([$nome]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw new RuntimeException("Falha na migração '$nome': " . $e->getMessage(), 0, $e);
        }
    }
}

function categoriasFixas(): array
{
    return [
        'Feriado Nacional'            => [99, 1],
        'Feriado Estadual'            => [98, 1],
        'Feriado Municipal'           => [97, 1],
        'Ponto Facultativo'           => [96, 1],
        // Feriado da instituição, não da norma civil: o Dia do Professor é o
        // caso. Fica abaixo dos três civis e do ponto facultativo — caindo no
        // mesmo dia que um deles, quem pinta é o de alcance maior.
        'Feriado Escolar'             => [95, 1],
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
 * Nacional, estadual, municipal e escolar viram uma linha só, "Feriado", quando
 * as quatro estão na mesma cor — que é como o seed as entrega.
 *
 * A legenda existe para explicar as cores do calendário: quatro linhas com o
 * mesmo vermelho não explicam nada, só ocupam espaço numa página que já é
 * apertada. O que distingue as quatro é a origem da norma, e isso continua dito
 * onde importa — na lista do mês, em "25 - Natal - Feriado Nacional".
 *
 * Basta um campus dar cor própria a uma delas para as quatro voltarem a
 * aparecer separadas, que é quando a distinção passa a valer alguma coisa no
 * papel. É tudo ou nada de propósito: quem diferenciou uma quis ver a diferença,
 * e mostrar duas linhas juntas e duas soltas seria o pior dos dois mundos.
 *
 * O Ponto Facultativo fica de fora: ele já vem com cor própria no seed, e não é
 * feriado — é dia em que o expediente é dispensável, não suprimido.
 *
 * A linha que fica herda a posição da primeira delas na ordem da legenda.
 */
function juntarFeriadosDaLegenda(array $legenda): array
{
    $tipos = ['Feriado Nacional', 'Feriado Estadual', 'Feriado Municipal', 'Feriado Escolar'];
    $cores = [];
    foreach ($legenda as $c) {
        if (in_array($c['nome'], $tipos, true)) {
            $cores[$c['nome']] = strtolower((string) $c['cor']);
        }
    }
    // Faltando alguma, ou havendo cor diferente entre elas, não há o que juntar.
    if (count($cores) !== count($tipos) || count(array_unique($cores)) !== 1) {
        return $legenda;
    }

    $primeira = true;
    foreach ($legenda as $chave => $c) {
        if (!in_array($c['nome'], $tipos, true)) {
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

/**
 * O que a categoria faz com a conta de dias letivos, em uma palavra.
 *
 * A coluna `letivo` tem três estados e nenhum deles se lê no nome: 1 obriga o
 * dia a contar (o sábado letivo), 0 obriga a não contar (feriado, férias,
 * recesso) e NULL não mexe — o dia decide pelo dia da semana, e a categoria só
 * pinta. Quem escolhe a cor de um evento precisa saber disso antes de escolher,
 * não depois de ver o total mudar.
 */
function efeitoDaCategoria(int|string|null $letivo): string
{
    return match ($letivo === null ? null : (int) $letivo) {
        1       => 'Letivo',
        0       => 'Não letivo',
        default => 'Neutro',
    };
}

/** Só as de feriado, que são as que a tela de Feriados oferece como tipo. */
function nomesDeFeriado(): array
{
    return ['Feriado Nacional', 'Feriado Estadual', 'Feriado Municipal',
            'Ponto Facultativo', 'Feriado Escolar'];
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
 * Feriados de fábrica: nacionais, estaduais do Tocantins, pontos facultativos
 * federais e o feriado escolar — o que vale igual em todo campus do IFTO.
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

        // Não é feriado da norma civil: é dia escolar não letivo, e por isso
        // tem categoria própria. A data é a mesma em todo lugar.
        ['Dia do Professor',                                    'fixo',  15, 10, null, 'Feriado Escolar'],
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
    $st = $pdo->prepare('INSERT INTO niveis (chave, nome) VALUES (?,?)');
    foreach ([
        ['superior',     'Superior'],
        ['integrado',    'Técnico Integrado'],
        ['concomitante', 'Técnico Concomitante'],
        ['subsequente',  'Técnico Subsequente'],
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
    // As de feriado têm a prioridade corrigida logo abaixo por
    // aplicarCategoriasFixas(), que é quem manda nelas.
    $cats = [
        // Exame Final é neutro: exames dentro do semestre continuam contando como
        // letivos; os que ficam fora já são excluídos pelos limites do semestre.
        // 85, e não 80: em 80 ele empataria com o Recesso, e o motor pinta com
        // `>` estrito — no empate a cor do dia sairia da ordem em que os eventos
        // foram lidos, não de uma regra.
        ['Exame Final',                                   '#7767d7', '#ffffff', null, 85, 1, 1],
        // Mesma cor e mesmo efeito nos quatro tipos de feriado: o que muda é a
        // origem da norma — federal, estadual, municipal ou da própria escola.
        ['Feriado Nacional',                              '#ff0000', '#000000', 0,    99, 1, 2],
        ['Feriado Estadual',                              '#ff0000', '#000000', 0,    98, 1, 3],
        ['Feriado Municipal',                             '#ff0000', '#000000', 0,    97, 1, 4],
        // O mesmo vermelho dos três civis: as quatro pintam o dia de feriado, e
        // na legenda impressa viram uma linha só. O que as distingue é a origem
        // da norma, e isso continua dito na lista do mês.
        ['Feriado Escolar',                               '#ff0000', '#000000', 0,    95, 1, 5],
        ['Férias',                                        '#e452cf', '#000000', 0,    90, 1, 6],
        ['Datas comemorativas',                           '#ffff00', '#000000', null, 45, 1, 7],
        ['Dias Escolares Não Letivos',                    '#d0cece', '#000000', 0,    70, 1, 8],
        ['Ponto Facultativo',                             '#00b050', '#000000', 0,    96, 1, 9],
        ['Planejamento Pedagógico',                       '#1155cc', '#ffffff', 0,    65, 1, 10],
        // A única que obriga o dia a contar: é o sábado que repõe uma segunda, a
        // quinta que cumpre horário de terça. As demais ou tiram o dia da conta
        // ou não mexem nela — esta põe. Com ela escolhida, "Conta como letivo"
        // pode ficar em "Herdar da categoria" que o dia conta assim mesmo.
        //
        // 49, e não 50: em 50 empatava com o marco de início e fim de bimestre, e
        // o motor pinta com `>` estrito — no empate a cor do dia saía da ordem em
        // que os eventos foram lidos, não de uma regra. Caindo a reposição num dia
        // de abertura ou fechamento de período, quem manda no dia é o marco: ele
        // diz o que aquele dia é no calendário, e a reposição diz só que horário
        // se cumpre nele.
        ['Reposição de horário',                          '#ffbe6f', '#000000', 1,    49, 1, 0],
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
        'titulo_modelo'       => 'CALENDÁRIO DO CURSO {nivel} EM {curso} / {ano}',
        // Um calendário por nível não tem um curso para nomear — vale para
        // todos os daquele nível —, então o modelo do curso, com {curso},
        // sairia com o campo vazio. Este é o modelo desses calendários.
        'titulo_modelo_nivel' => 'CALENDÁRIO DOS CURSOS DE NÍVEL {nivel} / {ano}',
        'situacao'       => 'Aguardando homologação',
        // Os quatro textos que o motor escreve sozinho nos dias de início e fim
        // de bimestre e de semestre. {ano} é o ano do calendário, {semestre} é
        // 1 ou 2 e {bimestre} é o número do bimestre como ele se chama naquele
        // curso — 1 a 4 no anual, 1 ou 2 dentro de cada semestre no semestral.
        'texto_inicio_semestre' => 'Início do {semestre}º semestre e {bimestre}º bimestre letivo de {ano}/{semestre}',
        'texto_fim_bimestre'    => 'Fim do {bimestre}º Bimestre',
        'texto_inicio_bimestre' => 'Início do {bimestre}º Bimestre',
        'texto_fim_semestre'    => 'Fim do {bimestre}º Bimestre e Fim do {semestre}º Semestre letivo {ano}/{semestre}',
        // As quatro linhas em negrito, como sempre saíram. Quem quiser o peso do
        // resto da lista desmarca em Configurações.
        'negrito_periodo'       => '1',
        // Cores fixas da grade — as que não vêm da legenda.
        'cor_dia_util'   => '#ffffff',
        'cor_dia_fds'    => '#ccc1da',
        'cor_mes'        => '#92d050',
        'cor_dow'        => '#8eb4e3',
    ];
}

function cfg(string $chave, ?string $padrao = null): string
{
    if (!isset($GLOBALS['cfg_cache'])) {
        $GLOBALS['cfg_cache'] = [];
        foreach (db()->query('SELECT chave, valor FROM config') as $r) {
            $GLOBALS['cfg_cache'][$r['chave']] = $r['valor'];
        }
    }
    return $GLOBALS['cfg_cache'][$chave] ?? $padrao ?? (cfgPadroes()[$chave] ?? '');
}

/**
 * Esquece o que já foi lido de `config`. Um pedido web não precisa: ele acaba
 * no redirect e o processo seguinte lê de novo. Os testes precisam — eles
 * trocam de banco várias vezes dentro do mesmo processo, e sem isto o segundo
 * banco responderia com os valores do primeiro.
 */
function cfgEsquecer(): void
{
    unset($GLOBALS['cfg_cache']);
}

/** Grava uma configuração. A tela é a única a chamar; o cache morre no redirect. */
function cfgSalvar(PDO $db, string $chave, string $valor): void
{
    $db->prepare('INSERT INTO config (chave, valor) VALUES (?,?)
                  ON CONFLICT(chave) DO UPDATE SET valor = excluded.valor')
       ->execute([$chave, $valor]);
}
