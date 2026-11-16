<?php
/**
 * Dados de teste (só mexe no que tem teste = 1).
 * POST { acao: "gerar" }  → apaga os de teste anteriores e cria seis meses de exemplo
 * POST { acao: "limpar" } → apaga só os de teste
 */
require __DIR__ . '/lib/resposta.php';
require __DIR__ . '/lib/financas.php';
require __DIR__ . '/lib/dados_teste.php';

endpoint(function (PDO $pdo) {
    $e = entrada_escrita();
    return match ($e['acao'] ?? '') {
        'gerar' => gerar_dados_teste($pdo),
        'limpar' => limpar_dados_teste($pdo),
        default => throw new ErroValidacao('Ação desconhecida.'),
    };
});
