<?php

declare(strict_types=1);

use App\Actions\Nodes\AddNodeRoleAction;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Nodes\NodeConverger;
use App\Domain\Nodes\NodeLockLoss;
use App\Domain\Nodes\NodeObservation;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\NodeProvisioningIdentity;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Nodes\RecoverableNodeConverger;
use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Nodes\RoleBaselineConverger;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Nodes\NodeLocks;
use App\Infrastructure\Nodes\Roles\NodeRoleConvergeLock;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Support\Facades\DB;

describe(AddNodeRoleAction::class, function (): void {
    beforeEach(function (): void {
        app()->instance(NodeConverger::class, new AddNodeRoleNodeConvergerFake);
    });

    it('moves the first role from operator DNS to managed DNS before converging the role', function (): void {
        $nodeConverger = new AddNodeRoleNodeConvergerFake;
        $baseline = new AddNodeRoleBaselineFake;
        $baseline->onConverge = function () use ($nodeConverger): void {
            $nodeConverger->events[] = 'role';
        };
        app()->instance(NodeConverger::class, $nodeConverger);
        app()->instance(RoleBaselineConverger::class, $baseline);
        $node = add_role_node();

        $result = app(AddNodeRoleAction::class)->execute($node, RoleName::Metrics);

        expect($nodeConverger->events)->toBe(['peer:managed:0', 'role']);
        expect($nodeConverger->identities)->toBe([['orbit', 'orbit']]);
        expect($result['assignment']->status)->toBe(LifecycleStatus::Active);
        expect($node->roles()->count())->toBe(1);
    });

    it('leaves a roleless Node unassigned when peer convergence fails and permits retry', function (): void {
        $nodeConverger = new AddNodeRoleNodeConvergerFake;
        $nodeConverger->failure = new NodeProvisioningException('wireguard', 'vpn.peer_converge_failed', 'Peer publication failed.');
        app()->instance(NodeConverger::class, $nodeConverger);
        $baseline = new AddNodeRoleBaselineFake;
        app()->instance(RoleBaselineConverger::class, $baseline);
        $node = add_role_node();

        expect(fn () => app(AddNodeRoleAction::class)->execute($node, RoleName::Metrics))
            ->toThrow(function (NodeRoleOperationException $exception): void {
                expect($exception->step)->toBe('converge:wireguard')
                    ->and($exception->underlyingErrorCode)->toBe('vpn.peer_converge_failed');
            });
        expect($node->roles()->exists())->toBeFalse();
        expect($baseline->convergedRoles)->toBeEmpty();

        $nodeConverger->failure = null;
        $result = app(AddNodeRoleAction::class)->execute($node, RoleName::Metrics);

        expect($nodeConverger->events)->toBe(['peer:managed:0', 'peer:managed:0']);
        expect($result['assignment']->status)->toBe(LifecycleStatus::Active);
    });

    it('reconverges managed DNS on retry after the initial role baseline rolls back the peer', function (): void {
        $nodeConverger = new AddNodeRoleNodeConvergerFake;
        app()->instance(NodeConverger::class, $nodeConverger);
        $baseline = new AddNodeRoleBaselineFake;
        $baseline->failure = new NodeProvisioningException('baseline', 'node.baseline_failed', 'Baseline failed.');
        app()->instance(RoleBaselineConverger::class, $baseline);
        $node = add_role_node();

        expect(fn () => app(AddNodeRoleAction::class)->execute($node, RoleName::Metrics))
            ->toThrow(NodeRoleOperationException::class);
        expect($nodeConverger->dnsMode)->toBe('operator');
        expect($node->roles()->sole()->status)->toBe(LifecycleStatus::Failed);

        $baseline->failure = null;
        $result = app(AddNodeRoleAction::class)->execute($node, RoleName::Metrics, convergeExisting: true);

        expect($nodeConverger->events)->toBe(['peer:managed:0', 'rollback', 'peer:managed:1']);
        expect($nodeConverger->dnsMode)->toBe('managed');
        expect($result['created'])->toBeFalse();
        expect($result['assignment']->status)->toBe(LifecycleStatus::Active);
    });

    it('fails the assigned role if peer finalization rolls back after its baseline and retries managed DNS', function (): void {
        $nodeConverger = new AddNodeRoleNodeConvergerFake;
        $nodeConverger->finalizationFailure = new NodeProvisioningException('wireguard-peer-finalize', 'vpn.peer_finalize_failed', 'Finalization failed.');
        app()->instance(NodeConverger::class, $nodeConverger);
        $baseline = new AddNodeRoleBaselineFake;
        app()->instance(RoleBaselineConverger::class, $baseline);
        $node = add_role_node();

        expect(fn () => app(AddNodeRoleAction::class)->execute($node, RoleName::Metrics))
            ->toThrow(function (NodeRoleOperationException $exception): void {
                expect($exception->underlyingErrorCode)->toBe('vpn.peer_finalize_failed');
            });
        expect($nodeConverger->dnsMode)->toBe('operator');
        expect($node->roles()->sole()->status)->toBe(LifecycleStatus::Failed);
        expect($node->roles()->sole()->failed_step)->toBe('converge:wireguard-peer-finalize');

        $nodeConverger->finalizationFailure = null;
        $result = app(AddNodeRoleAction::class)->execute($node, RoleName::Metrics, convergeExisting: true);

        expect($nodeConverger->events)->toBe(['peer:managed:0', 'rollback', 'peer:managed:1']);
        expect($nodeConverger->dnsMode)->toBe('managed');
        expect($result['assignment']->status)->toBe(LifecycleStatus::Active);
    });

    it('republishes managed DNS after two different roles fail and roll back the peer', function (): void {
        $nodeConverger = new AddNodeRoleNodeConvergerFake;
        app()->instance(NodeConverger::class, $nodeConverger);
        $baseline = new AddNodeRoleBaselineFake;
        $baseline->failure = new NodeProvisioningException('baseline', 'node.baseline_failed', 'Baseline failed.');
        app()->instance(RoleBaselineConverger::class, $baseline);
        $node = add_role_node();

        expect(fn () => app(AddNodeRoleAction::class)->execute($node, RoleName::Metrics))
            ->toThrow(NodeRoleOperationException::class);
        expect(fn () => app(AddNodeRoleAction::class)->execute($node, RoleName::AppDev))
            ->toThrow(NodeRoleOperationException::class);
        expect($node->roles()->where('status', LifecycleStatus::Failed)->count())->toBe(2);
        expect($nodeConverger->dnsMode)->toBe('operator');

        $baseline->failure = null;
        $result = app(AddNodeRoleAction::class)->execute($node, RoleName::Metrics, convergeExisting: true);

        expect($nodeConverger->events)->toBe([
            'peer:managed:0', 'rollback',
            'peer:managed:1', 'rollback',
            'peer:managed:2',
        ]);
        expect($nodeConverger->dnsMode)->toBe('managed');
        expect($result['assignment']->status)->toBe(LifecycleStatus::Active);
        expect($node->roles()->where('status', LifecycleStatus::Failed)->count())->toBe(1);
    });

    it('skips peer convergence when the Node already has a role', function (): void {
        $nodeConverger = new AddNodeRoleNodeConvergerFake;
        app()->instance(NodeConverger::class, $nodeConverger);
        $baseline = new AddNodeRoleBaselineFake;
        app()->instance(RoleBaselineConverger::class, $baseline);
        $node = add_role_node();
        $node->roles()->create(['role' => RoleName::Metrics, 'status' => LifecycleStatus::Active]);

        $result = app(AddNodeRoleAction::class)->execute($node, RoleName::AppDev);

        expect($nodeConverger->events)->toBeEmpty();
        expect($result['assignment']->status)->toBe(LifecycleStatus::Active);
    });

    it('refuses analytics role assignment when storage settings are missing', function (): void {
        $baseline = new AddNodeRoleBaselineFake;
        app()->instance(RoleBaselineConverger::class, $baseline);
        $node = add_role_node();

        expect(fn () => app(AddNodeRoleAction::class)->execute($node, RoleName::Analytics))
            ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)
                ->toBe('analytics.settings_missing')
                ->and($exception->status)
                ->toBe(422))
            ->and($node->roles()->exists())
            ->toBeFalse()
            ->and($baseline->convergedRoles)
            ->toBeEmpty();
    });

    it('returns node_role.node_busy and leaves the assignment as it was when another role operation holds the Node', function (bool $existing): void {
        $baseline = new AddNodeRoleBaselineFake;
        app()->instance(RoleBaselineConverger::class, $baseline);
        app()->instance(NodeRoleConvergeLock::class, new NodeRoleConvergeLock(app(NodeLocks::class), waitSeconds: 0));
        $node = add_role_node();

        if ($existing) {
            app(AddNodeRoleAction::class)->execute($node, RoleName::Metrics);
            $baseline->convergedRoles = [];
        }

        $held = app(NodeLocks::class)->lock("node-role:id:{$node->id}", 60);
        expect($held->get())->toBeTrue();

        try {
            expect(fn () => app(AddNodeRoleAction::class)->execute($node, RoleName::Metrics, convergeExisting: $existing))
                ->toThrow(function (NodeRoleOperationException $exception) use ($node): void {
                    expect($exception->step)->toBe('converge:node-lock')
                        ->and($exception->errorCode)->toBe('node_role.convergence_failed')
                        ->and($exception->underlyingErrorCode)->toBe('node_role.node_busy')
                        ->and($exception->getMessage())->toBe("Another role operation is still running on node [{$node->name}].");
                });
        } finally {
            $held->release();
        }

        expect($baseline->convergedRoles)->toBeEmpty();

        if ($existing) {
            $assignment = $node->roles()->sole();
            expect($assignment->status)->toBe(LifecycleStatus::Active)
                ->and($assignment->failed_step)->toBeNull()
                ->and($assignment->error_code)->toBeNull();
        } else {
            expect($node->roles()->exists())->toBeFalse();
        }

        app(AddNodeRoleAction::class)->execute($node, RoleName::Metrics, convergeExisting: true);

        expect($baseline->convergedRoles)->toBe([RoleName::Metrics])
            ->and($node->roles()->sole()->status)->toBe(LifecycleStatus::Active);
    })->with(['new role' => [false], 'active role' => [true]]);

    it('converges a role whose provisioning claim went stale, and refuses a fresh one', function (): void {
        $baseline = new AddNodeRoleBaselineFake;
        app()->instance(RoleBaselineConverger::class, $baseline);
        $node = add_role_node();
        $assignment = $node->roles()->create(['role' => RoleName::Metrics, 'status' => LifecycleStatus::Provisioning]);

        expect(fn () => app(AddNodeRoleAction::class)->execute($node, RoleName::Metrics, convergeExisting: true))
            ->toThrow(RoleAssignmentException::class, 'Role [metrics] cannot converge from status [provisioning].');

        $this->travel(NodeRole::StaleClaimSeconds + 1)->seconds();
        app(AddNodeRoleAction::class)->execute($node, RoleName::Metrics, convergeExisting: true);

        expect($baseline->convergedRoles)->toBe([RoleName::Metrics])
            ->and($assignment->refresh()->status)->toBe(LifecycleStatus::Active);
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

    it('runs the baseline while the role assignment is provisioning', function (): void {
        $baseline = new AddNodeRoleBaselineFake;
        app()->instance(RoleBaselineConverger::class, $baseline);
        $node = add_role_node();

        $result = app(AddNodeRoleAction::class)->execute($node, RoleName::AppDev);

        expect($baseline->observedStatuses)
            ->toBe([LifecycleStatus::Provisioning])
            ->and($result['assignment']->status)
            ->toBe(LifecycleStatus::Active);
    });

    it('fails the role with node.lock_lost when its operation lost the Node lock', function (): void {
        $baseline = new AddNodeRoleBaselineFake;
        $baseline->failure = new ResourceOperationException(
            'metrics.service_rollback_failed',
            'Service metrics recovery did not complete.',
            502,
            NodeLockLoss::exception('node-role:id:1'),
        );
        app()->instance(RoleBaselineConverger::class, $baseline);
        $node = add_role_node();

        expect(fn () => app(AddNodeRoleAction::class)->execute($node, RoleName::AppProd))
            ->toThrow(function (NodeRoleOperationException $exception): void {
                expect($exception->errorCode)->toBe('node_role.convergence_failed')
                    ->and($exception->underlyingErrorCode)->toBe(NodeLockLoss::ErrorCode);
            });

        expect($node->roles()->sole()->error_code)->toBe(NodeLockLoss::ErrorCode);
    });

    it('creates and converges a mutable role outside a database transaction', function (): void {
        expect(class_exists(AddNodeRoleAction::class))->toBeTrue();

        $baseline = new AddNodeRoleBaselineFake;
        app()->instance(RoleBaselineConverger::class, $baseline);
        $node = add_role_node();

        $ambientTransactionLevel = DB::transactionLevel();
        $result = app(AddNodeRoleAction::class)->execute($node, RoleName::AppDev);

        expect($result['created'])
            ->toBeTrue()
            ->and($result['assignment']->status)
            ->toBe(LifecycleStatus::Active)
            ->and($baseline->convergedRoles)
            ->toBe([RoleName::AppDev])
            ->and($baseline->observedStatuses)
            ->toBe([LifecycleStatus::Provisioning])
            ->and($baseline->transactionLevels)
            ->toBe([$ambientTransactionLevel])
            ->and($node->refresh()->status)
            ->toBe(LifecycleStatus::Active);
    });

    it('rejects a mutable role when the node is not active before baseline effects', function (): void {
        $baseline = new AddNodeRoleBaselineFake;
        app()->instance(RoleBaselineConverger::class, $baseline);
        $node = add_role_node(LifecycleStatus::Provisioning);

        expect(fn () => app(AddNodeRoleAction::class)->execute($node, RoleName::AppDev))
            ->toThrow(RoleAssignmentException::class);

        expect($baseline->convergedRoles)
            ->toBeEmpty()
            ->and($node->roles()->exists())
            ->toBeFalse();
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

    it('rejects protected roles before baseline effects', function (RoleName $role): void {
        $baseline = new AddNodeRoleBaselineFake;
        app()->instance(RoleBaselineConverger::class, $baseline);
        $node = add_role_node();

        expect(fn () => app(AddNodeRoleAction::class)->execute($node, $role))
            ->toThrow(RoleAssignmentException::class);

        expect($baseline->convergedRoles)
            ->toBeEmpty()
            ->and($node->roles()->exists())
            ->toBeFalse();
    })->with([
        'VPN' => RoleName::Vpn,
    ]);

    it('rejects an existing assignment unless convergence is explicit', function (): void {
        $baseline = new AddNodeRoleBaselineFake;
        app()->instance(RoleBaselineConverger::class, $baseline);
        $node = add_role_node();
        $assignment = $node->roles()->create([
            'role' => RoleName::AppDev,
            'status' => LifecycleStatus::Active,
        ]);

        expect(fn () => app(AddNodeRoleAction::class)->execute($node, RoleName::AppDev))
            ->toThrow(RoleAssignmentException::class);

        expect($baseline->convergedRoles)
            ->toBeEmpty()
            ->and($assignment->refresh()->status)
            ->toBe(LifecycleStatus::Active);
    });

    it('reconverges eligible existing assignments and clears old failures', function (
        LifecycleStatus $status,
        ?string $failedStep,
    ): void {
        $baseline = new AddNodeRoleBaselineFake;
        app()->instance(RoleBaselineConverger::class, $baseline);
        $node = add_role_node();
        $assignment = $node->roles()->create([
            'role' => RoleName::AppDev,
            'status' => $status,
            'failed_step' => $failedStep,
            'error_code' => $failedStep === null ? null : 'app-dev.caddy_config_failed',
        ]);

        $ambientTransactionLevel = DB::transactionLevel();
        $result = app(AddNodeRoleAction::class)->execute($node, RoleName::AppDev, convergeExisting: true);

        expect($result['created'])
            ->toBeFalse()
            ->and($result['assignment']->is($assignment))
            ->toBeTrue()
            ->and($assignment->refresh()->status)
            ->toBe(LifecycleStatus::Active)
            ->and($assignment->failed_step)
            ->toBeNull()
            ->and($assignment->error_code)
            ->toBeNull()
            ->and($baseline->transactionLevels)
            ->toBe([$ambientTransactionLevel]);
    })->with([
        'active assignment' => [LifecycleStatus::Active, null],
        'failed convergence' => [LifecycleStatus::Failed, 'converge:caddy-config'],
    ]);

    it('rejects assignments that are busy or failed during removal', function (
        LifecycleStatus $status,
        ?string $failedStep,
    ): void {
        $baseline = new AddNodeRoleBaselineFake;
        app()->instance(RoleBaselineConverger::class, $baseline);
        $node = add_role_node();
        $assignment = $node->roles()->create([
            'role' => RoleName::AppDev,
            'status' => $status,
            'failed_step' => $failedStep,
            'error_code' => $failedStep === null ? null : 'app-dev.remove_failed',
        ]);

        expect(fn () => app(AddNodeRoleAction::class)->execute($node, RoleName::AppDev, convergeExisting: true))
            ->toThrow(RoleAssignmentException::class);

        expect($baseline->convergedRoles)
            ->toBeEmpty()
            ->and($assignment->refresh()->status)
            ->toBe($status)
            ->and($assignment->failed_step)
            ->toBe($failedStep);
    })->with([
        'provisioning assignment' => [LifecycleStatus::Provisioning, null],
        'removing assignment' => [LifecycleStatus::Removing, null],
        'failed removal' => [LifecycleStatus::Failed, 'remove:caddy-config'],
    ]);

    it('rejects same-node role conflicts before baseline effects', function (): void {
        $baseline = new AddNodeRoleBaselineFake;
        app()->instance(RoleBaselineConverger::class, $baseline);
        $node = add_role_node();
        $node->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);

        expect(fn () => app(AddNodeRoleAction::class)->execute($node, RoleName::AppDev))
            ->toThrow(RoleAssignmentException::class);

        expect($baseline->convergedRoles)->toBeEmpty();
    });

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

    it('stores a namespaced convergence failure while the node stays active', function (): void {
        $baseline = new AddNodeRoleBaselineFake;
        $baseline->failure = new RuntimeConvergenceException(
            step: 'caddy-config',
            errorCode: 'app-dev.caddy_config_failed',
            message: 'Caddy failed.',
        );
        app()->instance(RoleBaselineConverger::class, $baseline);
        $node = add_role_node();
        $ambientTransactionLevel = DB::transactionLevel();

        expect(fn () => app(AddNodeRoleAction::class)->execute($node, RoleName::AppDev))
            ->toThrow(function (NodeRoleOperationException $exception): void {
                expect($exception->step)
                    ->toBe('converge:caddy-config')
                    ->and($exception->errorCode)
                    ->toBe('node_role.convergence_failed')
                    ->and($exception->underlyingErrorCode)
                    ->toBe('app-dev.caddy_config_failed');
            });

        $assignment = $node->roles()->sole();

        expect($node->refresh()->status)
            ->toBe(LifecycleStatus::Active)
            ->and($assignment->status)
            ->toBe(LifecycleStatus::Failed)
            ->and($assignment->failed_step)
            ->toBe('converge:caddy-config')
            ->and($assignment->error_code)
            ->toBe('app-dev.caddy_config_failed')
            ->and($baseline->transactionLevels)
            ->toBe([$ambientTransactionLevel]);
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
