<?php
/** Conexão com o MySQL/MariaDB do Fin. */

function ler_env(array $caminhos): ?array
{
    foreach ($caminhos as $c) {
        if (!$c || !is_readable($c)) continue;
        $vals = [];
        foreach (file($c, FILE_IGNORE_NEW_LINES) as $linha) {
            $linha = trim(preg_replace('/^\s*export\s+/', '', $linha));   // aceita "export CHAVE=valor"
            if ($linha === '' || $linha[0] === '#' || !str_contains($linha, '=')) continue;
            [$k, $v] = explode('=', $linha, 2);
            $vals[strtoupper(trim($k))] = trim(trim($v), "\"'");
        }
        $vals['__ARQUIVO__'] = $c;
        return $vals;
    }
    return null;
}

/** Primeiro valor preenchido entre os nomes aceitos (MYSQL_HOST, DB_HOST...). */
function env_valor(array $env, array $nomes): ?string
{
    foreach ($nomes as $n) if (isset($env[$n]) && $env[$n] !== '') return $env[$n];
    return null;
}

/** Credenciais do banco, aceitando os nomes mais comuns. Lança erro explicando o que falta (só nomes, nunca valores). */
function credenciais_banco(array $config): array
{
    $env = ler_env($config['env']);
    if (!$env) throw new ErroConfiguracao('Não encontrei o arquivo .env (em ~/Ton/fin/.env).');
    $c = [
        'host' => env_valor($env, ['MYSQL_HOST', 'DB_HOST', 'DATABASE_HOST', 'HOST']),
        'porta' => (int) (env_valor($env, ['MYSQL_PORT', 'DB_PORT', 'DATABASE_PORT', 'PORT']) ?? 3306),
        'banco' => env_valor($env, ['MYSQL_DATABASE', 'MYSQL_DB', 'DB_DATABASE', 'DB_NAME', 'DATABASE_NAME', 'DATABASE']),
        'usuario' => env_valor($env, ['MYSQL_USER', 'MYSQL_USERNAME', 'DB_USER', 'DB_USERNAME', 'DATABASE_USER', 'USER']),
        'senha' => env_valor($env, ['MYSQL_PASSWORD', 'MYSQL_PASS', 'DB_PASSWORD', 'DB_PASS', 'DATABASE_PASSWORD', 'PASSWORD']) ?? '',
    ];
    $faltam = array_keys(array_filter(['MYSQL_HOST' => $c['host'], 'MYSQL_DATABASE' => $c['banco'], 'MYSQL_USER' => $c['usuario']], fn($v) => !$v));
    if ($faltam) {
        $tem = array_values(array_filter(array_keys($env), fn($k) => $k !== '__ARQUIVO__'));
        throw new ErroConfiguracao('No .env faltam ' . implode(', ', $faltam) . '. Chaves encontradas: '
            . ($tem ? implode(', ', $tem) : 'nenhuma') . '. Veja o modelo em .env.example.');
    }
    return $c;
}

/** Problema de configuração: a mensagem pode ir para a tela (não contém segredos). */
class ErroConfiguracao extends RuntimeException {}

function conectar(array $config): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $c = credenciais_banco($config);
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['porta'], $c['banco']);
    try {
        $pdo = new PDO($dsn, $c['usuario'], $c['senha'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_TIMEOUT => 10,
        ]);
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        $motivo = match (true) {
            str_contains($msg, 'Access denied') => 'usuário ou senha recusados (na Hostinger, confira também se o seu IP está liberado em "MySQL remoto")',
            str_contains($msg, 'Unknown database') => 'o banco informado não existe',
            str_contains($msg, 'getaddrinfo') || str_contains($msg, 'Name or service') || str_contains($msg, 'nodename') => 'o endereço (host) não foi encontrado',
            str_contains($msg, 'timed out') || str_contains($msg, 'Connection refused') => 'o servidor não respondeu (host/porta errados ou acesso remoto bloqueado)',
            default => 'erro ao conectar',
        };
        throw new ErroConfiguracao("Não conectei ao banco {$c['banco']} em {$c['host']}: $motivo.");
    }
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
