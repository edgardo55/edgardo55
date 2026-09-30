const STATUS=["Aberto","Em andamento","Aguardando","Resolvido","Fechado"];
const PRIO=["Baixa","Média","Alta","Crítica"];
const CATS=["Hardware","Software","Rede / Internet","E-mail","Acessos / Senhas","Impressora","Telefonia","Outros"];
const TECHS=["Não atribuído","Equipe TI","Técnico 1","Técnico 2"];
const KEY="medianova_helpdesk_v1";
const $=s=>document.querySelector(s);
const esc=s=>String(s??"").replace(/[&<>"']/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c]));
const fmt=d=>new Date(d).toLocaleString("pt-BR",{dateStyle:"short",timeStyle:"short"});
let tickets=load();

function seed(){
  const now=Date.now(),h=3600e3;
  return [
    {id:1001,title:"Computador não liga",requester:"Ana Souza",email:"ana@medianova.com",dept:"Financeiro",category:"Hardware",priority:"Alta",status:"Em andamento",tech:"Técnico 1",description:"O PC não dá sinal de vídeo ao ligar.",created:now-30*h,comments:[]},
    {id:1002,title:"Acesso à VPN bloqueado",requester:"Carlos Lima",email:"carlos@medianova.com",dept:"NOC",category:"Acessos / Senhas",priority:"Crítica",status:"Aberto",tech:"Não atribuído",description:"Após troca de senha a VPN recusa login.",created:now-3*h,comments:[]},
    {id:1003,title:"Impressora do 2º andar sem toner",requester:"Marina Reis",email:"",dept:"RH",category:"Impressora",priority:"Baixa",status:"Resolvido",tech:"Equipe TI",description:"Toner substituído.",created:now-72*h,comments:[{author:"Equipe TI",text:"Toner trocado.",internal:false,date:now-70*h}]}
  ];
}
function load(){try{const d=JSON.parse(localStorage.getItem(KEY));if(Array.isArray(d))return d}catch(e){}const s=seed();localStorage.setItem(KEY,JSON.stringify(s));return s}
function save(){localStorage.setItem(KEY,JSON.stringify(tickets))}
function toast(m){const t=$("#toast");t.textContent=m;t.classList.remove("hidden");setTimeout(()=>t.classList.add("hidden"),2500)}

const tag=(t,v)=>`<span class="tag ${t}-${esc(v)}">${esc(v)}</span>`;
const opts=(arr,sel)=>arr.map(o=>`<option ${o===sel?"selected":""}>${o}</option>`).join("");

/* Navegação */
const titles={dashboard:"Painel",tickets:"Chamados",new:"Novo chamado"};
function show(v){
  document.querySelectorAll(".view").forEach(e=>e.classList.add("hidden"));
  $("#view-"+v).classList.remove("hidden");
  document.querySelectorAll(".nav-btn").forEach(b=>b.classList.toggle("active",b.dataset.view===v));
  $("#viewTitle").textContent=titles[v];
  render();
}
document.querySelectorAll(".nav-btn").forEach(b=>b.onclick=()=>show(b.dataset.view));

/* Painel */
function renderDash(){
  const open=tickets.filter(t=>!["Resolvido","Fechado"].includes(t.status));
  const c=[["Total",tickets.length],["Abertos",tickets.filter(t=>t.status==="Aberto").length],["Em andamento",tickets.filter(t=>t.status==="Em andamento").length],["Críticos abertos",open.filter(t=>t.priority==="Crítica").length],["Resolvidos",tickets.filter(t=>["Resolvido","Fechado"].includes(t.status)).length]];
  $("#statCards").innerHTML=c.map(([l,n])=>`<div class="stat"><b>${n}</b><span>${l}</span></div>`).join("");
  const chart=(arr,key)=>{const max=Math.max(1,...arr.map(a=>tickets.filter(t=>t[key]===a).length));return arr.map(a=>{const n=tickets.filter(t=>t[key]===a).length;return `<div class="bar-row"><span>${a}</span><div class="bar"><i style="width:${n/max*100}%"></i></div><b>${n}</b></div>`}).join("")};
  $("#chartPrio").innerHTML=chart(PRIO,"priority");
  $("#chartCat").innerHTML=chart(CATS,"category");
  const r=[...tickets].sort((a,b)=>b.created-a.created).slice(0,5);
  $("#recent").innerHTML=`<table><tbody>${r.map(rowHtml).join("")}</tbody></table>`;
  bindRows("#recent");
}
function rowHtml(t){return `<tr data-id="${t.id}"><td>#${t.id}</td><td>${esc(t.title)}</td><td>${esc(t.requester)}</td><td>${esc(t.category)}</td><td>${tag("p",t.priority)}</td><td>${tag("s",t.status)}</td><td>${esc(t.tech)}</td><td>${fmt(t.created)}</td></tr>`}
function bindRows(sel){document.querySelectorAll(sel+" tr[data-id]").forEach(r=>r.onclick=()=>openTicket(+r.dataset.id))}

/* Lista */
function renderList(){
  const q=$("#globalSearch").value.trim().toLowerCase();
  const f={status:$("#fStatus").value,priority:$("#fPrio").value,category:$("#fCat").value,tech:$("#fTech").value};
  const list=tickets.filter(t=>Object.entries(f).every(([k,v])=>!v||t[k]===v)&&(!q||[t.id,t.title,t.requester,t.email,t.dept,t.description].join(" ").toLowerCase().includes(q))).sort((a,b)=>b.created-a.created);
  $("#ticketRows").innerHTML=list.map(rowHtml).join("");
  $("#emptyMsg").classList.toggle("hidden",list.length>0);
  bindRows("#ticketRows");
}
function render(){renderDash();renderList()}

/* Detalhe */
function openTicket(id){
  const t=tickets.find(x=>x.id===id);if(!t)return;
  $("#detail").innerHTML=`
  <h2>#${t.id} — ${esc(t.title)}</h2>
  <p style="color:var(--muted);font-size:13px">Aberto em ${fmt(t.created)} por ${esc(t.requester)} ${t.email?`(${esc(t.email)})`:""} ${t.dept?"· "+esc(t.dept):""}</p>
  <div class="meta">
    <label>Status<select id="dStatus">${opts(STATUS,t.status)}</select></label>
    <label>Prioridade<select id="dPrio">${opts(PRIO,t.priority)}</select></label>
    <label>Categoria<select id="dCat">${opts(CATS,t.category)}</select></label>
    <label>Técnico<select id="dTech">${opts(TECHS,t.tech)}</select></label>
  </div>
  <div class="desc">${esc(t.description)}</div>
  <h3>Histórico</h3>
  <div>${t.comments.map(c=>`<div class="comment ${c.internal?"internal":""}"><small>${esc(c.author)} · ${fmt(c.date)} ${c.internal?"· nota interna":""}</small><div>${esc(c.text)}</div></div>`).join("")||'<p class="empty">Sem comentários.</p>'}</div>
  <div class="addc">
    <textarea id="cText" rows="3" placeholder="Adicionar comentário…"></textarea>
    <div class="r"><label><input type="checkbox" id="cInt" style="width:auto"> Nota interna</label>
    <span><button class="btn primary" id="cAdd">Comentar</button> <button class="btn accent" id="dSave">Salvar alterações</button></span></div>
  </div>
  <p style="margin-top:14px"><button class="btn" id="dDel" style="background:#fdeaea;color:var(--danger)">Excluir chamado</button></p>`;
  $("#modal").classList.remove("hidden");
  $("#dSave").onclick=()=>{t.status=$("#dStatus").value;t.priority=$("#dPrio").value;t.category=$("#dCat").value;t.tech=$("#dTech").value;save();render();toast("Chamado atualizado");closeModal()};
  $("#cAdd").onclick=()=>{const x=$("#cText").value.trim();if(!x)return;t.comments.push({author:"Equipe TI",text:x,internal:$("#cInt").checked,date:Date.now()});save();openTicket(id)};
  $("#dDel").onclick=()=>{if(confirm("Excluir este chamado?")){tickets=tickets.filter(x=>x.id!==id);save();render();closeModal()}};
}
function closeModal(){$("#modal").classList.add("hidden")}
$("#closeModal").onclick=closeModal;
$("#modal").onclick=e=>{if(e.target.id==="modal")closeModal()};
document.addEventListener("keydown",e=>{if(e.key==="Escape")closeModal()});

/* Novo */
const f=$("#ticketForm");
f.category.innerHTML=opts(CATS);f.priority.innerHTML=opts(PRIO,"Média");f.tech.innerHTML=opts(TECHS);
f.onsubmit=e=>{
  e.preventDefault();const d=Object.fromEntries(new FormData(f));
  const id=Math.max(1000,...tickets.map(t=>t.id))+1;
  tickets.push({id,...d,title:d.title.trim(),status:"Aberto",created:Date.now(),comments:[]});
  save();f.reset();f.priority.value="Média";toast("Chamado #"+id+" aberto");show("tickets");
};

/* Filtros */
[["#fStatus",STATUS],["#fPrio",PRIO],["#fCat",CATS],["#fTech",TECHS]].forEach(([s,a])=>{$(s).insertAdjacentHTML("beforeend",opts(a));$(s).onchange=renderList});
$("#globalSearch").oninput=()=>{if($("#view-tickets").classList.contains("hidden"))show("tickets");else renderList()};

/* Exportar / reset */
$("#exportBtn").onclick=()=>{
  const h=["ID","Assunto","Solicitante","E-mail","Departamento","Categoria","Prioridade","Status","Técnico","Abertura"];
  const q=v=>`"${String(v??"").replace(/"/g,'""')}"`;
  const rows=tickets.map(t=>[t.id,t.title,t.requester,t.email,t.dept,t.category,t.priority,t.status,t.tech,fmt(t.created)].map(q).join(";"));
  const a=document.createElement("a");a.href=URL.createObjectURL(new Blob(["﻿"+[h.map(q).join(";"),...rows].join("\n")],{type:"text/csv"}));a.download="chamados.csv";a.click();
};
$("#resetBtn").onclick=()=>{if(confirm("Substituir todos os chamados pelos dados de exemplo?")){tickets=seed();save();render();toast("Dados restaurados")}};

render();
