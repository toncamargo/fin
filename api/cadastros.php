<?php
/**
 * Contas, cartões e categorias.
 * GET  → { contas, categorias }
 * POST { acao: salvar_conta | apagar_conta | salvar_categoria | apagar_categoria, ... }
 */
require __DIR__ . '/lib/resposta.php';
require __DIR__ . '/lib/financas.php';

endpoint(function (PDO $pdo) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $e = entrada_escrita();
        return match ($e['acao'] ?? '') {
            'salvar_conta' => salvar_conta($pdo, $e),
            'apagar_conta' => apagar_conta($pdo, $e),
            'salvar_categoria' => salvar_categoria($pdo, $e),
            'apagar_categoria' => apagar_categoria($pdo, $e),
            default => throw new ErroValidacao('Ação desconhecida.'),
        };
    }
    return cadastros($pdo);
});

function cadastros(PDO $pdo): array
{
    $saldos = saldos_contas($pdo);
    $contas = linhas($pdo, 'SELECT * FROM contas WHERE teste = ? ORDER BY ativo DESC, tipo = "cartao", ordem, nome', [modo_teste()]);
    $hoje = date('Y-m-d');
    foreach ($contas as &$c) {
        if ($c['tipo'] === 'cartao') {
            $fatura = $c['dia_fechamento'] ? fatura_da_compra($hoje, $c) : null;
            $c['fatura_atual'] = $fatura ? ['fatura' => $fatura] + datas_fatura($fatura, $c) + totais_fatura($pdo, (int) $c['id'], $fatura) : null;
            $c['usado'] = (float) (linha($pdo, "SELECT
                  COALESCE(SUM(CASE WHEN tipo='despesa' THEN valor END),0) - COALESCE(SUM(CASE WHEN tipo='receita' THEN valor END),0)
                  - COALESCE((SELECT SUM(valor) FROM lancamentos WHERE tipo='transferencia' AND conta_destino_id = ?),0) AS usado
                FROM lancamentos WHERE conta_id = ?", [$c['id'], $c['id']])['usado'] ?? 0);
        } else {
            $c['saldo'] = round((float) ($saldos[$c['id']] ?? $c['saldo_inicial']), 2);
        }
    }
    $categorias = linhas($pdo, 'SELECT * FROM categorias ORDER BY tipo, ordem, nome');
    return ['contas' => $contas, 'categorias' => $categorias];
}

function salvar_conta(PDO $pdo, array $e): array
{
    $id = id_ok($e['id'] ?? null, 'id', false);
    $tipo = opcao_ok($e['tipo'] ?? null, ['corrente', 'poupanca', 'carteira', 'investimento', 'cartao'], 'tipo', 'corrente');
    $d = [
        'nome' => texto($e, 'nome', 80, true),
        'tipo' => $tipo,
        'instituicao' => texto($e, 'instituicao', 80),
        'cor' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($e['cor'] ?? '')) ? $e['cor'] : null,
        'saldo_inicial' => $tipo === 'cartao' ? 0 : (dinheiro($e['saldo_inicial'] ?? 0, 'saldo_inicial', false) ?? 0),
        'data_inicial' => $tipo === 'cartao' ? null : data_ok($e['data_inicial'] ?? null, 'data_inicial', false),
        'limite' => $tipo === 'cartao' ? dinheiro($e['limite'] ?? null, 'limite', false) : null,
        'dia_fechamento' => null, 'dia_vencimento' => null,
        'conta_pagamento_id' => $tipo === 'cartao' ? id_ok($e['conta_pagamento_id'] ?? null, 'conta_pagamento_id', false) : null,
        'ativo' => array_key_exists('ativo', $e) ? (int) (bool) $e['ativo'] : 1,
    ];
    if ($d['conta_pagamento_id']) conta($pdo, $d['conta_pagamento_id']); // precisa ser do mesmo modo (real/teste)
    if ($tipo === 'cartao') {
        foreach (['dia_fechamento' => 'fechamento', 'dia_vencimento' => 'vencimento'] as $k => $nome) {
            $dia = (int) ($e[$k] ?? 0);
            if ($dia < 1 || $dia > 31) throw new ErroValidacao("Informe o dia de $nome do cartão (1 a 31).");
            $d[$k] = $dia;
        }
    }
    if ($id) {
        conta($pdo, $id);
        $sets = implode(', ', array_map(fn($k) => "`$k` = :$k", array_keys($d)));
        executar($pdo, "UPDATE contas SET $sets WHERE id = :id", $d + ['id' => $id]);
    } else {
        $d['teste'] = modo_teste();
        $cols = implode(', ', array_keys($d));
        $vals = implode(', ', array_map(fn($k) => ":$k", array_keys($d)));
        executar($pdo, "INSERT INTO contas ($cols) VALUES ($vals)", $d);
        $id = (int) $pdo->lastInsertId();
    }
    return ['id' => $id];
}

/** Conta sem lançamentos é apagada; com lançamentos, só é arquivada (some das listas, histórico fica). */
function apagar_conta(PDO $pdo, array $e): array
{
    $id = id_ok($e['id'] ?? null, 'id');
    conta($pdo, $id);
    $usos = (int) linha($pdo, 'SELECT COUNT(*) n FROM lancamentos WHERE conta_id = ? OR conta_destino_id = ?', [$id, $id])['n'];
    if ($usos) {
        executar($pdo, 'UPDATE contas SET ativo = 0 WHERE id = ?', [$id]);
        return ['acao' => 'arquivada', 'lancamentos' => $usos];
    }
    executar($pdo, 'DELETE FROM contas WHERE id = ?', [$id]);
    return ['acao' => 'apagada'];
}

function salvar_categoria(PDO $pdo, array $e): array
{
    $id = id_ok($e['id'] ?? null, 'id', false);
    $d = [
        'nome' => texto($e, 'nome', 60, true),
        'tipo' => opcao_ok($e['tipo'] ?? null, ['despesa', 'receita'], 'tipo', 'despesa'),
        'pai_id' => id_ok($e['pai_id'] ?? null, 'pai_id', false),
        'cor' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($e['cor'] ?? '')) ? $e['cor'] : null,
        'icone' => preg_match('/^[a-z0-9-]{1,40}$/', (string) ($e['icone'] ?? '')) ? $e['icone'] : null,
    ];
    if ($d['pai_id'] && $d['pai_id'] === $id) throw new ErroValidacao('Uma categoria não pode ser subcategoria dela mesma.');
    if ($id) {
        executar($pdo, 'UPDATE categorias SET nome = :nome, tipo = :tipo, pai_id = :pai_id, cor = :cor, icone = :icone WHERE id = :id', $d + ['id' => $id]);
    } else {
        executar($pdo, 'INSERT INTO categorias (nome, tipo, pai_id, cor, icone) VALUES (:nome, :tipo, :pai_id, :cor, :icone)', $d);
        $id = (int) $pdo->lastInsertId();
    }
    return ['id' => $id];
}

/** Lançamentos da categoria ficam sem categoria (não são apagados). */
function apagar_categoria(PDO $pdo, array $e): array
{
    $id = id_ok($e['id'] ?? null, 'id');
    $n = (int) linha($pdo, 'SELECT COUNT(*) n FROM lancamentos WHERE categoria_id = ?', [$id])['n'];
    executar($pdo, 'DELETE FROM categorias WHERE id = ?', [$id]);
    return ['acao' => 'apagada', 'lancamentos_sem_categoria' => $n];
}
