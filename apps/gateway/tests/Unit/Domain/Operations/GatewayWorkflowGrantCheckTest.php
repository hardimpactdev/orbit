<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\GatewayWorkflowGrantCheck;

describe('Gateway workflow grant intent', function (): void {
    beforeEach(function (): void {
        $this->definitions = app(DirectOperation::class)->grantDefinitions();
        $this->policy = json_decode(file_get_contents(base_path('../../swamp/config/gateway-workflow-grants.json')), true, flags: JSON_THROW_ON_ERROR);
    });

    it('accepts the checked-in intent for all production registrations including Node converge', function (): void {
        $count = (new GatewayWorkflowGrantCheck)->execute($this->definitions, $this->policy);

        expect($count)->toBeGreaterThan(0);
    });

    it('declares and grants every vault key that a container create writes, including its native T3 sessions', function (string $key): void {
        $declared = app(DirectOperation::class)->definition('instance.create')['execution_copies'] ?? [];
        $matches = static fn (array $copies): bool => array_any($copies, static fn (array $copy): bool => preg_match('~'.$copy['key_pattern'].'~D', $key) === 1);

        expect($matches($declared))->toBeTrue()
            ->and($matches($this->policy['vaults']))->toBeTrue();
    })->with([
        'instance-create-environment-input-12', 'instance-create-processes-12', 'instance-create-model-12',
        'nckrtl-instance-t3-00000000-0000-4000-8000-000000000001-register-session-gateway-1831-token',
        'nckrtl-instance-t3-00000000-0000-4000-8000-000000000001-register-session-swamp-1831-token',
    ]);

    it('refuses intent missing the Node converge verifier copy', function (): void {
        $this->policy['vaults'] = array_values(array_filter($this->policy['vaults'], static fn (array $copy): bool => preg_match('~'.$copy['key_pattern'].'~D', 'node-converge-operation-17-agent-verifier') !== 1));

        expect(fn () => (new GatewayWorkflowGrantCheck)->execute($this->definitions, $this->policy))
            ->toThrow(LogicException::class, 'Execution copy vault intent must match all current declarations exactly.');
    });

    it('refuses a broader pattern instead of the declared Node converge verifier copy', function (): void {
        $this->policy['vaults'] = array_map(static fn (array $copy): array => preg_match('~'.$copy['key_pattern'].'~D', 'node-converge-operation-17-agent-verifier') === 1
            ? [...$copy, 'key_pattern' => '^node-converge-operation-.*$'] : $copy, $this->policy['vaults']);

        expect(fn () => (new GatewayWorkflowGrantCheck)->execute($this->definitions, $this->policy))
            ->toThrow(LogicException::class, 'Execution copy vault intent must match all current declarations exactly.');
    });
});
