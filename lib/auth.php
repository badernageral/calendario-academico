<?php
declare(strict_types=1);

/**
 * Autenticação por sessão, com perfil único: quem entra faz tudo.
 *
 * Não há papel de leitura, e gerar.php não é exceção: ele carrega o mesmo
 * lib/boot.php que as outras telas, então nem o calendário pronto se alcança sem
 * sessão. Quem só quer ver recebe o PDF impresso, não o endereço.
 *
 * O portão fica em lib/boot.php, que toda tela carrega antes de qualquer coisa.
 */

/** Há alguém logado nesta sessão? */
function logado(): bool
{
    sessao();
    return !empty($_SESSION['usuario_id']);
}

/** O usuário da sessão: id, nome e login. Null se não há sessão aberta. */
function usuarioAtual(): ?array
{
    sessao();
    return $_SESSION['usuario'] ?? null;
}

/** Nenhum usuário cadastrado = primeira abertura do sistema. */
function semUsuarios(PDO $db): bool
{
    return (int) $db->query('SELECT COUNT(*) FROM usuarios')->fetchColumn() === 0;
}

/**
 * Abre a sessão para um usuário.
 *
 * O id da sessão é regenerado: sem isso, um id que o atacante tenha plantado no
 * navegador antes do login continuaria valendo depois dele.
 */
function entrar(array $u): void
{
    sessao();
    session_regenerate_id(true);
    $_SESSION['usuario_id'] = (int) $u['id'];
    $_SESSION['usuario']    = [
        'id'      => (int) $u['id'],
        'nome'    => (string) $u['nome'],
        'usuario' => (string) $u['usuario'],
    ];
}

/** Fecha a sessão inteira, e não só as chaves do usuário. */
function sair(): void
{
    sessao();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/**
 * Confere login e senha. Devolve a linha do usuário, ou null.
 *
 * O password_verify roda mesmo quando o login não existe, contra um hash de
 * mentira: sem isso a resposta voltaria na hora para um usuário inexistente e
 * depois de uns milissegundos para um que existe, e a diferença diria quais
 * logins são válidos.
 */
function autenticar(PDO $db, string $usuario, string $senha): ?array
{
    $st = $db->prepare('SELECT * FROM usuarios WHERE usuario = ? LIMIT 1');
    $st->execute([$usuario]);
    $u = $st->fetch();

    $hash = $u ? (string) $u['senha_hash'] : hashDeMentira($db);
    $ok   = password_verify($senha, $hash);

    return ($u && $ok && (int) $u['ativo'] === 1) ? $u : null;
}

/**
 * O hash contra o qual se confere a senha de um login que não existe. Ele só
 * serve para gastar o mesmo tempo que um hash de verdade gastaria — nenhuma
 * senha casa com ele.
 *
 * O custo sai do prefixo (`$2y$NN$`) de um hash que está mesmo no banco, e não
 * de um número escrito aqui. Escrito à mão ele envelhece: o custo padrão do
 * password_hash() subiu de 10 para 12 no PHP 8.4, e um dummy fixo em 12 sobre um
 * banco gravado no 8.3 respondia em ~134 ms para o login inexistente contra
 * ~33 ms para o que existe — quatro vezes mais lento, justamente a diferença que
 * este hash existe para apagar. E o desnível não some com a atualização do PHP:
 * o hash gravado guarda o custo da época para sempre.
 *
 * Sem usuário nenhum não há login a proteger — a tela é a do primeiro acesso —,
 * e aí vale o padrão de hoje.
 *
 * Sem cache de propósito: é a leitura de uma linha, ruído perto dos 30 a 130 ms
 * do password_verify que vem logo em seguida, e um valor guardado entre bancos
 * diferentes responderia pelo custo do banco errado.
 */
function hashDeMentira(PDO $db): string
{
    $qualquer = (string) ($db->query('SELECT senha_hash FROM usuarios LIMIT 1')->fetchColumn() ?: '');
    $prefixo  = preg_match('/^(\$2[aby]\$\d{2}\$)/', $qualquer, $m) === 1
        ? $m[1]
        : substr((string) password_hash('', PASSWORD_DEFAULT), 0, 7);

    // 53 caracteres depois do prefixo é o tamanho de um bcrypt: 22 de sal e 31
    // de digest. O ponto está no alfabeto do sal, então o hash é bem-formado e o
    // password_verify faz a conta inteira em vez de recusar de saída.
    return $prefixo . str_repeat('.', 53);
}

/**
 * Cadastra um usuário e devolve a linha criada.
 *
 * Nasce ativo, e o `ativo` vai explícito em vez de sair do DEFAULT da coluna: o
 * que a aplicação grava fica dito aqui, e não escondido no schema. Desativar é
 * coisa da edição — a caixa "Ativo" nem aparece no cadastro de um usuário novo,
 * porque criar alguém já desligado não é caso que exista.
 *
 * $ativo existe para o teste poder criar um inativo sem mexer no banco à mão.
 */
function criarUsuario(PDO $db, string $nome, string $usuario, string $senha, bool $ativo = true): array
{
    $db->prepare('INSERT INTO usuarios (nome, usuario, senha_hash, ativo) VALUES (?,?,?,?)')
       ->execute([$nome, $usuario, password_hash($senha, PASSWORD_DEFAULT), $ativo ? 1 : 0]);
    $st = $db->prepare('SELECT * FROM usuarios WHERE id = ?');
    $st->execute([(int) $db->lastInsertId()]);
    return (array) $st->fetch();
}

/**
 * Quantos usuários ainda poderiam entrar se este saísse de cena — excluído ou
 * desativado. Zero significa que ele é o último, e deixá-lo sair trancaria o
 * sistema para todo mundo, sem ninguém do lado de dentro para reabrir.
 */
function outrosUsuariosAtivos(PDO $db, int $exceto): int
{
    $st = $db->prepare('SELECT COUNT(*) FROM usuarios WHERE ativo = 1 AND id <> ?');
    $st->execute([$exceto]);
    return (int) $st->fetchColumn();
}

/** Troca os dados de um usuário. Senha vazia = a que ele já tem fica. */
function salvarUsuario(PDO $db, int $id, string $nome, string $usuario, string $senha, bool $ativo): void
{
    if ($senha === '') {
        $db->prepare('UPDATE usuarios SET nome=?, usuario=?, ativo=? WHERE id=?')
           ->execute([$nome, $usuario, $ativo ? 1 : 0, $id]);
        return;
    }
    $db->prepare('UPDATE usuarios SET nome=?, usuario=?, ativo=?, senha_hash=? WHERE id=?')
       ->execute([$nome, $usuario, $ativo ? 1 : 0, password_hash($senha, PASSWORD_DEFAULT), $id]);
}
