<?php
/** Conexão com o MySQL/MariaDB do Fin. */

function ler_env(array $caminhos): ?array
{
    foreach ($caminhos as $c) {
        if (!$c || !is_readable($c)) continue;
        $vals = [];
        foreach (file($c, FILE_IGNORE_NEW_LINES) as $linha) {
            $linha = trim($linha);
            if ($linha === '' || $linha[0] === '#' || !str_contains($linha, '=')) continue;
            [$k, $v] = explode('=', $linha, 2);
            $vals[trim($k)] = trim(trim($v), "\"'");
        }
        return $vals;
    }
    return null;
}

function conectar(array $config): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $env = ler_env($config['env']);
    if (!$env || empty($env['MYSQL_HOST'])) {
        throw new RuntimeException('Arquivo .env com as credenciais do banco não encontrado.');
    }
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $env['MYSQL_HOST'], (int) ($env['MYSQL_PORT'] ?? 3306), $env['MYSQL_DATABASE'] ?? '');
    $pdo = new PDO($dsn, $env['MYSQL_USER'] ?? '', $env['MYSQL_PASSWORD'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
        PDO::ATTR_TIMEOUT => 10,
    ]);
    $pdo->exec("SET time_zone = '-03:00'");
    return $pdo;
}

function linhas(PDO $pdo, string $sql, array $args = []): array
{
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

function linha(PDO $pdo, string $sql, array $args = []): ?array
{
    return linhas($pdo, $sql, $args)[0] ?? null;
}

function executar(PDO $pdo, string $sql, array $args = []): int
{
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return $st->rowCount();
}
