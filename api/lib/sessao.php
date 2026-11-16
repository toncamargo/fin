<?php
/**
 * Login com Google (OpenID Connect, fluxo "authorization code" + PKCE, todo no servidor).
 *
 * Regras:
 *  - Só os e-mails listados em FIN_EMAILS (no .env) entram; os demais são recusados mesmo logando no Google.
 *  - A sessão vive num cookie HttpOnly (o JavaScript não lê), SameSite=Lax e Secure quando em HTTPS.
 *  - Sem GOOGLE_CLIENT_ID configurado, o site só funciona no próprio computador.
 *
 * Variáveis do .env:
 *   GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET   (Google Cloud → Credenciais → Cliente OAuth "Aplicativo da Web")
 *   FIN_EMAILS=seu.email@gmail.com          (vários: separados por vírgula)
 */

require_once __DIR__ . '/banco.php'; // ler_env()

const SESSAO_DIAS = 30;
const GOOGLE_AUTORIZAR = 'https://accounts.google.com/o/oauth2/v2/auth';
define('GOOGLE_TOKEN', getenv('FIN_GOOGLE_TOKEN_URL') ?: 'https://oauth2.googleapis.com/token'); // variável só para testes automatizados

function config_login(array $config): array
{
    static $c = null;
    if ($c === null) {
        $env = ler_env($config['env']) ?? [];
        $emails = array_filter(array_map(fn($e) => strtolower(trim($e)), explode(',', $env['FIN_EMAILS'] ?? '')));
        $c = [
            'client_id' => trim($env['GOOGLE_CLIENT_ID'] ?? ''),
            'client_secret' => trim($env['GOOGLE_CLIENT_SECRET'] ?? ''),
            'emails' => array_values($emails),
        ];
        $c['ativo'] = $c['client_id'] !== '' && $c['client_secret'] !== '' && $c['emails'];
    }
    return $c;
}

function em_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

/** Endereço base deste acesso, só se for um dos autorizados em config.php (evita cabeçalho Host forjado). */
function endereco_base(array $config): ?string
{
    $atual = (em_https() ? 'https://' : 'http://') . strtolower($_SERVER['HTTP_HOST'] ?? '');
    return in_array($atual, $config['enderecos'], true) ? $atual : null;
}

/**
 * Pasta das sessões: FORA da pasta pública, um nível acima do site
 * (local: ~/Ton/.fin-sessoes · Hostinger: ao lado do public_html).
 * Não depende da pasta temporária do PHP, que no Mac (Homebrew) pode não ter permissão de escrita.
 */
function pasta_sessoes(): string
{
    $pasta = getenv('FIN_SESSOES') ?: dirname(__DIR__, 3) . '/.fin-sessoes';
    if (!is_dir($pasta) && !@mkdir($pasta, 0700, true) && !is_dir($pasta)) {
        throw new RuntimeException("Não consegui criar a pasta de sessões: $pasta");
    }
    if (!is_writable($pasta)) throw new RuntimeException("Sem permissão para gravar sessões em: $pasta");
    return $pasta;
}

function iniciar_sessao(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_save_path(pasta_sessoes());
    ini_set('session.gc_maxlifetime', (string) (SESSAO_DIAS * 86400));
    ini_set('session.use_strict_mode', '1');
    session_name(em_https() ? '__Host-fin' : 'fin');
    session_set_cookie_params([
        'lifetime' => SESSAO_DIAS * 86400, 'path' => '/', 'secure' => em_https(), 'httponly' => true, 'samesite' => 'Lax',
    ]);
    session_start();
}

/**
 * Usuário logado (['email', 'nome', 'foto']) ou null.
 * Sem login configurado, o acesso pelo próprio computador vale como usuário "local".
 */
function usuario_atual(array $config): ?array
{
    if (!config_login($config)['ativo']) {
        return acesso_local() ? ['email' => 'local', 'nome' => 'Este computador', 'foto' => null, 'local' => true] : null;
    }
    iniciar_sessao();
    $u = $_SESSION['usuario'] ?? null;
    if (!$u || ($u['expira'] ?? 0) < time() || !in_array($u['email'], config_login($config)['emails'], true)) {
        return null;
    }
    return $u;
}

/** Para os endpoints da API: sem login, responde 401 e encerra. Libera a sessão logo (não trava pedidos paralelos). */
function exigir_login(array $config): array
{
    try {
        $u = usuario_atual($config);
    } catch (Throwable $e) {
        responder_erro('Não consegui abrir a sessão de login no servidor.', $e, 500);
    }
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    if (!$u) {
        responder_json(['erro' => 'Entre com sua conta Google para continuar.', 'login' => true], 401);
    }
    return $u;
}

function base64url(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

/** POST de formulário para o Google (curl, ou stream se o curl não existir). */
function post_google(string $url, array $campos): array
{
    $corpo = http_build_query($campos);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $corpo, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        ]);
        $resp = curl_exec($ch);
        $erro = curl_error($ch);
        curl_close($ch);
        if ($resp === false) throw new RuntimeException("Falha ao falar com o Google: $erro");
    } else {
        $resp = @file_get_contents($url, false, stream_context_create(['http' => [
            'method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
            'content' => $corpo, 'timeout' => 15, 'ignore_errors' => true,
        ]]));
        if ($resp === false) throw new RuntimeException('Falha ao falar com o Google.');
    }
    $j = json_decode($resp, true);
    if (!is_array($j)) throw new RuntimeException('Resposta inválida do Google.');
    return $j;
}

/**
 * Lê as informações do id_token. Ele chega direto do Google, por HTTPS, em troca do código + segredo;
 * nesse caso o OpenID Connect (seção 3.1.3.7) dispensa validar a assinatura. Os demais campos são conferidos.
 */
function ler_id_token(string $jwt, string $client_id, string $nonce): array
{
    $partes = explode('.', $jwt);
    if (count($partes) !== 3) throw new RuntimeException('id_token malformado.');
    $c = json_decode(base64_decode(strtr($partes[1], '-_', '+/')), true);
    if (!is_array($c)) throw new RuntimeException('id_token ilegível.');
    if (!in_array($c['iss'] ?? '', ['https://accounts.google.com', 'accounts.google.com'], true)) throw new RuntimeException('Emissor inválido.');
    $aud = (array) ($c['aud'] ?? []);
    if (!in_array($client_id, $aud, true)) throw new RuntimeException('Token emitido para outro aplicativo.');
    if (($c['exp'] ?? 0) < time() - 60) throw new RuntimeException('Token expirado.');
    if (!hash_equals($nonce, (string) ($c['nonce'] ?? ''))) throw new RuntimeException('Nonce inválido.');
    if (empty($c['email']) || empty($c['email_verified'])) throw new RuntimeException('E-mail não verificado no Google.');
    return $c;
}
