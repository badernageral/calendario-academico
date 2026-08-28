<?php
require __DIR__ . '/lib/boot.php';

$db = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('acao') === 'salvar') {
    // O modelo do título sem {curso} sairia igual em todo calendário — deixa
    // passar, mas avisa, porque quase sempre é engano.
    $modelo = post('titulo_modelo') !== '' ? post('titulo_modelo') : cfgPadroes()['titulo_modelo'];

    // Cor que não seja #rrggbb cai no padrão de fábrica — a peneira está em
    // postCor(), a mesma que a tela de legenda usa.
    $cor = static fn (string $campo): string => postCor($campo, cfgPadroes()[$campo]);

    $valores = [
        'texto_inicio_semestre' => post('texto_inicio_semestre'),
        'texto_fim_bimestre'    => post('texto_fim_bimestre'),
        'texto_inicio_bimestre' => post('texto_inicio_bimestre'),
        'texto_fim_semestre'    => post('texto_fim_semestre'),
        // Caixa desmarcada não é enviada, e é isso que a apaga.
        'negrito_periodo'       => isset($_POST['negrito_periodo']) ? '1' : '0',
        'orgao'         => post('orgao'),
        'campus'        => post('campus'),
        'cidade'        => post('cidade'),
        'titulo_modelo' => $modelo,
        'situacao'      => post('situacao'),
        'cor_dia_util'  => $cor('cor_dia_util'),
        'cor_dia_fds'   => $cor('cor_dia_fds'),
        'cor_mes'       => $cor('cor_mes'),
        'cor_dow'       => $cor('cor_dow'),
    ];
    foreach ($valores as $chave => $valor) {
        cfgSalvar($db, $chave, $valor);
    }

    // As cores das quatro categorias de feriado moram aqui, e não na tela de
    // Legenda: elas são automáticas — quem as aplica é o cadastro de feriados,
    // não o formulário de evento —, e a cor é a única coisa delas que se
    // ajusta. A cor do texto não se escolhe: sai da luminância do fundo, para
    // um vermelho escuro não virar número ilegível dentro do quadrado.
    $enviadas = (array) ($_POST['cor_automatica'] ?? []);
    $up = $db->prepare('UPDATE categorias SET cor = ?, cor_texto = ? WHERE id = ? AND protegida = 1');
    foreach (categoriasAutomaticas($db) as $cat) {
        $nova = corValida((string) ($enviadas[(int) $cat['id']] ?? ''), (string) $cat['cor']);
        $up->execute([$nova, corDeTexto($nova), (int) $cat['id']]);
    }

    flash(str_contains($modelo, '{curso}')
        ? 'Configurações salvas.'
        : 'Configurações salvas — atenção: o modelo do título não usa {curso}, então todo calendário sairá com o mesmo título.');
    redirect('configuracoes.php');
}

// Amostra do título com um curso de verdade, para conferir o modelo sem gerar.
$amostra = $db->query('SELECT nome, nivel FROM cursos ORDER BY ativo DESC, nome LIMIT 1')->fetch()
    ?: ['nome' => 'AGRONOMIA', 'nivel' => (string) array_key_first(niveisCurso())];
$tituloAmostra = strtr(cfg('titulo_modelo'), Engine::trocasDoTitulo(
    (string) $amostra['nome'], (string) $amostra['nivel'], (int) date('Y')
));

head('Configurações', 'configuracoes');
?>
<form method="post">
  <?= csrfCampo() ?>
  <input type="hidden" name="acao" value="salvar">

  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-transparent fw-semibold">
      <i class="bi bi-building me-1 text-primary"></i>Instituição
    </div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-12">
          <label class="form-label">Órgão (cabeçalho do documento)</label>
          <textarea name="orgao" class="form-control" rows="3"><?= e(cfg('orgao')) ?></textarea>
          <div class="form-text">Uma linha por linha impressa, no topo de toda página gerada.</div>
        </div>
        <div class="col-md-7">
          <label class="form-label">Campus</label>
          <input name="campus" class="form-control" value="<?= e(cfg('campus')) ?>">
          <div class="form-text">Sai abaixo do órgão no documento e no canto superior direito das telas.</div>
        </div>
        <div class="col-md-5">
          <label class="form-label">Cidade</label>
          <input name="cidade" class="form-control" value="<?= e(cfg('cidade')) ?>">
          <div class="form-text">Abre o “local e data” de um calendário novo: <em><?= e(localEData()) ?></em>.</div>
        </div>
      </div>
    </div>
  </div>

  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-transparent fw-semibold">
      <i class="bi bi-printer me-1 text-primary"></i>Documento gerado
    </div>
    <div class="card-body">
      <label class="form-label">Modelo do título</label>
      <input name="titulo_modelo" class="form-control" value="<?= e(cfg('titulo_modelo')) ?>">
      <div class="form-text">
        <code>{curso}</code>, <code>{nivel}</code> e <code>{ano}</code> são trocados na hora de
        gerar; o nível sai em maiúsculas, como o resto do título. Hoje sai:
        <strong><?= e($tituloAmostra) ?></strong>
      </div>
    </div>
  </div>

  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-transparent fw-semibold">
      <i class="bi bi-calendar-plus me-1 text-primary"></i>Padrão de um calendário novo
    </div>
    <div class="card-body">
      <label class="form-label">Situação</label>
      <input name="situacao" class="form-control" value="<?= e(cfg('situacao')) ?>">
      <div class="form-text">Aparece no rodapé de cada página impressa até ser trocada no calendário.</div>
    </div>
  </div>

  <?php
  // As automáticas são as categorias que o sistema aplica sozinho. Elas se
  // dividem em duas famílias com donos diferentes — o cadastro de Feriados e as
  // datas de cada calendário —, e por isso em dois quadros.
  $g_automaticas = categoriasAutomaticas($db);
  $g_marco       = $g_automaticas[CAT_SEMESTRE] ?? null;
  $g_deFeriado   = array_intersect_key($g_automaticas, array_flip(nomesDeFeriado()));

  /** Um seletor de cor de categoria automática, do jeito que os dois quadros usam. */
  $g_campoCor = static function (array $cat, string $nome, string $ajuda): void { ?>
    <div class="col-md-3">
      <label class="form-label" for="cor_automatica_<?= (int) $cat['id'] ?>"><?= e($nome) ?></label>
      <input type="color" name="cor_automatica[<?= (int) $cat['id'] ?>]" id="cor_automatica_<?= (int) $cat['id'] ?>"
             class="form-control form-control-color w-100" value="<?= e($cat['cor']) ?>">
      <div class="form-text"><?= $ajuda ?></div>
    </div>
  <?php };
  ?>

  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-transparent fw-semibold">
      <i class="bi bi-bookmark-star me-1 text-primary"></i>Semestre/bimestre (evento automático)
    </div>
    <div class="card-body">
      <p class="small text-muted">
        Nos dias de início e fim de cada bimestre o sistema escreve sozinho uma linha na lista do
        mês e no calendário impresso, e pinta o dia. As datas saem de cada calendário; a cor, o
        peso da letra e o texto saem daqui.
      </p>
      <div class="row g-3 align-items-start">
        <?php if ($g_marco): $g_campoCor($g_marco, 'Cor do dia', 'Prioridade ' . (int) $g_marco['prioridade']
            . ' — vence a cor do dia sobre as de alcance menor. A cor do texto acompanha o fundo sozinha.'); ?>
        <?php endif; ?>
        <div class="col-md-9">
          <label class="form-label d-block">Peso da letra</label>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="negrito_periodo" id="negrito_periodo"
                   <?= cfg('negrito_periodo') === '1' ? 'checked' : '' ?>>
            <label class="form-check-label" for="negrito_periodo">Descrição do evento em negrito</label>
          </div>
          <div class="form-text">
            Vale na lista de cada mês, na tela e no papel. Desmarcado, elas saem com o mesmo peso
            dos outros eventos.
          </div>
        </div>
      </div>

      <hr class="my-4">

      <p class="small text-muted">
        <strong>A descrição de cada marco.</strong>
        Trocas: <code>{ano}</code>, <code>{semestre}</code> (1 ou 2) e <code>{bimestre}</code>
        — 1 a 4 no curso anual, 1 ou 2 por semestre no semestral.
        Campo vazio não gera o evento daquele dia.
      </p>
      <div class="row g-3">
        <?php foreach ([
            ['texto_inicio_semestre', 'Dia que abre um semestre',  'O primeiro dia do 1º e do 3º bimestre.'],
            ['texto_fim_bimestre',    'Fim de bimestre',           'O último dia do 1º e do 3º bimestre.'],
            ['texto_inicio_bimestre', 'Início de bimestre',        'O primeiro dia do 2º e do 4º bimestre.'],
            ['texto_fim_semestre',    'Dia que fecha um semestre', 'O último dia do 2º e do 4º bimestre.'],
        ] as [$g_chave, $g_rotulo, $g_ajuda]): ?>
        <div class="col-md-6">
          <label class="form-label" for="<?= $g_chave ?>"><?= e($g_rotulo) ?></label>
          <input name="<?= $g_chave ?>" id="<?= $g_chave ?>" class="form-control"
                 value="<?= e(cfg($g_chave)) ?>">
          <div class="form-text"><?= e($g_ajuda) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-transparent fw-semibold">
      <i class="bi bi-flag me-1 text-primary"></i>Feriados
    </div>
    <div class="card-body">
      <p class="small text-muted">
        A cor de cada tipo, aplicada pelo cadastro em <a href="feriados.php">Feriados</a>. Os quatro
        não se criam nem se editam na tela de Legenda — nome e prioridade são fixos —, mas saem na
        legenda do calendário impresso. Dando a mesma cor aos três primeiros, a legenda impressa
        junta os três numa linha só, <em>Feriado</em>.
      </p>
      <div class="row g-3">
        <?php foreach ($g_deFeriado as $g_nome => $g_cat): ?>
          <?php $g_campoCor($g_cat, $g_nome, 'Prioridade ' . (int) $g_cat['prioridade'] . ' — vence a cor do dia '
              . ((int) $g_cat['prioridade'] === 99 ? 'sobre todas as outras' : 'sobre as de alcance menor') . '.'); ?>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-transparent fw-semibold">
      <i class="bi bi-palette2 me-1 text-primary"></i>Cores gerais da grade
    </div>
    <div class="card-body">
      <p class="small text-muted">
        As cores da grade que não vêm da legenda. Um dia com evento é pintado pela categoria dele;
        estas valem para o resto — e para o cabeçalho e a faixa do mês, sempre.
      </p>
      <div class="row g-3">
        <?php foreach ([
            ['cor_dia_util', 'Dias de segunda a sexta', 'O fundo do quadrado quando nada o pinta.'],
            ['cor_dia_fds',  'Sábados e domingos',      'A coluna inteira: quadrados, cabeçalho e sobras do mês.'],
            ['cor_mes',      'Faixa do nome do mês',    'A tarja com JANEIRO, FEVEREIRO…'],
            ['cor_dow',      'Cabeçalho dos dias úteis', 'A linha com D S T Q Q S S, nas colunas de segunda a sexta.'],
        ] as [$g_chave, $g_rotulo, $g_ajuda]): ?>
        <div class="col-md-3">
          <label class="form-label" for="<?= $g_chave ?>"><?= e($g_rotulo) ?></label>
          <input type="color" name="<?= $g_chave ?>" id="<?= $g_chave ?>"
                 class="form-control form-control-color w-100" value="<?= e(cfg($g_chave)) ?>">
          <div class="form-text"><?= e($g_ajuda) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <button class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Salvar configurações</button>
</form>

<?php foot(); ?>
