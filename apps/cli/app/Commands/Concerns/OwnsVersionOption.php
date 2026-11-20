<?php

declare(strict_types=1);

namespace App\Commands\Concerns;

/**
 * Lets a command's own --version option replace Symfony's application-version switch. The application routes the
 * command's input through DocumentVersionInput (OrbitConsoleApplication), so a bare `orbit --version` still works.
 */
trait OwnsVersionOption
{
    public function mergeApplicationDefinition(bool $mergeArgs = true): void
    {
        $application = $this->getApplication();
        if ($application === null || ! $this->getNativeDefinition()->hasOption('version')) {
            parent::mergeApplicationDefinition($mergeArgs);

            return;
        }
        $definition = $application->getDefinition();
        $scoped = clone $definition;
        $options = $scoped->getOptions();
        unset($options['version']);
        $scoped->setOptions($options);
        $application->setDefinition($scoped);
        try {
            parent::mergeApplicationDefinition($mergeArgs);
        } finally {
            $application->setDefinition($definition);
        }
    }
}
