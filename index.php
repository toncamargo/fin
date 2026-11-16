<?php
/**
 * Página inicial com "cache busting": cada CSS/JS vai com ?v=<data do arquivo>.
 * A CDN da Hostinger guarda arquivos estáticos por 7 dias; com a versão na URL, qualquer mudança
 * vira um endereço novo e nunca se mistura com a versão antiga. Os módulos JS importados entre si
 * também ganham versão, por um import map gerado aqui.
 */
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
header('X-Robots-Tag: noindex, nofollow');

$raiz = __DIR__;
$versao = fn(string $rel) => is_file("$raiz/$rel") ? substr(base_convert((string) filemtime("$raiz/$rel"), 10, 36), -6) : '0';

$html = file_get_contents("$raiz/index.html");

// <link href="assets/...css"> e <script src="assets/...js">
$html = preg_replace_callback('#(href|src)="(assets/[^"?]+\.(?:css|js|svg|woff2))"#', fn($m) => "{$m[1]}=\"{$m[2]}?v={$versao($m[2])}\"", $html);

// Import map: cada módulo de assets/js (e subpastas) aponta para a URL versionada
$mapa = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$raiz/assets/js", FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->getExtension() !== 'js') continue;
    $rel = 'assets/js/' . str_replace('\\', '/', substr($f->getPathname(), strlen("$raiz/assets/js/")));
    $mapa["/$rel"] = "/$rel?v=" . $versao($rel);
}
$importmap = '<script type="importmap">' . json_encode(['imports' => $mapa], JSON_UNESCAPED_SLASHES) . '</script>';
$html = preg_replace('#<script type="module"#', $importmap . "\n  <script type=\"module\"", $html, 1);

echo $html;
