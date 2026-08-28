<?php
declare(strict_types=1);

/**
 * As migrações do banco, em ordem de aplicação.
 *
 * Chave = o nome, que é o que fica gravado na tabela `migracoes` e nunca mais
 * muda; valor = o que fazer. Uma migração roda **uma vez** em cada banco, dentro
 * de uma transação, e o nome é registrado no mesmo commit — ou vai tudo, ou não
 * vai nada.
 *
 * O nome começa com a data para a ordem do arquivo ser a ordem cronológica, que
 * é a que importa quando uma migração depende do que a anterior deixou.
 *
 * Regras para escrever uma:
 *
 * - **Nunca edite nem apague uma que já foi lançada.** Os bancos que já a
 *   rodaram não a rodarão de novo, e o schema deles não acompanha a edição.
 *   Para corrigir, escreva outra.
 * - **Mexa também no `schema.sql`.** Ele é o que uma instalação nova recebe, e
 *   quem nasce dele já entra com todas as migrações marcadas como aplicadas.
 *   Os dois têm de descrever o mesmo banco no fim.
 * - **Não use as funções da aplicação.** Uma migração tem de continuar rodando
 *   daqui a dois anos, e `seed()`, `cfgPadroes()` e afins vão ter mudado até lá.
 *   Escreva o SQL que ela precisa, com os valores da época dentro dela.
 *
 * Exemplo:
 *
 *     '2026_09_15_curso_ganha_turno' => static function (PDO $pdo): void {
 *         $pdo->exec("ALTER TABLE cursos ADD COLUMN turno TEXT NOT NULL DEFAULT 'integral'");
 *     },
 *
 * @return array<string, callable(PDO): void>
 */
function migracoes(): array
{
    return [
        // A primeira instalação em produção nasce do schema.sql, e o histórico
        // começa vazio. As mudanças de schema da 1.0 para cá entram aqui.
    ];
}
