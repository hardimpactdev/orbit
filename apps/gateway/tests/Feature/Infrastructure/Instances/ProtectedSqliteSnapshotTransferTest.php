<?php

declare(strict_types=1);

use App\Domain\Instances\Sqlite\SqliteSeedPlacement;
use App\Infrastructure\AgentCommands\AgentBinaryInput;
use App\Infrastructure\AgentCommands\AgentBinaryOutput;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Instances\ProtectedSqliteSnapshotTransfer;
use App\Models\Node;
use Tests\Support\ProcessInput;

it('streams the verified snapshot through both agents and closes its private output sink', function (): void {
    $bytes = "SQLite format 3\0binary\xffdata";
    $agents = new SnapshotTransferAgents($bytes);
    $source = new SqliteSeedPlacement(1, 'development', '/apps/source', 'orbit', new Node(['name' => 'source']));
    $target = new SqliteSeedPlacement(2, 'production', '/home/instance-2', 'instance-2', new Node(['name' => 'target']));
    $digest = hash('sha256', $bytes);

    new ProtectedSqliteSnapshotTransfer($agents)->transfer($source, '/tmp/source-snapshot', $target, '/tmp/prepared-target', strlen($bytes), $digest);

    expect($agents->calls)->toBe([
        ['node' => 'source', 'command' => 'instances.read-sqlite-snapshot', 'input' => ['path' => '/tmp/source-snapshot', 'bytes' => strlen($bytes), 'sha256' => $digest]],
        ['node' => 'target', 'command' => 'instances.receive-sqlite-snapshot', 'input' => ['path' => '/tmp/prepared-target', 'base' => '/home/instance-2', 'bytes' => strlen($bytes), 'sha256' => $digest]],
    ])->and($agents->received)->toBe($bytes)
        ->and($agents->output?->write('after-close'))->toBeFalse();
});

it('refuses incomplete or changed data before upload and redacts native failures', function (string $failure): void {
    $expected = 'original snapshot bytes';
    $agents = new SnapshotTransferAgents(match ($failure) {
        'short' => 'short', 'digest' => str_repeat('x', strlen($expected)), default => $expected,
    }, $failure === 'native');
    $source = new SqliteSeedPlacement(1, 'development', '/apps/source', 'orbit', new Node(['name' => 'source']));
    $target = new SqliteSeedPlacement(2, 'production', '/home/instance-2', 'instance-2', new Node(['name' => 'target']));

    expect(fn () => new ProtectedSqliteSnapshotTransfer($agents)->transfer($source, '/tmp/source', $target, '/tmp/target', strlen($expected), hash('sha256', $expected)))
        ->toThrow(function (RuntimeException $error): void {
            expect($error->getMessage())->toBe('The protected SQLite snapshot transfer failed safely.')
                ->and($error->getPrevious())->toBeNull();
        });
    expect($agents->calls)->toHaveCount(1)->and($agents->received)->toBeNull()
        ->and($agents->output?->write('after-close'))->toBeFalse();
})->with(['short', 'digest', 'native']);

final class SnapshotTransferAgents implements AgentCommandRunner
{
    /** @var list<array{node: string, command: string, input: array<string, mixed>}> */
    public array $calls = [];

    public ?string $received = null;

    public ?AgentBinaryOutput $output = null;

    public function __construct(private readonly string $bytes, private readonly bool $fail = false) {}

    public function run(Node $node, string $command, array $input = [], ?float $deadlineSeconds = null, ?AgentBinaryOutput $output = null, ?AgentBinaryInput $binaryInput = null): array
    {
        $this->calls[] = ['node' => $node->name, 'command' => $command, 'input' => $input];
        if ($command === 'instances.read-sqlite-snapshot') {
            $this->output = $output;
            if ($this->fail) {
                throw new RuntimeException('private database diagnostic');
            }
            if (! $output?->write($this->bytes)) {
                throw new LogicException('Missing or full snapshot sink.');
            }
        } else {
            $input = $binaryInput?->envelope('');
            if ($input === null) {
                throw new LogicException('Missing snapshot body.');
            }
            $this->received = ProcessInput::contents($input);
        }

        return ['bytes' => strlen($this->bytes), 'sha256' => hash('sha256', $this->bytes)];
    }

    public function install(Node $node): bool
    {
        throw new LogicException('No installation is requested by the transfer.');
    }
}
