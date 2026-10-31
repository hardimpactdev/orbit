<?php

declare(strict_types=1);

use App\Actions\Nodes\AddNodeRoleAction;
use App\Domain\Nodes\NodeConverger;
use App\Domain\Nodes\NodeObservation;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\NodeProvisioningIdentity;
use App\Domain\Nodes\RecoverableNodeConverger;
use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Nodes\RoleBaselineConverger;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\ToolManagerMaterializer;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolManagerScopeLock;
use App\Infrastructure\Nodes\NodeLocks;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeToolManagerMaterializer;

describe(AddNodeRoleAction::class, function (): void {
    beforeEach(function (): void {
        app()->instance(ToolManagerMaterializer::class, new FakeToolManagerMaterializer);
        app()->instance(NodeConverger::class, new AddNodeRoleNodeConvergerFake);
    });

    it('keeps a claim fresh for a margin past the lock term', function (): void {
        $node = add_role_node();
        $assignment = $node->roles()->create(['role' => RoleName::Metrics, 'status' => LifecycleStatus::Provisioning]);

        $this->travel(NodeLocks::RequestSeconds + 1)->seconds();
        expect($assignment->refresh()->isStaleClaim())->toBeFalse();

        $this->travel(NodeRole::StaleClaimMarginSeconds)->seconds();
        expect($assignment->refresh()->isStaleClaim())->toBeTrue()
            ->and(NodeRole::StaleClaimSeconds)->toBe(660);
    });

    it('lets only one operation take a stale claim', function (): void {
        $node = add_role_node();
        $assignment = $node->roles()->create(['role' => RoleName::Metrics, 'status' => LifecycleStatus::Provisioning]);
        $this->travel(NodeRole::StaleClaimSeconds + 1)->seconds();
        $first = NodeRole::query()->findOrFail($assignment->id);
        $second = NodeRole::query()->findOrFail($assignment->id);
        $this->travel(1)->seconds();

        expect($first->canClaimConvergence())->toBeTrue()
            ->and($second->canClaimConvergence())->toBeTrue();

        $first->claimConvergence();

        expect(fn () => $second->claimConvergence())
            ->toThrow(RoleAssignmentException::class, 'Role [metrics] changed while it was being claimed.')
            ->and($first->isStaleClaim())->toBeFalse();
    });

    it('allows assignable roles on a provisioning node', function (): void {
        $baseline = new AddNodeRoleBaselineFake;
        app()->instance(RoleBaselineConverger::class, $baseline);
        $node = add_role_node(LifecycleStatus::Provisioning);

        $assignment = app(AddNodeRoleAction::class)->executeDuringProvisioning($node, RoleName::AppDev);

        expect($assignment->status)
            ->toBe(LifecycleStatus::Active)
            ->and($baseline->convergedRoles)
            ->toBe([RoleName::AppDev]);
    });

    it('rejects failed, removing, and nonexistent nodes during provisioning', function (?LifecycleStatus $status): void {
        $baseline = new AddNodeRoleBaselineFake;
        app()->instance(RoleBaselineConverger::class, $baseline);
        $node = $status === null ? new Node : add_role_node($status);

        expect(fn () => app(AddNodeRoleAction::class)->executeDuringProvisioning($node, RoleName::AppDev))
            ->toThrow(RoleAssignmentException::class);

        expect($baseline->convergedRoles)->toBeEmpty();
        if ($node->exists) {
            expect($node->roles()->exists())->toBeFalse();
        }
    })->with([
        'failed node' => LifecycleStatus::Failed,
        'removing node' => LifecycleStatus::Removing,
        'nonexistent node' => null,
    ]);

    it('rechecks singleton ownership during a provisioning claim', function (): void {
        $baseline = new AddNodeRoleBaselineFake;
        app()->instance(RoleBaselineConverger::class, $baseline);
        $first = add_role_node(name: 'first');
        $second = add_role_node(name: 'second', wireguardIp: '10.44.0.3');
        $first->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);

        expect(fn () => app(AddNodeRoleAction::class)->executeDuringProvisioning($second, RoleName::Gateway))
            ->toThrow(RoleAssignmentException::class);

        expect($baseline->convergedRoles)
            ->toBeEmpty()
            ->and($second->roles()->exists())
            ->toBeFalse();
    });

});

function add_role_node(
    LifecycleStatus $status = LifecycleStatus::Active,
    string $name = 'app-host',
    string $wireguardIp = '10.44.0.2',
): Node {
    return Node::query()->create([
        'name' => $name,
        'status' => $status,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'public_ssh_host' => '192.0.2.10',
        'user' => 'orbit',
        'wireguard_ip' => $wireguardIp,
    ]);
}

final class AddNodeRoleBaselineFake implements RoleBaselineConverger
{
    /** @var list<RoleName> */
    public array $convergedRoles = [];

    /** @var list<LifecycleStatus> */
    public array $observedStatuses = [];

    /** @var list<int> */
    public array $transactionLevels = [];

    public ?Throwable $failure = null;

    public ?Closure $onConverge = null;

    public function converge(Node $node, NodeRole $assignment): void
    {
        $this->convergedRoles[] = $assignment->role;
        $this->observedStatuses[] = $assignment->status;
        $this->transactionLevels[] = DB::transactionLevel();
        ($this->onConverge ?? static function (): void {})();

        if ($this->failure instanceof Throwable) {
            throw $this->failure;
        }
    }

    public function remove(Node $node, NodeRole $assignment, bool $purgeData): void {}

    public function removeUnreachable(Node $node, NodeRole $assignment): void {}
}

final class AddNodeRoleNodeConvergerFake implements NodeConverger, RecoverableNodeConverger
{
    /** @var list<string> */
    public array $events = [];

    /** @var list<array{string, string}> */
    public array $identities = [];

    public ?NodeProvisioningException $failure = null;

    public ?NodeProvisioningException $finalizationFailure = null;

    public string $dnsMode = 'operator';

    public function converge(Node $node, NodeProvisioningIdentity $identity, ?string $expectedSshHostFingerprint = null, bool $rolelessOperator = false, ?Closure $recordObservation = null): NodeObservation
    {
        $this->events[] = 'peer:'.($rolelessOperator ? 'operator' : 'managed').':'.$node->roles()->count();
        $this->identities[] = [$identity->bootstrapUser, $identity->managedUser];

        if ($this->failure instanceof NodeProvisioningException) {
            throw $this->failure;
        }

        $observation = new NodeObservation('x86_64');
        $recordObservation?->__invoke($observation);

        return $observation;
    }

    public function convergeRecoverably(Node $node, NodeProvisioningIdentity $identity, ?string $expectedSshHostFingerprint, Closure $completion, bool $rolelessOperator = false, ?Closure $recordObservation = null): void
    {
        $observation = $this->converge($node, $identity, $expectedSshHostFingerprint, $rolelessOperator, $recordObservation);
        $oldMode = $this->dnsMode;
        $this->dnsMode = $rolelessOperator ? 'operator' : 'managed';

        try {
            $completion($observation);

            if ($this->finalizationFailure instanceof NodeProvisioningException) {
                throw $this->finalizationFailure;
            }
        } catch (Throwable $exception) {
            $this->dnsMode = $oldMode;
            $this->events[] = 'rollback';

            throw $exception;
        }
    }
}

final class StateAwareToolManagerScopeLock implements ToolManagerScopeLock
{
    /** @var list<string> */
    public array $events = [];

    /** @var list<array{manager: ToolManagerName, status: LifecycleStatus, failed_step: ?string, error_code: ?string}> */
    public array $releaseStates = [];

    public function run(int $nodeId, ToolManagerName $manager, Closure $callback): mixed
    {
        $this->events[] = 'acquire:'.$manager->value;

        try {
            return $callback();
        } finally {
            $assignment = NodeRole::query()->where('node_id', $nodeId)->sole()->refresh();
            $this->releaseStates[] = [
                'manager' => $manager,
                'status' => $assignment->status,
                'failed_step' => $assignment->failed_step,
                'error_code' => $assignment->error_code,
            ];
            $this->events[] = 'release:'.$manager->value;
        }
    }
}
