<?php

declare(strict_types=1);

use App\Commands\LocalOnlyCommand;
use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Console\Kernel;
use Orbit\Core\Http\JsonEnvelope;

class TestLocalOnlyCommand extends LocalOnlyCommand
{
    protected $signature = 'test:local-only-command {--json}';

    protected $description = 'Test LocalOnlyCommand';

    public string $action = 'success';

    public function handle(): int
    {
        return match ($this->action) {
            'success' => $this->renderSuccess(['status' => 'local']),
            'success_empty' => $this->renderSuccess(),
            'failure' => $this->renderFailure('local_error', 'Something went wrong locally.', ['field' => 'x']),
            default => self::SUCCESS,
        };
    }
}

/**
 * @param  array<string, mixed>  $params
 * @return array{int, string}
 */
function runLocalOnlyCommand(object $test, string $action = 'success', array $params = []): array
{
    $test->mockConsoleOutput = false;
    app()->offsetUnset(OutputStyle::class);

    $command = new TestLocalOnlyCommand;
    $command->action = $action;

    app(Kernel::class)->registerCommand($command);

    $exitCode = $test->artisan('test:local-only-command', $params);

    return [$exitCode, trim(app(Kernel::class)->output())];
}

describe('LocalOnlyCommand', function (): void {
    it('renderSuccess produces a canonical envelope in JSON mode', function (): void {
        [$exitCode, $output] = runLocalOnlyCommand($this, 'success', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(0)
            ->and($decoded)
            ->toBe(JsonEnvelope::success(['status' => 'local']));
    });

    it('renderSuccess produces key:value lines in human mode', function (): void {
        [$exitCode, $output] = runLocalOnlyCommand($this, 'success');

        expect($exitCode)->toBe(0)->and($output)->toBe('status: local');
    });

    it('renderSuccess with empty data outputs OK in human mode', function (): void {
        [$exitCode, $output] = runLocalOnlyCommand($this, 'success_empty');

        expect($exitCode)->toBe(0)->and($output)->toBe('OK');
    });

    it('renderFailure produces a canonical error envelope in JSON mode', function (): void {
        [$exitCode, $output] = runLocalOnlyCommand($this, 'failure', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded)
            ->toBe(JsonEnvelope::failure('local_error', 'Something went wrong locally.', ['field' => 'x']));
    });

    it('renderFailure produces a code:message line in human mode', function (): void {
        [$exitCode, $output] = runLocalOnlyCommand($this, 'failure');

        expect($exitCode)->toBe(1)->and($output)->toBe('local_error: Something went wrong locally.');
    });
});
