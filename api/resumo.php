<?php
/**
 * Visão geral de um mês.
 * GET ?mes=AAAA-MM → totais do mês, saldo em contas, faturas, gastos por categoria (com orçamento),
 *                    a pagar/receber ainda em aberto e evolução dos últimos 6 meses.
 */
require __DIR__ . '/lib/resposta.php';
require __DIR__ . '/lib/financas.php';

endpoint(function (PDO $pdo) {
    $mes = mes_ok($_GET['mes'] ?? null);
    [$filtro, $args] = sql_no_mes($mes);

    $tot = linha($pdo, "
        SELECT COALESCE(SUM(CASE WHEN l.tipo = 'receita' THEN l.valor END), 0) AS receitas,
               COALESCE(SUM(CASE WHEN l.tipo = 'despesa' THEN l.valor END), 0) AS despesas,
               COALESCE(SUM(CASE WHEN l.tipo = 'despesa' AND l.pago = 0 THEN l.valor END), 0) AS a_pagar,
               COALESCE(SUM(CASE WHEN l.tipo = 'receita' AND l.pago = 0 THEN l.valor END), 0) AS a_receber,
               COUNT(*) AS quantidade
        FROM lancamentos l JOIN contas c ON c.id = l.conta_id WHERE $filtro", $args);
    $tot = array_map(fn($v) => round((float) $v, 2), $tot);
    $tot['saldo'] = round($tot['receitas'] - $tot['despesas'], 2);

    // Gastos por categoria principal (subcategoria soma na mãe) x orçamento do mês
    $categorias = linhas($pdo, "
        SELECT COALESCE(mae.id, cat.id) AS id, COALESCE(mae.nome, cat.nome, 'Sem categoria') AS nome,
               COALESCE(mae.cor, cat.cor) AS cor, COALESCE(mae.icone, cat.icone) AS icone, SUM(l.valor) AS gasto
        FROM lancamentos l
        JOIN contas c ON c.id = l.conta_id
        LEFT JOIN categorias cat ON cat.id = l.categoria_id
        LEFT JOIN categorias mae ON mae.id = cat.pai_id
        WHERE l.tipo = 'despesa' AND $filtro
        GROUP BY 1, 2, 3, 4 ORDER BY gasto DESC", $args);
    $orc = array_column(linhas($pdo, 'SELECT categoria_id, valor FROM orcamentos WHERE mes = ? AND teste = ?', [$mes, modo_teste()]), 'valor', 'categoria_id');
    foreach ($categorias as &$c) {
        $c['gasto'] = round((float) $c['gasto'], 2);
        $c['orcado'] = isset($orc[$c['id']]) ? (float) $orc[$c['id']] : null;
    }
    unset($c);
    // Categorias com orçamento e sem gasto ainda
    $comGasto = array_column($categorias, 'id');
    foreach ($orc as $catId => $valor) {
        if (in_array($catId, $comGasto)) continue;
        $cat = linha($pdo, 'SELECT id, nome, cor, icone FROM categorias WHERE id = ?', [$catId]);
        if ($cat) $categorias[] = $cat + ['gasto' => 0.0, 'orcado' => (float) $valor];
    }

    // Contas e cartões
    $saldos = saldos_contas($pdo);
    $contas = [];
    $patrimonio = 0.0;
    foreach (linhas($pdo, 'SELECT * FROM contas WHERE ativo = 1 AND teste = ? ORDER BY tipo = "cartao", ordem, nome', [modo_teste()]) as $c) {
        if ($c['tipo'] === 'cartao') {
            if (!$c['dia_fechamento']) continue;
            $fatura = inicio_mes($mes);
            $t = totais_fatura($pdo, (int) $c['id'], $fatura);
            $contas[] = ['id' => $c['id'], 'nome' => $c['nome'], 'tipo' => 'cartao', 'cor' => $c['cor'], 'fatura' => $fatura]
                + datas_fatura($fatura, $c) + $t;
        } else {
            $saldo = round((float) ($saldos[$c['id']] ?? $c['saldo_inicial']), 2);
            $patrimonio += $saldo;
            $contas[] = ['id' => $c['id'], 'nome' => $c['nome'], 'tipo' => $c['tipo'], 'cor' => $c['cor'], 'saldo' => $saldo];
        }
    }

    // Em aberto: lançamentos não pagos do mês (contas a pagar / a receber)
    $abertos = linhas($pdo, "
        SELECT l.id, l.tipo, l.descricao, l.valor, l.data, c.nome AS conta_nome
        FROM lancamentos l JOIN contas c ON c.id = l.conta_id
        WHERE l.pago = 0 AND l.tipo <> 'transferencia' AND $filtro ORDER BY l.data LIMIT 20", $args);

    // Últimos 6 meses (receitas x despesas)
    $serie = [];
    for ($i = 5; $i >= 0; $i--) {
        $m = substr(somar_meses(inicio_mes($mes), -$i), 0, 7);
        [$f, $a] = sql_no_mes($m);
        $r = linha($pdo, "SELECT COALESCE(SUM(CASE WHEN l.tipo='receita' THEN l.valor END),0) AS receitas,
                                 COALESCE(SUM(CASE WHEN l.tipo='despesa' THEN l.valor END),0) AS despesas
                          FROM lancamentos l JOIN contas c ON c.id = l.conta_id WHERE $f", $a);
        $serie[] = ['mes' => $m, 'receitas' => round((float) $r['receitas'], 2), 'despesas' => round((float) $r['despesas'], 2)];
    }

    return [
        'mes' => $mes, 'totais' => $tot, 'categorias' => $categorias, 'contas' => $contas,
        'patrimonio' => round($patrimonio, 2), 'abertos' => $abertos, 'serie' => $serie,
        'vazio' => !linha($pdo, 'SELECT id FROM contas WHERE teste = ? LIMIT 1', [modo_teste()]),
        'teste' => (bool) modo_teste(),
    ];
});
