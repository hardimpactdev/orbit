<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Infrastructure;

use App\Domain\Activity\ActivityInputRedaction;
use Closure;
use Orbit\Extensions\Conn\Domain\ConnProfileSections;

/** Keeps admin sessions, settings documents and thread titles out of Activity ([Activity](/cli/activity)). */
final readonly class ConnActivityInputRedaction implements ActivityInputRedaction
{
    public function commands(): array
    {
        return ['conn:environment:register', 'conn:profile:settings:update', 'conn:profile:settings:patch', 'conn:thread:record'];
    }

    public function input(string $command, Closure $input): array
    {
        $values = $input();

        if ($command === 'conn:environment:register') {
            // The admin session never enters Activity.
            return array_intersect_key($values, array_flip(['environment_id', 'label', 'url']));
        }
        if ($command === 'conn:thread:record') {
            // A title can name a customer, a bug or a secret: the index keeps it, Activity does not.
            return array_intersect_key($values, ['thread_id' => true]);
        }

        return $this->settingsInput($command, $values);
    }

    /**
     * A settings document holds workspace pictures, themes, and device values. Activity keeps the section
     * names and the versions the client sent, and nothing of the sections themselves.
     *
     * @param  array<array-key, mixed>  $input
     * @return array<string, mixed>
     */
    private function settingsInput(string $command, array $input): array
    {
        if ($command === 'conn:profile:settings:update') {
            $settings = $input['settings'] ?? null;

            return [
                ...array_intersect_key($input, ['version' => true]),
                'sections' => is_array($settings) ? array_values(array_intersect(array_keys($settings), ['workspaces', 'appearance', 'devices'])) : [],
            ];
        }

        $sections = $input['sections'] ?? null;
        $versions = [];

        foreach (is_array($sections) ? $sections : [] as $name => $change) {
            if (is_string($name) && ConnProfileSections::isKnown($name) && is_array($change) && is_int($change['version'] ?? null)) {
                $versions[$name] = $change['version'];
            }
        }

        return ['sections' => $versions];
    }
}
