<?php
/**
 * Dados de teste do Fin: seis meses de vida financeira fictícia (até o mês atual), marcados com teste = 1.
 * Não tocam em nada real. gerar() apaga os de teste anteriores e cria de novo; limpar() só apaga.
 */

function limpar_dados_teste(PDO $pdo): array
{
    $contas = 'SELECT id FROM contas WHERE teste = 1';
    $n = [];
    $n['lancamentos'] = executar($pdo, "DELETE FROM lancamentos WHERE teste = 1 OR conta_id IN ($contas) OR conta_destino_id IN ($contas)");
    $n['importacoes'] = executar($pdo, 'DELETE FROM importacoes WHERE teste = 1');
    $n['orcamentos'] = executar($pdo, 'DELETE FROM orcamentos WHERE teste = 1');
    $n['investimentos'] = executar($pdo, 'DELETE FROM investimentos WHERE teste = 1'); // posições vão junto
    $n['metas'] = executar($pdo, 'DELETE FROM metas WHERE teste = 1');                 // aportes vão junto
    executar($pdo, 'UPDATE contas SET conta_pagamento_id = NULL WHERE teste = 1');
    $n['contas'] = executar($pdo, 'DELETE FROM contas WHERE teste = 1');
    return $n;
}

/** INSERT de várias linhas de uma vez (poucas idas e voltas ao banco, que pode estar longe). */
function inserir_em_lote(PDO $pdo, string $tabela, array $linhas, int $porVez = 100): void
{
    if (!$linhas) return;
    $cols = array_keys($linhas[0]);
    $lista = implode(', ', array_map(fn($k) => "`$k`", $cols));
    $um = '(' . implode(', ', array_fill(0, count($cols), '?')) . ')';
    foreach (array_chunk($linhas, $porVez) as $lote) {
        $args = [];
        foreach ($lote as $l) foreach ($cols as $k) $args[] = $l[$k];
        executar($pdo, "INSERT INTO `$tabela` ($lista) VALUES " . implode(', ', array_fill(0, count($lote), $um)), $args);
    }
}

function gerar_dados_teste(PDO $pdo): array
{
    @set_time_limit(120);
    mt_srand(2026); // sempre os mesmos números
    $pdo->beginTransaction();
    try {
        limpar_dados_teste($pdo);

        // Categorias (as mesmas dos dados reais; cria a que faltar). Uma consulta só: o banco pode estar longe.
        $cats = [];
        foreach (linhas($pdo, 'SELECT id, nome, tipo FROM categorias WHERE pai_id IS NULL') as $c) $cats["{$c['tipo']}|{$c['nome']}"] = (int) $c['id'];
        $cat = function (string $nome, string $tipo = 'despesa') use ($pdo, &$cats): int {
            if (isset($cats["$tipo|$nome"])) return $cats["$tipo|$nome"];
            executar($pdo, 'INSERT INTO categorias (nome, tipo) VALUES (?, ?)', [$nome, $tipo]);
            return $cats["$tipo|$nome"] = (int) $pdo->lastInsertId();
        };

        // Contas e cartões
        $conta = function (array $d) use ($pdo): array {
            $d += ['instituicao' => null, 'saldo_inicial' => 0, 'data_inicial' => null, 'limite' => null, 'dia_fechamento' => null,
                   'dia_vencimento' => null, 'conta_pagamento_id' => null, 'ordem' => 0];
            $d['teste'] = 1;
            $cols = implode(', ', array_keys($d));
            $vals = implode(', ', array_map(fn($k) => ":$k", array_keys($d)));
            executar($pdo, "INSERT INTO contas ($cols) VALUES ($vals)", $d);
            return ['id' => (int) $pdo->lastInsertId()] + $d;
        };
        $inicio = substr(somar_meses(date('Y-m-01'), -5), 0, 7) . '-01';
        $nubank = $conta(['nome' => 'Nubank', 'tipo' => 'corrente', 'instituicao' => 'Nubank', 'cor' => '#820AD1', 'saldo_inicial' => 4200, 'data_inicial' => $inicio, 'ordem' => 1]);
        $itau = $conta(['nome' => 'Itaú', 'tipo' => 'corrente', 'instituicao' => 'Itaú', 'cor' => '#EC7000', 'saldo_inicial' => 1850, 'data_inicial' => $inicio, 'ordem' => 2]);
        $carteira = $conta(['nome' => 'Carteira', 'tipo' => 'carteira', 'cor' => '#17211D', 'saldo_inicial' => 150, 'data_inicial' => $inicio, 'ordem' => 3]);
        $cnu = $conta(['nome' => 'Cartão Nubank', 'tipo' => 'cartao', 'cor' => '#5B4A8B', 'limite' => 8000, 'dia_fechamento' => 3, 'dia_vencimento' => 10, 'conta_pagamento_id' => $nubank['id']]);
        $cit = $conta(['nome' => 'Itaú Visa', 'tipo' => 'cartao', 'cor' => '#005CA9', 'limite' => 5000, 'dia_fechamento' => 25, 'dia_vencimento' => 5, 'conta_pagamento_id' => $itau['id']]);

        $hoje = date('Y-m-d');
        $fila = [];       // lançamentos a inserir (em lote, no fim)
        $faturas = [];    // total de cada fatura: "cartao|AAAA-MM-01" => valor
        $lanc = function (string $tipo, array $c, ?int $categoria, string $descricao, float $valor, string $data, array $extra = []) use ($hoje, &$fila, &$faturas) {
            $d = [
                'tipo' => $tipo, 'conta_id' => $c['id'], 'conta_destino_id' => $extra['destino'] ?? null, 'categoria_id' => $categoria,
                'descricao' => $descricao, 'valor' => round($valor, 2), 'data' => $data,
                'fatura' => $extra['fatura'] ?? ($c['tipo'] === 'cartao' ? fatura_da_compra($data, $c) : null),
                'pago' => $c['tipo'] === 'cartao' ? 1 : ($data <= $hoje ? 1 : 0),
                'parcela' => $extra['parcela'] ?? null, 'parcelas' => $extra['parcelas'] ?? null, 'grupo' => $extra['grupo'] ?? null,
                'teste' => 1,
            ];
            if ($c['tipo'] === 'cartao' && $tipo !== 'transferencia') {
                $k = "{$c['id']}|{$d['fatura']}";
                $faturas[$k] = ($faturas[$k] ?? 0) + ($tipo === 'despesa' ? $d['valor'] : -$d['valor']);
            }
            $fila[] = $d;
        };
        $rnd = fn(float $a, float $b) => round($a + (mt_rand() / mt_getrandmax()) * ($b - $a), 2);
        $dia = fn(string $mes, int $d) => dia_do_mes($mes, $d);

        // Compra parcelada: notebook em 10x no cartão, há 3 meses
        $dataNote = $dia(substr(somar_meses(date('Y-m-01'), -3), 0, 7), 4);
        $grupo = bin2hex(random_bytes(8));
        $fat0 = fatura_da_compra($dataNote, $cnu);
        for ($p = 0; $p < 10; $p++) {
            $lanc('despesa', $cnu, $cat('Compras'), 'Notebook', $p === 0 ? 500.08 : 499.99, somar_meses($dataNote, $p),
                ['fatura' => substr(somar_meses($fat0, $p), 0, 7) . '-01', 'parcela' => $p + 1, 'parcelas' => 10, 'grupo' => $grupo]);
        }

        $mesAtual = date('Y-m');
        for ($i = -5; $i <= 0; $i++) {
            $mes = substr(somar_meses(date('Y-m-01'), $i), 0, 7);
            $ultimoDia = $mes === $mesAtual ? (int) date('t') : 31;

            // Receitas
            $lanc('receita', $nubank, $cat('Salário', 'receita'), 'Salário', 9800, $dia($mes, 5));
            $lanc('receita', $itau, $cat('Rendimentos', 'receita'), 'Rendimento CDB', $rnd(38, 62), $dia($mes, 15));
            if ($i === -3) $lanc('receita', $nubank, $cat('Outras receitas', 'receita'), 'Freela: site de restaurante', 2500, $dia($mes, 22));

            // Fixas
            $lanc('despesa', $nubank, $cat('Moradia'), 'Aluguel', 2400, $dia($mes, 10));
            $lanc('despesa', $nubank, $cat('Moradia'), 'Condomínio', 780, $dia($mes, 10));
            $lanc('despesa', $nubank, $cat('Moradia'), 'Luz', $rnd(150, 230), $dia($mes, 18));
            $lanc('despesa', $itau, $cat('Assinaturas'), 'Internet', 129.90, $dia($mes, 15));
            $lanc('despesa', $itau, $cat('Saúde'), 'Academia', 119.90, $dia($mes, 7));
            $lanc('despesa', $cnu, $cat('Assinaturas'), 'Netflix', 55.90, $dia($mes, 12));
            $lanc('despesa', $cnu, $cat('Assinaturas'), 'Spotify', 21.90, $dia($mes, 20));

            // Variáveis
            foreach ([2, 9, 16, 24] as $d) $lanc('despesa', $cnu, $cat('Mercado'), 'Supermercado', $rnd(140, 460), $dia($mes, $d));
            foreach ([6, 20] as $d) $lanc('despesa', $carteira, $cat('Mercado'), 'Feira', $rnd(55, 110), $dia($mes, $d));
            for ($k = 0; $k < 6; $k++) $lanc('despesa', $cnu, $cat('Transporte'), 'Uber', $rnd(16, 48), $dia($mes, 3 + $k * 4));
            $lanc('despesa', $cit, $cat('Transporte'), 'Combustível', $rnd(220, 290), $dia($mes, 13));
            foreach ([4, 11, 19, 26] as $d) $lanc('despesa', $cnu, $cat('Alimentação fora'), 'iFood', $rnd(42, 95), $dia($mes, $d));
            foreach ([8, 21] as $d) $lanc('despesa', $cit, $cat('Alimentação fora'), 'Restaurante', $rnd(80, 190), $dia($mes, $d));
            $lanc('despesa', $cit, $cat('Lazer'), mt_rand(0, 1) ? 'Cinema' : 'Show', $rnd(60, 180), $dia($mes, 14));
            $lanc('despesa', $carteira, $cat('Lazer'), 'Bar com amigos', $rnd(70, 140), $dia($mes, 27));
            $lanc('despesa', $cnu, $cat('Saúde'), 'Farmácia', $rnd(35, 120), $dia($mes, 17));
            if ($i === -2) $lanc('despesa', $cit, $cat('Compras'), 'Tênis de corrida', 649.90, $dia($mes, 23));
            if ($i === -1) $lanc('despesa', $nubank, $cat('Impostos e taxas'), 'IPVA (parcela)', 412.30, $dia($mes, 25));

            // Guardar dinheiro: Nubank → Itaú (reserva)
            $lanc('transferencia', $nubank, null, 'Reserva de emergência', 1000, $dia($mes, 6), ['destino' => $itau['id']]);
            // Saque para a carteira
            $lanc('transferencia', $nubank, null, 'Saque', 350, $dia($mes, 6), ['destino' => $carteira['id']]);

            // Faturas que vencem neste mês: pagas no vencimento (se já venceu)
            foreach ([$cnu, $cit] as $c) {
                $fatura = "$mes-01";
                $venc = datas_fatura($fatura, $c)['vencimento'];
                $total = round($faturas["{$c['id']}|$fatura"] ?? 0, 2);
                if ($total > 0 && $venc <= $hoje) {
                    $pag = $c['conta_pagamento_id'] === $nubank['id'] ? $nubank : $itau;
                    $lanc('transferencia', $pag, null, "Fatura {$c['nome']}", $total, $venc, ['destino' => $c['id'], 'fatura' => $fatura]);
                }
            }
        }

        inserir_em_lote($pdo, 'lancamentos', $fila);

        // Orçamento do mês atual e do anterior
        foreach ([0, -1] as $i) {
            $mes = substr(somar_meses(date('Y-m-01'), $i), 0, 7);
            $orc = [];
            foreach (['Mercado' => 1500, 'Alimentação fora' => 600, 'Transporte' => 500, 'Lazer' => 350, 'Assinaturas' => 250, 'Saúde' => 300] as $nome => $v) {
                $orc[] = ['mes' => $mes, 'categoria_id' => $cat($nome), 'valor' => $v, 'teste' => 1];
            }
            inserir_em_lote($pdo, 'orcamentos', $orc);
        }

        // Metas e aportes
        $meta = function (string $nome, float $alvo, ?string $prazo, string $cor, float $aporte) use ($pdo, $rnd) {
            executar($pdo, 'INSERT INTO metas (nome, valor_alvo, prazo, cor, teste) VALUES (?, ?, ?, ?, 1)', [$nome, $alvo, $prazo, $cor]);
            $id = (int) $pdo->lastInsertId();
            $ap = [];
            for ($i = -5; $i <= 0; $i++) $ap[] = ['meta_id' => $id, 'data' => dia_do_mes(substr(somar_meses(date('Y-m-01'), $i), 0, 7), 6), 'valor' => $aporte];
            inserir_em_lote($pdo, 'aportes', $ap);
            return $id;
        };
        $reserva = $meta('Reserva de emergência', 30000, null, '#2C5E4A', 1000);
        $viagem = $meta('Viagem ao Chile', 12000, substr(somar_meses(date('Y-m-01'), 8), 0, 10), '#D2773C', 800);

        // Investimentos e posições mensais
        $inv = function (string $nome, string $classe, string $inst, ?int $meta, float $base, float $aporte, float $taxa) use ($pdo) {
            executar($pdo, 'INSERT INTO investimentos (nome, classe, instituicao, meta_id, liquidez, teste) VALUES (?, ?, ?, ?, ?, 1)',
                [$nome, $classe, $inst, $meta, $classe === 'acoes' || $classe === 'fiis' ? 'D+2' : 'diária']);
            $id = (int) $pdo->lastInsertId();
            $aplicado = $base; $atual = $base; $pos = [];
            for ($i = -5; $i <= 0; $i++) {
                $aplicado += $aporte; $atual = ($atual + $aporte) * (1 + $taxa);
                $pos[] = ['investimento_id' => $id, 'data' => date('Y-m-t', strtotime(somar_meses(date('Y-m-01'), $i))),
                          'valor_aplicado' => round($aplicado, 2), 'valor_atual' => round($atual, 2)];
            }
            inserir_em_lote($pdo, 'posicoes', $pos);
        };
        $inv('Tesouro Selic 2029', 'tesouro', 'Itaú', $reserva, 9000, 1000, 0.0085);
        $inv('CDB Inter 110% CDI', 'renda_fixa', 'Inter', $viagem, 2500, 800, 0.0092);
        $inv('FII HGLG11', 'fiis', 'XP', null, 3200, 0, 0.004);
        $inv('Ações ITSA4', 'acoes', 'XP', null, 1800, 0, 0.011);

        $pdo->commit();
        return ['lancamentos' => count($fila), 'contas' => 5, 'metas' => 2, 'investimentos' => 4];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
