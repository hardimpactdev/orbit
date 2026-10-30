<?php

declare(strict_types=1);

use App\Domain\Operations\OperationFamilySpec;
use App\Domain\Tools\ToolManagerChildAdmission;
use App\Domain\Tools\ToolOperationCompletion;
use App\Domain\Tools\ToolOperationExecution;

return (static function (): array {
    $kinds = [];
    foreach (['update', 'remove', 'adopt', 'manager_converge'] as $verb) {
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
            $map[$variant] = $name;
            if ($verb === 'remove') {
                $map[$manager.':version'] = $version;
            }
            if (in_array($verb, ['update', 'remove'], true)) {
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
        if ($verb === 'manager_converge') {
            $commands = [...$commands, 'tool.materialize-composer', 'tool.materialize-vp', 'tool.materialize-homebrew'];
        }
        $kinds['tool.'.$verb] = ['commands' => $commands, 'done_when' => $map];
    }

    $kinds['tool.manager_converge']['admission'] = ToolManagerChildAdmission::class;

    return [
        'tool.install' => ['executor' => 'swamp', 'result_fields' => ['manager_version', 'installed_version', 'block', 'outcome', 'error_code', 'native_error_code']],
        ...OperationFamilySpec::compile([
            'requires' => ['children', 'list_of'],
            'completion' => ToolOperationCompletion::class, 'execution' => ToolOperationExecution::class,
            'transport' => 'agent', 'done_step' => 'tool-verified', 'variant' => 'completion_variant',
            'result_fields' => ['installed_version', 'block', 'version', 'outcome', 'error_code',
                ['name' => 'packages', 'type' => 'list', 'items' => 'string', 'max' => 4096],
                ['name' => 'roots', 'type' => 'list', 'items' => 'string', 'max' => 4096]],
        ], $kinds),
    ];
})();
