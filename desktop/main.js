/**
 * Calendário Acadêmico — orquestrador Electron desktop (Windows).
 *
 * Sem servidor de banco e sem Apache: o Electron sobe o servidor embutido do
 * PHP servindo a própria aplicação, e o SQLite é um arquivo em %APPDATA%.
 *
 * O banco não é criado aqui: a aplicação faz isso sozinha no primeiro acesso
 * (lib/db.php cria a partir do schema.sql e semeia legenda, feriados e
 * configuração). Aqui só se aponta onde ele deve ficar — fora da pasta de
 * instalação, para uma atualização não levar os dados junto.
 *
 * Ao fechar, o processo do PHP é encerrado; o SQLite não precisa de shutdown.
 *
 * Tudo o que o PHP escreve (stdout e stderr) vai para php.log, na mesma pasta
 * dos dados: num aplicativo de janela no Windows não há console, e sem o
 * arquivo um erro do PHP não deixaria rastro nenhum.
 */
const { app, BrowserWindow, dialog } = require('electron');
const { spawn } = require('child_process');
const net  = require('net');
const path = require('path');
const fs   = require('fs');

// ── Layout (dev vs. empacotado) ───────────────────────────────────
const PACKAGED = app.isPackaged;
const ROOT     = PACKAGED ? process.resourcesPath : __dirname;
const APP_DIR  = PACKAGED ? path.join(ROOT, 'app') : path.join(__dirname, '..');
const ROUTER   = path.join(ROOT, 'router.php');
const PHP_DIR  = path.join(ROOT, 'runtime', 'php');
const PHP_EXE  = path.join(PHP_DIR, 'php.exe');
const PHP_INI  = path.join(PHP_DIR, 'php.ini');

// Dados do usuário (graváveis), preservados entre atualizações.
const DADOS    = app.getPath('userData');
const DB_PATH  = path.join(DADOS, 'calendario.sqlite');
const BACKUPS  = path.join(DADOS, 'backups');
const LOG_PATH = path.join(DADOS, 'php.log');

let phpProc = null;
let log     = null;

// Cada abertura recomeça o arquivo: o que interessa é o que aconteceu agora.
function abrirLog() {
  fs.mkdirSync(DADOS, { recursive: true });
  log = fs.createWriteStream(LOG_PATH, { flags: 'w' });
  log.write(`[${new Date().toISOString()}] ${PHP_EXE}\n`);
}

function registrar(prefixo, d) {
  const texto = d.toString();
  console.log(prefixo, texto.trim());
  if (log) log.write(texto.endsWith('\n') ? texto : texto + '\n');
}

// As últimas linhas do log, para a caixa de erro já dizer o que houve.
function finalDoLog(linhas = 15) {
  try {
    return fs.readFileSync(LOG_PATH, 'utf8').trim().split(/\r?\n/).slice(-linhas).join('\n');
  } catch (_) { return ''; }
}

function falhaAoIniciar(e) {
  const fim = finalDoLog();
  dialog.showErrorBox(
    'Calendário Acadêmico — erro ao iniciar',
    String((e && e.message) || e) + (fim ? '\n\n' + fim : '') + '\n\nRegistro completo: ' + LOG_PATH
  );
  app.quit();
}

// ── Utilidades ────────────────────────────────────────────────────
function freePort() {
  return new Promise((resolve, reject) => {
    const srv = net.createServer();
    srv.unref();
    srv.on('error', reject);
    srv.listen(0, '127.0.0.1', () => {
      const port = srv.address().port;
      srv.close(() => resolve(port));
    });
  });
}

function waitForPort(port, timeoutMs = 15000) {
  const deadline = Date.now() + timeoutMs;
  return new Promise((resolve, reject) => {
    (function attempt() {
      const sock = net.connect(port, '127.0.0.1');
      sock.on('connect', () => { sock.destroy(); resolve(); });
      sock.on('error', () => {
        sock.destroy();
        if (Date.now() > deadline) return reject(new Error('O PHP não respondeu na porta ' + port));
        setTimeout(attempt, 250);
      });
    })();
  });
}

/**
 * Sobe o PHP e resolve com a porta quando ele já atende. Se o processo nem
 * chegar a abrir — php.exe ausente, DLL faltando — ou morrer antes de atender,
 * rejeita na hora, em vez de esperar o prazo da porta esgotar.
 */
function startPhp() {
  fs.mkdirSync(BACKUPS, { recursive: true });
  return freePort().then((port) => new Promise((resolve, reject) => {
    phpProc = spawn(PHP_EXE, ['-c', PHP_INI, '-S', `127.0.0.1:${port}`, '-t', APP_DIR, ROUTER], {
      cwd: PHP_DIR,
      env: {
        ...process.env,
        CALENDARIO_DB:      DB_PATH,
        CALENDARIO_BACKUPS: BACKUPS,
        CALENDARIO_LOG:     LOG_PATH,
      },
      windowsHide: true,
    });
    // O stdout também precisa ser lido: um pipe cheio e ninguém lendo trava o PHP.
    phpProc.stdout.on('data', (d) => registrar('[php]', d));
    phpProc.stderr.on('data', (d) => registrar('[php]', d));
    phpProc.on('error', (e) => reject(new Error('Não foi possível executar o PHP: ' + e.message)));
    phpProc.on('exit', (codigo) => {
      registrar('[php]', `processo encerrado (código ${codigo})`);
      phpProc = null;
      reject(new Error(`O PHP encerrou ao iniciar (código ${codigo}).`));
    });
    waitForPort(port).then(() => resolve(port), reject);
  }));
}

// Página de erro dentro da própria janela, para quando ela já está aberta.
function paginaDeErro(win, titulo, detalhe) {
  const h = (t) => String(t).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const html = '<!doctype html><meta charset="utf-8"><title>Erro</title>'
    + '<body style="font-family:system-ui,sans-serif;max-width:52rem;margin:3rem auto;padding:0 1rem;color:#222">'
    + `<h1 style="font-size:1.4rem">${h(titulo)}</h1>`
    + `<pre style="white-space:pre-wrap;background:#f4f4f4;padding:1rem;border-radius:6px">${h(detalhe)}</pre>`
    + `<p>Registro completo: <code>${h(LOG_PATH)}</code></p></body>`;
  win.loadURL('data:text/html;charset=utf-8,' + encodeURIComponent(html));
}

// ── Ciclo de vida ─────────────────────────────────────────────────
app.whenReady().then(async () => {
  let port;
  try {
    abrirLog();
    port = await startPhp();
  } catch (e) {
    return falhaAoIniciar(e);
  }

  const win = new BrowserWindow({
    width: 1280,
    height: 860,
    title: 'Calendário Acadêmico',
    webPreferences: { contextIsolation: true },
  });
  win.setMenuBarVisibility(false);

  // -3 é navegação abortada (um redirect, um download): não é falha.
  win.webContents.on('did-fail-load', (_ev, codigo, descricao, url, principal) => {
    if (!principal || codigo === -3 || url.startsWith('data:')) return;
    paginaDeErro(win, 'Não foi possível abrir a página',
      `${descricao} (${codigo})\n${url}` + (phpProc ? '' : '\n\nO PHP não está mais em execução.\n\n' + finalDoLog()));
  });

  win.loadURL(`http://127.0.0.1:${port}/`);
});

function shutdown() { try { if (phpProc) phpProc.kill(); } catch (_) {} }
app.on('window-all-closed', () => { shutdown(); app.quit(); });
app.on('before-quit', shutdown);
process.on('exit', shutdown);
