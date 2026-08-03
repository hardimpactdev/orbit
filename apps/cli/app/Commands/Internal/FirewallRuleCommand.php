<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Services\Firewall\LocalFirewallRuleAction;
use App\Services\Firewall\LocalFirewallRuleFailure;
use InvalidArgumentException;
use JsonException;

final class FirewallRuleCommand extends InternalExecutorCommand
{
    #[\Override]
    protected $signature = 'internal:firewall-rule {--operation-token=} {--json}';

    #[\Override]
    protected $description = 'Run a typed firewall rule backend mutation from an internal stdin payload';

    public function handle(LocalFirewallRuleAction $firewall): int
    {
        if (! $this->verifyOperationToken('internal:firewall-rule')) {
            return self::FAILURE;
        }

        try {
            $result = $firewall->run($this->readPayload());
        } catch (LocalFirewallRuleFailure $failure) {
            return $this->renderFailure($failure->errorCode, $failure->getMessage(), $failure->meta);
        } catch (InvalidArgumentException|JsonException) {
            return $this->renderFailure('validation_failed', 'Firewall rule payload is invalid.', []);
        }

        return $this->emitInternalSuccess($result, []);
    }

    /**
     * @return array<string, mixed>
     */
    private function readPayload(): array
    {
        $stdin = $this->stdin();

        if ($stdin === '') {
            throw new InvalidArgumentException('Firewall rule payload must be provided on stdin.');
        }

        /** @var mixed $decoded */
        $decoded = json_decode($stdin, associative: true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Firewall rule payload must be an object.');
        }

        foreach (array_keys($decoded) as $key) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Firewall rule payload must be an object.');
            }
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
