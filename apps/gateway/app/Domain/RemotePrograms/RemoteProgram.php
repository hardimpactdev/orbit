<?php

declare(strict_types=1);

namespace App\Domain\RemotePrograms;

use InvalidArgumentException;

/**
 * A program that the Gateway runs on a Node, assembled from its file under `resources/remote` and the libraries its
 * header names ([Remote programs](/reference/remote-programs)). Only `RemoteProgramCatalog` builds one.
 */
final readonly class RemoteProgram
{
    /**
     * @param  string  $name  The stable name, `<area>.<name>`.
     * @param  string  $source  The assembled source: the libraries, then the program.
     * @param  list<string>  $outcomes  Every outcome the program can return.
     */
    public function __construct(
        public string $name,
        public RemoteProgramLanguage $language,
        public string $source,
        public array $outcomes,
        public int $timeoutSeconds,
        public ?string $user = null,
    ) {
        if ($user !== null && preg_match('/\A[a-z_][a-z0-9_-]{0,31}\z/D', $user) !== 1) {
            throw new InvalidArgumentException('A remote program needs a valid Unix account name.');
        }
    }

    /** The SHA-256 of the assembled source. */
    public function digest(): string
    {
        return hash('sha256', $this->source);
    }

    public function declares(string $outcome): bool
    {
        return in_array($outcome, $this->outcomes, true);
    }
}
