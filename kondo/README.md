# Kondo — gestão de condomínios (SaaS) · versão PHP

Conversão para **PHP 8.1+ com SQLite** do projeto Kondo (antes em Node.js). O front-end (HTML/CSS/JS), as rotas da API e a estrutura da base de dados são os mesmos; só o servidor foi reescrito.

Áreas (todas entram em `/login`; é a conta que decide a área, verificado no servidor): **`/plataforma`** (dono do SaaS), **`/admin`** (administração do condomínio) e **`/portal`** (morador). Ver as funcionalidades no README original.

## Requisitos

PHP 8.1 ou mais recente com as extensões `pdo_sqlite`, `mbstring` e `json` (e `sodium`/argon2 opcional). Não há dependências (sem Composer).

## Arrancar

```bash
./iniciar.sh            # ou: php -S localhost:3000 -t public public/index.php
```

No Windows, duplo clique em `iniciar.bat`. Abra http://localhost:3000. Na primeira execução são criados dois condomínios de demonstração e as contas iniciais; as palavras-passe ficam em `data/CREDENCIAIS-DEMO.txt`.

| Variável | Para quê | Por omissão |
|---|---|---|
| `KONDO_DATA` | Pasta da base de dados e ficheiros enviados | `./data` |
| `KONDO_HTTPS` | `1` atrás de HTTPS (cookie `Secure`) | desligado |
| `KONDO_DEMO` | `0` para arrancar sem condomínios de demonstração | ligado |
| `KONDO_EMAIL` | Email da conta da plataforma criada no primeiro arranque | `plataforma@kondo.ao` |

## Estrutura

```
public/index.php   ponto de entrada (único ficheiro acessível pela web) + style.css
src/lib.php        base de dados, sessões, regras de negócio, helpers HTTP
src/routes.php     todas as rotas (páginas, API, recibos, relatórios)
src/seed.php       dados de demonstração na primeira execução
views/             login, admin, portal, plataforma (servidas só após verificar a sessão)
seed/demo.json     condomínio de exemplo
data/              SQLite e uploads (fora de public/; não versionar)
deploy/            Caddyfile (php-fpm) e backup.sh
```

## Produção

- Apontar a raiz do site para `public/` (Caddy: `deploy/Caddyfile`; Apache: `public/.htaccess`; Nginx: `try_files $uri /index.php$is_args$args;`) com PHP-FPM e HTTPS. `data/` tem de ficar **fora** da raiz web e ser gravável pelo utilizador do PHP.
- No `php.ini`: `post_max_size` e `upload_max_filesize` ≥ 10M (comprovativos até 5 MB em base64).
- Cópia de segurança diária com `deploy/backup.sh`.

## Diferenças em relação à versão Node

- Palavras-passe com `password_hash` (Argon2id/bcrypt) em vez de `scrypt`: **as contas de uma base de dados Node existente não iniciam sessão** — reponha as palavras-passe pela Plataforma. Instalações novas não são afetadas.
- O bloqueio de tentativas de login passou a ser guardado na base de dados (o PHP não mantém memória entre pedidos).

## Quotas dos moradores (regras)

- A **quota mensal é igual para todos** (10 000 Kz por omissão). Só os administradores a alteram, em *Definições → Quota mensal dos moradores*; a alteração aplica-se a todos os moradores. É independente do preço dos pacotes (planos) do Kondo.
- **O valor define os meses**: 20 000 Kz = 2 meses. O valor tem de ser múltiplo da quota.
- **Pagamento por ordem**: os meses são atribuídos a começar no mais antigo em dívida; não se paga um mês sem liquidar os anteriores (vale também para os pagamentos comunicados no portal).
- **Cobrança**: em *Dívidas* (ou na ficha do morador) o administrador envia a mensagem por WhatsApp, SMS ou para os Avisos do portal do morador.
- **Importar moradores**: em *Gestão de moradores → Importar Excel* (.xlsx ou .csv). Colunas: Nome (obrigatória), Fração, Bloco, Telefone, Email, Tipo.

## Prédios, pacotes e quotas

- O dono do sistema (área `/plataforma`) regista cada **prédio** com o seu administrador e define o **preço mensal do prédio** entre 35 000 e 75 000 Kz (editável em *Gerir*). É esse valor que o prédio paga ao Kondo.
- Em cada prédio, o administrador define a **quota dos moradores** (10 000 Kz por omissão, alterável nas Definições). As duas coisas são independentes.
