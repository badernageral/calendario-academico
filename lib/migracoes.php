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
        // O <textarea> do HTML manda \r\n, e nada tirava o \r antes de gravar.
        // Quem lê esses campos parte por \n, então cada linha ficava com um \r
        // no fim — dentro do cabeçalho do calendário impresso, inclusive. A
        // entrada já foi corrigida em post(); isto limpa o que ficou gravado.
        '2026_08_28_fim_de_linha_sem_cr' => static function (PDO $pdo): void {
            $pdo->exec("UPDATE config      SET valor       = replace(valor,       char(13) || char(10), char(10))
                         WHERE valor       LIKE '%' || char(13) || '%'");
            $pdo->exec("UPDATE calendarios SET observacoes = replace(observacoes, char(13) || char(10), char(10))
                         WHERE observacoes LIKE '%' || char(13) || '%'");
        },

        // A chave nasceu depois deste banco. cfg() cai no padrão quando ela
        // falta, então nada estava errado na tela — mas um banco novo tinha a
        // linha e este não, e duas instalações iguais têm de ter o mesmo banco.
        '2026_08_28_config_ganha_negrito_periodo' => static function (PDO $pdo): void {
            $pdo->exec("INSERT OR IGNORE INTO config (chave, valor) VALUES ('negrito_periodo', '1')");
        },

        // A ordem da legenda pulava o 9: Planejamento em 10 e o marco de período
        // em 11. Não mudava nada na tela — a ordenação é relativa —, mas deixava
        // um buraco que a próxima categoria a entrar herdaria. Só mexe em quem
        // ainda está no valor de fábrica, para não desfazer uma ordem escolhida
        // à mão.
        '2026_08_28_ordem_das_categorias_sem_buraco' => static function (PDO $pdo): void {
            $pdo->exec("UPDATE categorias SET ordem = 9
                         WHERE nome = 'Planejamento Pedagógico' AND ordem = 10");
            $pdo->exec("UPDATE categorias SET ordem = 10
                         WHERE nome = 'Início ou Fim de semestre/bimestre letivo' AND ordem = 11");
        },
    ];
}
