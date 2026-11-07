<?php

declare(strict_types=1);

use App\Actions\AppDev\AdmitPrivateDnsPublicationAction;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\SwampPrivateDnsManager;
use App\Infrastructure\Swamp\SwampDispatch;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    SwampNodeFixture::ensure();
});

/** An active vpn Node whose SSH identity is pinned, so a publication entry can name it. */
function vpnNodeForPublication(string $name = 'vpn', string $address = '10.44.0.1'): Node
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => $address, 'wireguard_ip' => $address, 'user' => 'orbit', 'wireguard_public_key' => $name.'-key',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => $name.'-host-key', 'ssh_host_fingerprint' => 'SHA256:'.$name]));
    $node->roles()->create(['role' => RoleName::Vpn, 'status' => 'active']);

    return $node;
}

/**
 * Swamp stand-in: each started run ends with the next status of the list, then with Succeeded.
 *
 * @param  list<OperationStatus>  $outcomes
 * @return ArrayObject<int, int> The ids of the started Operations, in order.
 */
function swampEndsPublications(array $outcomes = []): ArrayObject
{
    $started = new ArrayObject;
    app()->instance(SwampDispatch::class, new class($started, $outcomes)
    {
        /** @param list<OperationStatus> $outcomes */
        public function __construct(private readonly ArrayObject $started, private array $outcomes) {}

        public function start(Operation $operation): void
        {
            $this->started[] = $operation->id;
            app(OperationStore::class)->change($operation, ['status' => array_shift($this->outcomes) ?? OperationStatus::Succeeded,
                'finished_at' => now(), 'lock_key' => null]);
        }
    });

    return $started;
}

describe('SwampPrivateDnsManager', function (): void {
    it('publishes nothing while no vpn Node exists, so a baseline that runs before it does not fail', function (): void {
        $started = swampEndsPublications();

        app(SwampPrivateDnsManager::class)->converge();

        expect($started)->toHaveCount(0)->and(Operation::query()->where('kind', 'dns.publish')->count())->toBe(0);
    });

    it('admits one dns.publish operation for each vpn Node and waits for its end', function (): void {
        $started = swampEndsPublications();
        $first = vpnNodeForPublication('vpn-a', '10.44.0.1');
        $second = vpnNodeForPublication('vpn-b', '10.44.0.8');

        app(SwampPrivateDnsManager::class)->converge();

        $operations = Operation::query()->where('kind', 'dns.publish')->orderBy('node_id')->get();

        expect($started)->toHaveCount(2)
            ->and($operations->pluck('node_id')->all())->toBe([$first->id, $second->id])
            ->and($operations->every(fn (Operation $operation): bool => $operation->status === OperationStatus::Succeeded && $operation->target_type === 'private_dns'))->toBeTrue()
            ->and($operations[0]->input['intent']['node_id'])->toBe($first->id)
            ->and($operations[0]->input['intent']['dns'])->toHaveKeys(['revision', 'listen', 'tlds', 'records']);
    });

    it('fails with app-dev.dns_config_failed when the publication does not succeed', function (OperationStatus $status): void {
        swampEndsPublications([$status]);
        vpnNodeForPublication();

        expect(fn () => app(SwampPrivateDnsManager::class)->converge())
            ->toThrow(fn (RuntimeConvergenceException $exception): bool => $exception->errorCode === 'app-dev.dns_config_failed');
    })->with([OperationStatus::Failed, OperationStatus::Interrupted, OperationStatus::Cancelled]);

    it('fails when the publication has not ended within the timeout', function (): void {
        app()->instance(SwampDispatch::class, new class
        {
            public function start(Operation $operation): void {}
        });
        vpnNodeForPublication();

        expect(fn () => new SwampPrivateDnsManager(app(AdmitPrivateDnsPublicationAction::class), timeoutSeconds: 0)->converge())
            ->toThrow(fn (RuntimeConvergenceException $exception): bool => $exception->errorCode === 'app-dev.dns_config_failed'
                && str_contains($exception->getMessage(), 'did not finish'));
    });

    it('waits for the publication that holds the Node, then renders and publishes again', function (): void {
        $node = vpnNodeForPublication();
        // The earlier publication keeps the Node: it is admitted and never started.
        app()->instance(SwampDispatch::class, new class
        {
            public function start(Operation $operation): void {}
        });
        $holder = app(AdmitPrivateDnsPublicationAction::class)->execute($node);
        $polls = 0;
        $listening = true;
        // Its end arrives while the second caller polls.
        Operation::retrieved(function (Operation $operation) use ($holder, &$polls, &$listening): void {
            if ($listening && $operation->id === $holder->id && ! $operation->status->terminal() && ++$polls === 2) {
                app(OperationStore::class)->change(Operation::query()->findOrFail($holder->id), ['status' => OperationStatus::Succeeded, 'finished_at' => now(), 'lock_key' => null]);
            }
        });
        $started = swampEndsPublications();

        app(SwampPrivateDnsManager::class)->converge();
        $listening = false;

        $operations = Operation::query()->where('kind', 'dns.publish')->orderBy('id')->get();

        expect($operations)->toHaveCount(2)
            ->and($started->getArrayCopy())->toBe([$operations[1]->id])
            ->and($operations[1]->status)->toBe(OperationStatus::Succeeded)
            ->and((int) $operations[1]->input['intent']['dns']['revision'])->toBeGreaterThan((int) $operations[0]->input['intent']['dns']['revision']);
    });
});

describe('AdmitPrivateDnsPublicationAction', function (): void {
    it('records a fresh complete render with the connection of the vpn Node under one lock for the Node', function (): void {
        swampEndsPublications();
        $node = vpnNodeForPublication();

        $operation = app(AdmitPrivateDnsPublicationAction::class)->execute($node, 'operator', 'request-1');

        $intent = $operation->input['intent'];

        expect($operation->kind)->toBe('dns.publish')
            ->and($operation->target_type)->toBe('private_dns')
            ->and($operation->target_id)->toBe($node->id)
            ->and($operation->node_id)->toBe($node->id)
            ->and($intent)->toHaveKeys(['node_id', 'control_node_id', 'connection', 'host_keys', 'dns'])
            ->and($intent['node_id'])->toBe($node->id)
            ->and($intent['connection']['nodeHost'])->toBe('10.44.0.1')
            ->and($intent['host_keys'])->toBe(['ssh-ed25519 vpn-host-key'])
            ->and($intent['dns']['listen'])->toBe(['10.44.0.1'])
            ->and($intent)->not->toHaveKeys(['before', 'alternatives', 'records', 'catalog', 'digest']);
    });

    it('refuses a second publication for the same Node while the first one runs', function (): void {
        app()->instance(SwampDispatch::class, new class
        {
            public function start(Operation $operation): void {}
        });
        $node = vpnNodeForPublication();
        $first = app(AdmitPrivateDnsPublicationAction::class)->execute($node);

        expect(fn () => app(AdmitPrivateDnsPublicationAction::class)->execute($node))
            ->toThrow(fn (ResourceOperationException $exception): bool => $exception->errorCode === 'operation.target_busy');

        expect($first->refresh()->lock_key)->toBe(AdmitPrivateDnsPublicationAction::lockKey($node));
    });
});
