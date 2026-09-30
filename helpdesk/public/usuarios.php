<?php
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/layout.php';
exigir_admin();
$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $acao = $_POST['acao'] ?? '';
    if ($acao === 'criar') {
        $nome = trim($_POST['nome'] ?? ''); $email = strtolower(trim($_POST['email'] ?? '')); $senha = $_POST['senha'] ?? '';
        $perfil = $_POST['perfil'] ?? 'usuario'; $dep = trim($_POST['departamento'] ?? '');
        if ($nome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($senha) < 6 || !isset(PERFIS[$perfil])) $erro = 'Preencha nome, e-mail válido, perfil e senha (mín. 6 caracteres).';
        else try {
            db()->prepare('INSERT INTO usuarios (nome,email,senha,perfil,departamento) VALUES (?,?,?,?,?)')->execute([$nome, $email, password_hash($senha, PASSWORD_DEFAULT), $perfil, $dep ?: null]);
            flash('Usuário criado.'); redirect('usuarios.php');
        } catch (PDOException) { $erro = 'Já existe um usuário com esse e-mail.'; }
    } elseif ($acao === 'status') {
        $uid = (int)$_POST['id'];
        if ($uid !== (int)usuario()['id']) db()->prepare('UPDATE usuarios SET ativo = 1 - ativo WHERE id = ?')->execute([$uid]);
        redirect('usuarios.php');
    } elseif ($acao === 'senha') {
        if (strlen($_POST['senha'] ?? '') >= 6) { db()->prepare('UPDATE usuarios SET senha = ? WHERE id = ?')->execute([password_hash($_POST['senha'], PASSWORD_DEFAULT), (int)$_POST['id']]); flash('Senha alterada.'); }
        else flash('A senha deve ter ao menos 6 caracteres.', 'erro');
        redirect('usuarios.php');
    }
}
$lista = db()->query('SELECT * FROM usuarios ORDER BY ativo DESC, nome')->fetchAll();
topo('Usuários');
?>
<h1>Usuários</h1>
<div class="card"><h2>Novo usuário</h2>
<?php if ($erro): ?><div class="alert erro"><?= e($erro) ?></div><?php endif; ?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="acao" value="criar">
<div class="row">
<div><label>Nome</label><input name="nome" required></div>
<div><label>E-mail</label><input type="email" name="email" required></div>
<div><label>Departamento</label><input name="departamento"></div>
<div><label>Perfil</label><select name="perfil"><?php foreach (PERFIS as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
<div><label>Senha inicial</label><input type="password" name="senha" minlength="6" required></div>
</div><p><button class="btn">Criar usuário</button></p></form></div>
<div class="tbl"><table><tr><th>Nome</th><th>E-mail</th><th>Depto.</th><th>Perfil</th><th>Situação</th><th>Ações</th></tr>
<?php foreach ($lista as $x): ?>
<tr><td><?= e($x['nome']) ?></td><td><?= e($x['email']) ?></td><td><?= e($x['departamento'] ?? '-') ?></td><td><?= e(PERFIS[$x['perfil']]) ?></td><td><?= $x['ativo'] ? 'Ativo' : '<b style="color:var(--verm)">Inativo</b>' ?></td>
<td><form method="post" style="display:flex;gap:6px"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $x['id'] ?>">
<input type="password" name="senha" placeholder="Nova senha" style="width:130px;padding:5px"><button class="btn sec" name="acao" value="senha" style="padding:5px 10px">Trocar</button>
<?php if ($x['id'] != usuario()['id']): ?><button class="btn sec" name="acao" value="status" style="padding:5px 10px"><?= $x['ativo'] ? 'Desativar' : 'Ativar' ?></button><?php endif; ?></form></td></tr>
<?php endforeach; ?></table></div>
<?php rodape();
