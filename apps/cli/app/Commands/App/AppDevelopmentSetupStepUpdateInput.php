<?php

declare(strict_types=1);

namespace App\Commands\App;

final class AppDevelopmentSetupStepUpdateInput
{
    /**
     * @return array{values: array{command?: string, timeout?: int, before?: int, after?: int}}|array{error: array{field: string, message: string}}
     */
    public static function fromOptions(?string $command, mixed $timeout, mixed $before, mixed $after): array
    {
        $timeoutSeconds = AppDevelopmentSetupStepPositionInput::positiveInteger($timeout);

        if ($timeout !== null && $timeoutSeconds === null) {
            return AppDevelopmentSetupStepPositionInput::error(
                'timeout',
                'The --timeout option must be a positive integer.',
            );
        }

        $position = AppDevelopmentSetupStepPositionInput::fromOptions($before, $after);

        if (isset($position['error'])) {
            return $position;
        }

        $values = [];

        if (isset($position['values']['before'])) {
            $values['before'] = $position['values']['before'];
        }

        if (isset($position['values']['after'])) {
            $values['after'] = $position['values']['after'];
        }

        if ($command !== null && $command !== '') {
            $values['command'] = $command;
        }

        if ($timeoutSeconds !== null) {
            $values['timeout'] = $timeoutSeconds;
        }

        if ($values === []) {
            return AppDevelopmentSetupStepPositionInput::error('change', 'At least one change is required.');
        }

        return ['values' => $values];
    }
}
