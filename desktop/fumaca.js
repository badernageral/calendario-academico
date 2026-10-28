/**
 * Teste de fumaça do modo desktop: sobe o PHP do jeito que o main.js sobe e
 * percorre o primeiro acesso — painel, cadastro do primeiro usuário, painel
 * logado — cobrando a página certa em cada passo e o log sem erro do PHP.
 *
 *     node fumaca.js                      # desenvolvimento: php do PATH, app em ../
 *     node fumaca.js dist/win-unpacked    # o que vai no instalador
 *
 * Existe porque a versão Windows chegou a abrir em branco sem nenhum teste
 * acusar: a suíte roda no Linux, sobre o PHP do sistema, e nunca tinha aberto
 * o pacote. Daí o Node, e não PHP: é o mesmo spawn do Electron, com o ambiente
 * passado do mesmo jeito. E daí o banco numa pasta com acento e espaço, como a
 * de um usuário "José" no Windows.
 */
const { spawn } = require('child_process');
const net  = require('net');
const path = require('path');
const fs   = require('fs');
const os   = require('os');

const pacote = process.argv[2] ? path.resolve(process.argv[2]) : null;
const RES    = pacote ? path.join(pacote, 'resources') : null;
const PHP    = pacote ? path.join(RES, 'runtime', 'php', process.platform === 'win32' ? 'php.exe' : 'php') : 'php';
const INI    = pacote ? ['-c', path.join(RES, 'runtime', 'php', 'php.ini')] : [];
const APP    = pacote ? path.join(RES, 'app') : path.join(__dirname, '..');
const ROUTER = pacote ? path.join(RES, 'router.php') : path.join(__dirname, 'router.php');

const DADOS = fs.mkdtempSync(path.join(os.tmpdir(), 'calendario-'));
const PASTA = path.join(DADOS, 'Usuário José', 'Calendario Academico');
fs.mkdirSync(path.join(PASTA, 'backups'), { recursive: true });
const LOG = path.join(PASTA, 'php.log');

const falhas = [];
function conferir(ok, oque, detalhe = '') {
  console.log(`  ${ok ? '✓' : '✗'} ${oque}${ok || !detalhe ? '' : ' — ' + detalhe}`);
  if (!ok) falhas.push(oque);
  return ok;
}

function portaLivre() {
  return new Promise((resolve, reject) => {
    const srv = net.createServer();
    srv.on('error', reject);
    srv.listen(0, '127.0.0.1', () => { const p = srv.address().port; srv.close(() => resolve(p)); });
  });
}

async function esperarPorta(porta, proc) {
  for (let i = 0; i < 60; i++) {
    if (proc.exitCode !== null) throw new Error(`o PHP encerrou (código ${proc.exitCode})`);
    const ok = await new Promise((r) => {
      const s = net.connect(porta, '127.0.0.1');
      s.on('connect', () => { s.destroy(); r(true); });
      s.on('error', () => { s.destroy(); r(false); });
    });
    if (ok) return;
    await new Promise((r) => setTimeout(r, 250));
  }
  throw new Error('o PHP não atendeu na porta ' + porta);
}

let cookie = '';
async function pedir(base, url, campos) {
  const opcoes = { redirect: 'manual', headers: {} };
  if (cookie) opcoes.headers.Cookie = cookie;
  if (campos) {
    opcoes.method = 'POST';
    opcoes.body = new URLSearchParams(campos).toString();
    opcoes.headers['Content-Type'] = 'application/x-www-form-urlencoded';
  }
  const r = await fetch(base + url, opcoes);
  const c = r.headers.get('set-cookie');
  if (c) cookie = c.split(';')[0];
  return { codigo: r.status, local: r.headers.get('location') || '', corpo: await r.text() };
}

// O começo do texto visível — o que aparece na tela, para o erro dizer o que veio.
const resumo = (html) => html.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 300) || '(corpo vazio)';

(async () => {
  console.log(`PHP:   ${PHP}\nApp:   ${APP}\nDados: ${PASTA}\n`);
  const porta = await portaLivre();
  const saida = fs.openSync(LOG, 'w');
  const proc = spawn(PHP, [...INI, '-S', `127.0.0.1:${porta}`, '-t', APP, ROUTER], {
    cwd: pacote ? path.dirname(PHP) : APP,
    env: {
      ...process.env,
      CALENDARIO_DB:      path.join(PASTA, 'calendario.sqlite'),
      CALENDARIO_BACKUPS: path.join(PASTA, 'backups'),
      CALENDARIO_LOG:     LOG,
    },
    stdio: ['ignore', saida, saida],
  });

  try {
    await esperarPorta(porta, proc);
    const base = `http://127.0.0.1:${porta}`;

    let r = await pedir(base, '/');
    conferir(r.codigo === 302 && r.local === 'setup.php', '/ leva ao primeiro acesso', `HTTP ${r.codigo} ${r.local} ${resumo(r.corpo)}`);

    r = await pedir(base, '/setup.php');
    conferir(r.codigo === 200 && r.corpo.includes('Primeiro acesso'), 'a tela de primeiro acesso abre', `HTTP ${r.codigo}: ${resumo(r.corpo)}`);
    const token = (r.corpo.match(/name="_csrf" value="([^"]+)"/) || [])[1] || '';

    r = await pedir(base, '/setup.php', { _csrf: token, acao: 'criar', nome: 'Fumaça', usuario: 'fumaca', senha: 's3nha', senha2: 's3nha' });
    conferir(r.codigo === 302 && r.local === 'index.php', 'o primeiro usuário é criado', `HTTP ${r.codigo} ${r.local} ${resumo(r.corpo)}`);

    r = await pedir(base, '/index.php');
    conferir(r.codigo === 200 && r.corpo.includes('Calendário Acadêmico') && r.corpo.includes('sidebar'),
      'o painel abre logado', `HTTP ${r.codigo}: ${resumo(r.corpo)}`);

    r = await pedir(base, '/assets/app.css');
    conferir(r.codigo === 200 && r.corpo.length > 1000, 'o CSS é servido', `HTTP ${r.codigo}`);

    r = await pedir(base, '/lib/db.php');
    conferir(r.codigo === 403, 'lib/ fica fechado', `HTTP ${r.codigo}`);

    conferir(fs.existsSync(path.join(PASTA, 'calendario.sqlite')), 'o banco nasce na pasta de dados');
  } catch (e) {
    conferir(false, 'o PHP sobe e atende', e.message);
  } finally {
    proc.kill();
    await new Promise((r) => (proc.exitCode !== null ? r() : proc.on('exit', r)));
    fs.closeSync(saida);
  }

  const log = fs.readFileSync(LOG, 'utf8');
  const erros = log.split(/\r?\n/).filter((l) => /PHP (Warning|Notice|Fatal error|Parse error|Deprecated)|Unable to load|Uncaught/.test(l));
  conferir(erros.length === 0, 'o log do PHP está limpo', '\n      ' + erros.join('\n      '));

  if (falhas.length) {
    console.log('\nLog do PHP:\n' + log);
    console.log(`\n${falhas.length} problema(s).`);
    process.exit(1);
  }
  fs.rmSync(DADOS, { recursive: true, force: true });
  console.log('\nModo desktop ok.');
})();
