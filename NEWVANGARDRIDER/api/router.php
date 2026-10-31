<?php
declare(strict_types=1);

$requestPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$requestPath = is_string($requestPath) ? $requestPath : '/';

if ($requestPath === '/api' || str_starts_with($requestPath, '/api/')) {
    require __DIR__ . '/index.php';
}

$staticFile = dirname(__DIR__) . '/dist' . $requestPath;
if ($requestPath !== '/' && is_file($staticFile)) {
    return false;
}

$indexFile = dirname(__DIR__) . '/dist/index.html';
if (is_file($indexFile)) {
    header('Content-Type: text/html; charset=utf-8');
    readfile($indexFile);
    exit;
}

http_response_code(404);
