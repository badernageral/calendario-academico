<?php
declare(strict_types=1);

/**
 * Autenticação por sessão, com perfil único: quem entra faz tudo.
 *
 * Não há papel de leitura porque o calendário pronto sai por gerar.php, que é a
 * via de quem só quer ver — o resto da aplicação é edição, e quem edita precisa
 * de senha.
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

    $hash = $u ? (string) $u['senha_hash'] : '$2y$12$........................................................';
    $ok   = password_verify($senha, $hash);

    return ($u && $ok && (int) $u['ativo'] === 1) ? $u : null;
}

/** Cadastra um usuário e devolve a linha criada. */
function criarUsuario(PDO $db, string $nome, string $usuario, string $senha): array
{
    $db->prepare('INSERT INTO usuarios (nome, usuario, senha_hash) VALUES (?,?,?)')
       ->execute([$nome, $usuario, password_hash($senha, PASSWORD_DEFAULT)]);
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
