# MediaNova Helpdesk — Sistema de chamados de TI

Front-end em **HTML + CSS + JavaScript** puros e um pequeno servidor **Node.js** com banco **SQLite**.
Não há dependências para instalar: basta o **Node.js 22.5 ou superior**.

## Como executar
```
cd helpdesk
node server.js        # ou ./iniciar.sh  /  iniciar.bat (Windows)
```
Acesse http://localhost:3000 (porta alterável com `PORT=8080`). O banco fica em `data/helpdesk.db`
(alterável com `DB_PATH`). Para produção, use `SEED_DEMO=0` na primeira execução (cria só o admin e as categorias).

### Acessos iniciais (troca de senha obrigatória no primeiro login)
| Perfil | E-mail | Senha |
|---|---|---|
| Administrador | admin@medianova.com | Admin@123 |
| Técnico | tecnico@medianova.com | Tecnico@123 |
| Solicitante | usuario@medianova.com | Usuario@123 |

## Recursos
- **Login** com sessão segura (cookie HttpOnly), senhas com scrypt, bloqueio após 5 tentativas erradas.
- **Perfis:** Administrador, Técnico (TI) e Solicitante (vê e abre apenas os próprios chamados).
- **Chamados:** prioridade, categoria, status (Aberto → Em andamento → Aguardando → Resolvido → Fechado), atribuição a técnicos, anexos (até 5 MB), conversa com **notas internas** (visíveis só à TI) e histórico completo de alterações.
- **SLA** por prioridade (configurável), com alertas de "vencendo" e "atrasado".
- **Avaliação** do atendimento (1–5 estrelas) e reabertura pelo solicitante.
- **Painel** com indicadores, gráfico dos últimos 14 dias, carga por técnico, SLA cumprido, tempo médio e satisfação.
- **Notificações** internas (novo chamado, atribuição, respostas, mudança de status).
- **Base de conhecimento** com busca e sugestão de artigos ao abrir chamado.
- **Filtros, busca, ordenação, paginação** e exportação **CSV**.
- **Administração:** usuários, categorias, SLA, auto-cadastro opcional.
- **Identidade visual:** em *Configurações* envie o logotipo e escolha as cores da MediaNova — o layout inteiro se adapta.
- Responsivo (celular/tablet).

## Estrutura
```
server.js   API REST + arquivos estáticos     db.js   esquema SQLite, senhas e dados iniciais
public/     index.html · style.css · app.js
```
