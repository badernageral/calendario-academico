<?php
declare(strict_types=1);

if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}
// O fuso decide que dia é "hoje": o rodapé do calendário impresso e o ano em
// foco do painel. Fixo porque o IFTO inteiro fica no Tocantins — o campus é
// que muda de instalação para instalação, o fuso não.
date_default_timezone_set('America/Araguaina');

require __DIR__ . '/db.php';
require __DIR__ . '/util.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/feriados.php';   // o motor monta os feriados do ano a partir do cadastro
require __DIR__ . '/Engine.php';
require __DIR__ . '/auth.php';

// Todo POST passa por aqui antes de qualquer tela olhar para ele. É o único
// ponto de conferência do token justamente para não depender de cada tela
// lembrar de fazê-la — e as telas são muitas.
csrfConferir();

/**
 * Portão de entrada. Fica aqui, e não em cada tela, pelo mesmo motivo do token:
 * uma tela nova nasce protegida sem ninguém lembrar de protegê-la.
 *
 * Sem usuário nenhum cadastrado, tudo leva ao cadastro do primeiro — é a
 * primeira abertura do sistema. Com usuários, só a tela de login responde a
 * quem não entrou.
 */
$b_tela = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));

// Pela linha de comando não há portão a guardar: não existe navegador, sessão
// nem tela para onde redirecionar, e quem chega até aqui já tem acesso à
// máquina. É por onde os testes e o importador da planilha entram.
if (PHP_SAPI === 'cli') {
    return;
}

if (!logado()) {
    $b_alvo = semUsuarios(db()) ? 'setup.php' : 'login.php';
    if ($b_tela !== $b_alvo) {
        redirect($b_alvo);
    }
} elseif ($b_tela === 'setup.php') {
    // Já entrou: o cadastro do primeiro usuário não tem mais o que fazer.
    redirect('index.php');
}
unset($b_tela, $b_alvo);
