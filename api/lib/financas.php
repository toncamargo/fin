<?php
/**
 * Regras de negócio do Fin.
 *
 * Convenções:
 *  - valores sempre positivos; o sentido vem do tipo (despesa, receita, transferencia)
 *  - cartão de crédito: cada compra entra numa fatura, identificada pelo 1º dia do mês do VENCIMENTO
 *  - "mês" de um lançamento: em conta comum, o mês da data; em cartão, o mês da fatura
 *  - pagar a fatura é uma transferência da conta de pagamento para o cartão (não conta como despesa de novo)
 */

// ---------------------------------------------------------------------------
// Validação de entrada
// ---------------------------------------------------------------------------

function texto(array $e, string $campo, int $max, bool $obrigatorio = false): ?string
{
    $v = trim((string) ($e[$campo] ?? ''));
    if ($v === '') {
        if ($obrigatorio) throw new ErroValidacao('Preencha: ' . rotulo($campo) . '.');
        return null;
    }
    if (mb_strlen($v) > $max) throw new ErroValidacao(rotulo($campo) . " passa de $max caracteres.");
    return $v;
}

/** "1.234,56", "1234.56", "R$ 52,30", 52.3 → 1234.56 */
function dinheiro($v, string $campo = 'valor', bool $obrigatorio = true): ?float
{
    if ($v === null || $v === '') {
        if ($obrigatorio) throw new ErroValidacao('Informe ' . rotulo($campo) . '.');
        return null;
    }
    if (is_int($v) || is_float($v)) {
        $n = (float) $v;
    } else {
        $s = preg_replace('/[^\d,.\-]/', '', (string) $v);
        if (str_contains($s, ',')) {
            $s = str_replace(['.', ','], ['', '.'], $s);          // 1.234,56 → 1234.56
        } elseif (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $s)) {
            $s = str_replace('.', '', $s);                       // 2.400 → 2400 (ponto de milhar)
        }
        if (!is_numeric($s)) throw new ErroValidacao(rotulo($campo) . ' inválido.');
        $n = (float) $s;
    }
    if (abs($n) > 999_999_999) throw new ErroValidacao(rotulo($campo) . ' grande demais.');
    return round($n, 2);
}

function data_ok($v, string $campo = 'data', bool $obrigatorio = true): ?string
{
    $v = trim((string) ($v ?? ''));
    if ($v === '') {
        if ($obrigatorio) throw new ErroValidacao('Informe ' . rotulo($campo) . '.');
        return null;
    }
    if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $v, $m)) $v = "$m[3]-$m[2]-$m[1]";
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
    if (!$d || $d->format('Y-m-d') !== $v) throw new ErroValidacao(rotulo($campo) . ' inválida.');
    return $v;
}

function mes_ok($v): string
{
    $v = (string) ($v ?? '');
    if ($v === '') return date('Y-m');
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $v)) throw new ErroValidacao('Mês inválido.');
    return $v;
}

function opcao_ok($v, array $permitidas, string $campo, ?string $padrao = null): ?string
{
    if ($v === null || $v === '') return $padrao;
    if (!in_array($v, $permitidas, true)) throw new ErroValidacao(rotulo($campo) . ' inválido.');
    return $v;
}

function id_ok($v, string $campo, bool $obrigatorio = true): ?int
{
    if ($v === null || $v === '') {
        if ($obrigatorio) throw new ErroValidacao('Escolha ' . rotulo($campo) . '.');
        return null;
    }
    if (!ctype_digit((string) $v)) throw new ErroValidacao(rotulo($campo) . ' inválido.');
    return (int) $v;
}

function rotulo(string $campo): string
{
    return [
        'valor' => 'o valor', 'data' => 'a data', 'descricao' => 'a descrição', 'conta_id' => 'a conta',
        'conta_destino_id' => 'a conta de destino', 'categoria_id' => 'a categoria', 'nome' => 'o nome',
        'saldo_inicial' => 'o saldo inicial', 'data_inicial' => 'a data do saldo', 'limite' => 'o limite',
        'valor_alvo' => 'o valor-alvo', 'prazo' => 'o prazo', 'parcelas' => 'o número de parcelas',
    ][$campo] ?? $campo;
}

// ---------------------------------------------------------------------------
// Modo de dados: reais ou de teste (cabeçalho X-Fin-Teste: 1, enviado pelo site)
// ---------------------------------------------------------------------------

function modo_teste(): int
{
    return (($_SERVER['HTTP_X_FIN_TESTE'] ?? '') === '1' || ($_GET['teste'] ?? '') === '1') ? 1 : 0;
}

// ---------------------------------------------------------------------------
// Datas e cartão
// ---------------------------------------------------------------------------

function inicio_mes(string $mes): string { return "$mes-01"; }
function fim_mes(string $mes): string { return (new DateTimeImmutable("$mes-01"))->modify('last day of this month')->format('Y-m-d'); }
function somar_meses(string $data, int $n): string
{
    // Mantém o dia; se o mês não tiver esse dia (31 → fevereiro), usa o último dia do mês
    $d = new DateTimeImmutable($data);
    $alvo = $d->modify('first day of this month')->modify(sprintf('%+d months', $n));
    $dia = min((int) $d->format('d'), (int) $alvo->format('t'));
    return $alvo->setDate((int) $alvo->format('Y'), (int) $alvo->format('m'), $dia)->format('Y-m-d');
}

/** Dia N do mês (limitado ao último dia: dia 31 em abril vira 30). */
function dia_do_mes(string $mes, int $dia): string
{
    $d = new DateTimeImmutable("$mes-01");
    return $d->setDate((int) $d->format('Y'), (int) $d->format('m'), min($dia, (int) $d->format('t')))->format('Y-m-d');
}

/**
 * Fatura (1º dia do mês de vencimento) em que entra uma compra feita em $data.
 * Compra a partir do dia de fechamento vai para a fatura seguinte.
 */
function fatura_da_compra(string $data, array $cartao): string
{
    $fech = (int) $cartao['dia_fechamento'];
    $venc = (int) $cartao['dia_vencimento'];
    $mes = substr($data, 0, 7);
    $fecha_em = $data >= dia_do_mes($mes, $fech) ? substr(somar_meses("$mes-01", 1), 0, 7) : $mes;
    $vence_em = $venc > $fech ? $fecha_em : substr(somar_meses("$fecha_em-01", 1), 0, 7);
    return "$vence_em-01";
}

/** Datas de fechamento e vencimento de uma fatura. */
function datas_fatura(string $fatura, array $cartao): array
{
    $mes_venc = substr($fatura, 0, 7);
    $mes_fech = (int) $cartao['dia_vencimento'] > (int) $cartao['dia_fechamento'] ? $mes_venc : substr(somar_meses($fatura, -1), 0, 7);
    return ['fechamento' => dia_do_mes($mes_fech, (int) $cartao['dia_fechamento']), 'vencimento' => dia_do_mes($mes_venc, (int) $cartao['dia_vencimento'])];
}

// ---------------------------------------------------------------------------
// Cadastros auxiliares
// ---------------------------------------------------------------------------

/** Conta do modo atual (uma conta de teste não aparece nem é usada no modo de dados reais, e vice-versa). */
function conta(PDO $pdo, int $id): array
{
    return linha($pdo, 'SELECT * FROM contas WHERE id = ? AND teste = ?', [$id, modo_teste()])
        ?? throw new ErroValidacao('Conta não encontrada.');
}

/**
 * Filtro SQL do "mês" de um lançamento (data em conta comum, fatura em cartão), com os parâmetros.
 * Usa os aliases l (lançamento) e c (conta).
 */
function sql_no_mes(string $mes): array
{
    return ["((c.tipo <> 'cartao' AND l.data BETWEEN :m_ini AND :m_fim) OR (c.tipo = 'cartao' AND l.fatura = :m_fat)) AND c.teste = :m_teste",
        ['m_ini' => inicio_mes($mes), 'm_fim' => fim_mes($mes), 'm_fat' => inicio_mes($mes), 'm_teste' => modo_teste()]];
}

/** Saldo atual de uma conta comum (só o que está pago, a partir da data do saldo inicial). */
function saldos_contas(PDO $pdo, ?string $ate = null): array
{
    $ate ??= date('Y-m-d');
    $rows = linhas($pdo, "
        SELECT c.id,
          c.saldo_inicial
          + COALESCE(SUM(CASE WHEN l.tipo = 'receita' AND l.conta_id = c.id THEN l.valor END), 0)
          - COALESCE(SUM(CASE WHEN l.tipo = 'despesa' AND l.conta_id = c.id THEN l.valor END), 0)
          - COALESCE(SUM(CASE WHEN l.tipo = 'transferencia' AND l.conta_id = c.id THEN l.valor END), 0)
          + COALESCE(SUM(CASE WHEN l.tipo = 'transferencia' AND l.conta_destino_id = c.id THEN l.valor END), 0) AS saldo
        FROM contas c
        LEFT JOIN lancamentos l ON (l.conta_id = c.id OR l.conta_destino_id = c.id) AND l.pago = 1
             AND l.data <= :ate AND (c.data_inicial IS NULL OR l.data >= c.data_inicial)
        WHERE c.tipo <> 'cartao' AND c.teste = :teste
        GROUP BY c.id", ['ate' => $ate, 'teste' => modo_teste()]);
    return array_column($rows, 'saldo', 'id');
}

/** Total de uma fatura: compras − estornos; e quanto já foi pago (transferências para o cartão). */
function totais_fatura(PDO $pdo, int $cartao_id, string $fatura): array
{
    $r = linha($pdo, "
        SELECT
          COALESCE(SUM(CASE WHEN tipo = 'despesa' AND conta_id = :c THEN valor END), 0)
          - COALESCE(SUM(CASE WHEN tipo = 'receita' AND conta_id = :c2 THEN valor END), 0) AS total,
          COALESCE(SUM(CASE WHEN tipo = 'transferencia' AND conta_destino_id = :c3 THEN valor END), 0) AS pago
        FROM lancamentos WHERE fatura = :f AND (conta_id = :c4 OR conta_destino_id = :c5)",
        ['c' => $cartao_id, 'c2' => $cartao_id, 'c3' => $cartao_id, 'c4' => $cartao_id, 'c5' => $cartao_id, 'f' => $fatura]);
    return ['total' => round((float) $r['total'], 2), 'pago' => round((float) $r['pago'], 2)];
}
