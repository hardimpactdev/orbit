<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tasks;

use InvalidArgumentException;

/**
 * The copy turn agent values a request sets: each role's provider, model and effort. A value given as null clears it, so the group
 * or Project falls back to the next level; a value left out stays as it is.
 */
final readonly class TaskAgentsInput
{
    public const array Keys = ['implementer_provider', 'implementer_model', 'implementer_effort', 'reviewer_provider', 'reviewer_model', 'reviewer_effort'];

    /** @param  array<string, string|null>  $values  keyed by one of Keys */
    public function __construct(public array $values)
    {
        $unknown = array_diff(array_keys($values), self::Keys);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown task agent values: '.implode(', ', $unknown).'.');
        }
    }

    /** Clears every value of the role. */
    public static function clearRole(string $role): self
    {
        return new self(array_fill_keys([$role.'_provider', $role.'_model', $role.'_effort'], null));
    }

    public function with(self $other): self
    {
        return new self([...$this->values, ...$other->values]);
    }

    /** @return array<string, string|null> the body fields, with the Project's `task_` prefix when given */
    public function toArray(string $prefix = ''): array
    {
        $body = [];
        foreach (self::Keys as $key) {
            if (array_key_exists($key, $this->values)) {
                $body[$prefix.$key] = $this->values[$key];
            }
        }

        return $body;
    }
}
