<?php

declare(strict_types=1);

namespace App\Commands\Concerns;

use Orbit\Sdk\Requests\Tasks\TaskAgentsInput;

/**
 * Reads the copy turn agent options: `--implementer-provider=` … `--reviewer-effort=`, with `task-` before them on a Project, and
 * `--clear-implementer-agent` / `--clear-reviewer-agent` where a command clears a role. The Gateway validates each value.
 */
trait ReadsTaskAgentOptions
{
    /** @return TaskAgentsInput|false|null  null when no option is given, false after a refusal */
    protected function taskAgentsOption(string $prefix, string $errorPrefix, bool $clearable): TaskAgentsInput|false|null
    {
        $values = [];
        foreach (['implementer', 'reviewer'] as $role) {
            $given = [];
            foreach (['provider', 'model', 'effort'] as $value) {
                $option = $this->option("{$prefix}{$role}-{$value}");
                if (is_string($option)) {
                    if (trim($option) === '') {
                        $this->renderGatewayFailure("{$errorPrefix}.agent_invalid", "--{$prefix}{$role}-{$value} needs a value.");

                        return false;
                    }
                    $given["{$role}_{$value}"] = $option;
                }
            }
            if ($clearable && $this->option("clear-{$prefix}{$role}-agent") === true) {
                if ($given !== []) {
                    $this->renderGatewayFailure("{$errorPrefix}.agent_conflict", "Choose either --clear-{$prefix}{$role}-agent or the {$role}'s values.");

                    return false;
                }
                $given = TaskAgentsInput::clearRole($role)->values;
            }
            $values = [...$values, ...$given];
        }

        return $values === [] ? null : new TaskAgentsInput($values);
    }
}
