<?php

declare(strict_types=1);

if ($_SERVER['REQUEST_URI'] === '/sync/stream') {
    header('Content-Type: application/octet-stream');
    header('Content-Length: 13');
    echo "first\n";
    flush();
    usleep(1_500_000);
    echo "second\n";

    return;
}
header('Content-Type: application/json');
echo json_encode([
    'path' => $_SERVER['REQUEST_URI'],
    'method' => $_SERVER['REQUEST_METHOD'],
    'body' => file_get_contents('php://input'),
    'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
], JSON_THROW_ON_ERROR);
