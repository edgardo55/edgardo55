<?php
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/layout.php';
if (usuario()) redirect('dashboard.php');
$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (login($_POST['email'] ?? '', $_POST['senha'] ?? '')) redirect('dashboard.php');
    $erro = 'E-mail ou senha inválidos.';
    usleep(400000);
}
?><!DOCTYPE html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Entrar · <?= e($config['app_name']) ?></title><link rel="icon" href="assets/logo-icone.svg"><link rel="stylesheet" href="assets/style.css"></head>
<body><div class="login-wrap">
<div class="logo"><img src="assets/logo.svg" alt="Medianova"></div>
<div class="card"><h2>Central de Chamados · TI</h2>
<?php if ($erro): ?><div class="alert erro"><?= e($erro) ?></div><?php endif; ?>
<form method="post"><?= csrf_field() ?>
<label>E-mail</label><input type="email" name="email" required autofocus value="<?= e($_POST['email'] ?? '') ?>">
<label>Senha</label><input type="password" name="senha" required>
<button class="btn">Entrar</button></form></div></div></body></html>
