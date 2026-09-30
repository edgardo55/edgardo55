'use strict';
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { db, hashPassword, verifyPassword, getSettings, setSetting, seed, num } = require('./db');

const PORT = Number(process.env.PORT) || 3000;
const PUBLIC = path.join(__dirname, 'public');
const STATUS = ['Aberto', 'Em andamento', 'Aguardando', 'Resolvido', 'Fechado'];
const PRIO = ['Baixa', 'Média', 'Alta', 'Crítica'];
const ROLES = ['admin', 'tech', 'user'];
const SESSION_MS = 8 * 3600e3;
const MAX_BODY = 8 * 1024 * 1024, MAX_ATT = 5 * 1024 * 1024;
const MIME = { '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8', '.js': 'text/javascript; charset=utf-8',
  '.svg': 'image/svg+xml', '.png': 'image/png', '.ico': 'image/x-icon', '.json': 'application/json' };

class HttpError extends Error { constructor(code, msg) { super(msg); this.code = code; } }
const bad = m => new HttpError(400, m), forbid = () => new HttpError(403, 'Sem permissão'), notFound = () => new HttpError(404, 'Não encontrado');
const isStaff = u => u.role === 'admin' || u.role === 'tech';
const str = (v, max = 200) => String(v ?? '').trim().slice(0, max);

/* ---------- helpers ---------- */
function json(res, code, data, headers = {}) {
  const body = JSON.stringify(data);
  res.writeHead(code, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store', ...headers });
  res.end(body);
}
function readBody(req) {
  return new Promise((resolve, reject) => {
    let size = 0; const chunks = [];
    req.on('data', c => { size += c.length; if (size > MAX_BODY) { reject(new HttpError(413, 'Corpo da requisição muito grande')); req.destroy(); } else chunks.push(c); });
    req.on('end', () => {
      if (!chunks.length) return resolve({});
      try { resolve(JSON.parse(Buffer.concat(chunks).toString('utf8'))); } catch { reject(bad('JSON inválido')); }
    });
    req.on('error', reject);
  });
}
function parseCookies(req) {
  const out = {};
  for (const p of (req.headers.cookie || '').split(';')) { const i = p.indexOf('='); if (i > 0) out[p.slice(0, i).trim()] = decodeURIComponent(p.slice(i + 1).trim()); }
  return out;
}
function sessionUser(req) {
  const tok = parseCookies(req).sid; if (!tok) return null;
  const s = db.prepare('SELECT user_id, expires FROM sessions WHERE token=?').get(tok);
  if (!s) return null;
  if (s.expires < Date.now()) { db.prepare('DELETE FROM sessions WHERE token=?').run(tok); return null; }
  const u = db.prepare('SELECT * FROM users WHERE id=? AND active=1').get(s.user_id);
  return u || null;
}
const publicUser = u => ({ id: u.id, name: u.name, email: u.email, role: u.role, dept: u.dept, must_change: !!u.must_change });
const now = () => Date.now();

function notify(userId, ticketId, text, exceptId) {
  if (!userId || userId === exceptId) return;
  db.prepare('INSERT INTO notifications(user_id,ticket_id,text,created) VALUES(?,?,?,?)').run(userId, ticketId, text, now());
}
function notifyStaff(ticketId, text, exceptId) {
  for (const u of db.prepare("SELECT id FROM users WHERE active=1 AND role IN ('admin','tech')").all()) notify(u.id, ticketId, text, exceptId);
}
function hist(ticketId, userId, detail) {
  db.prepare('INSERT INTO history(ticket_id,user_id,detail,created) VALUES(?,?,?,?)').run(ticketId, userId, detail, now());
}
const slaHours = p => Number(getSettings()['sla_' + p]) || 24;

function slaState(t) {
  const end = t.resolved_at || now();
  if (t.resolved_at) return end <= t.due ? 'ok' : 'breached';
  if (now() > t.due) return 'breached';
  return (t.due - now()) < (t.due - t.created) * 0.25 ? 'warning' : 'ok';
}
const TICKET_SELECT = `SELECT t.*, r.name requester_name, r.email requester_email, r.dept requester_dept, a.name assignee_name
  FROM tickets t JOIN users r ON r.id=t.requester_id LEFT JOIN users a ON a.id=t.assignee_id`;
const shape = t => ({ ...t, sla: slaState(t) });

function getTicketFor(user, id) {
  const t = db.prepare(TICKET_SELECT + ' WHERE t.id=?').get(id);
  if (!t) throw notFound();
  if (!isStaff(user) && t.requester_id !== user.id) throw notFound();
  return t;
}

function buildFilter(user, q) {
  const w = [], p = [];
  if (!isStaff(user)) { w.push('t.requester_id=?'); p.push(user.id); }
  if (STATUS.includes(q.status)) { w.push('t.status=?'); p.push(q.status); }
  if (q.status === 'open') w.push("t.status NOT IN ('Resolvido','Fechado')");
  if (PRIO.includes(q.priority)) { w.push('t.priority=?'); p.push(q.priority); }
  if (q.category) { w.push('t.category=?'); p.push(q.category); }
  if (q.assignee === 'none') w.push('t.assignee_id IS NULL');
  else if (q.assignee === 'me') { w.push('t.assignee_id=?'); p.push(user.id); }
  else if (/^\d+$/.test(q.assignee || '')) { w.push('t.assignee_id=?'); p.push(Number(q.assignee)); }
  if (q.overdue === '1') { w.push("t.status NOT IN ('Resolvido','Fechado') AND t.due < ?"); p.push(now()); }
  if (q.q) {
    const like = '%' + q.q.replace(/[%_]/g, '') + '%';
    w.push('(t.title LIKE ? OR t.description LIKE ? OR r.name LIKE ? OR CAST(t.id AS TEXT)=?)');
    p.push(like, like, like, q.q.replace('#', ''));
  }
  return { where: w.length ? ' WHERE ' + w.join(' AND ') : '', params: p };
}

/* ---------- login throttling ---------- */
const fails = new Map();
function throttled(key) { const f = fails.get(key); return f && f.n >= 5 && now() - f.t < 300e3; }
function recordFail(key) { const f = fails.get(key); fails.set(key, { n: (f && now() - f.t < 300e3 ? f.n : 0) + 1, t: now() }); }

/* ---------- routes ---------- */
const routes = [];
const route = (method, pattern, opts, fn) => routes.push({ method, re: new RegExp('^' + pattern.replace(/:(\w+)/g, '(?<$1>[^/]+)') + '$'), opts, fn });
// opts: 'public' | 'auth' | 'staff' | 'admin'

route('GET', '/api/branding', 'public', () => {
  const s = getSettings();
  return { company: s.company, color_dark: s.color_dark, color_primary: s.color_primary, color_accent: s.color_accent, logo: s.logo, allow_register: s.allow_register === '1' };
});

route('POST', '/api/login', 'public', (c) => {
  const email = str(c.body.email).toLowerCase(), key = c.ip + '|' + email;
  if (throttled(key)) throw new HttpError(429, 'Muitas tentativas. Aguarde 5 minutos.');
  const u = db.prepare('SELECT * FROM users WHERE email=? AND active=1').get(email);
  if (!u || !verifyPassword(String(c.body.password || ''), u.password_hash)) { recordFail(key); throw new HttpError(401, 'E-mail ou senha inválidos'); }
  fails.delete(key);
  const tok = crypto.randomBytes(32).toString('hex');
  db.prepare('INSERT INTO sessions(token,user_id,expires) VALUES(?,?,?)').run(tok, u.id, now() + SESSION_MS);
  db.prepare('DELETE FROM sessions WHERE expires < ?').run(now());
  const secure = c.req.headers['x-forwarded-proto'] === 'https' ? '; Secure' : '';
  c.headers['Set-Cookie'] = `sid=${tok}; HttpOnly; SameSite=Strict; Path=/; Max-Age=${SESSION_MS / 1000}${secure}`;
  return publicUser(u);
});
route('POST', '/api/logout', 'public', (c) => {
  const tok = parseCookies(c.req).sid; if (tok) db.prepare('DELETE FROM sessions WHERE token=?').run(tok);
  c.headers['Set-Cookie'] = 'sid=; HttpOnly; SameSite=Strict; Path=/; Max-Age=0';
  return { ok: true };
});
route('POST', '/api/register', 'public', (c) => {
  if (getSettings().allow_register !== '1') throw forbid();
  const name = str(c.body.name, 100), email = str(c.body.email, 150).toLowerCase(), pw = String(c.body.password || '');
  if (!name || !/^\S+@\S+\.\S+$/.test(email)) throw bad('Nome e e-mail válidos são obrigatórios');
  if (pw.length < 8) throw bad('A senha deve ter ao menos 8 caracteres');
  if (db.prepare('SELECT 1 FROM users WHERE email=?').get(email)) throw bad('E-mail já cadastrado');
  db.prepare("INSERT INTO users(name,email,password_hash,role,dept,created) VALUES(?,?,?,'user',?,?)").run(name, email, hashPassword(pw), str(c.body.dept, 100), now());
  return { ok: true };
});
route('GET', '/api/me', 'auth', (c) => publicUser(c.user));
route('POST', '/api/me/password', 'auth', (c) => {
  const cur = String(c.body.current || ''), nw = String(c.body.new || '');
  if (!verifyPassword(cur, c.user.password_hash)) throw bad('Senha atual incorreta');
  if (nw.length < 8) throw bad('A nova senha deve ter ao menos 8 caracteres');
  if (nw === cur) throw bad('A nova senha deve ser diferente da atual');
  db.prepare('UPDATE users SET password_hash=?, must_change=0 WHERE id=?').run(hashPassword(nw), c.user.id);
  return { ok: true };
});

/* --- users --- */
route('GET', '/api/users', 'staff', (c) => {
  const rows = db.prepare('SELECT id,name,email,role,dept,active,created FROM users ORDER BY name').all();
  return c.user.role === 'admin' ? rows : rows.filter(r => r.active).map(({ id, name, role, dept, email }) => ({ id, name, role, dept, email }));
});
route('POST', '/api/users', 'admin', (c) => {
  const b = c.body, name = str(b.name, 100), email = str(b.email, 150).toLowerCase(), pw = String(b.password || '');
  if (!name || !/^\S+@\S+\.\S+$/.test(email)) throw bad('Nome e e-mail válidos são obrigatórios');
  if (!ROLES.includes(b.role)) throw bad('Perfil inválido');
  if (pw.length < 8) throw bad('A senha deve ter ao menos 8 caracteres');
  if (db.prepare('SELECT 1 FROM users WHERE email=?').get(email)) throw bad('E-mail já cadastrado');
  const r = db.prepare('INSERT INTO users(name,email,password_hash,role,dept,must_change,created) VALUES(?,?,?,?,?,1,?)')
    .run(name, email, hashPassword(pw), b.role, str(b.dept, 100), now());
  return { id: num(r) };
});
route('PUT', '/api/users/:id', 'admin', (c) => {
  const id = Number(c.params.id), u = db.prepare('SELECT * FROM users WHERE id=?').get(id);
  if (!u) throw notFound();
  const b = c.body, name = str(b.name ?? u.name, 100), role = b.role ?? u.role, active = b.active === undefined ? u.active : (b.active ? 1 : 0);
  if (!ROLES.includes(role)) throw bad('Perfil inválido');
  if (id === c.user.id && (role !== 'admin' || !active)) throw bad('Você não pode remover seu próprio acesso de administrador');
  if (!name) throw bad('Nome obrigatório');
  db.prepare('UPDATE users SET name=?, role=?, dept=?, active=? WHERE id=?').run(name, role, str(b.dept ?? u.dept, 100), active, id);
  if (b.password) {
    if (String(b.password).length < 8) throw bad('A senha deve ter ao menos 8 caracteres');
    db.prepare('UPDATE users SET password_hash=?, must_change=1 WHERE id=?').run(hashPassword(String(b.password)), id);
  }
  if (!active) db.prepare('DELETE FROM sessions WHERE user_id=?').run(id);
  return { ok: true };
});

/* --- categories & settings --- */
route('GET', '/api/categories', 'auth', () => db.prepare('SELECT id,name FROM categories ORDER BY name').all());
route('POST', '/api/categories', 'admin', (c) => {
  const name = str(c.body.name, 60); if (!name) throw bad('Nome obrigatório');
  try { return { id: num(db.prepare('INSERT INTO categories(name) VALUES(?)').run(name)) }; } catch { throw bad('Categoria já existe'); }
});
route('DELETE', '/api/categories/:id', 'admin', (c) => {
  if (db.prepare('SELECT COUNT(*) n FROM categories').get().n <= 1) throw bad('Mantenha ao menos uma categoria');
  db.prepare('DELETE FROM categories WHERE id=?').run(Number(c.params.id)); return { ok: true };
});
route('GET', '/api/settings', 'admin', () => { const s = getSettings(); delete s.logo; return s; });
route('PUT', '/api/settings', 'admin', (c) => {
  const b = c.body;
  for (const k of ['color_dark', 'color_primary', 'color_accent'])
    if (b[k] !== undefined) { if (!/^#[0-9a-f]{6}$/i.test(b[k])) throw bad('Cor inválida: ' + k); setSetting(k, b[k]); }
  for (const p of PRIO) if (b['sla_' + p] !== undefined) {
    const n = Number(b['sla_' + p]); if (!(n > 0 && n <= 1000)) throw bad('SLA inválido para ' + p); setSetting('sla_' + p, n);
  }
  if (b.company !== undefined) { const n = str(b.company, 60); if (!n) throw bad('Nome da empresa obrigatório'); setSetting('company', n); }
  if (b.allow_register !== undefined) setSetting('allow_register', b.allow_register ? '1' : '0');
  return { ok: true };
});
route('PUT', '/api/settings/logo', 'admin', (c) => {
  const d = String(c.body.data || '');
  if (!/^data:image\/(png|jpeg|gif|webp|svg\+xml);base64,[A-Za-z0-9+/=]+$/.test(d) || d.length > 700000) throw bad('Imagem inválida (máx. ~500 KB; PNG, JPG, GIF, WEBP ou SVG)');
  setSetting('logo', d); return { ok: true };
});
route('DELETE', '/api/settings/logo', 'admin', () => { setSetting('logo', ''); return { ok: true }; });

/* --- tickets --- */
route('GET', '/api/tickets', 'auth', (c) => {
  const { where, params } = buildFilter(c.user, c.query);
  const page = Math.max(1, Number(c.query.page) || 1), limit = Math.min(100, Number(c.query.limit) || 15);
  const join = ' FROM tickets t JOIN users r ON r.id=t.requester_id LEFT JOIN users a ON a.id=t.assignee_id';
  const total = db.prepare('SELECT COUNT(*) n' + join + where).get(...params).n;
  const order = { created: 't.created', updated: 't.updated', due: 't.due', priority: "CASE t.priority WHEN 'Crítica' THEN 4 WHEN 'Alta' THEN 3 WHEN 'Média' THEN 2 ELSE 1 END" }[c.query.sort] || 't.created';
  const dir = c.query.dir === 'asc' ? 'ASC' : 'DESC';
  const items = db.prepare(TICKET_SELECT + where + ` ORDER BY ${order} ${dir}, t.id DESC LIMIT ? OFFSET ?`).all(...params, limit, (page - 1) * limit).map(shape);
  return { items, total, page, pages: Math.max(1, Math.ceil(total / limit)) };
});
route('GET', '/api/export.csv', 'staff', (c) => {
  const { where, params } = buildFilter(c.user, c.query);
  const rows = db.prepare(TICKET_SELECT + where + ' ORDER BY t.id DESC').all(...params);
  const q = v => `"${String(v ?? '').replace(/"/g, '""')}"`, d = v => v ? new Date(v).toLocaleString('pt-BR') : '';
  const head = ['ID', 'Assunto', 'Solicitante', 'Departamento', 'Categoria', 'Prioridade', 'Status', 'Técnico', 'Abertura', 'Prazo SLA', 'Resolução', 'SLA', 'Avaliação'];
  const lines = rows.map(t => [t.id, t.title, t.requester_name, t.requester_dept, t.category, t.priority, t.status, t.assignee_name, d(t.created), d(t.due), d(t.resolved_at), slaState(t) === 'breached' ? 'Estourado' : 'No prazo', t.rating].map(q).join(';'));
  c.raw = { type: 'text/csv; charset=utf-8', body: '﻿' + [head.map(q).join(';'), ...lines].join('\r\n'), headers: { 'Content-Disposition': 'attachment; filename="chamados.csv"' } };
});
route('POST', '/api/tickets', 'auth', (c) => {
  const b = c.body, title = str(b.title, 150), desc = str(b.description, 10000);
  if (!title || !desc) throw bad('Assunto e descrição são obrigatórios');
  if (!PRIO.includes(b.priority)) throw bad('Prioridade inválida');
  const cat = db.prepare('SELECT name FROM categories WHERE name=?').get(str(b.category, 60));
  if (!cat) throw bad('Categoria inválida');
  let requester = c.user.id, assignee = null;
  if (isStaff(c.user)) {
    if (b.requester_id) { if (!db.prepare('SELECT 1 FROM users WHERE id=? AND active=1').get(Number(b.requester_id))) throw bad('Solicitante inválido'); requester = Number(b.requester_id); }
    if (b.assignee_id) { if (!db.prepare("SELECT 1 FROM users WHERE id=? AND active=1 AND role IN ('admin','tech')").get(Number(b.assignee_id))) throw bad('Técnico inválido'); assignee = Number(b.assignee_id); }
  }
  const t = now();
  const id = num(db.prepare(`INSERT INTO tickets(title,description,requester_id,category,priority,assignee_id,created,updated,due) VALUES(?,?,?,?,?,?,?,?,?)`)
    .run(title, desc, requester, cat.name, b.priority, assignee, t, t, t + slaHours(b.priority) * 3600e3));
  hist(id, c.user.id, 'Chamado aberto');
  if (assignee) { hist(id, c.user.id, 'Atribuído a ' + db.prepare('SELECT name FROM users WHERE id=?').get(assignee).name); notify(assignee, id, `Chamado #${id} atribuído a você: ${title}`, c.user.id); }
  else notifyStaff(id, `Novo chamado #${id}: ${title}`, c.user.id);
  return { id };
});
route('GET', '/api/tickets/:id', 'auth', (c) => {
  const t = getTicketFor(c.user, Number(c.params.id)), staff = isStaff(c.user);
  const comments = db.prepare('SELECT c.id,c.text,c.internal,c.created,u.name author,u.role FROM comments c JOIN users u ON u.id=c.user_id WHERE ticket_id=?' + (staff ? '' : ' AND internal=0') + ' ORDER BY c.id').all(t.id);
  const history = db.prepare('SELECT h.detail,h.created,u.name author FROM history h LEFT JOIN users u ON u.id=h.user_id WHERE ticket_id=? ORDER BY h.id').all(t.id);
  const attachments = db.prepare('SELECT a.id,a.name,a.type,a.size,a.created,u.name author FROM attachments a JOIN users u ON u.id=a.user_id WHERE ticket_id=? ORDER BY a.id').all(t.id);
  return { ...shape(t), comments, history, attachments };
});
route('PATCH', '/api/tickets/:id', 'auth', (c) => {
  const t = getTicketFor(c.user, Number(c.params.id)), b = c.body, staff = isStaff(c.user), sets = {}, changes = [];
  if (!staff) {
    if (b.status === undefined || Object.keys(b).length > 1) throw forbid();
    const ok = (b.status === 'Fechado' && t.status === 'Resolvido') || (b.status === 'Aberto' && ['Resolvido', 'Fechado'].includes(t.status));
    if (!ok) throw bad('Alteração de status não permitida');
  }
  if (b.status !== undefined && b.status !== t.status) {
    if (!STATUS.includes(b.status)) throw bad('Status inválido');
    sets.status = b.status; changes.push(`Status: ${t.status} → ${b.status}`);
    const done = ['Resolvido', 'Fechado'].includes(b.status);
    if (done && !t.resolved_at) sets.resolved_at = now();
    if (!done) { sets.resolved_at = null; sets.rating = null; sets.rating_comment = null; }
  }
  if (staff) {
    if (b.priority !== undefined && b.priority !== t.priority) {
      if (!PRIO.includes(b.priority)) throw bad('Prioridade inválida');
      sets.priority = b.priority; sets.due = t.created + slaHours(b.priority) * 3600e3; changes.push(`Prioridade: ${t.priority} → ${b.priority}`);
    }
    if (b.category !== undefined && b.category !== t.category) {
      if (!db.prepare('SELECT 1 FROM categories WHERE name=?').get(b.category)) throw bad('Categoria inválida');
      sets.category = b.category; changes.push(`Categoria: ${t.category} → ${b.category}`);
    }
    if (b.assignee_id !== undefined && (b.assignee_id || null) !== t.assignee_id) {
      let nm = 'ninguém';
      if (b.assignee_id) {
        const a = db.prepare("SELECT id,name FROM users WHERE id=? AND active=1 AND role IN ('admin','tech')").get(Number(b.assignee_id));
        if (!a) throw bad('Técnico inválido'); nm = a.name; sets.assignee_id = a.id;
        notify(a.id, t.id, `Chamado #${t.id} atribuído a você: ${t.title}`, c.user.id);
      } else sets.assignee_id = null;
      changes.push('Atribuído a ' + nm);
    }
  }
  if (!changes.length) return { ok: true };
  sets.updated = now();
  const keys = Object.keys(sets);
  db.prepare(`UPDATE tickets SET ${keys.map(k => k + '=?').join(',')} WHERE id=?`).run(...keys.map(k => sets[k]), t.id);
  for (const ch of changes) hist(t.id, c.user.id, ch);
  if (sets.status) {
    const msg = `Chamado #${t.id} agora está "${sets.status}"`;
    if (staff) notify(t.requester_id, t.id, msg, c.user.id); else notify(t.assignee_id, t.id, msg, c.user.id);
  }
  return { ok: true };
});
route('POST', '/api/tickets/:id/comments', 'auth', (c) => {
  const t = getTicketFor(c.user, Number(c.params.id)), text = str(c.body.text, 10000), staff = isStaff(c.user);
  if (!text) throw bad('Comentário vazio');
  const internal = staff && c.body.internal ? 1 : 0;
  db.prepare('INSERT INTO comments(ticket_id,user_id,text,internal,created) VALUES(?,?,?,?,?)').run(t.id, c.user.id, text, internal, now());
  db.prepare('UPDATE tickets SET updated=? WHERE id=?').run(now(), t.id);
  if (internal) notify(t.assignee_id, t.id, `Nota interna no chamado #${t.id}`, c.user.id);
  else if (staff) notify(t.requester_id, t.id, `Nova resposta no chamado #${t.id}`, c.user.id);
  else if (t.assignee_id) notify(t.assignee_id, t.id, `Novo comentário no chamado #${t.id}`, c.user.id);
  else notifyStaff(t.id, `Novo comentário no chamado #${t.id}`, c.user.id);
  return { ok: true };
});
route('POST', '/api/tickets/:id/rating', 'auth', (c) => {
  const t = getTicketFor(c.user, Number(c.params.id)), r = Number(c.body.rating);
  if (t.requester_id !== c.user.id) throw forbid();
  if (!['Resolvido', 'Fechado'].includes(t.status)) throw bad('Só é possível avaliar chamados resolvidos');
  if (!(r >= 1 && r <= 5 && Number.isInteger(r))) throw bad('Nota deve ser de 1 a 5');
  db.prepare('UPDATE tickets SET rating=?, rating_comment=? WHERE id=?').run(r, str(c.body.comment, 500), t.id);
  hist(t.id, c.user.id, `Avaliação do atendimento: ${r}/5`);
  return { ok: true };
});
route('POST', '/api/tickets/:id/attachments', 'auth', (c) => {
  const t = getTicketFor(c.user, Number(c.params.id)), name = str(c.body.name, 150).replace(/[\r\n"\\/]/g, '_');
  if (!name || typeof c.body.data !== 'string') throw bad('Arquivo inválido');
  if (db.prepare('SELECT COUNT(*) n FROM attachments WHERE ticket_id=?').get(t.id).n >= 10) throw bad('Limite de 10 anexos por chamado');
  const buf = Buffer.from(c.body.data, 'base64');
  if (!buf.length) throw bad('Arquivo vazio');
  if (buf.length > MAX_ATT) throw bad('Arquivo maior que 5 MB');
  db.prepare('INSERT INTO attachments(ticket_id,user_id,name,type,size,data,created) VALUES(?,?,?,?,?,?,?)')
    .run(t.id, c.user.id, name, str(c.body.type, 100) || 'application/octet-stream', buf.length, buf, now());
  hist(t.id, c.user.id, 'Anexo adicionado: ' + name);
  return { ok: true };
});
route('GET', '/api/attachments/:id', 'auth', (c) => {
  const a = db.prepare('SELECT * FROM attachments WHERE id=?').get(Number(c.params.id));
  if (!a) throw notFound();
  getTicketFor(c.user, a.ticket_id);
  c.raw = { type: 'application/octet-stream', body: Buffer.from(a.data), headers: { 'Content-Disposition': `attachment; filename*=UTF-8''${encodeURIComponent(a.name)}` } };
});

/* --- notifications --- */
route('GET', '/api/notifications', 'auth', (c) => ({
  unread: db.prepare('SELECT COUNT(*) n FROM notifications WHERE user_id=? AND read=0').get(c.user.id).n,
  items: db.prepare('SELECT * FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT 30').all(c.user.id)
}));
route('POST', '/api/notifications/read', 'auth', (c) => { db.prepare('UPDATE notifications SET read=1 WHERE user_id=?').run(c.user.id); return { ok: true }; });

/* --- knowledge base --- */
route('GET', '/api/kb', 'auth', (c) => {
  const q = c.query.q ? '%' + c.query.q.replace(/[%_]/g, '') + '%' : '%';
  return db.prepare('SELECT id,title,body,category,updated FROM kb WHERE title LIKE ? OR body LIKE ? ORDER BY title').all(q, q);
});
const kbBody = b => {
  const title = str(b.title, 150), body = str(b.body, 20000);
  if (!title || !body) throw bad('Título e conteúdo são obrigatórios');
  return [title, body, str(b.category, 60)];
};
route('POST', '/api/kb', 'staff', (c) => {
  const [t, b, cat] = kbBody(c.body);
  return { id: num(db.prepare('INSERT INTO kb(title,body,category,author_id,created,updated) VALUES(?,?,?,?,?,?)').run(t, b, cat, c.user.id, now(), now())) };
});
route('PUT', '/api/kb/:id', 'staff', (c) => {
  const [t, b, cat] = kbBody(c.body);
  db.prepare('UPDATE kb SET title=?,body=?,category=?,updated=? WHERE id=?').run(t, b, cat, now(), Number(c.params.id)); return { ok: true };
});
route('DELETE', '/api/kb/:id', 'staff', (c) => { db.prepare('DELETE FROM kb WHERE id=?').run(Number(c.params.id)); return { ok: true }; });

/* --- dashboard stats --- */
route('GET', '/api/stats', 'auth', (c) => {
  const staff = isStaff(c.user), w = staff ? '' : ' WHERE requester_id=' + c.user.id;
  const all = db.prepare('SELECT * FROM tickets' + w).all();
  const open = all.filter(t => !['Resolvido', 'Fechado'].includes(t.status));
  const count = (arr, k, keys) => Object.fromEntries(keys.map(v => [v, arr.filter(t => t[k] === v).length]));
  const cats = db.prepare('SELECT name FROM categories').all().map(r => r.name);
  const resolved = all.filter(t => t.resolved_at), rated = all.filter(t => t.rating);
  const out = {
    total: all.length, open: open.length,
    unassigned: open.filter(t => !t.assignee_id).length,
    overdue: open.filter(t => t.due < now()).length,
    resolved: resolved.length,
    byStatus: count(all, 'status', STATUS), byPriority: count(all, 'priority', PRIO), byCategory: count(all, 'category', cats),
    avgResolutionH: resolved.length ? +(resolved.reduce((s, t) => s + (t.resolved_at - t.created), 0) / resolved.length / 3600e3).toFixed(1) : null,
    slaCompliance: resolved.length ? Math.round(resolved.filter(t => t.resolved_at <= t.due).length / resolved.length * 100) : null,
    avgRating: rated.length ? +(rated.reduce((s, t) => s + t.rating, 0) / rated.length).toFixed(2) : null, ratings: rated.length
  };
  const days = []; const d0 = new Date(); d0.setHours(0, 0, 0, 0);
  for (let i = 13; i >= 0; i--) { const s = d0.getTime() - i * 86400e3; days.push({ day: new Date(s).toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' }), opened: all.filter(t => t.created >= s && t.created < s + 86400e3).length, resolved: all.filter(t => t.resolved_at && t.resolved_at >= s && t.resolved_at < s + 86400e3).length }); }
  out.daily = days;
  if (staff) out.byTech = db.prepare(`SELECT u.name, COUNT(t.id) total, SUM(CASE WHEN t.status IN ('Resolvido','Fechado') THEN 1 ELSE 0 END) resolved
    FROM users u LEFT JOIN tickets t ON t.assignee_id=u.id WHERE u.role IN ('admin','tech') AND u.active=1 GROUP BY u.id ORDER BY total DESC`).all();
  return out;
});

/* ---------- server ---------- */
function serveStatic(req, res, pathname) {
  let rel = decodeURIComponent(pathname); if (rel === '/') rel = '/index.html';
  const file = path.normalize(path.join(PUBLIC, rel));
  if (!file.startsWith(PUBLIC + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) { res.writeHead(404); return res.end('Not found'); }
  res.writeHead(200, { 'Content-Type': MIME[path.extname(file)] || 'application/octet-stream', 'Cache-Control': 'no-cache' });
  fs.createReadStream(file).pipe(res);
}

const server = http.createServer(async (req, res) => {
  res.setHeader('X-Content-Type-Options', 'nosniff');
  res.setHeader('X-Frame-Options', 'DENY');
  res.setHeader('Referrer-Policy', 'same-origin');
  res.setHeader('Content-Security-Policy', "default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; frame-ancestors 'none'");
  const url = new URL(req.url, 'http://x');
  try {
    if (!url.pathname.startsWith('/api/')) {
      if (req.method !== 'GET' && req.method !== 'HEAD') throw new HttpError(405, 'Método não permitido');
      return serveStatic(req, res, url.pathname);
    }
    const r = routes.find(r => r.method === req.method && r.re.test(url.pathname));
    if (!r) throw notFound();
    const c = { req, res, query: Object.fromEntries(url.searchParams), params: r.re.exec(url.pathname).groups || {}, body: {}, headers: {}, ip: req.socket.remoteAddress };
    if (r.opts !== 'public') {
      c.user = sessionUser(req);
      if (!c.user) throw new HttpError(401, 'Não autenticado');
      if (r.opts === 'staff' && !isStaff(c.user)) throw forbid();
      if (r.opts === 'admin' && c.user.role !== 'admin') throw forbid();
    }
    if (req.method !== 'GET' && req.method !== 'DELETE') {
      if (!(req.headers['content-type'] || '').startsWith('application/json') && Number(req.headers['content-length'] || 0) > 0) throw new HttpError(415, 'Use application/json');
      c.body = await readBody(req);
    }
    const out = r.fn(c);
    if (c.raw) {
      res.writeHead(200, { 'Content-Type': c.raw.type, 'Cache-Control': 'no-store', ...c.raw.headers });
      return res.end(c.raw.body);
    }
    json(res, 200, out, c.headers);
  } catch (e) {
    if (e instanceof HttpError) return json(res, e.code, { error: e.message });
    console.error(e); json(res, 500, { error: 'Erro interno do servidor' });
  }
});

const creds = seed();
server.listen(PORT, () => {
  console.log(`\n  MediaNova Helpdesk rodando em http://localhost:${PORT}\n`);
  if (creds) console.log('  Usuários criados (troque as senhas no primeiro acesso):\n   ' + creds.join('\n   ') + '\n');
});
