<?php
/** Respostas JSON, leitura da entrada e erros sem vazar detalhes internos. */

ini_set('display_errors', '0');
ini_set('log_errors', '1');

function responder_json($dados, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow');
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function acesso_local(): bool
{
    return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
}

/** Erro de validação: a mensagem vai para o usuário como está. */
class ErroValidacao extends RuntimeException {}

/** Erro para o navegador: mensagem genérica online; detalhe técnico só no próprio computador. */
function responder_erro(string $mensagem, ?Throwable $e = null, int $status = 500): void
{
    if ($e) error_log('[fin] ' . get_class($e) . ': ' . $e->getMessage());
    $saida = ['erro' => $mensagem];
    if ($e && acesso_local()) $saida['detalhe'] = $e->getMessage();
    responder_json($saida, $status);
}

/** Escritas: só POST, só JSON e com o cabeçalho X-Fin (formulários de outros sites não conseguem enviar). */
function entrada_escrita(): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') responder_erro('Use POST.', null, 405);
    if (($_SERVER['HTTP_X_FIN'] ?? '') !== '1') responder_erro('Requisição recusada.', null, 403);
    if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
        responder_erro('Envie JSON.', null, 415);
    }
    $corpo = file_get_contents('php://input', false, null, 0, 2_000_000);
    $dados = json_decode($corpo ?: '', true);
    if (!is_array($dados)) responder_erro('JSON inválido.', null, 400);
    return $dados;
}

/** Envolve um endpoint: login, conexão e tratamento de erros num lugar só. */
function endpoint(callable $acao): void
{
    $config = require __DIR__ . '/config.php';
    require_once __DIR__ . '/banco.php';
    require_once __DIR__ . '/sessao.php';
    exigir_login($config);
    try {
        $pdo = conectar($config);
        responder_json($acao($pdo, $config));
    } catch (ErroValidacao $e) {
        responder_json(['erro' => $e->getMessage()], 422);
    } catch (ErroConfiguracao $e) {
        responder_erro($e->getMessage(), $e, 503);
    } catch (PDOException $e) {
        if ($e->getCode() === '42S02') responder_erro('Falta preparar o banco: rode "php scripts/migrar.php".', $e, 409);
        if ($e->getCode() === '23000') responder_erro('Esse registro conflita com outro (duplicado ou em uso).', $e, 409);
        responder_erro('Não foi possível falar com o banco agora.', $e);
    } catch (Throwable $e) {
        responder_erro('Algo deu errado no servidor.', $e);
    }
}
