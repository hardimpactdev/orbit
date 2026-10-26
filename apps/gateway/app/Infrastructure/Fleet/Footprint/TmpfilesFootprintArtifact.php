<?php

declare(strict_types=1);

namespace App\Infrastructure\Fleet\Footprint;

use App\Domain\Fleet\NodeFootprintArtifact;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Models\Node;
use App\Models\NodeRole;

/**
 * The systemd-tmpfiles rule on an `app-dev` Node that empties Orbit's scratch directories in `/tmp` and
 * `/dev/shm` a day after their contents last changed. Task agents, task checks, and Orbit's test suites run
 * there and name their scratch `orbit-*`; a run that is killed leaves it behind. Orbit's own `/dev/shm/orbit`
 * does not match. The rule touches no other path.
 */
final readonly class TmpfilesFootprintArtifact implements NodeFootprintArtifact
{
    public const string Path = '/etc/tmpfiles.d/orbit.conf';

    public const string Rule = <<<'CONF'
        # Managed by Orbit. Empties Orbit's scratch directories one day after their contents last changed.
        e /tmp/orbit-* - - - 1d
        e /dev/shm/orbit-* - - - 1d

        CONF;

    public function __construct(private AgentCommandRunner $agents) {}

    public function name(): string
    {
        return 'tmpfiles';
    }

    public function applies(Node $node): bool
    {
        return $node->platform === 'linux' && $node->roles->contains(
            static fn (NodeRole $role): bool => $role->role === RoleName::AppDev && $role->status === LifecycleStatus::Active,
        );
    }

    public function digest(Node $node): string
    {
        return hash('sha256', self::Rule);
    }

    public function apply(Node $node): bool
    {
        try {
            return ($this->agents->run($node, 'node.publish-tmpfiles', ['rule' => self::Rule])['changed'] ?? false) === true;
        } catch (AgentCommandRefused|AgentRunnerFailure $exception) {
            throw new ResourceOperationException('node.footprint_tmpfiles_failed', 'The tmpfiles rule could not be published: '.$exception->getMessage(), 502, $exception);
        }
    }
}
