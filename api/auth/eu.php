<?php
/** GET /api/auth/eu.php → quem está logado (ou 401). */
require __DIR__ . '/../lib/resposta.php';
require __DIR__ . '/../lib/sessao.php';
$config = require __DIR__ . '/../lib/config.php';

$u = exigir_login($config);
responder_json(['email' => $u['email'], 'nome' => $u['nome'], 'foto' => $u['foto'] ?: null, 'local' => !empty($u['local'])]);
