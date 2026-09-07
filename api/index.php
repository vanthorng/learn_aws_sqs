<?php

declare(strict_types=1);

$publicPath = realpath(__DIR__.'/../public');
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$requestedFile = realpath($publicPath.'/'.ltrim($requestPath, '/'));

if ($publicPath !== false
    && $requestedFile !== false
    && str_starts_with($requestedFile, $publicPath.DIRECTORY_SEPARATOR)
    && is_file($requestedFile)) {
    $extension = pathinfo($requestedFile, PATHINFO_EXTENSION);
    $contentTypes = [
        'css' => 'text/css; charset=UTF-8',
        'js' => 'text/javascript; charset=UTF-8',
        'json' => 'application/json; charset=UTF-8',
        'svg' => 'image/svg+xml',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
    ];

    header('Content-Type: '.($contentTypes[$extension] ?? 'application/octet-stream'));
    header('Cache-Control: public, max-age=31536000, immutable');

    readfile($requestedFile);

    return;
}

require $publicPath.'/index.php';
