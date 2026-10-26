<?php

declare(strict_types=1);

use App\Data\Fleet\DesiredCliReleaseData;
use App\Domain\Fleet\CliReleaseName;
use App\Domain\Fleet\CliReleaseUnavailableReason;
use App\Domain\Nodes\NodeCliInstallation;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Nodes\AgentNodeCliInstaller;
use App\Infrastructure\Nodes\NodeCliFootprint;
use App\Models\Node;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\Fleet\FakeCliReleaseCatalog;
use Tests\Support\Fleet\FleetFixtures;

function cliInstallNode(): Node
{
    // The CLI profile names the Gateway's own WireGuard address, so the test sets it rather than the fixture counter.
    FleetFixtures::node('gateway', [RoleName::Gateway])->update(['wireguard_ip' => '10.44.0.1']);

    return new Node(['name' => 'dev', 'platform' => 'linux', 'architecture' => 'x86_64', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.2']);
}

function cliProfile(): string
{
    $address = Node::query()->where('name', 'gateway')->value('wireguard_ip');

    return NodeCliFootprint::configuration((string) $address);
}

function cliRelease(string $sha256 = ''): DesiredCliReleaseData
{
    return new FakeCliReleaseCatalog(sha256: $sha256 !== '' ? $sha256 : str_repeat('c', 64))->find(str_repeat('a', 40), new CliReleaseName(4681));
}

describe('CLI install on a Node', function (): void {
    it('sends the release for the Node architecture and root\'s profile, and reports the installed version', function (): void {
        $agents = (new FakeAgentCommandRunner)->answer('cli.install', ['installed' => '0.4681.0', 'configured' => true]);

        $installation = (new AgentNodeCliInstaller($agents))->ensure(cliInstallNode(), cliRelease());

        expect($installation->outcome)->toBe(NodeCliInstallation::Installed)
            ->and($installation->version)->toBe('0.4681.0')
            ->and($installation->configured)->toBeTrue()
            ->and($agents->calls[0]['input'])->toBe([
                'user' => 'orbit',
                'release' => ['version' => '0.4681.0', 'url' => cliRelease()->assets[0]->url, 'sha256' => str_repeat('c', 64)],
                'profile' => cliProfile(),
            ]);
    });

    it('reports a present CLI when the agent keeps it', function (): void {
        $agents = (new FakeAgentCommandRunner)->answer('cli.install', ['installed' => null, 'configured' => false]);

        $installation = (new AgentNodeCliInstaller($agents))->ensure(cliInstallNode(), cliRelease());

        expect($installation->outcome)->toBe(NodeCliInstallation::Present)
            ->and($installation->changed())->toBeFalse();
    });

    it('maps each agent refusal to its CLI error', function (string $code, string $error, int $status): void {
        $agents = (new FakeAgentCommandRunner)->refuse('cli.install', $code);

        expect(fn () => (new AgentNodeCliInstaller($agents))->ensure(cliInstallNode(), cliRelease()))
            ->toThrow(fn (ResourceOperationException $exception) => expect([$exception->errorCode, $exception->status])->toBe([$error, $status]));
    })->with([
        'foreign' => ['cli.foreign_binary', 'cli.foreign_binary', 409],
        'busy' => ['cli.update_busy', 'node.update_busy', 409],
        'download' => ['cli.download_failed', 'cli.download_failed', 502],
        'checksum' => ['cli.checksum_mismatch', 'cli.checksum_mismatch', 502],
        'candidate' => ['cli.candidate_invalid', 'cli.candidate_invalid', 502],
        'install' => ['cli.install_failed', 'cli.install_failed', 502],
    ]);

    it('sends no release before it is published, and reports that when the Node needs one', function (): void {
        $agents = (new FakeAgentCommandRunner)->refuse('cli.install', 'cli.release_needed');

        expect(fn () => (new AgentNodeCliInstaller($agents))->ensure(cliInstallNode(), DesiredCliReleaseData::unavailable(CliReleaseUnavailableReason::ReleaseMissing)))
            ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('cli.release_unavailable'));
        expect($agents->calls[0]['input']['release'])->toBeNull();
    });

    it('reads the link state through the agent', function (): void {
        $agents = (new FakeAgentCommandRunner)->answer('cli.inspect', ['state' => 'foreign']);

        expect((new AgentNodeCliInstaller($agents))->inspect(cliInstallNode()))->toBe('foreign')
            ->and($agents->calls[0]['input'])->toBe(['user' => 'orbit']);
    });

});
