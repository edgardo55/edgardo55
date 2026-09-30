<?php
// Kondo — rotas (páginas, autenticação, área administrativa, portal do morador e plataforma).
declare(strict_types=1);

/* ---- Páginas ---- */
route('GET', '/', fn($c) => redirect($c['op'] ? '/plataforma' : ($c['user'] ? areaDe($c['user']) : '/login')));
route('GET', '/login', fn($c) => $c['op'] ? redirect('/plataforma') : ($c['user'] ? redirect(areaDe($c['user'])) : serveView('login.html')));
route('GET', '/admin', fn($c) => !$c['user'] ? redirect('/login') : ($c['user']['papel'] !== 'admin' ? redirect('/portal') : serveView('admin.html')));
route('GET', '/portal', fn($c) => !$c['user'] ? redirect('/login') : ($c['user']['papel'] !== 'morador' ? redirect('/admin') : serveView('portal.html')));
route('GET', '/plataforma', fn($c) => !$c['op'] ? redirect('/login') : serveView('plataforma.html'));

/* ---- Autenticação ---- */
route('POST', '/api/login', function () {
    $b = readJson(10000);
    $email = str($b['email'] ?? ''); $senha = str($b['senha'] ?? '');
    $key = ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . strtolower($email);
    if (blocked($key)) fail(429, 'Demasiadas tentativas falhadas. Aguarde 5 minutos e tente de novo.');
    $u = one('SELECT * FROM utilizadores WHERE email = ?', [trim($email)]);
    $o = $u ? null : one('SELECT * FROM operadores WHERE email = ?', [trim($email)]);
    $conta = $u ?: $o;
    if (!$conta || !checkPassword($senha, $conta['senha'])) { failed($key); fail(401, 'Email ou palavra-passe incorretos.'); }
    clearAttempts($key);
    if ($o) {
        run('UPDATE operadores SET ultimo_login = ? WHERE id = ?', [nowIso(), $o['id']]);
        setCookieKondo('kondo_op', createSession('sessoes_op', 'op_id', $o['id']), SESSION_HOURS * 3600);
        return send(200, ['ok' => true, 'papel' => 'op', 'destino' => '/plataforma', 'trocar_senha' => (bool)$o['trocar_senha']]);
    }
    if (!$u['ativo']) fail(403, 'Esta conta foi desativada. Contacte a administração do condomínio.');
    $c = condoOf($u['condo_id']);
    if ($c['estado'] !== 'Ativo' && $u['papel'] === 'morador') fail(403, 'O portal deste condomínio está temporariamente indisponível. Contacte a administração.');
    run('UPDATE utilizadores SET ultimo_login = ? WHERE id = ?', [nowIso(), $u['id']]);
    setCookieKondo('kondo_sessao', createSession('sessoes', 'user_id', $u['id']), SESSION_HOURS * 3600);
    send(200, ['ok' => true, 'papel' => $u['papel'], 'destino' => areaDe($u), 'trocar_senha' => (bool)$u['trocar_senha']]);
});
route('POST', '/api/logout', function () {
    if (!empty($_COOKIE['kondo_sessao'])) run('DELETE FROM sessoes WHERE token = ?', [sha((string)$_COOKIE['kondo_sessao'])]);
    if (!empty($_COOKIE['kondo_op'])) run('DELETE FROM sessoes_op WHERE token = ?', [sha((string)$_COOKIE['kondo_op'])]);
    setCookieKondo('kondo_sessao', '', 0); setCookieKondo('kondo_op', '', 0);
    send(200, ['ok' => true]);
});
route('POST', '/api/recuperar', function () {
    $b = readJson(10000);
    $key = 'rec|' . ($_SERVER['REMOTE_ADDR'] ?? '');
    if (blocked($key)) fail(429, 'Demasiados pedidos. Aguarde 5 minutos.');
    failed($key);
    $u = one('SELECT * FROM utilizadores WHERE email = ? AND ativo = 1', [trim(str($b['email'] ?? ''))]);
    if ($u && !one('SELECT 1 FROM pedidos_senha WHERE user_id = ? AND resolvido = 0', [$u['id']])) {
        run('INSERT INTO pedidos_senha (user_id, t) VALUES (?, ?)', [$u['id'], nowIso()]);
        logAcao($u['condo_id'], $u['id'], 'Pediu', "Recuperação de palavra-passe: {$u['nome']} ({$u['email']})");
    }
    // Resposta igual quer o email exista ou não, para não revelar contas.
    send(200, ['ok' => true]);
});
route('POST', '/api/senha', function ($c) {
    $conta = $c['user'] ?: $c['op']; $table = $c['user'] ? 'utilizadores' : 'operadores';
    if (!$conta) fail(401, 'A sua sessão terminou. Entre de novo.');
    $b = readJson(10000); $atual = str($b['atual'] ?? ''); $nova = str($b['nova'] ?? '');
    if (!checkPassword($atual, $conta['senha'])) fail(400, 'A palavra-passe atual não está correta.');
    if (mb_strlen($nova) < 8) fail(400, 'A nova palavra-passe deve ter pelo menos 8 caracteres.');
    if ($nova === $atual) fail(400, 'A nova palavra-passe tem de ser diferente da atual.');
    run("UPDATE $table SET senha = ?, trocar_senha = 0 WHERE id = ?", [hashPassword($nova), $conta['id']]);
    if ($c['user']) run('DELETE FROM sessoes WHERE user_id = ? AND token != ?', [$c['user']['id'], sha((string)($_COOKIE['kondo_sessao'] ?? ''))]);
    else run('DELETE FROM sessoes_op WHERE op_id = ? AND token != ?', [$c['op']['id'], sha((string)($_COOKIE['kondo_op'] ?? ''))]);
    send(200, ['ok' => true]);
});

/* ---- Área administrativa ---- */
route('GET', '/api/admin/dados', function ($c) {
    $u = $c['user']; $out = [];
    foreach (COLS as $col) $out[$col] = lista($u['condo_id'], $col);
    $out['utilizadores'] = array_map('publicUser', rows('SELECT * FROM utilizadores WHERE condo_id = ? ORDER BY papel, nome', [$u['condo_id']]));
    $out['pedidos'] = rows('SELECT p.id, p.t, p.user_id FROM pedidos_senha p JOIN utilizadores u ON u.id = p.user_id WHERE u.condo_id = ? AND p.resolvido = 0 ORDER BY p.t', [$u['condo_id']]);
    $co = condoOf($u['condo_id']);
    $out['condominio'] = ['estado' => $co['estado'], 'plano' => $co['plano'], 'limite' => planoInfo($co['plano'])['limite']];
    $out['eu'] = publicUser($u);
    send(200, $out);
}, 'admin');
route('PUT', '/api/admin/reg/:col/:id', function ($c) {
    $u = $c['user']; $p = $c['p'];
    if (!in_array($p['col'], COLS, true)) fail(404, 'Coleção desconhecida.');
    if (!preg_match('/^[\w-]{1,64}$/', $p['id'])) fail(400, 'Identificador inválido.');
    $body = readJson(); unset($body['id']);
    $antes = getRec($u['condo_id'], $p['col'], $p['id']);
    if ($p['col'] === 'moradores' && isAtivo($body) && !($antes && isAtivo($antes))) {
        $co = condoOf($u['condo_id']); $lim = planoInfo($co['plano'])['limite'];
        $n = count(array_filter(lista($u['condo_id'], 'moradores'), fn($m) => $m['id'] !== $p['id'] && isAtivo($m)));
        if ($lim !== null && $n >= $lim) fail(403, "O plano {$co['plano']} permite até $lim frações ativas. Mude de plano em Definições para cadastrar mais.");
    }
    if ($p['col'] === 'config' && !empty($body['plano']) && $antes && $body['plano'] !== ($antes['plano'] ?? null)) $body['plano'] = $antes['plano'] ?? null; // o plano muda só pela rota própria
    putRec($u['condo_id'], $p['col'], $body + ['id' => $p['id']]);
    if ($p['col'] === 'config' && !empty($body['nome'])) run('UPDATE condominios SET nome = ?, nif = ? WHERE id = ?', [str($body['nome']), str($body['nif'] ?? '') ?: null, $u['condo_id']]);
    if ($p['col'] === 'moradores' && !empty($body['nome'])) run('UPDATE utilizadores SET nome = ? WHERE morador_id = ? AND condo_id = ?', [str($body['nome']), $p['id'], $u['condo_id']]);
    $rec = $body + ['id' => $p['id']];
    logAcao($u['condo_id'], $u['id'], $antes ? 'Alterou' : 'Criou', descrever($u['condo_id'], $p['col'], $rec));
    send(200, ['ok' => true, 'registo' => ['id' => $p['id']] + $body]);
}, 'admin');
route('DELETE', '/api/admin/reg/:col/:id', function ($c) {
    $u = $c['user']; $p = $c['p'];
    if (!in_array($p['col'], COLS, true)) fail(404, 'Coleção desconhecida.');
    $rec = getRec($u['condo_id'], $p['col'], $p['id']);
    if (!$rec) fail(404, 'Registo não encontrado.');
    run('DELETE FROM registos WHERE condo_id = ? AND col = ? AND id = ?', [$u['condo_id'], $p['col'], $p['id']]);
    if ($p['col'] === 'moradores') run('DELETE FROM utilizadores WHERE morador_id = ? AND condo_id = ?', [$p['id'], $u['condo_id']]);
    logAcao($u['condo_id'], $u['id'], 'Eliminou', descrever($u['condo_id'], $p['col'], $rec));
    send(200, ['ok' => true]);
}, 'admin');
route('GET', '/api/admin/historico', function ($c) {
    send(200, rows('SELECT h.t, h.acao, h.descr, u.nome AS quem FROM historico h LEFT JOIN utilizadores u ON u.id = h.user_id WHERE h.condo_id = ? ORDER BY h.id DESC LIMIT 300', [$c['user']['condo_id']]));
}, 'admin');

// Pagamentos comunicados pelos moradores
route('POST', '/api/admin/comunicacoes/:id/confirmar', function ($c) {
    $u = $c['user']; $cid = $u['condo_id'];
    $cm = getRec($cid, 'comunicacoes', $c['p']['id']);
    if (!$cm || ($cm['estado'] ?? '') !== 'Pendente') fail(404, 'Comunicação não encontrada ou já tratada.');
    $m = getRec($cid, 'moradores', $cm['moradorId'] ?? null);
    if (!$m) fail(400, 'O morador desta comunicação já não existe.');
    $todos = lista($cid, 'pagamentos');
    $pagos = array_column(array_filter($todos, fn($x) => ($x['moradorId'] ?? null) === $m['id']), 'mes');
    $novos = array_values(array_filter($cm['meses'], fn($x) => !in_array($x, $pagos, true)));
    $nPag = count($todos); $ids = [];
    foreach ($novos as $i => $mes) {
        $pg = ['id' => newId('p_'), 'moradorId' => $m['id'], 'mes' => $mes, 'valor' => (int)round($cm['valor'] / count($cm['meses'])), 'metodo' => $cm['metodo'], 'data' => $cm['data'],
            'referencia' => 'REC-' . (1001 + $nPag + $i), 'operacao' => $cm['referencia'] ?? '', 'comprovativoId' => $cm['comprovativoId'] ?? null];
        putRec($cid, 'pagamentos', $pg); $ids[] = $pg['id'];
    }
    putRec($cid, 'comunicacoes', array_merge($cm, ['estado' => 'Confirmado', 'tratadoEm' => nowIso(), 'pagamentoIds' => $ids]));
    logAcao($cid, $u['id'], 'Confirmou', "Pagamento comunicado por {$m['fracao']} · {$m['nome']}: " . implode(', ', $cm['meses']) . ' — ' . kz($cm['valor']));
    send(200, ['ok' => true, 'criados' => count($ids)]);
}, 'admin');
route('POST', '/api/admin/comunicacoes/:id/rejeitar', function ($c) {
    $u = $c['user']; $motivo = str(readJson(10000)['motivo'] ?? '');
    $cm = getRec($u['condo_id'], 'comunicacoes', $c['p']['id']);
    if (!$cm || ($cm['estado'] ?? '') !== 'Pendente') fail(404, 'Comunicação não encontrada ou já tratada.');
    if (trim($motivo) === '') fail(400, 'Indique o motivo para o morador saber o que corrigir.');
    putRec($u['condo_id'], 'comunicacoes', array_merge($cm, ['estado' => 'Rejeitado', 'motivo' => mb_substr($motivo, 0, 300), 'tratadoEm' => nowIso()]));
    logAcao($u['condo_id'], $u['id'], 'Rejeitou', 'Pagamento comunicado (' . implode(', ', $cm['meses']) . "): $motivo");
    send(200, ['ok' => true]);
}, 'admin');

// Contas de acesso
route('POST', '/api/admin/utilizadores', function ($c) {
    $u = $c['user']; $b = readJson(10000);
    $papel = ($b['papel'] ?? '') === 'admin' ? 'admin' : 'morador';
    $nome = trim(str($b['nome'] ?? '')); $moradorId = null;
    if ($papel === 'morador') {
        $m = getRec($u['condo_id'], 'moradores', str($b['morador_id'] ?? ''));
        if (!$m) fail(400, 'Escolha o morador a quem pertence a conta.');
        if (one('SELECT 1 FROM utilizadores WHERE morador_id = ? AND condo_id = ?', [$m['id'], $u['condo_id']])) fail(409, 'Este morador já tem acesso ao portal.');
        $nome = $m['nome']; $moradorId = $m['id'];
    }
    if ($nome === '') fail(400, 'Indique o nome.');
    $r = criarUtilizador($u['condo_id'], $papel, $b['email'] ?? '', $nome, $moradorId);
    logAcao($u['condo_id'], $u['id'], 'Criou', 'Acesso ' . ($papel === 'admin' ? 'de administrador' : 'ao portal') . ": $nome ({$r['utilizador']['email']})");
    send(201, ['ok' => true, 'senha_temporaria' => $r['senha'], 'utilizador' => $r['utilizador']]);
}, 'admin');
function contaDoCondo(array $user, string $id): ?array { return one('SELECT * FROM utilizadores WHERE id = ? AND condo_id = ?', [$id, $user['condo_id']]); }
route('POST', '/api/admin/utilizadores/:id/repor', function ($c) {
    $u = $c['user']; $t = contaDoCondo($u, $c['p']['id']); if (!$t) fail(404, 'Conta não encontrada.');
    $senha = reporSenha($t);
    logAcao($u['condo_id'], $u['id'], 'Alterou', "Palavra-passe reposta: {$t['nome']} ({$t['email']})");
    send(200, ['ok' => true, 'senha_temporaria' => $senha]);
}, 'admin');
route('PATCH', '/api/admin/utilizadores/:id', function ($c) {
    $u = $c['user']; $t = contaDoCondo($u, $c['p']['id']); if (!$t) fail(404, 'Conta não encontrada.');
    if ($t['id'] === $u['id']) fail(400, 'Não pode desativar a sua própria conta.');
    $ativo = !empty(readJson(10000)['ativo']);
    run('UPDATE utilizadores SET ativo = ? WHERE id = ?', [$ativo ? 1 : 0, $t['id']]);
    if (!$ativo) run('DELETE FROM sessoes WHERE user_id = ?', [$t['id']]);
    logAcao($u['condo_id'], $u['id'], 'Alterou', 'Acesso ' . ($ativo ? 'reativado' : 'desativado') . ": {$t['nome']} ({$t['email']})");
    send(200, ['ok' => true]);
}, 'admin');
route('DELETE', '/api/admin/utilizadores/:id', function ($c) {
    $u = $c['user']; $t = contaDoCondo($u, $c['p']['id']); if (!$t) fail(404, 'Conta não encontrada.');
    if ($t['id'] === $u['id']) fail(400, 'Não pode eliminar a sua própria conta.');
    if ($t['papel'] === 'admin' && (int)scalar("SELECT COUNT(*) FROM utilizadores WHERE condo_id = ? AND papel = 'admin' AND ativo = 1", [$u['condo_id']]) <= 1) fail(400, 'O condomínio precisa de pelo menos um administrador ativo.');
    run('DELETE FROM utilizadores WHERE id = ?', [$t['id']]);
    logAcao($u['condo_id'], $u['id'], 'Eliminou', "Acesso: {$t['nome']} ({$t['email']})");
    send(200, ['ok' => true]);
}, 'admin');

// Assinatura do condomínio
route('GET', '/api/admin/assinatura', function ($c) {
    $cid = $c['user']['condo_id']; $co = condoOf($cid);
    $faturas = array_map(fn($f) => ['estado' => faturaEstado($f)] + $f, rows('SELECT * FROM faturas WHERE condo_id = ? ORDER BY periodo DESC', [$cid]));
    send(200, ['plano' => $co['plano'], 'estado' => $co['estado'], 'fracoesAtivas' => count(array_filter(lista($cid, 'moradores'), 'isAtivo')), 'planos' => planosLista(), 'faturas' => $faturas]);
}, 'admin');
route('POST', '/api/admin/assinatura/plano', function ($c) {
    $u = $c['user']; $plano = str(readJson(10000)['plano'] ?? '');
    if (!isset(PLANOS[$plano])) fail(400, 'Plano desconhecido.');
    $n = count(array_filter(lista($u['condo_id'], 'moradores'), 'isAtivo'));
    $lim = PLANOS[$plano]['limite'];
    if ($lim !== null && $n > $lim) fail(400, "O plano $plano permite até $lim frações e o condomínio tem $n ativas.");
    run('UPDATE condominios SET plano = ? WHERE id = ?', [$plano, $u['condo_id']]);
    $cfg = getRec($u['condo_id'], 'config', 'geral'); if ($cfg) putRec($u['condo_id'], 'config', array_merge($cfg, ['plano' => $plano]));
    logAcao($u['condo_id'], $u['id'], 'Alterou', "Plano da assinatura: $plano (a partir da próxima fatura)");
    send(200, ['ok' => true]);
}, 'admin');

route('POST', '/api/admin/ficheiros', function ($c) {
    send(201, ['ok' => true, 'id' => saveFile($c['user']['condo_id'], readJson(7500000))]);
}, 'admin');
route('GET', '/ficheiros/:id', function ($c) {
    $u = $c['user'];
    $f = one('SELECT * FROM ficheiros WHERE id = ? AND condo_id = ?', [$c['p']['id'], $u['condo_id']]);
    if (!$f || !is_file(UPLOADS . '/' . $f['id'])) fail(404, 'Ficheiro não encontrado.');
    if ($u['papel'] === 'morador') {
        $m = getRec($u['condo_id'], 'moradores', $u['morador_id']) ?: [];
        $permitido = ($m['fotoId'] ?? null) === $f['id']
            || array_filter(lista($u['condo_id'], 'documentos'), fn($d) => ($d['ficheiroId'] ?? null) === $f['id'] && ($d['visivel'] ?? '') === 'Sim')
            || array_filter(lista($u['condo_id'], 'comunicacoes'), fn($x) => ($x['comprovativoId'] ?? null) === $f['id'] && ($x['moradorId'] ?? null) === ($m['id'] ?? ''));
        if (!$permitido) fail(403, 'Sem acesso a este ficheiro.');
    }
    sendHeaders(200, $f['tipo'], ['Content-Disposition' => 'inline; filename="' . rawurlencode($f['nome']) . '"', 'Cache-Control' => 'private, max-age=3600', 'Content-Length' => (string)filesize(UPLOADS . '/' . $f['id'])]);
    readfile(UPLOADS . '/' . $f['id']);
}, 'any');

/* ---- Recibo e relatório (páginas para imprimir ou guardar em PDF) ---- */
const PRINT_CSS = <<<'CSS'
<style>
*{box-sizing:border-box}body{margin:0;background:#EEF1EE;font:14px/1.5 "IBM Plex Sans",system-ui,sans-serif;color:#15221E}
.sheet{max-width:780px;margin:24px auto;background:#fff;padding:40px 44px;border:1px solid #DDE3DE}
.bar{max-width:780px;margin:16px auto 0;display:flex;justify-content:flex-end;gap:8px;padding:0 16px}
.bar button,.bar a{font:500 14px system-ui;padding:8px 14px;border-radius:8px;border:1px solid #0E6655;background:#0E6655;color:#fff;cursor:pointer;text-decoration:none}
.bar a{background:#fff;color:#0E6655}
h1{font:600 22px/1.2 Sora,system-ui,sans-serif;margin:0}h2{font:600 15px Sora,system-ui,sans-serif;margin:24px 0 8px}
.head{display:flex;justify-content:space-between;gap:16px;border-bottom:2px solid #0E6655;padding-bottom:14px;margin-bottom:18px}
.muted{color:#5B6863}.num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
table{width:100%;border-collapse:collapse}td,th{padding:7px 8px;border-bottom:1px solid #DDE3DE;text-align:left}th{font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:#5B6863;font-weight:500}
.total{font:600 26px Sora,system-ui,sans-serif}.box{background:#F2F4F1;border-radius:8px;padding:12px 14px;margin-top:12px}
.sign{margin-top:48px;display:flex;justify-content:space-between;gap:24px}.sign div{flex:1;border-top:1px solid #15221E;padding-top:6px;text-align:center;color:#5B6863;font-size:12px}
.kp{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}.kp div{background:#F2F4F1;border-radius:8px;padding:10px}.kp b{display:block;font:600 16px Sora,system-ui}
@media print{body{background:#fff}.bar{display:none}.sheet{margin:0;border:0;padding:0}}
@media (max-width:600px){.sheet{padding:20px 16px}.kp{grid-template-columns:1fr 1fr}.head{flex-direction:column}}
</style>
CSS;
function pageShell(string $title, string $body, string $voltar): string {
    return '<!doctype html><html lang="pt"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . esc($title) . '</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Sora:wght@600&family=IBM+Plex+Sans:wght@400;500;600&display=swap">' . PRINT_CSS . '</head><body>
<div class="bar"><a href="' . esc($voltar) . '">Voltar</a><button onclick="window.print()">Imprimir ou guardar PDF</button></div><div class="sheet">' . $body . '</div></body></html>';
}

route('GET', '/recibo/:id', function ($c) {
    $u = $c['user']; if (!$u) return redirect('/login');
    $pg = getRec($u['condo_id'], 'pagamentos', $c['p']['id']);
    if (!$pg || ($u['papel'] === 'morador' && ($pg['moradorId'] ?? null) !== $u['morador_id'])) fail(404, 'Recibo não encontrado.');
    $m = getRec($u['condo_id'], 'moradores', $pg['moradorId'] ?? null) ?: ['nome' => '—', 'fracao' => '—'];
    $cfg = getRec($u['condo_id'], 'config', 'geral') ?: [];
    $ref = first($pg['referencia'] ?? '', $pg['id']);
    $op = !empty($pg['operacao']);
    $body = '
    <div class="head"><div><h1>' . esc($cfg['nome'] ?? '') . '</h1><div class="muted">' . esc($cfg['morada'] ?? '') . (!empty($cfg['nif']) ? ' · NIF ' . esc($cfg['nif']) : '') . '</div></div>
      <div style="text-align:right"><div class="muted" style="font-size:12px;text-transform:uppercase;letter-spacing:.06em">Recibo de quota</div><h1>N.º ' . esc($ref) . '</h1><div class="muted">Emitido em ' . esc(dataBr(today())) . '</div></div></div>
    <p>Recebemos de <b>' . esc($m['nome']) . '</b>, ' . (($m['tipo'] ?? '') === 'Inquilino' ? 'inquilino(a)' : 'proprietário(a)') . ' da fração <b>' . esc($m['fracao']) . '</b>, a quantia de:</p>
    <div class="box"><div class="total">' . kz($pg['valor'] ?? 0) . '</div><div>' . esc(porExtenso(num($pg['valor'] ?? 0))) . '</div></div>
    <table style="margin-top:18px"><tr><th>Referente a</th><th>Data do pagamento</th><th>Método</th>' . ($op ? '<th>Ref. da operação</th>' : '') . '<th class="num">Valor</th></tr>
      <tr><td>Quota de condomínio — ' . esc(mesLongo(str($pg['mes'] ?? ''))) . '</td><td>' . esc(dataBr($pg['data'] ?? '')) . '</td><td>' . esc($pg['metodo'] ?? '') . '</td>' . ($op ? '<td>' . esc($pg['operacao']) . '</td>' : '') . '<td class="num">' . kz($pg['valor'] ?? 0) . '</td></tr></table>
    <p class="muted" style="margin-top:18px;font-size:12px">Este recibo comprova o pagamento da quota indicada e foi emitido pelo sistema Kondo.</p>
    <div class="sign"><div>A Administração</div><div>O Condómino</div></div>';
    html(pageShell("Recibo $ref", $body, areaDe($u)));
});

route('GET', '/relatorio/:mes', function ($c) {
    $u = $c['user']; $mes = $c['p']['mes'];
    if (!$u) return redirect('/login');
    if ($u['papel'] !== 'admin') fail(403, 'Não tem permissão para esta área.');
    if (!isMes($mes)) fail(400, 'Mês inválido.');
    $cid = $u['condo_id']; $cfg = getRec($cid, 'config', 'geral') ?: [];
    $ativos = array_values(array_filter(lista($cid, 'moradores'), 'isAtivo')); $pags = lista($cid, 'pagamentos');
    $pagMes = array_filter($pags, fn($x) => ($x['mes'] ?? '') === $mes);
    $recebidoMes = array_filter($pags, fn($x) => str_starts_with(str($x['data'] ?? ''), $mes));
    $desp = array_values(array_filter(lista($cid, 'despesas'), fn($d) => str_starts_with(str($d['data'] ?? ''), $mes)));
    $soma = fn(array $a) => array_sum(array_map(fn($x) => num($x['valor'] ?? 0), $a));
    $porCat = []; foreach ($desp as $d) { $k = first($d['categoria'] ?? '', 'Outro'); $porCat[$k] = ($porCat[$k] ?? 0) + num($d['valor'] ?? 0); }
    arsort($porCat);
    $esperado = array_sum(array_map(fn($m) => num($m['quota'] ?? 0), $ativos)); $cobrado = $soma($pagMes);
    $entradas = $soma($recebidoMes); $saidas = $soma($desp);
    $dev = [];
    foreach ($ativos as $m) { $d = dividaDe($cid, $m, $pags); if ($d['total']) $dev[] = ['m' => $m, 'd' => $d]; }
    usort($dev, fn($a, $b) => $b['d']['total'] <=> $a['d']['total']);
    $ocs = array_values(array_filter(lista($cid, 'ocorrencias'), fn($o) => str_starts_with(str($o['data'] ?? ''), $mes)));
    $res = $entradas - $saidas;

    $tDesp = $desp ? '<table><tr><th>Categoria</th><th class="num">Valor</th><th class="num">%</th></tr>' . implode('', array_map(fn($k, $v) => '<tr><td>' . esc($k) . '</td><td class="num">' . kz($v) . '</td><td class="num">' . round($v / $saidas * 100) . '%</td></tr>', array_keys($porCat), $porCat)) . '<tr><th>Total</th><th class="num">' . kz($saidas) . '</th><th></th></tr></table>' : '<p class="muted">Sem despesas registadas neste mês.</p>';
    $tDev = $dev ? '<table><tr><th>Fração</th><th>Responsável</th><th>Meses</th><th class="num">Valor</th></tr>' . implode('', array_map(fn($x) => '<tr><td>' . esc($x['m']['fracao'] ?? '') . '</td><td>' . esc($x['m']['nome'] ?? '') . '</td><td>' . count($x['d']['meses']) . '</td><td class="num">' . kz($x['d']['total']) . '</td></tr>', $dev)) . '<tr><th colspan="3">Total em atraso</th><th class="num">' . kz(array_sum(array_column(array_column($dev, 'd'), 'total'))) . '</th></tr></table>' : '<p class="muted">Nenhuma fração em atraso.</p>';
    $tOc = $ocs ? '<table><tr><th>Data</th><th>Ocorrência</th><th>Estado</th></tr>' . implode('', array_map(fn($o) => '<tr><td>' . esc(dataBr($o['data'])) . '</td><td>' . esc($o['titulo'] ?? '') . '</td><td>' . esc($o['estado'] ?? '') . '</td></tr>', $ocs)) . '</table>' : '<p class="muted">Sem ocorrências registadas.</p>';
    $body = '
    <div class="head"><div><h1>' . esc($cfg['nome'] ?? '') . '</h1><div class="muted">' . esc($cfg['morada'] ?? '') . '</div></div><div style="text-align:right"><div class="muted" style="font-size:12px;text-transform:uppercase;letter-spacing:.06em">Relatório mensal</div><h1>' . esc(mesLongo($mes)) . '</h1></div></div>
    <div class="kp"><div><span class="muted">Quotas do mês</span><b>' . kz($cobrado) . '</b><span class="muted">' . ($esperado ? round($cobrado / $esperado * 100) : 0) . '% de ' . kz($esperado) . '</span></div>
      <div><span class="muted">Entradas no mês</span><b>' . kz($entradas) . '</b><span class="muted">' . count($recebidoMes) . ' recibos</span></div>
      <div><span class="muted">Despesas</span><b>' . kz($saidas) . '</b><span class="muted">' . count($desp) . ' lançamentos</span></div>
      <div><span class="muted">Resultado</span><b style="color:' . ($res < 0 ? '#B23A26' : '#1F7A45') . '">' . kz($res) . '</b><span class="muted">entradas − despesas</span></div></div>
    <h2>Despesas por categoria</h2>' . $tDesp . '
    <h2>Frações com quotas em atraso (situação atual)</h2>' . $tDev . '
    <h2>Ocorrências do mês</h2>' . $tOc . '
    <p class="muted" style="margin-top:24px;font-size:12px">Gerado pelo Kondo em ' . esc(dataBr(today())) . '.</p>';
    html(pageShell('Relatório ' . mesLongo($mes), $body, '/admin'));
});

/* ---- Portal do morador ---- */
function ordenarDesc(array $a, string $k): array { usort($a, fn($x, $y) => strcmp(str($y[$k] ?? ''), str($x[$k] ?? ''))); return $a; }
function portalDados(array $u): ?array {
    $cid = $u['condo_id'];
    $m = getRec($cid, 'moradores', $u['morador_id']);
    if (!$m) return null;
    $cfg = getRec($cid, 'config', 'geral') ?: [];
    $pags = ordenarDesc(array_values(array_filter(lista($cid, 'pagamentos'), fn($p) => ($p['moradorId'] ?? null) === $m['id'])), 'mes');
    $grupos = ['Todos', ($m['tipo'] ?? '') === 'Inquilino' ? 'Inquilinos' : 'Proprietários', 'Bloco ' . ($m['bloco'] ?? '')];
    $perfil = $m; unset($perfil['observacoes']);
    return [
        'condominio' => ['nome' => $cfg['nome'] ?? null, 'morada' => $cfg['morada'] ?? null, 'iban' => $cfg['iban'] ?? null, 'diaVencimento' => $cfg['diaVencimento'] ?? 10],
        'morador' => $perfil, 'pagamentos' => $pags, 'divida' => dividaDe($cid, $m, $pags),
        'avisos' => ordenarDesc(array_values(array_filter(lista($cid, 'avisos'), fn($a) => in_array($a['destinatarios'] ?? null, $grupos, true))), 'data'),
        'ocorrencias' => ordenarDesc(array_values(array_filter(lista($cid, 'ocorrencias'), fn($o) => ($o['criadoPor'] ?? null) === $u['id'] || (!empty($o['fracao']) && $o['fracao'] === ($m['fracao'] ?? null)))), 'data'),
        'documentos' => ordenarDesc(array_map(function ($d) { unset($d['notas']); return $d; }, array_values(array_filter(lista($cid, 'documentos'), fn($d) => ($d['visivel'] ?? '') === 'Sim'))), 'data'),
        'comunicacoes' => ordenarDesc(array_values(array_filter(lista($cid, 'comunicacoes'), fn($x) => ($x['moradorId'] ?? null) === $m['id'])), 'criado'),
        'portaria' => array_map(fn($f) => ['nome' => $f['nome'] ?? null, 'funcao' => $f['funcao'] ?? null, 'turno' => $f['turno'] ?? null, 'telefone' => $f['telefone'] ?? null],
            array_values(array_filter(lista($cid, 'funcionarios'), fn($f) => ($f['estado'] ?? '') === 'Ativo' && in_array($f['funcao'] ?? '', ['Porteiro', 'Segurança', 'Administrador'], true)))),
        'editaveis' => MORADOR_EDITAVEIS,
    ];
}
route('GET', '/api/portal', function ($c) {
    $d = portalDados($c['user']);
    if (!$d) fail(404, 'O seu perfil de morador não foi encontrado. Contacte a administração.');
    send(200, $d + ['eu' => publicUser($c['user'])]);
}, 'morador');
route('PUT', '/api/portal/perfil', function ($c) {
    $u = $c['user']; $b = readJson(20000);
    $m = getRec($u['condo_id'], 'moradores', $u['morador_id']);
    if (!$m) fail(404, 'Perfil não encontrado.');
    $mud = [];
    foreach (MORADOR_EDITAVEIS as $k) if (array_key_exists($k, $b)) {
        $v = $k === 'agregado' ? max(0, min(30, (int)$b[$k])) : mb_substr(trim(str($b[$k])), 0, 160);
        if (($m[$k] ?? null) !== $v) { $m[$k] = $v; $mud[] = $k; }
    }
    if ($mud) { putRec($u['condo_id'], 'moradores', $m); logAcao($u['condo_id'], $u['id'], 'Alterou', "Morador atualizou o próprio perfil ({$m['fracao']}): " . implode(', ', $mud)); }
    send(200, ['ok' => true]);
}, 'morador');
route('POST', '/api/portal/ocorrencias', function ($c) {
    $u = $c['user']; $b = readJson(20000);
    $m = getRec($u['condo_id'], 'moradores', $u['morador_id']);
    $titulo = mb_substr(trim(str($b['titulo'] ?? '')), 0, 120);
    if ($titulo === '') fail(400, 'Escreva um título para a ocorrência.');
    $cats = ['Infraestrutura', 'Segurança', 'Convivência', 'Estacionamento', 'Limpeza', 'Outro'];
    $o = ['id' => newId('o_'), 'titulo' => $titulo, 'categoria' => in_array($b['categoria'] ?? null, $cats, true) ? $b['categoria'] : 'Outro', 'descricao' => mb_substr(str($b['descricao'] ?? ''), 0, 2000),
        'prioridade' => 'Média', 'estado' => 'Aberta', 'data' => today(), 'fracao' => $m['fracao'] ?? '', 'criadoPor' => $u['id'], 'origem' => 'Portal do morador'];
    putRec($u['condo_id'], 'ocorrencias', $o);
    logAcao($u['condo_id'], $u['id'], 'Criou', "Ocorrência pelo portal ({$o['fracao']}): $titulo");
    send(201, ['ok' => true, 'ocorrencia' => $o]);
}, 'morador');
route('POST', '/api/portal/pagamentos', function ($c) {
    $u = $c['user']; $b = readJson(7500000); $cid = $u['condo_id'];
    $m = getRec($cid, 'moradores', $u['morador_id']);
    if (!$m) fail(404, 'Perfil não encontrado.');
    $meses = array_values(array_unique(array_filter(array_map('strval', is_array($b['meses'] ?? null) ? array_filter($b['meses'], 'is_scalar') : []), 'isMes'))); sort($meses);
    if (!$meses) fail(400, 'Escolha pelo menos um mês.');
    $pagos = array_column(array_filter(lista($cid, 'pagamentos'), fn($x) => ($x['moradorId'] ?? null) === $m['id']), 'mes');
    if (array_intersect($meses, $pagos)) fail(400, 'Um dos meses escolhidos já está pago.');
    $pend = [];
    foreach (lista($cid, 'comunicacoes') as $x) if (($x['moradorId'] ?? null) === $m['id'] && ($x['estado'] ?? '') === 'Pendente') $pend = array_merge($pend, $x['meses'] ?? []);
    if (array_intersect($meses, $pend)) fail(400, 'Já comunicou o pagamento de um destes meses. Aguarde a confirmação da administração.');
    $valor = (int)round((float)num($b['valor'] ?? 0));
    if ($valor <= 0) fail(400, 'Indique o valor pago.');
    $data = preg_match('/^\d{4}-\d{2}-\d{2}$/', str($b['data'] ?? '')) ? $b['data'] : today();
    $metodos = ['Multicaixa Express', 'Transferência BAI', 'Transferência BFA', 'Transferência BIC', 'Depósito BIC', 'Numerário', 'Outro'];
    $comprovativoId = !empty($b['comprovativo']) ? saveFile($cid, $b['comprovativo']) : null;
    putRec($cid, 'comunicacoes', ['id' => newId('cp_'), 'moradorId' => $m['id'], 'fracao' => $m['fracao'], 'meses' => $meses, 'valor' => $valor, 'data' => $data,
        'metodo' => in_array($b['metodo'] ?? null, $metodos, true) ? $b['metodo'] : 'Outro', 'referencia' => mb_substr(str($b['referencia'] ?? ''), 0, 60),
        'comprovativoId' => $comprovativoId, 'estado' => 'Pendente', 'criado' => nowIso()]);
    logAcao($cid, $u['id'], 'Comunicou', "Pagamento ({$m['fracao']}): " . implode(', ', $meses) . ' — ' . kz($valor));
    send(201, ['ok' => true]);
}, 'morador');

/* ---- Plataforma (operador do SaaS) ---- */
function resumoPlataforma(): array {
    $faturas = array_map(fn($f) => ['estado' => faturaEstado($f)] + $f, rows('SELECT f.*, c.nome AS condo_nome FROM faturas f JOIN condominios c ON c.id = f.condo_id ORDER BY f.periodo DESC, c.nome'));
    $condos = array_map(function ($c) use ($faturas) {
        $mor = lista($c['id'], 'moradores'); $us = rows('SELECT * FROM utilizadores WHERE condo_id = ?', [$c['id']]);
        $info = planoInfo($c['plano']);
        $fs = array_filter($faturas, fn($f) => $f['condo_id'] === $c['id']);
        return ['id' => $c['id'], 'nome' => $c['nome'], 'nif' => $c['nif'], 'contacto' => $c['contacto'], 'plano' => $c['plano'], 'estado' => $c['estado'], 'criado' => $c['criado'],
            'preco' => $info['preco'], 'limite' => $info['limite'], 'fracoes' => count(array_filter($mor, 'isAtivo')),
            'moradoresComAcesso' => count(array_filter($us, fn($x) => $x['papel'] === 'morador' && $x['ativo'])),
            'admins' => array_map('publicUser', array_values(array_filter($us, fn($x) => $x['papel'] === 'admin'))),
            'ultimaAtividade' => scalar('SELECT MAX(t) FROM historico WHERE condo_id = ?', [$c['id']]),
            'emAtraso' => array_sum(array_map(fn($f) => $f['valor'], array_filter($fs, fn($f) => $f['estado'] === 'Em atraso')))];
    }, rows('SELECT * FROM condominios ORDER BY criado'));
    return ['planos' => planosLista(), 'condominios' => $condos, 'faturas' => $faturas];
}
route('GET', '/api/plataforma/resumo', fn($c) => send(200, resumoPlataforma() + ['eu' => ['nome' => $c['op']['nome'], 'email' => $c['op']['email']]]), 'op');
route('POST', '/api/plataforma/condominios', function () {
    $b = readJson(20000);
    $nome = trim(str($b['nome'] ?? '')); $adminNome = trim(str($b['adminNome'] ?? ''));
    if ($nome === '') fail(400, 'Indique o nome do condomínio.');
    if ($adminNome === '') fail(400, 'Indique o nome do administrador.');
    if (!isset(PLANOS[str($b['plano'] ?? '')])) fail(400, 'Escolha um plano.');
    $id = newId('c_'); $pdo = db();
    $pdo->beginTransaction();
    try {
        run('INSERT INTO condominios (id, nome, criado, estado, plano, nif, contacto) VALUES (?, ?, ?, ?, ?, ?, ?)', [$id, $nome, nowIso(), 'Ativo', $b['plano'], str($b['nif'] ?? '') ?: null, str($b['contacto'] ?? '') ?: null]);
        putRec($id, 'config', ['id' => 'geral', 'nome' => $nome, 'morada' => str($b['morada'] ?? ''), 'nif' => str($b['nif'] ?? ''), 'iban' => '', 'diaVencimento' => 10, 'inicio' => curMonth(), 'plano' => $b['plano']]);
        $r = criarUtilizador($id, 'admin', $b['adminEmail'] ?? '', $adminNome);
        emitirFatura($id, curMonth());
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    logAcao($id, null, 'Criou', "Condomínio criado na plataforma, plano {$b['plano']}");
    send(201, ['ok' => true, 'id' => $id, 'senha_temporaria' => $r['senha'], 'utilizador' => $r['utilizador']]);
}, 'op');
route('PATCH', '/api/plataforma/condominios/:id', function ($c) {
    $co = condoOf($c['p']['id']); if (!$co) fail(404, 'Condomínio não encontrado.');
    $b = readJson(10000);
    if (!empty($b['estado'])) {
        if (!in_array($b['estado'], ['Ativo', 'Suspenso'], true)) fail(400, 'Estado inválido.');
        run('UPDATE condominios SET estado = ? WHERE id = ?', [$b['estado'], $co['id']]);
        if ($b['estado'] === 'Suspenso') run("DELETE FROM sessoes WHERE user_id IN (SELECT id FROM utilizadores WHERE condo_id = ? AND papel = 'morador')", [$co['id']]);
        logAcao($co['id'], null, 'Alterou', 'Plataforma: condomínio ' . ($b['estado'] === 'Ativo' ? 'reativado' : 'suspenso'));
    }
    if (!empty($b['plano'])) {
        if (!isset(PLANOS[str($b['plano'])])) fail(400, 'Plano desconhecido.');
        run('UPDATE condominios SET plano = ? WHERE id = ?', [$b['plano'], $co['id']]);
        $cfg = getRec($co['id'], 'config', 'geral'); if ($cfg) putRec($co['id'], 'config', array_merge($cfg, ['plano' => $b['plano']]));
        logAcao($co['id'], null, 'Alterou', "Plataforma: plano alterado para {$b['plano']}");
    }
    send(200, ['ok' => true]);
}, 'op');
route('POST', '/api/plataforma/utilizadores/:id/repor', function ($c) {
    $u = one("SELECT * FROM utilizadores WHERE id = ? AND papel = 'admin'", [$c['p']['id']]);
    if (!$u) fail(404, 'Administrador não encontrado.');
    $senha = reporSenha($u);
    logAcao($u['condo_id'], null, 'Alterou', "Plataforma: palavra-passe reposta para {$u['nome']} ({$u['email']})");
    send(200, ['ok' => true, 'senha_temporaria' => $senha, 'utilizador' => publicUser($u)]);
}, 'op');
route('POST', '/api/plataforma/faturas/emitir', function () {
    $periodo = str(readJson(10000)['periodo'] ?? curMonth());
    if (!isMes($periodo)) fail(400, 'Período inválido.');
    $antes = (int)scalar('SELECT COUNT(*) FROM faturas');
    foreach (rows("SELECT id FROM condominios WHERE estado = 'Ativo'") as $co) emitirFatura($co['id'], $periodo);
    send(200, ['ok' => true, 'emitidas' => (int)scalar('SELECT COUNT(*) FROM faturas') - $antes]);
}, 'op');
route('PATCH', '/api/plataforma/faturas/:id', function ($c) {
    $estado = str(readJson(10000)['estado'] ?? '');
    if (!in_array($estado, ['Paga', 'Pendente', 'Anulada'], true)) fail(400, 'Estado inválido.');
    $f = one('SELECT * FROM faturas WHERE id = ?', [$c['p']['id']]); if (!$f) fail(404, 'Fatura não encontrada.');
    run('UPDATE faturas SET estado = ?, paga_em = ? WHERE id = ?', [$estado, $estado === 'Paga' ? nowIso() : null, $f['id']]);
    send(200, ['ok' => true]);
}, 'op');

/* ---------------- Despacho ---------------- */
function dispatch(): void {
    $path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    $path = rtrim($path, '/') ?: '/';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    try {
        $user = sessionUser(); $op = sessionOp();
        foreach ($GLOBALS['routes'] as $r) {
            if ($r['method'] !== $method || !preg_match($r['re'], $path, $m)) continue;
            $papel = $r['papel'];
            if ($papel === 'op' && !$op) fail($user ? 403 : 401, $user ? 'Não tem permissão para esta área.' : 'A sua sessão terminou. Entre de novo.');
            if ($papel && $papel !== 'op') {
                if (!$user) fail($op ? 403 : 401, $op ? 'Não tem permissão para esta área.' : 'A sua sessão terminou. Entre de novo.');
                if ($papel !== 'any' && $user['papel'] !== $papel) fail(403, 'Não tem permissão para esta área.');
            }
            // Condomínio suspenso: moradores sem acesso; administradores só em leitura.
            if ($user && !in_array($path, ['/api/logout', '/api/senha'], true) && $papel && $papel !== 'op') {
                $co = condoOf($user['condo_id']);
                if ($co['estado'] !== 'Ativo') {
                    if ($user['papel'] === 'morador') fail(403, 'O portal deste condomínio está temporariamente indisponível. Contacte a administração.');
                    if ($method !== 'GET') fail(402, 'A assinatura do condomínio está suspensa. Regularize as faturas em atraso para voltar a fazer alterações.');
                }
            }
            $p = [];
            foreach ($r['keys'] as $i => $k) $p[$k] = $m[$i + 1];
            ($r['handler'])(['user' => $user, 'op' => $op, 'p' => $p]);
            return;
        }
        fail(404, 'Página não encontrada.');
    } catch (HttpError $e) {
        send($e->status, ['erro' => $e->getMessage()]);
    } catch (Throwable $e) {
        error_log((string)$e);
        send(500, ['erro' => 'Erro interno do servidor.']);
    }
}
