'use strict';
const STATUS = ['Aberto', 'Em andamento', 'Aguardando', 'Resolvido', 'Fechado'];
const PRIO = ['Baixa', 'Média', 'Alta', 'Crítica'];
const ROLE_LABEL = { admin: 'Administrador', tech: 'Técnico', user: 'Solicitante' };
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const fmt = d => d ? new Date(d).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' }) : '—';
const opts = (arr, sel) => arr.map(o => `<option value="${esc(o)}" ${o === sel ? 'selected' : ''}>${esc(o)}</option>`).join('');
const tag = (t, v) => `<span class="tag ${t}-${esc(String(v).replace(/ /g, '-'))}">${esc(v)}</span>`;
const isStaff = () => state.user && state.user.role !== 'user';
const state = { user: null, brand: null, categories: [], users: [], list: { status: '', priority: '', category: '', assignee: '', overdue: false, sort: 'created', dir: 'desc', page: 1, q: '' } };

/* ---------- utilidades ---------- */
async function api(method, url, body) {
  const r = await fetch(url, { method, headers: body !== undefined ? { 'Content-Type': 'application/json' } : {}, body: body !== undefined ? JSON.stringify(body) : undefined });
  let d = null; try { d = await r.json(); } catch { /* sem corpo */ }
  if (r.status === 401 && state.user) { state.user = null; showLogin(); throw new Error('Sessão expirada. Entre novamente.'); }
  if (!r.ok) throw new Error((d && d.error) || 'Erro inesperado');
  return d;
}
function toast(msg, err) {
  const t = $('#toast'); t.textContent = msg; t.className = 'toast' + (err ? ' error' : '');
  clearTimeout(toast.t); toast.t = setTimeout(() => t.classList.add('hidden'), 3200);
}
const guard = fn => async (...a) => { try { await fn(...a); } catch (e) { toast(e.message, true); } };
function openModal(html) { $('#modalBox').innerHTML = html; $('#modal').classList.remove('hidden'); return $('#modalBox'); }
function closeModal() { $('#modal').classList.add('hidden'); }
$('#modal').addEventListener('mousedown', e => { if (e.target.id === 'modal' && !state.lockModal) closeModal(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape' && !state.lockModal) closeModal(); });
const debounce = (fn, ms) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };
const ago = ms => { const m = Math.round((Date.now() - ms) / 60000); return m < 1 ? 'agora' : m < 60 ? `${m} min` : m < 1440 ? `${Math.round(m / 60)} h` : `${Math.round(m / 1440)} d`; };
function slaBadge(t) {
  if (t.resolved_at) return t.sla === 'breached' ? '<span class="sla-breached">✖ Fora do SLA</span>' : '<span class="sla-ok">✔ Dentro do SLA</span>';
  const label = { ok: '● No prazo', warning: '▲ Vencendo', breached: '✖ Atrasado' }[t.sla];
  return `<span class="sla-${t.sla}">${label}</span>`;
}
const file2b64 = f => new Promise((res, rej) => { const r = new FileReader(); r.onload = () => res(String(r.result).split(',')[1]); r.onerror = rej; r.readAsDataURL(f); });

/* ---------- identidade visual ---------- */
function applyBrand(b) {
  state.brand = b;
  const r = document.documentElement.style;
  r.setProperty('--brand-dark', b.color_dark); r.setProperty('--brand', b.color_primary); r.setProperty('--brand-accent', b.color_accent);
  $$('.company-name').forEach(e => e.textContent = b.company);
  document.title = `${b.company} | Helpdesk de TI`;
  ['sideLogo', 'loginLogo'].forEach(id => { const i = $('#' + id); i.src = b.logo || '/logo.png'; i.alt = b.company; });
  $('#showRegister').classList.toggle('hidden', !b.allow_register);
}
async function loadBrand() { applyBrand(await api('GET', '/api/branding')); }

/* ---------- login / sessão ---------- */
function showLogin() { $('#appShell').classList.add('hidden'); $('#loginScreen').classList.remove('hidden'); clearInterval(state.poll); loadBrand().catch(() => {}); }
$('#loginForm').addEventListener('submit', async e => {
  e.preventDefault(); $('#loginError').textContent = '';
  const f = new FormData(e.target);
  try { state.user = await api('POST', '/api/login', { email: f.get('email'), password: f.get('password') }); e.target.reset(); await startApp(); }
  catch (err) { $('#loginError').textContent = err.message; }
});
$('#showRegister').addEventListener('click', () => {
  const m = openModal(`<h2>Criar conta</h2><form id="regForm">
    <label>Nome completo*<input name="name" required></label><label>E-mail*<input name="email" type="email" required></label>
    <label>Departamento<input name="dept"></label><label>Senha* <span class="hint">(mín. 8 caracteres)</span><input name="password" type="password" minlength="8" required></label>
    <div class="actions"><button type="button" class="btn" data-close>Cancelar</button><button class="btn primary">Cadastrar</button></div></form>`);
  $('[data-close]', m).onclick = closeModal;
  $('#regForm').onsubmit = guard(async e => { e.preventDefault(); await api('POST', '/api/register', Object.fromEntries(new FormData(e.target))); closeModal(); toast('Conta criada! Faça login.'); });
});
$('#logoutBtn').onclick = async () => { await api('POST', '/api/logout', {}).catch(() => {}); state.user = null; showLogin(); };
$('#pwBtn').onclick = () => passwordModal(false);

function passwordModal(forced) {
  state.lockModal = forced;
  const m = openModal(`<h2>${forced ? 'Defina uma nova senha' : 'Alterar senha'}</h2>${forced ? '<p class="hint" style="margin-bottom:12px">Por segurança, troque a senha provisória antes de continuar.</p>' : ''}
    <form id="pwForm"><label>Senha atual<input name="current" type="password" required autocomplete="current-password"></label>
    <label>Nova senha <span class="hint">(mín. 8 caracteres)</span><input name="new" type="password" minlength="8" required autocomplete="new-password"></label>
    <label>Confirmar nova senha<input name="confirm" type="password" minlength="8" required autocomplete="new-password"></label>
    <div class="actions">${forced ? '' : '<button type="button" class="btn" data-close>Cancelar</button>'}<button class="btn primary">Salvar</button></div></form>`);
  const c = $('[data-close]', m); if (c) c.onclick = closeModal;
  $('#pwForm').onsubmit = guard(async e => {
    e.preventDefault(); const d = Object.fromEntries(new FormData(e.target));
    if (d.new !== d.confirm) throw new Error('A confirmação não confere');
    await api('POST', '/api/me/password', { current: d.current, new: d.new });
    state.user.must_change = false; state.lockModal = false; closeModal(); toast('Senha alterada com sucesso');
  });
}

async function startApp() {
  $('#loginScreen').classList.add('hidden'); $('#appShell').classList.remove('hidden');
  $('#meName').textContent = state.user.name; $('#meRole').textContent = ROLE_LABEL[state.user.role];
  const nav = [['dashboard', '📊', 'Painel'], ['tickets', '🎫', isStaff() ? 'Chamados' : 'Meus chamados'], ['new', '➕', 'Novo chamado'], ['kb', '📚', 'Base de conhecimento']];
  if (state.user.role === 'admin') nav.push(['users', '👥', 'Usuários'], ['settings', '⚙️', 'Configurações']);
  $('#nav').innerHTML = nav.map(([k, i, l]) => `<a href="#/${k}" data-k="${k}">${i} ${l}</a>`).join('');
  [state.categories, state.users] = await Promise.all([api('GET', '/api/categories'), isStaff() ? api('GET', '/api/users') : []]);
  loadNotifs(); clearInterval(state.poll); state.poll = setInterval(loadNotifs, 30000);
  if (!location.hash) location.hash = '#/dashboard'; else router();
  if (state.user.must_change) passwordModal(true);
}

/* ---------- notificações ---------- */
async function loadNotifs() {
  try {
    const d = await api('GET', '/api/notifications'); state.notifs = d;
    $('#bellCount').textContent = d.unread; $('#bellCount').classList.toggle('hidden', !d.unread);
  } catch { /* ignora */ }
}
$('#bell').onclick = async e => {
  e.stopPropagation(); const m = $('#bellMenu');
  if (!m.classList.contains('hidden')) return m.classList.add('hidden');
  await loadNotifs(); const d = state.notifs;
  m.innerHTML = d.items.length ? d.items.map(n => `<div class="item ${n.read ? '' : 'unread'}" data-t="${n.ticket_id || ''}">${esc(n.text)}<small>há ${ago(n.created)}</small></div>`).join('') : '<div class="none">Sem notificações</div>';
  m.classList.remove('hidden');
  $$('.item', m).forEach(i => i.onclick = () => { if (i.dataset.t) location.hash = '#/ticket/' + i.dataset.t; });
  if (d.unread) { await api('POST', '/api/notifications/read', {}); setTimeout(loadNotifs, 400); }
};
document.addEventListener('click', e => { if (!e.target.closest('.bell-wrap')) $('#bellMenu').classList.add('hidden'); });
$('#menuBtn').onclick = () => $('#sidebar').classList.toggle('open');
$('#nav').addEventListener('click', () => $('#sidebar').classList.remove('open'));
$('#globalSearch').addEventListener('input', debounce(e => {
  state.list.q = e.target.value.trim(); state.list.page = 1;
  if (location.hash === '#/tickets') router(); else location.hash = '#/tickets';
}, 300));

/* ---------- roteador ---------- */
const TITLES = { dashboard: 'Painel', tickets: 'Chamados', new: 'Novo chamado', kb: 'Base de conhecimento', users: 'Usuários', settings: 'Configurações', ticket: 'Chamado' };
async function router() {
  if (!state.user) return;
  const m = location.hash.match(/^#\/(\w+)(?:\/(\d+))?/) || [null, 'dashboard'];
  const name = TITLES[m[1]] ? m[1] : 'dashboard';
  if ((name === 'users' || name === 'settings') && state.user.role !== 'admin') return location.hash = '#/dashboard';
  $$('#nav a').forEach(a => a.classList.toggle('active', a.dataset.k === name || (name === 'ticket' && a.dataset.k === 'tickets')));
  $('#viewTitle').textContent = name === 'ticket' ? `Chamado #${m[2]}` : (name === 'tickets' && !isStaff() ? 'Meus chamados' : TITLES[name]);
  if (name !== 'tickets') { /* mantém busca visível */ }
  const view = { dashboard: viewDashboard, tickets: viewTickets, new: viewNew, kb: viewKb, users: viewUsers, settings: viewSettings, ticket: () => viewTicket(Number(m[2])) }[name];
  const token = state.routeToken = Symbol();
  try { await view(token); } catch (e) { if (state.routeToken === token) $('#content').innerHTML = `<div class="panel empty">${esc(e.message)}</div>`; }
}
window.addEventListener('hashchange', router);
const stale = t => state.routeToken !== t;

/* ---------- painel ---------- */
function barChart(map) {
  const max = Math.max(1, ...Object.values(map));
  return Object.entries(map).map(([k, n]) => `<div class="bar-row"><span>${esc(k)}</span><div class="bar"><i style="width:${n / max * 100}%"></i></div><b>${n}</b></div>`).join('');
}
function dailyChart(days) {
  const W = Math.min(1200, Math.max(300, $('#content').clientWidth - 40)), H = 170, pad = 22, max = Math.max(1, ...days.flatMap(d => [d.opened, d.resolved])), bw = (W - pad) / days.length;
  let s = `<svg class="chart" viewBox="0 0 ${W} ${H + 22}" role="img" aria-label="Chamados abertos e resolvidos nos últimos 14 dias">`;
  days.forEach((d, i) => {
    const x = pad + i * bw, h1 = d.opened / max * H, h2 = d.resolved / max * H;
    s += `<rect x="${x + 2}" y="${H - h1}" width="${bw / 2 - 3}" height="${h1}" rx="2" fill="var(--brand)"><title>${d.day}: ${d.opened} abertos</title></rect>`;
    s += `<rect x="${x + bw / 2}" y="${H - h2}" width="${bw / 2 - 3}" height="${h2}" rx="2" fill="var(--ok)"><title>${d.day}: ${d.resolved} resolvidos</title></rect>`;
    if (i % 3 === 0) s += `<text x="${x + bw / 2}" y="${H + 17}" font-size="12" text-anchor="middle" fill="#66768a">${d.day}</text>`;
  });
  s += `<line x1="${pad}" x2="${W}" y1="${H}" y2="${H}" stroke="#e2e8f0"/><text x="0" y="12" font-size="12" fill="#66768a">${max}</text></svg>`;
  return `<div class="legend"><span><i style="background:var(--brand)"></i>Abertos</span><span><i style="background:var(--ok)"></i>Resolvidos</span></div>${s}`;
}
function rows(items, withReq) {
  return items.map(t => `<tr class="click" data-id="${t.id}"><td data-l="#">#${t.id}</td><td class="title-cell" data-l="Assunto">${esc(t.title)}</td>${withReq ? `<td data-l="Solicitante">${esc(t.requester_name)}</td>` : ''}<td data-l="Categoria">${esc(t.category)}</td><td data-l="Prioridade">${tag('p', t.priority)}</td><td data-l="Status">${tag('s', t.status)}</td>${isStaff() ? `<td data-l="Técnico">${esc(t.assignee_name || '—')}</td>` : ''}<td data-l="Prazo (SLA)"><div class="due">${fmt(t.due)}<br>${slaBadge(t)}</div></td></tr>`).join('');
}
const bindRows = root => $$('tr[data-id]', root).forEach(r => r.onclick = () => location.hash = '#/ticket/' + r.dataset.id);
const tableHead = withReq => `<thead><tr><th>#</th><th>Assunto</th>${withReq ? '<th>Solicitante</th>' : ''}<th>Categoria</th><th>Prioridade</th><th>Status</th>${isStaff() ? '<th>Técnico</th>' : ''}<th>Prazo (SLA)</th></tr></thead>`;

async function viewDashboard(tok) {
  const staff = isStaff();
  const [s, att] = await Promise.all([api('GET', '/api/stats'), api('GET', '/api/tickets?status=open&sort=' + (staff ? 'due&dir=asc' : 'created') + '&limit=6')]);
  if (stale(tok)) return;
  const cards = staff ? `<div class="cards c4">
    <a class="stat" href="#/tickets" data-f="open"><b>${s.open}</b><span>Chamados em aberto</span></a>
    <a class="stat accent" href="#/tickets" data-f="none"><b>${s.unassigned}</b><span>Sem técnico</span></a>
    <a class="stat danger" href="#/tickets" data-f="overdue"><b>${s.overdue}</b><span>Atrasados (SLA)</span></a>
    <div class="stat ok"><b>${s.resolved}</b><span>Resolvidos</span></div></div><div class="cards c3">
    <div class="stat"><b>${s.slaCompliance === null ? '—' : s.slaCompliance + '%'}</b><span>SLA cumprido</span></div>
    <div class="stat"><b>${s.avgResolutionH === null ? '—' : s.avgResolutionH + ' h'}</b><span>Tempo médio de resolução</span></div>
    <div class="stat accent"><b>${s.avgRating === null ? '—' : '★ ' + s.avgRating}</b><span>Satisfação (${s.ratings} aval.)</span></div></div>` : `<div class="cards c3">
    <a class="stat" href="#/tickets" data-f="open"><b>${s.open}</b><span>Meus chamados em aberto</span></a>
    <div class="stat ok"><b>${s.resolved}</b><span>Resolvidos</span></div>
    <div class="stat accent"><b>${s.total}</b><span>Total de chamados</span></div></div>`;
  $('#content').innerHTML = `
    ${cards}
    ${staff ? `<div class="panel"><h3>Últimos 14 dias</h3>${dailyChart(s.daily)}</div>
    <div class="grid3"><div class="panel"><h3>Por status</h3>${barChart(s.byStatus)}</div><div class="panel"><h3>Por prioridade</h3>${barChart(s.byPriority)}</div><div class="panel"><h3>Por categoria</h3>${barChart(s.byCategory)}</div></div>
    <div class="panel"><h3>Carga por técnico</h3><div class="table-wrap"><table class="stack"><thead><tr><th>Técnico</th><th>Atribuídos</th><th>Resolvidos</th></tr></thead><tbody>${s.byTech.map(t => `<tr><td data-l="Técnico">${esc(t.name)}</td><td data-l="Atribuídos">${t.total}</td><td data-l="Resolvidos">${t.resolved || 0}</td></tr>`).join('')}</tbody></table></div></div>` : ''}
    <div class="panel flush"><h3 style="padding:16px 18px 0">${staff ? 'Exigem atenção (ordenados por prazo)' : 'Meus chamados em aberto'}</h3>
      ${att.items.length ? `<div class="table-wrap"><table class="stack">${tableHead(staff)}<tbody>${rows(att.items, staff)}</tbody></table></div>` : '<p class="empty">Nenhum chamado em aberto 🎉</p>'}</div>
    ${staff ? '' : '<div class="panel"><h3>Precisa de ajuda?</h3><p>Consulte a <a href="#/kb">base de conhecimento</a> ou <a href="#/new">abra um novo chamado</a>.</p></div>'}`;
  bindRows($('#content'));
  $$('a.stat[data-f]').forEach(a => a.onclick = () => {
    Object.assign(state.list, { status: '', assignee: '', overdue: false, q: '', page: 1 }); $('#globalSearch').value = '';
    const f = a.dataset.f; if (f === 'open') state.list.status = 'open'; if (f === 'none') { state.list.status = 'open'; state.list.assignee = 'none'; } if (f === 'overdue') state.list.overdue = true;
  });
}

/* ---------- lista de chamados ---------- */
async function viewTickets(tok) {
  const L = state.list, staff = isStaff();
  const qs = new URLSearchParams({ status: L.status, priority: L.priority, category: L.category, assignee: L.assignee, overdue: L.overdue ? '1' : '', sort: L.sort, dir: L.dir, page: L.page, q: L.q });
  const d = await api('GET', '/api/tickets?' + qs);
  if (stale(tok)) return;
  const techs = state.users.filter(u => u.role !== 'user');
  $('#content').innerHTML = `
    <div class="filters" style="--n:${staff ? 4 : 3}">
      <select id="fStatus"><option value="">Todos os status</option><option value="open" ${L.status === 'open' ? 'selected' : ''}>Em aberto</option>${opts(STATUS, L.status)}</select>
      <select id="fPrio"><option value="">Prioridades</option>${opts(PRIO, L.priority)}</select>
      <select id="fCat"><option value="">Categorias</option>${opts(state.categories.map(c => c.name), L.category)}</select>
      ${staff ? `<select id="fAssignee"><option value="">Todos os técnicos</option><option value="me" ${L.assignee === 'me' ? 'selected' : ''}>Atribuídos a mim</option><option value="none" ${L.assignee === 'none' ? 'selected' : ''}>Sem técnico</option>${techs.map(t => `<option value="${t.id}" ${String(t.id) === L.assignee ? 'selected' : ''}>${esc(t.name)}</option>`).join('')}</select>` : ''}
    </div>
    <div class="toolbar between">
      <div class="grp">
        <select id="fSort"><option value="created">Ordenar: abertura</option><option value="due" ${L.sort === 'due' ? 'selected' : ''}>Ordenar: prazo SLA</option><option value="priority" ${L.sort === 'priority' ? 'selected' : ''}>Ordenar: prioridade</option><option value="updated" ${L.sort === 'updated' ? 'selected' : ''}>Ordenar: atualização</option></select>
        <button class="btn" id="fDir" title="Inverter ordem">${L.dir === 'desc' ? '↓ Recentes' : '↑ Antigos'}</button>
        <label class="inline check"><input type="checkbox" id="fOver" ${L.overdue ? 'checked' : ''}> Só atrasados</label>
      </div>
      <div class="grp">
        ${staff ? '<button class="btn" id="exportBtn">⬇ CSV</button>' : ''}
        <button class="btn" id="clearBtn">Limpar</button>
        <a class="btn accent" href="#/new" style="text-decoration:none">➕ Novo</a>
      </div>
    </div>
    ${L.q ? `<p class="hint" style="margin-bottom:10px">Busca: “${esc(L.q)}”</p>` : ''}
    <div class="panel flush">
      ${d.items.length ? `<div class="table-wrap"><table class="stack">${tableHead(staff)}<tbody>${rows(d.items, staff)}</tbody></table></div>` : '<p class="empty">Nenhum chamado encontrado.</p>'}
      <div class="pager"><span>${d.total} chamado(s)</span><div><button class="btn sm" id="prev" ${d.page <= 1 ? 'disabled' : ''}>‹ Anterior</button><span>Página ${d.page} de ${d.pages}</span><button class="btn sm" id="next" ${d.page >= d.pages ? 'disabled' : ''}>Próxima ›</button></div></div>
    </div>`;
  bindRows($('#content'));
  const bind = (id, key, fn) => { const e = $(id); if (e) e.onchange = () => { L[key] = fn ? fn(e) : e.value; L.page = 1; router(); }; };
  bind('#fStatus', 'status'); bind('#fPrio', 'priority'); bind('#fCat', 'category'); bind('#fAssignee', 'assignee'); bind('#fSort', 'sort'); bind('#fOver', 'overdue', e => e.checked);
  $('#fDir').onclick = () => { L.dir = L.dir === 'desc' ? 'asc' : 'desc'; router(); };
  $('#clearBtn').onclick = () => { Object.assign(L, { status: '', priority: '', category: '', assignee: '', overdue: false, q: '', page: 1, sort: 'created', dir: 'desc' }); $('#globalSearch').value = ''; router(); };
  $('#prev').onclick = () => { L.page--; router(); }; $('#next').onclick = () => { L.page++; router(); };
  const ex = $('#exportBtn'); if (ex) ex.onclick = () => { qs.delete('page'); location.href = '/api/export.csv?' + qs; };
}

/* ---------- novo chamado ---------- */
async function viewNew(tok) {
  const staff = isStaff(), techs = state.users.filter(u => u.role !== 'user');
  $('#content').innerHTML = `<form id="newForm" class="panel">
    ${staff ? `<div class="form-grid"><label>Solicitante<select name="requester_id"><option value="">(eu mesmo)</option>${state.users.map(u => `<option value="${u.id}">${esc(u.name)} — ${esc(u.email)}</option>`).join('')}</select></label>
    <label>Técnico responsável<select name="assignee_id"><option value="">Não atribuído</option>${techs.map(u => `<option value="${u.id}">${esc(u.name)}</option>`).join('')}</select></label></div>` : ''}
    <div class="form-grid"><label>Categoria*<select name="category" required>${opts(state.categories.map(c => c.name))}</select></label>
    <label>Prioridade*<select name="priority" required>${opts(PRIO, 'Média')}</select><span class="hint" id="slaHint"></span></label></div>
    <label>Assunto*<input name="title" required maxlength="150" autocomplete="off"></label>
    <div id="suggest"></div>
    <label>Descrição*<textarea name="description" rows="7" required maxlength="10000" placeholder="Descreva o problema: o que aconteceu, quando começou, mensagens de erro…"></textarea></label>
    <label>Anexos <span class="hint">(até 5 MB cada)</span><input type="file" name="files" multiple></label>
    <div class="actions"><a class="btn" href="#/dashboard" style="text-decoration:none">Cancelar</a><button class="btn primary" id="sendBtn">Abrir chamado</button></div></form>`;
  const f = $('#newForm');
  const sugg = debounce(guard(async () => {
    const w = f.title.value.trim().split(/\s+/).filter(x => x.length > 3).sort((a, b) => b.length - a.length)[0];
    if (!w) return $('#suggest').innerHTML = '';
    const r = (await api('GET', '/api/kb?q=' + encodeURIComponent(w))).slice(0, 3);
    $('#suggest').innerHTML = r.length ? `<div class="suggest">💡 Estes artigos podem ajudar:${r.map(a => `<a href="#/kb" data-q="${esc(a.title)}">${esc(a.title)}</a>`).join('')}</div>` : '';
  }), 500);
  f.title.addEventListener('input', sugg);
  f.onsubmit = guard(async e => {
    e.preventDefault(); const btn = $('#sendBtn'); btn.disabled = true;
    try {
      const d = Object.fromEntries(['requester_id', 'assignee_id', 'category', 'priority', 'title', 'description'].filter(k => f[k]).map(k => [k, f[k].value]));
      const { id } = await api('POST', '/api/tickets', d);
      for (const file of f.files.files) {
        if (file.size > 5 * 1024 * 1024) { toast(`“${file.name}” excede 5 MB e não foi anexado`, true); continue; }
        await api('POST', `/api/tickets/${id}/attachments`, { name: file.name, type: file.type, data: await file2b64(file) });
      }
      toast(`Chamado #${id} aberto com sucesso`); location.hash = '#/ticket/' + id;
    } finally { btn.disabled = false; }
  });
}

/* ---------- detalhe do chamado ---------- */
async function viewTicket(id) {
  const tok = state.routeToken;
  const t = await api('GET', '/api/tickets/' + id);
  if (stale(tok)) return;
  const staff = isStaff(), mine = t.requester_id === state.user.id, closed = ['Resolvido', 'Fechado'].includes(t.status);
  const techs = state.users.filter(u => u.role !== 'user');
  const side = staff ? `<dl>
      <dt>Status</dt><dd><select id="eStatus">${opts(STATUS, t.status)}</select></dd>
      <dt>Prioridade</dt><dd><select id="ePrio">${opts(PRIO, t.priority)}</select></dd>
      <dt>Categoria</dt><dd><select id="eCat">${opts(state.categories.map(c => c.name), t.category)}</select></dd>
      <dt>Técnico</dt><dd><select id="eTech"><option value="">Não atribuído</option>${techs.map(u => `<option value="${u.id}" ${u.id === t.assignee_id ? 'selected' : ''}>${esc(u.name)}</option>`).join('')}</select></dd></dl>
      <div class="actions" style="margin-top:14px"><button class="btn" id="takeBtn">Assumir</button><button class="btn primary" id="saveBtn">Salvar</button></div>`
    : `<dl><dt>Status</dt><dd>${tag('s', t.status)}</dd><dt>Prioridade</dt><dd>${tag('p', t.priority)}</dd><dt>Categoria</dt><dd>${esc(t.category)}</dd><dt>Técnico</dt><dd>${esc(t.assignee_name || 'Aguardando atribuição')}</dd></dl>
      <div class="actions" style="margin-top:14px">${t.status === 'Resolvido' ? '<button class="btn primary" id="closeBtn">Confirmar solução (fechar)</button>' : ''}${closed ? '<button class="btn accent" id="reopenBtn">Reabrir chamado</button>' : ''}</div>`;
  const rating = mine && closed ? `<div class="panel"><h3>Avalie o atendimento</h3>${t.rating ? `<p>Sua nota: <b>${'★'.repeat(t.rating)}${'☆'.repeat(5 - t.rating)}</b>${t.rating_comment ? `<br><span class="hint">${esc(t.rating_comment)}</span>` : ''}</p>` :
    `<div class="stars" id="stars">${[1, 2, 3, 4, 5].map(n => `<button type="button" data-n="${n}" aria-label="${n} estrelas">★</button>`).join('')}</div><textarea id="rateText" rows="2" placeholder="Comentário (opcional)"></textarea><div class="actions"><button class="btn primary" id="rateBtn" disabled>Enviar avaliação</button></div>`}</div>` : (staff && t.rating ? `<div class="panel"><h3>Avaliação do solicitante</h3><p><b>${'★'.repeat(t.rating)}${'☆'.repeat(5 - t.rating)}</b> ${esc(t.rating_comment || '')}</p></div>` : '');
  $('#content').innerHTML = `
    <div class="t-head"><h2>${esc(t.title)}</h2>${tag('s', t.status)} ${tag('p', t.priority)}</div>
    <p class="hint" style="margin-bottom:16px">Aberto em ${fmt(t.created)} por <b>${esc(t.requester_name)}</b>${t.requester_dept ? ' · ' + esc(t.requester_dept) : ''}${staff ? ' · ' + esc(t.requester_email) : ''} &nbsp;|&nbsp; Prazo SLA: ${fmt(t.due)} ${slaBadge(t)}</p>
    <div class="t-layout"><div>
      <div class="panel"><h3>Descrição</h3><div class="desc">${esc(t.description)}</div>
        <h3>Anexos</h3>${t.attachments.map(a => `<div class="att"><span>📎 <a href="/api/attachments/${a.id}">${esc(a.name)}</a> <span class="hint">(${(a.size / 1024).toFixed(1)} KB · ${esc(a.author)})</span></span></div>`).join('') || '<p class="hint">Sem anexos.</p>'}
        <input type="file" id="attFile" multiple style="margin-top:8px"></div>
      ${rating}
      <div class="panel"><h3>Conversa</h3>
        ${t.comments.map(c => `<div class="msg ${c.internal ? 'internal' : c.role !== 'user' ? 'staff' : ''}"><small><b>${esc(c.author)}</b> · ${fmt(c.created)}${c.internal ? ' · 🔒 nota interna (visível só à TI)' : ''}</small><div>${esc(c.text)}</div></div>`).join('') || '<p class="hint">Nenhuma mensagem ainda.</p>'}
        <form id="cForm" style="margin-top:14px"><textarea id="cText" rows="3" placeholder="Escreva uma resposta…" required maxlength="10000"></textarea>
        <div class="actions" style="align-items:center">${staff ? '<label class="inline" style="margin:0 auto 0 0"><input type="checkbox" id="cInt"> Nota interna</label>' : ''}<button class="btn primary">Enviar</button></div></form></div>
    </div>
    <div class="side"><div class="panel"><h3>Detalhes</h3>${side}</div>
      <div class="panel"><h3>Histórico</h3><ul class="timeline">${t.history.map(h => `<li>${esc(h.detail)}<small>${esc(h.author || 'Sistema')} · ${fmt(h.created)}</small></li>`).join('')}</ul></div></div></div>`;
  const patch = guard(async body => { await api('PATCH', '/api/tickets/' + id, body); toast('Chamado atualizado'); router(); });
  const save = $('#saveBtn');
  if (save) {
    save.onclick = () => patch({ status: $('#eStatus').value, priority: $('#ePrio').value, category: $('#eCat').value, assignee_id: $('#eTech').value ? Number($('#eTech').value) : null });
    $('#takeBtn').onclick = () => patch({ assignee_id: state.user.id, ...(t.status === 'Aberto' ? { status: 'Em andamento' } : {}) });
  }
  const cb = $('#closeBtn'); if (cb) cb.onclick = () => patch({ status: 'Fechado' });
  const rb = $('#reopenBtn'); if (rb) rb.onclick = () => patch({ status: 'Aberto' });
  $('#cForm').onsubmit = guard(async e => {
    e.preventDefault(); const i = $('#cInt');
    await api('POST', `/api/tickets/${id}/comments`, { text: $('#cText').value, internal: i ? i.checked : false }); router();
  });
  $('#attFile').onchange = guard(async e => {
    for (const f of e.target.files) {
      if (f.size > 5 * 1024 * 1024) { toast(`“${f.name}” excede 5 MB`, true); continue; }
      await api('POST', `/api/tickets/${id}/attachments`, { name: f.name, type: f.type, data: await file2b64(f) });
    }
    router();
  });
  const stars = $('#stars');
  if (stars) {
    let n = 0;
    $$('button', stars).forEach(b => b.onclick = () => { n = Number(b.dataset.n); $$('button', stars).forEach(x => x.classList.toggle('on', Number(x.dataset.n) <= n)); $('#rateBtn').disabled = false; });
    $('#rateBtn').onclick = guard(async () => { await api('POST', `/api/tickets/${id}/rating`, { rating: n, comment: $('#rateText').value }); toast('Obrigado pela avaliação!'); router(); });
  }
}

/* ---------- base de conhecimento ---------- */
async function viewKb(tok) {
  const q = state.kbq || '';
  const items = await api('GET', '/api/kb?q=' + encodeURIComponent(q));
  if (stale(tok)) return;
  $('#content').innerHTML = `<div class="toolbar"><input type="search" id="kbSearch" placeholder="Buscar artigos…" value="${esc(q)}" style="max-width:380px">${isStaff() ? '<span class="grow"></span><button class="btn accent" id="kbNew">➕ Novo artigo</button>' : ''}</div>
    ${items.map(a => `<details class="kb-item"><summary>${esc(a.title)} ${a.category ? `<span class="tag role-user">${esc(a.category)}</span>` : ''}</summary><div class="kb-body">${esc(a.body)}</div>
      ${isStaff() ? `<div class="kb-actions"><button class="btn sm" data-edit="${a.id}">Editar</button><button class="btn sm danger" data-del="${a.id}">Excluir</button></div>` : ''}</details>`).join('') || '<div class="panel empty">Nenhum artigo encontrado.</div>'}`;
  const s = $('#kbSearch'); s.oninput = debounce(() => { state.kbq = s.value; router().then(() => { const n = $('#kbSearch'); n.focus(); n.setSelectionRange(99, 99); }); }, 350);
  const form = a => {
    const m = openModal(`<h2>${a ? 'Editar' : 'Novo'} artigo</h2><form id="kbForm"><label>Título*<input name="title" required maxlength="150" value="${esc(a?.title)}"></label>
      <label>Categoria<select name="category"><option value="">—</option>${opts(state.categories.map(c => c.name), a?.category)}</select></label>
      <label>Conteúdo*<textarea name="body" rows="9" required>${esc(a?.body)}</textarea></label>
      <div class="actions"><button type="button" class="btn" data-close>Cancelar</button><button class="btn primary">Salvar</button></div></form>`);
    $('[data-close]', m).onclick = closeModal;
    $('#kbForm').onsubmit = guard(async e => { e.preventDefault(); const d = Object.fromEntries(new FormData(e.target)); await (a ? api('PUT', '/api/kb/' + a.id, d) : api('POST', '/api/kb', d)); closeModal(); toast('Artigo salvo'); router(); });
  };
  const nb = $('#kbNew'); if (nb) nb.onclick = () => form(null);
  $$('[data-edit]').forEach(b => b.onclick = () => form(items.find(a => a.id === Number(b.dataset.edit))));
  $$('[data-del]').forEach(b => b.onclick = guard(async () => { if (confirm('Excluir este artigo?')) { await api('DELETE', '/api/kb/' + b.dataset.del); router(); } }));
}

/* ---------- usuários (admin) ---------- */
async function viewUsers(tok) {
  const users = await api('GET', '/api/users'); state.users = users.filter(u => u.active);
  if (stale(tok)) return;
  $('#content').innerHTML = `<div class="toolbar"><span class="grow"></span><button class="btn accent" id="uNew">➕ Novo usuário</button></div>
    <div class="panel flush"><div class="table-wrap"><table class="stack"><thead><tr><th>Nome</th><th>E-mail</th><th>Departamento</th><th>Perfil</th><th>Situação</th><th></th></tr></thead><tbody>
    ${users.map(u => `<tr class="${u.active ? '' : 'off'}"><td data-l="Nome"><b>${esc(u.name)}</b></td><td data-l="E-mail">${esc(u.email)}</td><td data-l="Departamento">${esc(u.dept || '—')}</td><td data-l="Perfil"><span class="tag role-${u.role}">${ROLE_LABEL[u.role]}</span></td><td data-l="Situação">${u.active ? 'Ativo' : 'Inativo'}</td><td data-l=""><button class="btn sm" data-edit="${u.id}">Editar</button></td></tr>`).join('')}</tbody></table></div></div>`;
  const form = u => {
    const m = openModal(`<h2>${u ? 'Editar usuário' : 'Novo usuário'}</h2><form id="uForm">
      <label>Nome*<input name="name" required value="${esc(u?.name)}"></label>
      <label>E-mail*<input name="email" type="email" required value="${esc(u?.email)}" ${u ? 'disabled' : ''}></label>
      <div class="form-grid"><label>Departamento<input name="dept" value="${esc(u?.dept)}"></label>
      <label>Perfil<select name="role">${Object.entries(ROLE_LABEL).map(([k, v]) => `<option value="${k}" ${u?.role === k ? 'selected' : ''}>${v}</option>`).join('')}</select></label></div>
      <label>${u ? 'Redefinir senha' : 'Senha provisória*'} <span class="hint">(mín. 8 caracteres${u ? '; deixe em branco para manter' : ''}; será pedida a troca no primeiro acesso)</span><input name="password" type="text" minlength="8" ${u ? '' : 'required'} autocomplete="off"></label>
      ${u ? `<label class="inline"><input type="checkbox" name="active" ${u.active ? 'checked' : ''}> Usuário ativo</label>` : ''}
      <div class="actions"><button type="button" class="btn" data-close>Cancelar</button><button class="btn primary">Salvar</button></div></form>`);
    $('[data-close]', m).onclick = closeModal;
    $('#uForm').onsubmit = guard(async e => {
      e.preventDefault(); const f = e.target;
      const d = { name: f.name.value, dept: f.dept.value, role: f.role.value }; if (f.password.value) d.password = f.password.value;
      if (u) { d.active = f.active.checked; await api('PUT', '/api/users/' + u.id, d); } else { d.email = f.email.value; await api('POST', '/api/users', d); }
      closeModal(); toast('Usuário salvo'); router();
    });
  };
  $('#uNew').onclick = () => form(null);
  $$('[data-edit]').forEach(b => b.onclick = () => form(users.find(u => u.id === Number(b.dataset.edit))));
}

/* ---------- configurações (admin) ---------- */
async function viewSettings(tok) {
  const [s, cats] = await Promise.all([api('GET', '/api/settings'), api('GET', '/api/categories')]);
  state.categories = cats; if (stale(tok)) return;
  $('#content').innerHTML = `
    <form id="brandForm" class="panel"><h3>Identidade visual</h3>
      <div class="form-grid"><label>Nome da empresa<input name="company" value="${esc(s.company)}" required maxlength="60"></label></div>
      <div class="form-grid"><label>Cor principal (menu / títulos)<br><input type="color" name="color_dark" value="${s.color_dark}"></label>
      <label>Cor de destaque 1 (botões / links)<br><input type="color" name="color_primary" value="${s.color_primary}"></label>
      <label>Cor de destaque 2 (detalhes)<br><input type="color" name="color_accent" value="${s.color_accent}"></label></div>
      <p class="hint" style="margin-bottom:10px">Ajuste as cores conforme o logotipo da empresa.</p>
      <label class="inline"><input type="checkbox" name="allow_register" ${s.allow_register === '1' ? 'checked' : ''}> Permitir auto-cadastro de solicitantes na tela de login</label>
      <h3 style="margin-top:14px">SLA — prazo de resolução (horas)</h3>
      <div class="form-grid">${PRIO.map(p => `<label>${p}<input type="number" min="1" max="1000" step="0.5" name="sla_${p}" value="${s['sla_' + p]}"></label>`).join('')}</div>
      <div class="actions"><button class="btn primary">Salvar configurações</button></div></form>
    <div class="panel"><h3>Logotipo</h3><div class="logo-preview"><img class="logo-img" src="${state.brand.logo || '/logo.png'}" alt="Logotipo atual"></div><br>
      <input type="file" id="logoFile" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml" style="max-width:340px"> ${state.brand.logo ? '<button class="btn danger sm" id="logoDel">Restaurar logo padrão</button>' : ''}
      <p class="hint" style="margin-top:6px">PNG, JPG, WEBP, GIF ou SVG, até ~500 KB. Prefira fundo transparente.</p></div>
    <div class="panel"><h3>Categorias de chamado</h3><div class="chips">${cats.map(c => `<span class="chip">${esc(c.name)}<button data-del="${c.id}" title="Remover">×</button></span>`).join('')}</div>
      <form id="catForm" class="toolbar" style="margin:14px 0 0"><input name="name" placeholder="Nova categoria" required maxlength="60" style="max-width:260px"><button class="btn primary">Adicionar</button></form></div>`;
  $('#brandForm').onsubmit = guard(async e => {
    e.preventDefault(); const f = e.target, d = Object.fromEntries(new FormData(f)); d.allow_register = f.allow_register.checked;
    await api('PUT', '/api/settings', d); await loadBrand(); toast('Configurações salvas'); router();
  });
  $('#logoFile').onchange = guard(async e => {
    const f = e.target.files[0]; if (!f) return;
    if (f.size > 500 * 1024) throw new Error('Imagem maior que 500 KB');
    const data = `data:${f.type};base64,${await file2b64(f)}`;
    await api('PUT', '/api/settings/logo', { data }); await loadBrand(); toast('Logotipo atualizado'); router();
  });
  const ld = $('#logoDel'); if (ld) ld.onclick = guard(async () => { await api('DELETE', '/api/settings/logo'); await loadBrand(); router(); });
  $('#catForm').onsubmit = guard(async e => { e.preventDefault(); await api('POST', '/api/categories', { name: e.target.name.value }); router(); });
  $$('[data-del]').forEach(b => b.onclick = guard(async () => { if (confirm('Remover categoria? Chamados existentes mantêm o nome.')) { await api('DELETE', '/api/categories/' + b.dataset.del); router(); } }));
}

/* ---------- inicialização ---------- */
(async function init() {
  try { await loadBrand(); state.user = await api('GET', '/api/me'); await startApp(); }
  catch { state.user = null; showLogin(); }
})();
