<?php

declare(strict_types=1);

namespace App\Data\Nodes\RoleSettings;

use InvalidArgumentException;

final readonly class AppDevelopmentRoleSettings implements NodeRoleSettings
{
    public function __construct(
        public string $tld,
    ) {
        if (trim($this->tld) === '') {
            throw new InvalidArgumentException('The app-development role requires a non-empty tld setting.');
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public static function fromArray(array $settings): self
    {
        $tld = $settings['tld'] ?? null;

        if (! is_string($tld)) {
            throw new InvalidArgumentException('The app-development role requires a non-empty tld setting.');
        }

        return new self($tld);
    }

    #[\Override]
    public function toArray(): array
    {
        return ['tld' => $this->tld];
    }
}
