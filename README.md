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

O Apache (`www-data`) precisa escrever em `data/` e em `backups/` — nas pastas,
não só nos arquivos: o SQLite em modo WAL cria um `-wal` e um `-shm` ao lado do
banco, e a tela de Backup grava cópias novas.

    sudo setfacl -m u:www-data:rwx -m d:u:www-data:rw data
    sudo setfacl -m u:www-data:rwx -m d:u:www-data:rw backups

O `-d` é o que faz um arquivo criado depois já nascer gravável — inclusive o
próprio `data/calendario.sqlite`, que **não existe numa instalação nova**: ele
nasce no primeiro acesso. Só se você trouxe um banco pronto de outra máquina é
que vale ajustar o arquivo direto:

    sudo setfacl -m u:www-data:rw data/calendario.sqlite

**Não abra o banco com outro usuário.** O SQLite roda em modo WAL, e abrir
`data/calendario.sqlite` — mesmo só para ler, mesmo com um `php -r` ou um
`sqlite3` — cria um `-wal` e um `-shm` ao lado dele, sob *quem abriu*. Se não
foi o `www-data`, ele passa a não conseguir escrever nesses dois arquivos, e o
site inteiro vira somente-leitura: as telas abrem normalmente, mas qualquer
gravação morre com `attempt to write a readonly database` e a página fica
indisponível. O sintoma engana, porque ler continua funcionando.

Para conferir, e para consertar quando acontecer:

    ls -l data/calendario.sqlite*        # os três têm de ser do www-data
    sudo rm -f data/calendario.sqlite-wal data/calendario.sqlite-shm

Apagar os dois é seguro **com o site parado ou ocioso**: o `-shm` é só índice em
memória compartilhada, e o `-wal` costuma estar vazio — confira o tamanho antes.
O `www-data` recria os dois, como ele mesmo, na requisição seguinte. Se precisar
mesmo olhar o banco pela linha de comando, faça como ele:

    sudo -u www-data sqlite3 data/calendario.sqlite

As duas pastas ficam dentro da raiz do site, então cada uma tem um `.htaccess`
com `Require all denied` — sem ele, o banco seria baixável pela URL. Pelo mesmo
motivo há um em `lib/`, em `ferramentas/` e em `testes/`: são a mesma lista que
o `desktop/router.php` nega no modo desktop, e sem eles o Apache entregaria o
`schema.sql`, o importador da planilha e a suíte.

**O `.htaccess` só vale se o Apache aceitar.** A configuração precisa ter
`AllowOverride All` no diretório que contém o site; com `AllowOverride None` —
que é como o Debian e o Ubuntu entregam `/var/www/` — os arquivos são
simplesmente ignorados, e `data/calendario.sqlite` volta a ser baixável pela
URL. Vale conferir depois de instalar:

    curl -so /dev/null -w '%{http_code}\n' http://localhost/calendario-academico/data/calendario.sqlite

O esperado é `403`. Se vier `200`, o banco inteiro está aberto: ajuste o
`AllowOverride` ou tire `data/` e `backups/` da raiz do site, apontando
`CALENDARIO_DB` e `CALENDARIO_BACKUPS` para fora dela.

### Quem pode usar

**O sistema não tem login: quem alcança a URL faz tudo.** Cadastra, altera,
exclui, baixa o banco inteiro pela tela de Backup e, pela mesma tela, substitui
todos os dados por um arquivo enviado. É uma decisão de escopo — um campus, um
punhado de pessoas montando o calendário do ano —, não um esquecimento; mas ela
transfere a segurança inteira para a rede.

Então o Apache **não pode estar exposto à internet**. Sirva o sistema só na rede
interna, ou ponha algo na frente: um `Require ip` no virtual host, uma
autenticação básica do próprio Apache (`AuthType Basic`), ou uma VPN. No modo
desktop nada disso se aplica — ali o PHP escuta em `127.0.0.1` e só a máquina
local alcança.

O que o sistema faz por conta própria é impedir que **outra** página aberta no
mesmo navegador dispare uma ação aqui dentro: todo formulário leva um token de
sessão, conferido em `lib/boot.php` antes de qualquer tela olhar para o POST.
Isso protege contra o pedido forjado de fora, não contra quem simplesmente abre
a URL — para esse, a barreira é a rede.

### Extensões do PHP

Bastam `pdo_sqlite` e `calendar` (para os feriados móveis). `mbstring` é
opcional — sem ela, um substituto interno cuida das maiúsculas acentuadas.
Instalar `php-mbstring` é recomendado, mas não obrigatório.

No Debian e no Ubuntu, `calendar` já vem compilada no pacote base do PHP, mas
**`pdo_sqlite` não**: ela mora num pacote à parte, que não entra por padrão.
Sem ela toda tela devolve 500, e o log do Apache diz
`PDOException: could not find driver`. É o tropeço mais provável numa máquina
nova, porque `php` e `libapache2-mod-php` instalam sem reclamar:

    sudo apt-get install -y php-sqlite3 acl

Para conferir o que está carregado — as três precisam aparecer:

    php -m | grep -E '^(pdo_sqlite|calendar|mbstring)$'


## Como se usa

1. **Cursos** — cadastre uma vez. O nome entra no título do documento, junto do
   nível: “CALENDÁRIO DO CURSO *nível* EM *nome* / *ano*”, pelo modelo em
   *Configurações*. Como o nível já entra por conta própria, **o nome do curso
   deve trazer só o curso** — `ENGENHARIA AGRONÔMICA`, não
   `SUPERIOR EM ENGENHARIA AGRONÔMICA`, que sairia repetido no cabeçalho. Cada curso tem um **nível de ensino**,
   escolhido entre os cadastrados em **Níveis** — o sistema já vem com Superior,
   Técnico Integrado, Técnico Concomitante e Técnico Subsequente. O nível serve
   para direcionar eventos globais; um nível em uso por algum curso ou evento
   não pode ser excluído, e renomeá-lo não afeta nada, porque o que fica gravado
   é a *chave* (`superior`, `integrado`…), que nasce do nome e não muda mais.
   O campo **Disciplinas** diz se elas duram um semestre ou o ano inteiro
   (*Semestral* ou *Anual*); um curso novo nasce semestral, e os cadastrados
   antes deste campo existir entraram assim na migração. Por enquanto ele é
   informativo — aparece na lista de cursos e não altera a contagem de dias
   letivos, que continua saindo dos dois semestres do calendário.
2. **Feriados** — cadastro único, **válido em todos os anos**: nada de inserir
   feriado ano a ano. O de data fixa guarda dia e mês; o **móvel** guarda a
   distância até o domingo de Páscoa, e o sistema calcula a data de cada ano
   (Carnaval `-48` e `-47`, Quarta-feira de Cinzas `-46`, Sexta-feira da Paixão
   `-2`, Corpus Christi `60`). O sistema já vem com os nacionais, os estaduais
   do Tocantins e os pontos facultativos federais — o que vale igual em todo
   campus. Os **municipais não vêm**, porque mudam de cidade para cidade: cada
   campus cadastra os seus aqui, com o **tipo** *Feriado Municipal*. Junto com
   *campus* e *cidade* em **Configurações**, é o que uma instalação nova pede
   antes de qualquer outra coisa.
   Cada feriado tem um **tipo** — *Feriado Nacional*, *Estadual*, *Municipal* ou
   *Ponto Facultativo* —, que é a origem da norma e sai impressa depois do nome
   (“25 - Natal - Feriado Nacional”). É o tipo que decide a prioridade na cor do
   dia; a **cor de cada tipo se escolhe em *Configurações***, não aqui e não na
   tela de Legenda.
   As **emendas** — a segunda antes de um feriado de terça, a sexta depois de
   Corpus Christi — também entram aqui, como *Ponto Facultativo*. Cuidado com o
   que este cadastro é: uma regra que vale para **todos os anos**. A portaria
   anual do MGI declara as emendas ano a ano (em 2026, 20/4 e 5/6), então uma
   emenda cadastrada como data fixa vai reaparecer em 2027, quando o feriado cai
   noutro dia da semana e emenda nenhuma foi declarada — nesse ano, é excluí-la.
   Corrigir um feriado conserta todos os anos de uma vez.
   A data fixa se escolhe num **calendário de um mês**, sem ano: o que se grava
   é dia e mês, e fevereiro sempre mostra 29 dias, que é como se cadastra um
   feriado que só existe em ano bissexto. O *tipo* — a origem da norma — sai num
   dropdown que **mostra a cor de cada um**, a mesma do campo de categoria do
   evento: é o tipo que decide a cor do dia na grade.
   Os feriados aparecem na grade e na lista de cada mês com a marca *feriado*, e
   **se alteram de qualquer uma das três telas** que mostram a grade do ano — o
   formulário abre em modal ali mesmo, sem trocar de tela. Excluir, não: isso é
   só aqui, porque um feriado sai dos calendários de todos os anos.
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
   As datas que se digitam são as dos **quatro bimestres**, oito ao todo, e
   **os semestres saem delas**: o 1º semestre vai do início do 1º bimestre ao
   fim do 2º, e o 2º do início do 3º ao fim do 4º. O intervalo entre o 2º e o
   3º é o recesso do meio do ano, fora de qualquer semestre — e por isso não
   conta dia letivo, como sempre foi. As oito datas vêm sugeridas e acompanham
   a troca do ano.
   Como os campos se chamam depende do **regime do curso** (o campo
   *Disciplinas*): no anual eles correm de *1º* a *4º bimestre*; no semestral
   cada semestre tem o seu *1º* e *2º*, e o rótulo diz de qual semestre se
   trata. Trocar o curso no formulário troca os rótulos na hora — as datas
   ficam onde estão, porque o que muda é só o nome de cada campo.
   Um calendário criado antes dos bimestres existirem tem só os dois semestres:
   ao abri-lo em *Editar*, a tela avisa e sugere partir cada semestre ao meio.
5. Cada calendário da lista tem três botões. **Editar** abre os dados do
   calendário: situação, local e data, as datas dos dois semestres, as metas de
   dias letivos e as observações que saem no resumo impresso — é para onde o
   sistema leva logo depois de criar o calendário. **Gerenciar** abre a tela de
   eventos, e **Gerar** abre o calendário pronto para impressão.
6. Na tela de **Gerenciar** ficam os eventos específicos daquele curso. Ela
   mostra **a grade do ano inteiro com a mesma cara da impressão** — nome do mês
   em verde, dias pintados pela categoria e a contagem de dias letivos no rodapé
   de cada mês. **Clicar em um dia** abre o que cai nele: cada evento com sua
   categoria e um atalho para editar — inclusive os feriados, que abrem o
   formulário do cadastro em modal, sem sair daqui — e o botão *Novo evento
   neste dia*, que já volta com a data preenchida. O cadastro e a alteração de evento acontecem **em um
   modal**: ele abre sozinho quando a página vem de *Editar*, de um dia clicado
   na grade ou do botão *Novo evento*. O formulário é o mesmo das duas telas —
   dentro de um calendário, o campo **Abrangência** decide se o evento é
   *local* (só daquele calendário) ou *global* (todos os do ano), e o mesmo
   campo promove um evento de local para global e vice-versa. Os globais
   aparecem na lista de cada mês com a marca *global*. Os dias com evento levam um ponto
   embaixo do número, para aparecerem mesmo quando a categoria não pinta.
   A legenda fica acima da grade e, **embaixo de cada mês, a lista dos seus
   eventos** — três meses lado a lado, como no papel. Cada linha da lista abre o
   evento para edição e traz um × para excluir. Cada tela apaga o que é dela: o
   × aparece no evento *local* aqui, no *global* na tela de Eventos globais e no
   *feriado* no cadastro de Feriados — apagar um global daqui afetaria todos os
   calendários do ano, e um feriado, todos os anos. Editar é de qualquer tela.
   O marco de bimestre não tem ×, e o clique nele abre os **dados do
   calendário** — que é de onde ele sai. Todo evento entra nessa lista — a mesma
   que sai impressa. As quatro caixas de *Exibir* — *Feriados*, *Eventos
   globais*, *Eventos locais* e *Automáticos* — tiram cada tipo da vista sem mexer na
   contagem de dias letivos, que continua contando com tudo. O que o servidor
   recusar ao salvar aparece **dentro do próprio modal**, junto do que foi
   digitado, e não no topo da página, atrás dele.
   **Passar o mouse numa linha acende, na grade, os dias daquele evento** — uma
   lâmina azul translúcida por cima da célula, com o número em branco e negrito.
   Um evento de 26 dias marca as 26 células de uma vez, que é o que torna
   visível o que a lista só diz por escrito (“5 a 30”). A lâmina é escura de
   propósito: assim o realce vale igual sobre qualquer cor de categoria, do
   branco do dia útil ao vermelho do feriado. O realce acompanha o foco do
   teclado também, para quem navega por Tab.
   Cada linha leva uma **etiqueta de origem**, colorida para se achar de
   relance: *feriado* em vermelho (vem do cadastro e vale para todos os anos),
   *global* em verde (vale para todos os calendários do ano) e *local* em azul
   (só deste calendário). Os marcos de bimestre levam *automático*, em cinza,
   porque não são origem: são escritos pelo sistema. Nas telas de *Eventos
   globais* e de *Feriados* a etiqueta não aparece — ali tudo é do mesmo tipo,
   e ela só repetiria a mesma palavra em toda linha. Abaixo da grade fica o **resumo dos semestres e bimestres**: quantas segundas,
   terças, quartas, quintas, sextas e sábados letivos cada período tem, com os
   dois bimestres recuados sob o semestre a que pertencem, e a soma do ano
7. **Gerar** — abre o calendário pronto. `Ctrl+P` → A4, paisagem, **gráficos de
   fundo ativados** → PDF.
8. **Configurações** — o que vale para o sistema inteiro, em seis blocos:
   *Instituição* (órgão do cabeçalho impresso, campus e cidade), *Documento
   gerado* (modelo do título, com `{curso}`, `{nivel}` e `{ano}`), *Padrão de um
   calendário novo* (a situação, que cada calendário ajusta depois),
   *Eventos automáticos* (tudo sobre as linhas que o sistema escreve nos dias de
   início e fim de bimestre: a cor do dia, o negrito e os quatro textos),
   *Feriados* (a cor de cada um dos quatro tipos, aplicada pelo cadastro de
   Feriados) e *Cores gerais da grade* (dias de segunda a sexta, sábados e
   domingos, faixa do nome do mês, cabeçalho dos dias úteis).

   Nas cinco categorias automáticas — as quatro de feriado e a de início e fim de
   período — a cor é a única coisa ajustável: nome e prioridade são fixos, e a cor
   do texto acompanha o fundo sozinha, para um fundo escuro não deixar o número
   ilegível. O fuso horário é fixo em `America/Araguaina`, em `lib/boot.php` — o
   IFTO inteiro fica no Tocantins.
   **Numa instalação nova, *campus* e *cidade* vêm como `CAMPUS MIDGARD` e
   `Midgard`** — valores de exemplo, e a primeira coisa a trocar. O órgão já vem
   certo, porque é o mesmo em todo o IFTO; o campus, não, e ele sai impresso no
   cabeçalho do documento. Troque antes de gerar o primeiro calendário: ninguém
   quer descobrir o campus errado num calendário já homologado.
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
| **Neutro** — o dia da semana decide | só pinta | Exame Final, Culminância |

Regras aplicadas na ordem:

1. Fora dos semestres — que saem dos bimestres —, nenhum dia conta.
2. O “não” vence o “sim” (feriado em cima de sábado letivo derruba o dia).
3. Onde todas as categorias do dia são neutras, conta de segunda a sexta.
4. A cor exibida é a da categoria de maior **prioridade** entre os eventos do dia.
   A prioridade que se digita vai de 1 a 95; acima dela ficam as quatro legendas
   de que o sistema depende, com valor fixo e sem exclusão — *Feriado Nacional*
   (99), *Feriado Estadual* (98), *Feriado Municipal* (97) e *Ponto Facultativo*
   (96) —, que sempre vencem a cor do dia, ganhando entre si o de alcance maior.
   Essas quatro **nem aparecem na tela de Legenda**: não se criam, não se
   excluem e não se renomeiam — o nome é a identidade com que o cadastro de
   feriados as encontra, e a prioridade é fixa. A única coisa ajustável nelas é
   a cor, em *Configurações*. Também não aparecem para escolher num evento, já
   que quem as aplica é o cadastro de feriados. Mas **continuam saindo na
   legenda impressa**.

### Os marcos de bimestre, escritos pelo sistema

Nos oito dias de início e fim de bimestre o sistema escreve sozinho uma linha na
lista do mês — a mesma que sai impressa —, em negrito, se assim se quiser:

    2  - Início do 1º semestre e 1º bimestre letivo de 2026/1
    17 - Fim do 1º Bimestre
    20 - Início do 2º Bimestre
    30 - Fim do 2º Bimestre e Fim do 1º Semestre letivo 2026/1

O primeiro bimestre de cada semestre abre o semestre e o último o fecha, e nesses
dois dias o texto fala das duas coisas. Os **quatro modelos** ficam em
*Configurações*, junto da caixa que decide o **negrito** das quatro, com as
trocas `{ano}`, `{semestre}` (1 ou 2) e `{bimestre}` — o
número do bimestre como ele se chama naquele curso. Esvaziar um modelo tira
aquela linha do calendário.

Esses dias são pintados pela legenda *Início ou Fim de semestre/bimestre
letivo*, cuja **cor se escolhe em Configurações**, junto com as dos feriados.
Ela é **neutra**: o primeiro e o último dia de aula continuam contando pela
regra do dia da semana, e um feriado em cima deles vence a cor, por ter
prioridade maior.

Os marcos **não são eventos**: não entram na conta de eventos do calendário, não
se editam e não se apagam. Quem manda neles são as datas dos bimestres e o
modelo do texto.

O dia que nenhum evento pinta fica com a **cor fixa da grade**: uma para segunda
a sexta e outra para sábado e domingo, escolhidas em *Configurações* junto com a
cor da faixa do mês e a do cabeçalho dos dias úteis. A de segunda a sexta fecha a
legenda — impressa e da tela — como *Representação de Dia Letivo*: é a cor mais
frequente do calendário, e sem essa linha a legenda explicaria todas as outras
menos a do dia de aula normal.

Na legenda, **nacional, estadual e municipal viram uma linha só, *Feriado*,
enquanto as três estiverem na mesma cor** — que é como o sistema as entrega.
Três linhas com o mesmo vermelho não explicam nada, e a página é apertada; o que
distingue as três é a origem da norma, e isso continua dito na lista do mês
(“25 - Natal - Feriado Nacional”). Dar cor própria a uma delas em *Configurações*
traz as três de volta separadas, que é quando a distinção passa a valer no papel. Fim de semana não é legenda:
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
    editar_calendario.php   caminho para o modal de dados do calendário
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
                            (a grade anual, usada pelas três telas),
                            feriados.php (as regras de data dos feriados),
                            form_evento.php, form_feriado.php e
                            form_calendario.php (os modais, os mesmos nas telas
                            que os abrem), eventos_crud.php, feriados_crud.php e
                            calendario_crud.php (o POST de cada um),
                            campos_bimestres.php (as oito datas, nas duas telas
                            que as pedem) e valida_bimestres.php (as mesmas
                            regras no navegador)
    assets/                 app.css, calendario.css (impressão), vendor/ (Bootstrap)
    data/                   calendario.sqlite
    backups/                cópias geradas pela tela de Backup
    ferramentas/            importar_ods.py — migração da planilha antiga
    testes/                 executar.php — a suíte do motor; telas.php — abre
                            todas as páginas e cobra log limpo
    desktop/                empacotamento Electron para Windows (main.js,
                            router.php, php.ini e o ícone)
    .github/workflows/      build do instalador e a suíte no GitHub Actions

## Testes

    php testes/executar.php
    php testes/telas.php

A primeira cobre o motor — a única parte do sistema que decide alguma coisa
sozinha: a contagem de dias letivos, a precedência entre categorias, o feriado
que derruba o sábado letivo, os feriados móveis, o alcance por nível de ensino,
os rótulos de data que saem no papel e as oito datas que chegam do formulário.

A segunda é de fumaça: sobe o servidor embutido sobre um banco temporário, abre
as quinze telas e cobra HTTP 200 com o log do PHP limpo. Ela existe porque a
suíte do motor não abre página nenhuma — e foi assim que um `require` fora de
ordem (500 no cadastro de feriados) e um SELECT sem a coluna `regime` (bimestres
de curso anual rotulados como semestral) passaram por `php -l` e pela suíte sem
ninguém notar.

Cada teste monta o cenário num banco temporário, criado do próprio `schema.sql`
com o seed de fábrica; a base do site não é tocada. O GitHub Actions roda as
duas e um `php -l` em todo arquivo a cada push.

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
