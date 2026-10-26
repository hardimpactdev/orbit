<?php

declare(strict_types=1);

$result = ['ok' => 0, 'failed' => 0, 'errors' => []];
while (true) {
    $context = stream_context_create(['http' => ['method' => 'POST', 'header' => "Authorization: Bearer held-task-key\r\nContent-Type: application/json", 'content' => '{"model":"test-model","messages":[{"role":"user","content":"ping"}]}', 'timeout' => 1]]);
    $body = @file_get_contents('http://127.0.0.1:8317/v1/chat/completions', context: $context);
    if (is_string($body) && (json_decode($body, true)['choices'][0]['message']['content'] ?? null) === 'ok') {
        $result['ok']++;
    } else {
        $result['failed']++;
        $result['errors'][] = ['time' => microtime(true), 'error' => 'Held-key request failed'];
    }
    file_put_contents($argv[1].'.next', json_encode($result, JSON_THROW_ON_ERROR));
    rename($argv[1].'.next', $argv[1]);
    usleep(20_000);
}
