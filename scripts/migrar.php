<?php
/**
 * Aplica as migrações pendentes de db/migracoes/ (em ordem) e registra cada uma na tabela "migracoes".
 *   php scripts/migrar.php            aplica o que falta
 *   php scripts/migrar.php --status   só mostra o que já foi e o que falta
 *   php scripts/migrar.php --testar   só testa a conexão
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/../api/lib/resposta.php';
require __DIR__ . '/../api/lib/banco.php';
$config = require __DIR__ . '/../api/lib/config.php';
$opcao = $argv[1] ?? '';

try {
    $pdo = conectar($config);
} catch (Throwable $e) {
    fwrite(STDERR, "❌ Não conectou ao banco: {$e->getMessage()}\n");
    exit(1);
}
$versao = $pdo->query('SELECT VERSION()')->fetchColumn();
$banco = $pdo->query('SELECT DATABASE()')->fetchColumn();
echo "Conectado a \"$banco\" ($versao)\n";
if ($opcao === '--testar') exit(0);

$pdo->exec('CREATE TABLE IF NOT EXISTS migracoes (arquivo VARCHAR(120) PRIMARY KEY, aplicada DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)
            ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
$feitas = array_column($pdo->query('SELECT arquivo FROM migracoes')->fetchAll(PDO::FETCH_ASSOC), 'arquivo');
$arquivos = glob(__DIR__ . '/../db/migracoes/*.sql');
sort($arquivos);

$pendentes = 0;
foreach ($arquivos as $caminho) {
    $nome = basename($caminho);
    if (in_array($nome, $feitas, true)) { echo "  ✓ $nome\n"; continue; }
    $pendentes++;
    if ($opcao === '--status') { echo "  · $nome (pendente)\n"; continue; }

    // Comentários de linha saem; comandos são separados por ";" no fim da linha
    $sql = preg_replace('/^\s*--.*$/m', '', file_get_contents($caminho));
    $comandos = array_filter(array_map('trim', preg_split('/;\s*$/m', $sql)));
    try {
        foreach ($comandos as $cmd) $pdo->exec($cmd);
        $pdo->prepare('INSERT INTO migracoes (arquivo) VALUES (?)')->execute([$nome]);
        echo "  ✅ $nome\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "  ❌ $nome: {$e->getMessage()}\n");
        exit(1);
    }
}
echo $pendentes ? ($opcao === '--status' ? "$pendentes pendente(s).\n" : "Pronto.\n") : "Nada a fazer: o banco está em dia.\n";
