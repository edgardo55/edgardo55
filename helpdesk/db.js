'use strict';
const { DatabaseSync } = require('node:sqlite');
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');

const DB_PATH = process.env.DB_PATH || path.join(__dirname, 'data', 'helpdesk.db');
fs.mkdirSync(path.dirname(DB_PATH), { recursive: true });
const db = new DatabaseSync(DB_PATH);
db.exec('PRAGMA journal_mode = WAL; PRAGMA foreign_keys = ON;');

db.exec(`
CREATE TABLE IF NOT EXISTS users(
  id INTEGER PRIMARY KEY, name TEXT NOT NULL, email TEXT NOT NULL UNIQUE COLLATE NOCASE,
  password_hash TEXT NOT NULL, role TEXT NOT NULL DEFAULT 'user', dept TEXT DEFAULT '',
  active INTEGER NOT NULL DEFAULT 1, must_change INTEGER NOT NULL DEFAULT 0, created INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS sessions(token TEXT PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, expires INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS categories(id INTEGER PRIMARY KEY, name TEXT NOT NULL UNIQUE);
CREATE TABLE IF NOT EXISTS tickets(
  id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, description TEXT NOT NULL,
  requester_id INTEGER NOT NULL REFERENCES users(id), category TEXT NOT NULL, priority TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'Aberto', assignee_id INTEGER REFERENCES users(id),
  created INTEGER NOT NULL, updated INTEGER NOT NULL, due INTEGER NOT NULL, resolved_at INTEGER,
  rating INTEGER, rating_comment TEXT);
CREATE INDEX IF NOT EXISTS idx_t_req ON tickets(requester_id);
CREATE INDEX IF NOT EXISTS idx_t_status ON tickets(status);
CREATE TABLE IF NOT EXISTS comments(id INTEGER PRIMARY KEY, ticket_id INTEGER NOT NULL REFERENCES tickets(id) ON DELETE CASCADE,
  user_id INTEGER NOT NULL REFERENCES users(id), text TEXT NOT NULL, internal INTEGER NOT NULL DEFAULT 0, created INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS history(id INTEGER PRIMARY KEY, ticket_id INTEGER NOT NULL REFERENCES tickets(id) ON DELETE CASCADE,
  user_id INTEGER REFERENCES users(id), detail TEXT NOT NULL, created INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS attachments(id INTEGER PRIMARY KEY, ticket_id INTEGER NOT NULL REFERENCES tickets(id) ON DELETE CASCADE,
  user_id INTEGER NOT NULL REFERENCES users(id), name TEXT NOT NULL, type TEXT NOT NULL, size INTEGER NOT NULL, data BLOB NOT NULL, created INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS kb(id INTEGER PRIMARY KEY, title TEXT NOT NULL, body TEXT NOT NULL, category TEXT DEFAULT '',
  author_id INTEGER REFERENCES users(id), created INTEGER NOT NULL, updated INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS notifications(id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  ticket_id INTEGER, text TEXT NOT NULL, read INTEGER NOT NULL DEFAULT 0, created INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS settings(key TEXT PRIMARY KEY, value TEXT NOT NULL);
`);

/* ---------- helpers ---------- */
function hashPassword(pw) {
  const salt = crypto.randomBytes(16);
  return salt.toString('hex') + ':' + crypto.scryptSync(pw, salt, 64).toString('hex');
}
function verifyPassword(pw, stored) {
  const [s, h] = String(stored).split(':');
  if (!s || !h) return false;
  const calc = crypto.scryptSync(pw, Buffer.from(s, 'hex'), 64);
  const real = Buffer.from(h, 'hex');
  return calc.length === real.length && crypto.timingSafeEqual(calc, real);
}
const DEFAULT_SETTINGS = {
  company: 'MediaNova',
  color_dark: '#0b1f3a', color_primary: '#0a84ff', color_accent: '#ff7a1a',
  sla_Crítica: '4', sla_Alta: '8', sla_Média: '24', sla_Baixa: '72',
  allow_register: '0', logo: ''
};
function getSettings() {
  const out = { ...DEFAULT_SETTINGS };
  for (const r of db.prepare('SELECT key,value FROM settings').all()) out[r.key] = r.value;
  return out;
}
function setSetting(k, v) {
  db.prepare('INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value').run(k, String(v));
}
const num = r => Number(r.lastInsertRowid);

/* ---------- seed ---------- */
function seed() {
  if (db.prepare('SELECT COUNT(*) c FROM users').get().c > 0) return null;
  const now = Date.now(), H = 3600e3;
  const creds = [];
  const mk = (name, email, pw, role, dept) => {
    const r = db.prepare('INSERT INTO users(name,email,password_hash,role,dept,must_change,created) VALUES(?,?,?,?,?,1,?)')
      .run(name, email, hashPassword(pw), role, dept, now);
    creds.push(`${role.padEnd(5)} ${email}  /  ${pw}`);
    return num(r);
  };
  const admin = mk('Administrador', 'admin@medianova.com', 'Admin@123', 'admin', 'TI');
  if (process.env.SEED_DEMO === '0') {
    for (const c of ['Hardware', 'Software', 'Rede / Internet', 'E-mail', 'Acessos / Senhas', 'Impressora', 'Telefonia', 'Outros'])
      db.prepare('INSERT INTO categories(name) VALUES(?)').run(c);
    return creds;
  }
  const tech = mk('Técnico de TI', 'tecnico@medianova.com', 'Tecnico@123', 'tech', 'TI');
  const u1 = mk('Ana Souza', 'usuario@medianova.com', 'Usuario@123', 'user', 'Financeiro');
  const u2 = mk('Carlos Lima', 'carlos@medianova.com', 'Usuario@123', 'user', 'NOC');
  for (const c of ['Hardware', 'Software', 'Rede / Internet', 'E-mail', 'Acessos / Senhas', 'Impressora', 'Telefonia', 'Outros'])
    db.prepare('INSERT INTO categories(name) VALUES(?)').run(c);
  const SLA = { 'Crítica': 4, 'Alta': 8, 'Média': 24, 'Baixa': 72 };
  const add = (title, desc, req, cat, prio, status, assignee, ageH) => {
    const created = now - ageH * H;
    const resolved = ['Resolvido', 'Fechado'].includes(status) ? created + 5 * H : null;
    const id = num(db.prepare(`INSERT INTO tickets(title,description,requester_id,category,priority,status,assignee_id,created,updated,due,resolved_at)
      VALUES(?,?,?,?,?,?,?,?,?,?,?)`).run(title, desc, req, cat, prio, status, assignee, created, created, created + SLA[prio] * H, resolved));
    db.prepare('INSERT INTO history(ticket_id,user_id,detail,created) VALUES(?,?,?,?)').run(id, req, 'Chamado aberto', created);
    return id;
  };
  add('Computador não liga', 'O PC não dá sinal de vídeo ao ligar.', u1, 'Hardware', 'Alta', 'Em andamento', tech, 30);
  add('Acesso à VPN bloqueado', 'Após trocar a senha a VPN recusa o login.', u2, 'Acessos / Senhas', 'Crítica', 'Aberto', null, 3);
  const t3 = add('Impressora do 2º andar sem toner', 'Precisa trocar o toner da impressora.', u1, 'Impressora', 'Baixa', 'Resolvido', tech, 72);
  db.prepare('INSERT INTO comments(ticket_id,user_id,text,internal,created) VALUES(?,?,?,0,?)').run(t3, tech, 'Toner substituído.', now - 67 * H);
  add('Instalar Office no notebook novo', 'Notebook recebido sem pacote Office.', u2, 'Software', 'Média', 'Aguardando', tech, 20);
  db.prepare('INSERT INTO kb(title,body,category,author_id,created,updated) VALUES(?,?,?,?,?,?)').run(
    'Como redefinir minha senha', '1. Acesse o portal corporativo.\n2. Clique em "Esqueci minha senha".\n3. Siga as instruções enviadas ao seu e-mail.\nSe não funcionar, abra um chamado na categoria "Acessos / Senhas".',
    'Acessos / Senhas', admin, now, now);
  db.prepare('INSERT INTO kb(title,body,category,author_id,created,updated) VALUES(?,?,?,?,?,?)').run(
    'Conectar à VPN da empresa', '1. Abra o cliente VPN.\n2. Informe seu usuário e senha de rede.\n3. Aguarde o status "Conectado".\nProblemas de acesso? Abra um chamado de prioridade Alta.',
    'Rede / Internet', admin, now, now);
  return creds;
}

module.exports = { db, hashPassword, verifyPassword, getSettings, setSetting, seed, num };
