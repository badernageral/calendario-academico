<?php
/**
 * O molde das duas telas de porta — login e primeiro acesso. Elas ficam fora do
 * layout normal: sem barra lateral, sem menu, sem nada que só faça sentido para
 * quem já entrou.
 *
 * Espera: $titulo, $subtitulo, $corpo (HTML do formulário).
 */
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titulo) ?> · Calendário Acadêmico</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📅</text></svg>">
<link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
<link href="assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
<link href="assets/app.css?v=<?= (int) @filemtime(APP_ROOT . '/assets/app.css') ?>" rel="stylesheet">
</head>
<body class="tela-acesso">
<main class="cartao-acesso">
  <div class="text-center text-white mb-4">
    <i class="bi bi-calendar2-week-fill fs-1"></i>
    <h1 class="h4 fw-bold mt-2 mb-0">Calendário Acadêmico</h1>
    <small class="text-white-50"><?= e($subtitulo) ?></small>
  </div>

  <div class="card border-0 shadow">
    <div class="card-body p-4">
      <?php $f = flash(); if ($f): ?>
        <div class="alert alert-<?= $f['tipo'] === 'erro' ? 'danger' : 'success' ?> py-2 small" role="alert">
          <?= e($f['msg']) ?>
        </div>
      <?php endif; ?>
      <?= $corpo ?>
    </div>
  </div>
</main>
</body>
</html>
