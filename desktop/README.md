# Calendário Acadêmico — desktop (Windows)

Empacota o sistema como aplicativo **offline, por máquina**, sem instalar
Apache nem PHP. O Electron sobe o **servidor embutido do PHP** servindo a
própria aplicação, e o banco continua sendo um arquivo **SQLite**.

> Não afeta a instalação no Apache. Cada máquina tem o seu banco, criado na
> primeira execução a partir do `lib/schema.sql`, já com a legenda, os feriados
> e a configuração institucional.

## Onde ficam os dados

Fora da pasta de instalação, para uma atualização não levar nada junto:

    %APPDATA%\calendario-academico-desktop\calendario.sqlite
    %APPDATA%\calendario-academico-desktop\backups\
    %APPDATA%\calendario-academico-desktop\php.log

O nome da pasta vem do `name` do `package.json`, não do `productName` — é
assim que o Electron escolhe a pasta de dados. Mudar o `name` mudaria a pasta,
e quem atualizasse abriria com o banco vazio.

O `main.js` passa esses caminhos para o PHP nas variáveis `CALENDARIO_DB` e
`CALENDARIO_BACKUPS`; sem elas — no Apache — o sistema usa `data/` e `backups/`
do próprio site.

## Quando algo dá errado

O `php.log`, recriado a cada abertura, guarda tudo o que o PHP escreveu: o
servidor, os avisos e os erros. Um erro fatal também aparece na própria janela,
com a mensagem e o caminho do log (o `router.php` cuida disso), e uma falha ao
subir o PHP abre uma caixa de erro com o final do log. Na janela, **Ctrl+Shift+I**
abre as ferramentas do desenvolvedor.

## Binário a colocar em `desktop/runtime/` (não versionado)

**PHP portátil (Windows, x64, Thread Safe)** → `runtime/php/`, de
<https://windows.php.net/download> (php-8.3.x-Win32-vs16-x64.zip).

Garanta em `runtime/php/ext/`:

- `php_pdo_sqlite.dll` — o banco;
- `php_mbstring.dll` — acentuação.

Copie também o `desktop/php.ini` para `runtime/php/php.ini`, que é quem habilita
as duas. A extensão **calendar** (`easter_date()`, base dos feriados móveis) não
precisa de linha nem de DLL: no Windows ela vem compilada dentro do PHP.

## Rodar em desenvolvimento

```
cd desktop
npm install
npm start
```

## Teste de fumaça

```
node fumaca.js                      # app deste repositório, php do PATH
node fumaca.js dist/win-unpacked    # o pacote gerado pelo npm run dist
```

Sobe o PHP como o `main.js` sobe, com o banco numa pasta com acento e espaço, e
percorre o primeiro acesso até o painel logado. O workflow roda a segunda forma
no Windows antes de publicar o instalador.

## Gerar o instalador

```
npm run dist     # gera desktop/dist/Calendario-Academico-Setup-<versão>.exe
```

No GitHub isso é automático: o workflow **Build Desktop (Windows)** baixa o PHP
portátil, empacota e publica o `.exe` como artefato. Empurrando uma tag `v*`, o
instalador é anexado à Release correspondente.
