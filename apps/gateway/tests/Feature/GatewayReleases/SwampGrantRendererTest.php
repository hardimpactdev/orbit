<?php

declare(strict_types=1);

use App\Infrastructure\GatewayReleases\SwampGrantRenderer;
use Illuminate\Filesystem\Filesystem;

beforeEach(function (): void {
    $this->repository = sys_get_temp_dir().'/orbit-release-grants-'.bin2hex(random_bytes(6));
    mkdir($this->repository.'/config', 0755, true);
    file_put_contents($this->repository.'/config/gateway-workflow-grants.json', json_encode([
        'principal' => 'user:gateway-fixture',
        'vaults' => [['vault' => 'private-inputs', 'key_pattern' => '^key-\\d+"suffix$', 'actions' => ['write', 'read']]],
        'workflows' => [['workflow' => 'schedule-*', 'actions' => ['run', 'read', 'write'], 'publishes_inputs' => ['secret'], 'schedule_workflows' => true]],
    ], JSON_THROW_ON_ERROR));
    $this->serve = [['subject' => 'worker:*', 'effect' => 'allow', 'actions' => ['read'], 'resource' => 'vault:private-inputs',
        'condition' => 'key.matches("^safe$") && principal != "user:other"']];
    file_put_contents($this->repository.'/config/serve-grants.json', json_encode(['grants' => $this->serve], JSON_THROW_ON_ERROR));
});

afterEach(function (): void {
    new Filesystem()->deleteDirectory($this->repository);
});

it('renders only serve grant fields and preserves nested CEL string escaping and raw serve conditions', function (): void {
    $files = new SwampGrantRenderer()->render($this->repository);
    $gateway = json_decode($files['grants/orbit-gateway.yaml'], true, flags: JSON_THROW_ON_ERROR);
    expect($gateway)->toBe(['grants' => [
        ['subject' => 'user:gateway-fixture', 'effect' => 'allow', 'actions' => ['write', 'read'], 'resource' => 'vault:private-inputs',
            'condition' => 'key.matches('.json_encode('^key-\\d+"suffix$', JSON_THROW_ON_ERROR).')'],
        ['subject' => 'user:gateway-fixture', 'effect' => 'allow', 'actions' => ['run', 'read', 'write'], 'resource' => 'workflow:schedule-*'],
    ]])->and(json_decode($files['grants/orbit-serve.yaml'], true, flags: JSON_THROW_ON_ERROR))->toBe(['grants' => $this->serve]);
});

it('refuses missing or malformed release manifests', function (bool $missing): void {
    $path = $this->repository.'/config/serve-grants.json';
    if ($missing) {
        unlink($path);
    } else {
        file_put_contents($path, '{broken');
    }
    expect(fn () => new SwampGrantRenderer()->render($this->repository))->toThrow($missing ? RuntimeException::class : JsonException::class);
})->with([true, false]);
