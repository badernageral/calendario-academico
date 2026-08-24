<?php
declare(strict_types=1);

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Maiúsculas com acento, sem depender da extensão mbstring. */
function maiusculas(string $s): string
{
    if (function_exists('mb_strtoupper')) {
        return mb_strtoupper($s, 'UTF-8');
    }
    $acentos = [
        'á' => 'Á', 'à' => 'À', 'â' => 'Â', 'ã' => 'Ã', 'ä' => 'Ä',
        'é' => 'É', 'ê' => 'Ê', 'è' => 'È', 'í' => 'Í', 'ì' => 'Ì', 'î' => 'Î',
        'ó' => 'Ó', 'ô' => 'Ô', 'õ' => 'Õ', 'ò' => 'Ò', 'ö' => 'Ö',
        'ú' => 'Ú', 'ù' => 'Ù', 'û' => 'Û', 'ü' => 'Ü', 'ç' => 'Ç', 'ñ' => 'Ñ',
    ];
    return strtoupper(strtr($s, $acentos));
}

function post(string $k, string $padrao = ''): string
{
    return trim((string) ($_POST[$k] ?? $padrao));
}

function postInt(string $k, ?int $padrao = null): ?int
{
    $v = trim((string) ($_POST[$k] ?? ''));
    return $v === '' ? $padrao : (int) $v;
}

/**
 * Cor de um <input type="color">, que só manda #rrggbb — mas um POST à mão
 * poderia mandar qualquer coisa, e daqui ela sai direto para dentro de um
 * `style`. O que não casa com #rrggbb vira o padrão.
 */
function postCor(string $k, string $padrao): string
{
    $v = post($k);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $v) === 1 ? strtolower($v) : $padrao;
}

function get(string $k, string $padrao = ''): string
{
    return trim((string) ($_GET[$k] ?? $padrao));
}

function getInt(string $k, int $padrao = 0): int
{
    return (int) ($_GET[$k] ?? $padrao);
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

/** A sessão guarda o aviso de uma tela para a outra e o token dos formulários. */
function sessao(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

/**
 * Token que amarra um formulário a esta sessão. Nasce uma vez e vale enquanto
 * ela durar: trocá-lo a cada tela derrubaria a página deixada aberta em outra
 * aba.
 */
function csrfToken(): string
{
    sessao();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** O campo escondido que todo formulário POST leva. */
function csrfCampo(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrfToken()) . '">';
}

/**
 * Confere o token antes de a tela olhar para o POST. Como o sistema não tem
 * login, é isto que impede outra página aberta no mesmo navegador de disparar
 * uma exclusão ou uma importação de backup aqui dentro.
 *
 * Não devolve nada: ou o pedido é legítimo, ou a requisição para aqui. Quem
 * chama é o boot, uma vez, para nenhuma tela poder esquecer.
 */
function csrfConferir(): void
{
    // Na linha de comando (a suíte de testes) não há requisição nenhuma.
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return;
    }

    // POST maior que post_max_size chega com $_POST vazio e sem aviso nenhum —
    // o caminho provável é a importação de um banco grande. Sem esta linha, o
    // usuário levaria a culpa do token no lugar da mensagem certa.
    if ($_POST === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        pararCom('O envio passou do limite do servidor (post_max_size = '
               . ini_get('post_max_size') . '). Suba o limite no php.ini para importar este arquivo.');
    }

    $enviado = (string) ($_POST['_csrf'] ?? '');
    if ($enviado === '' || !hash_equals(csrfToken(), $enviado)) {
        pararCom('Este formulário não confere com a sua sessão. Volte, recarregue a página e tente de novo.');
    }
}

/** Página curta de fim de linha: o pedido não vai ser atendido, e explica por quê. */
function pararCom(string $motivo): never
{
    http_response_code(400);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="pt-BR"><meta charset="utf-8">'
       . '<title>Pedido recusado · Calendário Acadêmico</title>'
       . '<body style="font-family:system-ui,sans-serif;max-width:34rem;margin:4rem auto;padding:0 1rem">'
       . '<h1 style="font-size:1.25rem">Pedido recusado</h1><p>' . e($motivo) . '</p>'
       . '<p><a href="index.php">Voltar ao painel</a></p>';
    exit;
}

function flash(?string $msg = null, string $tipo = 'ok'): ?array
{
    sessao();
    if ($msg !== null) {
        $_SESSION['flash'] = ['msg' => $msg, 'tipo' => $tipo];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

/** Níveis de ensino cadastrados: chave gravada => rótulo mostrado. */
function niveisCurso(): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT chave, nome FROM niveis ORDER BY ordem, nome') as $r) {
            $cache[$r['chave']] = $r['nome'];
        }
    }
    return $cache;
}

/**
 * Chave a partir do nome: "Técnico Integrado" => "tecnico_integrado". É ela que
 * fica gravada nos cursos e nos eventos, então nasce uma vez e não muda mais.
 */
function chaveNivel(string $nome): string
{
    $minusculo = function_exists('mb_strtolower') ? mb_strtolower($nome, 'UTF-8') : strtolower($nome);
    $semAcento = strtr($minusculo, [
        'á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','é'=>'e','ê'=>'e','è'=>'e',
        'í'=>'i','ì'=>'i','î'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ò'=>'o','ö'=>'o',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n',
        // sem mbstring as maiúsculas acentuadas chegam aqui como estão
        'Á'=>'a','À'=>'a','Â'=>'a','Ã'=>'a','É'=>'e','Ê'=>'e','Í'=>'i',
        'Ó'=>'o','Ô'=>'o','Õ'=>'o','Ú'=>'u','Ü'=>'u','Ç'=>'c','Ñ'=>'n',
    ]);
    $chave = preg_replace('/[^a-z0-9]+/', '_', strtolower($semAcento));
    return trim((string) $chave, '_');
}

/**
 * O campo `nivel` do evento guarda zero ou mais níveis separados por vírgula.
 * Vazio/NULL = vale para todos os cursos.
 * @return string[]
 */
function niveisDoEvento(?string $nivel): array
{
    $lista = array_filter(array_map('trim', explode(',', (string) $nivel)));
    return array_values(array_intersect($lista, array_keys(niveisCurso())));
}

/** "Superior, Técnico Integrado" — para mostrar na lista. */
function niveisRotulo(?string $nivel): string
{
    $nomes = niveisCurso();
    return implode(', ', array_map(static fn ($n) => $nomes[$n], niveisDoEvento($nivel)));
}

/**
 * Normaliza o que veio do formulário para gravar no banco. Todos marcados vira
 * NULL — é o mesmo "vale para todos", e assim um nível criado depois também
 * passa a valer para o evento, em vez de ficar de fora para sempre.
 */
function niveisParaBanco(array $marcados): ?string
{
    $todos   = array_keys(niveisCurso());
    $validos = array_values(array_intersect($marcados, $todos));
    return (!$validos || count($validos) === count($todos)) ? null : implode(',', $validos);
}

function dataBr(?string $iso): string
{
    return $iso ? implode('/', array_reverse(explode('-', $iso))) : '';
}

/**
 * O "local e data" com que um calendário novo abre: "Lagoa da Confusão,
 * agosto de 2026". Enquanto a cidade não estiver configurada sai só o mês e o
 * ano, em vez de uma vírgula solta no começo da linha.
 */
function localEData(): string
{
    $quando = mesExtenso((int) date('n')) . ' de ' . date('Y');
    $cidade = cfg('cidade');
    return $cidade === '' ? $quando : $cidade . ', ' . $quando;
}

function mesExtenso(int $m): string
{
    return ['', 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
            'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'][$m];
}

/**
 * Interpreta a caixa de datas de um evento e devolve faixas normalizadas.
 * Aceita, um por linha ou separados por ";":
 *   2026-03-07            -> dia único
 *   2026-03-14..2026-03-16 -> faixa
 *   14-16/03              -> faixa dentro do mês (precisa de $ano)
 *   7/3                   -> dia único
 * @return array<array{inicio:string,fim:string}>
 */
function parseFaixas(string $texto, int $ano): array
{
    $out = [];
    foreach (preg_split('/[;\n\r]+/', $texto) as $bruto) {
        $t = trim($bruto);
        if ($t === '') {
            continue;
        }
        $t = str_replace([' a ', '–', '—'], ['..', '..', '..'], $t);
        $partes = array_map('trim', explode('..', $t, 2));
        $ini = normalizaData($partes[0], $ano);
        $fim = isset($partes[1]) ? normalizaData($partes[1], $ano, $ini) : $ini;
        if ($ini && $fim) {
            $out[] = $fim < $ini ? ['inicio' => $fim, 'fim' => $ini] : ['inicio' => $ini, 'fim' => $fim];
        }
    }
    return $out;
}

function normalizaData(string $t, int $ano, ?string $ref = null): ?string
{
    $t = trim($t);
    if ($t === '') {
        return null;
    }
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $t, $m)) {
        return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
    }
    if (preg_match('#^(\d{1,2})[/.](\d{1,2})(?:[/.](\d{2,4}))?$#', $t, $m)) {
        $a = isset($m[3]) ? (int) $m[3] : $ano;
        if ($a < 100) {
            $a += 2000;
        }
        return sprintf('%04d-%02d-%02d', $a, (int) $m[2], (int) $m[1]);
    }
    // só o dia: herda mês/ano da data de referência
    if (preg_match('/^(\d{1,2})$/', $t, $m) && $ref) {
        return sprintf('%s-%02d', substr($ref, 0, 7), (int) $m[1]);
    }
    return null;
}

/** Devolve o texto que o formulário mostra na caixa de datas, em dd/mm/aaaa. */
function faixasParaTexto(array $faixas): string
{
    $l = [];
    foreach ($faixas as $f) {
        $l[] = $f['inicio'] === $f['fim']
            ? dataBr($f['inicio'])
            : dataBr($f['inicio']) . ' a ' . dataBr($f['fim']);
    }
    return implode("\n", $l);
}

/**
 * Semestres que vieram do formulário, prontos para gravar. As quatro datas são
 * obrigatórias: sem elas o calendário não tem período letivo definido, e a
 * contagem de dias letivos perde o sentido.
 *
 * Devolve [semestres, erro]: com erro preenchido, nada deve ser gravado.
 *
 * @return array{0: array<int, array{0: string, 1: string}>, 1: string}
 */
function semestresDoFormulario(): array
{
    $semestres = [];
    foreach ([1, 2] as $n) {
        $inicio = post("sem{$n}_inicio");
        $fim    = post("sem{$n}_fim");
        if ($inicio === '' || $fim === '') {
            return [[], 'Informe as datas de início e fim dos dois semestres letivos.'];
        }
        if ($fim < $inicio) {
            return [[], "No {$n}º semestre, o fim está antes do início."];
        }
        $semestres[$n] = [$inicio, $fim];
    }
    if ($semestres[2][0] < $semestres[1][1]) {
        return [[], 'O 2º semestre começa antes de o 1º terminar.'];
    }
    return [$semestres, ''];
}

/**
 * Datas dos dois semestres de um calendário, como vêm do formulário. O par
 * vazio apaga o semestre — caminho que só sobra para dados antigos, já que o
 * formulário exige as quatro datas.
 *
 * @param array<int, array{0: string, 1: string}> $semestres numero => [inicio, fim]
 */
function salvarSemestres(PDO $db, int $calendarioId, array $semestres): void
{
    $sel = $db->prepare("SELECT id FROM periodos WHERE calendario_id=? AND tipo='semestre' AND numero=?");
    $up  = $db->prepare('UPDATE periodos SET inicio=?, fim=? WHERE id=?');
    $ins = $db->prepare("INSERT INTO periodos (calendario_id, tipo, numero, inicio, fim) VALUES (?, 'semestre', ?, ?, ?)");
    $del = $db->prepare("DELETE FROM periodos WHERE calendario_id=? AND tipo='semestre' AND numero=?");

    foreach ($semestres as $numero => [$inicio, $fim]) {
        if ($inicio === '' || $fim === '') {
            $del->execute([$calendarioId, $numero]);
            continue;
        }
        $sel->execute([$calendarioId, $numero]);
        $pid = $sel->fetchColumn();
        $pid ? $up->execute([$inicio, $fim, $pid])
             : $ins->execute([$calendarioId, $numero, $inicio, $fim]);
    }
}

function salvarFaixas(PDO $db, int $eventoId, array $faixas): void
{
    $db->prepare('DELETE FROM evento_datas WHERE evento_id = ?')->execute([$eventoId]);
    $st = $db->prepare('INSERT INTO evento_datas (evento_id, inicio, fim) VALUES (?,?,?)');
    foreach ($faixas as $f) {
        $st->execute([$eventoId, $f['inicio'], $f['fim']]);
    }
}
