<?php
/** GET /api/auth/entrar.php → manda para a tela de login do Google. */
require __DIR__ . '/../lib/resposta.php';
require __DIR__ . '/../lib/sessao.php';
$config = require __DIR__ . '/../lib/config.php';

$login = config_login($config);
if (!$login['ativo']) {
    header('Location: /?erro=login-nao-configurado', true, 302);
    exit;
}
// O Google só aceita "localhost" (não 127.0.0.1) como endereço local cadastrado
if (in_array(strtolower($_SERVER['HTTP_HOST'] ?? ''), ['127.0.0.1:8788', '[::1]:8788'], true)) {
    header('Location: http://localhost:8788/api/auth/entrar.php', true, 302);
    exit;
}
$base = endereco_base($config);
if (!$base) {
    http_response_code(400);
    exit('Endereço não autorizado. Inclua-o em "enderecos" no api/lib/config.php.');
}

try {
    iniciar_sessao();
} catch (Throwable $e) {
    error_log('[fin] ' . $e->getMessage());
    header('Location: /?erro=sessao', true, 302);
    exit;
}
$verificador = base64url(random_bytes(48));
$_SESSION['oauth'] = [
    'state' => base64url(random_bytes(24)),
    'nonce' => base64url(random_bytes(24)),
    'verificador' => $verificador,
    'criado' => time(),
];
$params = [
    'client_id' => $login['client_id'],
    'redirect_uri' => "$base/api/auth/retorno.php",
    'response_type' => 'code',
    'scope' => 'openid email profile',
    'state' => $_SESSION['oauth']['state'],
    'nonce' => $_SESSION['oauth']['nonce'],
    'code_challenge' => base64url(hash('sha256', $verificador, true)),
    'code_challenge_method' => 'S256',
    'prompt' => 'select_account',
];
if (count($login['emails']) === 1) $params['login_hint'] = $login['emails'][0];
header('Cache-Control: no-store');
header('Location: ' . GOOGLE_AUTORIZAR . '?' . http_build_query($params), true, 302);
