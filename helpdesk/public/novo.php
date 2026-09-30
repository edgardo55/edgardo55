<?php
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/layout.php';
exigir_login();
$cats = db()->query('SELECT * FROM categorias ORDER BY nome')->fetchAll();
$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $titulo = trim($_POST['titulo'] ?? '');
    $desc = trim($_POST['descricao'] ?? '');
    $prio = $_POST['prioridade'] ?? 'media';
    $cat = (int)($_POST['categoria_id'] ?? 0);
    if ($titulo === '' || $desc === '') $erro = 'Informe o título e a descrição.';
    elseif (!isset(PRIORIDADES[$prio])) $erro = 'Prioridade inválida.';
    else {
        db()->prepare('INSERT INTO chamados (titulo, descricao, categoria_id, prioridade, solicitante_id) VALUES (?,?,?,?,?)')
            ->execute([mb_substr($titulo, 0, 200), $desc, $cat ?: null, $prio, usuario()['id']]);
        $id = db()->lastInsertId();
        flash("Chamado #$id aberto com sucesso.");
        redirect("chamado.php?id=$id");
    }
}
topo('Abrir chamado');
?>
<h1>Abrir novo chamado</h1>
<div class="card" style="max-width:780px">
<?php if ($erro): ?><div class="alert erro"><?= e($erro) ?></div><?php endif; ?>
<form method="post"><?= csrf_field() ?>
<label>Título *</label><input name="titulo" maxlength="200" required value="<?= e($_POST['titulo'] ?? '') ?>" placeholder="Resumo do problema">
<div class="row">
<div><label>Categoria</label><select name="categoria_id"><option value="">Selecione…</option>
<?php foreach ($cats as $c): ?><option value="<?= $c['id'] ?>" <?= ($_POST['categoria_id'] ?? '') == $c['id'] ? 'selected' : '' ?>><?= e($c['nome']) ?></option><?php endforeach; ?></select></div>
<div><label>Prioridade</label><select name="prioridade">
<?php foreach (PRIORIDADES as $k => $v): ?><option value="<?= $k ?>" <?= ($_POST['prioridade'] ?? 'media') === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
</div>
<label>Descrição *</label><textarea name="descricao" required placeholder="Descreva o problema com o máximo de detalhes (o que aconteceu, quando, mensagens de erro…)"><?= e($_POST['descricao'] ?? '') ?></textarea>
<p><button class="btn">Enviar chamado</button> <a class="btn sec" href="chamados.php">Cancelar</a></p>
</form></div>
<?php rodape();
