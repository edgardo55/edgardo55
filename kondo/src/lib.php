<?php
// Kondo — biblioteca: base de dados, sessões, regras de negócio e helpers HTTP.
declare(strict_types=1);

date_default_timezone_set('Africa/Luanda');

const SESSION_HOURS = 12;
const COLS = ['config', 'moradores', 'pagamentos', 'despesas', 'funcionarios', 'ocorrencias', 'manutencao', 'documentos', 'cameras', 'avisos', 'comunicacoes'];
const MORADOR_EDITAVEIS = ['telefone', 'email', 'emergencia', 'viaturas', 'agregado'];
const MESES_L = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

// Planos da assinatura: preço mensal em Kz e limite de frações ativas (null = ilimitado)
const PLANOS = [
    'Básico' => ['preco' => 15000, 'limite' => 30],
    'Profissional' => ['preco' => 35000, 'limite' => 120],
    'Empresarial' => ['preco' => 75000, 'limite' => null],
];
function planoInfo(?string $p): array { return PLANOS[$p] ?? PLANOS['Profissional']; }
function planosLista(): array {
    $o = [];
    foreach (PLANOS as $nome => $v) $o[] = ['nome' => $nome, 'preco' => $v['preco'], 'limite' => $v['limite']];
    return $o;
}

class HttpError extends Exception {
    public function __construct(string $msg, public int $status = 400) { parent::__construct($msg, $status); }
}
function bad(string $msg, int $code = 400): HttpError { return new HttpError($msg, $code); }
function fail(int $code, string $msg): never { throw new HttpError($msg, $code); }

/* ---------------- Configuração e base de dados ---------------- */
define('ROOT', dirname(__DIR__));
define('DATA_DIR', rtrim(getenv('KONDO_DATA') ?: ROOT . '/data', '/\\'));
define('UPLOADS', DATA_DIR . '/uploads');
define('SECURE_COOKIE', getenv('KONDO_HTTPS') === '1');
if (!is_dir(UPLOADS)) mkdir(UPLOADS, 0770, true);

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('sqlite:' . DATA_DIR . '/kondo.db', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 5000; PRAGMA journal_mode = WAL; PRAGMA foreign_keys = ON;');
    return $pdo;
}
function run(string $sql, array $params = []): PDOStatement { $st = db()->prepare($sql); $st->execute($params); return $st; }
function one(string $sql, array $params = []): ?array { $r = run($sql, $params)->fetch(); return $r === false ? null : $r; }
function rows(string $sql, array $params = []): array { return run($sql, $params)->fetchAll(); }
function scalar(string $sql, array $params = []): mixed { $r = run($sql, $params)->fetch(PDO::FETCH_NUM); return $r ? $r[0] : null; }

function initDb(): void {
    db()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS condominios (id TEXT PRIMARY KEY, nome TEXT NOT NULL, criado TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS utilizadores (
  id TEXT PRIMARY KEY, condo_id TEXT NOT NULL REFERENCES condominios(id),
  email TEXT NOT NULL UNIQUE COLLATE NOCASE, nome TEXT NOT NULL, senha TEXT NOT NULL,
  papel TEXT NOT NULL CHECK (papel IN ('admin','morador')), morador_id TEXT,
  ativo INTEGER NOT NULL DEFAULT 1, trocar_senha INTEGER NOT NULL DEFAULT 0,
  ultimo_login TEXT, criado TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS sessoes (token TEXT PRIMARY KEY, user_id TEXT NOT NULL REFERENCES utilizadores(id) ON DELETE CASCADE, expira INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS registos (condo_id TEXT NOT NULL, col TEXT NOT NULL, id TEXT NOT NULL, data TEXT NOT NULL, atualizado TEXT NOT NULL, PRIMARY KEY (condo_id, col, id));
CREATE TABLE IF NOT EXISTS historico (id INTEGER PRIMARY KEY AUTOINCREMENT, condo_id TEXT NOT NULL, t TEXT NOT NULL, user_id TEXT, acao TEXT NOT NULL, descr TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS ficheiros (id TEXT PRIMARY KEY, condo_id TEXT NOT NULL, nome TEXT NOT NULL, tipo TEXT NOT NULL, tamanho INTEGER NOT NULL, criado TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS operadores (id TEXT PRIMARY KEY, email TEXT NOT NULL UNIQUE COLLATE NOCASE, nome TEXT NOT NULL, senha TEXT NOT NULL, trocar_senha INTEGER NOT NULL DEFAULT 0, ultimo_login TEXT, criado TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS sessoes_op (token TEXT PRIMARY KEY, op_id TEXT NOT NULL REFERENCES operadores(id) ON DELETE CASCADE, expira INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS faturas (id TEXT PRIMARY KEY, condo_id TEXT NOT NULL REFERENCES condominios(id), periodo TEXT NOT NULL, plano TEXT NOT NULL, valor INTEGER NOT NULL,
  estado TEXT NOT NULL DEFAULT 'Pendente', emitida TEXT NOT NULL, vence TEXT NOT NULL, paga_em TEXT, referencia TEXT NOT NULL, UNIQUE (condo_id, periodo));
CREATE TABLE IF NOT EXISTS pedidos_senha (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id TEXT NOT NULL REFERENCES utilizadores(id) ON DELETE CASCADE, t TEXT NOT NULL, resolvido INTEGER NOT NULL DEFAULT 0);
CREATE TABLE IF NOT EXISTS tentativas (chave TEXT PRIMARY KEY, n INTEGER NOT NULL DEFAULT 0, ate INTEGER NOT NULL DEFAULT 0);
SQL);
    $addCol = function (string $t, string $c, string $def) {
        $cols = array_column(rows("PRAGMA table_info($t)"), 'name');
        if (!in_array($c, $cols, true)) db()->exec("ALTER TABLE $t ADD COLUMN $c $def");
    };
    $addCol('condominios', 'estado', "TEXT NOT NULL DEFAULT 'Ativo'");
    $addCol('condominios', 'plano', "TEXT NOT NULL DEFAULT 'Profissional'");
    $addCol('condominios', 'nif', 'TEXT');
    $addCol('condominios', 'contacto', 'TEXT');
}

/* ---------------- Utilitários ---------------- */
function nowIso(): string {
    $t = microtime(true);
    return gmdate('Y-m-d\TH:i:s', (int)$t) . sprintf('.%03dZ', (int)(($t - floor($t)) * 1000));
}
function today(): string { return gmdate('Y-m-d'); }
function curMonth(): string { return gmdate('Y-m'); }
function newId(string $p = ''): string { return $p . rtrim(strtr(base64_encode(random_bytes(9)), '+/', '-_'), '='); }
function num(mixed $v): int|float { return is_numeric($v) ? $v + 0 : 0; }
function str(mixed $v): string { return is_scalar($v) ? (string)$v : ''; }
function esc(mixed $s): string { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function dataBr(mixed $s): string { return implode('/', array_reverse(explode('-', (string)$s))); }
function isMes(mixed $s): bool { return is_string($s) && preg_match('/^\d{4}-\d{2}$/', $s) === 1; }
function mesLongo(string $m): string {
    [$y, $mm] = array_pad(explode('-', $m), 2, '');
    return isset(MESES_L[(int)$mm - 1]) ? MESES_L[(int)$mm - 1] . " de $y" : $m;
}
// Formato pt-PT: separador de milhares (espaço) só a partir de 5 dígitos.
function kz(int|float|string|null $v): string {
    $n = (int)round((float)num($v));
    $s = (string)abs($n);
    if (strlen($s) >= 5) $s = str_replace('|', "\u{00A0}", strrev(implode('|', str_split(strrev($s), 3))));
    return ($n < 0 ? '-' : '') . $s . ' Kz';
}
function first(mixed ...$v): mixed { foreach ($v as $x) if ($x !== null && $x !== '' && $x !== false && $x !== 0) return $x; return end($v); }

// Valor por extenso em português (para recibos)
function extenso(int|float $n): string {
    $n = (int)round(abs((float)$n)); if ($n === 0) return 'zero';
    $U = ['', 'um', 'dois', 'três', 'quatro', 'cinco', 'seis', 'sete', 'oito', 'nove', 'dez', 'onze', 'doze', 'treze', 'catorze', 'quinze', 'dezasseis', 'dezassete', 'dezoito', 'dezanove'];
    $D = ['', '', 'vinte', 'trinta', 'quarenta', 'cinquenta', 'sessenta', 'setenta', 'oitenta', 'noventa'];
    $C = ['', 'cento', 'duzentos', 'trezentos', 'quatrocentos', 'quinhentos', 'seiscentos', 'setecentos', 'oitocentos', 'novecentos'];
    $ate999 = function (int $x) use ($U, $D, $C): string {
        if ($x === 100) return 'cem';
        $c = intdiv($x, 100); $r = $x % 100; $p = [];
        if ($c) $p[] = $C[$c];
        if ($r) $p[] = $r < 20 ? $U[$r] : $D[intdiv($r, 10)] . ($r % 10 ? ' e ' . $U[$r % 10] : '');
        return implode(' e ', $p);
    };
    $nomes = [['', ''], ['mil', 'mil'], ['milhão', 'milhões'], ['mil milhões', 'mil milhões']];
    $g = []; for ($x = $n; $x > 0; $x = intdiv($x, 1000)) $g[] = $x % 1000;
    $partes = [];
    for ($i = count($g) - 1; $i >= 0; $i--) {
        if (!$g[$i]) continue;
        $s = ($i === 1 && $g[$i] === 1) ? 'mil' : $ate999($g[$i]) . ($i ? ' ' . $nomes[$i][$g[$i] === 1 ? 0 : 1] : '');
        $partes[] = ['s' => $s, 'v' => $g[$i]];
    }
    $out = [];
    foreach ($partes as $k => $p) $out[] = ($k > 0 && $k === count($partes) - 1 && ($p['v'] < 100 || $p['v'] % 100 === 0)) ? 'e ' . $p['s'] : $p['s'];
    return implode(' ', $out);
}
function porExtenso(int|float $v): string {
    $t = extenso($v);
    return mb_strtoupper(mb_substr($t, 0, 1)) . mb_substr($t, 1) . (preg_match('/(milhão|milhões)$/u', $t) ? ' de kwanzas' : ' kwanzas');
}

/* ---------------- Registos (coleções JSON por condomínio) ---------------- */
function jenc(mixed $v): string { return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR); }
function lista(string $condo, string $col): array {
    return array_map(fn($r) => ['id' => $r['id']] + (json_decode($r['data'], true) ?: []),
        rows('SELECT id, data FROM registos WHERE condo_id = ? AND col = ?', [$condo, $col]));
}
function getRec(string $condo, string $col, ?string $id): ?array {
    $r = one('SELECT data FROM registos WHERE condo_id = ? AND col = ? AND id = ?', [$condo, $col, (string)$id]);
    return $r ? ['id' => $id] + (json_decode($r['data'], true) ?: []) : null;
}
function putRec(string $condo, string $col, array $rec): void {
    $id = (string)$rec['id']; unset($rec['id']);
    run('INSERT INTO registos (condo_id, col, id, data, atualizado) VALUES (?, ?, ?, ?, ?)
         ON CONFLICT (condo_id, col, id) DO UPDATE SET data = excluded.data, atualizado = excluded.atualizado',
        [$condo, $col, $id, jenc((object)$rec), nowIso()]);
}
function logAcao(string $condo, ?string $userId, string $acao, string $descr): void {
    run('INSERT INTO historico (condo_id, t, user_id, acao, descr) VALUES (?, ?, ?, ?, ?)', [$condo, nowIso(), $userId, $acao, mb_substr($descr, 0, 300)]);
}
function condoOf(string $id): ?array { return one('SELECT * FROM condominios WHERE id = ?', [$id]); }
function isAtivo(array $m): bool { return !isset($m['estado']) || $m['estado'] === '' || $m['estado'] === 'Ativo'; }

/* ---------------- Palavras-passe e sessões ---------------- */
function hashPassword(string $pw): string { return password_hash($pw, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT); }
function checkPassword(string $pw, string $stored): bool { return password_verify($pw, $stored); }
function tempPassword(): string {
    $abc = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
    $o = ''; foreach (str_split(random_bytes(10)) as $b) $o .= $abc[ord($b) % strlen($abc)];
    return $o;
}
function sha(string $t): string { return hash('sha256', $t); }
function createSession(string $table, string $col, string $id): string {
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    run("INSERT INTO $table (token, $col, expira) VALUES (?, ?, ?)", [sha($token), $id, (time() + SESSION_HOURS * 3600) * 1000]);
    return $token;
}
function sessionUser(): ?array {
    $token = $_COOKIE['kondo_sessao'] ?? null; if (!$token) return null;
    $row = one('SELECT u.*, s.expira FROM sessoes s JOIN utilizadores u ON u.id = s.user_id WHERE s.token = ?', [sha((string)$token)]);
    return $row && $row['expira'] > time() * 1000 && $row['ativo'] ? $row : null;
}
function sessionOp(): ?array {
    $token = $_COOKIE['kondo_op'] ?? null; if (!$token) return null;
    $row = one('SELECT o.*, s.expira FROM sessoes_op s JOIN operadores o ON o.id = s.op_id WHERE s.token = ?', [sha((string)$token)]);
    return $row && $row['expira'] > time() * 1000 ? $row : null;
}
function setCookieKondo(string $name, string $token, int $maxAge): void {
    setcookie($name, $token, ['expires' => $maxAge > 0 ? time() + $maxAge : 1, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict', 'secure' => SECURE_COOKIE]);
}
function publicUser(array $u): array {
    return ['id' => $u['id'], 'nome' => $u['nome'], 'email' => $u['email'], 'papel' => $u['papel'], 'morador_id' => $u['morador_id'],
        'ativo' => (bool)$u['ativo'], 'trocar_senha' => (bool)$u['trocar_senha'], 'ultimo_login' => $u['ultimo_login']];
}

// Limite de tentativas por IP + email (login e recuperação), guardado na base de dados.
function blocked(string $key): bool { return (int)scalar('SELECT ate FROM tentativas WHERE chave = ?', [$key]) > time() * 1000; }
function failed(string $key): void {
    $a = one('SELECT n, ate FROM tentativas WHERE chave = ?', [$key]) ?: ['n' => 0, 'ate' => 0];
    $n = $a['n'] + 1; $ate = $a['ate'];
    if ($n >= 5) { $ate = (time() + 300) * 1000; $n = 0; }
    run('INSERT INTO tentativas (chave, n, ate) VALUES (?, ?, ?) ON CONFLICT (chave) DO UPDATE SET n = excluded.n, ate = excluded.ate', [$key, $n, $ate]);
}
function clearAttempts(string $key): void { run('DELETE FROM tentativas WHERE chave = ?', [$key]); }
function limparSessoes(): void {
    $t = time() * 1000;
    run('DELETE FROM sessoes WHERE expira < ?', [$t]);
    run('DELETE FROM sessoes_op WHERE expira < ?', [$t]);
    run('DELETE FROM tentativas WHERE ate < ? AND n = 0', [$t]);
}

/* ---------------- Regras de negócio ---------------- */
function monthsBetween(string $a, string $b): array {
    $out = []; [$y, $m] = array_map('intval', explode('-', $a)); [$by, $bm] = array_map('intval', explode('-', $b));
    while (($y < $by || ($y === $by && $m <= $bm)) && count($out) < 120) {
        $out[] = sprintf('%d-%02d', $y, $m);
        if (++$m > 12) { $m = 1; $y++; }
    }
    return $out;
}
function dividaDe(string $condo, array $m, array $pagamentos): array {
    $cfg = getRec($condo, 'config', 'geral') ?: [];
    if (!isAtivo($m)) return ['meses' => [], 'total' => 0];
    $cur = curMonth(); $inicio = str($cfg['inicio'] ?? '');
    $venc = num($cfg['diaVencimento'] ?? 0) ?: 10;
    $vencido = (int)date('j') > $venc;
    $desde = str($m['desde'] ?? '');
    $start = ($desde !== '' && $desde > $inicio) ? $desde : ($inicio ?: $cur);
    $pagos = [];
    foreach ($pagamentos as $p) if (($p['moradorId'] ?? null) === $m['id']) $pagos[$p['mes'] ?? ''] = true;
    $meses = array_values(array_filter(monthsBetween($start, $cur), fn($x) => !isset($pagos[$x]) && ($x < $cur || $vencido)));
    return ['meses' => $meses, 'total' => count($meses) * num($m['quota'] ?? 0)];
}

function saveFile(string $condo, mixed $f): string {
    $TIPOS = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    if (!is_array($f) || !in_array(str($f['tipo'] ?? ''), $TIPOS, true)) throw bad('Tipo de ficheiro não suportado. Use PDF, JPG, PNG ou WebP.');
    $buf = base64_decode(str($f['base64'] ?? ''));
    if ($buf === false || $buf === '' || strlen($buf) > 5e6) throw bad('O ficheiro deve ter até 5 MB.');
    $id = newId('f_');
    file_put_contents(UPLOADS . '/' . $id, $buf);
    run('INSERT INTO ficheiros (id, condo_id, nome, tipo, tamanho, criado) VALUES (?, ?, ?, ?, ?, ?)',
        [$id, $condo, mb_substr(str($f['nome'] ?? '') ?: 'ficheiro', 0, 120), $f['tipo'], strlen($buf), nowIso()]);
    return $id;
}
function criarUtilizador(string $condo, string $papel, mixed $email, string $nome, ?string $moradorId = null): array {
    $email = trim(str($email));
    if (!preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email)) throw bad('Indique um email válido.');
    if (one('SELECT 1 FROM utilizadores WHERE email = ?', [$email]) || one('SELECT 1 FROM operadores WHERE email = ?', [$email])) throw bad('Já existe uma conta com este email.', 409);
    $senha = tempPassword(); $id = newId('u_');
    run('INSERT INTO utilizadores (id, condo_id, email, nome, senha, papel, morador_id, trocar_senha, criado) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)',
        [$id, $condo, $email, $nome, hashPassword($senha), $papel, $moradorId, nowIso()]);
    return ['senha' => $senha, 'utilizador' => publicUser(one('SELECT * FROM utilizadores WHERE id = ?', [$id]))];
}
function reporSenha(array $u): string {
    $senha = tempPassword();
    run('UPDATE utilizadores SET senha = ?, trocar_senha = 1 WHERE id = ?', [hashPassword($senha), $u['id']]);
    run('DELETE FROM sessoes WHERE user_id = ?', [$u['id']]);
    run('UPDATE pedidos_senha SET resolvido = 1 WHERE user_id = ?', [$u['id']]);
    return $senha;
}
function faturaEstado(array $f): string { return $f['estado'] === 'Pendente' && $f['vence'] < today() ? 'Em atraso' : $f['estado']; }
function emitirFatura(string $condo, string $periodo): void {
    $c = condoOf($condo); $info = planoInfo($c['plano']);
    [$y, $m] = explode('-', $periodo);
    // Vence no dia 15 do mês, mas nunca menos de 10 dias depois de emitida (clientes novos a meio do mês).
    $minimo = gmdate('Y-m-d', time() + 10 * 86400);
    $vence = ("$y-$m-15" < $minimo && $periodo >= curMonth()) ? $minimo : "$y-$m-15";
    run('INSERT OR IGNORE INTO faturas (id, condo_id, periodo, plano, valor, estado, emitida, vence, referencia) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [newId('ft_'), $condo, $periodo, $c['plano'], $info['preco'], 'Pendente', nowIso(), $vence, "KND-$y$m-" . strtoupper(bin2hex(random_bytes(3)))]);
}
function descrever(string $condo, string $col, array $r): string {
    $nomes = ['config' => 'Definições', 'moradores' => 'Morador', 'pagamentos' => 'Pagamento', 'despesas' => 'Despesa', 'funcionarios' => 'Funcionário', 'ocorrencias' => 'Ocorrência', 'manutencao' => 'Manutenção', 'documentos' => 'Documento', 'cameras' => 'Câmara', 'avisos' => 'Aviso', 'comunicacoes' => 'Comunicação de pagamento'];
    if ($col === 'pagamentos') {
        $m = getRec($condo, 'moradores', $r['moradorId'] ?? null);
        return 'Pagamento: ' . ($m ? $m['fracao'] . ' · ' . $m['nome'] : '?') . ' — ' . str($r['mes'] ?? '') . ' — ' . kz($r['valor'] ?? 0);
    }
    $nome = '';
    foreach (['nome', 'titulo', 'equipamento', 'descricao', 'id'] as $k) if (!empty($r[$k])) { $nome = str($r[$k]); break; }
    return ($nomes[$col] ?? $col) . ': ' . $nome . ($col === 'moradores' && !empty($r['fracao']) ? ' (' . $r['fracao'] . ')' : '');
}

/* ---------------- HTTP ---------------- */
function sendHeaders(int $code, string $ctype, array $extra = []): void {
    http_response_code($code);
    header('Content-Type: ' . $ctype);
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: DENY');
    foreach ($extra as $k => $v) header("$k: $v");
}
function send(int $code, mixed $body, array $extra = []): void {
    if (is_string($body)) { sendHeaders($code, 'text/plain; charset=utf-8', $extra); echo $body; return; }
    sendHeaders($code, 'application/json; charset=utf-8', $extra);
    echo jenc($body);
}
function html(string $body): void { sendHeaders(200, 'text/html; charset=utf-8', ['Cache-Control' => 'no-store']); echo $body; }
function redirect(string $to): void { header('Location: ' . $to, true, 302); }
function readJson(int $limit = 1000000): array {
    if (!str_starts_with($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '', 'application/json')) throw bad('Envie os dados em JSON.', 415);
    $raw = file_get_contents('php://input', false, null, 0, $limit + 1);
    if (strlen($raw) > $limit) throw bad('Pedido demasiado grande.', 413);
    $v = trim($raw) === '' ? [] : json_decode($raw, true);
    if (!is_array($v) || ($v && array_is_list($v))) throw bad('JSON inválido.');
    return $v;
}
function serveView(string $file): void {
    $full = ROOT . '/views/' . $file;
    if (!is_file($full)) fail(404, 'Página não encontrada.');
    sendHeaders(200, 'text/html; charset=utf-8', ['Cache-Control' => 'no-cache']);
    readfile($full);
}

$GLOBALS['routes'] = [];
// papel: null (público), 'any' (qualquer utilizador de condomínio), 'admin', 'morador', 'op' (operador da plataforma)
function route(string $method, string $pattern, callable $handler, ?string $papel = null): void {
    $keys = [];
    $re = '#^' . preg_replace_callback('/:(\w+)/', function ($m) use (&$keys) { $keys[] = $m[1]; return '([^/]+)'; }, $pattern) . '$#';
    $GLOBALS['routes'][] = compact('method', 're', 'keys', 'handler', 'papel');
}
function areaDe(array $u): string { return $u['papel'] === 'admin' ? '/admin' : '/portal'; }
