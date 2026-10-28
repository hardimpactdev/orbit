<?php

declare(strict_types=1);

use App\Domain\Tools\ToolManagerChildAdmission;
use App\Domain\Tools\ToolOperationCompletion;
use App\Domain\Tools\ToolOperationExecution;

return (static function (): array {
    $kinds = [];
    foreach (['install', 'update', 'remove', 'adopt', 'manager_converge'] as $verb) {
        $commands = ['tool.apt-version', 'tool.composer-version', 'tool.vp-version', 'tool.homebrew-version'];
        $map = [];
        foreach (['apt', 'composer', 'vp', 'brew', 'brew-cask'] as $manager) {
            $prefix = match ($manager) {
                'brew' => 'tool.homebrew-formula-', 'brew-cask' => 'tool.homebrew-cask-',
                default => 'tool.'.$manager.'-',
            };
            $version = in_array($manager, ['brew', 'brew-cask'], true) ? 'tool.homebrew-version' : $prefix.'version';
            $name = $prefix.'inventory';
            $variant = $manager.':inventory';
            if ($verb === 'manager_converge') {
                $name = $version;
                $variant = $manager.':version';
            } elseif ($verb === 'adopt' && in_array($manager, ['brew', 'brew-cask'], true)) {
                $name = $prefix.'adoption';
                $variant = $manager.':adoption';
            }
            $commands[] = $name;
            $map[$variant] = ['step' => 'tool-verified', 'transport' => 'agent', 'name' => $name, 'result' => []];
            if ($verb === 'remove') {
                $map[$manager.':version'] = ['step' => 'tool-verified', 'transport' => 'agent', 'name' => $version, 'result' => []];
            }
            if (in_array($verb, ['install', 'update', 'remove'], true)) {
                $commands[] = $prefix.$verb;
                if ($verb !== 'remove') {
                    $commands[] = $prefix.'candidate';
                } elseif (in_array($manager, ['apt', 'vp', 'composer'], true)) {
                    $commands[] = $prefix.'removal-plan';
                }
            }
        }
        if ($verb === 'adopt') {
            $commands[] = 'tool.inspect-composer-scope';
        }
        if (in_array($verb, ['install', 'manager_converge'], true)) {
            $commands = [...$commands, 'tool.materialize-composer', 'tool.materialize-vp', 'tool.materialize-homebrew'];
        }
        $runs = array_map(static fn (string $name): array => [
            'transport' => 'agent', 'name' => $name, 'input_fields' => [], 'input_values' => [], 'secrets' => [],
        ], array_values(array_unique($commands)));
        $kinds['tool.'.$verb] = [
            'executor' => 'swamp', 'completion' => ToolOperationCompletion::class,
            'execution' => ToolOperationExecution::class, 'runs' => $runs, 'children' => [],
            'completion_contract' => ['completion' => ['by' => 'completion_variant', 'map' => $map]],
            'result_fields' => ['installed_version', 'block', 'version', 'outcome', 'error_code',
                ['name' => 'packages', 'type' => 'list', 'items' => 'string', 'max' => 4096],
                ['name' => 'roots', 'type' => 'list', 'items' => 'string', 'max' => 4096]],
        ];
    }

    $kinds['tool.manager_converge']['admission'] = ToolManagerChildAdmission::class;

    return $kinds;
})();
