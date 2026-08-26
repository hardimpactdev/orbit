<?php

declare(strict_types=1);

namespace App\Commands\App;

final class AppDevelopmentSetupStepAddInput
{
    /**
     * @return array{values: array<string, int|string>}|array{error: array{field: string, message: string}}
     */
    public static function fromOptions(string $command, mixed $timeout, mixed $before, mixed $after): array
    {
        $timeoutSeconds = AppDevelopmentSetupStepPositionInput::positiveInteger($timeout);

        if ($timeout !== null && $timeoutSeconds === null) {
            return AppDevelopmentSetupStepPositionInput::error(
                'timeout',
                'Timeout must be a positive integer.',
            );
        }

        $position = AppDevelopmentSetupStepPositionInput::fromOptions($before, $after);

        if (array_key_exists('error', $position)) {
            return $position;
        }

        return ['values' => AppDevelopmentSetupStepPositionInput::filled([
            'command' => $command,
            'timeout' => $timeoutSeconds ?? 600,
            ...$position['values'],
        ])];
    }
}
