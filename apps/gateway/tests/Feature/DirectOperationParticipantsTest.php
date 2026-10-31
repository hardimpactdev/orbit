<?php

declare(strict_types=1);

use App\Domain\Logs\LogRedactor;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationPrimitive;
use App\Domain\Operations\DirectOperationPrivateBindings;
use App\Domain\Operations\DirectOperationPrivateMaterial;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationPublicView;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationSecretOutputs;
use App\Domain\Operations\OperationSecretOutputStore;
use App\Domain\Operations\OperationSecrets;
use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Synthetic family exercises the shared HTTP contract without a family-specific relay. */
final class ParticipantProjection implements DirectOperationProjection, DirectOperationPublicView
{
    public function input(Operation $operation): array
    {
        return ['root_node_id' => $operation->node_id];
    }

    public function assertPublicParticipant(Operation $operation, int $callerNodeId): void
    {
        if ($callerNodeId === ($operation->input['denied_public_node_id'] ?? null)) {
            throw new ResourceOperationException('operation.executor_forbidden', 'This participant has no public view.', 403);
        }
    }

    public function publicInput(Operation $operation, int $callerNodeId): array
    {
        return ['root_node_id' => $operation->node_id, 'caller_node_id' => $callerNodeId, 'fresh_public_value' => $operation->input['fresh_public_value']];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return app(DirectOperation::class)->project($operation, $data, ['applied' => true]);
    }
}

final class ParticipantPrimitive implements DirectOperationPrimitive
{
    public int $calls = 0;

    public function execute(Operation $operation): array
    {
        $this->calls++;

        return ['certificate' => ['pem' => 'public-certificate', 'node_id' => $operation->node_id]];
    }
}

final readonly class ParticipantMaterial implements OperationSecretSource
{
    public function __construct(private string $field) {}

    public function name(): string
    {
        return 'fixture.'.$this->field;
    }

    public function targetTypes(): array
    {
        return ['node'];
    }

    public function ownerId(Operation $operation): ?int
    {
        return app(DirectOperationPrivateBindings::class)->ownerNode($operation, $this->field);
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        if ($id !== $this->ownerId($operation) || $parameter !== $this->field) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The material has another owner.', 422);
        }

        return new OperationSecretValue(['token' => 'private-'.$this->field, 'owner_node_id' => $id]);
    }
}

final class ParticipantOutput implements OperationSecretOutputStore
{
    /** @var list<array{owner:int,value:mixed}> */
    public array $writes = [];

    public function name(): string
    {
        return 'fixture.credential';
    }

    public function targetTypes(): array
    {
        return ['node'];
    }

    public function ownerId(Operation $operation): ?int
    {
        return app(DirectOperationPrivateBindings::class)->ownerNode($operation, 'credential');
    }

    public function store(Operation $operation, int $ownerId, #[SensitiveParameter] mixed $value): void
    {
        $this->writes[] = ['owner' => $ownerId, 'value' => $value];
    }
}

function participantIntent(TestCase $test): object
{
    Http::preventStrayRequests();
    $root = Node::query()->create(['name' => 'root', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.1', 'public_ssh_host' => '192.0.2.1']);
    NodeRole::query()->create(['node_id' => $root->id, 'role' => 'gateway', 'status' => 'active']);
    $nodes = [];
    foreach (['issuer', 'host', 'guest', 'foreign'] as $offset => $name) {
        $node = Node::query()->create(['name' => $name, 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.'.($offset + 2), 'public_ssh_host' => '192.0.2.'.($offset + 2)]);
        $node->accessibleNodes()->attach($root);
        $nodes[$name] = $node;
    }
    $run = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $operation = Operation::query()->create(['kind' => 'fixture.participants', 'target_type' => 'node', 'target_id' => $root->id, 'node_id' => $root->id, 'status' => OperationStatus::Queued, 'sequence' => 1,
        'state' => ['fixture_writer' => 'direct', 'swamp_run_id' => $run], 'input' => [
            'fresh_public_value' => 'initial', 'participant_node_ids' => [$root->id, $nodes['issuer']->id, $nodes['host']->id, $nodes['guest']->id],
            'credential' => ['$secret' => 'fixture.credential', 'id' => $nodes['guest']->id], 'root_secret' => ['$secret' => 'fixture.root_secret', 'id' => $root->id],
            'private_bindings' => ['credential' => ['owner_node_id' => $nodes['guest']->id, 'read_node_ids' => [$nodes['host']->id], 'write_node_ids' => [$nodes['issuer']->id]]],
        ]]);
    $reflection = new ReflectionClass(DirectOperation::class);
    $direct = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('definitions')->setValue($direct, ['fixture.participants' => ['workflow' => 'operation-fixture', 'writer_config' => 'swamp.direct_writers', 'writer_state' => 'fixture_writer', 'projection' => ParticipantProjection::class,
        'primitive' => ParticipantPrimitive::class, 'private_material' => ['credential' => 'fixture.credential', 'root_secret' => 'fixture.root_secret'], 'secret_outputs' => ['credential' => 'fixture.credential']]]);
    app()->instance(DirectOperation::class, $direct);
    app()->forgetInstance(DirectOperationPrivateBindings::class);
    app()->forgetInstance(DirectOperationPrivateMaterial::class);
    app()->instance(OperationSecrets::class, new OperationSecrets([new ParticipantMaterial('credential'), new ParticipantMaterial('root_secret')], app(LogRedactor::class)));
    $output = new ParticipantOutput;
    app()->instance(OperationSecretOutputs::class, new OperationSecretOutputs([$output], app(OperationKinds::class)));
    $primitive = new ParticipantPrimitive;
    app()->instance(ParticipantPrimitive::class, $primitive);
    config(['dependency_detection.services_node_id' => $nodes['foreign']->id, 'operation_executor.worker_node_ids' => []]);
    $test->withServerVariables(['REMOTE_ADDR' => $root->wireguard_ip]);

    return (object) ['root' => $root, ...$nodes, 'operation' => $operation, 'run' => $run, 'path' => '/api/v1/direct-operations/'.$operation->id, 'output' => $output, 'primitive' => $primitive];
}

describe('public participants', function (): void {
    it('uses authenticated participants and fresh safe public input on the same root run', function (): void {
        $fixture = participantIntent($this);
        $fixture->operation->update(['input' => [...$fixture->operation->input, 'fresh_public_value' => 'updated']]);

        $this->withServerVariables(['REMOTE_ADDR' => $fixture->host->wireguard_ip])->getJson($fixture->path.'?run_id='.$fixture->run)
            ->assertOk()->assertJsonPath('data.input', ['root_node_id' => $fixture->root->id, 'caller_node_id' => $fixture->host->id, 'fresh_public_value' => 'updated'])->assertDontSee('private-credential');

        Http::assertNothingSent();
    });

    it('returns 403 for foreign Nodes including configured Services', function (): void {
        $fixture = participantIntent($this);

        $this->withServerVariables(['REMOTE_ADDR' => $fixture->foreign->wireguard_ip])->getJson($fixture->path.'?run_id='.$fixture->run)
            ->assertForbidden()->assertJsonPath('error.code', 'operation.executor_forbidden');
    });

    it('enforces the family public participant assertion', function (): void {
        $fixture = participantIntent($this);
        $fixture->operation->update(['input' => [...$fixture->operation->input, 'denied_public_node_id' => $fixture->host->id]]);

        $this->withServerVariables(['REMOTE_ADDR' => $fixture->host->wireguard_ip])->getJson($fixture->path.'?run_id='.$fixture->run)->assertForbidden();
    });

    it('returns 422 for caller supplied public participant selectors', function (): void {
        $fixture = participantIntent($this);

        $this->withServerVariables(['REMOTE_ADDR' => $fixture->host->wireguard_ip])->getJson($fixture->path.'?run_id='.$fixture->run.'&caller_node_id='.$fixture->guest->id)
            ->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
    });

    it('keeps result callbacks restricted to the root Node', function (): void {
        $fixture = participantIntent($this);
        $body = ['run_id' => $fixture->run, 'status' => 'succeeded', 'started_at' => '2026-10-30T18:30:00.000Z', 'finished_at' => '2026-10-30T18:30:01.000Z', 'result' => []];

        $this->withServerVariables(['REMOTE_ADDR' => $fixture->host->wireguard_ip])->postJson($fixture->path.'/result', $body)->assertForbidden();
        expect($fixture->operation->fresh()->status)->toBe(OperationStatus::Queued);
        $this->withServerVariables(['REMOTE_ADDR' => $fixture->root->wireguard_ip])->postJson($fixture->path.'/result', $body)->assertOk()->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.result', ['applied' => true]);
        expect($fixture->operation->fresh()->status)->toBe(OperationStatus::Succeeded);
    });
});

describe('private field bindings', function (): void {
    it('refuses an owner lookup for a field outside the fixed declarations', function (): void {
        $fixture = participantIntent($this);

        expect(fn (): int => app(DirectOperationPrivateBindings::class)->ownerNode($fixture->operation, 'undeclared'))
            ->toThrow(ResourceOperationException::class, 'Private bindings must name declared fields and admitted Nodes.');
    });

    it('reads only the exact delegated field for the admitted bootstrap host and guest owner', function (): void {
        $fixture = participantIntent($this);
        $before = $fixture->operation->fresh()->getAttributes();

        $this->withServerVariables(['REMOTE_ADDR' => $fixture->host->wireguard_ip])->getJson($fixture->path.'/private-material?run_id='.$fixture->run)
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data', ['credential' => ['token' => 'private-credential', 'owner_node_id' => $fixture->guest->id]]);
        $this->withServerVariables(['REMOTE_ADDR' => $fixture->root->wireguard_ip])->getJson($fixture->path.'/private-material?run_id='.$fixture->run)
            ->assertOk()->assertJsonPath('data', ['root_secret' => ['token' => 'private-root_secret', 'owner_node_id' => $fixture->root->id]]);
        expect($fixture->operation->fresh()->getAttributes())->toBe($before);
    });

    it('writes only the declared credential for the admitted issuer and guest owner', function (): void {
        $fixture = participantIntent($this);
        $before = $fixture->operation->fresh()->getAttributes();

        $this->withServerVariables(['REMOTE_ADDR' => $fixture->issuer->wireguard_ip])->postJson($fixture->path.'/secret-output', ['run_id' => $fixture->run, 'outputs' => ['credential' => ' exact private bytes ']])
            ->assertOk()->assertJsonPath('data.stored.credential', ['$secret' => 'fixture.credential', 'id' => $fixture->guest->id])->assertDontSee('exact private bytes');

        expect($fixture->output->writes)->toBe([['owner' => $fixture->guest->id, 'value' => ' exact private bytes ']]);
        expect($fixture->operation->fresh()->getAttributes())->toBe($before);
    });

    it('returns 403 for admitted Nodes without the exact read or write binding', function (string $actor, string $endpoint): void {
        $fixture = participantIntent($this);
        $this->withServerVariables(['REMOTE_ADDR' => $fixture->{$actor}->wireguard_ip]);

        if ($endpoint === 'read') {
            $this->getJson($fixture->path.'/private-material?run_id='.$fixture->run)->assertForbidden()->assertJsonPath('error.code', 'operation.executor_forbidden');
        } else {
            $this->postJson($fixture->path.'/secret-output', ['run_id' => $fixture->run, 'outputs' => ['credential' => 'refused-private']])->assertForbidden()->assertJsonPath('error.code', 'operation.executor_forbidden');
        }

        expect($fixture->output->writes)->toBe([]);
    })->with([['issuer', 'read'], ['guest', 'read'], ['host', 'write'], ['guest', 'write'], ['root', 'write'], ['foreign', 'read'], ['foreign', 'write']]);

    it('returns 422 for undeclared output fields without storing any values', function (): void {
        $fixture = participantIntent($this);

        $this->withServerVariables(['REMOTE_ADDR' => $fixture->issuer->wireguard_ip])->postJson($fixture->path.'/secret-output', ['run_id' => $fixture->run, 'outputs' => ['credential' => 'private-value', 'arbitrary' => 'foreign']])
            ->assertUnprocessable()->assertJsonPath('error.code', 'operation.execution_forbidden');

        expect($fixture->output->writes)->toBe([]);
    });

    it('returns 409 for a different native run on delegated reads and writes', function (): void {
        $fixture = participantIntent($this);
        $foreignRun = '20e8138a-cf7f-4c6c-85fd-ff561b575ac8';

        $this->withServerVariables(['REMOTE_ADDR' => $fixture->host->wireguard_ip])->getJson($fixture->path.'/private-material?run_id='.$foreignRun)->assertConflict()->assertJsonPath('error.code', 'operation.run_mismatch');
        $this->withServerVariables(['REMOTE_ADDR' => $fixture->issuer->wireguard_ip])->postJson($fixture->path.'/secret-output', ['run_id' => $foreignRun, 'outputs' => ['credential' => 'private-value']])->assertConflict();
        expect($fixture->output->writes)->toBe([]);
    });

    it('returns 422 for invalid immutable field bindings', function (string $invalid): void {
        $fixture = participantIntent($this);
        $input = $fixture->operation->input;
        match ($invalid) {
            'owner' => $input['private_bindings']['credential']['owner_node_id'] = $fixture->foreign->id,
            'reader' => $input['private_bindings']['credential']['read_node_ids'] = [$fixture->foreign->id],
            'writer' => $input['private_bindings']['credential']['write_node_ids'] = [$fixture->foreign->id],
            'field' => $input['private_bindings']['undeclared'] = $input['private_bindings']['credential'],
            'participant' => $input['participant_node_ids'][] = 0,
        };
        $fixture->operation->update(['input' => $input]);

        $this->withServerVariables(['REMOTE_ADDR' => $fixture->host->wireguard_ip])->getJson($fixture->path.'/private-material?run_id='.$fixture->run)
            ->assertUnprocessable()->assertJsonPath('error.code', 'operation.private_binding_invalid');
    })->with(['owner', 'reader', 'writer', 'field', 'participant']);
});

describe('fixed primitive transport', function (): void {
    it('returns the typed primitive result directly without Kit result filtering', function (): void {
        $fixture = participantIntent($this);

        $this->postJson($fixture->path.'/primitive', ['run_id' => $fixture->run])->assertOk()->assertJsonPath('data', ['certificate' => ['pem' => 'public-certificate', 'node_id' => $fixture->root->id]]);

        expect($fixture->primitive->calls)->toBe(1);
    });

    it('requires only run identity and refuses caller supplied primitive input', function (): void {
        $fixture = participantIntent($this);

        $this->postJson($fixture->path.'/primitive', ['run_id' => $fixture->run, 'csr' => 'forged-public-csr'])->assertUnprocessable();
        $this->postJson($fixture->path.'/primitive', ['run_id' => '20e8138a-cf7f-4c6c-85fd-ff561b575ac8'])->assertConflict();
        $this->withServerVariables(['REMOTE_ADDR' => $fixture->issuer->wireguard_ip])->postJson($fixture->path.'/primitive', ['run_id' => $fixture->run])->assertForbidden();
        expect($fixture->primitive->calls)->toBe(0);
    });
});

describe('native failure callback', function (): void {
    it('records a fixed failure without family projection or Gateway steps and replays idempotently', function (): void {
        $fixture = participantIntent($this);
        $this->postJson($fixture->path.'/failure', ['run_id' => $fixture->run])->assertOk()->assertJsonPath('data.status', 'failed');
        $operation = $fixture->operation->refresh();
        expect($operation->error_code)->toBe('operation.native_failed');
        expect($operation->result)->toBe([]);
        expect(Schema::hasTable('operation_steps'))->toBeFalse();
        $sequence = $operation->sequence;
        $this->postJson($fixture->path.'/failure', ['run_id' => $fixture->run])->assertOk();
        expect($operation->refresh()->sequence)->toBe($sequence);
        Http::assertNothingSent();
    });

    it('refuses foreign participants, mismatched runs and caller-supplied diagnostics', function (): void {
        $fixture = participantIntent($this);
        $this->withServerVariables(['REMOTE_ADDR' => $fixture->host->wireguard_ip])->postJson($fixture->path.'/failure', ['run_id' => $fixture->run])->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => $fixture->root->wireguard_ip])->postJson($fixture->path.'/failure', ['run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92677'])->assertConflict();
        $this->postJson($fixture->path.'/failure', ['run_id' => $fixture->run, 'error_code' => 'private.secret'])->assertUnprocessable();
        expect($fixture->operation->refresh()->status)->toBe(OperationStatus::Queued);
    });

    it('preserves a committed native success when failure delivery arrives late', function (): void {
        $fixture = participantIntent($this);
        $fixture->operation->update(['status' => OperationStatus::Succeeded, 'result' => ['ready' => true]]);
        $this->postJson($fixture->path.'/failure', ['run_id' => $fixture->run])->assertOk()->assertJsonPath('data.status', 'succeeded');
        expect($fixture->operation->refresh()->result)->toBe(['ready' => true]);
    });
});
