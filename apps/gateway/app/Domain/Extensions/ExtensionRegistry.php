<?php

declare(strict_types=1);

namespace App\Domain\Extensions;

use Illuminate\Foundation\Application;
use InvalidArgumentException;

/** The known extensions: every registered service provider that implements {@see Extension}. */
final readonly class ExtensionRegistry
{
    public function __construct(private Application $app) {}

    /** @return array<string, Extension> keyed by slug, in provider registration order */
    public function all(): array
    {
        $extensions = [];
        foreach ($this->app->getProviders(Extension::class) as $provider) {
            if ($provider instanceof Extension) {
                $extensions[$provider->name()] = $provider;
            }
        }

        return $extensions;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->all());
    }

    public function find(string $name): Extension
    {
        return $this->all()[$name] ?? throw new InvalidArgumentException("Unknown extension [{$name}].");
    }
}
