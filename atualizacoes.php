<?php
require __DIR__ . '/lib/boot.php';

$status = atualizacaoStatus();

head('Atualizações', 'atualizacoes');
?>

<div class="d-flex align-items-center mb-3">
  <h5 class="mb-0 fw-semibold"><i class="bi bi-arrow-repeat me-2 text-primary"></i>Atualizações</h5>
</div>

<div class="row g-3">
  <div class="col-md-6">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-transparent fw-semibold">
        <i class="bi bi-pc-display me-1"></i>Versão instalada
      </div>
      <div class="card-body">
        <span class="fs-4 fw-bold">v<?= e($status['versao_atual']) ?></span>
      </div>
    </div>
  </div>

  <div class="col-md-6">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-transparent fw-semibold">
        <i class="bi bi-cloud me-1"></i>Última versão no repositório
      </div>
      <div class="card-body">
        <?php if ($status['tag'] === null): ?>
          <span class="text-muted"><i class="bi bi-exclamation-triangle me-1"></i>Não foi possível consultar o GitHub agora.</span>
        <?php else: ?>
          <span class="fs-4 fw-bold">v<?= e($status['versao_disponivel']) ?></span>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="card border-0 shadow-sm mt-3">
  <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-2">
    <div>
      <?php if ($status['tag'] === null): ?>
        <span class="badge text-bg-secondary"><i class="bi bi-question-circle me-1"></i>Verificação indisponível</span>
      <?php elseif ($status['atualizado']): ?>
        <span class="badge text-bg-success"><i class="bi bi-check-circle me-1"></i>Você está com a versão mais recente</span>
      <?php else: ?>
        <span class="badge text-bg-warning"><i class="bi bi-arrow-up-circle me-1"></i>Nova versão disponível: v<?= e($status['versao_disponivel']) ?></span>
      <?php endif; ?>
      <?php if ($status['verificado_em']): ?>
        <div class="small text-muted mt-2">
          Última verificação: <?= date('d/m/Y H:i', $status['verificado_em']) ?>
        </div>
      <?php endif; ?>
    </div>
    <a href="<?= e($status['url_releases']) ?>" target="_blank" rel="noopener" class="btn btn-primary">
      <i class="bi bi-box-arrow-up-right me-1"></i>Ver lista de releases no GitHub
    </a>
  </div>
</div>

<?php foot(); ?>
