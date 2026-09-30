<?php
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/layout.php';
exigir_login();
$id = (int)($_GET['id'] ?? 0);
function carregar(int $id): ?array
{
    $st = db()->prepare('SELECT c.*, s.nome solicitante, s.email solicitante_email, s.departamento, t.nome tecnico, g.nome categoria
      FROM chamados c JOIN usuarios s ON s.id = c.solicitante_id LEFT JOIN usuarios t ON t.id = c.tecnico_id LEFT JOIN categorias g ON g.id = c.categoria_id WHERE c.id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}
$c = carregar($id);
if (!$c || !pode_ver_chamado($c)) { http_response_code(404); topo('Não encontrado'); echo '<h1>Chamado não encontrado</h1>'; rodape(); exit; }
$meu = (int)usuario()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $acao = $_POST['acao'] ?? '';
    $pdo = db();
    $agora = gmdate('Y-m-d H:i:s');
    if ($acao === 'comentar') {
        $msg = trim($_POST['mensagem'] ?? '');
        $interno = e_ti() && !empty($_POST['interno']) ? 1 : 0;
        if ($msg !== '' && $c['status'] !== 'fechado') {
            $pdo->prepare('INSERT INTO comentarios (chamado_id, usuario_id, mensagem, interno) VALUES (?,?,?,?)')->execute([$id, $meu, $msg, $interno]);
            // Resposta do usuário reabre chamado que aguardava retorno; resposta da TI mantém fluxo.
            $novo = $c['status'];
            if (!e_ti() && $c['status'] === 'aguardando') $novo = 'andamento';
            if (!e_ti() && $c['status'] === 'resolvido') $novo = 'aberto';
            $pdo->prepare('UPDATE chamados SET atualizado_em = ?, status = ?, fechado_em = NULL WHERE id = ?')->execute([$agora, $novo, $id]);
            flash('Resposta registrada.');
        }
    } elseif ($acao === 'gerenciar' && e_ti()) {
        $status = $_POST['status'] ?? $c['status'];
        $prio = $_POST['prioridade'] ?? $c['prioridade'];
        $tec = (int)($_POST['tecnico_id'] ?? 0) ?: null;
        if (isset(STATUS[$status]) && isset(PRIORIDADES[$prio])) {
            $fechado = in_array($status, ['resolvido', 'fechado'], true) ? ($c['fechado_em'] ?? $agora) : null;
            // Ao assumir um chamado aberto sem técnico, passa para "em andamento".
            if ($tec && !$c['tecnico_id'] && $status === 'aberto') $status = 'andamento';
            $pdo->prepare('UPDATE chamados SET status=?, prioridade=?, tecnico_id=?, atualizado_em=?, fechado_em=? WHERE id=?')->execute([$status, $prio, $tec, $agora, $fechado, $id]);
            $log = [];
            if ($status !== $c['status']) $log[] = 'Status: ' . STATUS[$c['status']] . ' → ' . STATUS[$status];
            if ($prio !== $c['prioridade']) $log[] = 'Prioridade: ' . PRIORIDADES[$c['prioridade']] . ' → ' . PRIORIDADES[$prio];
            if ((int)$tec !== (int)$c['tecnico_id']) $log[] = 'Técnico alterado';
            if ($log) $pdo->prepare('INSERT INTO comentarios (chamado_id, usuario_id, mensagem, interno) VALUES (?,?,?,1)')->execute([$id, $meu, '⚙ ' . implode(' · ', $log), ]);
            flash('Chamado atualizado.');
        }
    } elseif ($acao === 'fechar' && (int)$c['solicitante_id'] === $meu && $c['status'] === 'resolvido') {
        $pdo->prepare("UPDATE chamados SET status='fechado', atualizado_em=? WHERE id=?")->execute([$agora, $id]);
        flash('Chamado fechado. Obrigado!');
    }
    redirect("chamado.php?id=$id");
}

$coms = db()->prepare('SELECT m.*, u.nome, u.perfil FROM comentarios m JOIN usuarios u ON u.id = m.usuario_id WHERE chamado_id = ?' . (e_ti() ? '' : ' AND interno = 0') . ' ORDER BY m.id');
$coms->execute([$id]);
$coms = $coms->fetchAll();
$tecnicos = e_ti() ? db()->query("SELECT id, nome FROM usuarios WHERE perfil IN ('tecnico','admin') AND ativo = 1 ORDER BY nome")->fetchAll() : [];
topo("Chamado #$id");
?>
<h1>#<?= $id ?> · <?= e($c['titulo']) ?></h1>
<div class="card">
<div class="meta">
<div><small>Status</small><?= badge_status($c['status']) ?></div>
<div><small>Prioridade</small><?= badge_prio($c['prioridade']) ?></div>
<div><small>Categoria</small><?= e($c['categoria'] ?? '-') ?></div>
<div><small>Solicitante</small><?= e($c['solicitante']) ?><?= $c['departamento'] ? ' · ' . e($c['departamento']) : '' ?></div>
<div><small>Técnico</small><?= e($c['tecnico'] ?? 'Não atribuído') ?></div>
<div><small>Aberto em</small><?= data_br($c['criado_em']) ?></div>
<div><small>Atualizado</small><?= data_br($c['atualizado_em']) ?></div>
<?php if ($c['fechado_em']): ?><div><small>Resolvido em</small><?= data_br($c['fechado_em']) ?></div><?php endif; ?>
</div>
<div class="desc"><?= e($c['descricao']) ?></div>
</div>

<?php if (e_ti()): ?>
<div class="card"><h2>Gerenciar chamado</h2>
<form method="post"><?= csrf_field() ?><input type="hidden" name="acao" value="gerenciar">
<div class="row">
<div><label>Status</label><select name="status"><?php foreach (STATUS as $k => $v): ?><option value="<?= $k ?>" <?= $c['status'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
<div><label>Prioridade</label><select name="prioridade"><?php foreach (PRIORIDADES as $k => $v): ?><option value="<?= $k ?>" <?= $c['prioridade'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
<div><label>Técnico responsável</label><select name="tecnico_id"><option value="">— Não atribuído —</option>
<?php foreach ($tecnicos as $t): ?><option value="<?= $t['id'] ?>" <?= (int)$c['tecnico_id'] === (int)$t['id'] ? 'selected' : '' ?>><?= e($t['nome']) ?></option><?php endforeach; ?></select></div>
</div>
<p><button class="btn azul">Salvar alterações</button></p></form></div>
<?php endif; ?>

<h2>Histórico</h2>
<?php foreach ($coms as $m): ?>
<div class="msg <?= $m['perfil'] !== 'usuario' ? 'ti' : '' ?> <?= $m['interno'] ? 'interno' : '' ?>">
<header><b><?= e($m['nome']) ?></b> · <?= e(PERFIS[$m['perfil']]) ?> · <?= data_br($m['criado_em']) ?><?= $m['interno'] ? ' · <b>🔒 Nota interna (TI)</b>' : '' ?></header>
<p><?= e($m['mensagem']) ?></p></div>
<?php endforeach; if (!$coms): ?><p style="color:#777">Sem mensagens ainda.</p><?php endif; ?>

<?php if ($c['status'] !== 'fechado'): ?>
<div class="card"><h2>Responder</h2>
<form method="post"><?= csrf_field() ?><input type="hidden" name="acao" value="comentar">
<textarea name="mensagem" required placeholder="Escreva sua mensagem…"></textarea>
<?php if (e_ti()): ?><label style="font-weight:400"><input type="checkbox" name="interno" value="1" style="width:auto"> Nota interna (visível apenas para a TI)</label><?php endif; ?>
<p><button class="btn">Enviar</button>
<?php if ((int)$c['solicitante_id'] === $meu && $c['status'] === 'resolvido'): ?>
</form><form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="acao" value="fechar"><button class="btn azul">Problema resolvido – fechar chamado</button></p></form>
<?php else: ?></p></form><?php endif; ?>
</div>
<?php endif; ?>
<?php rodape();
