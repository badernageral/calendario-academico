<?php
declare(strict_types=1);

/**
 * Um `.xlsx` mínimo, escrito à mão: workbook, planilhas, mesclas, largura de
 * coluna e um estilo por célula (fundo, cor do texto, negrito, tamanho,
 * centralizado, borda fina). Não é um leitor, nem lida com fórmula, imagem ou
 * formatação numérica — só o que a exportação do calendário precisa.
 *
 * Existe porque o sistema não tem nenhuma dependência de fora (nem Composer),
 * e um `.xlsx` é só um .zip com XML dentro — o `ZipArchive` do próprio PHP
 * escreve o pacote inteiro sem precisar de mais nada.
 */
final class Xlsx
{
    /** @var array<string, array{celulas: array<string, array{v: mixed, t: string, s: int}>, merges: string[], larguras: array<int, float>, alturas: array<int, float>, ordem: int}> */
    private array $planilhas = [];
    private int $proximaOrdem = 0;

    /** @var array<string, int> chave do estilo => índice em cellXfs */
    private array $estiloIndice = [];
    /** @var array<int, array{fontId: int, fillId: int, borderId: int, centro: bool}> */
    private array $cellXfs = [];

    /** @var array<string, int> "negrito:tamanho" => índice */
    private array $fontIndice = [];
    /** @var array<int, array{negrito: bool, tamanho: float, cor: ?string}> */
    private array $fonts = [];

    /** @var array<string, int> ARGB ou 'none' => índice */
    private array $fillIndice = [];
    /** @var array<int, ?string> índice => ARGB, ou null para "sem fundo" */
    private array $fills = [];

    /** @var array<int, bool> índice => tem borda fina */
    private array $borders = [0 => false, 1 => true];

    public function __construct()
    {
        // Índices 0 e 1 de fill são reservados pelo formato (none, gray125) —
        // manter os dois de fora da lista de cores de verdade evita que uma
        // cor escolhida por acaso caia num desses slots e vire outra coisa.
        $this->fillIndice['none']  = 0;
        $this->fills[0]            = null;
        $this->fillIndice['gray125'] = 1;
        $this->fills[1]            = null;

        $this->fontIndice['0:11:'] = 0;
        $this->fonts[0] = ['negrito' => false, 'tamanho' => 11.0, 'cor' => null];

        // Estilo 0: o padrão que toda célula sem `s=` usa.
        $this->estiloIndice['0|0|0|0|0'] = 0;
        $this->cellXfs[0] = ['fontId' => 0, 'fillId' => 0, 'borderId' => 0, 'centro' => false, 'quebra' => false];
    }

    /** Cria a planilha se não existir, e devolve o nome — ele é a própria chave. */
    public function folha(string $nome): string
    {
        if (!isset($this->planilhas[$nome])) {
            $this->planilhas[$nome] = [
                'celulas'  => [],
                'merges'   => [],
                'larguras' => [],
                'alturas'  => [],
                'ordem'    => $this->proximaOrdem++,
            ];
        }
        return $nome;
    }

    /**
     * Registra um estilo (fundo, cor do texto, negrito, tamanho, centralizado,
     * borda fina, quebra de linha automática) e devolve o índice para usar em
     * celula(). Estilos iguais devolvem o mesmo índice — um calendário inteiro
     * usa poucas combinações.
     *
     * @param array{bg?: ?string, cor?: ?string, negrito?: bool, tamanho?: float, centro?: bool, borda?: bool, quebra?: bool} $p
     */
    public function estilo(array $p): int
    {
        $bg      = $p['bg']       ?? null;
        $cor     = $p['cor']      ?? null;
        $negrito = $p['negrito']  ?? false;
        $tamanho = $p['tamanho']  ?? 11.0;
        $centro  = $p['centro']   ?? false;
        $borda   = $p['borda']    ?? false;
        $quebra  = $p['quebra']   ?? false;

        $fontChave = ($negrito ? '1' : '0') . ':' . $tamanho . ':' . ($cor ?? '');
        if (!isset($this->fontIndice[$fontChave])) {
            $id = count($this->fonts);
            $this->fonts[$id] = ['negrito' => $negrito, 'tamanho' => $tamanho, 'cor' => $cor];
            $this->fontIndice[$fontChave] = $id;
        }
        $fontId = $this->fontIndice[$fontChave];

        $argb      = $bg !== null ? $this->argb($bg) : 'none';
        if (!isset($this->fillIndice[$argb])) {
            $id = count($this->fills);
            $this->fills[$id] = $bg !== null ? $argb : null;
            $this->fillIndice[$argb] = $id;
        }
        $fillId = $this->fillIndice[$argb];

        $borderId = $borda ? 1 : 0;

        $chave = "$fontId|$fillId|$borderId|" . ($centro ? '1' : '0') . '|' . ($quebra ? '1' : '0');
        if (!isset($this->estiloIndice[$chave])) {
            $id = count($this->cellXfs);
            $this->cellXfs[$id] = [
                'fontId' => $fontId, 'fillId' => $fillId, 'borderId' => $borderId,
                'centro' => $centro, 'quebra' => $quebra,
            ];
            $this->estiloIndice[$chave] = $id;
        }
        return $this->estiloIndice[$chave];
    }

    /**
     * Estimativa de altura de linha para um texto que vai quebrar sozinho numa
     * célula (ou num intervalo mesclado) de largura conhecida — sem isto, a
     * altura da linha fica no padrão do Excel e o texto quebrado só aparece
     * inteiro depois que alguém manda "Ajustar altura da linha" à mão.
     *
     * A conversão de "unidades de largura de coluna" para caracteres por linha
     * é aproximada — o bastante para não cortar texto, ainda que sobre um
     * pouco de espaço em branco embaixo.
     */
    public static function alturaParaTexto(string $texto, float $largura, float $pontosPorLinha = 11.0): float
    {
        // 1.4 char/unidade veio de medir contra o LibreOffice de verdade: um
        // fator mais folgado (calculado, 1.8) deixava a altura curta demais e
        // uma linha quebrada em duas invadia a linha de baixo.
        $caracteresPorLinha = max(1, (int) round($largura * 1.4));
        $linhas = max(1, (int) ceil(mb_strlen($texto) / $caracteresPorLinha));
        return $linhas * $pontosPorLinha + 4;
    }

    /** Uma célula, texto ou número — `is_numeric` decide o tipo gravado. */
    public function celula(string $folha, int $linha, int $coluna, string|int|float $valor, ?int $estilo = null): void
    {
        $this->folha($folha);
        $ref = self::colLetra($coluna) . $linha;
        $this->planilhas[$folha]['celulas'][$ref] = [
            'v' => $valor,
            't' => is_int($valor) || is_float($valor) ? 'n' : 's',
            's' => $estilo ?? 0,
        ];
    }

    public function mesclar(string $folha, int $linha1, int $col1, int $linha2, int $col2): void
    {
        $this->folha($folha);
        if ($linha1 === $linha2 && $col1 === $col2) {
            return;
        }
        $this->planilhas[$folha]['merges'][] =
            self::colLetra($col1) . $linha1 . ':' . self::colLetra($col2) . $linha2;
    }

    /** Largura em "unidades de caractere", a mesma escala do Excel. */
    public function largura(string $folha, int $coluna, float $unidades): void
    {
        $this->folha($folha);
        $this->planilhas[$folha]['larguras'][$coluna] = $unidades;
    }

    /**
     * A maior altura pedida para a linha, nunca a última: os três meses de um
     * trimestre dividem os mesmos números de linha nas listas de eventos, e
     * cada um pede a altura que o seu próprio texto precisa — o texto mais
     * longo dos três é quem manda, senão o do vizinho mais curto encolhia de
     * volta uma linha que já estava do tamanho certo.
     */
    public function altura(string $folha, int $linha, float $pontos): void
    {
        $this->folha($folha);
        $atual = $this->planilhas[$folha]['alturas'][$linha] ?? 0.0;
        $this->planilhas[$folha]['alturas'][$linha] = max($atual, $pontos);
    }

    /** Coluna 1-indexada para letra: 1=A, 26=Z, 27=AA. */
    public static function colLetra(int $n): string
    {
        $s = '';
        while ($n > 0) {
            $n--;
            $s = chr(65 + $n % 26) . $s;
            $n = intdiv($n, 26);
        }
        return $s;
    }

    /** '#rrggbb' -> 'FFRRGGBB', o ARGB que o OOXML espera. */
    private function argb(string $hex): string
    {
        return 'FF' . strtoupper(ltrim($hex, '#'));
    }

    private static function texto(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** Monta o pacote inteiro e devolve os bytes do .xlsx. */
    public function bytes(): string
    {
        uasort($this->planilhas, static fn ($a, $b) => $a['ordem'] <=> $b['ordem']);
        $nomes = array_keys($this->planilhas);

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml', $this->contentTypes(count($nomes)));
        $zip->addFromString('_rels/.rels', $this->relsRaiz());
        $zip->addFromString('xl/workbook.xml', $this->workbook($nomes));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels(count($nomes)));
        $zip->addFromString('xl/styles.xml', $this->styles());

        $i = 1;
        foreach ($nomes as $nome) {
            $zip->addFromString('xl/worksheets/sheet' . $i . '.xml', $this->sheet($this->planilhas[$nome]));
            $i++;
        }

        $zip->close();
        $conteudo = (string) file_get_contents($tmp);
        unlink($tmp);
        return $conteudo;
    }

    private function contentTypes(int $nSheets): string
    {
        $overrides = '';
        for ($i = 1; $i <= $nSheets; $i++) {
            $overrides .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" '
                . 'ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . $overrides
            . '</Types>';
    }

    private function relsRaiz(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    /** @param string[] $nomes */
    private function workbook(array $nomes): string
    {
        $sheets = '';
        foreach ($nomes as $i => $nome) {
            $id = $i + 1;
            $sheets .= '<sheet name="' . self::texto($nome) . '" sheetId="' . $id . '" r:id="rId' . $id . '"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $sheets . '</sheets>'
            . '</workbook>';
    }

    private function workbookRels(int $nSheets): string
    {
        $rels = '';
        for ($i = 1; $i <= $nSheets; $i++) {
            $rels .= '<Relationship Id="rId' . $i . '" '
                . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" '
                . 'Target="worksheets/sheet' . $i . '.xml"/>';
        }
        $rels .= '<Relationship Id="rIdStyles" '
            . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>';
    }

    private function styles(): string
    {
        $fonts = '';
        foreach ($this->fonts as $f) {
            $cor = $f['cor'] !== null ? '<color rgb="' . $this->argb($f['cor']) . '"/>' : '<color theme="1"/>';
            $fonts .= '<font>' . ($f['negrito'] ? '<b/>' : '') . '<sz val="' . $f['tamanho'] . '"/>' . $cor
                . '<name val="Calibri"/></font>';
        }

        $fills = '';
        foreach ($this->fills as $i => $argb) {
            if ($i === 0) {
                $fills .= '<fill><patternFill patternType="none"/></fill>';
            } elseif ($i === 1) {
                $fills .= '<fill><patternFill patternType="gray125"/></fill>';
            } else {
                $fills .= '<fill><patternFill patternType="solid">'
                    . '<fgColor rgb="' . $argb . '"/><bgColor indexed="64"/></patternFill></fill>';
            }
        }

        $fina    = '<left style="thin"><color indexed="64"/></left><right style="thin"><color indexed="64"/></right>'
                 . '<top style="thin"><color indexed="64"/></top><bottom style="thin"><color indexed="64"/></bottom><diagonal/>';
        $borders = '<border>' . '<left/><right/><top/><bottom/><diagonal/>' . '</border>'
                 . '<border>' . $fina . '</border>';

        $xfs = '';
        foreach ($this->cellXfs as $xf) {
            $partes = [];
            if ($xf['centro']) {
                $partes[] = 'horizontal="center" vertical="center"';
            }
            if ($xf['quebra']) {
                // Alinhado ao topo: sem isto o padrão é embaixo, e uma célula
                // mais alta que o texto sobra em branco por cima dele, em vez
                // de por baixo — o oposto de como se lê uma lista.
                $partes[] = 'vertical="top" wrapText="1"';
            }
            $align = $partes
                ? ' applyAlignment="1"><alignment ' . implode(' ', $partes) . '/></xf>'
                : '/>';
            $xfs .= '<xf numFmtId="0" fontId="' . $xf['fontId'] . '" fillId="' . $xf['fillId'] . '" '
                . 'borderId="' . $xf['borderId'] . '" xfId="0" applyFont="1" applyFill="1" applyBorder="1"' . $align;
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="' . count($this->fonts) . '">' . $fonts . '</fonts>'
            . '<fills count="' . count($this->fills) . '">' . $fills . '</fills>'
            . '<borders count="2">' . $borders . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="' . count($this->cellXfs) . '">' . $xfs . '</cellXfs>'
            . '</styleSheet>';
    }

    /** @param array{celulas: array, merges: string[], larguras: array, alturas: array} $p */
    private function sheet(array $p): string
    {
        $cols = '';
        foreach ($p['larguras'] as $col => $w) {
            $cols .= '<col min="' . $col . '" max="' . $col . '" width="' . $w . '" customWidth="1"/>';
        }

        $porLinha = [];
        foreach ($p['celulas'] as $ref => $c) {
            preg_match('/^([A-Z]+)(\d+)$/', $ref, $m);
            $porLinha[(int) $m[2]][$ref] = $c;
        }
        ksort($porLinha);

        $sheetData = '';
        foreach ($porLinha as $linha => $celulas) {
            $altura = isset($p['alturas'][$linha]) ? ' ht="' . $p['alturas'][$linha] . '" customHeight="1"' : '';
            $sheetData .= '<row r="' . $linha . '"' . $altura . '>';
            uksort($celulas, static fn ($a, $b) => strnatcmp($a, $b));
            foreach ($celulas as $ref => $c) {
                if ($c['t'] === 'n') {
                    $sheetData .= '<c r="' . $ref . '" s="' . $c['s'] . '"><v>' . $c['v'] . '</v></c>';
                } else {
                    $sheetData .= '<c r="' . $ref . '" s="' . $c['s'] . '" t="inlineStr"><is><t xml:space="preserve">'
                        . self::texto((string) $c['v']) . '</t></is></c>';
                }
            }
            $sheetData .= '</row>';
        }

        $merges = '';
        if ($p['merges']) {
            $merges = '<mergeCells count="' . count($p['merges']) . '">';
            foreach ($p['merges'] as $ref) {
                $merges .= '<mergeCell ref="' . $ref . '"/>';
            }
            $merges .= '</mergeCells>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . ($cols !== '' ? '<cols>' . $cols . '</cols>' : '')
            . '<sheetData>' . $sheetData . '</sheetData>'
            . $merges
            . '</worksheet>';
    }
}
