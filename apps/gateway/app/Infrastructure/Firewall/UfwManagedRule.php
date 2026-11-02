<?php

declare(strict_types=1);

namespace App\Infrastructure\Firewall;

final readonly class UfwManagedRule
{
    /** @param non-empty-list<string> $arguments */
    public function __construct(
        public UfwRuleShape $shape,
        public array $arguments,
        public bool $replacePort = false,
    ) {}

    /** @return non-empty-list<string> */
    public function removalArguments(): array
    {
        $routed = $this->arguments[2] === 'route';

        return ['ufw', '--force', ...($routed ? ['route'] : []), 'delete', ...array_slice($this->arguments, $routed ? 3 : 2)];
    }
}
