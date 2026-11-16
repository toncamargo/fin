<?php
/**
 * Lançamentos.
 * GET  ?mes=AAAA-MM [&conta=ID] [&categoria=ID|sem] [&tipo=despesa|receita|transferencia] [&q=texto]
 * POST { acao: salvar | apagar | pago, ... }
 *   salvar: { id?, tipo, conta_id, conta_destino_id?, categoria_id?, descricao, valor, data, pago?, parcelas?, fatura?, observacao? }
 *           sem id e com parcelas > 1: cria uma linha por parcela (valor total dividido, 1 por mês)
 *   apagar: { id, todas_parcelas? }
 *   pago:   { id, pago: true|false }
 */
require __DIR__ . '/lib/resposta.php';
require __DIR__ . '/lib/financas.php';

endpoint(function (PDO $pdo) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $e = entrada_escrita();
        return match ($e['acao'] ?? '') {
            'salvar' => salvar_lancamento($pdo, $e),
            'apagar' => apagar_lancamento($pdo, $e),
            'pago' => marcar_pago($pdo, $e),
            default => throw new ErroValidacao('Ação desconhecida.'),
        };
    }
    return listar($pdo, $_GET);
});

function listar(PDO $pdo, array $q): array
{
    $mes = mes_ok($q['mes'] ?? null);
    [$filtro, $args] = sql_no_mes($mes);
    $onde = [$filtro];
    if (!empty($q['conta'])) {
        $onde[] = '(l.conta_id = :conta OR l.conta_destino_id = :conta2)';
        $args['conta'] = $args['conta2'] = id_ok($q['conta'], 'conta');
    }
    if (($q['categoria'] ?? '') === 'sem') {
        $onde[] = "l.categoria_id IS NULL AND l.tipo <> 'transferencia'";
    } elseif (!empty($q['categoria'])) {
        $onde[] = '(l.categoria_id = :cat OR cat.pai_id = :cat2)';
        $args['cat'] = $args['cat2'] = id_ok($q['categoria'], 'categoria');
    }
    if (!empty($q['tipo'])) {
        $onde[] = 'l.tipo = :tipo';
        $args['tipo'] = opcao_ok($q['tipo'], ['despesa', 'receita', 'transferencia'], 'tipo');
    }
    if (trim($q['q'] ?? '') !== '') {
        $onde[] = '(l.descricao LIKE :busca OR l.observacao LIKE :busca2)';
        $args['busca'] = $args['busca2'] = '%' . addcslashes(trim($q['q']), '%_') . '%';
    }
    $itens = linhas($pdo, "
        SELECT l.*, c.nome AS conta_nome, c.tipo AS conta_tipo, c.cor AS conta_cor,
               d.nome AS destino_nome, cat.nome AS categoria_nome, cat.cor AS categoria_cor, cat.icone AS categoria_icone
        FROM lancamentos l
        JOIN contas c ON c.id = l.conta_id
        LEFT JOIN contas d ON d.id = l.conta_destino_id
        LEFT JOIN categorias cat ON cat.id = l.categoria_id
        WHERE " . implode(' AND ', $onde) . "
        ORDER BY l.data DESC, l.id DESC", $args);

    $tot = ['receitas' => 0.0, 'despesas' => 0.0];
    foreach ($itens as $l) {
        if ($l['tipo'] === 'receita') $tot['receitas'] += (float) $l['valor'];
        if ($l['tipo'] === 'despesa') $tot['despesas'] += (float) $l['valor'];
    }
    $tot = array_map(fn($v) => round($v, 2), $tot);
    $tot['saldo'] = round($tot['receitas'] - $tot['despesas'], 2);
    return ['mes' => $mes, 'itens' => $itens, 'totais' => $tot];
}

function salvar_lancamento(PDO $pdo, array $e): array
{
    $id = id_ok($e['id'] ?? null, 'id', false);
    $tipo = opcao_ok($e['tipo'] ?? null, ['despesa', 'receita', 'transferencia'], 'tipo', 'despesa');
    $conta = conta($pdo, id_ok($e['conta_id'] ?? null, 'conta_id'));
    $valor = dinheiro($e['valor'] ?? null);
    if ($valor <= 0) throw new ErroValidacao('O valor precisa ser maior que zero.');
    $data = data_ok($e['data'] ?? null);

    $d = [
        'tipo' => $tipo,
        'conta_id' => (int) $conta['id'],
        'conta_destino_id' => null,
        'categoria_id' => null,
        'descricao' => texto($e, 'descricao', 255, true),
        'valor' => $valor,
        'data' => $data,
        'fatura' => null,
        'pago' => array_key_exists('pago', $e) ? (int) (bool) $e['pago'] : ($data <= date('Y-m-d') ? 1 : 0),
        'observacao' => texto($e, 'observacao', 5000),
    ];
    if ($tipo === 'transferencia') {
        $destino = conta($pdo, id_ok($e['conta_destino_id'] ?? null, 'conta_destino_id'));
        if ($destino['id'] === $conta['id']) throw new ErroValidacao('Escolha contas diferentes para a transferência.');
        $d['conta_destino_id'] = (int) $destino['id'];
        // Pagamento de fatura: a transferência para o cartão abate a fatura informada (ou a que vence no mês do pagamento)
        if ($destino['tipo'] === 'cartao') {
            $d['fatura'] = !empty($e['fatura']) ? substr(data_ok($e['fatura'], 'fatura'), 0, 7) . '-01' : inicio_mes(substr($data, 0, 7));
        }
    } else {
        $cat = id_ok($e['categoria_id'] ?? null, 'categoria_id', false);
        if ($cat) {
            $c = linha($pdo, 'SELECT tipo FROM categorias WHERE id = ?', [$cat]) ?? throw new ErroValidacao('Categoria não encontrada.');
            if ($c['tipo'] !== $tipo) throw new ErroValidacao('Essa categoria é de ' . ($c['tipo'] === 'despesa' ? 'despesas' : 'receitas') . '.');
        }
        $d['categoria_id'] = $cat;
    }
    if ($conta['tipo'] === 'cartao' && $tipo !== 'transferencia') {
        if (!$conta['dia_fechamento']) throw new ErroValidacao('Cadastre os dias de fechamento e vencimento desse cartão.');
        $d['fatura'] = !empty($e['fatura']) ? data_ok($e['fatura'], 'fatura') : fatura_da_compra($data, $conta);
        $d['pago'] = 1; // compra no cartão "acontece" na hora; o que fica em aberto é a fatura
    }

    if ($id) {
        $atual = linha($pdo, 'SELECT id FROM lancamentos WHERE id = ?', [$id]) ?? throw new ErroValidacao('Lançamento não encontrado.');
        $sets = implode(', ', array_map(fn($k) => "`$k` = :$k", array_keys($d)));
        executar($pdo, "UPDATE lancamentos SET $sets WHERE id = :id", $d + ['id' => $id]);
        return ['id' => $id];
    }

    // Novo: parcelado vira N lançamentos, um por mês, ligados pelo mesmo "grupo"
    $n = (int) ($e['parcelas'] ?? 1);
    if ($n < 1 || $n > 120) throw new ErroValidacao('Parcelas: de 1 a 120.');
    if ($n > 1 && $tipo === 'transferencia') throw new ErroValidacao('Transferência não pode ser parcelada.');
    $ids = [];
    $pdo->beginTransaction();
    try {
        $cents = (int) round($valor * 100);
        $base = intdiv($cents, $n);
        $grupo = $n > 1 ? bin2hex(random_bytes(8)) : null;
        for ($i = 0; $i < $n; $i++) {
            $p = $d;
            $p['valor'] = ($base + ($i === 0 ? $cents - $base * $n : 0)) / 100; // centavos que sobram vão na 1ª
            if ($n > 1) {
                $p['data'] = somar_meses($data, $i);
                $p['descricao'] = $d['descricao'];
                if ($p['fatura']) $p['fatura'] = substr(somar_meses($d['fatura'], $i), 0, 7) . '-01';
                if ($conta['tipo'] !== 'cartao') $p['pago'] = $p['data'] <= date('Y-m-d') ? 1 : 0;
            }
            $p += ['parcela' => $n > 1 ? $i + 1 : null, 'parcelas' => $n > 1 ? $n : null, 'grupo' => $grupo];
            $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($p)));
            $vals = implode(', ', array_map(fn($k) => ":$k", array_keys($p)));
            executar($pdo, "INSERT INTO lancamentos ($cols) VALUES ($vals)", $p);
            $ids[] = (int) $pdo->lastInsertId();
        }
        $pdo->commit();
    } catch (Throwable $x) {
        $pdo->rollBack();
        throw $x;
    }
    return ['id' => $ids[0], 'ids' => $ids];
}

function apagar_lancamento(PDO $pdo, array $e): array
{
    $id = id_ok($e['id'] ?? null, 'id');
    $l = linha($pdo, 'SELECT id, grupo, parcela FROM lancamentos WHERE id = ?', [$id]) ?? throw new ErroValidacao('Lançamento não encontrado.');
    if (!empty($e['todas_parcelas']) && $l['grupo']) {
        // Apaga esta e as próximas parcelas (as anteriores já aconteceram)
        $n = executar($pdo, 'DELETE FROM lancamentos WHERE grupo = ? AND parcela >= ?', [$l['grupo'], $l['parcela']]);
        return ['apagados' => $n];
    }
    executar($pdo, 'DELETE FROM lancamentos WHERE id = ?', [$id]);
    return ['apagados' => 1];
}

function marcar_pago(PDO $pdo, array $e): array
{
    $id = id_ok($e['id'] ?? null, 'id');
    executar($pdo, 'UPDATE lancamentos SET pago = ? WHERE id = ?', [(int) (bool) ($e['pago'] ?? true), $id]);
    return ['id' => $id];
}
