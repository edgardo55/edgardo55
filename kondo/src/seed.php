<?php
// Primeira execução: cria a conta da plataforma, dois condomínios de demonstração e as contas iniciais.
declare(strict_types=1);

function seedRun(): void {
    $linhas = [];
    $credFile = DATA_DIR . '/CREDENCIAIS-DEMO.txt';
    $addConta = function (string $tipo, string $email, string $nome, string $senha) use (&$linhas) {
        $linhas[] = str_pad($tipo, 14) . ' ' . str_pad($email, 30) . ' ' . $senha . '   ' . $nome;
    };

    // Conta do operador da plataforma (o dono do SaaS). Também é criada em instalações antigas.
    $opEmail = getenv('KONDO_EMAIL') ?: 'plataforma@kondo.ao';
    if (!one('SELECT 1 FROM operadores')) {
        $senha = tempPassword();
        run('INSERT INTO operadores (id, email, nome, senha, criado) VALUES (?, ?, ?, ?, ?)', [newId('op_'), $opEmail, 'Operador Kondo', hashPassword($senha), nowIso()]);
        $addConta('PLATAFORMA', $opEmail, 'Operador Kondo', $senha);
    }

    // KONDO_DEMO=0 arranca sem condomínios de demonstração (instalação de produção).
    if (getenv('KONDO_DEMO') !== '0' && !one('SELECT 1 FROM condominios')) {
        $demo = json_decode(file_get_contents(ROOT . '/seed/demo.json'), true, 512, JSON_THROW_ON_ERROR);
        $user = function (string $condo, string $papel, string $email, string $nome, ?string $moradorId = null) use ($addConta) {
            $senha = tempPassword();
            run('INSERT INTO utilizadores (id, condo_id, email, nome, senha, papel, morador_id, trocar_senha, criado) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)',
                [newId('u_'), $condo, $email, $nome, hashPassword($senha), $papel, $moradorId, nowIso()]);
            $addConta($papel === 'admin' ? 'ADMINISTRADOR' : 'MORADOR', $email, $nome, $senha);
        };
        $pdo = db();
        $pdo->beginTransaction();
        try {
            // 1. Condomínio Jardins do Talatona (completo)
            $c1 = 'c1';
            run('INSERT INTO condominios (id, nome, criado, estado, plano, nif) VALUES (?, ?, ?, ?, ?, ?)', [$c1, $demo['config'][0]['nome'], '2026-01-02T09:00:00.000Z', 'Ativo', $demo['config'][0]['plano'], $demo['config'][0]['nif']]);
            foreach ($demo as $col => $rs) foreach ($rs as $r) putRec($c1, $col, $r);
            putRec($c1, 'comunicacoes', ['id' => 'cp_demo1', 'moradorId' => 'm13', 'fracao' => 'B-3Esq', 'meses' => ['2026-09'], 'valor' => 45000, 'data' => '2026-09-25', 'metodo' => 'Multicaixa Express', 'referencia' => 'MCX 884 211 903', 'comprovativoId' => null, 'estado' => 'Pendente', 'criado' => '2026-09-25T18:22:00.000Z']);
            $user($c1, 'admin', 'admin@kondo.ao', 'Administração do Condomínio');
            foreach (['m01', 'm02'] as $id) {
                foreach ($demo['moradores'] as $m) if ($m['id'] === $id) $user($c1, 'morador', $m['email'], $m['nome'], $m['id']);
            }

            // 2. Edifício Maianga Sol (pequeno, plano Básico, com fatura em atraso)
            $c2 = 'c2';
            run('INSERT INTO condominios (id, nome, criado, estado, plano, nif) VALUES (?, ?, ?, ?, ?, ?)', [$c2, 'Edifício Maianga Sol', '2026-06-10T09:00:00.000Z', 'Ativo', 'Básico', '5402000000']);
            putRec($c2, 'config', ['id' => 'geral', 'nome' => 'Edifício Maianga Sol', 'morada' => 'Maianga, Luanda', 'nif' => '5402000000', 'iban' => '', 'diaVencimento' => 5, 'inicio' => '2026-06', 'plano' => 'Básico']);
            foreach (['Cláudia Manuel', 'Osvaldo Tomás', 'Filomena Cabral', 'Hélder Jamba', 'Rita Muanza', 'Vasco Ndala'] as $i => $nome)
                putRec($c2, 'moradores', ['id' => 'n' . ($i + 1), 'fracao' => ($i + 1) . '.º andar', 'bloco' => 'Único', 'tipologia' => 'T3', 'nome' => $nome, 'tipo' => 'Proprietário', 'estado' => 'Ativo', 'quota' => 30000, 'desde' => '2026-06', 'telefone' => '']);
            $user($c2, 'admin', 'admin.maianga@kondo.ao', 'Gestão Maianga Sol');

            // Faturas da assinatura
            foreach (['2026-07', '2026-08', '2026-09'] as $per) { emitirFatura($c1, $per); emitirFatura($c2, $per); }
            run("UPDATE faturas SET estado = 'Paga', paga_em = periodo || '-12T10:00:00.000Z' WHERE condo_id = 'c1'");
            run("UPDATE faturas SET estado = 'Paga', paga_em = periodo || '-14T10:00:00.000Z' WHERE condo_id = 'c2' AND periodo = '2026-07'");
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    }

    if ($linhas) {
        $head = is_file($credFile) ? '' : "Kondo — contas de demonstração\nGuarde estas palavras-passe e apague este ficheiro depois de as alterar.\n\n";
        file_put_contents($credFile, $head . '# criadas em ' . str_replace('T', ' ', substr(nowIso(), 0, 16)) . "\n" . implode("\n", $linhas) . "\n\n", FILE_APPEND);
        error_log('Kondo: contas de acesso criadas. Veja data/CREDENCIAIS-DEMO.txt');
    }
}
