# Helpdesk Medianova – Sistema de chamados de TI

Sistema em PHP (8+) com SQLite, layout nas cores da Medianova (azul #3B2A8C e vermelho #E4161F).

## Como executar
```bash
cd helpdesk/public
php -S 0.0.0.0:8080
```
Acesse http://localhost:8080 — login inicial: `admin@medianova.com` / `admin123` (altere em `config.php` antes da primeira execução e troque a senha em *Usuários*).

Em Apache/Nginx, aponte o DocumentRoot para `helpdesk/public` e dê permissão de escrita em `helpdesk/data`. Requer extensão `pdo_sqlite`.

## Recursos
- Perfis: Usuário, Técnico TI e Administrador
- Abertura de chamados (categoria, prioridade), histórico de respostas e notas internas (só TI)
- Painel com indicadores, filtros/busca, atribuição de técnico, status e prioridade
- Usuário fecha o chamado após resolvido; responder reabre/retoma automaticamente
- Gestão de usuários (admin), CSRF, senhas com hash, consultas preparadas
