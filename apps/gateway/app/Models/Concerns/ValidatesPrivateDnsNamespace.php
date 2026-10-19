<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Domain\Nodes\NodeProvisioningException;
use App\Infrastructure\WireGuard\PeerDnsPolicy;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Validation\ValidationException;

/** @mixin Node|Cluster|Route */
trait ValidatesPrivateDnsNamespace
{
    /** @param array{touch?: bool|null} $options */
    public function save(array $options = []): bool
    {
        $fields = $this instanceof Route ? ['domain', 'publication'] : ['tld'];
        if (! $this->isDirty($fields)) {
            return parent::save($options);
        }

        // SQLite IMMEDIATE transactions serialize validation with every namespace write.
        return $this->getConnection()->transaction(function () use ($options): bool {
            $this->validatePrivateDnsNamespace();

            return parent::save($options);
        });
    }

    public function delete(): ?bool
    {
        return $this->getConnection()->transaction(function (): ?bool {
            $this->validatePrivateDnsNamespace(removing: true);

            return parent::delete();
        });
    }

    private function validatePrivateDnsNamespace(bool $removing = false): void
    {
        try {
            app(PeerDnsPolicy::class)->validateChange($this, $removing);
        } catch (NodeProvisioningException $exception) {
            throw ValidationException::withMessages([$this instanceof Route ? 'domain' : 'tld' => $exception->getMessage()]);
        }
    }
}
