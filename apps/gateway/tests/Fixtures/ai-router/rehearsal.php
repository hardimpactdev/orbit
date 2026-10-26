<?php

declare(strict_types=1);

// Synthetic CLIProxyAPI. The held-key probe runs as a separate PHP process.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$log = getenv('AI_ROUTER_FIXTURE_LOG');
if (is_string($log) && $log !== '') {
    file_put_contents($log, json_encode(['method' => $_SERVER['REQUEST_METHOD'], 'path' => $path], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
}
$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$client = str_starts_with($path, '/v1/');
if ($authorization !== 'Bearer '.($client ? 'held-task-key' : 'synthetic-management')) {
    http_response_code(401);
    exit;
}
$payload = match ($path) {
    '/v1/chat/completions' => ['choices' => [['message' => ['content' => 'ok']]]],
    '/v1/models' => ['data' => [['id' => 'test-model']]],
    '/v0/management/auth-files' => ['files' => [['name' => 'codex.json', 'auth_index' => 'codex-1', 'provider' => 'codex', 'email' => 'test@example.test']]],
    '/v0/management/auth-files/models' => ['models' => [['id' => 'test-model', 'owned_by' => 'openai']]],
    '/v0/management/api-call' => ['status_code' => 200, 'body' => ['rate_limit' => ['primary_window' => ['used_percent' => 25, 'limit_window_seconds' => 18000]]]],
    default => [],
};
header('Content-Type: application/json');
echo json_encode($payload, JSON_THROW_ON_ERROR);
