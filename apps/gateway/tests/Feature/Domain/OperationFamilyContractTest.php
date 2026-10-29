<?php

declare(strict_types=1);

use App\Domain\Operations\OperationFamilyCatalogue;
use App\Domain\Operations\OperationFamilyContract;
use App\Domain\Operations\OperationGatewayCommands;
use App\Domain\Tools\ToolOperationCompletion;
use App\Infrastructure\AgentCommands\AgentContract;

it('exports the pinned agent and registered Gateway commands with closed hook names', function (): void {
    $catalogue = app(OperationFamilyCatalogue::class);
    $document = json_decode($catalogue->json(), true, flags: JSON_THROW_ON_ERROR);
    $agentPath = base_path('../agent/contract/commands.json');
    $agent = new AgentContract($agentPath);
    $names = $agent->names();
    sort($names);
    $gateway = app(OperationGatewayCommands::class)->names();
    sort($gateway);

    expect($document['agent_contract_sha256'])->toBe(hash_file('sha256', $agentPath))
        ->and(array_column($document['commands']['orbit-agent'], 'name'))->toBe($names)
        ->and(array_column($document['commands']['gateway'], 'name'))->toBe($gateway)
        ->and($catalogue->projection('tools.record_intent'))->toBeInstanceOf(ToolOperationCompletion::class)
        ->and($document['invariants'])->toBe([])
        ->and($document['native_methods'])->toBe([]);
});

it('loads the actual Swamp compiler artifact only at its reviewed release pin', function (): void {
    $contract = app(OperationFamilyContract::class)->load(
        base_path('tests/Fixtures/Operations/family-contract.json'),
        'a13bac0016250ea5e31cf4888aa3098c926d65176bc7f4efe8e69b0568f02ed7',
    );

    expect($contract['kind'])->toBe('example.manage')
        ->and($contract['catalogue'])->toBe(app(OperationFamilyCatalogue::class)->digest())
        ->and($contract['workflow']['id'])->toBe('1b2998fb-67eb-440d-bbea-9f77031b5fe2');
});

it('refuses an artifact outside its release pin', function (string $pin): void {
    expect(fn () => app(OperationFamilyContract::class)->load(base_path('tests/Fixtures/Operations/family-contract.json'), $pin))
        ->toThrow(LogicException::class, 'Family artifact does not match its release pin.');
})->with(['', 'not-a-hash', str_repeat('0', 64)]);

it('refuses stale catalogues and unregistered names even in a release-pinned artifact', function (string $field, mixed $value, string $message): void {
    $contract = json_decode(file_get_contents(base_path('tests/Fixtures/Operations/family-contract.json')), true, flags: JSON_THROW_ON_ERROR);
    $contract[$field] = $value;
    $bytes = json_encode($contract, JSON_THROW_ON_ERROR);
    $path = tempnam(sys_get_temp_dir(), 'orbit-family-');
    file_put_contents($path, $bytes);

    try {
        expect(fn () => app(OperationFamilyContract::class)->load($path, hash('sha256', $bytes)))
            ->toThrow(LogicException::class, $message);
    } finally {
        unlink($path);
    }
})->with([
    ['catalogue', str_repeat('0', 64), 'Family catalogue does not match this Gateway release.'],
    ['schema', 'orbit.family-contract/v2', 'Family catalogue does not match this Gateway release.'],
    ['projection', 'caller.arbitrary', 'Unknown family projection.'],
    ['invariants', ['caller.arbitrary'], 'Unknown family invariant.'],
    ['commands', ['inspect' => ['transport' => 'orbit-agent', 'names' => ['valid.looking.typo']]], 'Unknown family command.'],
    ['commands', ['inspect' => ['transport' => 'gateway', 'names' => ['tool.apt-version']]], 'Unknown family command.'],
    ['commands', ['inspect' => ['transport' => 'shell', 'names' => ['exec']]], 'Unknown family transport.'],
    ['commands', ['inspect' => ['transport' => 'orbit-agent', 'names' => ['tool.apt-version']]], 'No native family command methods are registered.'],
]);
