<?php
/**
 * Dados de teste pelo Terminal (o mesmo que o botão do site faz):
 *   php scripts/dados-teste.php           cria (ou recria) seis meses de exemplo, marcados com teste = 1
 *   php scripts/dados-teste.php --limpar  apaga só os dados de teste
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../api/lib/resposta.php';
require __DIR__ . '/../api/lib/banco.php';
require __DIR__ . '/../api/lib/financas.php';
require __DIR__ . '/../api/lib/dados_teste.php';

$pdo = conectar(require __DIR__ . '/../api/lib/config.php');
if (($argv[1] ?? '') === '--limpar') {
    $n = limpar_dados_teste($pdo);
    echo "🧹 Dados de teste apagados: " . json_encode($n, JSON_UNESCAPED_UNICODE) . "\n";
} else {
    $n = gerar_dados_teste($pdo);
    echo "✅ Dados de teste criados: {$n['lancamentos']} lançamentos, {$n['contas']} contas e cartões, {$n['metas']} metas, {$n['investimentos']} investimentos.\n";
    echo "   No site, marque \"Dados de teste\" no canto inferior esquerdo.\n";
}
