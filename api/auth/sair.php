<?php
/** POST /api/auth/sair.php → encerra a sessão. */
require __DIR__ . '/../lib/resposta.php';
require __DIR__ . '/../lib/sessao.php';
$config = require __DIR__ . '/../lib/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_SERVER['HTTP_X_FIN'] ?? '') !== '1') {
    responder_erro('Requisição recusada.', null, 403);
}
try { iniciar_sessao(); } catch (Throwable $e) { responder_json(['ok' => true]); }
$_SESSION = [];
$p = session_get_cookie_params();
setcookie(session_name(), '', ['expires' => time() - 3600] + array_intersect_key($p, array_flip(['path', 'secure', 'httponly', 'samesite'])));
session_destroy();
responder_json(['ok' => true]);
