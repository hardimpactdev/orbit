<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use JsonSerializable;
use SensitiveParameter;

/** Carries the session with explicit masks so public IDs and counts retain their types. */
final readonly class ConnExecutionInput implements JsonSerializable
{
    /** @param array{url:string,admin_session:string,id:int} $value */
    public function __construct(#[SensitiveParameter] private array $value) {}

    /** @return array{url:string,admin_session:string,id:int} */
    public function jsonSerialize(): array
    {
        return $this->value;
    }

    /** @return array<string, never> */
    public function __debugInfo(): array
    {
        return [];
    }
}
