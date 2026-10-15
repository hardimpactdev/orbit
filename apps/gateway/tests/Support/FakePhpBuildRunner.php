<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\GitHub\GitReadEnvironment;
use App\Domain\RemotePrograms\ProgramResult;
use App\Domain\RemotePrograms\RemoteProgram;
use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Models\Node;
use Closure;
use LogicException;
use SensitiveParameter;

/** Answers php.install-build like a Node already on the recipe build, and records each input. */
final class FakePhpBuildRunner implements RemoteProgramRunner
{
    /** @var list<array<string, mixed>> */
    public array $inputs = [];

    /** @param array<string, array{0: string, 1: array<string, mixed>}> $answers  outcome and data by mode */
    public function __construct(public array $answers = []) {}

    public function run(
        Node $node,
        RemoteProgram $program,
        array $input = [],
        #[SensitiveParameter] ?GitReadEnvironment $gitRead = null,
        ?float $timeoutSeconds = null,
        ?Closure $cancelled = null,
    ): ProgramResult {
        if ($program->name !== 'php.install-build') {
            throw new LogicException("Unexpected remote program [{$program->name}].");
        }
        $this->inputs[] = $input;
        $mode = is_string($input['mode'] ?? null) ? $input['mode'] : 'apply';
        [$outcome, $data] = $this->answers[$mode] ?? ($mode === 'plan'
            ? ['planned', ['pending' => [], 'extras' => []]]
            : ['current', ['changed' => [], 'purged' => [], 'restarted' => [], 'deferred' => [], 'stale_masters' => []]]);

        return new ProgramResult($outcome, $data, '');
    }
}
