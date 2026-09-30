<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config.php';
date_default_timezone_set($config['timezone']);

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

const STATUS = ['aberto' => 'Aberto', 'andamento' => 'Em andamento', 'aguardando' => 'Aguardando usuário', 'resolvido' => 'Resolvido', 'fechado' => 'Fechado'];
const PRIORIDADES = ['baixa' => 'Baixa', 'media' => 'Média', 'alta' => 'Alta', 'critica' => 'Crítica'];
const PERFIS = ['usuario' => 'Usuário', 'tecnico' => 'Técnico TI', 'admin' => 'Administrador'];

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    global $config;
    $novo = !file_exists($config['db_path']);
    $pdo = new PDO('sqlite:' . $config['db_path']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    if ($novo) instalar($pdo, $config);
    return $pdo;
}

function instalar(PDO $pdo, array $config): void
{
    $pdo->exec("
    CREATE TABLE usuarios (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nome TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE,
        senha TEXT NOT NULL,
        perfil TEXT NOT NULL DEFAULT 'usuario',
        departamento TEXT,
        ativo INTEGER NOT NULL DEFAULT 1,
        criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE categorias (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nome TEXT NOT NULL UNIQUE
    );
    CREATE TABLE chamados (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        titulo TEXT NOT NULL,
        descricao TEXT NOT NULL,
        categoria_id INTEGER REFERENCES categorias(id),
        prioridade TEXT NOT NULL DEFAULT 'media',
        status TEXT NOT NULL DEFAULT 'aberto',
        solicitante_id INTEGER NOT NULL REFERENCES usuarios(id),
        tecnico_id INTEGER REFERENCES usuarios(id),
        criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        atualizado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        fechado_em TEXT
    );
    CREATE TABLE comentarios (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        chamado_id INTEGER NOT NULL REFERENCES chamados(id) ON DELETE CASCADE,
        usuario_id INTEGER NOT NULL REFERENCES usuarios(id),
        mensagem TEXT NOT NULL,
        interno INTEGER NOT NULL DEFAULT 0,
        criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
    CREATE INDEX idx_chamados_status ON chamados(status);
    CREATE INDEX idx_chamados_solicitante ON chamados(solicitante_id);
    ");
    $cat = $pdo->prepare('INSERT INTO categorias (nome) VALUES (?)');
    foreach (['Hardware', 'Software', 'Rede / Internet', 'E-mail', 'Acessos e senhas', 'Impressoras', 'Telefonia', 'Outros'] as $c) $cat->execute([$c]);
    $pdo->prepare("INSERT INTO usuarios (nome, email, senha, perfil, departamento) VALUES (?, ?, ?, 'admin', 'TI')")
        ->execute(['Administrador', $config['admin_email'], password_hash($config['admin_senha'], PASSWORD_DEFAULT)]);
}

// ---------- utilidades ----------
function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function redirect(string $url): never { header('Location: ' . $url); exit; }
function data_br(?string $d): string { return $d ? date('d/m/Y H:i', strtotime($d . ' UTC')) : '-'; }

function csrf_token(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . csrf_token() . '">'; }
function csrf_check(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(400);
        exit('Requisição inválida (CSRF).');
    }
}

function flash(?string $msg = null, string $tipo = 'ok'): ?array
{
    if ($msg !== null) { $_SESSION['flash'] = [$msg, $tipo]; return null; }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

// ---------- autenticação ----------
function usuario(): ?array { return $_SESSION['usuario'] ?? null; }
function e_ti(): bool { return in_array(usuario()['perfil'] ?? '', ['tecnico', 'admin'], true); }
function e_admin(): bool { return (usuario()['perfil'] ?? '') === 'admin'; }

function exigir_login(): void { if (!usuario()) redirect('index.php'); }
function exigir_ti(): void { exigir_login(); if (!e_ti()) { http_response_code(403); exit('Acesso negado.'); } }
function exigir_admin(): void { exigir_login(); if (!e_admin()) { http_response_code(403); exit('Acesso negado.'); } }

function login(string $email, string $senha): bool
{
    $st = db()->prepare('SELECT * FROM usuarios WHERE email = ? AND ativo = 1');
    $st->execute([trim(strtolower($email))]);
    $u = $st->fetch();
    if (!$u || !password_verify($senha, $u['senha'])) return false;
    session_regenerate_id(true);
    unset($u['senha']);
    $_SESSION['usuario'] = $u;
    return true;
}

function badge_status(string $s): string { return '<span class="badge st-' . e($s) . '">' . e(STATUS[$s] ?? $s) . '</span>'; }
function badge_prio(string $p): string { return '<span class="badge pr-' . e($p) . '">' . e(PRIORIDADES[$p] ?? $p) . '</span>'; }

function pode_ver_chamado(array $c): bool
{
    return e_ti() || (int)$c['solicitante_id'] === (int)usuario()['id'];
}
