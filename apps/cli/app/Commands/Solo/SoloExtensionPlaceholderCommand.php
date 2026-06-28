<?php

declare(strict_types=1);

namespace App\Commands\Solo;

use App\Commands\Concerns\RequiresLocalExtension;
use App\Commands\GatewayCommand;

final class SoloExtensionPlaceholderCommand extends GatewayCommand
{
    use RequiresLocalExtension {
        isHidden as private extensionIsHidden;
    }

    #[\Override]
    protected $signature;

    #[\Override]
    protected $description;

    public function __construct(string $commandName)
    {
        $this->signature = "{$commandName} {--json : Output JSON}";
        $this->description = 'Reserved Solo extension command.';

        parent::__construct();
    }

    public function handle(): int
    {
        if (($failure = $this->guardLocalExtension()) !== null) {
            return $failure;
        }

        return $this->renderFailure(
            'solo_command_deferred',
            'Solo command execution is reserved for a later Orbit extension slice.',
            [
                'scope' => 'local',
            ],
        );
    }

    #[\Override]
    public function isHidden(): bool
    {
        if ($this->showAllExtensionCommands()) {
            return true;
        }

        return $this->extensionIsHidden();
    }

    protected function extensionSlug(): string
    {
        return 'solo';
    }

    private function showAllExtensionCommands(): bool
    {
        $value = getenv('ORBIT_CLI_SHOW_ALL_EXTENSION_COMMANDS');

        return $value === '1' || $value === 'true';
    }
}
