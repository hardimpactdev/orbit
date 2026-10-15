<?php

declare(strict_types=1);

namespace App\Domain\Extensions;

/**
 * An optional Gateway feature behind one switch ([Extensions](/reference/extensions)).
 *
 * Each extension's service provider implements this contract. The registered providers are the set of known extensions.
 */
interface Extension
{
    /** The switch slug, such as `documents`. */
    public function name(): string;

    /** One sentence that describes the extension. */
    public function description(): string;

    /**
     * The slugs of the extensions that must be enabled before this one.
     *
     * @return list<string>
     */
    public function dependencies(): array;

    /** Setup after the switch turns on. It runs again when an enabled extension is enabled, so it must be safe to repeat. */
    public function enable(): void;

    /** Teardown after the switch turns off. It must be safe to repeat. */
    public function disable(): void;
}
