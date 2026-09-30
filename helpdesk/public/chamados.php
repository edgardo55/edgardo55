<?php
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/layout.php';
exigir_login();
$w = ['1=1']; $p = [];
if (!e_ti()) { $w[] = 'c.solicitante_id = ?'; $p[] = usuario()['id']; }
$fs = $_GET['status'] ?? ''; $fp = $_GET['prioridade'] ?? ''; $fc = (int)($_GET['categoria'] ?? 0); $q = trim($_GET['q'] ?? ''); $ft = $_GET['tecnico'] ?? '';
if ($fs === 'pendentes') $w[] = "c.status NOT IN ('resolvido','fechado')";
elseif (isset(STATUS[$fs])) { $w[] = 'c.status = ?'; $p[] = $fs; }
if (isset(PRIORIDADES[$fp])) { $w[] = 'c.prioridade = ?'; $p[] = $fp; }
if ($fc) { $w[] = 'c.categoria_id = ?'; $p[] = $fc; }
if ($q !== '') { $w[] = '(c.titulo LIKE ? OR c.descricao LIKE ? OR c.id = ?)'; $p[] = "%$q%"; $p[] = "%$q%"; $p[] = (int)$q; }
if (e_ti() && $ft === 'meus') { $w[] = 'c.tecnico_id = ?'; $p[] = usuario()['id']; }
if (e_ti() && $ft === 'nenhum') $w[] = 'c.tecnico_id IS NULL';
$st = db()->prepare('SELECT c.*, s.nome solicitante, t.nome tecnico, g.nome categoria FROM chamados c
  JOIN usuarios s ON s.id = c.solicitante_id LEFT JOIN usuarios t ON t.id = c.tecnico_id LEFT JOIN categorias g ON g.id = c.categoria_id
  WHERE ' . implode(' AND ', $w) . " ORDER BY CASE c.status WHEN 'fechado' THEN 2 WHEN 'resolvido' THEN 2 ELSE 1 END, CASE c.prioridade WHEN 'critica' THEN 1 WHEN 'alta' THEN 2 WHEN 'media' THEN 3 ELSE 4 END, c.criado_em DESC LIMIT 300");
$st->execute($p);
$lista = $st->fetchAll();
$cats = db()->query('SELECT * FROM categorias ORDER BY nome')->fetchAll();
topo('Chamados');
?>
<h1><?= e_ti() ? 'Todos os chamados' : 'Meus chamados' ?> <a class="btn" style="float:right;font-size:.9rem" href="novo.php">+ Novo chamado</a></h1>
<form class="card filtros" method="get">
<div><label>Busca</label><input name="q" value="<?= e($q) ?>" placeholder="Título, descrição ou nº"></div>
<div><label>Status</label><select name="status"><option value="">Todos</option><option value="pendentes" <?= $fs === 'pendentes' ? 'selected' : '' ?>>Pendentes</option>
<?php foreach (STATUS as $k => $v): ?><option value="<?= $k ?>" <?= $fs === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
<div><label>Prioridade</label><select name="prioridade"><option value="">Todas</option>
<?php foreach (PRIORIDADES as $k => $v): ?><option value="<?= $k ?>" <?= $fp === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
<div><label>Categoria</label><select name="categoria"><option value="">Todas</option>
<?php foreach ($cats as $c): ?><option value="<?= $c['id'] ?>" <?= $fc == $c['id'] ? 'selected' : '' ?>><?= e($c['nome']) ?></option><?php endforeach; ?></select></div>
<?php if (e_ti()): ?><div><label>Técnico</label><select name="tecnico"><option value="">Todos</option><option value="meus" <?= $ft === 'meus' ? 'selected' : '' ?>>Atribuídos a mim</option><option value="nenhum" <?= $ft === 'nenhum' ? 'selected' : '' ?>>Sem técnico</option></select></div><?php endif; ?>
<div style="flex:0"><button class="btn azul">Filtrar</button></div>
</form>
<div class="tbl"><table><tr><th>#</th><th>Título</th><th>Categoria</th><th>Prioridade</th><th>Status</th><?php if (e_ti()): ?><th>Solicitante</th><th>Técnico</th><?php endif; ?><th>Aberto em</th></tr>
<?php foreach ($lista as $c): ?>
<tr><td><a href="chamado.php?id=<?= $c['id'] ?>"><?= $c['id'] ?></a></td><td><a href="chamado.php?id=<?= $c['id'] ?>"><?= e($c['titulo']) ?></a></td><td><?= e($c['categoria'] ?? '-') ?></td>
<td><?= badge_prio($c['prioridade']) ?></td><td><?= badge_status($c['status']) ?></td>
<?php if (e_ti()): ?><td><?= e($c['solicitante']) ?></td><td><?= e($c['tecnico'] ?? '—') ?></td><?php endif; ?>
<td><?= data_br($c['criado_em']) ?></td></tr>
<?php endforeach; if (!$lista): ?><tr><td colspan="9" style="text-align:center;padding:30px">Nenhum chamado encontrado.</td></tr><?php endif; ?>
</table></div>
<?php rodape();
