<?php
function topo(string $titulo): void
{
    global $config;
    $u = usuario();
    $pag = basename($_SERVER['SCRIPT_NAME']);
    $f = flash();
    ?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titulo) ?> · <?= e($config['app_name']) ?></title>
<link rel="icon" href="assets/logo-icone.svg">
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<?php if ($u): ?>
<header class="topbar">
  <a class="brand" href="dashboard.php"><img src="assets/logo.svg" alt="Medianova"><span>Helpdesk TI</span></a>
  <nav>
    <a href="dashboard.php" class="<?= $pag === 'dashboard.php' ? 'on' : '' ?>">Painel</a>
    <a href="chamados.php" class="<?= in_array($pag, ['chamados.php', 'chamado.php']) ? 'on' : '' ?>"><?= e_ti() ? 'Chamados' : 'Meus chamados' ?></a>
    <a href="novo.php" class="<?= $pag === 'novo.php' ? 'on' : '' ?>">Abrir chamado</a>
    <?php if (e_admin()): ?><a href="usuarios.php" class="<?= $pag === 'usuarios.php' ? 'on' : '' ?>">Usuários</a><?php endif; ?>
  </nav>
  <div class="user"><span><?= e($u['nome']) ?> <small><?= e(PERFIS[$u['perfil']]) ?></small></span> <a href="sair.php">Sair</a></div>
</header>
<?php endif; ?>
<main class="container">
<?php if ($f): ?><div class="alert <?= e($f[1]) ?>"><?= e($f[0]) ?></div><?php endif; ?>
<?php
}

function rodape(): void
{
    ?>
</main>
<footer class="rodape">© <?= date('Y') ?> Medianova · Departamento de TI</footer>
</body>
</html>
<?php
}
