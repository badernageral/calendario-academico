<?php
declare(strict_types=1);

/**
 * Motor do calendário: resolve, para cada dia do ano, qual categoria pinta a
 * célula e se o dia conta como letivo; e produz as contagens mensais, o resumo
 * semestral e as notas de reposição.
 */
final class Engine
{
    public const MESES = [
        1 => 'JANEIRO', 'FEVEREIRO', 'MARÇO', 'ABRIL', 'MAIO', 'JUNHO',
        'JULHO', 'AGOSTO', 'SETEMBRO', 'OUTUBRO', 'NOVEMBRO', 'DEZEMBRO',
    ];
    /** Iniciais do cabeçalho da grade, domingo a sábado. */
    public const DOW_INICIAL = ['D', 'S', 'T', 'Q', 'Q', 'S', 'S'];
    public const DOW_NOME = [
        0 => 'domingo', 1 => 'segunda', 2 => 'terça', 3 => 'quarta',
        4 => 'quinta', 5 => 'sexta', 6 => 'sábado',
    ];
    public const DOW_PLURAL = [
        0 => 'domingos', 1 => 'segundas', 2 => 'terças', 3 => 'quartas',
        4 => 'quintas', 5 => 'sextas', 6 => 'sábados',
    ];

    private array $categorias = [];
    private array $eventos    = [];
    private array $semestres  = [];
    private bool  $semestresImplicitos = false;
    /** @var array<string, array> mapa 'Y-m-d' => estado do dia */
    private array $dias = [];

    /**
     * O que entra na grade:
     *   'calendario' — eventos do curso, globais do ano e os feriados;
     *   'globais'    — só os eventos globais do ano (tela de Eventos globais);
     *   'feriados'   — só os feriados do cadastro (tela de Feriados).
     */
    private string $modo = 'calendario';

    /** No modo 'globais', se os feriados entram junto (a caixa da tela decide). */
    private bool $comFeriados = false;

    public function __construct(
        private PDO $db,
        public array $cal,
        string $modo = 'calendario',
        bool $comFeriados = false,
    ) {
        $this->modo        = $modo;
        $this->comFeriados = $comFeriados;
        foreach ($db->query('SELECT * FROM categorias') as $c) {
            $this->categorias[(int) $c['id']] = $c;
        }
        $this->carregarEventos();
        $this->carregarPeriodos();
        $this->montarDias();
    }

    /**
     * Motor de um ano sem calendário: os eventos globais, de todos os níveis.
     * Serve à tela de Eventos globais, que mostra a mesma grade. Os feriados
     * ficam de fora, a não ser que a tela peça para vê-los.
     */
    public static function paraAnoGlobal(PDO $db, int $ano, bool $comFeriados = false): self
    {
        return new self($db, self::anoSemCalendario($ano, 'EVENTOS GLOBAIS'), 'globais', $comFeriados);
    }

    /** Motor de um ano só com os feriados: a grade da tela de Feriados. */
    public static function paraFeriados(PDO $db, int $ano): self
    {
        return new self($db, self::anoSemCalendario($ano, 'FERIADOS'), 'feriados');
    }

    private static function anoSemCalendario(int $ano, string $rotulo): array
    {
        return [
            'id'              => null,
            'ano'             => $ano,
            'curso_nome'      => $rotulo,
            'curso_nivel'     => null,
            'situacao'        => '',
            'local_texto'     => '',
            'observacoes'     => '',
            'meta_letivos_s1' => 0,
            'meta_letivos_s2' => 0,
        ];
    }

    public static function paraCalendario(PDO $db, int $id): ?self
    {
        $st = $db->prepare(
            'SELECT c.*, cu.nome AS curso_nome, cu.nivel AS curso_nivel
             FROM calendarios c JOIN cursos cu ON cu.id = c.curso_id WHERE c.id = ?'
        );
        $st->execute([$id]);
        $cal = $st->fetch();
        return $cal ? new self($db, $cal) : null;
    }

    // ---------------------------------------------------------------- carga

    private function carregarEventos(): void
    {
        // A tela de Feriados mostra só o cadastro deles, sem evento nenhum.
        if ($this->modo === 'feriados') {
            $this->juntarFeriados();
            return;
        }

        // Sem calendário (id null) a tela é a de eventos globais: entram todos
        // os globais do ano, de qualquer nível.
        if ($this->cal['id'] === null) {
            $st = $this->db->prepare(
                'SELECT * FROM eventos WHERE ano = ? AND calendario_id IS NULL ORDER BY id'
            );
            $st->execute([$this->cal['ano']]);
            $this->guardarEventos($st);
            return;
        }

        $st = $this->db->prepare(
            // nivel guarda zero ou mais níveis separados por vírgula; vazio
            // vale para todos. As vírgulas nas pontas evitam que "superior"
            // case com "superior_novo" num LIKE.
            "SELECT * FROM eventos
             WHERE ano = :ano
               AND (calendario_id = :cal
                    OR (calendario_id IS NULL
                        AND (nivel IS NULL OR nivel = ''
                             OR ',' || nivel || ',' LIKE '%,' || :nivel || ',%')))
             ORDER BY id"
        );
        $st->execute([
            ':ano'   => $this->cal['ano'],
            ':cal'   => $this->cal['id'],
            ':nivel' => $this->cal['curso_nivel'],
        ]);
        $this->guardarEventos($st);
    }

    /** Anexa as faixas de datas e descarta quem ficou sem nenhuma. */
    private function guardarEventos(PDOStatement $st): void
    {
        $eventos = [];
        foreach ($st as $e) {
            $e['datas'] = [];
            $eventos[(int) $e['id']] = $e;
        }
        if ($eventos) {
            $ids = implode(',', array_keys($eventos));
            foreach ($this->db->query("SELECT * FROM evento_datas WHERE evento_id IN ($ids) ORDER BY inicio, fim") as $d) {
                $eventos[(int) $d['evento_id']]['datas'][] = ['inicio' => $d['inicio'], 'fim' => $d['fim']];
            }
        }
        // eventos sem faixa de datas não existem no calendário
        $this->eventos = array_filter($eventos, static fn ($e) => $e['datas'] !== []);
        $this->juntarFeriados();
    }

    /**
     * Os feriados cadastrados entram como eventos do ano, montados na hora. Não
     * existem em `eventos`: por isso levam id null e a marca 'feriado_id', que
     * as telas usam para mandar a edição ao cadastro em vez do formulário de
     * evento. Ficam no fim da lista, mas a ordenação por data cuida do resto.
     *
     * Na tela de eventos globais eles não entram: ali só se mexe no que é
     * evento, e feriado tem tela própria.
     */
    private function juntarFeriados(): void
    {
        if ($this->modo === 'globais' && !$this->comFeriados) {
            return;
        }

        foreach (feriadosDoAno($this->db, (int) $this->cal['ano']) as $f) {
            $this->eventos[] = [
                'id'            => null,
                'feriado_id'    => (int) $f['id'],
                'ano'           => (int) $this->cal['ano'],
                'calendario_id' => null,
                'categoria_id'  => $f['categoria_id'] === null ? null : (int) $f['categoria_id'],
                'descricao'     => $f['nome'],
                'pinta_dias'    => 1,
                'negrito'       => 0,
                'conta_letivo'  => null,   // herda da categoria: feriado não é letivo
                'rotulo'        => null,
                'nivel'         => null,   // feriado vale para todo curso
                'repoe_dow'     => null,
                'datas'         => [['inicio' => $f['data'], 'fim' => $f['data']]],
            ];
        }
    }

    private function carregarPeriodos(): void
    {
        $st = $this->db->prepare(
            "SELECT * FROM periodos WHERE calendario_id = ? AND tipo = 'semestre' ORDER BY numero"
        );
        $st->execute([$this->cal['id']]);
        foreach ($st as $p) {
            $this->semestres[(int) $p['numero']] = $p;
        }

        // Sem semestres cadastrados o ano inteiro é letivo: só feriados, férias
        // e recessos tiram dias. O RESUMO então divide o ano ao meio.
        if (!$this->semestres) {
            $ano = (int) $this->cal['ano'];
            $this->semestres = [
                1 => ['numero' => 1, 'inicio' => "$ano-01-01", 'fim' => "$ano-06-30"],
                2 => ['numero' => 2, 'inicio' => "$ano-07-01", 'fim' => "$ano-12-31"],
            ];
            $this->semestresImplicitos = true;
        }
    }

    /** true = os semestres não foram cadastrados e o ano inteiro está contando. */
    public function semestresImplicitos(): bool
    {
        return $this->semestresImplicitos;
    }

    // ------------------------------------------------------------ resolução

    private function montarDias(): void
    {
        $ano = (int) $this->cal['ano'];

        // 1. base: todo dia do ano, sem categoria
        for ($m = 1; $m <= 12; $m++) {
            $ult = (int) date('t', mktime(0, 0, 0, $m, 1, $ano));
            for ($d = 1; $d <= $ult; $d++) {
                $iso = sprintf('%04d-%02d-%02d', $ano, $m, $d);
                $this->dias[$iso] = [
                    'dow'       => (int) date('w', mktime(0, 0, 0, $m, $d, $ano)),
                    'categoria' => null,
                    'prio'      => PHP_INT_MIN,
                    'forcado'   => null,   // 0/1 vindo de categoria ou evento
                    'eventos'   => [],
                ];
            }
        }

        // 2. aplica os eventos
        // Guarda a chave do evento, não o id: o feriado vem do cadastro e não
        // tem id de evento, mas está na mesma lista.
        foreach ($this->eventos as $chave => $ev) {
            $cat = $ev['categoria_id'] !== null ? ($this->categorias[(int) $ev['categoria_id']] ?? null) : null;
            foreach ($this->expandir($ev['datas']) as $iso) {
                if (!isset($this->dias[$iso])) {
                    continue;   // faixa que atravessa o ano
                }
                $this->dias[$iso]['eventos'][] = $chave;

                if ($cat && (int) $ev['pinta_dias'] === 1) {
                    $prio = (int) $cat['prioridade'];
                    if ($prio > $this->dias[$iso]['prio']) {
                        $this->dias[$iso]['prio']      = $prio;
                        $this->dias[$iso]['categoria'] = $cat;
                    }
                }

                // 0 sempre vence 1: feriado no sábado letivo derruba o dia
                $forca = $ev['conta_letivo'] !== null
                    ? (int) $ev['conta_letivo']
                    : ($cat && $cat['letivo'] !== null ? (int) $cat['letivo'] : null);
                if ($forca !== null) {
                    $atual = $this->dias[$iso]['forcado'];
                    $this->dias[$iso]['forcado'] = ($atual === 0 || $forca === 0) ? 0 : 1;
                }
            }
        }

        // 3. decisão final de dia letivo. A cor do fim de semana não sai daqui:
        // é cor fixa da grade, definida em Configurações, e vale para o dia que
        // nenhum evento pintou.
        foreach ($this->dias as $iso => &$dia) {
            $fds = $dia['dow'] === 0 || $dia['dow'] === 6;

            if (!$this->dentroDeSemestre($iso)) {
                $dia['letivo'] = false;
            } elseif ($dia['forcado'] !== null) {
                $dia['letivo'] = $dia['forcado'] === 1;
            } else {
                $dia['letivo'] = !$fds;
            }
        }
        unset($dia);
    }

    /** @param array<array{inicio:string,fim:string}> $faixas @return string[] */
    private function expandir(array $faixas): array
    {
        $out = [];
        foreach ($faixas as $f) {
            $ini = new DateTimeImmutable($f['inicio']);
            $fim = new DateTimeImmutable($f['fim']);
            if ($fim < $ini) {
                [$ini, $fim] = [$fim, $ini];
            }
            for ($d = $ini; $d <= $fim; $d = $d->modify('+1 day')) {
                $out[$d->format('Y-m-d')] = true;
            }
        }
        return array_keys($out);
    }

    private function dentroDeSemestre(string $iso): bool
    {
        return $this->semestreDe($iso) !== null;
    }

    public function semestreDe(string $iso): ?int
    {
        foreach ($this->semestres as $n => $p) {
            if ($iso >= $p['inicio'] && $iso <= $p['fim']) {
                return $n;
            }
        }
        return null;
    }

    // ------------------------------------------------------------- consultas

    public function dia(string $iso): ?array
    {
        return $this->dias[$iso] ?? null;
    }

    public function semestres(): array
    {
        return $this->semestres;
    }

    public function categorias(): array
    {
        return $this->categorias;
    }

    /** Eventos que valem para este calendário: os locais mais os globais do ano, por id. */
    public function eventos(): array
    {
        return $this->eventos;
    }

    /** Semanas do mês: 6 linhas × 7 colunas (domingo a sábado); null = fora do mês. */
    public function semanas(int $mes): array
    {
        $ano   = (int) $this->cal['ano'];
        $prim  = (int) date('w', mktime(0, 0, 0, $mes, 1, $ano));
        $ult   = (int) date('t', mktime(0, 0, 0, $mes, 1, $ano));
        $cel   = array_fill(0, 42, null);
        for ($d = 1; $d <= $ult; $d++) {
            $cel[$prim + $d - 1] = sprintf('%04d-%02d-%02d', $ano, $mes, $d);
        }
        return array_chunk($cel, 7);
    }

    /** Contagem de dias letivos do mês por dia da semana (Seg..Sáb) + total. */
    public function contagemMes(int $mes): array
    {
        $por = array_fill(1, 6, 0);
        $tot = 0;
        foreach ($this->dias as $iso => $dia) {
            if ((int) substr($iso, 5, 2) !== $mes || !$dia['letivo']) {
                continue;
            }
            $tot++;
            if ($dia['dow'] >= 1 && $dia['dow'] <= 6) {
                $por[$dia['dow']]++;
            }
        }
        return ['por_dow' => $por, 'total' => $tot];
    }

    /** Mesma contagem, para um semestre inteiro. */
    public function contagemSemestre(int $numero): array
    {
        $por = array_fill(1, 6, 0);
        $tot = 0;
        foreach ($this->dias as $iso => $dia) {
            if (!$dia['letivo'] || $this->semestreDe($iso) !== $numero) {
                continue;
            }
            $tot++;
            if ($dia['dow'] >= 1 && $dia['dow'] <= 6) {
                $por[$dia['dow']]++;
            }
        }
        return ['por_dow' => $por, 'total' => $tot];
    }

    /** Eventos listados no mês, na ordem em que aparecem na planilha. */
    public function eventosDoMes(int $mes): array
    {
        $out = [];
        foreach ($this->eventos as $ev) {
            $ini = $ev['datas'][0]['inicio'];
            if ((int) substr($ini, 0, 4) !== (int) $this->cal['ano'] || (int) substr($ini, 5, 2) !== $mes) {
                continue;
            }
            $out[] = ['ordem' => $ini, 'rotulo' => $this->rotulo($ev), 'ev' => $ev];
        }
        usort($out, static fn ($a, $b) => [$a['ordem'], $a['ev']['id']] <=> [$b['ordem'], $b['ev']['id']]);
        return $out;
    }

    /**
     * A descrição como ela sai na lista embaixo do mês. Feriado leva a origem
     * no fim — "25 - Natal - Feriado Nacional" —, porque no papel é ela que
     * diz por que o dia não é letivo.
     */
    public function descricaoNaLista(array $ev): string
    {
        $desc = (string) $ev['descricao'];
        if (!isset($ev['feriado_id'])) {
            return $desc;
        }
        $cat = $ev['categoria_id'] !== null ? ($this->categorias[(int) $ev['categoria_id']] ?? null) : null;
        return $cat ? $desc . ' - ' . $cat['nome'] : $desc;
    }

    /** Rótulo de datas: "5", "5 a 9", "14 a 16 e 19 a 20", "30/9 a 24/10". */
    public function rotulo(array $ev): string
    {
        if (($ev['rotulo'] ?? '') !== '' && $ev['rotulo'] !== null) {
            return $ev['rotulo'];
        }
        $mesBase = (int) substr($ev['datas'][0]['inicio'], 5, 2);
        $partes  = [];
        foreach ($ev['datas'] as $f) {
            $i = new DateTimeImmutable($f['inicio']);
            $f2 = new DateTimeImmutable($f['fim']);
            $fmt = static fn (DateTimeImmutable $d) => (int) $d->format('m') === $mesBase
                ? $d->format('j')
                : $d->format('j') . '/' . $d->format('n');
            $partes[] = $i->format('Y-m-d') === $f2->format('Y-m-d')
                ? $fmt($i)
                : $fmt($i) . ' a ' . $fmt($f2);
        }
        if (count($partes) === 1) {
            return $partes[0];
        }
        $ultimo = array_pop($partes);
        return implode(', ', $partes) . ' e ' . $ultimo;
    }

    /**
     * Notas de reposição por semestre, geradas a partir de repoe_dow:
     * "4 sábados letivos com horário de segunda".
     */
    public function notasReposicao(): array
    {
        $acc = [];
        foreach ($this->eventos as $ev) {
            if ($ev['repoe_dow'] === null) {
                continue;
            }
            foreach ($this->expandir($ev['datas']) as $iso) {
                $sem = $this->semestreDe($iso);
                if ($sem === null || !isset($this->dias[$iso])) {
                    continue;
                }
                $chave = $this->dias[$iso]['dow'] . '-' . (int) $ev['repoe_dow'];
                $acc[$sem][$chave] = ($acc[$sem][$chave] ?? 0) + 1;
            }
        }

        $out = [];
        foreach ($acc as $sem => $itens) {
            ksort($itens);
            foreach ($itens as $chave => $qtd) {
                [$real, $repoe] = array_map('intval', explode('-', $chave));
                $nome  = $qtd === 1 ? self::DOW_NOME[$real] : self::DOW_PLURAL[$real];
                $letivo = $real === 6 ? ($qtd === 1 ? ' letivo' : ' letivos') : '';
                $out[$sem][] = sprintf('%d %s%s com horário de %s', $qtd, $nome, $letivo, self::DOW_NOME[$repoe]);
            }
        }
        ksort($out);
        return $out;
    }

    /** Modelo configurável, com {curso} e {ano} no lugar de placeholders de sprintf. */
    public function titulo(): string
    {
        return strtr(cfg('titulo_modelo'), [
            '{curso}' => (string) $this->cal['curso_nome'],
            '{ano}'   => (string) (int) $this->cal['ano'],
        ]);
    }
}
