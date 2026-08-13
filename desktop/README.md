# Calendário Acadêmico — desktop (Windows)

Empacota o sistema como aplicativo **offline, por máquina**, sem instalar
Apache nem PHP. O Electron sobe o **servidor embutido do PHP** servindo a
própria aplicação, e o banco continua sendo um arquivo **SQLite**.

> Não afeta a instalação no Apache. Cada máquina tem o seu banco, criado na
> primeira execução a partir do `lib/schema.sql`, já com a legenda, os feriados
> e a configuração institucional.

## Onde ficam os dados

Fora da pasta de instalação, para uma atualização não levar nada junto:

    %APPDATA%\Calendario Academico\calendario.sqlite
    %APPDATA%\Calendario Academico\backups\

O `main.js` passa esses caminhos para o PHP nas variáveis `CALENDARIO_DB` e
`CALENDARIO_BACKUPS`; sem elas — no Apache — o sistema usa `data/` e `backups/`
do próprio site.

## Binário a colocar em `desktop/runtime/` (não versionado)

**PHP portátil (Windows, x64, Thread Safe)** → `runtime/php/`, de
<https://windows.php.net/download> (php-8.3.x-Win32-vs16-x64.zip).

Garanta em `runtime/php/ext/`:

- `php_pdo_sqlite.dll` — o banco;
- `php_calendar.dll` — `easter_date()`, base dos feriados móveis;
- `php_mbstring.dll` — acentuação.

Copie também o `desktop/php.ini` para `runtime/php/php.ini`, que é quem habilita
as três.

## Rodar em desenvolvimento

```
cd desktop
npm install
npm start
```

## Gerar o instalador

```
npm run dist     # gera desktop/dist/Calendario-Academico-Setup-<versão>.exe
```

No GitHub isso é automático: o workflow **Build Desktop (Windows)** baixa o PHP
portátil, empacota e publica o `.exe` como artefato. Empurrando uma tag `v*`, o
instalador é anexado à Release correspondente.
