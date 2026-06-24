<?php

declare(strict_types=1);

use App\Commands\BootstrapGatewayCommand;
use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Console\Kernel;
use Orbit\Core\Http\JsonEnvelope;

class TestBootstrapGatewayCommand extends BootstrapGatewayCommand
{
    protected $signature = 'test:bootstrap-gateway-command {--json}';

    protected $description = 'Test BootstrapGatewayCommand';

    public string $action = 'success';

    public function handle(): int
    {
        return match ($this->action) {
            'success' => $this->renderSuccess(['bootstrapped' => true]),
            'failure' => $this->renderFailure('bootstrap_error', 'Bootstrap failed.', ['step' => 'init']),
            default => self::SUCCESS,
        };
    }
}

/**
 * @param  array<string, mixed>  $params
 * @return array{int, string}
 */
function runBootstrapGatewayCommand(object $test, string $action = 'success', array $params = []): array
{
    $test->mockConsoleOutput = false;
    app()->offsetUnset(OutputStyle::class);

    $command = new TestBootstrapGatewayCommand;
    $command->action = $action;

    app(Kernel::class)->registerCommand($command);

    $exitCode = $test->artisan('test:bootstrap-gateway-command', $params);

    return [$exitCode, trim(app(Kernel::class)->output())];
}

describe('BootstrapGatewayCommand', function (): void {
    it('renderSuccess produces a canonical envelope in JSON mode', function (): void {
        [$exitCode, $output] = runBootstrapGatewayCommand($this, 'success', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(0)
            ->and($decoded)
            ->toBe(JsonEnvelope::success(['bootstrapped' => true]));
    });

    it('renderSuccess produces key:value lines in human mode', function (): void {
        [$exitCode, $output] = runBootstrapGatewayCommand($this, 'success');

        expect($exitCode)->toBe(0)->and($output)->toBe('bootstrapped: true');
    });

    it('renderFailure produces a canonical error envelope in JSON mode', function (): void {
        [$exitCode, $output] = runBootstrapGatewayCommand($this, 'failure', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded)
            ->toBe(JsonEnvelope::failure('bootstrap_error', 'Bootstrap failed.', ['step' => 'init']));
    });

    it('renderFailure produces a code:message line in human mode', function (): void {
        [$exitCode, $output] = runBootstrapGatewayCommand($this, 'failure');

        expect($exitCode)->toBe(1)->and($output)->toBe('bootstrap_error: Bootstrap failed.');
    });
});
