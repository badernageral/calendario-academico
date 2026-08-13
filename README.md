# Sistema de Calendário Acadêmico

Gera o calendário anual por curso no mesmo formato da planilha usada até agora
(IFTO — Campus Lagoa da Confusão), a partir de um cadastro de cursos, eventos e
períodos. PHP 8 + SQLite, com Bootstrap 5 na interface.

## Como abrir

    http://localhost/calendario-academico/

O banco (`data/calendario.sqlite`) é criado sozinho no primeiro acesso, já com a
legenda oficial e a configuração institucional.

A tela abre no **Painel**, com os números do ano em foco e os atalhos de início
rápido. A navegação fica na barra lateral escura à esquerda — *Painel,
Calendários, Eventos globais, Feriados, Cursos, Níveis, Legenda, Configurações,
Backup*. O botão no topo recolhe a barra para só os ícones, e a escolha fica
guardada no navegador.

A interface usa **Bootstrap 5.3.3 e Bootstrap Icons**, os mesmos do sistema de
Horários e copiados de lá para `assets/vendor/` — servidos do próprio servidor,
sem CDN, então o sistema continua funcionando sem internet. O que é específico
do calendário fica em `assets/app.css`: a barra lateral, os cartões e a tabela
de eventos.

### Permissões

O Apache (`www-data`) precisa escrever em `data/` e em `backups/`:

    setfacl -m u:www-data:rwx data
    setfacl -m u:www-data:rw  data/calendario.sqlite
    setfacl -m u:www-data:rwx -m d:u:www-data:rw backups

As duas pastas ficam dentro da raiz do site, então cada uma tem um `.htaccess`
com `Require all denied` — sem ele, o banco seria baixável pela URL.

### Extensões do PHP

Bastam `pdo_sqlite` e `calendar` (para os feriados móveis). `mbstring` é
opcional — sem ela, um substituto interno cuida das maiúsculas acentuadas.
Instalar `php-mbstring` é recomendado, mas não obrigatório.

## Como se usa

1. **Cursos** — cadastre uma vez. O nome entra no título:
   “CALENDÁRIO DO CURSO *nome* *ano*”. Cada curso tem um **nível de ensino**,
   escolhido entre os cadastrados em **Níveis** — o sistema já vem com Superior,
   Técnico Integrado, Técnico Concomitante e Técnico Subsequente. O nível serve
   para direcionar eventos globais; um nível em uso por algum curso ou evento
   não pode ser excluído, e renomeá-lo não afeta nada, porque o que fica gravado
   é a *chave* (`superior`, `integrado`…), que nasce do nome e não muda mais.
2. **Feriados** — cadastro único, **válido em todos os anos**: nada de inserir
   feriado ano a ano. O de data fixa guarda dia e mês; o **móvel** guarda a
   distância até o domingo de Páscoa, e o sistema calcula a data de cada ano
   (Carnaval `-48` e `-47`, Quarta-feira de Cinzas `-46`, Sexta-feira da Paixão
   `-2`, Corpus Christi `60`). O sistema já vem com os nacionais, os estaduais
   do Tocantins, os pontos facultativos federais e os municipais do campus.
   Corrigir um feriado conserta todos os anos de uma vez, e desmarcar *Ativo*
   tira o feriado de circulação sem apagar o cadastro. Os feriados aparecem na
   grade e na lista de cada mês com a marca *feriado*; eles não são eventos,
   então não se editam nem se apagam por lá — o lápis leva a esta tela.
3. **Eventos globais** — recessos, planejamento e prazos institucionais do ano,
   na mesma grade anual clicável da tela de um
   calendário (sem as linhas de dias letivos, que dependem dos semestres de um
   curso). Valem para todos os calendários daquele ano; as
   caixas *Vale para os níveis* restringem o evento a um ou mais níveis de
   curso. Elas nascem **todas marcadas** — que é o mesmo que sem restrição —, e
   desmarcar é que restringe; a caixa *Todos*, na frente delas, marca e desmarca
   a lista inteira. Os locais, que valem só para um
   calendário, ficam na tela dele. O botão **Excluir os de \<ano\>** limpa de uma
   vez os eventos globais daquele ano — só os globais daquele ano, e nada dos
   feriados, que vêm do cadastro.
4. **Calendários** — um por curso/ano. Ao criar, dá para **copiar os eventos de
   outro calendário**, com as datas deslocadas para o novo ano — é o caminho
   normal na virada de ano.
5. Cada calendário da lista tem três botões. **Editar** abre os dados do
   calendário: situação, local e data, as datas dos dois semestres, as metas de
   dias letivos e as observações que saem no resumo impresso — é para onde o
   sistema leva logo depois de criar o calendário. **Gerenciar** abre a tela de
   eventos, e **Gerar** abre o calendário pronto para impressão.
6. Na tela de **Gerenciar** ficam os eventos específicos daquele curso. Ela
   mostra **a grade do ano inteiro com a mesma cara da impressão** — nome do mês
   em verde, dias pintados pela categoria e a contagem de dias letivos no rodapé
   de cada mês. **Clicar em um dia** abre o
   que cai nele: cada evento com sua categoria, um atalho para editar (os da
   um atalho para editar e o botão *Novo evento neste dia*, que já volta com a
   data preenchida. O cadastro e a alteração de evento acontecem **em um
   modal**: ele abre sozinho quando a página vem de *Editar*, de um dia clicado
   na grade ou do botão *Novo evento*. O formulário é o mesmo das duas telas —
   dentro de um calendário, o campo **Abrangência** decide se o evento é
   *local* (só daquele calendário) ou *global* (todos os do ano), e o mesmo
   campo promove um evento de local para global e vice-versa. Os globais
   aparecem na lista de cada mês com a marca *global*. Os dias com evento levam um ponto
   embaixo do número, para aparecerem mesmo quando a categoria não pinta.
   A legenda fica acima da grade e, **embaixo de cada mês, a lista dos seus
   eventos** — três meses lado a lado, como no papel. Cada linha da lista abre o
   evento para edição e traz um × para excluir; os globais são marcados com *global* e só têm o atalho de edição, já que apagá-los ali
   afetaria todos os calendários do ano. Todo evento entra nessa lista — a mesma
   que sai impressa. Abaixo da grade fica o **resumo dos semestres**: quantas segundas, terças,
   quartas, quintas, sextas e sábados letivos cada semestre tem, o total contra
   a meta e a soma do ano — os mesmos números que saem no papel.
7. **Gerar** — abre o calendário pronto. `Ctrl+P` → A4, paisagem, **gráficos de
   fundo ativados** → PDF.
8. **Configurações** — o que vale para o sistema inteiro, em quatro blocos:
   *Instituição* (órgão do cabeçalho impresso, campus e cidade), *Documento
   gerado* (modelo do título, com `{curso}` e `{ano}`), *Padrões de um calendário
   novo* (situação e meta de dias letivos por semestre, que cada calendário
   ajusta depois) e *Cores fixas do calendário* (dias de segunda a sexta, sábados
   e domingos, faixa do nome do mês e cabeçalho dos dias úteis). O fuso horário
   é fixo em `America/Araguaina`, em `lib/boot.php`.
9. **Backup** — *Baixar backup agora* gera uma cópia consistente do banco
   (`VACUUM INTO`, que já incorpora o WAL), guarda em `backups/` e baixa o
   arquivo. *Importar* faz o caminho inverso: **substitui todos os dados** pelo
   `.sqlite` enviado, depois de conferir a integridade do arquivo e se ele tem
   as tabelas do sistema — o de outro sistema é recusado. Antes de sobrescrever,
   o estado atual é guardado em `backups/pre_import_*.sqlite`. Vale baixar um
   backup antes de homologar o calendário do ano.

As datas do evento não se digitam: o modal traz **um calendário só**, no ano do
evento. O primeiro clique marca o início do período, o segundo marca o fim —
clicar duas vezes no mesmo dia vale como dia único, e enquanto o fim não é
escolhido o intervalo aparece sombreado. Cada período escolhido vira uma linha
ao lado, com um × para remover, e dá para acumular vários: três períodos viram
o rótulo “5 a 8, 20 e 10/2 a 12/2”, como no papel.

Datas aparecem sempre em **dd/mm/aaaa**. As dos semestres usam o seletor nativo
do navegador, que em português mostra dd/mm/aaaa.

## Como o sistema decide as coisas

**A grade e os dias letivos são calculados, não digitados.** Os dias vêm do
próprio ano; a cor de cada dia vem dos eventos que caem nele.

Cada categoria da legenda diz o que faz com o dia:

| Efeito | Significado | Exemplos |
|---|---|---|
| **Não** — nunca conta como letivo | o dia deixa de ser letivo | Feriado (nacional, estadual ou municipal), Férias, Ponto Facultativo, Dias Escolares Não Letivos, Planejamento Pedagógico |
| **Sim** — sempre conta como letivo | o dia passa a ser letivo | um sábado letivo, cadastrado como evento com *Conta como letivo = sim* |
| **Neutro** — o dia da semana decide | só pinta | Exame Final, Culminância, Início/Fim de semestre |

Regras aplicadas na ordem:

1. Fora dos semestres cadastrados, nenhum dia conta.
2. O “não” vence o “sim” (feriado em cima de sábado letivo derruba o dia).
3. Onde todas as categorias do dia são neutras, conta de segunda a sexta.
4. A cor exibida é a da categoria de maior **prioridade** entre os eventos do dia.
   A prioridade que se digita vai de 1 a 95; acima dela ficam as quatro legendas
   de que o sistema depende, com valor fixo e sem exclusão — *Feriado Nacional*
   (99), *Feriado Estadual* (98), *Feriado Municipal* (97) e *Ponto Facultativo*
   (96) —, que sempre vencem a cor do dia, ganhando entre si o de alcance maior.

O dia que nenhum evento pinta fica com a **cor fixa da grade**: uma para segunda
a sexta e outra para sábado e domingo, escolhidas em *Configurações* junto com a
cor da faixa do mês e a do cabeçalho dos dias úteis. Fim de semana não é legenda:
é cor, e a regra de não ser letivo vem do dia da semana, não de uma categoria.

O topo da tela de Gerenciar compara o total calculado com a meta de dias letivos,
e o resumo dos semestres detalha isso por dia da semana — é onde aparece qualquer
divergência.

### Rótulos e reposições

- O prefixo do evento (“14 a 16 e 19 a 20 - …”) é gerado das faixas de datas.
  Um evento pode ter várias faixas, uma por linha. O campo *rótulo* sobrescreve
  quando é preciso fugir do padrão (ex.: “5 e 6” em vez de “5 a 6”).
- O campo *funciona com horário de* gera sozinho o bloco final
  “4 sábados letivos com horário de segunda”.

## Estrutura

    index.php               painel: números do ano e atalhos
    calendarios.php         calendários (criar, copiar de outro ano, excluir)
    editar_calendario.php   dados do calendário: semestres, metas, observações
    calendario.php          grade clicável e eventos do curso
    cursos.php              cadastro de cursos
    niveis.php              cadastro dos níveis de ensino
    configuracoes.php       o que vale para o sistema inteiro
    eventos.php             eventos globais do ano
    feriados.php            cadastro de feriados, válido em todos os anos
    categorias.php          legenda: cores, efeito no cômputo, prioridade
    backup.php              exportar e importar o banco
    gerar.php               saída no formato da planilha (tela e impressão)
    lib/                    db.php, Engine.php (cálculo), util.php, schema.sql,
                            layout.php (barra lateral), grade_calendario.php
                            (a grade anual, usada pelas duas telas),
                            feriados.php (as regras de data dos feriados) e
                            form_evento.php (o modal de evento)
    assets/                 app.css, calendario.css (impressão), vendor/ (Bootstrap)
    data/                   calendario.sqlite
    backups/                cópias geradas pela tela de Backup
    ferramentas/            importar_ods.py — migração da planilha antiga
    desktop/                empacotamento Electron para Windows (main.js,
                            router.php, php.ini e o ícone)
    .github/workflows/      build do instalador no GitHub Actions

## Migrar de uma planilha .ods

    python3 ferramentas/importar_ods.py

Lê a planilha, cria os cursos, separa os eventos comuns às três abas em base
comum e deixa o resto por curso. A classificação por cor é heurística: revise o
resultado na tela antes de usar. Ajuste `ODS`, `DB` e `ANO` no topo do arquivo.

## Aplicativo desktop (Windows)

Além do Apache, o sistema roda como aplicativo instalável, **sem instalar PHP
nem Apache na máquina**: o Electron sobe o servidor embutido do PHP em
`127.0.0.1` dentro de uma janela. Nada muda no código das telas — é a mesma
aplicação.

O instalador é gerado pelo GitHub Actions (workflow *Build Desktop (Windows)*),
que baixa o PHP portátil e empacota tudo. Ele roda quando se empurra uma tag
`v*` — e aí o `.exe` fica anexado à Release — ou manualmente, pela aba Actions.
Os detalhes estão em [`desktop/README.md`](desktop/README.md).

**Onde ficam os dados nesse modo:** em `%APPDATA%\Calendario Academico\`, fora
da pasta de instalação, para uma atualização não levar nada junto. Quem decide
isso são as variáveis de ambiente `CALENDARIO_DB` e `CALENDARIO_BACKUPS`, que o
`desktop/main.js` passa ao PHP; sem elas — no Apache — valem `data/` e
`backups/` do próprio site.
