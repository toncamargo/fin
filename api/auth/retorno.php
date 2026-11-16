<?php
/** GET /api/auth/retorno.php — o Google volta para cá depois do login. */
require __DIR__ . '/../lib/resposta.php';
require __DIR__ . '/../lib/sessao.php';
$config = require __DIR__ . '/../lib/config.php';

function voltar(string $erro = ''): void
{
    header('Cache-Control: no-store');
    header('Location: /' . ($erro ? '?erro=' . rawurlencode($erro) : ''), true, 302);
    exit;
}

$login = config_login($config);
$base = endereco_base($config);
if (!$login['ativo'] || !$base) voltar('login-nao-configurado');

try {
    iniciar_sessao();
} catch (Throwable $e) {
    error_log('[fin] ' . $e->getMessage());
    voltar('sessao');
}
$oauth = $_SESSION['oauth'] ?? null;
unset($_SESSION['oauth']); // uso único

if (isset($_GET['error'])) voltar($_GET['error'] === 'access_denied' ? 'cancelado' : 'falha');
if (!$oauth || time() - $oauth['criado'] > 600 || !hash_equals($oauth['state'], (string) ($_GET['state'] ?? ''))) {
    error_log('[fin] login: ' . (!$oauth ? 'sessão sem o pedido de login (cookie não voltou ou sessão não foi gravada)' : 'state diferente ou pedido com mais de 10 min'));
    voltar('expirado');
}

try {
    $resp = post_google(GOOGLE_TOKEN, [
        'code' => (string) ($_GET['code'] ?? ''),
        'client_id' => $login['client_id'],
        'client_secret' => $login['client_secret'],
        'redirect_uri' => "$base/api/auth/retorno.php",
        'grant_type' => 'authorization_code',
        'code_verifier' => $oauth['verificador'],
    ]);
    if (empty($resp['id_token'])) throw new RuntimeException('Google não devolveu id_token: ' . ($resp['error'] ?? '?'));
    $c = ler_id_token($resp['id_token'], $login['client_id'], $oauth['nonce']);
} catch (Throwable $e) {
    error_log('[fin] login: ' . $e->getMessage());
    voltar('falha');
}

$email = strtolower($c['email']);
if (!in_array($email, $login['emails'], true)) {
    error_log("[fin] login recusado: $email");
    voltar('nao-autorizado');
}

session_regenerate_id(true);
$_SESSION['usuario'] = [
    'email' => $email,
    'nome' => (string) ($c['name'] ?? $email),
    'foto' => (string) ($c['picture'] ?? ''),
    'expira' => time() + SESSAO_DIAS * 86400,
];
voltar();
