<?php
declare(strict_types=1);

if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}
// O fuso decide que dia é "hoje": o rodapé do calendário impresso e o ano em
// foco do painel. O sistema é de um campus só, então ele é fixo.
date_default_timezone_set('America/Araguaina');

require __DIR__ . '/db.php';
require __DIR__ . '/util.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/feriados.php';   // o motor monta os feriados do ano a partir do cadastro
require __DIR__ . '/Engine.php';
