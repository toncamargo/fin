<?php
date_default_timezone_set('America/Sao_Paulo');

/**
 * Configuração do Fin.
 * Credenciais NUNCA ficam aqui: vêm de um arquivo .env fora do git (e, online, fora da pasta pública).
 */
return [
    // Endereços autorizados. O login com Google só funciona neles; cada um precisa estar cadastrado
    // no Google Cloud com o retorno <endereço>/api/auth/retorno.php
    'enderecos' => [
        'http://localhost:8788',
        'https://fin.toncamargo.com.br',
    ],

    // .env com MYSQL_* e GOOGLE_* (o primeiro que existir é usado)
    'env' => array_values(array_filter([
        getenv('FIN_ENV') ?: null,
        __DIR__ . '/../../../.env-fin',   // online: um nível acima da pasta pública
        __DIR__ . '/../../.env',          // local: ~/Ton/fin/.env
    ])),
];
