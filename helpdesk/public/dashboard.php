<?php
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/layout.php';
exigir_login();
$u = usuario();
$where = e_ti() ? '1=1' : 'solicitante_id = ' . (int)$u['id'];
$cont = db()->query("SELECT status, COUNT(*) n FROM chamados WHERE $where GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$total = array_sum($cont);
$criticos = (int)db()->query("SELECT COUNT(*) FROM chamados WHERE $where AND prioridade IN ('alta','critica') AND status NOT IN ('resolvido','fechado')")->fetchColumn();
$recentes = db()->query("SELECT c.*, s.nome solicitante FROM chamados c JOIN usuarios s ON s.id = c.solicitante_id WHERE $where ORDER BY c.atualizado_em DESC LIMIT 8")->fetchAll();
$porCat = e_ti() ? db()->query("SELECT COALESCE(g.nome,'Sem categoria') nome, COUNT(*) n FROM chamados c LEFT JOIN categorias g ON g.id = c.categoria_id GROUP BY g.nome ORDER BY n DESC")->fetchAll() : [];
topo('Painel');
?>
<h1>Olá, <?= e(explode(' ', $u['nome'])[0]) ?> 👋</h1>
<div class="grid">
  <div class="stat"><b><?= $total ?></b><span>Total de chamados</span></div>
  <div class="stat red"><b><?= $cont['aberto'] ?? 0 ?></b><span>Abertos</span></div>
  <div class="stat"><b><?= $cont['andamento'] ?? 0 ?></b><span>Em andamento</span></div>
  <div class="stat"><b><?= $cont['aguardando'] ?? 0 ?></b><span>Aguardando usuário</span></div>
  <div class="stat"><b><?= ($cont['resolvido'] ?? 0) + ($cont['fechado'] ?? 0) ?></b><span>Resolvidos / fechados</span></div>
  <div class="stat red"><b><?= $criticos ?></b><span>Alta/Crítica pendentes</span></div>
</div>
<div class="row" style="grid-template-columns:2fr 1fr;align-items:start">
<div class="card"><h2>Atividade recente</h2>
<?php if (!$recentes): ?><p>Nenhum chamado ainda. <a href="novo.php">Abrir o primeiro</a>.</p><?php else: ?>
<div class="tbl"><table><tr><th>#</th><th>Título</th><th>Prioridade</th><th>Status</th><th>Atualizado</th></tr>
<?php foreach ($recentes as $c): ?>
<tr><td><a href="chamado.php?id=<?= $c['id'] ?>"><?= $c['id'] ?></a></td><td><a href="chamado.php?id=<?= $c['id'] ?>"><?= e($c['titulo']) ?></a><br><small><?= e($c['solicitante']) ?></small></td>
<td><?= badge_prio($c['prioridade']) ?></td><td><?= badge_status($c['status']) ?></td><td><?= data_br($c['atualizado_em']) ?></td></tr>
<?php endforeach; ?></table></div><?php endif; ?></div>
<?php if ($porCat): ?><div class="card"><h2>Por categoria</h2>
<?php foreach ($porCat as $g): ?><div><?= e($g['nome']) ?> <b style="float:right"><?= $g['n'] ?></b><div class="barra"><i style="width:<?= round($g['n'] / max(1, $total) * 100) ?>%"></i></div></div><br><?php endforeach; ?></div><?php endif; ?>
</div>
<?php rodape();
