<?php
require __DIR__ . '/lib/boot.php';

/**
 * Os dados e as oito datas do calendário viraram um modal, aberto de dentro da
 * grade: é olhando o ano desenhado que se confere onde cada bimestre começa e
 * termina. Esta tela virou o caminho até lá, para links guardados e para o
 * botão "Editar" da lista de calendários continuarem levando a algum lugar.
 */
$id = getInt('id') ?: postInt('cal_id', 0);
redirect('calendario.php?id=' . $id . '&editar_cal=1');
