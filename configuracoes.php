<?php
require __DIR__ . '/lib/boot.php';

$db = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('acao') === 'salvar') {
    $meta = postInt('meta_letivos', 100);

    if ($meta < 1 || $meta > 366) {
        flash('A meta de dias letivos precisa ficar entre 1 e 366.', 'erro');
        redirect('configuracoes.php');
    }
    // O modelo do título sem {curso} sairia igual em todo calendário — deixa
    // passar, mas avisa, porque quase sempre é engano.
    $modelo = post('titulo_modelo') !== '' ? post('titulo_modelo') : cfgPadroes()['titulo_modelo'];

    // As cores vêm de <input type="color">, que só manda #rrggbb — mas um POST
    // à mão poderia mandar qualquer coisa dentro de um style.
    $cor = static function (string $campo) : string {
        $v = post($campo);
        return preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtolower($v) : cfgPadroes()[$campo];
    };

    $valores = [
        'orgao'         => post('orgao'),
        'campus'        => post('campus'),
        'cidade'        => post('cidade'),
        'titulo_modelo' => $modelo,
        'situacao'      => post('situacao'),
        'meta_letivos'  => (string) $meta,
        'cor_dia_util'  => $cor('cor_dia_util'),
        'cor_dia_fds'   => $cor('cor_dia_fds'),
        'cor_mes'       => $cor('cor_mes'),
        'cor_dow'       => $cor('cor_dow'),
    ];
    foreach ($valores as $chave => $valor) {
        cfgSalvar($db, $chave, $valor);
    }

    flash(str_contains($modelo, '{curso}')
        ? 'Configurações salvas.'
        : 'Configurações salvas — atenção: o modelo do título não usa {curso}, então todo calendário sairá com o mesmo título.');
    redirect('configuracoes.php');
}

// Amostra do título com um curso de verdade, para conferir o modelo sem gerar.
$cursoAmostra = (string) ($db->query('SELECT nome FROM cursos ORDER BY ativo DESC, nome LIMIT 1')->fetchColumn()
    ?: 'SUPERIOR EM ENGENHARIA AGRONÔMICA');
$tituloAmostra = strtr(cfg('titulo_modelo'), ['{curso}' => $cursoAmostra, '{ano}' => date('Y')]);

head('Configurações', 'configuracoes');
?>
<form method="post">
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
          <div class="form-text">Abre o “local e data” de um calendário novo: <em><?= e(cfg('cidade')) ?>, <?= e(mesExtenso((int) date('n'))) ?> de <?= date('Y') ?></em>.</div>
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
        <code>{curso}</code> e <code>{ano}</code> são trocados na hora de gerar. Hoje sai:
        <strong><?= e($tituloAmostra) ?></strong>
      </div>
    </div>
  </div>

  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-transparent fw-semibold">
      <i class="bi bi-calendar-plus me-1 text-primary"></i>Padrões de um calendário novo
    </div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-8">
          <label class="form-label">Situação</label>
          <input name="situacao" class="form-control" value="<?= e(cfg('situacao')) ?>">
          <div class="form-text">Aparece no rodapé de cada página impressa até ser trocada no calendário.</div>
        </div>
        <div class="col-md-4">
          <label class="form-label">Meta de dias letivos por semestre</label>
          <input type="number" name="meta_letivos" class="form-control" min="1" max="366"
                 value="<?= (int) cfg('meta_letivos') ?>">
          <div class="form-text">Entra nos dois semestres; cada calendário ajusta o seu depois.</div>
        </div>
      </div>
    </div>
  </div>

  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-transparent fw-semibold">
      <i class="bi bi-palette2 me-1 text-primary"></i>Cores fixas do calendário
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
