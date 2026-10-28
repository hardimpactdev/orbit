<?php

declare(strict_types=1);

namespace App\Domain\Integrations;

/** Builds only server-owned integration intent. Protected values are saved separately by admission. */
final class IntegrationPlan
{
    /** @var array<string, array<string, mixed>> */
    public array $effects = [];

    /** @var list<array<string, mixed>> */
    public array $flow = [];

    /** @var array<string, array<string, mixed>> */
    public array $protected = [];

    public function __construct(private readonly int $ownerId) {}

    /** @param array<string, mixed> $input
     * @param  array<string, mixed>  $secrets
     */
    public function agent(string $key, string $command, array $input, #[\SensitiveParameter] array $secrets = []): void
    {
        foreach ($secrets as $parameter => $value) {
            $input[$parameter] = ['$secret' => 'integration.payload', 'id' => $this->ownerId];
            $this->protected[$key][$parameter] = $value;
        }
        $this->effects[$key] = ['key' => $key, 'step' => $key, 'transport' => 'agent', 'name' => $command,
            'input' => $input, 'resumable' => true, 'required' => true];
        $this->flow[] = ['type' => 'run', 'key' => $key];
    }

    /** @param array<string, mixed> $input */
    public function child(string $key, string $kind, int $nodeId, array $input, bool $continueOnFailure = false): void
    {
        $this->flow[] = ['type' => 'child', 'key' => $key, 'kind' => $kind, 'node_id' => $nodeId, 'input' => $input, ...($continueOnFailure ? ['continue_on_failure' => true] : [])];
    }

    /** @param array<string, mixed> $input */
    public function gateway(string $key, string $command, array $input): void
    {
        $this->effects[$key] = ['key' => $key, 'step' => $key, 'transport' => 'gateway', 'name' => $command,
            'input' => $input, 'resumable' => true, 'required' => true];
        $this->flow[] = ['type' => 'run', 'key' => $key];
    }

    public function receipt(string $backend): void
    {
        $this->effects['integration-receipt'] = ['key' => 'integration-receipt', 'step' => 'integration-receipt',
            'transport' => 'gateway', 'name' => 'integration.receipt', 'input' => ['backend' => $backend], 'resumable' => true, 'required' => true];
        $this->flow[] = ['type' => 'run', 'key' => 'integration-receipt'];
    }
}
