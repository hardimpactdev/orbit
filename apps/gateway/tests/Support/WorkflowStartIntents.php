<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use PHPUnit\Framework\Assert;

/**
 * The workflow inputs that the Gateway hands Swamp when it starts a run, kept as a fixture of the Swamp test that resolves every
 * expression of a workflow against them (swamp/tests/workflow_start_resolution_test.ts). Swamp resolves every step's inputs when a
 * run starts, also of a step a guard will skip, so the fixture must hold exactly the keys the Gateway sends: the Pest test that builds
 * the inputs compares their key paths with the fixture. ORBIT_WRITE_START_INTENTS=1 writes the fixture from the builders instead.
 */
final class WorkflowStartIntents
{
    public const string Fixture = __DIR__.'/../../../../swamp/tests/fixtures/workflow-start-intents.json';

    /** A native vault that accepts each put, as the Swamp serve socket answers it. */
    public static function vault(): WebSocketClient
    {
        config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/ca.pem', 'swamp.run_token' => 'run-token']);

        return new class implements WebSocketClient
        {
            /** @var list<array<string, mixed>> */
            private array $frames = [];

            public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void {}

            public function send(array $message): void
            {
                $this->frames[] = $message;
            }

            public function receive(float $timeoutSeconds): array
            {
                $frame = end($this->frames);
                $payload = is_array($frame) && is_array($frame['payload'] ?? null) ? $frame['payload'] : [];

                return [['id' => is_array($frame) ? ($frame['id'] ?? null) : null, 'type' => is_array($frame) ? ($frame['type'] ?? null) : null,
                    'payload' => ['data' => ['vaultName' => $payload['vaultName'] ?? null, 'secretKey' => $payload['key'] ?? null, 'vaultType' => 'local_encryption']]]];
            }

            public function close(): void {}

            public function isConnected(): bool
            {
                return true;
            }
        };
    }

    /**
     * Writes the inputs under `$name` when asked to, else requires the fixture's inputs under `$name` to have exactly their key paths.
     *
     * @param  array<string, mixed>  $inputs
     */
    public static function match(string $name, array $inputs): void
    {
        $fixture = is_file(self::Fixture) ? json_decode((string) file_get_contents(self::Fixture), true, flags: JSON_THROW_ON_ERROR) : [];
        Assert::assertIsArray($fixture);
        if (getenv('ORBIT_WRITE_START_INTENTS') === '1') {
            $fixture[$name] = $inputs;
            ksort($fixture);
            file_put_contents(self::Fixture, json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

            return;
        }
        Assert::assertArrayHasKey($name, $fixture, "Write the fixture with ORBIT_WRITE_START_INTENTS=1: {$name} is missing.");
        Assert::assertSame(self::paths($inputs), self::paths($fixture[$name]), "The Gateway's {$name} inputs no longer match swamp/tests/fixtures/workflow-start-intents.json.");
    }

    /** @return list<string> */
    private static function paths(mixed $value, string $prefix = ''): array
    {
        if (! is_array($value) || $value === []) {
            return [$prefix];
        }
        $paths = [];
        foreach ($value as $key => $item) {
            array_push($paths, ...self::paths($item, $prefix === '' ? (string) $key : $prefix.'.'.$key));
        }
        sort($paths);

        return $paths;
    }
}
