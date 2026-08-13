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

let phpProc = null;

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

function startPhp() {
  fs.mkdirSync(BACKUPS, { recursive: true });
  return freePort().then((port) => {
    phpProc = spawn(PHP_EXE, ['-c', PHP_INI, '-S', `127.0.0.1:${port}`, '-t', APP_DIR, ROUTER], {
      cwd: PHP_DIR,
      env: {
        ...process.env,
        CALENDARIO_DB:      DB_PATH,
        CALENDARIO_BACKUPS: BACKUPS,
      },
    });
    phpProc.stderr.on('data', (d) => console.log('[php]', d.toString().trim()));
    return port;
  });
}

// ── Ciclo de vida ─────────────────────────────────────────────────
app.whenReady().then(async () => {
  try {
    const port = await startPhp();
    await waitForPort(port);

    const win = new BrowserWindow({
      width: 1280,
      height: 860,
      title: 'Calendário Acadêmico',
      webPreferences: { contextIsolation: true },
    });
    win.setMenuBarVisibility(false);
    win.loadURL(`http://127.0.0.1:${port}/`);
  } catch (e) {
    dialog.showErrorBox('Calendário Acadêmico — erro ao iniciar', String((e && e.message) || e));
    app.quit();
  }
});

function shutdown() { try { if (phpProc) phpProc.kill(); } catch (_) {} }
app.on('window-all-closed', () => { shutdown(); app.quit(); });
app.on('before-quit', shutdown);
process.on('exit', shutdown);
