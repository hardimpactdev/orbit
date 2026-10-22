<?php

declare(strict_types=1);

use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\GatewayReleases\GatewayNodeAgentUpdate;
use App\Models\Node;
use Tests\Support\FakeAgentCommandRunner;

function gatewayAgentNode(string $platform = 'linux'): Node
{
    return new Node(['name' => 'gateway', 'platform' => $platform, 'architecture' => 'x86_64']);
}

function gatewayAgentUpdate(FakeAgentCommandRunner $runner): GatewayNodeAgentUpdate
{
    return new GatewayNodeAgentUpdate($runner, app(AgentDelivery::class));
}

describe(GatewayNodeAgentUpdate::class, function (): void {
    it('installs the release pin through the agent and reports the reported version', function (bool $replaced, string $outcome): void {
        $runner = (new FakeAgentCommandRunner)->installReplaces($replaced);
        $version = app(AgentDelivery::class)->reportedVersion(gatewayAgentNode());

        expect(gatewayAgentUpdate($runner)->converge(gatewayAgentNode()))
            ->toBe(['outcome' => $outcome, 'version' => $version])
            ->and($runner->installs)->toBe(['gateway'])
            ->and($version)->toStartWith('0.5.0+');
    })->with([
        'replaced' => [true, 'updated'],
        'already pinned' => [false, 'unchanged'],
    ]);

    it('skips a Gateway Node that is not on Linux without running anything', function (): void {
        $runner = new FakeAgentCommandRunner;

        expect(gatewayAgentUpdate($runner)->converge(gatewayAgentNode('macos')))
            ->toBe(['outcome' => 'skipped', 'reason' => 'platform'])
            ->and($runner->installs)->toBe([]);
    });

    it('records the installer refusal and its cause instead of throwing', function (): void {
        $runner = (new FakeAgentCommandRunner)->failInstall(new AgentCommandRefused('agent.install', 'agent.install_failed', 'The observer did not stay active.', ['cause' => 'agent.health_check_failed']));

        expect(gatewayAgentUpdate($runner)->converge(gatewayAgentNode()))
            ->toMatchArray(['outcome' => 'failed', 'error_code' => 'agent.health_check_failed', 'message' => 'The observer did not stay active.']);
    });

    it('records a lost result as a failed installation instead of throwing', function (): void {
        $runner = (new FakeAgentCommandRunner)->failInstall(new AgentRunnerFailure('agent.install', AgentRunnerFailure::MissingResult, 'No result.'));

        expect(gatewayAgentUpdate($runner)->converge(gatewayAgentNode()))
            ->toMatchArray(['outcome' => 'failed', 'error_code' => 'agent.install_failed', 'message' => 'No result.']);
    });
});
