<?php
require __DIR__ . '/lib/boot.php';

// Por POST: um GET de logout qualquer <img> numa página aberta em outra aba
// dispararia, e o token conferido em lib/boot.php vale também para cá.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sair();
}
redirect('login.php');
