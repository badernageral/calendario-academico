-- Esquema do Sistema de Calendário Acadêmico
-- Modelado a partir da planilha calendario.ods (IFTO / Campus Lagoa da Confusão)

PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS config (
    chave TEXT PRIMARY KEY,
    valor TEXT NOT NULL
);

-- Níveis de ensino. A `chave` é o que fica gravado em cursos.nivel e em
-- eventos.nivel, então ela não muda depois de criada — o nome, sim.
CREATE TABLE IF NOT EXISTS niveis (
    id    INTEGER PRIMARY KEY AUTOINCREMENT,
    chave TEXT NOT NULL UNIQUE,                 -- ex.: superior, integrado
    nome  TEXT NOT NULL                         -- ex.: Técnico Integrado
);

CREATE TABLE IF NOT EXISTS cursos (
    id     INTEGER PRIMARY KEY AUTOINCREMENT,
    nome   TEXT NOT NULL,                       -- ex.: SUPERIOR EM ENGENHARIA AGRONÔMICA
    nivel  TEXT NOT NULL DEFAULT 'superior',    -- chave de um nível (tabela niveis)
    regime TEXT NOT NULL DEFAULT 'semestral',   -- anual | semestral: duração das disciplinas
    ativo  INTEGER NOT NULL DEFAULT 1,
    CHECK (regime IN ('anual','semestral'))
);

-- Categorias = a legenda do calendário. Definem a cor do dia na grade.
-- letivo:  1 = força o dia a contar como letivo (ex.: sábado letivo)
--          0 = força o dia a NÃO contar (feriado, férias, ponto facultativo)
--       NULL = neutro, só pinta; a regra do dia da semana decide
CREATE TABLE IF NOT EXISTS categorias (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    nome       TEXT NOT NULL UNIQUE,
    cor        TEXT NOT NULL,
    cor_texto  TEXT NOT NULL DEFAULT '#000000',
    letivo     INTEGER,
    prioridade INTEGER NOT NULL DEFAULT 50,     -- maior vence quando dois eventos caem no mesmo dia
    na_legenda INTEGER NOT NULL DEFAULT 1,
    ordem      INTEGER NOT NULL DEFAULT 0,
    protegida  INTEGER NOT NULL DEFAULT 0,      -- 1 = o sistema depende dela: não se exclui e a prioridade é fixa
    oculta     INTEGER NOT NULL DEFAULT 0,      -- 1 = automática, não aparece para escolher num evento
    CHECK (letivo IS NULL OR letivo IN (0,1))
);

-- Feriados: cadastrados uma vez e válidos em todos os anos. Os de data fixa
-- guardam dia e mês; os móveis, a distância em dias até o domingo de Páscoa
-- (Carnaval = -48, Corpus Christi = +60).
CREATE TABLE IF NOT EXISTS feriados (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    nome         TEXT NOT NULL,
    tipo         TEXT NOT NULL DEFAULT 'fixo',   -- fixo | movel
    dia          INTEGER,
    mes          INTEGER,
    deslocamento INTEGER,
    categoria_id INTEGER REFERENCES categorias(id) ON DELETE SET NULL,
    CHECK (tipo IN ('fixo','movel')),
    CHECK ((tipo = 'fixo'  AND dia BETWEEN 1 AND 31 AND mes BETWEEN 1 AND 12)
        OR (tipo = 'movel' AND deslocamento IS NOT NULL))
);

CREATE TABLE IF NOT EXISTS calendarios (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    curso_id        INTEGER NOT NULL REFERENCES cursos(id) ON DELETE CASCADE,
    ano             INTEGER NOT NULL,
    situacao        TEXT NOT NULL DEFAULT 'Aguardando homologação',
    local_texto     TEXT NOT NULL DEFAULT '',   -- ex.: Lagoa da Confusão, outubro de 2025
    observacoes     TEXT NOT NULL DEFAULT '',
    criado_em       TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    UNIQUE (curso_id, ano)
);

-- Quem entra no sistema. Perfil único: quem tem senha faz tudo — não há papel
-- de leitura, e o calendário publicado sai por gerar.php, que é a via de quem
-- só quer ver. A senha nunca é guardada, só o hash que password_hash() produz.
CREATE TABLE IF NOT EXISTS usuarios (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    nome       TEXT NOT NULL,
    usuario    TEXT NOT NULL UNIQUE,
    senha_hash TEXT NOT NULL,
    ativo      INTEGER NOT NULL DEFAULT 1,
    criado_em  TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);

-- Os quatro bimestres, que é o que se digita, e os dois semestres que saem
-- deles. Ambos delimitam contagem: o semestre diz que dia é letivo, o bimestre
-- responde pelos contadores e pelos marcos automáticos na grade.
CREATE TABLE IF NOT EXISTS periodos (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    calendario_id INTEGER NOT NULL REFERENCES calendarios(id) ON DELETE CASCADE,
    tipo          TEXT NOT NULL,                -- semestre | bimestre
    numero        INTEGER NOT NULL,
    inicio        TEXT NOT NULL,
    fim           TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_periodos_cal ON periodos(calendario_id);
-- Um período de cada tipo e número por calendário, e não mais.
CREATE UNIQUE INDEX IF NOT EXISTS idx_periodos_unico ON periodos(calendario_id, tipo, numero);

-- Eventos. calendario_id NULL = base comum do ano (vale para todos os cursos).
CREATE TABLE IF NOT EXISTS eventos (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    ano           INTEGER NOT NULL,
    calendario_id INTEGER REFERENCES calendarios(id) ON DELETE CASCADE,
    categoria_id  INTEGER REFERENCES categorias(id) ON DELETE SET NULL,
    descricao     TEXT NOT NULL,
    pinta_dias    INTEGER NOT NULL DEFAULT 1,   -- pinta a célula na grade
    negrito       INTEGER NOT NULL DEFAULT 0,
    conta_letivo  INTEGER,                      -- NULL = herda da categoria
    rotulo        TEXT,                         -- sobrescreve o rótulo "5 a 9" quando necessário
    nivel         TEXT,                         -- só na base comum: chaves de níveis separadas por vírgula
    repoe_dow     INTEGER,                      -- 0=Dom..6=Sáb: "sábado letivo com horário de terça"
    CHECK (conta_letivo IS NULL OR conta_letivo IN (0,1))
);
CREATE INDEX IF NOT EXISTS idx_eventos_ano ON eventos(ano, calendario_id);

-- Um evento pode ter várias faixas: "14 a 16 e 19 a 20 - Matrícula"
CREATE TABLE IF NOT EXISTS evento_datas (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    evento_id INTEGER NOT NULL REFERENCES eventos(id) ON DELETE CASCADE,
    inicio    TEXT NOT NULL,
    fim       TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_evento_datas ON evento_datas(evento_id);
