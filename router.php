<?php
/**
 * Roteador do servidor embutido do PHP (uso local):  php -S 127.0.0.1:8788 router.php
 * Reproduz as proteções do .htaccess: bloqueia api/lib, scripts, db e arquivos ocultos.
 */
$caminho = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
if (preg_match('#(^|/)\.|^/(api/lib|scripts|db)(/|$)|^/(router\.php|README\.md)$#', $caminho)) {
    http_response_code(403);
    exit('Acesso negado.');
}
if ($caminho === '/' || $caminho === '') return false;
if (is_file(__DIR__ . $caminho)) return false;
http_response_code(404);
echo 'Não encontrado.';
